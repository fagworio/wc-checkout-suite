/**
 * Fields a rule shows and hides, in the browser.
 *
 * The server decides; this makes the page agree with it while the customer is
 * still filling the form. Two things follow from that order, and they shape the
 * module:
 *
 * 1. **Only the rules the page can answer travel.** The server publishes the rules
 *    whose every source the page owns — the country and state being filled in, the
 *    shipping and payment methods chosen, and the value of another field. A rule
 *    that reads the cart or whether the customer is logged in stays on the server,
 *    which recomputes it with trusted context. Answering it here would be a second
 *    opinion, and the two opinions would disagree in the direction that blocks an
 *    order: the browser hiding a field the server considers required.
 *
 * 2. **`required` is hidden, never invented.** Removing the attribute from a field
 *    the page has hidden is what stops WooCommerce's own browser validation from
 *    refusing a form over a field nobody can see. The attribute is never added back
 *    to a field the server had not marked required: the browser lowers what it
 *    knows about and never raises it.
 *
 * The value follows the policy the definition declared: a hidden `discard` field is
 * cleared, and a hidden `preserve` field keeps what the customer typed. The input
 * is never disabled, so the server always sees whatever the field holds and stays
 * the authority on what is stored.
 *
 * @see src/Domain/Validation/ValueProcessor.php
 * @see ROADMAP.md section 11
 */

import { evaluate } from './conditions';

/**
 * Reads the value of a form control.
 *
 * A checkbox reads as a boolean and a radio group as the checked value, because
 * that is what the source is: comparing the string `'on'` with `true` is how a
 * rule about a consent box starts being wrong.
 *
 * @param {any} element Form control.
 * @return {any} Value.
 */
function readControl( element ) {
	if ( ! element ) {
		return undefined;
	}

	if ( 'checkbox' === element.type ) {
		return Boolean( element.checked );
	}

	return element.value;
}

/**
 * Creates the conditional field component.
 *
 * @param {Object}                                         options             Options.
 * @param {Record<string, {policy: string, visible: any}>} [options.rules]     Rules by field identifier, as the server published them.
 * @param {string}                                         [options.attribute] Attribute carrying the field identifier.
 * @param {Document}                                       [options.document]  Document to work in.
 * @return {{ run: () => void, context: () => Record<string, any>, apply: (element: any, policy: string, visible: boolean) => void, attach: () => void }} The component.
 */
export function createConditionalFields( {
	rules = {},
	attribute = 'data-wccs-field',
	document: doc = typeof document !== 'undefined' ? document : undefined,
} = {} ) {
	/**
	 * Finds the control of a field.
	 *
	 * @param {string} id Field identifier.
	 * @return {any} Element, or null.
	 */
	const control = ( id ) =>
		doc?.querySelector( `[${ attribute }="${ id }"]` ) ?? null;

	/**
	 * Reads the country being filled in, from either address.
	 *
	 * Billing first, because that is where the field the rule usually concerns
	 * lives, and the shipping address when billing has nothing yet.
	 *
	 * @param {string} key `country` or `state`.
	 * @return {string} Value.
	 */
	const address = ( key ) => {
		for ( const prefix of [ 'billing', 'shipping' ] ) {
			const element = /** @type {any} */ (
				doc?.querySelector( `#${ prefix }_${ key }` )
			);

			if ( element && element.value ) {
				return element.value;
			}
		}

		return '';
	};

	/**
	 * Reads the checked value of a radio group.
	 *
	 * @param {string} name Group name.
	 * @return {string} Value.
	 */
	const chosen = ( name ) => {
		const element = /** @type {any} */ (
			doc?.querySelector( `input[name="${ name }"]:checked` )
		);

		return element ? element.value : '';
	};

	/**
	 * Builds the context the rules are evaluated against.
	 *
	 * @return {Record<string, any>} Context.
	 */
	const context = () => {
		/** @type {Record<string, any>} */
		const fields = {};

		doc?.querySelectorAll( `[${ attribute }]` ).forEach( ( element ) => {
			const id = element.getAttribute( attribute );

			if ( id ) {
				fields[ id ] = readControl( element );
			}
		} );

		return {
			country: address( 'country' ),
			state: address( 'state' ),
			shipping_method: chosen( 'shipping_method' ),
			payment_method: chosen( 'payment_method' ),
			fields,
		};
	};

	/**
	 * Shows or hides one field.
	 *
	 * @param {any}     element Control.
	 * @param {string}  policy  Hidden-value policy.
	 * @param {boolean} visible Whether the rule matched.
	 * @return {void}
	 */
	const apply = ( element, policy, visible ) => {
		const row =
			element.closest( '.form-row' ) ??
			element.closest( 'p' ) ??
			element.parentElement;

		if ( row ) {
			row.hidden = ! visible;
			row.classList.toggle( 'wccs-hidden-by-rule', ! visible );
		}

		if ( visible ) {
			// Only what the field already carried is restored: the attribute the
			// server rendered is remembered rather than assumed.
			if ( 'true' === element.dataset.wccsRequired ) {
				element.required = true;
				element.setAttribute( 'aria-required', 'true' );
			}

			return;
		}

		// Remembered on the way out, so showing the field again restores exactly
		// what WooCommerce asked for and nothing more.
		element.dataset.wccsRequired = element.required ? 'true' : 'false';
		element.required = false;
		element.removeAttribute( 'aria-required' );

		if ( 'discard' === policy ) {
			if ( 'checkbox' === element.type ) {
				element.checked = false;
			} else if (
				'select-one' === element.type ||
				'select-multiple' === element.type
			) {
				element.selectedIndex = -1;
			} else {
				element.value = '';
			}
		}
	};

	/**
	 * Evaluates every published rule and applies the answer.
	 *
	 * @return {void}
	 */
	const run = () => {
		const entries = context();

		for ( const [ id, rule ] of Object.entries( rules ) ) {
			const element = control( id );

			if ( ! element ) {
				continue;
			}

			apply(
				element,
				rule.policy ?? 'discard',
				evaluate( rule.visible, entries )
			);
		}
	};

	/**
	 * Runs whenever something a rule reads can have changed.
	 *
	 * @return {void}
	 */
	const attach = () => {
		if ( ! doc ) {
			return;
		}

		// `input` covers typing in another field, `change` covers the selects and
		// the radio groups, and the two WooCommerce events cover a refresh that
		// rebuilds the form from the server.
		doc.addEventListener( 'input', run );
		doc.addEventListener( 'change', run );
	};

	return { run, context, apply, attach };
}
