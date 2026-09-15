/**
 * Fase 11 observation — what each gateway proved, on the screen that reports it.
 *
 * The harness proves the gate against the registry and the payload. This opens the screen a merchant
 * or a member of staff reads, and checks the two things the phase is about: that the presentation
 * question and the transactional one are answered side by side from two records, and that the only
 * action offered for a gateway nobody proved is WooCommerce's own pay-for-order link.
 *
 * It reads and writes nothing.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase11-gateway-capabilities.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=diagnostics`;
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

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.wccs-settings__gateways', { timeout: 30000 } );

await page.waitForFunction(
	() =>
		( document.body.textContent || '' ).includes(
			'What each gateway proved'
		),
	{ timeout: 20000 }
);

// ---------------------------------------------------------------------------
// 1. Two records, one screen.
// ---------------------------------------------------------------------------
const tables = await page.locator( '.wccs-settings__gateways' ).count();

record(
	'Both matrices are on the screen that reports them',
	2 === tables,
	'tables=' + tables
);

const presentation = await page
	.locator( '.wccs-settings__gateways' )
	.first()
	.locator( 'thead' )
	.textContent();

record(
	'And the presentation one is about presentation',
	presentation.includes( 'Mode' ) &&
		! presentation.includes( 'Actions that may be offered' ),
	( presentation || '' ).replace( /\s+/g, ' ' ).trim()
);

const transactional = await page
	.locator( '.wccs-settings__gateways' )
	.nth( 1 )
	.locator( 'thead' )
	.textContent();

record(
	'And the transactional one is about actions, with their evidence',
	transactional.includes( 'Actions that may be offered' ) &&
		transactional.includes( 'Evidence' ),
	( transactional || '' ).replace( /\s+/g, ' ' ).trim()
);

// ---------------------------------------------------------------------------
// 2. The gate, visible.
// ---------------------------------------------------------------------------
const rows = await page
	.locator( '.wccs-settings__gateways' )
	.nth( 1 )
	.locator( 'tbody tr' )
	.allInnerTexts();

record(
	'The screen lists the store gateways with what each may offer',
	rows.length > 0,
	'rows=' + rows.length
);

record(
	'And every one of them offers only the platform pay-for-order link',
	rows.every( ( row ) => row.includes( 'pay_for_order' ) ) &&
		rows.every( ( row ) => ! row.includes( 'authorize' ) ) &&
		rows.every( ( row ) => ! row.includes( 'capture' ) ),
	rows[ 0 ]?.replace( /\s+/g, ' ' ).trim() ?? ''
);

record(
	'And the screen says what the absence means, rather than showing an empty cell',
	( await page.locator( 'body' ).innerText() ).includes(
		'nenhuma ação comprovada'
	)
);

record(
	'And it states the rule the page follows',
	( await page.locator( 'body' ).innerText() ).includes(
		'Uma ação só aparece onde existe prova'
	)
);

await page.screenshot( {
	path: `${ OUT }/fase11-gateway-capabilities.png`,
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
console.log( 'Fase 11 observation — what each gateway proved' );
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
