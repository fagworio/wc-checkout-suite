/**
 * Payment frame tests.
 *
 * The acceptance for this component is mostly a list of things it must not do —
 * "não duplicar inputs, clonar iframes, interceptar tokens ou recriar SDK de
 * pagamento" — and an absence is asserted by counting: the nodes the module is given
 * are the nodes that are there afterwards, the gateway's own fields keep their values
 * and their attributes, and the only thing that changes is which panel is visible.
 *
 * The accordion's own behaviour is then asserted the same way a customer would see it:
 * the radio the checkout has selected is the panel that is open, and the radios are
 * the form's own, so a selection the module made is a selection the form submits.
 */

import {
	createPaymentFrame,
	prepareMethod,
} from '../../../resources/checkout/payment';

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
 * The payment box as WooCommerce renders it, with two gateways: one that carries a
 * form of its own — the shape a real gateway has — and one without fields.
 *
 * @return {void}
 */
function withPaymentBox() {
	document.body.innerHTML = `
		<ul class="wc_payment_methods payment_methods methods">
			<li class="wc_payment_method payment_method_wccs_card">
				<input id="payment_method_wccs_card" type="radio" class="input-radio" name="payment_method" value="wccs_card" checked />
				<label for="payment_method_wccs_card">Card</label>
				<div class="payment_box payment_method_wccs_card">
					<input type="text" name="wccs-card-number" value="4111" />
					<input type="hidden" name="wccs-card-nonce" value="abc123" />
					<iframe title="gateway" src="https://gateway.example/frame"></iframe>
				</div>
			</li>
			<li class="wc_payment_method payment_method_wccs_invoice">
				<input id="payment_method_wccs_invoice" type="radio" class="input-radio" name="payment_method" value="wccs_invoice" />
				<label for="payment_method_wccs_invoice">Invoice</label>
				<div class="payment_box payment_method_wccs_invoice" style="display:none">
					<p>Pay within five days.</p>
				</div>
			</li>
		</ul>
	`;
}

/**
 * The radios in the box.
 *
 * @return {Array<HTMLInputElement>} Radios.
 */
function radios() {
	return Array.from(
		document.querySelectorAll( 'input[name="payment_method"]' )
	);
}

