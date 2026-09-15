/**
 * Browser proof for standalone WooCommerce My Account sections.
 *
 * @package WCCheckoutSuite
 */

import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const container = process.env.WCCS_PHP_CONTAINER || 'devilbox-php-1';
const wpPath = process.env.WCCS_WP_PATH || '/shared/httpd/wpagf/htdocs';
const fixturePath = `${ wpPath }/wp-content/plugins/wc-checkout-suite/tests/Integration/support/my-account-sections-fixture.php`;
const findings = [];

function wp( ...args ) {
	return execFileSync(
		'docker',
		[
			'exec',
			container,
			'wp',
			'--allow-root',
			`--path=${ wpPath }`,
			'eval-file',
			fixturePath,
			...args,
		],
		{ encoding: 'utf8' }
	);
}

function response( output, prefix ) {
	const line = output.split( '\n' ).find( ( entry ) => entry.startsWith( prefix ) );
	if ( ! line ) {
		throw new Error( `Fixture did not return ${ prefix }` );
	}
	return JSON.parse( line.slice( prefix.length ) );
}

function record( label, ok, detail = '' ) {
	findings.push( { label, ok: Boolean( ok ), detail } );
}

let browser;

try {
	const fixture = response( wp( 'setup' ), 'WCCS_ACCOUNT_FLOW=' );
	browser = await chromium.launch( {
		executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
		args: [ '--no-sandbox' ],
	} );
	const page = await browser.newPage();
	const value = `valor de perfil ${ Date.now() }`;
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );

	await page.goto( `${ origin }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', fixture.username );
	await page.fill( '#user_pass', fixture.password );
	await page.click( '#wp-submit' );
	await page.goto( fixture.endpoint_url, { waitUntil: 'networkidle' } );

	record( 'endpoint de Minha conta renderizado', await page.getByRole( 'heading', { name: 'Perfil E2E' } ).count() === 1 );
	const input = page.locator( '#wccs_account_fields\\[wccs_e2e_profile_note\\]' );
	record( 'campo aparece sem pedido', await input.count() === 1 );
	await input.fill( value );
	await page.getByRole( 'button', { name: 'Salvar informações' } ).click();
	await page.getByText( 'Informações salvas com sucesso.' ).waitFor();
	const verified = response( wp( 'verify', value ), 'WCCS_ACCOUNT_FLOW_VERIFY=' );
	record( 'cliente salva valor próprio', verified.ok, verified.ok ? '' : JSON.stringify( verified ) );
	await page.reload( { waitUntil: 'networkidle' } );
	record( 'valor persiste no próximo acesso', await input.inputValue() === value );
	await page.goto( fixture.checkout_url, { waitUntil: 'domcontentloaded' } );
	record( 'campo de Minha conta não aparece no checkout', await page.locator( '#wccs_e2e_profile_note' ).count() === 0 );
	record( 'sem erro JavaScript', errors.length === 0, errors.join( ' | ' ) );
} catch ( error ) {
	record( 'fluxo Minha conta', false, String( error?.stack || error ) );
} finally {
	if ( browser ) {
		await browser.close();
	}
	try {
		wp( 'teardown' );
	} catch ( error ) {
		record( 'restauração da fixture', false, String( error ) );
	}
}

console.log( JSON.stringify( { findings, failures: findings.filter( ( finding ) => ! finding.ok ) }, null, 2 ) );
process.exitCode = findings.some( ( finding ) => ! finding.ok ) ? 1 : 0;
