/**
 * Screenshots the two dialogs the design draws bodies for, against real data.
 *
 * The publication review and the history are the two surfaces where the design says
 * something the screen can only say with a published revision behind it. This script
 * signs in, opens both from where the interface opens them, and prints what each one
 * reports — so the numbers in the images can be checked against the store.
 *
 * It expects a store with a published revision and a draft ahead of it; both
 * `seed-design-draft.php` and a publication provide that pair.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const ADMIN = `${ORIGIN}/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ORIGIN}` ],
} );
const page = await browser.newPage( { viewport: { width: 1440, height: 950 } } );

for ( const pair of [ process.env.WCCS_COOKIE, process.env.WCCS_AUTH_COOKIE ].filter( Boolean ) ) {
	const i = pair.indexOf( '=' );

	await page.context().addCookies( [ {
		name: pair.slice( 0, i ),
		value: pair.slice( i + 1 ),
		domain: 'wpagf.dvl.to',
		path: '/',
		expires: -1,
		httpOnly: true,
		secure: false,
		sameSite: 'Lax',
	} ] );
}

/**
 * Opens the prototype at the design's phone width.
 *
 * @param {any} instance Browser.
 * @return {Promise<any>} Page.
 */
async function prototype( instance ) {
	const narrow = await instance.newPage( {
		viewport: { width: 390, height: 844 },
	} );

	await narrow.setContent(
		readFileSync( 'roadmap/fields.html', 'utf8' ),
		{ waitUntil: 'load' }
	);
	await narrow.waitForTimeout( 600 );

	return narrow;
}

const errs = [];

page.on( 'pageerror', ( e ) => errs.push( String( e.message ).slice( 0, 300 ) ) );
await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 1200 );

// The publication review, from the topbar.
await page.getByRole( 'button', { name: 'Revisar publicação' } ).click();
await page.waitForTimeout( 500 );
await page.screenshot( { path: `${OUT}/admin-publish.png`, fullPage: false } );
const publish = await page.evaluate( () => ( {
	stats: Array.from( document.querySelectorAll( '.publish-stat' ) ).map( ( s ) => s.textContent ),
	rows: Array.from( document.querySelectorAll( '.diff-row' ) ).map( ( r ) => r.textContent ),
	subtitle: document.querySelector( '.publish-subtitle' )?.textContent ?? '',
} ) );

await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 300 );

// The history, from the editor's footer. Clicked through the DOM because wp-admin's
// own footer overlaps that strip of the screen — which is itself worth knowing.
await page.evaluate( () => {
	const button = Array.from( document.querySelectorAll( 'button' ) ).find(
		( candidate ) => 'Revisões' === candidate.textContent.trim()
	);

	button?.click();
} );
await page.waitForTimeout( 500 );
await page.screenshot( { path: `${OUT}/admin-history.png`, fullPage: false } );
const history = await page.evaluate( () =>
	Array.from( document.querySelectorAll( '.history-row' ) ).map( ( r ) => r.textContent )
);

// The inspector below the design's 870px breakpoint: the column is hidden by the
// stylesheet and the same properties open over the list, in both documents.
const narrowPrototype = await prototype( browser );
await narrowPrototype.locator( '[data-edit-field]' ).first().click();
await narrowPrototype.waitForTimeout( 400 );
await narrowPrototype.screenshot( {
	path: `${OUT}/prototype-mobile-inspector.png`,
	fullPage: false,
} );
const prototypeMobile = await narrowPrototype.evaluate( () => ( {
	open: Boolean( document.querySelector( '#mobileInspector' )?.open ),
	column: getComputedStyle( document.querySelector( '#inspector' ) ).display,
} ) );

const mobile = await browser.newPage( { viewport: { width: 390, height: 844 } } );
await mobile.context().addCookies( await page.context().cookies() );
mobile.on( 'pageerror', ( e ) => errs.push( String( e.message ).slice( 0, 160 ) ) );
await mobile.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await mobile.waitForTimeout( 900 );
await mobile.locator( '.wccs-admin .field-info' ).first().click();
await mobile.waitForTimeout( 400 );
await mobile.screenshot( {
	path: `${OUT}/admin-mobile-inspector.png`,
	fullPage: false,
} );
const adminMobile = await mobile.evaluate( () => ( {
	open: Boolean( document.querySelector( '.mobile-inspector' )?.open ),
	column: getComputedStyle( document.querySelector( '.wccs-admin .inspector' ) )
		.display,
	head: document.querySelector( '.mobile-inspector-top strong' )?.textContent ?? '',
} ) );

console.log(
	JSON.stringify(
		{
			publish,
			history,
			mobileInspector: { prototype: prototypeMobile, admin: adminMobile },
			errs,
		},
		null,
		1
	)
);
await browser.close();
