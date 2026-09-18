/**
 * Verifies the real admin flow for creating a file field in a new My Account section.
 *
 * Usage:
 * WCCS_ADMIN_USER='admin' WCCS_ADMIN_PASSWORD='password' node tests/browser/customer-account-file-field-flow.mjs
 */
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const user = process.env.WCCS_ADMIN_USER || '';
const password = process.env.WCCS_ADMIN_PASSWORD || '';
const failures = [];
let step = 'initialising';

if ( ! user || ! password ) {
	console.log(
		JSON.stringify( {
			ok: false,
			failures: [
				'WCCS_ADMIN_USER and WCCS_ADMIN_PASSWORD are required for this real admin flow.',
			],
		} )
	);
	process.exitCode = 1;
} else {
	const browser = await chromium.launch( {
		executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
		args: [ '--no-sandbox' ],
	} );
	const page = await browser.newPage( {
		viewport: { width: 1668, height: 1050 },
	} );
	page.setDefaultTimeout( 8000 );

	try {
		step = 'login';
		await page.goto( `${ origin }/wp-login.php`, {
			waitUntil: 'domcontentloaded',
			timeout: 30000,
		} );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', password );
		await page.click( '#wp-submit' );
		await page.waitForURL( /\/wp-admin\//, { timeout: 15000 } );

		step = 'open-customer-account-editor';
		await page.goto(
			`${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields&area=customer_account`,
			{ waitUntil: 'networkidle', timeout: 30000 }
		);

		step = 'select-customer-account';
		await page
			.getByRole( 'tab', { name: 'Minha conta', exact: true } )
			.waitFor();
		await page
			.getByRole( 'tab', { name: 'Minha conta', exact: true } )
			.click();

		const pageTitle = `E2E Upload ${ Date.now() }`;
		step = 'create-new-section';
		await page
			.getByRole( 'button', { name: 'Nova página', exact: true } )
			.last()
			.click();
		await page.locator( '#wccs-new-section-title' ).fill( pageTitle );
		await page
			.getByRole( 'button', { name: 'Criar página', exact: true } )
			.click();
		// Keep the journey explicit while the editor settles: the field must be
		// created in this new page, never in the first native account section.
		await page
			.locator( '.section-tabs button' )
			.filter( { hasText: pageTitle } )
			.click();

		step = 'open-field-picker';
		await page
			.getByRole( 'button', {
				name: 'Adicionar campo',
				exact: true,
			} )
			.click();
		const picker = page.locator( 'dialog.picker-dialog' );
		await picker.waitFor();
		const activeCategory = picker.locator(
			'.picker-categories button.active'
		);
		await activeCategory.waitFor();
		if (
			! ( await activeCategory.innerText() ).includes( 'Todos os campos' )
		) {
			throw new Error( 'The picker did not start at Todos os campos.' );
		}
		step = 'choose-file-type';
		await picker.locator( '[data-testid="field-type-file"]' ).click();

		const label = `Documento ${ Date.now() }`;
		const key = `documento_e2e_${ Date.now() }`;
		step = 'configure-file-type';
		await page.locator( '#wccs-new-label' ).fill( label );
		await page.locator( '#wccs-new-key' ).fill( key );
		await page.locator( '#wccs-setting-maxFiles' ).fill( '1' );
		await page.locator( '#wccs-setting-allowedExtensions' ).fill( 'pdf' );
		step = 'confirm-field';
		await picker
			.getByRole( 'button', { name: 'Adicionar campo', exact: true } )
			.click();

		step = 'verify-field-added';
		const fieldRow = page
			.locator( '.field-row' )
			.filter( { hasText: label } );
		await fieldRow.waitFor();
		await fieldRow.getByText( key, { exact: true } ).waitFor();
		step = 'verify-picker-reset';
		await page
			.locator( '.context-status .badge' )
			.getByText( 'Alterações não salvas', { exact: true } )
			.waitFor();
		await page.screenshot( {
			path: 'tmp/wccs-account-file-field-added.png',
			fullPage: true,
		} );

		// Opening the picker again must start from the catalogue, not the previous type.
		await page
			.getByRole( 'button', {
				name: 'Adicionar campo',
				exact: true,
			} )
			.click();
		const secondPicker = page.locator( 'dialog.picker-dialog' );
		const secondActiveCategory = secondPicker.locator(
			'.picker-categories button.active'
		);
		await secondActiveCategory.waitFor();
		if (
			! ( await secondActiveCategory.innerText() ).includes(
				'Todos os campos'
			)
		) {
			throw new Error( 'The picker did not reset to Todos os campos.' );
		}

		console.log(
			JSON.stringify(
				{
					ok: true,
					section: pageTitle,
					field: label,
					key,
					screenshot: 'tmp/wccs-account-file-field-added.png',
					checks: [
						'customer-account-url',
						'new-section-created-in-draft',
						'file-type-configured',
						'field-added-to-section',
						'dirty-state-visible',
						'picker-resets-to-all-fields',
					],
				},
				null,
				2
			)
		);
	} catch ( error ) {
		failures.push( String( error?.message || error ).split( '\n' )[ 0 ] );
		failures.unshift( `step=${ step } url=${ page.url() }` );
		await page
			.screenshot( {
				path: 'tmp/wccs-account-file-failure.png',
				fullPage: true,
			} )
			.catch( () => {} );
		console.log( JSON.stringify( { ok: false, failures }, null, 2 ) );
		process.exitCode = 1;
	} finally {
		await browser.close();
	}
}
