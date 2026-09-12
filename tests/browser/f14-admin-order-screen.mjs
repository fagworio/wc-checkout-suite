/**
 * The user-level observation of the order screen a member of staff opens.
 *
 * The phase's areas are not only the customer's pages: `admin_order` is the store's own
 * order screen, and the panel there is the one a person works in. This opens the real
 * order the customer flow placed and reads what the box shows: the section the links name,
 * the titles they give, the order they set — and none of the fields that belong to the
 * other areas.
 *
 * Usage:
 *   WCCS_ORDER=123 node tests/browser/f14-admin-order-screen.mjs
 *
 * The store runs WooCommerce's new orders screen (HPOS); the URL below is that screen.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const ORDER = process.env.WCCS_ORDER || '';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ ORDER }`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

const findings = [];
const record = ( label, ok, detail = '' ) => findings.push( { label, ok: Boolean( ok ), detail } );

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }` ],
} );
const page = await browser.newPage( { viewport: { width: 1600, height: 1100 } } );

for ( const pair of [ process.env.WCCS_COOKIE, process.env.WCCS_AUTH_COOKIE ].filter( Boolean ) ) {
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

page.on( 'pageerror', ( e ) => record( 'No page error on the order screen', false, e.message ) );

await page.goto( URL, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 3500 );

await page.screenshot( { path: `${ OUT }/f14-admin-order-screen.png`, fullPage: false } );

const box = page.locator( '#wccs-order-fields' );

record(
	'The plugin\'s box is on the order screen',
	( await box.count() ) > 0,
	'#wccs-order-fields'
);

const text = ( await box.count() ) > 0 ? ( await box.first().innerText() ).replace( /\s+/g, ' ' ) : '';

record(
	'The configured section titles it, with the configured titles in order',
	text.includes( 'Documentos para análise' ) &&
		text.indexOf( 'Documento fiscal' ) < text.indexOf( 'Autorização assinada' ) &&
		text.indexOf( 'Autorização assinada' ) < text.indexOf( 'Observações da entrega' ),
	text.slice( 0, 200 )
);

record(
	'And nothing linked only to a customer area is in it',
	! text.includes( 'Código de retirada' ) &&
		! text.includes( 'Campo sem vínculo' ) &&
		! text.includes( 'Como prefere ser contactado' ),
	'the thank-you-only field and the unlinked ones stay out'
);

await browser.close();

console.log( '=====================================================================' );
console.log( 'F14 user-level observation — the order screen (staff)' );
console.log( '=====================================================================' );

for ( const finding of findings ) {
	console.log(
		`  ${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${ finding.detail ? `  [${ finding.detail }]` : '' }`
	);
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log( '=====================================================================' );
console.log( `RESULT: ${ findings.length - failed } passed, ${ failed } failed` );
console.log( '=====================================================================' );

process.exit( failed > 0 ? 1 : 0 );
