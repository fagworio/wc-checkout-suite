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
const ADMIN = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }`,
	],
} );
const page = await browser.newPage( {
	viewport: { width: 1440, height: 950 },
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

	await narrow.setContent( readFileSync( 'roadmap/fields.html', 'utf8' ), {
		waitUntil: 'load',
	} );
	await narrow.waitForTimeout( 600 );

	return narrow;
}

const errs = [];

page.on( 'pageerror', ( e ) =>
	errs.push( String( e.message ).slice( 0, 300 ) )
);
await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 1200 );

// The publication review, from the topbar.
await page.getByRole( 'button', { name: 'Revisar publicação' } ).click();
await page.waitForTimeout( 500 );
await page.screenshot( {
	path: `${ OUT }/admin-publish.png`,
	fullPage: false,
} );
const publish = await page.evaluate( () => ( {
	stats: Array.from( document.querySelectorAll( '.publish-stat' ) ).map(
		( s ) => s.textContent
	),
	rows: Array.from( document.querySelectorAll( '.diff-row' ) ).map(
		( r ) => r.textContent
	),
	subtitle: document.querySelector( '.publish-subtitle' )?.textContent ?? '',
} ) );

// Closed through its own button: the browser's Escape reaches the native dialog
// before React sees it, and the probe needs the surface gone, not just cancelled.
await page
	.locator( '.wccs-dialog.publish-dialog .dialog-footer button' )
	.first()
	.click();
await page.waitForTimeout( 300 );

// The history, from the editor's footer, with a real click: the footer used to sit
// over this strip, and a click that only works through the DOM is a click a merchant
// cannot make.
await page.getByRole( 'button', { name: 'Revisões' } ).click();
await page.waitForTimeout( 500 );
await page.screenshot( {
	path: `${ OUT }/admin-history.png`,
	fullPage: false,
} );
const history = await page.evaluate( () =>
	Array.from( document.querySelectorAll( '.history-row' ) ).map(
		( r ) => r.textContent
	)
);

// The conditions, which are the controls the design's own markup styles: the shared
// control library now carries `.form-group`, `.input` and `.form-help`, and the row
// carries `.condition-row`.
// Reloaded rather than reused: the dialogs above leave focus and scroll where they
// were, and a fresh document is what the merchant would be looking at anyway.
await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 900 );
// The field that carries the rule, in its own section: the editor opens on the first
// section, which is not where the probe's rule lives.
await page
	.locator( '.wccs-admin .section-tabs button', { hasText: 'Cobrança' } )
	.first()
	.click();
await page.waitForTimeout( 300 );
await page
	.locator( '.wccs-admin .field-info', { hasText: 'Telefone' } )
	.first()
	.click();
await page.waitForTimeout( 300 );
await page.locator( '.wccs-admin .inspector-tabs button' ).nth( 1 ).click();
await page.waitForTimeout( 300 );
// A rule the real vocabulary accepts: the builder's own button, not a document
// written by hand, because a source key the vocabulary does not know renders nothing.
// The builder is still worded in English inside the design's Portuguese inspector,
// which is why the probe matches either: the copy is a separate finding.
const addCondition = page.getByRole( 'button', {
	name: /Adicionar condição|Add a condition/,
} );

if ( await addCondition.count() ) {
	await addCondition.first().click();
	await page.waitForTimeout( 300 );
}

await page.screenshot( {
	path: `${ OUT }/admin-conditions.png`,
	fullPage: false,
} );
// Is the wp-admin footer really over the screen's last strip, or was the click just
// below the fold? Measured, because a workaround that hides a defect is worse than the
// defect.
const footer = await page.evaluate( () => {
	const node = document.getElementById( 'wpfooter' );
	const style = node ? getComputedStyle( node ) : null;
	const strip = document.querySelector( '.wccs-admin .bottom-status' );

	return {
		position: style?.position ?? '',
		bottom: style?.bottom ?? '',
		height: style ? Math.round( node.getBoundingClientRect().height ) : 0,
		stripBottom: strip
			? Math.round( strip.getBoundingClientRect().bottom )
			: 0,
		footerTop: node ? Math.round( node.getBoundingClientRect().top ) : 0,
	};
} );

const conditions = await page.evaluate( () => {
	const row = document.querySelector( '.condition-row' );
	const select = row?.querySelector( 'select' );
	const label = row?.querySelector( 'label' );

	return {
		tabs: Array.from(
			document.querySelectorAll( '.wccs-admin .inspector-tabs button' )
		).map( ( b ) => b.textContent ),
		panel:
			document
				.querySelector( '.wccs-admin .inspector-body' )
				?.textContent.slice( 0, 120 ) ?? '',
		builder: Boolean( document.querySelector( '.wccs-conditions' ) ),
		rows: document.querySelectorAll( '.condition-row' ).length,
		rowClass: row?.className ?? '',
		selectClass: select?.className ?? '',
		selectHeight: select ? getComputedStyle( select ).height : '',
		selectBorder: select ? getComputedStyle( select ).borderTopColor : '',
		labelSize: label ? getComputedStyle( label ).fontSize : '',
		result:
			document.querySelector( '.condition-result' )?.textContent ?? '',
	};
} );

// The inspector below the design's 870px breakpoint: the column is hidden by the
// stylesheet and the same properties open over the list, in both documents.
const narrowPrototype = await prototype( browser );
await narrowPrototype.locator( '[data-edit-field]' ).first().click();
await narrowPrototype.waitForTimeout( 400 );
await narrowPrototype.screenshot( {
	path: `${ OUT }/prototype-mobile-inspector.png`,
	fullPage: false,
} );
const prototypeMobile = await narrowPrototype.evaluate( () => ( {
	open: Boolean( document.querySelector( '#mobileInspector' )?.open ),
	column: getComputedStyle( document.querySelector( '#inspector' ) ).display,
} ) );

const mobile = await browser.newPage( {
	viewport: { width: 390, height: 844 },
} );
await mobile.context().addCookies( await page.context().cookies() );
mobile.on( 'pageerror', ( e ) =>
	errs.push( String( e.message ).slice( 0, 160 ) )
);
await mobile.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await mobile.waitForTimeout( 900 );
await mobile.locator( '.wccs-admin .field-info' ).first().click();
await mobile.waitForTimeout( 400 );
await mobile.screenshot( {
	path: `${ OUT }/admin-mobile-inspector.png`,
	fullPage: false,
} );
const adminMobile = await mobile.evaluate( () => ( {
	open: Boolean( document.querySelector( '.mobile-inspector' )?.open ),
	column: getComputedStyle(
		document.querySelector( '.wccs-admin .inspector' )
	).display,
	head:
		document.querySelector( '.mobile-inspector-top strong' )?.textContent ??
		'',
} ) );

console.log(
	JSON.stringify(
		{
			publish,
			history,
			footer,
			conditions,
			mobileInspector: { prototype: prototypeMobile, admin: adminMobile },
			errs,
		},
		null,
		1
	)
);
await browser.close();
