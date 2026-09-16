/**
 * Fase 3 observation — the store's own checkout, in the administration.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.2: the screen
 * loads the real checkout and does not start empty. The harness proves the server half
 * (`tests/Integration/FASE3-checkout-reference-proof.php`); this drives the screen a merchant
 * uses and reads what it offers and what it does:
 *
 *   1. **The store's checkout is on screen**: the sections WooCommerce declares, their native
 *      fields with their real keys and labels, each marked `Nativo`.
 *   2. **It says which checkout the store runs** (§6.7): the capability banner matches the
 *      store, read from the store rather than chosen by the merchant.
 *   3. **A whole section is adopted in one action**, from inside the field list (the block
 *      that used to be a panel of its own now ends the list of the open section), so the
 *      merchant never rebuilds a
 *      checkout that already exists — and adopting it writes the fields under WooCommerce's
 *      own identity, in the order the store runs them.
 *   4. **What is managed stops being offered**, and the panel says how much of the section is
 *      managed instead.
 *
 * It writes: the adoption is saved and published, which is what the screen does. The store
 * is left with the adopted native fields in its draft.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase3-checkout-reference.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 *
 * It needs the store on the clean fixture, because it adopts a section of the real checkout
 * and adoption is durable:
 *
 *   wp option delete wccs_schema_draft wccs_schema_published wccs_schema_revisions
 *   wp eval-file tests/Integration/support/seed-f14-links.php draft
 *
 * @package WCCheckoutSuite
 */

import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const SECTION = 'billing';
const NATIVE_FIELD = 'billing_first_name';

const findings = [];
const notes = [];
const record = ( label, ok, detail = '' ) =>
	findings.push( { label, ok: Boolean( ok ), detail } );
const note = ( message ) => notes.push( message );

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }`,
	],
} );
const page = await browser.newPage( { viewport: { width: 1600, height: 1100 } } );

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

const errors = [];

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
 * The draft the store holds, read through the route the screen reads it with.
 *
 * @return {Promise<any>} The draft document.
 */
async function storedDraft() {
	return page.evaluate( async () => {
		const boot = /** @type {any} */ ( window ).wccsAdmin;
		const response = await fetch(
			boot.rest.root + boot.rest.namespace + boot.rest.routes.draft,
			{ headers: { 'X-WP-Nonce': boot.rest.nonce } }
		);

		return response.json();
	} );
}

/**
 * The store's own checkout, read through the route the screen reads it with.
 *
 * The panel is a report of this: comparing the two is what proves the screen shows the
 * store's checkout rather than a list of its own.
 *
 * @return {Promise<any>} The inventory.
 */
async function storedInventory() {
	return page.evaluate( async () => {
		const boot = /** @type {any} */ ( window ).wccsAdmin;
		const response = await fetch(
			boot.rest.root + boot.rest.namespace + boot.rest.routes.coreFields,
			{ headers: { 'X-WP-Nonce': boot.rest.nonce } }
		);

		return response.json();
	} );
}

await page.goto( URL, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 3000 );

// ---------------------------------------------------------------------------
// 1. The store's checkout, on screen.
// ---------------------------------------------------------------------------
await step( 'The screen loads the store checkout', async () => {
	// O bloco é o fim da lista da **seção aberta**: abrir a seção onde a loja corre o
	// campo nativo é o que o traz para o ecrã.
	await page.waitForSelector( '.section-tabs', { timeout: 30000 } );

	const panel = page.getByRole( 'region', {
		name: 'Campos do checkout da loja nesta seção',
	} );

	record(
		'The store checkout is on screen, not an empty editor',
		( await panel.count() ) > 0,
		( await panel.count() ) + ' block(s)'
	);

	// The server's own answers, so the assertion is "the screen shows the store's checkout"
	// and not "the screen shows a number this test remembered". The panel lists the sections
	// that still have something to take over, which is what a report of the store says.
	const inventory = await storedInventory();
	const draft = await storedDraft();
	const managed = new Set(
		( draft?.fields ?? [] ).map( ( field ) => field?.id )
	);
	const expected = ( inventory?.sections ?? [])
		.filter( ( section ) =>
			( section.fields ?? [] ).some(
				( field ) => ! managed.has( field.id )
			)
		)
		.map( ( section ) => section.key );
	const shown = await page
		.locator( '.core-checkout-section' )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) =>
				( node.getAttribute( 'id' ) || '' ).replace(
					'wccs-core-section-',
					''
				)
			)
		);

	// A lista mostra a seção que o editor tem aberta, e o servidor diz que é uma das
	// que ainda têm o que usar — a mesma resposta, um lugar de cada vez.
	record(
		'With the section the editor is in, as the server reports it',
		1 === shown.length && expected.includes( shown[ 0 ] ),
		'server=' + expected.join( ',' ) + ' screen=' + shown.join( ',' )
	);

	record(
		'And the native fields of the store, with their own keys',
		( await page.locator( `#wccs-core-adopt-${ NATIVE_FIELD }` ).count() ) >
			0 &&
			( await page.locator( `text=${ NATIVE_FIELD }` ).count() ) > 0,
		'field=' + NATIVE_FIELD
	);

	const native = await page
		.locator( '.core-checkout-fields .wccs-badge' )
		.count();

	record(
		'Each of them says it belongs to the platform',
		native >= 10,
		'native badges=' + native
	);

	await page.screenshot( { path: `${ OUT }/fase3-store-checkout.png` } );
} );

