/**
 * Fase 9 observation — the statuses screen, as a merchant meets it.
 *
 * The harness walks §12.7 against a real order and proves the store's half. What it cannot show is
 * the screen: that the states the merchant configured and the ones WooCommerce owns are two
 * different things on it, that a state says it is created without an identifier, that the screen
 * states where the payment decision lives, and that saving really writes a state the order list then
 * has.
 *
 * It reads and clicks, and the writes it performs are the ones it is there to observe. The state it
 * creates is removed again at the end.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase9-order-statuses.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=statuses`;
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
 * Reads the statuses through the route the screen uses.
 *
 * @return {Promise<any>} Answer, or null.
 */
async function readStatuses() {
	const answer = await page.evaluate( async () => {
		const config = window.wccsAdmin ?? {};
		const rest = config.rest ?? {};
		const url =
			( rest.root ?? '' ) +
			( rest.namespace ?? '' ) +
			( rest.routes?.statuses ?? '' );

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': rest.nonce ?? '' },
		} );

		return response.ok ? response.json() : null;
	} );

	return answer;
}

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '#statusesTitle', { timeout: 30000 } );

// ---------------------------------------------------------------------------
// 1. The screen says what it is for.
// ---------------------------------------------------------------------------
await step( 'The screen states that a status is not a command', async () => {
	record(
		'The heading is the design one',
		( await page.locator( '#statusesTitle' ).innerText() ).includes(
			'Status personalizados'
		)
	);
	record(
		'And it says a state does not charge anything',
		( await page.locator( 'body' ).innerText() ).includes(
			'não cobra nada'
		)
	);
	record(
		'And it says who charges: a workflow transition, and not the state',
		( await page.locator( 'body' ).innerText() ).includes(
			'transição de workflow'
		)
	);
} );

// ---------------------------------------------------------------------------
// 2. What the store has, in two groups.
// ---------------------------------------------------------------------------
await step( 'The list separates the merchant states from the platform ones', async () => {
	const inventory = await readStatuses();

	record(
		'The route answers with the inventory and what WooCommerce considers paid',
		Array.isArray( inventory?.statuses ) &&
			Array.isArray( inventory?.paid ),
		'paid=' + ( inventory?.paid ?? [] ).join( ',' )
	);
	record(
		'And the platform states are listed as not the merchant own',
		( inventory?.statuses ?? [] ).some(
			( entry ) => entry && ! entry.custom
		),
		'core=' +
			( inventory?.statuses ?? [] )
				.filter( ( entry ) => entry && ! entry.custom )
				.map( ( entry ) => entry.id )
				.join( ',' )
	);
} );

// ---------------------------------------------------------------------------
// 3. A new state, and what it says about itself before it is saved.
// ---------------------------------------------------------------------------
await step( 'A new state is created without an identifier', async () => {
	await page.getByRole( 'button', { name: /Novo estado/ } ).click();

	record(
		'The screen says the identifier is created when it is saved',
		( await page.locator( 'body' ).innerText() ).includes(
			'o identificador é criado ao guardar'
		)
	);
	record(
		'And the slug field is empty, because the store will assign it',
		'' === ( await page.locator( '#wccs-status-id' ).inputValue() )
	);
	record(
		'And a state that is not named cannot be saved',
		await page
			.getByRole( 'button', { name: /Salvar alterações/ } )
			.isDisabled()
	);
} );

// ---------------------------------------------------------------------------
// 4. Saving writes a state the store registers.
// ---------------------------------------------------------------------------
await step( 'The state is saved and the store registers it', async () => {
	await page.locator( '#wccs-status-label' ).fill( 'Análise pendente' );
	await page
		.locator( '#wccs-status-customer-label' )
		.fill( 'Estamos a analisar o seu pedido' );
	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	let stored = null;

	for ( let attempt = 0; attempt < 40 && null === stored; attempt++ ) {
		const inventory = await readStatuses();
		const found = ( inventory?.statuses ?? [] ).find(
			( entry ) =>
				entry &&
				entry.custom &&
				'Análise pendente' === entry.label
		);

		if ( found ) {
			stored = { entry: found, paid: inventory.paid ?? [] };
			break;
		}

		await page.waitForTimeout( 250 );
	}

	record(
		'The store assigned a readable identifier',
		null !== stored && 'analise_pendente' === stored.entry.id,
		stored ? 'id=' + stored.entry.id : 'not stored'
	);
	record(
		'And it is registered',
		null !== stored && true === stored.entry.registered
	);
	record(
		'And the customer label the merchant wrote is kept',
		null !== stored &&
			'Estamos a analisar o seu pedido' ===
				stored.entry.customer_label
	);
	record(
		'And a state before payment is not in the paid list, which the screen says',
		null !== stored &&
			! stored.paid.includes( stored.entry.id ) &&
			( await page.locator( 'body' ).innerText() ).includes(
				'não está na lista'
			),
		stored ? 'paid=' + stored.paid.join( ',' ) : ''
	);

	await page.screenshot( { path: `${ OUT }/fase9-order-statuses.png` } );
} );

// ---------------------------------------------------------------------------
// 5. Renaming keeps the state.
// ---------------------------------------------------------------------------
await step( 'Renaming the state keeps the identifier the orders hold', async () => {
	await page.locator( '#wccs-status-label' ).fill( 'Em análise' );
	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	let stored = null;

	for ( let attempt = 0; attempt < 40 && null === stored; attempt++ ) {
		const inventory = await readStatuses();
		const found = ( inventory?.statuses ?? [] ).find(
			( entry ) => entry && entry.custom && 'Em análise' === entry.label
		);

		if ( found ) {
			stored = found;
			break;
		}

		await page.waitForTimeout( 250 );
	}

	record(
		'The label changed and the identifier did not',
		null !== stored && 'analise_pendente' === stored.id,
		stored ? 'id=' + stored.id : 'not stored'
	);
} );

// ---------------------------------------------------------------------------
// 6. The screen is left the way it was found.
// ---------------------------------------------------------------------------
await step( 'The state this observation created is removed again', async () => {
	await page.getByRole( 'button', { name: /Remover estado/ } ).click();
	await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

	/** @type {Array<any>} */
	let left = [];

	for ( let attempt = 0; attempt < 40; attempt++ ) {
		const inventory = await readStatuses();

		left = ( inventory?.statuses ?? [] ).filter(
			( entry ) => entry && entry.custom
		);

		if ( 0 === left.length ) {
			break;
		}

		// The save is a request, so the answer is waited for rather than read once: a single
		// read taken before it lands would report the state that was just removed.
		await page.waitForTimeout( 250 );
	}

	record(
		'The store has no custom states again',
		0 === left.length,
		'left=' + left.map( ( entry ) => entry.id ).join( ',' )
	);
} );

record( 'No page error was raised while the screen was used', errors.length === 0, errors.join( ' | ' ) );

await browser.close();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
console.log( '=====================================================================' );
console.log( 'Fase 9 observation — the statuses screen' );
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
