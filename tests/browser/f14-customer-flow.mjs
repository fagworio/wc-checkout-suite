/**
 * The user-level observation of F14 in the storefront: a customer, a real order, and the
 * pages the destinations decide.
 *
 * Two modes, because the observed checkout and the observed pages need an order each on
 * either side of a gap this script found:
 *
 *   - `WCCS_MODE=place` signs in as a customer, empties the cart, fills the store's own
 *     Blocks checkout — including the fields this plugin registers there — places the
 *     order and prints its identifier and key.
 *   - `WCCS_MODE=observe` (with `WCCS_ORDER` and `WCCS_KEY`) opens the pages that same
 *     order appears on and reads what each area shows: the thank-you page
 *     (`order_received`) and the account's order view (`customer_order`).
 *
 * What it checks:
 *
 *   1. **The fields reach the checkout.** A Suite field is rendered by WooCommerce's own
 *      additional-fields API, which is the only way it can appear on a page this plugin
 *      does not own.
 *   2. **The page decides the area.** `codigo_retirada` is linked to `order_received` only
 *      and `documento_fiscal` to `customer_order` (with `admin_order` and the two
 *      e-mails). Each page must show its own and not the other's.
 *   3. **Nothing appears where it was not linked.** `campo_sem_vinculo` was filled in and
 *      is linked nowhere; `preferencia_perfil` is linked only to the profile area, which
 *      has no surface yet.
 *   4. **The approval flow tells the customer the order is waiting** — the review state and
 *      the situation it shows.
 *
 * Usage:
 *   WCCS_MODE=place node tests/browser/f14-customer-flow.mjs
 *   WCCS_MODE=observe WCCS_ORDER=123 WCCS_KEY=wc_order_... node tests/browser/f14-customer-flow.mjs
 *
 * The order it places is a real order on the store; the identifier is printed so it can be
 * removed afterwards.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const CHECKOUT = `${ ORIGIN }/finalizar-compra/`;
const LOGIN = `${ ORIGIN }/wp-login.php`;
const ORDERS = `${ ORIGIN }/minha-conta/orders/`;
const PRODUCT = process.env.WCCS_PRODUCT || '2775';
const USER = process.env.WCCS_USER || 'wccs-cliente-f14';
const PASS = process.env.WCCS_PASS || 'F14-fluxo-cliente!';
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const MODE = process.env.WCCS_MODE || 'place';
const ORDER = process.env.WCCS_ORDER || '';
const KEY = process.env.WCCS_KEY || '';

const findings = [];
const notes = [];
const record = ( label, ok, detail = '' ) => findings.push( { label, ok: Boolean( ok ), detail } );
const note = ( message ) => notes.push( message );

const values = {
	fiscal: '123.456.789-09',
	retirada: 'RET-42',
	semVinculo: 'NUNCA-MOSTRAR',
	perfil: 'PERFIL-NAO-MOSTRAR',
};

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }` ],
} );
const page = await browser.newPage( { viewport: { width: 1440, height: 1200 } } );

const errors = [];

page.on( 'pageerror', ( e ) => errors.push( e.message ) );

/**
 * Runs one step, reporting a failure rather than aborting the run.
 *
 * @param {string}   label Step name.
 * @param {Function} run   Step body.
 * @return {Promise<void>} Resolves either way.
 */
async function step( label, run ) {
	try {
		await run();
	} catch ( error ) {
		record( label, false, String( error && error.message ? error.message : error ).split( '\n' )[ 0 ] );
	}
}

/**
 * Signs the customer in, the way a customer does.
 *
 * @return {Promise<void>} Resolves after the login request.
 */
