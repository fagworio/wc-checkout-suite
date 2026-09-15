/**
 * The user-level observation of the uses of a field, in the administration.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.3 says one
 * definition may be used several times, each use with its own container, order, title and
 * editable decision. The destination map cannot say that — it holds one entry per
 * destination — so the editor has to write the list, and this script proves it does by
 * driving the screen and then reading what the store holds:
 *
 *   1. **A second use can be added** in a destination the field is already on, with its own
 *      container, title, order and read-only decision.
 *   2. **Saving writes the list**, and the store keeps both uses with distinct identifiers —
 *      two uses of one field in one container would otherwise answer to the same name.
 *   3. **The map follows the list**, so every surface that still reads the map sees the same
 *      configuration, and the destinations that were not touched are untouched.
 *   4. **The screen reads the list back**: opening the field again shows both uses with the
 *      values that were typed, and nothing left to save.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/f14-bindings-observation.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`;
 * the draft comes from `wp eval-file tests/Integration/support/seed-f14-links.php`.
 *
 * @package WCCheckoutSuite
 */

import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const FIELD = 'arquivo_autorizacao';
const DESTINATION = 'admin_order';

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
 * Opens one field's inspector on the links tab.
 *
 * @param {string} listSection Section tab the field lives in.
 * @param {string} fieldId     Technical key of the field.
 * @return {Promise<void>} Resolves once the panel is rendered.
 */
async function openField( listSection, fieldId ) {
	await page
		.getByRole( 'button', { name: new RegExp( `^${ listSection }` ) } )
		.first()
		.click();
	await page.waitForTimeout( 500 );

	await page.locator( `text=${ fieldId }` ).first().click();
	await page.waitForTimeout( 800 );

	await page
		.getByRole( 'button', { name: 'Vínculos', exact: true } )
		.first()
		.click();
	await page.waitForTimeout( 600 );
}

/**
 * The draft the store holds, read through the route the screen reads it with.
 *
 * The page is the authenticated client here: it holds the cookies and the REST nonce the
 * shell was booted with, so this asks the server the same question the screen asks.
 *
 * @return {Promise<any>} The draft document.
 */
async function storedDraft() {
	return page.evaluate( async () => {
		const boot = /** @type {any} */ ( window ).wccsAdmin;
		const response = await fetch(
			boot.rest.root +
				boot.rest.namespace +
				boot.rest.routes.draft,
			{ headers: { 'X-WP-Nonce': boot.rest.nonce } }
		);

		return response.json();
	} );
}

/**
 * The stored definition of the field this run works on.
 *
 * @return {Promise<any>} The field, or null.
 */
async function storedField() {
	const draft = await storedDraft();

	return ( draft?.fields ?? [] ).find( ( field ) => field?.id === FIELD ) ?? null;
}

await page.goto( URL, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 2500 );

// ---------------------------------------------------------------------------
// 1. A second use of the field in one destination.
// ---------------------------------------------------------------------------
await step( 'A second use of the field can be added in a destination', async () => {
	await openField( 'Instruções de entrega', FIELD );

	const before = await page
		.locator( `#wccs-link-title-${ DESTINATION }` )
		.first()
		.inputValue();

	record(
		'The field starts with the single use the document holds',
		before === 'Autorização assinada',
		'title=' + before
	);

	// The link the merchant already had is the first use; the button adds the second.
	await page.locator( `#wccs-link-add-${ DESTINATION }` ).first().click();
	await page.waitForTimeout( 400 );

	const sections = await page
		.locator( `[id^="wccs-link-section-${ DESTINATION }"]` )
		.count();

	record(
		'The area now offers two uses, each with its own controls',
		sections === 2,
		'controls=' + sections
	);
} );

