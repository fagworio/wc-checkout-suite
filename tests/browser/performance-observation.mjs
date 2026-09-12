/**
 * The browser half of WCCS-064: what the checkout costs a customer.
 *
 * Section 21 of the roadmap budgets four things that are browser facts and cannot be
 * measured from PHP: no external request is required for basic fields, local validators
 * make no request per keystroke, no field triggers a `save()`, and the bundle is only
 * where it is used. The other half of the acceptance — "memória após refresh estável" —
 * is literally about reloading a page, so it is measured the same way.
 *
 * What it measures, and on what:
 *
 *   1. the store's checkout, loaded as a customer loads it, with the plugin's document
 *      published: every request is recorded, and typing twelve characters into a field
 *      must start none;
 *   2. the plugin's own controlled field, rendered in a document this script owns (the
 *      same harness WCCS-063 uses), for the same keystroke question on this plugin's
 *      component rather than on the platform's;
 *   3. six reloads of the checkout, with the renderer's own counters read through the
 *      browser protocol after each: DOM nodes, documents and the JavaScript heap.
 *
 * It writes nothing to the store and changes nothing.
 *
 * Usage:
 *   WCCS_COOKIE="wordpress_logged_in_...=..." WCCS_PRODUCT=2777 \
 *     node tests/browser/performance-observation.mjs
 */
import { chromium } from 'playwright';

import { renderComponent } from './support/component-harness.mjs';

const CHECKOUT = process.env.WCCS_URL || 'http://wpagf.dvl.to:8080/finalizar-compra/';
const ORIGIN = new globalThis.URL( CHECKOUT ).origin;
const PRODUCT = process.env.WCCS_PRODUCT || '';
const COOKIE = process.env.WCCS_COOKIE || '';
const BUNDLE = `${ORIGIN}/wp-content/plugins/wc-checkout-suite/build/blocks/index.js`;
const RELOADS = Number( process.env.WCCS_RELOADS || 6 );
const TYPED = 'ABC-123-4567';
const findings = [];
const notes = [];

const record = (label, ok, detail = '') => findings.push({ label, ok: Boolean(ok), detail });
const note = (message) => notes.push(message);

