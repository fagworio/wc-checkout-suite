/**
 * UI-007: authenticated visual validation for the four-region checkout workspace.
 *
 * Usage:
 * WCCS_ADMIN_USER='admin' WCCS_ADMIN_PASSWORD='secret' \
 *   node tests/browser/ui007-workspace-visual-validation.mjs
 *
 * Cookie authentication is also supported through WCCS_COOKIE or WCCS_AUTH_COOKIE.
 * This flow is read-only: it does not create, edit, publish, or remove schema data.
 */
/* eslint-disable import/no-extraneous-dependencies, no-console */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const base = `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=checkouts`;
const output = process.env.WCCS_UI007_ARTIFACT_DIR || 'tmp/wccs-ui007';
const viewports = [ 1668, 1280, 1024 ];
const failures = [];
const pageErrors = [];
const apiErrors = [];

mkdirSync( output, { recursive: true } );

const browser = await chromium.launch( {
	executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
	args: [ '--no-sandbox' ],
} );
const context = await browser.newContext( {
	viewport: { width: viewports[ 0 ], height: 1050 },
} );
const page = await context.newPage();
page.setDefaultTimeout( 10000 );

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'response', ( response ) => {
	if ( response.url().includes( '/wp-json/' ) && response.status() >= 400 ) {
		apiErrors.push(
			`${ response.status() } ${ response
				.request()
				.method() } ${ response.url() }`
		);
	}
} );
page.on( 'requestfailed', ( request ) => {
	if ( request.url().includes( '/wp-json/' ) ) {
		apiErrors.push( `failed ${ request.method() } ${ request.url() }` );
	}
} );

async function addAuthentication() {
	if ( process.env.WCCS_ADMIN_USER && process.env.WCCS_ADMIN_PASSWORD ) {
		await page.goto( `${ origin }/wp-login.php`, {
			waitUntil: 'domcontentloaded',
			timeout: 30000,
		} );
		await page.fill( '#user_login', process.env.WCCS_ADMIN_USER );
		await page.fill( '#user_pass', process.env.WCCS_ADMIN_PASSWORD );
		await page.click( '#wp-submit' );
		await page.waitForURL( /\/wp-admin\//, { timeout: 15000 } );
		return;
	}

	for ( const cookie of [
		process.env.WCCS_COOKIE,
		process.env.WCCS_AUTH_COOKIE,
	].filter( Boolean ) ) {
		const split = cookie.indexOf( '=' );
		await context.addCookies( [
			{
				name: cookie.slice( 0, split ),
				value: cookie.slice( split + 1 ),
				domain: new URL( origin ).hostname,
				path: '/',
				httpOnly: true,
				secure: false,
				sameSite: 'Lax',
			},
		] );
	}
}

async function assertVisibleRegion( selector, label ) {
	const locator = page.locator( selector ).first();
	await locator.waitFor();
	const box = await locator.boundingBox();
	if ( ! box || box.width <= 0 || box.height <= 0 ) {
		throw new Error(
			`${ label } has no visible bounding box: ${ JSON.stringify( box ) }`
		);
	}

	return {
		label,
		x: Math.round( box.x ),
		y: Math.round( box.y ),
		width: Math.round( box.width ),
		height: Math.round( box.height ),
	};
}

async function assertNoTruncatedLabels() {
	const result = await page
		.locator(
			'.wccs-workspace-region-sections button strong, .wccs-workspace-region-checkouts button strong, .wccs-workspace-region-fields .field-name strong'
		)
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => ( {
				text: node.textContent?.trim() ?? '',
				clientWidth: node.clientWidth,
				scrollWidth: node.scrollWidth,
			} ) )
		);
	const truncated = result.filter(
		( item ) => item.text && item.scrollWidth > item.clientWidth + 1
	);
	if ( truncated.length ) {
		throw new Error(
			`Workspace navigation labels are truncated: ${ JSON.stringify(
				truncated
			) }`
		);
	}
}

try {
	await addAuthentication();

	for ( const width of viewports ) {
		await page.setViewportSize( { width, height: 1050 } );
		await page.goto( base, {
			waitUntil: 'networkidle',
			timeout: 30000,
		} );

		if ( page.url().includes( '/wp-login.php' ) ) {
			throw new Error(
				'Admin authentication required: set WCCS_ADMIN_USER/WCCS_ADMIN_PASSWORD or WCCS_COOKIE.'
			);
		}

		await page.locator( '.wccs-checkout-workspace' ).waitFor();
		const regions = [
			await assertVisibleRegion(
				'.wccs-workspace-region-checkouts',
				'Checkouts'
			),
			await assertVisibleRegion(
				'.wccs-workspace-region-sections',
				'Seções'
			),
			await assertVisibleRegion(
				'.wccs-workspace-region-fields',
				'Campos'
			),
			await assertVisibleRegion(
				'.wccs-workspace-region-properties',
				'Propriedades'
			),
		];

		const sectionNavigation = page.getByRole( 'complementary', {
			name: /Seções desta área/i,
		} );
		if ( ! ( await sectionNavigation.isVisible() ) ) {
			throw new Error( `Section navigation is hidden at ${ width }px.` );
		}
		const sectionItems = sectionNavigation.locator(
			'.wccs-container-list__items button'
		);
		if ( 0 === ( await sectionItems.count() ) ) {
			await sectionNavigation
				.getByRole( 'button', { name: 'Nova seção', exact: true } )
				.waitFor();
		}
		await assertNoTruncatedLabels();

		await page.screenshot( {
			path: `${ output }/ui007-workspace-${ width }.png`,
			fullPage: true,
		} );

		console.log(
			JSON.stringify( {
				viewport: width,
				regions,
				sectionNavigation: true,
				screenshot: `${ output }/ui007-workspace-${ width }.png`,
			} )
		);
	}
} catch ( error ) {
	failures.push( error instanceof Error ? error.message : String( error ) );
} finally {
	await browser.close();
}

if ( pageErrors.length || apiErrors.length || failures.length ) {
	console.error(
		JSON.stringify( { failures, pageErrors, apiErrors }, null, 2 )
	);
	process.exitCode = 1;
} else {
	console.log(
		JSON.stringify( {
			viewports,
			pageErrors,
			apiErrors,
			status: 'passed',
		} )
	);
}
