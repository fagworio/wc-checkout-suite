/**
 * Screenshots the plugin screen and the prototype, side by side.
 *
 * A design port is verified by looking at it. This script renders the prototype from
 * the repository and the real administration screen from the store, at the same
 * window size, and writes both images so they can be compared.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const COOKIE = process.env.WCCS_COOKIE || '';
const ADMIN = `${ORIGIN}/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

const browser = await chromium.launch({
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ORIGIN}` ],
});

// The prototype, from the repository, in its own document.
const prototype = await browser.newPage({ viewport: { width: 1440, height: 950 } });
await prototype.setContent( readFileSync( 'roadmap/fields.html', 'utf8' ), { waitUntil: 'load' } );
await prototype.waitForTimeout( 600 );
await prototype.screenshot( { path: `${OUT}/prototype.png`, fullPage: false } );

// The real screen, authenticated as a store administrator.
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
// Two cookies: the front end asks whether the visitor is logged in, wp-admin asks
// whether the request may enter the administration. Sending only the first gets the
// login screen back with `reauth=1`.
for ( const pair of [ COOKIE, process.env.WCCS_AUTH_COOKIE || '' ].filter( Boolean ) ) {
	const separator = pair.indexOf( '=' );

	await page.context().addCookies( [ {
		name: pair.slice( 0, separator ),
		value: pair.slice( separator + 1 ),
		domain: 'wpagf.dvl.to',
		path: '/',
		expires: -1,
		httpOnly: true,
		secure: false,
		sameSite: 'Lax',
	} ] );
}

const errors = [];
page.on( 'pageerror', ( error ) => errors.push( String( error.message ).slice( 0, 160 ) ) );
await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 900 );
await page.screenshot( { path: `${OUT}/admin.png`, fullPage: false } );

const said = await page.evaluate( () => ( {
	url: window.location.href,
	title: document.title,
	hasMount: Boolean( document.getElementById( 'wccs-admin-root' ) ),
	bodyClass: document.body.className.slice( 0, 120 ),
	shell: Boolean( document.querySelector( '.wccs-admin .app' ) ),
	sidebar: Boolean( document.querySelector( '.wccs-admin .sidebar' ) ),
	topbar: Boolean( document.querySelector( '.wccs-admin .topbar' ) ),
	navItems: Array.from( document.querySelectorAll( '.wccs-admin .nav button span' ) ).map( ( element ) => element.textContent ),
	actions: Array.from( document.querySelectorAll( '.wccs-admin .top-actions button' ) ).map( ( element ) => ( element.getAttribute( 'aria-label' ) || element.textContent ).trim() ),
	// A port that is wider than the window it is drawn in scrolls sideways, which the
	// prototype does not. Numbers, because a screenshot at one size cannot say it.
	overflow: {
		scrollWidth: document.documentElement.scrollWidth,
		clientWidth: document.documentElement.clientWidth,
	},
} ) );

// The toast, from the same interaction in both documents: moving the first row down.
// What this compares is the toast itself — where it sits, how it is shaped, how long
// it stays — so the sentence differs only because the fields do.
// The keyboard route, not the row's arrow buttons: in the real screen those sit at the
// far right of the row and a click refuses to scroll to them, which the keyboard does
// not need. It is also the shortcut the handle's own label advertises.
const prototypeHandle = prototype.locator( '[data-drag]' ).first();
await prototypeHandle.focus();
await prototype.keyboard.press( 'Alt+ArrowDown' );
await prototype.waitForTimeout( 300 );
await prototype.screenshot( {
	path: `${OUT}/prototype-toast.png`,
	fullPage: false,
} );
const prototypeToast = await prototype.locator( '#toast' ).textContent();

const adminHandle = page.locator( '.wccs-admin .field-row .drag-handle' ).first();
await adminHandle.focus();
await page.keyboard.press( 'Alt+ArrowDown' );
await page.waitForTimeout( 300 );
await page.screenshot( { path: `${OUT}/admin-toast.png`, fullPage: false } );
const adminToast = await page.locator( '.wccs-admin .toast' ).textContent();

console.log(
	JSON.stringify(
		{
			...said,
			toast: { prototype: prototypeToast, admin: adminToast },
			errors,
		},
		null,
		1
	)
);
await browser.close();
