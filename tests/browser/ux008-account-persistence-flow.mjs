/**
 * UX-008: authenticated persistence and navigation matrix for My Account.
 *
 * Usage:
 * WCCS_COOKIE='name=value' WCCS_AUTH_COOKIE='name=value' \
 *   node tests/browser/ux008-account-persistence-flow.mjs
 *
 * The flow intentionally uses a fresh browser context. A local draft must not
 * hide a server-side save/reload regression.
 */
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const base = `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const failures = [];
const findings = [];
const pageErrors = [];
const apiErrors = [];
const runId = Date.now();
const pageTitle = `UX-008 ${ runId }`;
const pageMenuLabel = `${ pageTitle } editada`;
const fieldLabel = `${ pageTitle } campo`;
const editedFieldLabel = `${ fieldLabel } salvo`;
let createdPageLabels = [ pageTitle, pageMenuLabel ];
let pageWasRemoved = false;
let step = 'initialising';

const browser = await chromium.launch( {
	executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
	args: [ '--no-sandbox' ],
} );
const context = await browser.newContext( {
	viewport: { width: 1668, height: 1050 },
} );
const page = await context.newPage();
page.setDefaultTimeout( 10000 );

page.on( 'pageerror', ( error ) => {
	pageErrors.push( error.message );
} );
page.on( 'response', ( response ) => {
	if ( response.url().includes( '/wp-json/' ) && response.status() >= 400 ) {
		apiErrors.push( `${ response.status() } ${ response.request().method() } ${ response.url() }` );
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

async function openEditor() {
	await page.goto( base, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	if ( page.url().includes( '/wp-login.php' ) ) {
		throw new Error( 'Admin authentication was rejected.' );
	}
	await page.locator( '#editorView' ).waitFor();
}

async function accountMenu() {
	const menu = page.locator( '.wccs-account-menu-list' );
	await menu.waitFor();
	return menu;
}

async function accountButton( menu, labels ) {
	const expectedLabels = Array.isArray( labels ) ? labels : [ labels ];
	const buttons = menu.getByRole( 'button' );
	const count = await buttons.count();
	for ( let index = 0; index < count; index++ ) {
		const button = buttons.nth( index );
		const text = await button.innerText();
		if ( expectedLabels.some( ( label ) => text.includes( label ) ) ) {
			return button;
		}
	}
	throw new Error( `Account page button not found: ${ expectedLabels.join( ' / ' ) }` );
}

async function openAccountContext( label, aliases = [] ) {
	const menu = await accountMenu();
	const button = await accountButton( menu, [ label, ...aliases ] );
	await button.click();
	if ( ( await button.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		throw new Error( `Account context was not selected: ${ label }` );
	}
	findings.push( `context:${ label }` );
}

async function saveAndWait( description ) {
	step = `save:${ description }`;
	const saveButton = page.getByRole( 'button', {
		name: 'Salvar alterações',
		exact: true,
	} );
	await saveButton.waitFor();
	if ( ! ( await saveButton.isEnabled() ) ) {
		throw new Error( `Save button was disabled for ${ description }.` );
	}
	const responsePromise = page.waitForResponse(
		( response ) =>
			response.request().method() === 'PUT' &&
			response.url().includes( '/wp-json/' ),
		{ timeout: 30000 }
	);
	await saveButton.click();
	const response = await responsePromise;
	if ( ! response.ok() ) {
		const body = await response.text();
		const payload = response.request().postData() || '';
		let sectionIds = 'unavailable';
		try {
			sectionIds = ( JSON.parse( payload ).schema?.sections ?? [] )
				.map( ( section ) => section.id )
				.join( ',' );
		} catch ( parseError ) {
			// Keep the server error as the primary diagnostic when the request body
			// cannot be decoded by the browser probe.
		}
		throw new Error(
			`Save failed for ${ description }: ${ response.status() } ${ body.slice( 0, 600 ) } payload_sections=${ sectionIds}.`
		);
	}
	await page
		.getByText( 'Alterações salvas com sucesso.', { exact: true } )
		.waitFor();
	findings.push( `saved:${ description }` );
}

async function openCustomPage() {
	const menu = await accountMenu();
	for ( const label of createdPageLabels ) {
		try {
			const button = await accountButton( menu, label );
			await button.click();
			return button;
		} catch ( error ) {
			if ( ! String( error?.message || error ).includes( 'not found' ) ) {
				throw error;
			}
		}
	}
	throw new Error( 'Created UX-008 account page was not found.' );
}

async function openPageActions() {
	await page.getByRole( 'button', { name: /Ações da página/ } ).click();
	const dialog = page.getByRole( 'dialog' );
	await dialog.waitFor();
	return dialog;
}

async function removeCreatedPage() {
	await openCustomPage();
	const dialog = await openPageActions();
	await dialog
		.getByRole( 'button', { name: 'Remover Página da conta', exact: true } )
		.click();
	const confirmation = page.getByRole( 'dialog' );
	await confirmation
		.getByRole( 'button', { name: 'Remover Página da conta e campos', exact: true } )
		.click();
}

try {
	await addAuthentication();

	step = 'open-editor';
	await openEditor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	await accountMenu();

	step = 'account-context-matrix';
	for ( const label of [
		[ 'Painel', 'Dashboard' ],
		[ 'Pedidos', 'Orders' ],
		[ 'Downloads' ],
		[ 'Endereços', 'Addresses' ],
		[ 'Detalhes da conta', 'Account details' ],
	] ) {
		await openAccountContext( label[ 0 ], label.slice( 1 ) );
	}

	step = 'create-custom-page';
	await page.getByRole( 'button', { name: 'Nova página', exact: true } ).click();
	const createDialog = page.getByRole( 'dialog' );
	await createDialog.locator( '#wccs-new-section-title' ).fill( pageTitle );
	await createDialog
		.getByRole( 'button', { name: 'Criar página', exact: true } )
		.click();
	await openCustomPage();
	findings.push( 'custom-page:created' );
	await saveAndWait( 'create custom account page' );

	step = 'reload-after-page-create';
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	await openCustomPage();
	findings.push( 'reload:custom-page-created-state-preserved' );

	step = 'create-field';
	await page.getByRole( 'button', { name: 'Adicionar campo', exact: true } ).click();
	const picker = page.locator( 'dialog.picker-dialog' );
	await picker.waitFor();
	await picker.locator( '.picker-card' ).first().click();
	await page.locator( '#wccs-new-label' ).fill( fieldLabel );
	await page.locator( '#wccs-new-key' ).fill( `ux008_${ runId }` );
	await picker
		.getByRole( 'button', { name: 'Adicionar campo', exact: true } )
		.click();
	const createdField = page.locator( '.field-row' ).filter( { hasText: fieldLabel } );
	await createdField.waitFor();
	findings.push( 'custom-field:created' );

	step = 'edit-field';
	await createdField.locator( '.field-info' ).click();
	await page.locator( '#wccs-field-label' ).fill( editedFieldLabel );
	await page.locator( '.field-row' ).filter( { hasText: editedFieldLabel } ).waitFor();
	findings.push( 'custom-field:edited' );

	step = 'edit-custom-page';
	const properties = await openPageActions();
	await properties.locator( '#wccs-account-section-menu-label' ).fill( pageMenuLabel );
	await properties.getByRole( 'button', { name: 'Done', exact: true } ).click();
	createdPageLabels = [ pageMenuLabel, pageTitle ];
	findings.push( 'custom-page:edited' );

	await saveAndWait( 'create and edit account page and field' );

	step = 'reload-after-create-edit';
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	await openCustomPage();
	await page.locator( '.field-row' ).filter( { hasText: editedFieldLabel } ).waitFor();
	findings.push( 'reload:created-and-edited-state-preserved' );

	step = 'disable-custom-page';
	let pageProperties = await openPageActions();
	const enabledControl = pageProperties.getByLabel( 'Aba/seção ativa', { exact: true } );
	await enabledControl.uncheck();
	await pageProperties.getByRole( 'button', { name: 'Done', exact: true } ).click();
	await saveAndWait( 'deactivate custom account page' );

	step = 'reload-after-disable';
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	const inactiveButton = await accountButton( await accountMenu(), pageMenuLabel );
	if ( ! ( await inactiveButton.innerText() ).toLocaleLowerCase().includes( 'inativa' ) ) {
		throw new Error( 'Reloaded custom page did not remain inactive.' );
	}
	await inactiveButton.click();
	pageProperties = await openPageActions();
	if ( await pageProperties.getByLabel( 'Aba/seção ativa', { exact: true } ).isChecked() ) {
		throw new Error( 'Deactivated custom page was reactivated after reload.' );
	}
	await pageProperties.getByRole( 'button', { name: 'Done', exact: true } ).click();
	findings.push( 'reload:custom-page-deactivated-state-preserved' );

	step = 'reactivate-custom-page';
	pageProperties = await openPageActions();
	await pageProperties.getByLabel( 'Aba/seção ativa', { exact: true } ).check();
	await pageProperties.getByRole( 'button', { name: 'Done', exact: true } ).click();
	await saveAndWait( 'reactivate custom account page' );

	step = 'reload-after-reactivate';
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	const activeButton = await accountButton( await accountMenu(), pageMenuLabel );
	if ( ( await activeButton.innerText() ).toLocaleLowerCase().includes( 'inativa' ) ) {
		throw new Error( 'Reactivated custom page is still marked inactive.' );
	}
	await activeButton.click();
	pageProperties = await openPageActions();
	if ( ! ( await pageProperties.getByLabel( 'Aba/seção ativa', { exact: true } ).isChecked() ) ) {
		throw new Error( 'Reactivated custom page was not persisted as active.' );
	}
	await pageProperties.getByRole( 'button', { name: 'Done', exact: true } ).click();
	findings.push( 'reload:custom-page-reactivated-state-preserved' );

	step = 'remove-custom-page';
	await removeCreatedPage();
	await saveAndWait( 'remove custom account page and fields' );
	pageWasRemoved = true;

	step = 'reload-after-remove';
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
	for ( const label of createdPageLabels ) {
		const menu = await accountMenu();
		const buttons = menu.getByRole( 'button' );
		for ( let index = 0; index < await buttons.count(); index++ ) {
			if ( ( await buttons.nth( index ).innerText() ).includes( label ) ) {
				throw new Error( `Removed custom page is still visible: ${ label }` );
			}
		}
	}
	findings.push( 'reload:custom-page-removal-persisted' );

	if ( pageErrors.length > 0 ) {
		throw new Error( `Browser pageerror detected: ${ pageErrors.join( ' | ' ) }` );
	}
	if ( apiErrors.length > 0 ) {
		throw new Error( `REST/API error detected: ${ apiErrors.join( ' | ' ) }` );
	}

	console.log( JSON.stringify( {
		ok: true,
		matrix: [ 'Painel', 'Pedidos', 'Downloads', 'Endereços', 'Detalhes da conta' ],
		findings,
		pageErrors,
		apiErrors,
	}, null, 2 ) );
} catch ( error ) {
	failures.push( `step=${ step } url=${ page.url() }` );
	failures.push( String( error?.message || error ).split( '\n' )[ 0 ] );
	console.log( JSON.stringify( {
		ok: false,
		findings,
		failures,
		pageErrors,
		apiErrors,
	}, null, 2 ) );
	process.exitCode = 1;
} finally {
	if ( ! pageWasRemoved && createdPageLabels.length > 0 ) {
		try {
			await page.getByRole( 'tab', { name: 'Minha conta', exact: true } ).click();
			await removeCreatedPage();
			const saveButton = page.getByRole( 'button', {
				name: 'Salvar alterações',
				exact: true,
			} );
			if ( await saveButton.isEnabled() ) {
				await saveButton.click();
			}
		} catch ( cleanupError ) {
			failures.push( `cleanup=${ String( cleanupError?.message || cleanupError ).split( '\n' )[ 0 ] }` );
			process.exitCode = 1;
		}
	}
	await browser.close();
}
