/**
 * CHK-007: authenticated checkout -> section -> field identity and persistence flow.
 *
 * Usage:
 * WCCS_ADMIN_USER='admin' WCCS_ADMIN_PASSWORD='secret' \
 *   node tests/browser/checkout-profiles-deterministic-flow.mjs
 *
 * Cookie authentication is also supported through WCCS_COOKIE and WCCS_AUTH_COOKIE. The flow uses
 * a clean schema baseline, unique names, saves after every lifecycle change, reloads the editor
 * between assertions, and restores the baseline after the run.
 */
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const base = `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=checkouts`;
const runId = Date.now();
const profileName = `Black Friday CHK-007 ${ runId }`;
const deliverySectionTitle = `Entrega CHK-007 ${ runId }`;
const documentsSectionTitle = `Documentos CHK-007 ${ runId }`;
const fieldA = `Campo A CHK-007 ${ runId }`;
const fieldB = `Campo B CHK-007 ${ runId }`;
const fieldC = `Campo C CHK-007 ${ runId }`;
const fieldKey = ( suffix ) => `chk007_${ suffix }_${ runId }`;
const fixtureSectionTitle = `E2E fixture UI-001 ${ runId }`;
const knownTestResidue = /CHK-(?:001|003|007)/i;
const knownTestId = /^chk(?:001|003|007)_/i;

const failures = [];
const findings = [];
const pageErrors = [];
const apiErrors = [];
let step = 'initialising';
let baselineDocument = null;
let latestRevision = null;
let baselineRestored = false;

