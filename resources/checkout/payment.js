/**
 * The payment accordion.
 *
 * Section 15 states what this may and may not be, and the two lists decide the whole
 * module:
 *
 * > Pagamentos em accordion: radios reais e conteúdo do gateway, sem recolher
 * > cartão/CVV em campos próprios da Suite. Não duplicar inputs, clonar iframes,
 * > interceptar tokens ou recriar SDK de pagamento.
 *
 * So this is a **presentation of what WooCommerce already rendered**, and nothing
 * else. The radios are the checkout's own `input[type="radio"][name="payment_method"]`
 * — the same ones the form submits — and the panel content is the gateway's own
 * markup, moved nowhere and copied nowhere. Selecting a method is left to the radio
 * the browser already knows how to select, and the `change` event WooCommerce listens
 * for is the one the customer's own click produces.
 *
 * Three consequences, and each is a decision rather than an omission:
 *
 * 1. **No input is created, moved or replaced.** An accordion that rebuilt the
 *    payment list would be re-rendering a gateway's form, and a gateway's form is
 *    the one thing this plugin must not own: it holds the nonce, the token field and
 *    the script tags that initialise it. The module only adds attributes and the
 *    `aria-*` wiring, and every element it touches is one that was already there.
 *
 * 2. **Which panel is open is not decided here.** WooCommerce already does it: the
 *    template renders the chosen method's panel and hides the others with an inline
 *    `style="display:none"`, and `assets/js/frontend/checkout.js` slides the boxes up
 *    and down when the radio changes. A module that also set visibility would be two
 *    owners of one state, and they disagree in the direction that matters: the panel
 *    of a method the customer just selected, hidden by an attribute the platform's
 *    script does not know about, is a checkout that cannot be paid.
 *
 * 3. **Focus is never moved, and the keyboard is the platform's.** Arrow keys move
 *    within a radio group because that is what a radio group does. What this module
 *    adds is the wiring a screen reader needs to say what the group is: a name for the
 *    group, and `aria-controls` on each radio pointing at the panel it belongs to.
 *
 * Nothing here reads a total, a coupon or a shipping rate. The payment box is not
 * where a price is decided, and a module that formatted one would be a second source
 * for a number the store already published.
 */

/** Attribute marking a payment method's panel as already prepared. */
const PREPARED = 'data-wccs-payment-frame';

/**
 * The label a radio belongs to, which is the only part of the row a customer clicks.
 *
 * @param {HTMLInputElement} radio Radio input.
 * @return {HTMLLabelElement|null} Label.
 */
function labelFor( radio ) {
	const view = radio.ownerDocument.defaultView;

	if ( ! view ) {
		return null;
	}

	if ( radio.id ) {
		const found = radio.ownerDocument.querySelector(
			`label[for="${ radio.id.replace( /["\\]/g, '\\$&' ) }"]`
		);

		if ( found instanceof view.HTMLLabelElement ) {
			return found;
		}
	}

	const wrapping = radio.closest( 'label' );

	return wrapping instanceof view.HTMLLabelElement ? wrapping : null;
}

/**
 * The panel a radio's method owns.
 *
 * WooCommerce renders it as a sibling of the label inside the method's `li`, in the
 * three shapes the classic checkout has used: `.payment_box`, and a box that carries
 * no class at all on some themes. Which one it is does not matter here — what matters
 * is that it is the element the gateway wrote, found rather than created.
 *
 * @param {HTMLInputElement} radio Radio input.
 * @return {HTMLElement|null} Panel.
 */
function panelFor( radio ) {
	const row = radio.closest( 'li' );

	if ( ! row ) {
		return null;
	}

	const box = row.querySelector( '.payment_box' );

	if ( box ) {
		return /** @type {HTMLElement} */ ( box );
	}

	// A box with no class: the element after the label inside the row, when it is
	// not another method's radio.
	for ( const child of Array.from( row.children ) ) {
		if ( child.querySelector( 'input[name="payment_method"]' ) ) {
			continue;
		}

		if (
			child.classList.contains( 'payment_method' ) ||
			child.tagName === 'LABEL' ||
			child.tagName === 'INPUT' ||
			child.tagName === 'SCRIPT' ||
			child.tagName === 'STYLE'
		) {
			continue;
		}

		return /** @type {HTMLElement} */ ( child );
	}

	return null;
}

/**
 * Prepares one payment method row.
 *
 * Exported because it is the unit of behaviour: a row is prepared once, and the
 * properties it must have — a real radio, the panel that belongs to that radio, and
 * the pairing between them — are asserted per row rather than through the whole box.
 *
 * @param {HTMLInputElement} radio Radio input.
 * @return {{prepared: boolean, reason: string, panel: HTMLElement|null}} Outcome.
 */
