/**
 * Fase 8 observation — the rule builder, as a merchant meets it.
 *
 * The harness proves the server decides the newest sources with a real cart, and the two suites
 * prove both engines answer one fixture file the same way. What neither shows is the screen: that
 * the vocabulary the server publishes is the one the builder offers, that a rule reading a product
 * tag can be written, that two comparisons nothing can satisfy are **shown** rather than refused,
 * and that saving writes the rule the merchant composed.
 *
 * It reads and clicks, and the writes it performs are the ones it is there to observe.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase8-rule-builder.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`; the
 * document comes from `wp eval-file tests/Integration/support/seed-f14-links.php`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

const findings = [];
const errors = [];
const record = ( label, ok, detail = '' ) =>
	findings.push( { label, ok: Boolean( ok ), detail } );

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }`,
	],
} );
const page = await browser.newPage( { viewport: { width: 1600, height: 1200 } } );

for ( const pair of [
	process.env.WCCS_COOKIE,
	process.env.WCCS_AUTH_COOKIE,
].filter( Boolean ) ) {
	const i = pair.indexOf( '=' );

	await page.context().addCookies( [
		{
			name: pair.slice( 0, i ),
			value: pair.slice( i + 1 ),
			domain: 'wpagf.dvl.to',
			path: '/',
			expires: -1,
			httpOnly: true,
			secure: false,
			sameSite: 'Lax',
		},
	] );
}

page.on( 'pageerror', ( e ) => errors.push( e.message ) );

/**
 * Runs one step, reporting a failure rather than aborting the run.
 *
 * @param {string}   label Step name.
 * @param {Function} run   Step body.
 * @return {Promise<void>} Resolves either way.
 */
async function step( label, run ) {
	try {
		await run();
	} catch ( error ) {
		record(
			label,
			false,
			String( error && error.message ? error.message : error ).split(
				'\n'
			)[ 0 ]
		);
	}
}

/**
 * Reads the draft through the REST API the screen itself uses.
 *
 * @return {Promise<any>} Document, or null.
 */
async function draft() {
	const answer = await page.evaluate( async () => {
		const config = window.wccsAdmin ?? {};
		const rest = config.rest ?? {};
		const url =
			( rest.root ?? '' ) +
			( rest.namespace ?? '' ) +
			( rest.routes?.draft ?? '' );

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': rest.nonce ?? '' },
		} );

		return response.ok ? response.json() : null;
	} );

	return answer;
}

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.wccs-editor-areas', { timeout: 30000 } );

// ---------------------------------------------------------------------------
// 1. The builder offers the vocabulary the server publishes.
// ---------------------------------------------------------------------------
await step( 'The rule builder offers the newest sources', async () => {
	// The inspector is three tabs and the rule lives in the second one, which is where a
	// merchant writes it: the observation walks the same path.
	await page.locator( '.field-info' ).first().click();

	await page
		.getByRole( 'button', { name: 'Regras', exact: true } )
		.first()
		.click();

	const builder = page.locator( '.wccs-conditions' );

	await builder.waitFor( { state: 'visible', timeout: 15000 } );

	await builder
		.getByRole( 'button', { name: /Add a condition/ } )
		.first()
		.click();

	const sources = builder
		.locator( '.wccs-conditions__leaf' )
		.first()
		.locator( 'select' )
		.first();

	await sources.waitFor( { timeout: 10000 } );

	const offered = await sources
		.locator( 'option' )
		.evaluateAll( ( nodes ) => nodes.map( ( node ) => node.value ) );

	record(
		'The builder offers the whole vocabulary, including the newest sources',
		[ 'cart_tags', 'cart_virtual', 'cart_quantity', 'cart_subtotal', 'user_role' ].every(
			( key ) => offered.includes( key )
		),
		'sources=' + offered.join( ',' )
	);
} );

// ---------------------------------------------------------------------------
// 2. A rule about a product tag can be written.
// ---------------------------------------------------------------------------
await step( 'A rule about a product tag is composed', async () => {
	const builder = page.locator( '.wccs-conditions' );
	const select = ( /** @type {number} */ index ) =>
		builder.locator( '.wccs-conditions__leaf' ).nth( index );

	await select( 0 ).getByLabel( 'Reads' ).selectOption( 'cart_tags' );
	await select( 0 )
		.getByLabel( 'Comparison' )
		.selectOption( 'contains' );
	await select( 0 )
		.locator( 'input[type="text"]' )
		.first()
		.fill( 'promocao' );

	const sentence = await builder
		.locator( '.wccs-conditions__sentence' )
		.innerText();

	record(
		'The rule reads as a sentence about a product tag',
		sentence.includes( 'promocao' ),
		sentence
	);
} );

// ---------------------------------------------------------------------------
// 3. A rule nothing can satisfy is shown, not refused.
// ---------------------------------------------------------------------------
await step( 'Two comparisons that cannot both hold are reported', async () => {
	const builder = page.locator( '.wccs-conditions' );

	await builder
		.getByRole( 'button', { name: /Add a condition/ } )
		.first()
		.click();

	const second = builder.locator( '.wccs-conditions__leaf' ).nth( 1 );

	await second.getByLabel( 'Reads' ).selectOption( 'cart_tags' );
	await second
		.getByLabel( 'Comparison' )
		.selectOption( 'not_contains' );
	await second
		.locator( 'input[type="text"]' )
		.first()
		.fill( 'promocao' );

	const notices = await builder.locator( '.wccs-notice' ).allInnerTexts();

	record(
		'The contradiction is reported',
		notices.some( ( text ) => text.includes( 'cannot both be true' ) ),
		notices.join( ' | ' )
	);
	record(
		'And it is not refused: the rule is still there to be edited',
		( await builder.locator( '.wccs-conditions__leaf' ).count() ) === 2
	);
} );

// ---------------------------------------------------------------------------
// 4. Saving writes the rule the merchant composed.
// ---------------------------------------------------------------------------
await step( 'The rule is saved with the document', async () => {
	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	let stored = null;

	for ( let attempt = 0; attempt < 40 && null === stored; attempt++ ) {
		const document = await draft();

		if ( document ) {
			const field = ( document.fields ?? [] ).find(
				( entry ) => entry && entry.conditions?.visible?.all
			);

			if ( field ) {
				stored = field;
				break;
			}
		}

		await page.waitForTimeout( 250 );
	}

	record(
		'The document holds the group of the two comparisons',
		null !== stored,
		stored ? JSON.stringify( stored.conditions.visible ) : 'not stored'
	);
	record(
		'And both of them read the product tags of the cart',
		null !== stored &&
			'cart_tags' === stored.conditions.visible.all[ 0 ].source &&
			'cart_tags' === stored.conditions.visible.all[ 1 ].source
	);

	await page.screenshot( { path: `${ OUT }/fase8-rule-builder.png` } );
} );

record( 'No page error was raised while the screen was used', errors.length === 0, errors.join( ' | ' ) );

await browser.close();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
console.log( '=====================================================================' );
console.log( 'Fase 8 observation — the rule builder' );
console.log( '=====================================================================' );

for ( const finding of findings ) {
	console.log(
		`  ${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${
			finding.detail ? `  [${ finding.detail }]` : ''
		}`
	);
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log( '=====================================================================' );
console.log(
	`RESULT: ${ findings.length - failed } passed, ${ failed } failed`
);
console.log( '=====================================================================' );

process.exit( failed > 0 ? 1 : 0 );