await step( 'It says which checkout the store runs', async () => {
	const mode = await page.evaluate( () =>
		( /** @type {any} */ ( window ).wccsAdmin ?? {} ).checkoutMode
	);

	const banner = await page
		.locator( '.mode-banner' )
		.count();

	record(
		'The server read the store, and the banner matches it',
		'blocks' === mode ? banner > 0 : 0 === banner,
		'mode=' + mode + ' banners=' + banner
	);
} );

// ---------------------------------------------------------------------------
// 2. Adopting a whole section.
// ---------------------------------------------------------------------------
await step( 'A whole section of the store checkout is adopted', async () => {
	const card = page.locator( `#wccs-core-section-${ SECTION }` );

	await card.getByRole( 'button', { name: 'Usar esta seção' } ).click();
	await page.waitForTimeout( 800 );

	record(
		'The section is taken over in one action',
		( await page
			.locator( `#wccs-core-adopt-${ NATIVE_FIELD }` )
			.count() ) === 0,
		'the section no longer offers every field one by one'
	);

	await page.evaluate( () => window.sessionStorage.clear() );
	await page.waitForTimeout( 200 );

	const saveButton = page
		.getByRole( 'button', { name: /Salvar alterações|Save changes/ } )
		.first();

	await saveButton.click();
	await page.waitForTimeout( 4500 );

	const draft = await storedDraft();
	const stored = ( draft?.fields ?? [] ).filter(
		( field ) => field?.origin === 'core'
	);

	record(
		'The adopted fields are stored under the identity WooCommerce uses',
		stored.some( ( field ) => field.id === NATIVE_FIELD ) &&
			stored.every( ( field ) => field.integration_id === field.id ),
		'core fields=' + stored.length
	);

	const first = stored.find( ( field ) => field.id === NATIVE_FIELD ) ?? {};

	record(
		'With the order and the label the store runs them at',
		10 === Number( first.position ) && '' !== ( first.label ?? '' ),
		`position=${ first.position } label=${ first.label }`
	);

	record(
		'And the document still holds what it had before',
		( draft?.fields ?? [] ).some(
			( field ) => field?.id === 'arquivo_autorizacao'
		),
		'the fields this store configured are still there'
	);
} );

// ---------------------------------------------------------------------------
// 3. What an edit of a native field changes (§6.7).
// ---------------------------------------------------------------------------
await step( 'The inspector says what an edit of a native field changes', async () => {
	// The adopted field is now part of the document, so the editor lists it under the
	// location the store runs it in.
	// The tab is named the way the editor names the location, and the location the store
	// runs it in is the one that holds the adopted fields.
	await page
		.getByRole( 'button', { name: /^(Cobrança|Billing)/ } )
		.first()
		.click();
	await page.waitForTimeout( 600 );

	// The row's own button, not the panel above it: the store-checkout panel also prints the
	// key of every native field, and clicking that would open nothing.
	await page
		.locator( '.builder-panel button.field-info', {
			hasText: NATIVE_FIELD,
		} )
		.first()
		.click();
	await page.waitForTimeout( 900 );

	const support = page.locator( '#wccs-native-support' );
	const present = ( await support.count() ) > 0;

	record(
		'Opening a native field states what this checkout will take from the edit',
		present,
		present
			? ( await support.innerText() ).split( '\n' )[ 0 ].slice( 0, 80 )
			: 'no statement on screen'
	);

	if ( present ) {
		const mode = await page.evaluate( () =>
			( /** @type {any} */ ( window ).wccsAdmin ?? {} ).checkoutMode
		);
		const label = await page.locator( '#wccs-native-label' ).innerText();

		record(
			'And it matches the checkout the store runs',
			'blocks' === mode
				? label.includes( 'da plataforma' )
				: label.includes( 'aplicado' ),
			'mode=' + mode + ' label=' + label.replace( /\s+/g, ' ' ).trim()
		);
	}

	await page.screenshot( { path: `${ OUT }/fase3-native-support.png` } );
} );

// ---------------------------------------------------------------------------
// 4. What is managed stops being offered.
// ---------------------------------------------------------------------------
await step( 'The panel stops offering what is managed', async () => {
	await page.goto( URL, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 3000 );

	record(
		'The section that was taken over is no longer offered',
		( await page.locator( `#wccs-core-section-${ SECTION }` ).count() ) === 0,
		'nothing is left to adopt in it'
	);

	// O que continua por usar aparece na seção onde ele vive: o bloco conta a loja,
	// não desaparece depois da primeira adoção.
	await page.locator( '.section-tabs button' ).nth( 1 ).click();
	await page.waitForTimeout( 600 );

	const remaining = await page
		.getByRole( 'region', {
			name: 'Campos do checkout da loja nesta seção',
		} )
		.count();

	record(
		'And the rest of the store checkout is still there, in its own section',
		remaining > 0,
		'blocks=' + remaining
	);

	await page.screenshot( { path: `${ OUT }/fase3-section-managed.png` } );
} );

record(
	'No page error was raised while the screen was used',
	errors.length === 0,
	errors.join( ' | ' )
);

if ( errors.length > 0 ) {
	note( 'Page errors: ' + errors.join( ' | ' ) );
}

await browser.close();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
console.log(
	'====================================================================='
);
console.log( 'Fase 3 observation — the checkout the store already runs' );
console.log(
	'====================================================================='
);

for ( const finding of findings ) {
	console.log(
		`  ${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${
			finding.detail ? `  [${ finding.detail }]` : ''
		}`
	);
}

for ( const message of notes ) {
	console.log( `  NOTE  ${ message }` );
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log(
	'====================================================================='
);
console.log(
	`RESULT: ${ findings.length - failed } passed, ${ failed } failed, ${
		notes.length
	} notes`
);
console.log(
	'====================================================================='
);

process.exit( failed > 0 ? 1 : 0 );