const browser = await chromium.launch( {
	executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
	args: [ '--no-sandbox' ],
} );
const context = await browser.newContext( {
	viewport: { width: 1668, height: 1050 },
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

async function readDraft() {
	return page.evaluate( async () => {
		const boot = /** @type {any} */ ( window ).wccsAdmin ?? {};
		const rest = boot.rest ?? {};
		const response = await fetch(
			( rest.root ?? '' ) +
				( rest.namespace ?? '' ) +
				( rest.routes?.draft ?? '' ),
			{
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': rest.nonce ?? '' },
			}
		);

		return response.ok ? response.json() : null;
	} );
}

async function requestSchema( route, method, data ) {
	return page.evaluate(
		async ( { routeName, requestMethod, payload } ) => {
			const boot = /** @type {any} */ ( window ).wccsAdmin ?? {};
			const rest = boot.rest ?? {};
			const response = await fetch(
				( rest.root ?? '' ) +
					( rest.namespace ?? '' ) +
					( rest.routes?.[ routeName ] ?? '' ),
				{
					method: requestMethod,
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': rest.nonce ?? '',
					},
					body: JSON.stringify( payload ),
				}
			);
			const body = await response.text();

			if ( ! response.ok ) {
				throw new Error(
					`${ requestMethod } ${ routeName } failed: ${
						response.status
					} ${ body.slice( 0, 600 ) }`
				);
			}

			return body ? JSON.parse( body ) : null;
		},
		{ routeName: route, requestMethod: method, payload: data }
	);
}

async function saveDocument( document, expectedRevision ) {
	await requestSchema( 'draft', 'PUT', {
		schema: document,
		expected_revision: expectedRevision,
	} );
	const stored = await readDraft();
	latestRevision = stored?.revision ?? null;

	if ( null === latestRevision ) {
		throw new Error(
			'The schema draft did not return a revision after saving.'
		);
	}

	return stored;
}

async function publishDocument( expectedRevision ) {
	await requestSchema( 'publish', 'POST', {
		expected_revision: expectedRevision,
	} );
	const stored = await readDraft();
	latestRevision = stored?.revision ?? null;

	if ( null === latestRevision ) {
		throw new Error(
			'The schema draft did not return a revision after publishing.'
		);
	}

	return stored;
}

function hasKnownTestResidue( value ) {
	return [
		value?.id,
		value?.name,
		value?.title,
		value?.label,
		value?.integration_id,
	]
		.filter( ( entry ) => 'string' === typeof entry )
		.some(
			( entry ) =>
				knownTestResidue.test( entry ) || knownTestId.test( entry )
		);
}

function cleanKnownTestResidue( document ) {
	const removedSectionIds = new Set();
	const profiles = ( document?.profiles ?? [] )
		.filter( ( profile ) => {
			if ( hasKnownTestResidue( profile ) ) {
				( profile.sections ?? [] ).forEach( ( section ) =>
					removedSectionIds.add( section.id )
				);
				return false;
			}

			return true;
		} )
		.map( ( profile ) => ( {
			...profile,
			sections: ( profile.sections ?? [] ).filter( ( section ) => {
				const remove = hasKnownTestResidue( section );
				if ( remove ) {
					removedSectionIds.add( section.id );
				}

				return ! remove;
			} ),
		} ) );

	const sections = ( document?.sections ?? [] ).filter( ( section ) => {
		const remove = hasKnownTestResidue( section );
		if ( remove ) {
			removedSectionIds.add( section.id );
		}

		return ! remove;
	} );
	const fields = ( document?.fields ?? [] ).filter(
		( field ) =>
			! hasKnownTestResidue( field ) &&
			! removedSectionIds.has( field.section )
	);

	return {
		...document,
		fields,
		sections,
		profiles,
	};
}

async function prepareBaseline() {
	const stored = await readDraft();
	if ( ! stored || ! Number.isInteger( stored.revision ) ) {
		throw new Error(
			'The E2E baseline did not return a valid schema revision.'
		);
	}

	const cleaned = cleanKnownTestResidue( stored );
	const changed = JSON.stringify( cleaned ) !== JSON.stringify( stored );
	baselineDocument = cleaned;
	latestRevision = stored.revision;

	if ( changed ) {
		await saveDocument( cleaned, stored.revision );
		await publishDocument( latestRevision );
		await reloadEditor();
		findings.push( 'fixture:removed known CHK residues' );
	}

	const sections = ( baselineDocument.sections ?? [] ).filter( ( section ) =>
		( section.areas ?? [ 'checkout' ] ).includes( 'checkout' )
	);

	if ( 0 === sections.length ) {
		if ( 0 === ( await page.locator( '.core-checkout' ).count() ) ) {
			throw new Error(
				'Checkout baseline is invalid: no checkout section and no WooCommerce native inventory were found.'
			);
		}

		await createSection( fixtureSectionTitle );
		await selectSection( fixtureSectionTitle );
		findings.push( 'fixture:created isolated checkout section' );
	}
}

async function restoreBaseline() {
	if ( ! baselineDocument || baselineRestored ) {
		return;
	}

	const current = await readDraft();
	if ( current?.revision !== latestRevision ) {
		throw new Error(
			`Baseline restore refused because revision ${ current?.revision } differs from test revision ${ latestRevision }.`
		);
	}

	const restored = await saveDocument( baselineDocument, current.revision );
	await publishDocument( restored.revision );
	baselineRestored = true;
	findings.push( 'fixture:baseline restored' );
}

async function listedSections() {
	return page
		.locator( '.wccs-container-list__items [aria-pressed]' )
		.allInnerTexts();
}

async function selectedCheckoutName() {
	return page
		.locator( '.wccs-checkouts__strip [role="tab"][aria-selected="true"]' )
		.first()
		.innerText();
}

async function selectedSectionName() {
	return page
		.locator( '.wccs-container-list__items [aria-pressed="true"]' )
		.first()
		.innerText();
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
		throw new Error(
			`Save failed for ${ description }: ${ response.status() } ${ body.slice(
				0,
				600
			) }.`
		);
	}
	await page
		.getByText( 'Alterações salvas com sucesso.', { exact: true } )
		.waitFor();
	const stored = await readDraft();
	latestRevision = stored?.revision ?? latestRevision;
	findings.push( `saved:${ description }` );
}

