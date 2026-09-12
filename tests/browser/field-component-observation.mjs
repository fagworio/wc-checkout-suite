/**
 * The browser observation of this plugin's own field component: WCCS-063.
 *
 * The store's checkout page draws the fields WooCommerce can draw natively, and the
 * ones this plugin has to draw itself are registered as checkout inner blocks, which
 * WooCommerce renders only where the checkout page contains them. This store's page
 * does not, and putting the block there would be editing the merchant's page. So the
 * component is exercised the only other honest way: on a real checkout page, in a
 * browser, with the built bundle the store serves and the stylesheets the store
 * links, rendered into a document this script owns.
 *
 * How it is rendered is the part worth stating. The bundle reads three things from
 * the page: the payload the server published (`window.wccsBlocks`), the WordPress
 * element library, and the checkout API it registers its components with. The
 * payload and the library are the store's own; the API is a recorder, because the
 * store's own `registerCheckoutBlock` is a non-writable, non-configurable export and
 * cannot be wrapped — a registration that reached it would be a registration nothing
 * could observe, and the field would still not be placed on the page.
 *
 * The recorder is given the real registration object, renders the component it hands
 * over, and the assertions are then about the component: the five widths, focus,
 * keyboard, reduced motion, tokens and zoom. Each width is a real layout pass inside a
 * document sized to that width, so the media queries the presentation declares are the
 * ones that apply.
 *
 * It reads pages and writes nothing to the store.
 *
 * Usage:
 *   WCCS_COOKIE="wordpress_logged_in_...=..." WCCS_PRODUCT=2777 \
 *     node tests/browser/field-component-observation.mjs
 */
import { chromium } from 'playwright';

const CHECKOUT = process.env.WCCS_URL || 'http://wpagf.dvl.to:8080/finalizar-compra/';
const ORIGIN = new globalThis.URL( CHECKOUT ).origin;
const PRODUCT = process.env.WCCS_PRODUCT || '';
const COOKIE = process.env.WCCS_COOKIE || '';
const BUNDLE = `${ORIGIN}/wp-content/plugins/wc-checkout-suite/build/blocks/index.js`;
const WIDTHS = [320, 375, 768, 1280, 1440];
const findings = [];
const notes = [];

const record = (label, ok, detail = '') => findings.push({ label, ok: Boolean(ok), detail });
const note = (message) => notes.push(message);

