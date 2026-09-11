/**
 * Applies the registered masks to the Suite's fields.
 *
 * IMask is a local, pinned dependency bundled into this checkout bundle. Section 9
 * forbids a CDN at runtime, and the reason is not purity: a checkout that fetches
 * its JavaScript from somebody else's server fails when that server does, and the
 * failure lands on the merchant mid-sale.
 *
 * A mask is a typing aid and never an authority — ADR-0003 rule 5. What it
 * produces is what the customer sees; what the store keeps is what the server's
 * normalizer makes of it, and the server checks the value again on submit. That is
 * why nothing here tries to compute the canonical value: sending the digits
 * instead of what the customer sees would be the client making a decision that
 * belongs to the server, and the two would drift the first time a document format
 * changed.
 *
 * Two properties of the library shape this module, and both were measured rather
 * than assumed:
 *
 * - A mask created on an element that **already holds a value** formats that value
 *   and leaves the cursor at the end. A mask created on an empty element does not
 *   reformat a value assigned to it afterwards. The value keeper therefore runs
 *   *before* this component, so a restored value is formatted by the mask that is
 *   then created over it, instead of sitting unformatted inside a masked field.
 * - The wildcard token `*` matches any character, not an alphanumeric one, so a
 *   mask built from it is permissive. It is left that way on purpose: tightening
 *   it would move a rule about what a document may contain into the browser, and
 *   the layer that owns that rule is the validator on the server.
 *
 * The keyboard a field asks for comes from the pattern too, for the same reason:
 * a numeric keypad is right for eleven digits and makes an alphanumeric document
 * impossible to type, so a pattern that accepts letters asks for no keypad.
 */

import IMask from 'imask';

/**
 * Turns a registered mask definition into IMask options.
 *
 * The definitions are data from the server, and only the shapes this plugin
 * registers are understood: a pattern string, and the `type: 'pattern'`
 * configuration the generic masks use. Anything else answers null, and the caller
 * leaves the field unmasked and says so — a mask applied as the wrong thing is
 * worse than no mask, because it formats a value nobody can type back.
 *
 * @param {any} definition Declarative definition.
 * @return {any|null} IMask options, or null when the shape is not understood.
 */
export function optionsFor( definition ) {
	if ( typeof definition === 'string' ) {
		return { mask: definition };
	}

	if (
		definition &&
		'object' === typeof definition &&
		'pattern' === definition.type &&
		'string' === typeof definition.pattern
	) {
		const { type, pattern, ...rest } = definition;

		return { ...rest, mask: pattern };
	}

	return null;
}

/**
 * The keyboard a mask's own pattern asks for, if it asks for one.
 *
 * A phone keypad is the right keyboard for eleven digits and the wrong one for a
 * document that can contain letters: an alphanumeric CNPJ typed on a numeric
 * keypad cannot be typed at all, which is how a mobile device would break the
 * value rather than the mask breaking it. So the pattern decides, and a pattern
 * that accepts anything asks for nothing.
 *
 * The tokens are IMask's: `0` is a digit, `a` a letter and `*` anything. Every
 * other character in the pattern is a literal the customer does not type.
 *
 * @param {any} options IMask options.
 * @return {string} An `inputmode` value, or an empty string for none.
 */
export function keyboardFor( options ) {
	const pattern = options && options.mask;

	if ( 'string' !== typeof pattern ) {
		return '';
	}

	if ( pattern.includes( '*' ) || pattern.includes( 'a' ) ) {
		return '';
	}

	return pattern.includes( '0' ) ? 'numeric' : '';
}

/**
 * Creates the component that masks the Suite's fields.
 *
 * @param {Record<string, any>} definitions Mask entries, keyed by field identifier.
 * @param {string}              attribute   Attribute the server marks its fields with.
 * @return {{attribute: string, apply: (element: Element) => boolean}} The component.
 */
export function createMaskedFields(
	definitions = {},
	attribute = 'data-wccs-field'
) {
	/**
	 * Masks one element, if its field declares a mask.
	 *
	 * Called by the lifecycle, once per element it has never seen. An element that
	 * survived a refresh is never offered again, which is what keeps the cursor
	 * still: re-creating a mask over a live input resets the caret to the end.
	 *
	 * @param {Element} element Element.
	 * @return {boolean} Whether a mask was applied.
	 */
	function apply( element ) {
		const field = element.getAttribute( attribute );

		if ( ! field ) {
			return false;
		}

		const entry = definitions[ field ];

		if ( ! entry ) {
			return false;
		}

		const options = optionsFor( entry.definition );

		if ( ! options ) {
			// The field stays unmasked and the reason is visible. Silently doing
			// nothing would look exactly like a field that declares no mask.
			// eslint-disable-next-line no-console
			console.warn(
				`[wc-checkoutsuite] The mask "${ entry.key }" on the field "${ field }" is not one this checkout can apply, so the field is left unmasked.`,
				entry.definition
			);

			return false;
		}

		// The attribute the server marks its fields with is only ever rendered on
		// form controls, and IMask takes a control. The cast states that, rather
		// than widening the function's contract to every Element on the page.
		const input = /** @type {any} */ ( element );

		IMask( input, options );

		const keyboard = keyboardFor( options );

		if ( '' !== keyboard ) {
			input.setAttribute( 'inputmode', keyboard );
		}

		return true;
	}

	return { attribute, apply };
}