async function reloadEditor() {
	await page.reload( { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.locator( '#editorView' ).waitFor();
	await page.locator( '.wccs-checkouts__strip' ).waitFor();
}

async function selectCheckout( name ) {
	const tab = page.getByRole( 'tab', { name, exact: true } );
	await tab.waitFor();
	await tab.click();
	await page.waitForFunction(
		( expected ) =>
			Array.from(
				document.querySelectorAll(
					'.wccs-checkouts__strip [role="tab"][aria-selected="true"]'
				)
			).some( ( node ) => node.textContent.includes( expected ) ),
		name
	);
}

async function selectSection( title ) {
	const button = page
		.locator( '.wccs-container-list__items [aria-pressed]' )
		.filter( { hasText: title } )
		.first();
	await button.waitFor();
	await button.click();
	await page.waitForFunction(
		( expected ) =>
			Array.from(
				document.querySelectorAll(
					'.wccs-container-list__items [aria-pressed="true"]'
				)
			).some( ( node ) => node.textContent.includes( expected ) ),
		title
	);
}

async function createField( label, key ) {
	step = `create-field:${ label }:open-picker`;
	await page
		.getByRole( 'button', { name: 'Adicionar campo', exact: true } )
		.click();
	const picker = page.locator( 'dialog.picker-dialog' );
	step = `create-field:${ label }:wait-picker`;
	await picker.waitFor();
	step = `create-field:${ label }:choose-type`;
	await picker.locator( '.picker-card' ).first().click();
	step = `create-field:${ label }:fill-form`;
	await page.locator( '#wccs-new-label' ).fill( label );
	await page.locator( '#wccs-new-key' ).fill( key );
	step = `create-field:${ label }:submit`;
	await picker
		.getByRole( 'button', { name: 'Adicionar campo', exact: true } )
		.click();
	step = `create-field:${ label }:wait-row`;
	await page.locator( '.field-row' ).filter( { hasText: label } ).waitFor();
}

async function createSection( title ) {
	await page
		.getByRole( 'button', { name: 'Nova seção', exact: true } )
		.click();
	const dialog = page.getByRole( 'dialog', { name: 'Nova seção' } );
	await dialog.locator( '#wccs-new-section-title' ).fill( title );
	await dialog
		.getByRole( 'button', { name: 'Adicionar seção', exact: true } )
		.click();
	await page
		.locator( '.wccs-container-list__items [aria-pressed]' )
		.filter( { hasText: title } )
		.first()
		.waitFor();
}

async function removeSection( title ) {
	await selectSection( title );
	await page.getByRole( 'button', { name: /^Ações da seção/ } ).click();
	await page
		.getByRole( 'button', { name: 'Remover Seção', exact: true } )
		.click();
	await page
		.getByRole( 'button', { name: 'Remover Seção e campos', exact: true } )
		.click();
}

try {
	await addAuthentication();

	step = 'open-checkouts';
	await page.goto( base, { waitUntil: 'domcontentloaded', timeout: 30000 } );
	if ( page.url().includes( '/wp-login.php' ) ) {
		throw new Error( 'Admin authentication was rejected.' );
	}
	await page.locator( '#editorView' ).waitFor();
	await page.locator( '.wccs-checkouts__strip' ).waitFor();
	await prepareBaseline();

	step = 'default-checkout';
	if ( ! ( await selectedCheckoutName() ).includes( 'Checkout padrão' ) ) {
		throw new Error( 'Checkouts did not open on Checkout padrão.' );
	}
	findings.push( 'default:Checkout padrão active' );

	step = 'create-field-a';
	const initialSections = ( await listedSections() ).filter( ( label ) =>
		label.trim()
	);
	if ( 0 === initialSections.length ) {
		throw new Error(
			'Checkout baseline did not render its fixture section.'
		);
	}
	await createField( fieldA, fieldKey( 'a' ) );
	await saveAndWait( 'field A in Checkout padrão' );
	await reloadEditor();
	const afterDefaultSave = await readDraft();
	const savedA = ( afterDefaultSave?.fields ?? [] ).find(
		( field ) => field.label === fieldA
	);
	const defaultSectionIds = new Set(
		( afterDefaultSave?.sections ?? [] ).map( ( section ) => section.id )
	);
	if ( ! savedA || ! defaultSectionIds.has( savedA.section ) ) {
		throw new Error( 'Field A did not persist in the default section.' );
	}
	findings.push( 'default:field A persisted after reload' );

	step = 'create-inherited-checkout';
	await page
		.getByRole( 'button', { name: 'Novo checkout', exact: true } )
		.click();
	const createDialog = page.getByRole( 'dialog', { name: 'Novo checkout' } );
	await createDialog.locator( '#wccs-profile-name' ).fill( profileName );
	await createDialog
		.locator( 'input[name="wccs-profile-source"]' )
		.first()
		.check();
	await createDialog
		.getByRole( 'button', { name: 'Criar checkout', exact: true } )
		.click();
	await page.getByRole( 'tab', { name: profileName, exact: true } ).waitFor();
	await selectCheckout( profileName );
	findings.push( 'alternate:inherited checkout selected' );

	step = 'create-delivery-section';
	await createSection( deliverySectionTitle );
	await selectSection( deliverySectionTitle );
	await createField( fieldB, fieldKey( 'b' ) );
	await saveAndWait( 'field B in inherited checkout delivery section' );
	await reloadEditor();

	const afterAlternateSave = await readDraft();
	const storedProfile = ( afterAlternateSave?.profiles ?? [] ).find(
		( profile ) => profile.name === profileName
	);
	const deliverySection = ( storedProfile?.sections ?? [] ).find(
		( section ) => section.title === deliverySectionTitle
	);
	const savedB = ( afterAlternateSave?.fields ?? [] ).find(
		( field ) => field.label === fieldB
	);
	if (
		! storedProfile ||
		! deliverySection ||
		! savedB ||
		savedB.section !== deliverySection.id
	) {
		throw new Error(
			'Field B or its delivery section was not persisted in the alternate checkout.'
		);
	}
	findings.push( 'alternate:field B persisted with its owned section' );

	step = 'default-does-not-show-field-b';
	await selectCheckout( 'Checkout padrão' );
	if (
		( await page
			.locator( '.field-row' )
			.filter( { hasText: fieldB } )
			.count() ) > 0
	) {
		throw new Error(
			'Checkout padrão rendered field B from the alternate checkout.'
		);
	}
	findings.push(
		'default:field B did not migrate into the default checkout'
	);

	step = 'alternate-field-b-after-navigation';
	await selectCheckout( profileName );
	await selectSection( deliverySectionTitle );
	await page.locator( '.field-row' ).filter( { hasText: fieldB } ).waitFor();
	findings.push( 'navigation:return to alternate checkout restored field B' );

	step = 'create-documents-section-and-field-c';
	await createSection( documentsSectionTitle );
	await selectSection( documentsSectionTitle );
	await createField( fieldC, fieldKey( 'c' ) );
	await saveAndWait( 'documents section and field C' );
	await reloadEditor();
	await selectCheckout( profileName );
	await selectSection( documentsSectionTitle );
	await page.locator( '.field-row' ).filter( { hasText: fieldC } ).waitFor();
	findings.push( 'reload:documents section and field C restored' );

	step = 'remove-documents-section';
	await removeSection( documentsSectionTitle );
	await saveAndWait( 'remove documents section and field C' );
	await reloadEditor();
	await selectCheckout( profileName );
	const afterSectionRemoval = await readDraft();
	const remainingProfile = ( afterSectionRemoval?.profiles ?? [] ).find(
		( profile ) => profile.name === profileName
	);
	if (
		( remainingProfile?.sections ?? [] ).some(
			( section ) => section.title === documentsSectionTitle
		) ||
		( afterSectionRemoval?.fields ?? [] ).some(
			( field ) => field.label === fieldC
		)
	) {
		throw new Error(
			'Removing Documents did not remove its section and field C.'
		);
	}
	findings.push( 'alternate:documents section removal persisted' );

	step = 'remove-inherited-checkout';
	await page
		.getByRole( 'button', { name: 'Excluir checkout', exact: true } )
		.click();
	await saveAndWait( 'remove inherited checkout' );
	await reloadEditor();
	if (
		( await page
			.getByRole( 'tab', { name: profileName, exact: true } )
			.count() ) > 0
	) {
		throw new Error(
			'Removed inherited checkout is still present after reload.'
		);
	}
	if ( ! ( await selectedCheckoutName() ).includes( 'Checkout padrão' ) ) {
		throw new Error(
			'Checkout padrão was not restored after removing the alternate checkout.'
		);
	}
	findings.push( 'default:restored after alternate checkout removal' );

	step = 'restore-baseline';
	await restoreBaseline();

	if ( pageErrors.length > 0 ) {
		throw new Error(
			`Browser pageerror detected: ${ pageErrors.join( ' | ' ) }`
		);
	}
	if ( apiErrors.length > 0 ) {
		throw new Error(
			`REST/API error detected: ${ apiErrors.join( ' | ' ) }`
		);
	}

	console.log(
		JSON.stringify( { ok: true, findings, pageErrors, apiErrors }, null, 2 )
	);
} catch ( error ) {
	failures.push( `step=${ step } url=${ page.url() }` );
	failures.push( String( error?.message || error ).split( '\n' )[ 0 ] );
	console.log(
		JSON.stringify(
			{ ok: false, findings, failures, pageErrors, apiErrors },
			null,
			2
		)
	);
	process.exitCode = 1;
} finally {
	try {
		await restoreBaseline();
	} catch ( cleanupError ) {
		failures.push(
			`cleanup=${
				String( cleanupError?.message || cleanupError ).split(
					'\n'
				)[ 0 ]
			}`
		);
		process.exitCode = 1;
	}
	await browser.close();
}