async function signIn() {
	await page.goto( LOGIN, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * The visible text of the page, whitespace collapsed.
 *
 * @return {Promise<string>} Text.
 */
async function body() {
	return ( await page.locator( 'body' ).innerText() ).replace( /\s+/g, ' ' );
}

const suiteField = ( name ) => page.locator( `#billing-wc-checkoutsuite-${ name }` );

await signIn();

if ( 'place' === MODE ) {
	// An empty cart, so the order this run places is the order it filled.
	await page.goto( `${ ORIGIN }/carrinho/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 1000 );

	for ( const remove of await page.locator( 'a[href*="remove_item"], .wc-block-cart-item__remove-link' ).all() ) {
		await remove.click().catch( () => {} );
		await page.waitForTimeout( 700 );
	}

	await page.goto( `${ ORIGIN }/?add-to-cart=${ PRODUCT }&quantity=1`, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 800 );
	await page.goto( CHECKOUT, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 6000 );

	const reachable = ( await page.locator( '#billing-first_name' ).count() ) > 0;

	record(
		'The storefront serves the checkout to a customer',
		reachable,
		reachable ? 'the Blocks checkout is on the page' : ( await body() ).slice( 0, 120 )
	);

	if ( ! reachable ) {
		note( 'Without a checkout there is no order to observe; the run stops here.' );
	} else {
		// The Blocks checkout collapses the address block once it has an address, and the
		// Suite fields live inside it: a customer clicks the card's edit control to reach them.
		const edit = page.locator( '.wc-block-components-address-card__edit' );

		if (
			( await edit.count() ) > 0 &&
			! ( await page.locator( '#billing-first_name' ).first().isVisible().catch( () => true ) )
		) {
			await edit.first().click();
			await page.waitForTimeout( 1200 );
		}

		await step( 'The Suite fields are rendered by the checkout', async () => {
			const labels = await page.evaluate( () =>
				Array.from( document.querySelectorAll( '[id^=billing-wc-checkoutsuite-]' ) ).map( ( node ) =>
					(
						document.querySelector( `label[for="${ node.id }"]` )?.innerText ||
						node.closest( '.wc-block-components-text-input' )?.innerText ||
						''
					)
						.replace( /\s+/g, ' ' )
						.trim()
				)
			);

			record(
				'Every field the store published is offered to the customer',
				labels.length === 4,
				labels.join( ' | ' )
			);

			record(
				'Including the one linked only to the thank-you page',
				( await suiteField( 'codigo_retirada' ).count() ) === 1,
				'Código de retirada'
			);
		} );

		await step( 'The checkout could be filled and submitted', async () => {
			await page.fill( '#email', 'cliente-f14@example.test' );
			await page.fill( '#billing-first_name', 'Cliente' );
			await page.fill( '#billing-last_name', 'do Fluxo' );

			const country = page.locator( '#billing-country' );

			if ( ( await country.count() ) > 0 ) {
				await country.selectOption( 'BR' );
				await page.waitForTimeout( 1500 );
			}

			await page.fill( '#billing-address_1', 'Rua das Asserções, 42' );
			await page.fill( '#billing-city', 'São Paulo' );
			await page.fill( '#billing-postcode', '01001000' );

			const state = page.locator( '#billing-state' );

			if ( ( await state.count() ) > 0 ) {
				const options = await state.locator( 'option' ).evaluateAll( ( nodes ) => nodes.map( ( n ) => n.value ) );
				const chosen = options.find( ( value ) => 'SP' === value ) || options[ 1 ];

				if ( chosen ) {
					await state.selectOption( chosen );
				}
			}

			for ( const [ name, value ] of Object.entries( {
				documento_fiscal: values.fiscal,
				codigo_retirada: values.retirada,
				campo_sem_vinculo: values.semVinculo,
				preferencia_perfil: values.perfil,
			} ) ) {
				await suiteField( name ).fill( value );
			}

			await page.screenshot( { path: `${ OUT }/f14-customer-checkout.png`, fullPage: true } );

			// The offline gateway this run enables: no credentials, and the only way a
			// customer can place an order on a store whose real gateways are unconfigured.
			const gateway = page.locator( 'label' ).filter( { hasText: /Transferência bancária|Bank transfer/ } ).first();

			if ( ( await gateway.count() ) > 0 ) {
				await gateway.click();
			}

			await page.waitForTimeout( 1500 );

			record(
				'A payment method is offered by the store',
				( await page.locator( 'input[type=radio]' ).count() ) > 0,
				'the offline gateway this run enables'
			);

			await page.getByRole( 'button', { name: /Finalizar pedido/ } ).first().click();
			await page.waitForTimeout( 9000 );
		} );

		const url = page.url();
		const orderId = ( url.match( /order-received\/(\d+)/ ) || [] )[ 1 ] || '';
		const orderKey = ( url.match( /key=(wc_order_[A-Za-z0-9]+)/ ) || [] )[ 1 ] || '';

		record( 'The order was placed and the thank-you page is what answers', '' !== orderId, url );

		await page.screenshot( { path: `${ OUT }/f14-thankyou.png`, fullPage: true } );

		if ( '' !== orderId ) {
			console.log( `ORDER=${ orderId }` );
			console.log( `KEY=${ orderKey }` );
			note( `The order this run placed is #${ orderId }: a real order, removable afterwards.` );
		}
	}
} else {
	record( 'The run has an order to observe', '' !== ORDER && '' !== KEY, 'order=' + ORDER );

	const thankyou = '' === ORDER ? '' : `${ CHECKOUT }order-received/${ ORDER }/?key=${ KEY }`;

	await step( 'The thank-you page could be opened', async () => {
		await page.goto( thankyou, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 2500 );
		await page.screenshot( { path: `${ OUT }/f14-thankyou.png`, fullPage: true } );

		const text = await body();

		record(
			'The thank-you page shows the field linked to it, with its value and section',
			text.includes( 'Código de retirada' ) && text.includes( values.retirada ) && text.includes( 'Documentos enviados' ),
			'order_received: Código de retirada / Documentos enviados'
		);

		record(
			'And not the field that belongs to the account page',
			! text.includes( 'Documento enviado' ) && ! text.includes( values.fiscal ),
			'the customer_order link stays out'
		);

		record(
			'And nothing that was linked nowhere, or only to the profile',
			! text.includes( values.semVinculo ) &&
				! text.includes( values.perfil ) &&
				! text.includes( 'Campo sem vínculo' ) &&
				! text.includes( 'Como prefere ser contactado' ),
			'no unlinked field, no profile field'
		);

		record(
			'The approval flow tells the customer the order is waiting',
			text.includes( 'Pendente de aprovação' ),
			'the review state, on the page the customer sees'
		);
	} );

	await step( 'The account order view could be opened', async () => {
		await page.goto( ORDERS, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 2000 );

		const link = page.locator( `a[href*="view-order/${ ORDER }"], a[href*="/view-order/"]` ).first();

		if ( ( await link.count() ) === 0 ) {
			throw new Error( 'no order link in the account: ' + ( await body() ).slice( 0, 160 ) );
		}

		await link.click();
		await page.waitForTimeout( 3000 );
		await page.screenshot( { path: `${ OUT }/f14-account-order.png`, fullPage: true } );

		const text = await body();

		record(
			'The account page shows the field linked to it, with its value and section',
			text.includes( 'Documento enviado' ) && text.includes( values.fiscal ) && text.includes( 'Documentos enviados' ),
			'customer_order: Documento enviado / Documentos enviados'
		);

		record(
			'And not the field that belongs to the thank-you page',
			! text.includes( 'Código de retirada' ) && ! text.includes( values.retirada ),
			'the order_received link stays out'
		);

		record(
			'And still nothing that was linked nowhere',
			! text.includes( values.semVinculo ) && ! text.includes( values.perfil ),
			'no unlinked field, no profile field'
		);

		record(
			'The situation is shown here too, because it is the same order',
			text.includes( 'Pendente de aprovação' ),
			'the review state travels with the order'
		);
	} );
}

record( 'No page error was raised while the storefront was used', errors.length === 0, errors.join( ' | ' ) );

await browser.close();

console.log( '=====================================================================' );
console.log( `F14 user-level observation — the storefront (${ MODE })` );
console.log( '=====================================================================' );

for ( const finding of findings ) {
	console.log(
		`  ${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${ finding.detail ? `  [${ finding.detail }]` : '' }`
	);
}

for ( const message of notes ) {
	console.log( `  NOTE  ${ message }` );
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log( '=====================================================================' );
console.log( `RESULT: ${ findings.length - failed } passed, ${ failed } failed, ${ notes.length } notes` );
console.log( '=====================================================================' );

process.exit( failed > 0 ? 1 : 0 );
