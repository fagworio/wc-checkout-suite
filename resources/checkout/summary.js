/**
 * The order summary disclosure.
 *
 * Section 15 asks for an expandable summary on a narrow page, followed by the form,
 * and states one rule that shapes this module more than any other:
 *
 * > Produto, valores, impostos, moeda, cupons e envio do HTML são demonstrativos. Em
 * > produção vêm do carrinho WooCommerce.
 *
 * So the summary is **WooCommerce's**, and this module never writes a number. It does
 * not total a line, it does not format a price, it does not copy a value into a
 * heading. What it does is make the element the store already rendered openable and
 * closable, and add the one control that does that.
 *
 * The same rule explains what is not here: the shipping options, the coupon form and
 * the totals table keep their own behaviour, because they are WooCommerce's forms and
 * the checkout refreshes them over AJAX. Re-rendering any of it would produce a second
 * copy of a number that the store has already published, and the second copy is the
 * one that goes stale.
 *
 * Two behaviours are deliberate:
 *
 * 1. **Content is never hidden on a wide page.** The disclosure exists for the narrow
 *    layout, where the summary would otherwise push the form far down the page. On a
 *    wide page the summary is open and the control is removed from the page, because a
 *    button that hides the order review on a desktop is a button nobody asked for.
 *
 * 2. **Focus is never moved.** Toggling leaves focus on the button that was activated,
 *    which is where the keyboard already is. A disclosure that jumps focus into the
 *    panel is a disclosure a keyboard user has to find their way back from.
 *
 * The decision of whether the page is narrow is handed in rather than read from
 * `window`, for the same reason the classic assets take their gate as an argument: a
 * branch nobody can exercise is a branch nobody has checked.
 */

/** Class of the control this module owns. */
export const TOGGLE_CLASS = 'wccs-summary__toggle';

/**
 * Creates the order summary disclosure.
 *
 * @param {Object}                [options]          Options.
 * @param {string}                [options.selector] Review element selector.
 * @param {() => boolean}         [options.narrow]   Whether the page is narrow.
 * @param {string}                [options.label]    Visible control text.
 * @param {string}                [options.show]     Accessible name when closed.
 * @param {string}                [options.hide]     Accessible name when open.
 * @param {Document|Element|null} [options.root]     Where to look.
 * @return {{run: (root?: Document|Element|null) => number, toggle: (element?: Element|null) => boolean, isOpen: (element?: Element|null) => boolean}} Component.
 */
export function createOrderSummary( {
	selector = '.woocommerce-checkout-review-order',
	narrow = () => false,
	label = '',
	show = '',
	hide = '',
	root = null,
} = {} ) {
	/**
	 * The review elements in scope.
	 *
	 * @param {Document|Element|null} scope Where to look.
	 * @return {Array<HTMLElement>} Elements.
	 */
	function reviews( scope ) {
		const where = scope ?? root ?? document;

		if ( ! where || typeof where.querySelectorAll !== 'function' ) {
			return [];
		}

		if ( 'matches' in where && where.matches( selector ) ) {
			return [ /** @type {HTMLElement} */ ( where ) ];
		}

		return Array.from( where.querySelectorAll( selector ) );
	}

	/**
	 * The control that belongs to a review element.
	 *
	 * Found by walking back from the review rather than by a global query: a page can
	 * have more than one summary, and a control that toggled whichever review it found
	 * first would be a control that toggles the wrong one.
	 *
	 * @param {HTMLElement} review Review element.
	 * @return {HTMLButtonElement|null} Control.
	 */
	function toggleFor( review ) {
		const previous = review.previousElementSibling;

		return previous && previous.classList.contains( TOGGLE_CLASS )
			? /** @type {HTMLButtonElement} */ ( previous )
			: null;
	}

	/**
	 * Whether the summary is currently open.
	 *
	 * @param {Element|null} [element] Control or review.
	 * @return {boolean} Open state.
	 */
	function isOpen( element = null ) {
		const review = element ?? reviews( null )[ 0 ];

		return review ? ! review.hasAttribute( 'hidden' ) : true;
	}

	/**
	 * Opens or closes one summary.
	 *
	 * @param {Element|null} [element] Control or review.
	 * @return {boolean} The new state.
	 */
	function toggle( element = null ) {
		const review = element ?? reviews( null )[ 0 ];

		if ( ! review ) {
			return true;
		}

		const control = toggleFor( /** @type {HTMLElement} */ ( review ) );
		const open = review.hasAttribute( 'hidden' );

		review.toggleAttribute( 'hidden', ! open );

		if ( control ) {
			control.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( hide || show ) {
				control.setAttribute(
					'aria-label',
					open ? hide || label : show || label
				);
			}
		}

		return open;
	}

	/**
	 * Applies the current layout to one summary.
	 *
	 * @param {HTMLElement} review Review element.
	 * @return {void}
	 */
	function apply( review ) {
		const control = toggleFor( review );
		const isNarrow = Boolean( narrow() );

		if ( ! isNarrow ) {
			// A wide page keeps the summary open and takes the control off the page.
			// `hidden` on the review is cleared rather than remembered, because the
			// page can change width and the summary must not stay closed behind a
			// control that is no longer rendered.
			review.removeAttribute( 'hidden' );

			if ( control ) {
				control.hidden = true;
				control.setAttribute( 'aria-expanded', 'true' );
			}

			return;
		}

		if ( ! control ) {
			return;
		}

		control.hidden = false;

		const open = ! review.hasAttribute( 'hidden' );

		control.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

		if ( hide || show ) {
			control.setAttribute(
				'aria-label',
				open ? hide || label : show || label
			);
		}
	}

	/**
	 * Removes a control left behind by an earlier element.
	 *
	 * @param {HTMLElement} review Review element.
	 * @return {void}
	 */
	function removeStaleControls( review ) {
		const document = review.ownerDocument;

		if ( ! review.id ) {
			return;
		}

		document
			.querySelectorAll(
				`.${ TOGGLE_CLASS }[aria-controls="${ review.id }"]`
			)
			.forEach( ( stale ) => stale.remove() );
	}

	/**
	 * Prepares every summary that has not been prepared yet.
	 *
	 * @param {Document|Element|null} [scope] Where to look.
	 * @return {number} How many summaries were prepared in this pass.
	 */
	function run( scope = null ) {
		let prepared = 0;

		for ( const review of reviews( scope ) ) {
			if ( ! toggleFor( review ) ) {
				// WooCommerce replaces the order review over AJAX, and a refresh that
				// replaces the element rather than its contents leaves the previous
				// control behind: a sibling of a node that is no longer in the page.
				// Removing it here is what keeps one summary with one control after any
				// number of refreshes.
				removeStaleControls( review );

				const control = review.ownerDocument.createElement( 'button' );

				control.type = 'button';
				control.className = TOGGLE_CLASS;

				if ( label ) {
					control.textContent = label;
				}

				if ( ! review.id ) {
					review.id = 'wccs-order-review';
				}

				control.setAttribute( 'aria-controls', review.id );

				// The control goes next to the summary, never inside it: a control
				// inside the element it hides is a control that hides itself.
				review.parentNode?.insertBefore( control, review );

				control.addEventListener( 'click', () => toggle( review ) );

				// The narrow layout starts closed — that is the point of the control,
				// and a summary that is open by default on a phone is the layout this
				// exists to avoid. The wide layout is applied in the same pass, so the
				// initial state never has to be guessed by the stylesheet.
				if ( narrow() ) {
					review.setAttribute( 'hidden', '' );
				}

				prepared += 1;
			}

			apply( review );
		}

		return prepared;
	}

	return { run, toggle, isOpen };
}
