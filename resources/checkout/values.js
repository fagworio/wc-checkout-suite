/**
 * Keeps what the customer typed across a checkout refresh.
 *
 * WooCommerce's classic checkout replaces parts of itself over AJAX. The Suite's
 * fields normally live in the address sections and survive, but a plugin or theme
 * can add its own fragment through `woocommerce_update_order_review_fragments`,
 * and anything inside a replaced fragment comes back empty — the server renders it
 * from the session, and a Suite field's value is not in the session.
 *
 * The mechanism has two halves and they are on opposite sides of the request:
 * capture on `update_checkout`, which fires before the AJAX call, and restore on
 * `updated_checkout`, which fires after the new HTML is in the page.
 *
 * Restoring is safe because of where it is called from. The lifecycle only starts
 * a component on an element it has never seen, so this only ever restores into an
 * element that was just replaced — which is the only case where a value can have
 * been lost. An element that survived is left exactly as it is.
 *
 * Events are deliberately **not** dispatched after a restore. WooCommerce
 * recalculates on `change`, so a restore that announced itself would ask for a
 * refresh, which would replace the element again, which would restore again.
 * Nothing needs the recalculation either: only the Suite's own fields are marked
 * and only those are restored, and those fields carry no shipping, tax or total.
 */

/**
 * An answer recorded before a refresh.
 *
 * @typedef {Object} RecordedValue
 * @property {string}  value   Value the element held.
 * @property {boolean} checked Whether a box or radio was checked.
 * @property {string}  type    Input type of the element.
 */

/**
 * A value keeper.
 *
 * @typedef {Object} ValueKeeper
 * @property {string}                              attribute Attribute the server marks its fields with.
 * @property {(root?: Document|Element) => number} capture   Records what the form holds.
 * @property {(element: Element) => boolean}       restore   Restores one element.
 */

/**
 * Reads the current answer out of one element.
 *
 * Duck typed rather than cast to an input: the same attribute can sit on an
 * input, a select or a textarea, and they do not share a class. Asking whether
 * the property exists is the honest test; asserting a type would be a claim the
 * markup may not honour.
 *
 * @param {Element} element Element.
 * @return {RecordedValue} Recorded answer.
 */
function read( element ) {
	return {
		value: 'value' in element ? String( element.value ) : '',
		checked: 'checked' in element ? Boolean( element.checked ) : false,
		type: 'type' in element ? String( element.type ) : '',
	};
}

/**
 * Creates a value keeper.
 *
 * @param {string} [attribute] Attribute the server marks its own fields with.
 * @return {ValueKeeper} Keeper.
 */
export function createValueKeeper( attribute = 'data-wccs-field' ) {
	/** @type {Map<string, RecordedValue[]>} */
	const captured = new Map();

	const selector = `[${ attribute }]`;

	/**
	 * Records what the form currently holds.
	 *
	 * Called before the refresh is requested, and called again before every
	 * later one, so what it holds is always the most recent answer rather than
	 * the one from the first refresh.
	 *
	 * @param {Document|Element} [root] Element to search under.
	 * @return {number} How many elements were recorded.
	 */
	function capture( root = document ) {
		captured.clear();

		let count = 0;

		root.querySelectorAll( selector ).forEach( ( element ) => {
			const field = element.getAttribute( attribute );

			if ( ! field ) {
				return;
			}

			const entries = captured.get( field ) || [];

			entries.push( read( element ) );

			captured.set( field, entries );
			count += 1;
		} );

		return count;
	}

	/**
	 * Restores one element's value, if it has one to restore.
	 *
	 * Called by the lifecycle, once per element it has never seen — so on first
	 * page load this finds nothing captured and does nothing.
	 *
	 * @param {Element} element Element.
	 * @return {boolean} Whether a value was restored.
	 */
	function restore( element ) {
		const field = element.getAttribute( attribute );

		if ( ! field ) {
			return false;
		}

		const entries = captured.get( field );

		if ( ! entries || 0 === entries.length ) {
			return false;
		}

		const current = read( element );

		if ( 'checkbox' === current.type || 'radio' === current.type ) {
			const match = entries.find(
				( entry ) => entry.value === current.value
			);

			if (
				match &&
				match.checked &&
				! current.checked &&
				'checked' in element
			) {
				element.checked = true;

				return true;
			}

			return false;
		}

		// A replacement that came back with something in it was rendered with a
		// value — a default, or the server's own answer. That is not a loss, and
		// overwriting it would be this module inventing an answer.
		if ( '' !== current.value ) {
			return false;
		}

		const previous = entries[ 0 ].value;

		if ( '' === previous || ! ( 'value' in element ) ) {
			return false;
		}

		element.value = previous;

		return true;
	}

	return { attribute, capture, restore };
}
