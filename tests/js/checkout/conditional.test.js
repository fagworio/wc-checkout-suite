/**
 * Conditional field tests.
 *
 * What is asserted here is the part of the policy the page owns: a row is shown or
 * hidden from a rule the server published, `required` is lowered for a field the
 * page has hidden and never raised, and the value follows the policy the
 * definition declared — cleared for `discard`, kept for `preserve`.
 *
 * The engine itself is not tested here. `tests/js/checkout/conditions.test.js`
 * holds it to the shared fixtures, and this module is only about turning an answer
 * into what the customer sees.
 */

import { createConditionalFields } from '../../../resources/checkout/conditional';

/**
 * Builds a billing country field.
 *
 * @param {string} value Current value.
 * @return {void}
 */
function withCountry( value ) {
	document.body.innerHTML = `
		<p class="form-row" id="billing_country_field">
			<select id="billing_country"><option>BR</option><option>PT</option></select>
		</p>
		<p class="form-row" id="wccs_document_field">
			<input data-wccs-field="wccs_document" id="wccs_document" required aria-required="true" />
		</p>
		<p class="form-row" id="wccs_free_field">
			<input data-wccs-field="wccs_free" id="wccs_free" />
		</p>
	`;

	/** @type {any} */ ( document.querySelector( '#billing_country' ) ).value =
		value;
}

/**
 * The published rules used by the specs.
 *
 * @type {Record<string, {policy: string, visible: any}>}
 */
const RULES = {
	wccs_document: {
		policy: 'discard',
		visible: { source: 'country', operator: 'equals', value: 'BR' },
	},
};

/**
 * Runs the component once over the current document.
 *
 * @param {Record<string, {policy: string, visible: any}>} [rules] Rules.
 * @return {any} The component.
 */
function build( rules = RULES ) {
	const component = createConditionalFields( { rules, document } );

	component.run();

	return component;
}

/**
 * The row of a field.
 *
 * @param {string} id Field identifier.
 * @return {any} Row element.
 */
const row = ( id ) => document.querySelector( `#${ id }_field` );

describe( 'conditional fields', () => {
	it( 'hides the row of a field whose rule does not match', () => {
		withCountry( 'PT' );
		build();

		expect( row( 'wccs_document' ).hidden ).toBe( true );
		expect(
			row( 'wccs_document' ).classList.contains( 'wccs-hidden-by-rule' )
		).toBe( true );
	} );

	it( 'shows the row of a field whose rule matches', () => {
		withCountry( 'BR' );
		build();

		expect( row( 'wccs_document' ).hidden ).toBe( false );
	} );

	it( 'clears the value of a hidden field that discards it', () => {
		withCountry( 'PT' );
		/** @type {any} */ (
			document.querySelector( '#wccs_document' )
		).value = '123';

		build();

		expect(
			/** @type {any} */ ( document.querySelector( '#wccs_document' ) )
				.value
		).toBe( '' );
	} );

	it( 'keeps the value of a hidden field that preserves it', () => {
		withCountry( 'PT' );
		/** @type {any} */ (
			document.querySelector( '#wccs_document' )
		).value = '123';

		build( {
			wccs_document: { ...RULES.wccs_document, policy: 'preserve' },
		} );

		expect(
			/** @type {any} */ ( document.querySelector( '#wccs_document' ) )
				.value
		).toBe( '123' );
	} );

	it( 'lowers required while the field is hidden and restores it afterwards', () => {
		withCountry( 'PT' );

		const component = build();
		const control = /** @type {any} */ (
			document.querySelector( '#wccs_document' )
		);

		expect( control.required ).toBe( false );
		expect( control.hasAttribute( 'aria-required' ) ).toBe( false );

		/** @type {any} */ (
			document.querySelector( '#billing_country' )
		).value = 'BR';
		component.run();

		expect( control.required ).toBe( true );
		expect( control.getAttribute( 'aria-required' ) ).toBe( 'true' );
	} );

	it( 'never raises required for a field the server had not marked', () => {
		withCountry( 'PT' );
		build( {
			wccs_free: {
				policy: 'discard',
				visible: { source: 'country', operator: 'equals', value: 'PT' },
			},
		} );

		const control = /** @type {any} */ (
			document.querySelector( '#wccs_free' )
		);

		expect( row( 'wccs_free' ).hidden ).toBe( false );
		expect( control.required ).toBe( false );
	} );

	it( 'reads another field from the page', () => {
		withCountry( 'BR' );
		build( {
			wccs_free: {
				policy: 'discard',
				visible: {
					source: 'field',
					field: 'wccs_document',
					operator: 'equals',
					value: 'pj',
				},
			},
		} );

		expect( row( 'wccs_free' ).hidden ).toBe( true );

		/** @type {any} */ (
			document.querySelector( '#wccs_document' )
		).value = 'pj';
		build( {
			wccs_free: {
				policy: 'discard',
				visible: {
					source: 'field',
					field: 'wccs_document',
					operator: 'equals',
					value: 'pj',
				},
			},
		} );

		expect( row( 'wccs_free' ).hidden ).toBe( false );
	} );

	it( 'reads a checkbox as a boolean rather than as the text on', () => {
		document.body.innerHTML = `
			<p class="form-row" id="wccs_consent_field">
				<input type="checkbox" data-wccs-field="wccs_consent" id="wccs_consent" />
			</p>
			<p class="form-row" id="wccs_gift_field">
				<input data-wccs-field="wccs_gift" id="wccs_gift" />
			</p>
		`;

		const component = createConditionalFields( {
			document,
			rules: {
				wccs_gift: {
					policy: 'discard',
					visible: {
						source: 'field',
						field: 'wccs_consent',
						operator: 'equals',
						value: true,
					},
				},
			},
		} );

		component.run();
		expect( row( 'wccs_gift' ).hidden ).toBe( true );

		/** @type {any} */ (
			document.querySelector( '#wccs_consent' )
		).checked = true;
		component.run();

		expect( row( 'wccs_gift' ).hidden ).toBe( false );
	} );

	it( 'leaves the control enabled, so the server still sees what it holds', () => {
		withCountry( 'PT' );
		build();

		expect(
			/** @type {any} */ ( document.querySelector( '#wccs_document' ) )
				.disabled
		).toBe( false );
	} );

	it( 'does nothing about a rule it was not given', () => {
		withCountry( 'PT' );
		build( {} );

		expect( row( 'wccs_document' ).hidden ).toBe( false );
	} );
} );
