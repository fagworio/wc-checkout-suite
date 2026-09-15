/**
 * Fase 14 observation — one checkout composes its own sections.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.4 gives the checkout
 * destination the composition it draws: a strip of checkouts at the top, a list of sections on the
 * left, and the list belongs to whichever checkout is selected. Fase 7 left the model and the
 * runtime in place and said so on screen; this phase closed the editor half, and what a unit test
 * cannot show is the screen a merchant actually uses:
 *
 *   1. **The store's own checkout is what the screen opens on**, with its own list of sections.
 *   2. **A new checkout starts from the store's own composition**, and the strip says the list on the
 *      left is now that checkout's.
 *   3. **A section added while a checkout is selected is that checkout's**, and the store's own list
 *      does not grow — the rule §6.4 draws, proven by reading the stored document rather than the
 *      screen.
 *   4. **Switching back to the store's own checkout shows its own list again**, with the section
 *      that belongs to the other one absent.
 *
 * It writes, and it puts the store back: the checkout it creates is removed and the document saved
 * again, so a run leaves the draft as it found it.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase14-custom-checkout.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 *
 * @package WCCheckoutSuite
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
const page = await browser.newPage( {
	viewport: { width: 1600, height: 1200 },
} );

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
 * Reads the draft document through the route the screen uses.
 *
 * @return {Promise<any>} Document, or null.
 */
async function read() {
	return page.evaluate( async () => {
		const boot = /** @type {any} */ ( window ).wccsAdmin ?? {};
		const rest = boot.rest ?? {};
		const response = await fetch(
			( rest.root ?? '' ) +
				( rest.namespace ?? '' ) +
				( rest.routes?.draft ?? '' ),
			{
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': rest.nonce ?? '' },
			}
		);

		if ( ! response.ok ) {
			return null;
		}

		const answer = await response.json();

		return answer?.document ?? answer;
	} );
}

/**
 * The identifiers of the sections on the left, in the order they are listed.
 *
 * @return {Promise<Array<string>>} Identifiers.
 */
async function listedSections() {
	return page.evaluate( () =>
		Array.from(
			document.querySelectorAll( '.section-tabs [aria-pressed]' )
		).map( ( node ) => node.textContent.trim() )
	);
}

/**
 * Waits until the stored document satisfies a predicate.
 *
 * @param {Function} predicate Test over the document.
 * @return {Promise<any>} Document that satisfied it.
 */
async function waitFor( predicate ) {
	let document_ = null;

	for ( let attempt = 0; attempt < 40; attempt++ ) {
		document_ = await read();

		if ( document_ && predicate( document_ ) ) {
			return document_;
		}

		await page.waitForTimeout( 250 );
	}

	return document_;
}

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.section-tabs', { timeout: 30000 } );
await page.waitForSelector( '#editorTitle', { timeout: 30000 } );

const NEW_SECTION = 'Documentação digital';

// ---------------------------------------------------------------------------
// 1. The store's own checkout, and its own list of sections.
// ---------------------------------------------------------------------------
await step( 'The screen opens on the store own checkout', async () => {
	const draft = await read();

	record(
		'The store has no alternative checkout yet',
		Array.isArray( draft?.sections ) &&
			0 === ( draft.profiles ?? [] ).length,
		'sections=' +
			( draft?.sections ?? [] ).length +
			' profiles=' +
			( draft?.profiles ?? [] ).length
	);

	// The list is never empty even on a document that declares no container: a field bound to a
	// location the store has is an implied section, and §6.2 asks the screen to show the checkout the
	// store runs rather than an empty one.
	record(
		'And it lists the sections of the store own checkout',
		( await listedSections() ).length > 0,
		( await listedSections() ).join( ' | ' )
	);

	record(
		'And the strip has the store own tab selected',
		await page
			.locator( '.wccs-checkouts__strip [role="tab"][aria-selected="true"]' )
			.innerText()
			.then( ( text ) => text.includes( 'Checkout padrão' ) )
	);

	await page.screenshot( { path: `${ OUT }/fase14-checkout-proprio.png` } );
} );