describe( 'the payment frame', () => {
	beforeEach( withPaymentBox );

	it( 'prepares the checkout own radios and creates no input', () => {
		const before = radios();
		const inputsBefore = document.querySelectorAll( 'input' ).length;

		const frame = createPaymentFrame();
		const prepared = frame.run( document.body );

		expect( prepared ).toBe( 2 );
		expect( radios() ).toHaveLength( before.length );
		expect( document.querySelectorAll( 'input' ) ).toHaveLength(
			inputsBefore
		);

		// The very same nodes, not replacements: a gateway's field is bound to the
		// element the server rendered, and a clone would be a field that does not post.
		radios().forEach( ( radio, index ) => {
			expect( radio ).toBe( before[ index ] );
		} );
	} );

	it( 'pairs each radio with the panel the gateway wrote', () => {
		const frame = createPaymentFrame();
		frame.run( document.body );

		const radio = el( '#payment_method_wccs_card' );
		const panel = el( '.payment_method_wccs_card .payment_box' );

		expect( radio ).toHaveAttribute( 'aria-controls', panel.id );
		expect( panel ).toHaveAttribute( 'data-wccs-payment-panel' );
		expect( panel.querySelector( 'iframe' ) ).toHaveAttribute(
			'src',
			'https://gateway.example/frame'
		);
	} );

	it( 'does not decide which panel is open, or change the one that is', () => {
		// The state belongs to the platform: the template renders the chosen method's
		// panel and hides the others with an inline style, and `frontend/checkout.js`
		// slides them when the radio changes. A module that also set visibility would
		// be a second owner, and the disagreement that matters is the panel of a method
		// the customer just selected, hidden by an attribute the platform never touches.
		const frame = createPaymentFrame();

		const card = el( '.payment_method_wccs_card .payment_box' );
		const invoice = el( '.payment_method_wccs_invoice .payment_box' );

		const cardStyle = card.getAttribute( 'style' );
		const invoiceStyle = invoice.getAttribute( 'style' );

		frame.run( document.body );

		expect( card.getAttribute( 'style' ) ).toBe( cardStyle );
		expect( invoice.getAttribute( 'style' ) ).toBe( invoiceStyle );
		expect( card ).not.toHaveAttribute( 'hidden' );
		expect( invoice ).not.toHaveAttribute( 'hidden' );
	} );

	it( 'follows a change of method rather than deciding it', () => {
		const frame = createPaymentFrame();
		frame.run( document.body );

		const invoiceRadio = /** @type {HTMLInputElement} */ (
			el( '#payment_method_wccs_invoice' )
		);
		const cardRadio = /** @type {HTMLInputElement} */ (
			el( '#payment_method_wccs_card' )
		);

		// What the customer's own click does, and what the checkout's script listens
		// for. The frame is not what selects a method, and it is not what opens a panel.
		invoiceRadio.checked = true;
		cardRadio.checked = false;
		invoiceRadio.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		frame.run( document.body );

		expect( invoiceRadio.checked ).toBe( true );
		expect( cardRadio.checked ).toBe( false );
		expect( invoiceRadio ).toHaveAttribute( 'aria-controls' );
		expect( el( '.payment_method_wccs_invoice' ) ).toHaveAttribute(
			'data-wccs-payment-frame',
			'true'
		);
	} );

	it( 'does not touch what the gateway put inside a panel', () => {
		const frame = createPaymentFrame();
		frame.run( document.body );

		const number = el( 'input[name="wccs-card-number"]' );
		const nonce = el( 'input[name="wccs-card-nonce"]' );

		expect( number ).toHaveValue( '4111' );
		expect( number ).not.toBeDisabled();
		expect( number ).not.toHaveAttribute( 'hidden' );
		expect( nonce ).toHaveValue( 'abc123' );
	} );

	it( 'never moves focus', () => {
		const frame = createPaymentFrame();
		const radio = el( '#payment_method_wccs_invoice' );

		/** @type {any} */ ( radio ).focus();

		frame.run( document.body );

		expect( document.activeElement ).toBe( radio );
	} );

	it( 'prepares a row once, however many passes run', () => {
		const frame = createPaymentFrame();

		expect( frame.run( document.body ) ).toBe( 2 );

		const panel = el( '.payment_method_wccs_card .payment_box' );
		const id = panel.id;

		expect( frame.run( document.body ) ).toBe( 0 );
		expect( panel.id ).toBe( id );
		expect( document.querySelectorAll( '.payment_box' ) ).toHaveLength( 2 );
	} );

	it( 'leaves an input that is not a payment method radio alone', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input type="checkbox" name="wccs_terms" value="yes" />'
		);

		const frame = createPaymentFrame();
		frame.run( document.body );

		const terms = /** @type {any} */ ( el( 'input[name="wccs_terms"]' ) );

		expect( terms ).not.toHaveAttribute( 'aria-controls' );
		expect( terms ).not.toHaveAttribute( 'data-wccs-payment-frame' );
	} );

	it( 'reports a method with no panel instead of inventing one', () => {
		document.body.innerHTML = `
			<ul class="wc_payment_methods">
				<li class="wc_payment_method">
					<input id="payment_method_bare" type="radio" name="payment_method" value="bare" checked />
					<label for="payment_method_bare">Bare</label>
				</li>
			</ul>
		`;

		const radio = /** @type {HTMLInputElement} */ (
			el( '#payment_method_bare' )
		);
		const outcome = prepareMethod( radio );

		expect( outcome.prepared ).toBe( false );
		expect( outcome.reason ).toBe( 'no_panel' );
		expect( radio.checked ).toBe( true );
		expect( document.querySelectorAll( '.payment_box' ) ).toHaveLength( 0 );
	} );

	it( 'applies a decoration only when the record did not withhold it', () => {
		// The matrix is data and this module is the consumer: a gateway whose record
		// withheld the panel box gets the wiring — the id, the aria-controls, the mode
		// on the row — and no marker for the stylesheet to draw a box from. A withheld
		// decoration is never applied rather than applied and undone, which is the
		// difference between a record that is honoured and one that is negotiated.
		const frame = createPaymentFrame( {
			decisions: {
				wccs_card: { mode: 'compatible', withheld: [ 'panel' ] },
				wccs_invoice: { mode: 'decorated', withheld: [] },
			},
		} );

		frame.run( document.body );

		const cardPanel = /** @type {any} */ (
			el( '.payment_method_wccs_card .payment_box' )
		);
		const invoicePanel = /** @type {any} */ (
			el( '.payment_method_wccs_invoice .payment_box' )
		);

		expect( cardPanel ).not.toHaveAttribute( 'data-wccs-payment-panel' );
		expect( invoicePanel ).toHaveAttribute(
			'data-wccs-payment-panel',
			'true'
		);

		// Wired either way: what is withheld is the decoration, not the field's
		// accessibility or the pairing the customer's screen reader reads.
		expect(
			/** @type {any} */ ( el( '#payment_method_wccs_card' ) )
		).toHaveAttribute( 'aria-controls', cardPanel.id );
		expect(
			/** @type {any} */ ( el( '.payment_method_wccs_card' ) )
		).toHaveAttribute( 'data-wccs-mode', 'compatible' );
		expect(
			/** @type {any} */ ( el( '.payment_method_wccs_invoice' ) )
		).toHaveAttribute( 'data-wccs-mode', 'decorated' );
	} );

	it( 'treats a gateway with no record as undecided and says so on the row', () => {
		const frame = createPaymentFrame();

		frame.run( document.body );

		// Nothing is promised about a gateway nobody ran, and the row says which answer
		// it got rather than leaving a merchant to guess.
		expect(
			/** @type {any} */ ( el( '.payment_method_wccs_card' ) )
		).toHaveAttribute( 'data-wccs-mode', 'undecided' );
		expect(
			/** @type {any} */ (
				el( '.payment_method_wccs_card .payment_box' )
			)
		).toHaveAttribute( 'data-wccs-payment-panel', 'true' );
	} );

	it( 'accepts a method becoming available and refuses one going missing', () => {
		const frame = createPaymentFrame();

		frame.run( document.body );

		const box = /** @type {any} */ ( el( '.wc_payment_methods' ) );

		// A refresh that offers one more gateway is WooCommerce saying a method
		// became available, and the frame takes it.
		box.insertAdjacentHTML(
			'beforeend',
			`<li class="wc_payment_method">
				<input id="payment_method_wccs_new" type="radio" name="payment_method" value="wccs_new" />
				<label for="payment_method_wccs_new">New</label>
				<div class="payment_box"></div>
			</li>`
		);

		expect( frame.run( document.body ) ).toBe( 1 );

		// A method that disappears is a radio the customer can no longer choose.
		radios()
			.slice( 0, 2 )
			.forEach( ( radio ) =>
				/** @type {any} */ ( radio.closest( 'li' ) ).remove()
			);

		expect( () => frame.run( document.body ) ).toThrow(
			/The payment frame lost a payment method radio/
		);
	} );
} );
