/**
 * Order summary tests.
 *
 * Two properties are asserted here and both are about restraint. The summary is
 * WooCommerce's: the module adds exactly one node — its own control — and the totals,
 * the coupon form and the shipping options are byte-identical afterwards, because a
 * second copy of a price is a copy that goes stale. And the disclosure behaves like a
 * disclosure: open on a wide page with no control at all, closed on a narrow one, and
 * focus stays on the button that was pressed.
 */

import {
	createOrderSummary,
	TOGGLE_CLASS,
} from '../../../resources/checkout/summary';

/**
 * The first element matching a selector.
 *
 * Typed as `any` because that is what the DOM hands back here: the specs read
 * attributes and ids off the elements they just built, and a cast at every call site
 * would be noise around the assertion rather than part of it.
 *
 * @param {string} selector CSS selector.
 * @return {any} The element.
 */
function el( selector ) {
	return document.querySelector( selector );
}

/**
 * The review markup WooCommerce renders, with the numbers in it.
 *
 * The values are WooCommerce's in this fixture the same way they are in a real page:
 * the module never reads them, so what is asserted is that it never writes them.
 *
 * @return {void}
 */
function withReview() {
	document.body.innerHTML = `
		<form name="checkout" class="checkout">
			<div id="customer_details"><input name="billing_first_name" /></div>
			<div class="woocommerce-checkout-review-order" id="order_review">
				<table class="shop_table">
					<tr><th>Product</th><td>R$ 120,00</td></tr>
					<tr class="cart-subtotal"><th>Subtotal</th><td>R$ 120,00</td></tr>
					<tr class="order-total"><th>Total</th><td>R$ 135,00</td></tr>
				</table>
				<div class="woocommerce-checkout-payment">
					<ul class="wc_payment_methods"><li></li></ul>
				</div>
			</div>
		</form>
	`;
}

/**
 * The review element.
 *
 * @return {HTMLElement} Review.
 */
function review() {
	return /** @type {HTMLElement} */ (
		el( '.woocommerce-checkout-review-order' )
	);
}

/**
 * The control.
 *
 * @return {HTMLButtonElement|null} Control.
 */
function control() {
	return el( `.${ TOGGLE_CLASS }` );
}

describe( 'the order summary', () => {
	beforeEach( withReview );

	it( 'adds one control and nothing else to the page', () => {
		const nodesBefore = document.body.querySelectorAll( '*' ).length;
		const markupBefore = review().innerHTML;

		const summary = createOrderSummary( { label: 'Order summary' } );
		summary.run( document.body );

		expect( document.body.querySelectorAll( '*' ) ).toHaveLength(
			nodesBefore + 1
		);
		expect( review().innerHTML ).toBe( markupBefore );
	} );

	it( 'never writes a number the store published', () => {
		const summary = createOrderSummary( { label: 'Order summary' } );
		summary.run( document.body );

		const totals = review().querySelector( '.order-total td' );
		const subtotal = review().querySelector( '.cart-subtotal td' );

		expect( totals ).toHaveTextContent( 'R$ 135,00' );
		expect( subtotal ).toHaveTextContent( 'R$ 120,00' );

		summary.toggle();
		summary.toggle();

		expect( totals ).toHaveTextContent( 'R$ 135,00' );
		expect( subtotal ).toHaveTextContent( 'R$ 120,00' );
		expect( review().querySelectorAll( '.order-total' ) ).toHaveLength( 1 );
	} );

	it( 'leaves a wide page open and offers no control', () => {
		const summary = createOrderSummary( {
			label: 'Order summary',
			narrow: () => false,
		} );

		summary.run( document.body );

		expect( review() ).not.toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( summary.isOpen() ).toBe( true );
	} );

	it( 'starts closed on a narrow page and opens on the control', () => {
		const summary = createOrderSummary( {
			label: 'Order summary',
			show: 'Show order summary',
			hide: 'Hide order summary',
			narrow: () => true,
		} );

		summary.run( document.body );

		expect( review() ).toHaveAttribute( 'hidden' );
		expect( control() ).not.toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( control() ).toHaveAttribute(
			'aria-label',
			'Show order summary'
		);

		/** @type {any} */ ( control() ).click();

		expect( review() ).not.toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( control() ).toHaveAttribute(
			'aria-label',
			'Hide order summary'
		);

		/** @type {any} */ ( control() ).click();

		expect( review() ).toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'is a real button, wired to the summary it opens', () => {
		const summary = createOrderSummary( {
			label: 'Order summary',
			narrow: () => true,
		} );
		summary.run( document.body );

		const button = /** @type {any} */ ( control() );

		expect( button.tagName ).toBe( 'BUTTON' );
		expect( button ).toHaveAttribute( 'type', 'button' );
		expect( button ).toHaveAttribute( 'aria-controls', review().id );
		expect( button ).toHaveTextContent( 'Order summary' );

		// Next to the summary, never inside it: a control inside the element it hides
		// hides itself.
		expect( button.nextElementSibling ).toBe( review() );
		expect( review().contains( button ) ).toBe( false );
	} );

	it( 'keeps focus on the button that was pressed', () => {
		const summary = createOrderSummary( {
			label: 'Order summary',
			narrow: () => true,
		} );
		summary.run( document.body );

		const button = /** @type {any} */ ( control() );

		button.focus();
		button.click();

		expect( document.activeElement ).toBe( button );

		button.click();

		expect( document.activeElement ).toBe( button );
	} );

	it( 'reopens the summary when the page becomes wide again', () => {
		let isNarrow = true;

		const summary = createOrderSummary( {
			label: 'Order summary',
			narrow: () => isNarrow,
		} );

		summary.run( document.body );

		expect( review() ).toHaveAttribute( 'hidden' );

		isNarrow = false;
		summary.run( document.body );

		expect( review() ).not.toHaveAttribute( 'hidden' );
		expect( control() ).toHaveAttribute( 'hidden' );
	} );

	it( 'prepares one summary once and toggles the one it belongs to', () => {
		const summary = createOrderSummary( {
			label: 'Order summary',
			narrow: () => true,
		} );

		expect( summary.run( document.body ) ).toBe( 1 );
		expect( summary.run( document.body ) ).toBe( 0 );
		expect(
			document.querySelectorAll( `.${ TOGGLE_CLASS }` )
		).toHaveLength( 1 );
	} );
} );
