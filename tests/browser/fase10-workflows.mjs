/**
 * Fase 10 observation — the automation screen, as a merchant meets it.
 *
 * The harness drives the engine against a real order; the unit and screen suites prove the model
 * and the component. What neither shows is the screen against the real server: that the vocabulary
 * the store publishes is the one the editor offers, that the strategies it cannot execute are
 * absent from the selects and named in a notice, that saving writes an automation the store keeps,
 * and that the simulator answers without writing.
 *
 * It reads and clicks, and it removes the automation it created at the end.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase10-workflows.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=workflows`;
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
	viewport: { width: 1600, height: 1100 },
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
 * Reads the automations through the route the screen uses.
 *
 * @return {Promise<any>} Answer, or null.
 */
async function read() {
	return page.evaluate( async () => {
		const config = window.wccsAdmin ?? {};
		const rest = config.rest ?? {};
		const url =
			( rest.root ?? '' ) +
			( rest.namespace ?? '' ) +
			( rest.routes?.workflows ?? '' );

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': rest.nonce ?? '' },
		} );

		return response.ok ? response.json() : null;
	} );
}

/**
 * Waits until the store holds the given number of automations.
 *
 * @param {number} expected Count.
 * @return {Promise<Array<any>>} Stored automations.
 */
async function waitFor( expected ) {
	let stored = [];

	for ( let attempt = 0; attempt < 40; attempt++ ) {
		const answer = await read();

		stored = Array.isArray( answer?.workflows ) ? answer.workflows : [];

		if ( stored.length === expected ) {
			return stored;
		}

		await page.waitForTimeout( 250 );
	}

	return stored;
}

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '#workflowsTitle', { timeout: 30000 } );

// ---------------------------------------------------------------------------
// 1. The screen, and the vocabulary it is written with.
// ---------------------------------------------------------------------------
await step( 'The screen draws the design steps', async () => {
	const body = await page.locator( 'body' ).innerText();

	record(
		'The heading is the design one',
		( await page.locator( '#workflowsTitle' ).innerText() ).includes(
			'Automação de status'
		)
	);
	record(
		'And it says an automation changes states and charges nothing',
		body.includes( 'não cobra nada' )
	);

	const vocabulary = ( await read() )?.vocabulary ?? {};

	record(
		'The route publishes the vocabulary the editor is written with',
		Array.isArray( vocabulary.triggers ) &&
			Array.isArray( vocabulary.decisions ) &&
			Array.isArray( vocabulary.events ),
		'triggers=' +
			( vocabulary.triggers ?? [] )
				.map( ( entry ) => entry.value )
				.join( ',' )
	);
	record(
		'And it says which strategies the store can execute today',
		JSON.stringify( vocabulary.executable ) ===
			JSON.stringify( { inventory: [ 'none' ], payment: [ 'none' ] } ),
		'executable=' + JSON.stringify( vocabulary.executable )
	);
} );

// ---------------------------------------------------------------------------
// 2. What the screen does not offer, and says so.
// ---------------------------------------------------------------------------
await step( 'A new automation is created and its steps are drawn', async () => {
	await page.getByRole( 'button', { name: /Nova automação/ } ).click();

	await page.waitForSelector( '#wccs-workflow-name', { timeout: 10000 } );

	const body = await page.locator( 'body' ).innerText();

	// The panel's `textContent` rather than the page's `innerText`: both hold the steps, and only
	// the first one is already there the moment the editor is in the tree — the second is computed
	// from layout, and a check that read it here was reading the panel before the browser had laid
	// it out.
	const editor = await page
		.locator( '.wccs-workflows__editor' )
		.evaluate( ( node ) => node.textContent || '' );
	const drawn = [
		'Passo 1',
		'Passo 2',
		'Passo 3',
		'Passo 4',
		'Passo 5',
		'Passo 6',
	].filter( ( heading ) => editor.includes( heading ) );

	record(
		'The six steps of the design are drawn',
		6 === drawn.length,
		'drawn=' + drawn.join( ' | ' )
	);

	const stock = await page
		.locator( '#wccs-workflow-inventory option' )
		.evaluateAll( ( nodes ) => nodes.map( ( node ) => node.value ) );
	const payment = await page
		.locator( '#wccs-workflow-payment option' )
		.evaluateAll( ( nodes ) => nodes.map( ( node ) => node.value ) );

	record(
		'And the selects offer only what the store can execute',
		[ 'none' ].join() === stock.join() &&
			[ 'none' ].join() === payment.join(),
		'stock=' + stock.join( ',' ) + ' payment=' + payment.join( ',' )
	);
	record(
		'And the ones it cannot are named, with the reason',
		body.includes( 'não são oferecidas' ) &&
			body.includes( 'capabilities do gateway' )
	);
} );

// ---------------------------------------------------------------------------
// 3. Saving writes an automation the store keeps.
// ---------------------------------------------------------------------------
await step(
	'The automation is saved and the store assigns its identifier',
	async () => {
		await page.locator( '#wccs-workflow-name' ).fill( 'Produtos químicos' );

		// A state to wait in is what the screen insists on before it will save.
		const initial = page.locator( '#wccs-workflow-initial' );
		const options = await initial
			.locator( 'option' )
			.evaluateAll( ( nodes ) =>
				nodes
					.map( ( node ) => node.value )
					.filter( ( value ) => '' !== value )
			);

		if ( options.length > 0 ) {
			await initial.selectOption( options[ 0 ] );
		}

		await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

		const stored = await waitFor( 1 );

		record(
			'The store holds the automation, with an identifier derived from its name',
			1 === stored.length && 'produtos_quimicos' === stored[ 0 ].id,
			stored.map( ( entry ) => entry.id ).join( ',' )
		);
		record(
			'And it carries no strategy the store cannot execute',
			'none' === stored[ 0 ]?.payment_strategy &&
				'none' === stored[ 0 ]?.inventory_strategy
		);

		await page.screenshot( { path: `${ OUT }/fase10-workflows.png` } );
	}
);

// ---------------------------------------------------------------------------
// 4. The simulator answers, and writes nothing.
// ---------------------------------------------------------------------------
await step(
	'The simulator answers a scenario without saving anything',
	async () => {
		await page.locator( '#wccs-sim-categories' ).fill( 'quimicos' );
		await page.getByRole( 'button', { name: /Testar cenário/ } ).click();

		await page.waitForSelector( '.wccs-workflows__result', {
			timeout: 10000,
		} );

		const answer = await page
			.locator( '.wccs-workflows__result' )
			.innerText();
		const stored = await read();

		record(
			'The simulation answers what the configuration would decide',
			answer.includes( 'entra em' ) ||
				answer.includes( 'Nenhum workflow' ),
			answer.split( '\n' )[ 1 ] ?? ''
		);
		record(
			'And nothing was written by simulating',
			1 === ( stored?.workflows ?? [] ).length
		);
	}
);

// ---------------------------------------------------------------------------
// 5. The screen is left the way it was found.
// ---------------------------------------------------------------------------
await step(
	'The automation this observation created is removed again',
	async () => {
		await page.getByRole( 'button', { name: /Remover automação/ } ).click();
		await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

		const left = await waitFor( 0 );

		record( 'The store has no automations again', 0 === left.length );
	}
);

record(
	'No page error was raised while the screen was used',
	errors.length === 0,
	errors.join( ' | ' )
);

await browser.close();

console.log(
	'====================================================================='
);
console.log( 'Fase 10 observation — the automation screen' );
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