// ---------------------------------------------------------------------------
// 2. A new checkout, and the list that becomes its own.
// ---------------------------------------------------------------------------
await step( 'A new checkout is created from the store own one', async () => {
	await page.getByRole( 'button', { name: /Novo checkout/ } ).click();
	await page.waitForSelector( '#wccs-profile-name', { timeout: 10000 } );
	await page.locator( '#wccs-profile-name' ).fill( 'Checkout digital' );
	await page
		.getByRole( 'button', { name: /Criar checkout/ } )
		.click();

	// Nothing is written yet, and that is the rule this screen keeps: creating a checkout is an edit
	// to the draft, and the draft is stored by «Salvar alterações» and by nothing else.
	const stored = await read();

	await page
		.getByRole( 'tab', { name: 'Checkout digital' } )
		.waitFor( { timeout: 10000 } );

	record(
		'The store now has one alternative checkout on the strip',
		1 === ( await page.getByRole( 'tab', { name: 'Checkout digital' } ).count() )
	);

	record(
		'And creating it wrote nothing, because this screen saves in one action',
		0 === ( stored?.profiles ?? [] ).length,
		'stored profiles=' + ( stored?.profiles ?? [] ).length
	);

	record(
		'And the strip says the list on the left is that checkout own composition',
		await page
			.locator( '.wccs-checkouts__detail' )
			.innerText()
			.then( ( text ) => text.includes( 'composição deste checkout' ) )
	);
} );

// ---------------------------------------------------------------------------
// 3. A section added here is that checkout's, and not the store's.
// ---------------------------------------------------------------------------
await step( 'A section added with a checkout selected belongs to it', async () => {
	await page.getByRole( 'tab', { name: 'Checkout digital' } ).click();
	await page.waitForTimeout( 300 );

	await page.getByRole( 'button', { name: /Nova seção/ } ).click();
	await page.waitForSelector( '#wccs-new-section-title', { timeout: 10000 } );
	await page.locator( '#wccs-new-section-title' ).fill( NEW_SECTION );
	await page
		.getByRole( 'button', { name: /Adicionar seção/ } )
		.click();

	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	const draft = await waitFor(
		( document_ ) =>
			( document_.profiles?.[ 0 ]?.sections ?? [] ).some(
				( section ) => section.title === NEW_SECTION
			)
	);

	const profile = ( draft?.profiles ?? [] )[ 0 ] ?? {};
	const owned = ( profile.sections ?? [] ).map(
		( section ) => section.title
	);
	const store = ( draft?.sections ?? [] ).map(
		( section ) => section.title
	);

	record(
		'The section is stored in that checkout, and the store own list did not grow',
		owned.includes( NEW_SECTION ) && ! store.includes( NEW_SECTION ),
		'owned=' + owned.join( ' | ' ) + ' :: store=' + store.join( ' | ' )
	);

	record(
		'And the screen lists it while that checkout is selected',
		( await listedSections() ).some( ( label ) =>
			label.includes( NEW_SECTION )
		),
		( await listedSections() ).join( ' | ' )
	);

	await page.screenshot( { path: `${ OUT }/fase14-checkout-digital.png` } );
} );

// ---------------------------------------------------------------------------
// 4. Back to the store's own checkout, and its own list.
// ---------------------------------------------------------------------------
await step( 'The store own checkout keeps its own list', async () => {
	await page.getByRole( 'tab', { name: /Checkout padrão/ } ).click();
	await page.waitForTimeout( 300 );

	record(
		'The section that belongs to the other checkout is not listed here',
		! ( await listedSections() ).some( ( label ) =>
			label.includes( NEW_SECTION )
		),
		( await listedSections() ).join( ' | ' )
	);
} );

// ---------------------------------------------------------------------------
// 5. The store is left the way it was found.
// ---------------------------------------------------------------------------
await step( 'The checkout this observation created is removed again', async () => {
	await page.getByRole( 'tab', { name: 'Checkout digital' } ).click();
	await page.waitForTimeout( 300 );
	await page.getByRole( 'button', { name: /Excluir checkout/ } ).click();
	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	const draft = await waitFor(
		( document_ ) => 0 === ( document_.profiles ?? [] ).length
	);

	record(
		'The store has no alternative checkout again',
		0 === ( draft?.profiles ?? [] ).length,
		'profiles=' + ( draft?.profiles ?? [] ).length
	);
} );

record(
	'No page error was raised while the screen was used',
	errors.length === 0,
	errors.join( ' | ' )
);

await browser.close();

console.log(
	'====================================================================='
);
console.log( 'Fase 14 observation — the composition of a checkout' );
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

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log(
	'====================================================================='
);
console.log(
	`RESULT: ${ findings.length - failed } passed, ${ failed } failed`
);
console.log(
	'====================================================================='
);

process.exit( failed > 0 ? 1 : 0 );