export function prepareMethod( radio ) {
	const view = radio.ownerDocument.defaultView;

	if ( ! view || ! ( radio instanceof view.HTMLInputElement ) ) {
		return { prepared: false, reason: 'not_an_input', panel: null };
	}

	if ( 'radio' !== radio.type || 'payment_method' !== radio.name ) {
		// Every other kind of input in the payment box belongs to a gateway. Reading
		// its value, marking it or hiding it would be touching a form this plugin does
		// not own, so it is left exactly as the gateway wrote it.
		return { prepared: false, reason: 'not_a_method_radio', panel: null };
	}

	const panel = panelFor( radio );
	const label = labelFor( radio );

	if ( ! panel || ! label ) {
		// Without both, an accordion has nothing to open and nothing to click. The
		// radio still works: the customer can select the method, and the panel is
		// simply always visible, which is the checkout's own behaviour.
		return {
			prepared: false,
			reason: ! panel ? 'no_panel' : 'no_label',
			panel,
		};
	}

	if ( 'true' === radio.getAttribute( PREPARED ) ) {
		return { prepared: true, reason: 'already_prepared', panel };
	}

	if ( ! panel.id ) {
		panel.id = `wccs-payment-panel-${ radio.value || 'method' }`;
	}

	// The row is a group with a name, so what a screen reader announces when the
	// customer lands on a radio is the method and not an unlabelled control.
	radio.setAttribute( 'aria-controls', panel.id );

	// The marker goes on the radio and on its row: the radio is what this function is
	// asked about the next time, and the row is what the stylesheet reads.
	radio.setAttribute( PREPARED, 'true' );

	const row = radio.closest( 'li' );

	if ( row ) {
		row.setAttribute( PREPARED, 'true' );
	}

	panel.setAttribute( 'data-wccs-payment-panel', 'true' );

	// The panel's visibility is left exactly as it was found. It is WooCommerce's
	// state, expressed in the element's own inline style, and the checkout's script is
	// what changes it when the customer picks another method.
	return { prepared: true, reason: '', panel };
}

/**
 * Creates the payment frame over the checkout's own payment box.
 *
 * @param {Object}                [options]          Options.
 * @param {string}                [options.selector] Box selector.
 * @param {Document|Element|null} [options.root]     Where to look.
 * @return {{run: (root?: Document|Element|null) => number}} Component.
 */
export function createPaymentFrame( {
	selector = '.wc_payment_methods',
	root = null,
} = {} ) {
	/**
	 * How many methods the box held when this frame last looked.
	 *
	 * @type {number|null}
	 */
	let seen = null;

	/**
	 * Every method radio in the box, in the order the gateway list has them.
	 *
	 * @param {Document|Element|null} scope Where to look.
	 * @return {Array<HTMLInputElement>} Radios.
	 */
	function radios( scope ) {
		const where = scope ?? root ?? document;

		if ( ! where || typeof where.querySelectorAll !== 'function' ) {
			return [];
		}

		const found =
			'matches' in where && where.matches( selector )
				? where
				: where.querySelector( selector );

		if ( ! found ) {
			return [];
		}

		return Array.from(
			found.querySelectorAll(
				'input[type="radio"][name="payment_method"]'
			)
		);
	}

	/**
	 * Wires every method that has not been wired yet.
	 *
	 * @param {Document|Element|null} [scope] Where to look.
	 * @return {number} How many rows were prepared in this pass.
	 */
	function run( scope = null ) {
		let prepared = 0;

		for ( const radio of radios( scope ) ) {
			const outcome = prepareMethod( radio );

			// Only a row prepared in *this* pass counts. `prepared` is true for a row
			// that was already done as well, and counting those would report that every
			// method was prepared again on every refresh — the count is the only thing
			// that says whether the once-per-element rule held.
			if ( outcome.prepared && '' === outcome.reason ) {
				prepared += 1;
			}
		}

		// The guard has a direction on purpose. A refresh that offers a new method is
		// WooCommerce saying a gateway became available, and refusing that would break
		// a real checkout. A method that *disappears* is the dangerous direction: the
		// radio the customer can no longer reach is a method that can no longer be
		// chosen, and a frame that accepted it silently would be hiding a broken
		// payment list behind a tidy accordion.
		const total = radios( null ).length;

		if ( null === seen ) {
			seen = total;
		} else if ( total < seen ) {
			throw new Error(
				'The payment frame lost a payment method radio; the method list shrank.'
			);
		} else {
			seen = total;
		}

		return prepared;
	}

	return { run };
}
