/**
 * Browser journey for the Fields editor's destructive and constructive flows.
 *
 * It intentionally does not click "Atualizar campos": every mutation stays in
 * the isolated browser tab, so it can exercise a merchant journey without
 * changing the store configuration.
 *
 * Usage:
 * WCCS_COOKIE='name=value' WCCS_AUTH_COOKIE='name=value' node tests/browser/admin-section-field-rules-flow.mjs
 */
import { chromium } from 'playwright';

const origin = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const results = [];
const record = ( name, ok, detail = '' ) => results.push( { name, ok, detail } );

const browser = await chromium.launch( {
	executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
	args: [ '--no-sandbox' ],
} );
const page = await browser.newPage();
page.setDefaultTimeout( 4000 );

for ( const cookie of [ process.env.WCCS_COOKIE, process.env.WCCS_AUTH_COOKIE ].filter( Boolean ) ) {
	const split = cookie.indexOf( '=' );
	await page.context().addCookies( [ {
		name: cookie.slice( 0, split ), value: cookie.slice( split + 1 ),
		domain: 'wpagf.dvl.to', path: '/', httpOnly: true, secure: false, sameSite: 'Lax',
	} ] );
}

const errors = [];
let stage = 'abrir editor';
page.on( 'pageerror', ( error ) => errors.push( error.message ) );

try {
	await page.goto( `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`, { waitUntil: 'domcontentloaded', timeout: 15000 } );
	const name = `E2E seção ${ Date.now() }`;

	await page.getByRole( 'button', { name: 'Nova seção' } ).click();
	await page.locator( '#wccs-new-section-title' ).fill( name );
	await page.getByRole( 'button', { name: /Add section|Adicionar seção/ } ).click();
	const sectionTab = page.locator( '.section-tabs button' ).filter( { hasText: name } );
	await sectionTab.waitFor();
	await sectionTab.click();
	record( 'cria uma seção', true );

	await page.getByRole( 'button', { name: 'Adicionar campo', exact: true } ).click();
	await page.getByTestId( 'field-type-file' ).click();
	await page.locator( '#wccs-new-label' ).fill( 'Arquivo E2E' );
	await page.locator( '#wccs-new-key' ).fill( 'arquivo_e2e' );
	await page.getByRole( 'button', { name: 'Adicionar campo', exact: true } ).last().click();
	await page.locator( '.field-row' ).filter( { hasText: 'Arquivo E2E' } ).waitFor();
	record( 'adiciona campo File com defaults', true );

	stage = 'abrir propriedades do campo';
	await page.locator( '[data-row-id="arquivo_e2e"] .field-info' ).click();
	await page.getByRole( 'button', { name: 'Regras', exact: true } ).click();
	await page.getByRole( 'button', { name: 'Add a condition', exact: true } ).first().click();
	await page.locator( '#wccs-condition-root-source' ).selectOption( 'country' );
	await page.locator( '#wccs-condition-root-operator' ).selectOption( 'equals' );
	await page.locator( '#wccs-condition-root-value' ).fill( 'BR' );
	await page.locator( '.condition-result' ).filter( { hasText: 'BR' } ).waitFor();
	record( 'adiciona uma regra condicional válida', true );

	stage = 'editar regra condicional';
	await page.locator( '#wccs-condition-root-value' ).fill( 'PT' );
	await page.locator( '.condition-result' ).filter( { hasText: 'PT' } ).waitFor();
	record( 'edita a regra condicional', true );

	stage = 'remover regra condicional';
	await page.getByRole( 'button', { name: 'Always show this field', exact: true } ).click();
	await page.getByText( 'This field is always shown.', { exact: true } ).waitFor();
	record( 'remove a regra condicional', true );

	stage = 'renomear seção e conferir áreas';
	await page.getByRole( 'button', { name: /Ações da seção/ } ).click();
	await page.getByRole( 'dialog', { name: 'Section' } ).getByText( 'Preenchido pelo cliente durante a finalização da compra.', { exact: true } ).waitFor();
	await page.locator( '#wccs-section-title' ).fill( `${ name } renomeada` );
	await page.getByRole( 'button', { name: 'Done', exact: true } ).click();
	const renamedSectionTab = page.locator( '.section-tabs button' ).filter( { hasText: `${ name } renomeada` } );
	await renamedSectionTab.waitFor();
	record( 'renomeia a seção e reflete o título na aba', true );

	stage = 'confirmar dependências da seção';
	await page.getByRole( 'button', { name: /Ações da seção/ } ).click();
	await page.getByRole( 'button', { name: 'Remove section', exact: true } ).click();
	const dependencyDialog = page.getByRole( 'dialog', { name: 'Remover seção e dependências' } );
	await dependencyDialog.locator( 'li' ).filter( { hasText: 'Arquivo E2E' } ).waitFor();
	await dependencyDialog.getByRole( 'button', { name: 'Cancelar', exact: true } ).click();
	await dependencyDialog.waitFor( { state: 'hidden' } );
	const sectionDialog = page.getByRole( 'dialog', { name: 'Section' } );
	await sectionDialog.getByRole( 'button', { name: 'Done', exact: true } ).click();
	await sectionDialog.waitFor( { state: 'hidden' } );
	record( 'lista dependências e permite cancelar a remoção', true );

	stage = 'abrir Regras do editor';
	await page.getByRole( 'button', { name: 'Regras do editor' } ).click();
	await page.waitForTimeout( 200 );
	record( 'abre Regras do editor', ( await page.locator( 'body' ).innerText() ).includes( 'Regras' ) );

	stage = 'retornar ao editor';
	await page.getByRole( 'button', { name: 'Editor de campos' } ).click();
	await page.waitForURL( /section=fields/ );
	await page.locator( '.field-row' ).filter( { hasText: 'Arquivo E2E' } ).waitFor();
	stage = 'abrir ações do campo';
	await page.getByRole( 'button', { name: /Ações de Arquivo E2E/ } ).click();
	stage = 'remover campo';
	await page.getByRole( 'button', { name: /Excluir campo/ } ).click();
	record( 'remove o campo temporário', await page.locator( '.field-row' ).filter( { hasText: 'File' } ).count() === 0 );

	stage = 'selecionar seção temporária';
	await renamedSectionTab.click();
	stage = 'abrir ações da seção';
	await page.getByRole( 'button', { name: /Ações da seção/ } ).click();
	stage = 'remover seção';
	await page.getByRole( 'button', { name: /Remove section/ } ).click();
	record( 'remove a seção temporária vazia', await renamedSectionTab.count() === 0 );

	await page.goto( `${ origin }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`, { waitUntil: 'domcontentloaded', timeout: 15000 } );
	record( 'nenhum erro JavaScript', errors.length === 0, errors.join( ' | ' ) );
} catch ( error ) {
	record( 'percurso administrativo', false, `${ stage }: ${ String( error.message || error ).split( '\n' )[ 0 ] }` );
} finally {
	await browser.close();
}

console.log( JSON.stringify( { results, failures: results.filter( ( result ) => ! result.ok ) }, null, 2 ) );
process.exitCode = results.some( ( result ) => ! result.ok ) ? 1 : 0;