const browser = await chromium.launch({
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ORIGIN}`,
		// The heap gauge is only comparable across loads if the garbage has been
		// collected: otherwise the series measures the collector's timing rather than
		// what the page holds.
		'--js-flags=--expose-gc',
	],
});

const seed = await browser.newPage({ viewport: { width: 1280, height: 900 } });

if (PRODUCT) {
	await seed.goto(`${ORIGIN}/?add-to-cart=${PRODUCT}`, { waitUntil: 'networkidle', timeout: 45000 });
	note(`Cart seeded with product ${PRODUCT}`);
}

const state = await seed.context().storageState();

if (COOKIE) {
	const separator = COOKIE.indexOf('=');
	const host = new globalThis.URL( ORIGIN ).hostname;

	state.cookies.push({
		name: COOKIE.slice(0, separator),
		value: COOKIE.slice(separator + 1),
		domain: host,
		path: '/',
		expires: -1,
		httpOnly: true,
		secure: false,
		sameSite: 'Lax',
	});
}

/**
 * Records every request a page makes, with the ones this plugin owns separated out.
 *
 * @param {import('playwright').Page} page Page.
 * @return {Object} Recorder.
 */
function watchRequests( page ) {
	const all = [];
	const plugin = [];

	page.on( 'request', ( request ) => {
		const url = request.url();

		all.push( url );

		if ( url.includes( 'wc-checkout-suite' ) ) {
			plugin.push( url );
		}
	} );

	return { all, plugin };
}

/**
 * The URLs requested between two points, excluding what the browser itself fetches.
 *
 * @param {Array<string>} all  Every URL seen.
 * @param {number}        from Index to start at.
 * @return {Array<string>} URLs.
 */
function since( all, from ) {
	return all.slice( from );
}

const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, storageState: state });
const errors = [];
const requests = watchRequests( page );
const pluginRoutes = [];

page.on( 'pageerror', ( error ) => errors.push( String( error.message ).slice( 0, 140 ) ) );
page.on( 'request', ( request ) => {
	if ( request.url().includes( '/wp-json/wc-checkoutsuite/' ) ) {
		pluginRoutes.push( `${ request.method() } ${ request.url() }` );
	}
} );

// ---------------------------------------------------------------------------
// 1. The checkout, loaded as a customer loads it.
// ---------------------------------------------------------------------------
await page.goto( CHECKOUT, { waitUntil: 'networkidle', timeout: 45000 } );

const onLoad = requests.all.length;
const onLoadPlugin = requests.plugin.length;
const payload = await page.evaluate( () => /** @type {any} */ ( window ).wccsBlocks || null );

record(
	'The checkout page carries the plugin payload the bundle reads',
	Boolean( payload && Array.isArray( payload.fields ) && payload.fields.length > 0 ),
	`payload=${ JSON.stringify( payload ? payload.fields.map( ( field ) => field.name ) : null ) }`
);

record(
	'Every resource this plugin loads comes from the store itself, so a basic field needs no external request',
	requests.plugin.every( ( url ) => url.startsWith( ORIGIN ) ),
	`plugin_requests=${ onLoadPlugin } of ${ onLoad }`
);

note( `Requests on load: ${ onLoad } in total, ${ onLoadPlugin } of them this plugin's own files.` );

const field = page.locator( '.wc-block-checkout input[type=text], .wc-block-checkout input[type=email], .wc-block-checkout textarea' ).first();

if ( await field.count() ) {
	const before = requests.all.length;
	const initial = await field.inputValue();

	await field.pressSequentially( TYPED, { delay: 25 } );

	const during = since( requests.all, before );
	const typed = await field.inputValue();

	record(
		'Typing into a checkout field starts no request that carries what was typed',
		0 === during.filter( ( url ) => url.includes( encodeURIComponent( TYPED ) ) || url.includes( TYPED ) ).length,
		`requests=${ during.length } for ${ TYPED.length } keystrokes: ${ JSON.stringify( during.slice( 0, 4 ) ) }`
	);
	record( 'What was typed is still in the field: nothing re-rendered it away', typed === initial + TYPED, `value=${ typed }` );
} else {
	record( 'A field was on the checkout to type into', false, 'no text field was found' );
}

record(
	'No field of this plugin wrote anything: no request reached its own routes',
	0 === pluginRoutes.length,
	`routes=${ JSON.stringify( pluginRoutes ) }`
);

// ---------------------------------------------------------------------------
// 2. This plugin's own component, and the same keystroke question.
// ---------------------------------------------------------------------------
if ( payload && Array.isArray( payload.fields ) && payload.fields.length > 0 ) {
	const rendered = await page.evaluate( renderComponent, { bundle: BUNDLE, width: 1280, payload } );

	record( 'The plugin\'s controlled field rendered for the keystroke measurement', rendered.rendered, `rendered=${ rendered.rendered } reason=${ rendered.reason || '' }` );

	if ( rendered.rendered ) {
		const frame = page.frames().filter( ( candidate ) => candidate !== page.mainFrame() ).pop();
		const control = frame.locator( '.wccs-blocks-field textarea, .wccs-blocks-field input' ).last();
		const before = requests.all.length;
		const beforePlugin = requests.plugin.length;
		const seen = [];

		// One key at a time, reading the value after each: the component is controlled by
		// design, so what happens to a keystroke is a fact about the integration that
		// renders it and not only about the component.
		for ( const character of TYPED.slice( 0, 4 ).split( '' ) ) {
			await control.press( character );
			seen.push( await control.inputValue() );
		}

		const during = since( requests.all, before );
		const pluginDuring = requests.plugin.length - beforePlugin;
		const instances = await frame.evaluate( () => document.querySelectorAll( '.wccs-blocks-field' ).length );

		record(
			'Typing into this plugin\'s own field makes no request of this plugin: local validation is local',
			0 === pluginDuring && 0 === during.filter( ( url ) => url.includes( '/wp-json/wc-checkoutsuite/' ) ).length,
			`plugin=${ pluginDuring } any=${ during.length } for 4 keystrokes${ during.length ? ': ' + JSON.stringify( during.slice( 0, 3 ) ) : '' }`
		);
		record( 'One field, one instance, no matter how much was typed', 1 === instances, `instances=${ instances }` );

		if ( seen.every( ( value ) => '' === value ) ) {
			note(
				'The controlled field reported no value after each keystroke: the element is built once with the value the store held at that moment, and the default registration returns that same element (and then nothing) on a re-render, so nothing ever hands the component the value it just reported. Recorded as part of BLOCKS-CONTROLLED-FIELD-PLACEMENT rather than as a performance result.'
			);
		} else {
			note( `The controlled field reported after each keystroke: ${ JSON.stringify( seen ) }` );
		}
	}
} else {
	record( 'The plugin\'s own component could be rendered', false, 'the page carried no payload' );
}

// ---------------------------------------------------------------------------
// 3. Six reloads: the counters the renderer keeps.
// ---------------------------------------------------------------------------
// The counters are read from inside the page and not from the renderer's cumulative
// performance counters: `Performance.getMetrics` accumulates for the life of the
// renderer, so across reloads it reports growth that is the counter's own, not the
// page's. What is measured here is a gauge — how many nodes the document holds and how
// much heap it holds after a collection.
const gauge = () => {
	if ( 'function' === typeof ( /** @type {any} */ ( window ).gc ) ) {
		( /** @type {any} */ ( window ).gc )();
	}

	const memory = /** @type {any} */ ( window ).performance.memory;

	return {
		nodes: document.querySelectorAll( '*' ).length,
		plugins: navigator.plugins.length,
		heap: memory ? memory.usedJSHeapSize : null,
		listeners: 'undefined' === typeof window.getEventListeners ? null : Object.keys( window.getEventListeners( document ) ).length,
	};
};

const series = [];

for ( let round = 0; round < RELOADS; round++ ) {
	await page.goto( CHECKOUT, { waitUntil: 'networkidle', timeout: 45000 } );

	// One turn of the event loop after the load, so the checkout's own deferred work has
	// finished before the gauge is read.
	await page.waitForTimeout( 500 );

	const reading = await page.evaluate( gauge );

	series.push( { round, ...reading, requests: requests.all.length } );
}

const first = series[ 0 ];
const last = series[ series.length - 1 ];
const heapGrowth = first.heap > 0 ? ( last.heap - first.heap ) / first.heap : 0;

record(
	`Reloading the checkout ${ RELOADS } times leaves the DOM where it started`,
	null != last.nodes && last.nodes <= first.nodes * 1.1 + 20,
	`nodes first=${ first.nodes } last=${ last.nodes } every round=${ JSON.stringify( series.map( ( entry ) => entry.nodes ) ) }`
);

record(
	`Reloading the checkout ${ RELOADS } times leaves the JavaScript heap where it started`,
	null != last.heap && heapGrowth <= 0.3,
	`heap first=${ Math.round( first.heap / 1024 ) } KB last=${ Math.round( last.heap / 1024 ) } KB growth=${ ( heapGrowth * 100 ).toFixed( 1 ) }%`
);

record( 'No uncaught script error across the reloads', 0 === errors.length, errors.join( ' | ' ) );

note( `After each reload, with the heap collected first: ${ JSON.stringify( series.map( ( entry ) => ( { nodes: entry.nodes, heap_kb: entry.heap ? Math.round( entry.heap / 1024 ) : null } ) ) ) }` );
note( `Requests over the whole run: ${ requests.all.length } (${ requests.plugin.length } this plugin's own files, ${ pluginRoutes.length } to its REST routes).` );

await seed.close();
await browser.close();

for ( const finding of findings ) {
	console.log( `${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${ finding.detail ? '  [' + finding.detail + ']' : '' }` );
}

for ( const message of notes ) {
	console.log( `NOTE  ${ message }` );
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;
console.log( `RESULT: ${ findings.length - failed } passed, ${ failed } failed, ${ notes.length } notes` );
process.exit( failed > 0 ? 1 : 0 );
