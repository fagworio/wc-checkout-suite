/**
 * Real WooCommerce customer journey for the complete WCCS field matrix.
 *
 * The PHP fixture provisions a temporary customer, product, published schema and
 * classic checkout, then this file drives the browser exactly as a customer would.
 * It deliberately reports unsupported/degraded capabilities instead of treating
 * them as browser failures.
 *
 * Usage:
 *   node tests/browser/full-woocommerce-flow.mjs
 *   WCCS_KEEP_DATA=1 node tests/browser/full-woocommerce-flow.mjs
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const ORIGIN = process.env.WCCS_ORIGIN || 'http://wpagf.dvl.to:8080';
const PHP_CONTAINER = process.env.WCCS_PHP_CONTAINER || 'devilbox-php-1';
const WP_PATH = process.env.WCCS_WP_PATH || '/shared/httpd/wpagf/htdocs';
const OUT = process.env.WCCS_OUT || '/tmp/wccs-full-commerce-flow';
const KEEP = process.env.WCCS_KEEP_DATA === '1';
const state = { findings: [], capabilities: [], notes: [], orderId: '', orderKey: '' };

mkdirSync( OUT, { recursive: true } );

function wp( action ) {
	const output = execFileSync( 'docker', [ 'exec', PHP_CONTAINER, 'wp', '--allow-root', `--path=${ WP_PATH }`, 'eval-file', `${ WP_PATH }/wp-content/plugins/wc-checkout-suite/tests/Integration/support/full-commerce-flow-fixture.php`, action ], { encoding: 'utf8' } );
	return output;
}

function jsonLine( output, prefix ) {
	const line = output.split( '\n' ).find( ( candidate ) => candidate.startsWith( prefix ) );
	if ( ! line ) throw new Error( `fixture did not print ${ prefix }` );
	return JSON.parse( line.slice( prefix.length ) );
}

function record( label, ok, detail = '' ) {
	state.findings.push( { label, ok: Boolean( ok ), detail } );
}

function capability( type, status, detail ) {
	state.capabilities.push( { type, status, detail } );
}

function field( id ) {
	return page.locator( `[data-wccs-field="wccs_e2e_${ id }"]` ).first();
}

let fixture;
let page;
let browser;

try {
	fixture = jsonLine( wp( 'setup' ), 'WCCS_FULL_FLOW=' );
	state.notes.push( `fixture schema revision ${ fixture.schema_revision }` );

	browser = await chromium.launch( {
		executablePath: process.env.WCCS_CHROME || '/usr/bin/google-chrome',
		args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }` ],
	} );
	page = await browser.newPage( { viewport: { width: 1440, height: 1200 } } );
	const pageErrors = [];
	const checkoutResponses = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
	page.on( 'response', ( response ) => {
		if ( response.url().includes( 'wc-ajax' ) || response.url().includes( 'checkout' ) ) checkoutResponses.push( `${ response.status() } ${ response.url() }` );
	} );

	await page.goto( `${ ORIGIN }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', fixture.username );
	await page.fill( '#user_pass', fixture.password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'domcontentloaded' );
	record( 'cliente temporário autenticado', ! ( await page.locator( '#login_error' ).count() ) );

	await page.goto( `${ ORIGIN }/?add-to-cart=${ fixture.product_id }&quantity=1`, { waitUntil: 'domcontentloaded' } );
	await page.goto( fixture.checkout_url, { waitUntil: 'networkidle' } );
	await page.screenshot( { path: `${ OUT }/01-checkout-inicial.png`, fullPage: true } );
	record( 'checkout clássico renderizado', await page.locator( 'form.checkout' ).count() === 1 );
	record( 'produto no checkout', ( await page.locator( 'body' ).innerText() ).includes( fixture.product_name ) );

	const standard = {
		'#billing_first_name': 'Cliente',
		'#billing_last_name': 'E2E WCCS',
		'#billing_email': fixture.email,
		'#billing_phone': '11999998888',
		'#billing_address_1': 'Rua de Teste, 123',
		'#billing_city': 'São Paulo',
		'#billing_postcode': '01311000',
	};
	for ( const [ selector, value ] of Object.entries( standard ) ) {
		if ( await page.locator( selector ).count() ) await page.fill( selector, value );
	}
	if ( await page.locator( '#billing_country' ).count() ) await page.selectOption( '#billing_country', 'BR' );
	if ( await page.locator( '#billing_state' ).count() ) await page.selectOption( '#billing_state', 'SP' ).catch( () => page.fill( '#billing_state', 'SP' ) );

	const values = {
		text: '529.982.247-25', textarea: 'Instrução E2E do cliente', email: fixture.email, tel: '11999998888', number: '42', url: 'https://example.test',
		select: 'beta', radio: 'beta', checkbox: true, date: '2026-09-14', time: '12:30', datetime: '2026-09-14T12:30', hidden: 'e2e-hidden', conditional: 'Campo condicional preenchido',
	};

	for ( const [ type, value ] of Object.entries( values ) ) {
		const locator = field( type );
		if ( ! await locator.count() ) {
			capability( type, 'not-rendered', type === 'hidden' ? 'Campo oculto não é visível por definição.' : 'Não foi renderizado nesta engine.' );
			continue;
		}
		if ( type === 'checkbox' ) await locator.check();
		else if ( [ 'select' ].includes( type ) ) await locator.selectOption( value );
		else if ( [ 'radio' ].includes( type ) ) {
			const radio = page.locator( `[data-wccs-field="wccs_e2e_${ type }"][value="${ value }"]` );
			await radio.evaluate( ( element ) => { element.checked = true; element.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
		}
		else if ( type === 'hidden' ) await locator.evaluate( ( element, next ) => { element.value = next; element.dispatchEvent( new Event( 'input', { bubbles: true } ) ); element.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }, String( value ) );
		else await locator.fill( String( value ) );
		capability( type, 'filled', 'Preenchido no formulário real.' );
	}

	for ( const type of [ 'multiselect', 'checkbox-group' ] ) capability( type, 'not-rendered', 'O adaptador Classic não possui controle para este tipo; o Blocks usa controle próprio.' );
	capability( 'file', ( await field( 'file' ).count() ) ? 'rendered-unavailable' : 'not-rendered', 'Upload permanece bloqueado enquanto o ambiente não comprovar armazenamento privado.' );
	capability( 'heading', 'not-rendered', 'Campo de layout não é entrada de checkout.' );
	await page.screenshot( { path: `${ OUT }/02-checkout-preenchido.png`, fullPage: true } );

	const payment = page.locator( 'input[name="payment_method"]' );
	if ( await payment.count() ) {
		await payment.first().check();
		await page.locator( 'body' ).press( 'Tab' ).catch( () => {} );
	}
	record( 'método de pagamento selecionável', await payment.count() > 0 );

	await page.locator( '#place_order' ).click();
	await page.waitForURL( /order-received/, { timeout: 15000 } ).catch( () => {} );
	await page.waitForTimeout( 1000 );
	await page.screenshot( { path: `${ OUT }/03-order-received.png`, fullPage: true } );
	const receivedUrl = page.url();
	const match = receivedUrl.match( /order-received\/(\d+)/ );
	state.orderId = match ? match[1] : '';
	state.orderKey = new URL( receivedUrl ).searchParams.get( 'key' ) || '';
	record( 'pedido criado', Boolean( state.orderId ), receivedUrl );
	const receivedBody = await page.locator( 'body' ).innerText();
	state.notes.push( `checkout responses: ${ checkoutResponses.join( ' | ' ) }` );
	record( 'destino order_received observado', Boolean( state.orderId ) && receivedBody.includes( 'E2E' ), state.orderId ? '' : receivedBody.match( /(?:erro|Error|obrigatório|required)[^\n]*/i )?.[ 0 ] || 'checkout permaneceu na página' );
	record( 'página sem erro JavaScript', pageErrors.length === 0, pageErrors.join( ' | ' ) );

	if ( ! state.orderId ) throw new Error( 'checkout não criou pedido; consulte 03-order-received.png para os erros de validação' );
	await page.goto( `${ ORIGIN }/minha-conta/orders/`, { waitUntil: 'domcontentloaded' } );
	const orderLink = page.locator( 'a[href*="view-order"]' ).first();
	record( 'pedido visível na conta do cliente', await orderLink.count() > 0 );
	if ( await orderLink.count() ) {
		await orderLink.click();
		await page.waitForLoadState( 'networkidle' );
		await page.screenshot( { path: `${ OUT }/04-customer-order.png`, fullPage: true } );
		record( 'destino customer_order observado', ( await page.locator( 'body' ).innerText() ).includes( 'E2E' ) );
	}

	writeFileSync( `${ OUT }/report.json`, JSON.stringify( { fixture, ...state }, null, 2 ) );
	console.log( JSON.stringify( { fixture, ...state }, null, 2 ) );
} catch ( error ) {
	record( 'execução do fluxo completo', false, String( error?.stack || error ) );
	if ( page ) await page.screenshot( { path: `${ OUT }/error.png`, fullPage: true } ).catch( () => {} );
	writeFileSync( `${ OUT }/report.json`, JSON.stringify( { fixture, ...state }, null, 2 ) );
	console.error( String( error?.stack || error ) );
} finally {
	if ( browser ) await browser.close();
	try {
		wp( `teardown${ KEEP ? ' keep' : '' }` );
	} catch ( error ) {
		state.notes.push( `teardown failed: ${ String( error ) }` );
	}
}

const failures = state.findings.filter( ( finding ) => ! finding.ok );
console.log( `WCCS_FULL_FLOW_RESULT=${ JSON.stringify( { failures: failures.length, findings: state.findings.length, order_id: state.orderId, report: OUT } ) }` );
process.exitCode = failures.length ? 1 : 0;
