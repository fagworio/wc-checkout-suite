/**
 * Visual/functional journey for the consolidated destination editor.
 *
 * Usage:
 * WCCS_COOKIE='name=value' WCCS_AUTH_COOKIE='name=value' node tests/browser/design-destinations-flow.mjs
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const output = process.env.WCCS_VISUAL_OUT || 'tmp/wccs-visual';
mkdirSync( output, { recursive: true } );

const browser = await chromium.launch( {
	executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
	args: [ '--no-sandbox' ],
} );
const page = await browser.newPage( {
	viewport: { width: 1668, height: 1050 },
} );
page.setDefaultTimeout( 6000 );

if ( process.env.WCCS_ADMIN_USER && process.env.WCCS_ADMIN_PASSWORD ) {
	await page.goto( `${ origin }/wp-login.php`, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await page.fill( '#user_login', process.env.WCCS_ADMIN_USER );
	await page.fill( '#user_pass', process.env.WCCS_ADMIN_PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /\/wp-admin\//, { timeout: 15000 } );
} else {
	for ( const cookie of [
		process.env.WCCS_COOKIE,
		process.env.WCCS_AUTH_COOKIE,
	].filter( Boolean ) ) {
		const split = cookie.indexOf( '=' );
		await page.context().addCookies( [
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

const base = `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const surfaces = [
	[ 'Checkout', 'checkout' ],
	[ 'Minha conta', 'customer_account' ],
	[ 'Pedido do cliente', 'customer_order' ],
	[ 'Pedido (admin)', 'admin_order' ],
	[ 'Perfil do cliente (admin)', 'admin_customer_profile' ],
	[ 'E-mails', 'customer_email' ],
];
const failures = [];

try {
	await page.goto( base, { waitUntil: 'networkidle', timeout: 30000 } );
	if ( page.url().includes( '/wp-login.php' ) ) {
		throw new Error(
			'Admin authentication required: set WCCS_ADMIN_USER/WCCS_ADMIN_PASSWORD or WCCS_COOKIE.'
		);
	}
	const tabs = page.getByRole( 'tablist', {
		name: 'Superfície',
		exact: true,
	} );
	await tabs.waitFor();

	for ( const [ label, id ] of surfaces ) {
		await tabs.getByRole( 'tab', { name: label, exact: true } ).click();
		await page.locator( '.wccs-container-list' ).waitFor();
		await page.screenshot( {
			path: `${ output }/${ id }.png`,
			fullPage: true,
		} );
	}

	await tabs.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	const accountMenu = page.locator( '.wccs-account-menu-list' );
	for ( const label of [
		'Painel',
		'Pedidos',
		'Downloads',
		'Endereços',
		'Detalhes da conta',
	] ) {
		await accountMenu
			.getByRole( 'button', { name: new RegExp( label ) } )
			.waitFor();
	}
	await accountMenu
		.getByRole( 'button', { name: /^Detalhes da conta/ } )
		.click();
	const nativeAccount = page.locator( '.account-native-fields' );
	await nativeAccount.waitFor();
	for ( const label of [
		'Nome',
		'Sobrenome',
		'Nome de exibição',
		'Endereço de e-mail',
	] ) {
		await nativeAccount.getByText( label, { exact: true } ).waitFor();
	}
	await nativeAccount
		.getByRole( 'button', { name: /Editar campo Nome$/ } )
		.click();
	await page.locator( '#wccs-field-label' ).waitFor();
	await page.locator( '#wccs-field-label' ).fill( 'Nome do cliente' );
	await page
		.locator( '.context-status .badge' )
		.getByText( 'Alterações não salvas', { exact: true } )
		.waitFor();
	await nativeAccount
		.getByRole( 'button', { name: /Ocultar campo Nome do cliente$/ } )
		.click();
	await nativeAccount
		.locator( '.account-native-field--disabled' )
		.getByRole( 'button', { name: /Restaurar campo Nome do cliente$/ } )
		.click();
	await accountMenu.getByRole( 'button', { name: /^Endereços/ } ).click();
	await page
		.locator( '.account-address-notice' )
		.getByText( /submenus próprios para Cobrança e Entrega/ )
		.waitFor();
	for ( const label of [ 'Cobrança', 'Entrega' ] ) {
		await page
			.locator( '.account-address-notice' )
			.getByRole( 'link', { name: label, exact: true } )
			.waitFor();
	}
	await accountMenu
		.getByRole( 'button', { name: /^Detalhes da conta/ } )
		.click();
	await page.getByRole( 'button', { name: /Nova página/ } ).click();
	const createDialog = page.getByRole( 'dialog' );
	for ( const label of [ 'Ícone', 'Modo', 'Exibir título no conteúdo' ] ) {
		if ( ( await createDialog.getByLabel( label ).count() ) !== 0 ) {
			throw new Error(
				`Structural page creation must not expose optional control: ${ label }`
			);
		}
	}
	await page
		.locator( '#wccs-new-section-title' )
		.fill( `E2E container ${ Date.now() }` );
	await page.getByRole( 'button', { name: 'Criar página' } ).click();
	await page.getByRole( 'button', { name: /Ações da página/ } ).click();
	const propertiesDialog = page.getByRole( 'dialog' );
	for ( const label of [ 'Ícone da aba', 'Modo' ] ) {
		if ( ( await propertiesDialog.getByLabel( label ).count() ) !== 1 ) {
			throw new Error(
				`Page properties must expose optional control: ${ label }`
			);
		}
	}
	if (
		( await propertiesDialog
			.getByLabel( 'Exibir título no conteúdo' )
			.count() ) !== 1
	) {
		throw new Error(
			'Page properties must expose the content title visibility control'
		);
	}
	await propertiesDialog
		.getByLabel( 'Nome da página' )
		.fill( 'E2E container renamed' );
	await propertiesDialog.getByRole( 'button', { name: 'Done' } ).click();
	await page
		.locator( '.context-status .badge' )
		.getByText( 'Alterações não salvas', { exact: true } )
		.waitFor();

	console.log(
		JSON.stringify(
			{
				ok: true,
				surfaces: surfaces.map( ( [ , id ] ) => id ),
				checks: [
					'surface-tabs',
					'six destination captures',
					'WooCommerce account menu in lateral',
					'native account fields in account details',
					'edit and hide/restore native account field',
					'address submenu notice',
					'canonical container form',
					'dirty state',
				],
			},
			null,
			2
		)
	);
} catch ( error ) {
	failures.push( String( error?.message || error ).split( '\n' )[ 0 ] );
	console.log( JSON.stringify( { ok: false, failures }, null, 2 ) );
	process.exitCode = 1;
} finally {
	await browser.close();
}
