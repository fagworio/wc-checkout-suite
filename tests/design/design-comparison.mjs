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
// Switching to Blocks is the announcement that does not depend on what the store
// happens to hold: it is offered on every document, and both screens answer it the
// same way. (Dragging a row needs two rows in one section, which a store is free not
// to have.)
await prototype.locator( '[data-mode="blocks"]' ).first().click();
await prototype.waitForTimeout( 300 );
await prototype.screenshot( {
	path: `${OUT}/prototype-toast.png`,
	fullPage: false,
} );
const prototypeToast = await prototype.locator( '#toast' ).textContent();

await page
	.locator( '.wccs-admin .contextbar .segmented button' )
	.nth( 1 )
	.click();
await page.waitForTimeout( 300 );
await page.screenshot( { path: `${OUT}/admin-toast.png`, fullPage: false } );
const adminToast = await page.locator( '.wccs-admin .toast' ).textContent();

// The preview, which is the screen where the two documents differ most: the prototype
// fills it from its demo document and this one from the draft.
await prototype.locator( '[data-view="preview"]' ).first().click();
await prototype.waitForTimeout( 400 );
await prototype.screenshot( { path: `${OUT}/prototype-preview.png`, fullPage: false } );
const prototypePreview = await prototype.evaluate( () => ( {
	fields: document.querySelectorAll( '#previewFields .public-field' ).length,
	sections: document.querySelectorAll( '#previewFields .store-section' ).length,
	caption: document.querySelector( '#previewCaption' )?.textContent ?? '',
} ) );

const preview = await browser.newPage( { viewport: { width: 1440, height: 950 } } );
await preview.context().addCookies( await page.context().cookies() );
preview.on( 'pageerror', ( error ) => errors.push( String( error.message ).slice( 0, 160 ) ) );
await preview.goto( ADMIN.replace( 'section=fields', 'section=appearance' ), {
	waitUntil: 'networkidle',
	timeout: 45000,
} );
await preview.waitForTimeout( 900 );
await preview.screenshot( { path: `${OUT}/admin-preview.png`, fullPage: false } );
const adminPreview = await preview.evaluate( () => ( {
	fields: document.querySelectorAll( '#previewView .public-field' ).length,
	sections: document.querySelectorAll( '#previewView .store-section' ).length,
	caption: document.querySelector( '#previewView .preview-caption' )?.textContent ?? '',
	brand: document.querySelector( '#previewView .store-brand' )?.textContent ?? '',
	device: document.querySelector( '#previewView .preview-shell' )?.className ?? '',
} ) );

console.log(
	JSON.stringify(
		{
			...said,
			toast: { prototype: prototypeToast, admin: adminToast },
			preview: { prototype: prototypePreview, admin: adminPreview },
			errors,
		},
		null,
		1
	)
);
await browser.close();