await step( 'The second use carries its own container, title and order', async () => {
	// The seed declares one container for the staff area, so the second use is written in
	// the same place as the first: two uses of one field in one container and destination,
	// which the identifiers have to keep apart.
	await page
		.locator( `#wccs-link-section-${ DESTINATION }-2` )
		.selectOption( 'documentos_para_analise' );
	await page
		.locator( `#wccs-link-title-${ DESTINATION }-2` )
		.fill( 'Autorização conferida' );
	await page
		.locator( `#wccs-link-position-${ DESTINATION }-2` )
		.fill( '30' );
	// Read-only where the first use is writable: the decision belongs to the use.
	await page.locator( `#wccs-link-editable-${ DESTINATION }-2` ).uncheck();
	await page.waitForTimeout( 300 );

	await page.screenshot( { path: `${ OUT }/f14-bindings-two-uses.png` } );
} );

// ---------------------------------------------------------------------------
// 2. Saving writes the list and the map.
// ---------------------------------------------------------------------------
await step( 'Saving writes the list of uses the store holds', async () => {
	await page.evaluate( () => window.sessionStorage.clear() );
	await page.waitForTimeout( 200 );

	const saveButton = page
		.getByRole( 'button', { name: /Salvar alterações|Save changes/ } )
		.first();

	await saveButton.click();
	await page.waitForTimeout( 4000 );

	const field = await storedField();
	const uses = ( field?.bindings ?? [] ).filter(
		( binding ) => binding.destination === DESTINATION
	);

	record(
		'The store holds two uses of the field in that destination',
		uses.length === 2,
		'uses=' + uses.length
	);

	record(
		'Each use has an identifier of its own',
		uses.length === 2 && uses[ 0 ].id !== uses[ 1 ].id,
		uses.map( ( binding ) => binding.id ).join( ' | ' )
	);

	const second = uses[ 1 ] ?? {};

	record(
		'The second use kept its container, title and order',
		second.container_id === 'documentos_para_analise' &&
			second.label_override === 'Autorização conferida' &&
			Number( second.position ) === 30,
		`container=${ second.container_id } title=${ second.label_override } position=${ second.position }`
	);

	record(
		'And it is read-only while the first use stays writable',
		false === second.editable && true === uses[ 0 ]?.editable,
		`first=${ uses[ 0 ]?.editable } second=${ second.editable }`
	);

	record(
		'The document stores the list, not only the map',
		Array.isArray( field?.bindings ) && field.bindings.length >= 2,
		'bindings=' + ( field?.bindings ?? [] ).length
	);

	record(
		'The map is the projection of the list',
		field?.destinations?.[ DESTINATION ]?.enabled === true &&
			field?.destinations?.[ DESTINATION ]?.title ===
				'Autorização conferida',
		'map title=' + field?.destinations?.[ DESTINATION ]?.title
	);

	record(
		'The destinations that were not touched are untouched',
		field?.destinations?.customer_order?.title === 'Enviado por você' &&
			field?.destinations?.customer_email?.title === 'Documento do pedido',
		'customer_order=' +
			field?.destinations?.customer_order?.title +
			' customer_email=' +
			field?.destinations?.customer_email?.title
	);
} );

// ---------------------------------------------------------------------------
// 3. The screen reads the list back.
// ---------------------------------------------------------------------------
await step( 'The screen reads both uses back', async () => {
	await page.goto( URL, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 2500 );
	await openField( 'Instruções de entrega', FIELD );

	const titles = await page
		.locator( `[id^="wccs-link-title-${ DESTINATION }"]` )
		.evaluateAll( ( nodes ) => nodes.map( ( node ) => node.value ) );

	record(
		'The panel shows both uses after the save',
		titles.length === 2,
		titles.join( ' | ' )
	);

	record(
		'And the values typed are the ones it shows',
		titles[ 1 ] === 'Autorização conferida',
		'titles=' + titles.join( ' | ' )
	);

	const addButton = page.locator( `#wccs-link-add-${ DESTINATION }` ).first();

	record(
		'The area still offers another use to add',
		( await addButton.count() ) > 0,
		'the list is open-ended, not a single slot'
	);
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
console.log( 'F14 user-level observation — the uses of a field' );
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