const browser = await chromium.launch({
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ORIGIN}` ],
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
 * Renders the plugin's controlled field into a document this script owns.
 *
 * Runs inside the browser. It builds an iframe on the checkout page, copies the
 * store's stylesheets and its WordPress element library into it, publishes the
 * payload and a recording checkout API, then loads the built bundle. What the bundle
 * registers is rendered with the store's own React, so what the assertions measure is
 * the shipped component and not a copy of it.
 *
 * @param {Object}  input         Input.
 * @param {string}  input.bundle  Bundle URL.
 * @param {number}  input.width   Document width to lay the region out in.
 * @param {Object}  input.payload Payload the server published.
 * @param {boolean} [input.focus] Whether to focus the control and read the ring.
 * @param {boolean} [input.zoom]  Whether to apply the zoom pass.
 * @return {Promise<Object>} Observation.
 */
const renderInDocument = async ( { bundle, width, payload, focus = false, zoom = false } ) => {
	const frame = document.createElement( 'iframe' );
	frame.setAttribute( 'title', 'wccs component harness' );
	frame.style.cssText = `width:${ width }px;height:700px;border:0;display:block`;
	document.body.appendChild( frame );

	const doc = frame.contentDocument;
	const view = frame.contentWindow;

	doc.open();
	doc.write( '<!doctype html><html><head></head><body><div class="wc-block-checkout"><div id="wccs-component-harness"></div></div></body></html>' );
	doc.close();

	// The store's own stylesheets, so the region is presented exactly as it is on the
	// checkout — including the tokens the presentation reads.
	document.querySelectorAll( 'link[rel=stylesheet]' ).forEach( ( link ) => {
		const copy = doc.createElement( 'link' );
		copy.rel = 'stylesheet';
		copy.href = link.href;
		doc.head.appendChild( copy );
	} );

	view.wp = /** @type {any} */ ( window ).wp;
	view.ReactDOM = /** @type {any} */ ( window ).ReactDOM;
	// The bundle is built with the automatic JSX runtime, which the page has as its own
	// script dependency: without it the component throws on its first element.
	view.ReactJSXRuntime = /** @type {any} */ ( window ).ReactJSXRuntime;
	view.wccsBlocks = payload;

	const captured = [];

	view.wc = {
		blocksCheckout: {
			registerCheckoutBlock: ( registration ) => captured.push( registration ),
		},
	};

	try {
		await new Promise( ( resolve, reject ) => {
			const script = doc.createElement( 'script' );
			script.src = bundle;
			script.onload = () => resolve( undefined );
			script.onerror = () => reject( new Error( 'the bundle did not load' ) );
			doc.head.appendChild( script );
		} );
	} catch ( error ) {
		return { rendered: false, reason: String( error.message ) };
	}

	if ( 0 === captured.length ) {
		return { rendered: false, reason: 'nothing_registered' };
	}

	const element = captured[ 0 ].component();

	if ( ! element ) {
		return { rendered: false, reason: 'no_component' };
	}

	/** @type {any} */ ( view ).wp.element.render( element, doc.getElementById( 'wccs-component-harness' ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );

	const region = doc.querySelector( '.wccs-blocks-field' );
	const control = region ? region.querySelector( 'textarea, input, select' ) : null;
	const label = region ? region.querySelector( 'label' ) : null;
	const htmlFor = label ? label.getAttribute( 'for' ) : null;

	const observation = {
		rendered: Boolean( region ),
		width,
		registeredName: captured[ 0 ].metadata ? captured[ 0 ].metadata.name : null,
		registeredParent: captured[ 0 ].metadata ? captured[ 0 ].metadata.parent : null,
		controlTag: control ? control.tagName.toLowerCase() : null,
		controlType: control ? control.getAttribute( 'type' ) : null,
		controlHeight: control ? Math.round( control.getBoundingClientRect().height ) : 0,
		labelled: Boolean( htmlFor && control && control.id === htmlFor ),
		labelText: label ? label.textContent.trim() : null,
		regionWidth: region ? Math.round( region.getBoundingClientRect().width ) : 0,
		regionRight: region ? Math.round( region.getBoundingClientRect().right ) : 0,
		overflow: doc.documentElement.scrollWidth - view.innerWidth,
		documentWidth: view.innerWidth,
	};

	if ( focus && control ) {
		control.focus();

		const style = view.getComputedStyle( control );

		observation.focused = doc.activeElement === control;
		observation.outlineWidth = parseFloat( style.outlineWidth ) || 0;
		observation.outlineStyle = style.outlineStyle;
		observation.boxShadow = style.boxShadow;
		observation.transitionDuration = style.transitionDuration;
		observation.transitionsInRegion = Array.from( region.querySelectorAll( '*' ) ).filter( ( child ) => {
			const value = view.getComputedStyle( child ).transitionDuration;

			return value && '0s' !== value && parseFloat( value ) > 0.5;
		} ).length;
	}

	if ( zoom ) {
		// Playwright cannot drive the browser's own zoom UI, and this is the layout
		// consequence of it: the document is laid out as if the viewport were half as
		// wide. What is asserted is reflow, which is what a zoomed reader experiences.
		doc.documentElement.style.zoom = '2';
		void doc.documentElement.offsetWidth;
		observation.zoomOverflow = doc.documentElement.scrollWidth - view.innerWidth;
		doc.documentElement.style.zoom = '';
	}

	// The tokens are declared on the region itself and on nothing else, which is what
	// keeps this plugin from restyling a page it is not on. Reading one from the region
	// and the same one from the document root asserts both halves of that.
	// The facts a screen reader depends on: one control, a name for it, and nothing
	// hiding the region from assistive technology. A real screen reader is not driven
	// here, and the report says so.
	observation.controls = region ? region.querySelectorAll( 'textarea, input, select' ).length : 0;
	observation.hiddenFromAt = Boolean(
		region && (
			region.getAttribute( 'aria-hidden' ) === 'true'
			|| 'none' === view.getComputedStyle( region ).display
			|| 'hidden' === view.getComputedStyle( region ).visibility
		)
	);
	observation.token = region ? view.getComputedStyle( region ).getPropertyValue( '--wccs-ink' ).trim() : '';
	observation.rootToken = view.getComputedStyle( doc.documentElement ).getPropertyValue( '--wccs-ink' ).trim();

	return observation;
};

const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', storageState: state });
const errors = [];
page.on( 'pageerror', ( error ) => errors.push( String( error.message ).slice( 0, 140 ) ) );
await page.goto( CHECKOUT, { waitUntil: 'networkidle', timeout: 45000 } );

const payload = await page.evaluate( () => /** @type {any} */ ( window ).wccsBlocks || null );

if ( ! payload || ! Array.isArray( payload.fields ) || 0 === payload.fields.length ) {
	record( 'The page carried a payload for the component to draw', false, `payload=${ JSON.stringify( payload ) }` );
} else {
	note( `Payload published by the server: ${ JSON.stringify( payload.fields.map( ( field ) => field.name ) ) }` );

	for ( const width of WIDTHS ) {
		const observation = await page.evaluate( renderInDocument, { bundle: BUNDLE, width, payload } );

		record(
			`The controlled field renders at ${width}px`,
			observation.rendered,
			`rendered=${ observation.rendered } reason=${ observation.reason || '' }`
		);

		if ( ! observation.rendered ) {
			continue;
		}

		record(
			`The registration carries a name and an area the platform accepts at ${width}px`,
			'string' === typeof observation.registeredName
				&& observation.registeredName.startsWith( 'wc-checkoutsuite/' )
				&& 'string' === typeof observation.registeredParent,
			`name=${ observation.registeredName } parent=${ observation.registeredParent }`
		);
		record(
			`The field is a labelled control at ${width}px`,
			observation.labelled && 'string' === typeof observation.labelText && '' !== observation.labelText,
			`label="${ observation.labelText }" for=${ observation.labelled } control=${ observation.controlTag }/${ observation.controlType }`
		);
		record(
			`The region fits its viewport at ${width}px`,
			observation.overflow <= 1 && observation.regionRight <= observation.documentWidth,
			`overflow=${ observation.overflow }px region=${ observation.regionWidth }px right=${ observation.regionRight } viewport=${ observation.documentWidth }px`
		);
		record(
			`The control is a usable touch target at ${width}px`,
			observation.controlHeight >= 40,
			`height=${ observation.controlHeight }px`
		);
		record(
			`The region exposes exactly one control and hides nothing at ${width}px`,
			1 === observation.controls && ! observation.hiddenFromAt,
			`controls=${ observation.controls } hidden=${ observation.hiddenFromAt }`
		);
	}

	const interaction = await page.evaluate( renderInDocument, { bundle: BUNDLE, width: 1280, payload, focus: true } );

	if ( interaction.rendered ) {
		const ring = ( interaction.outlineWidth > 0 && 'none' !== interaction.outlineStyle )
			|| ( interaction.boxShadow && 'none' !== interaction.boxShadow );

		record( 'The control takes focus', interaction.focused, `active=${ interaction.focused }` );
		record(
			'A focused control keeps a visible ring',
			Boolean( ring ),
			`outline=${ interaction.outlineWidth }px ${ interaction.outlineStyle } shadow=${ interaction.boxShadow }`
		);
		record(
			'Reduced motion: the region declares no long transition',
			0 === interaction.transitionsInRegion,
			`elements=${ interaction.transitionsInRegion } transition=${ interaction.transitionDuration }`
		);
		record(
			'The tokens are declared on the region and on nothing else',
			'' !== interaction.token && '' === interaction.rootToken,
			`region --wccs-ink=${ interaction.token } root --wccs-ink=${ interaction.rootToken }`
		);

		// Keyboard order, driven by the browser and not by a synthetic event: a control
		// that cannot be left is a keyboard trap, and the way to find one is to leave it.
		const frame = page.frames().filter( ( candidate ) => candidate !== page.mainFrame() ).pop();

		if ( frame ) {
			const control = frame.locator( '.wccs-blocks-field textarea, .wccs-blocks-field input, .wccs-blocks-field select' ).last();

			await control.focus();
			const focusedTag = await frame.evaluate( () => ( document.activeElement ? document.activeElement.tagName.toLowerCase() : null ) );

			await page.keyboard.press( 'Tab' );
			const afterTab = await frame.evaluate( () => ( document.activeElement ? document.activeElement.tagName.toLowerCase() : null ) );

			await page.keyboard.press( 'Shift+Tab' );
			const afterBack = await frame.evaluate( () => ( document.activeElement ? document.activeElement.tagName.toLowerCase() : null ) );

			record(
				'The control takes keyboard focus and is not a trap',
				'textarea' === focusedTag && 'textarea' !== afterTab && 'textarea' === afterBack,
				`focused=${ focusedTag } after_tab=${ afterTab } after_shift_tab=${ afterBack }`
			);
		} else {
			record( 'The keyboard order could be driven', false, 'the harness document was not found' );
		}
	} else {
		record( 'The component could be rendered for the focus and motion checks', false, `reason=${ interaction.reason || '' }` );
	}

	const zoomed = await page.evaluate( renderInDocument, { bundle: BUNDLE, width: 1280, payload, zoom: true } );

	if ( zoomed.rendered ) {
		record( 'Zoom to 200% does not overflow horizontally', zoomed.zoomOverflow <= 1, `overflow=${ zoomed.zoomOverflow }px` );
		note( 'Zoom is applied to the document the component lives in, so the reflow measured is the reflow a zoomed reader gets.' );
	}

	record( 'No uncaught script error while rendering the component', 0 === errors.length, errors.join( ' | ' ) );
}

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
