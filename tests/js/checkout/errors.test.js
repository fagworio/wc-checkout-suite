/**
 * Error surface and form behaviour tests.
 *
 * The acceptance is three sentences: a message beside each field, the first error
 * focused, and the critical rule revalidated on submit. Each of them is asserted
 * here against a real DOM, plus the two properties section 10 adds that are easy
 * to lose — the value is never erased, and a check that could not be made is
 * never reported as a pass.
 */

import { createFieldErrors } from '../../../resources/checkout/errors';
import { createFormValidation } from '../../../resources/checkout/form';
import { STATUS } from '../../../resources/checkout/remote';
import { createFieldChecker } from '../../../resources/checkout/validate';

/**
 * Builds a checkout form with the given fields.
 *
 * @param {Array<{id: string, value?: string}>} fields Fields.
 * @return {any} The form.
 */
function buildForm( fields ) {
	document.body.innerHTML = '';

	const form = document.createElement( 'form' );

	form.className = 'checkout';

	fields.forEach( ( entry ) => {
		const row = document.createElement( 'p' );

		row.className = 'form-row';

		const input = document.createElement( 'input' );

		input.type = 'text';
		input.id = entry.id;
		input.value = entry.value || '';
		input.setAttribute( 'data-wccs-field', entry.id );

		row.appendChild( input );
		form.appendChild( row );
	} );

	document.body.appendChild( form );

	return form;
}

/**
 * Wires the three modules together the way the bundle does.
 *
 * @param {any} [options] Overrides.
 * @return {any} The wired pieces.
 */
function wired( options = {} ) {
	const rules = options.rules || {};
	const errors = createFieldErrors();
	const checker = createFieldChecker( {
		rules,
		remote: options.remote || null,
	} );
	const form = createFormValidation( { checker, errors } );

	return { errors, checker, form };
}

/**
 * Reads a field element back out of the page.
 *
 * Typed loosely: the assertions are about what the error surface did to the DOM,
 * and a query result is `Element | null` in a way that adds noise without adding
 * anything to what is being checked.
 *
 * @param {string} selector CSS selector.
 * @return {any} The element.
 */
function find( selector ) {
	return document.querySelector( selector );
}

/**
 * A rule as the server publishes it.
 *
 * @param {string} key     Validator key.
 * @param {string} code    Failure code.
 * @param {string} message Failure message.
 * @return {Object} Rule.
 */
function rule( key, code, message ) {
	return { key, code, message };
}

describe( 'the field error surface', () => {
	it( 'puts the message beside the field rather than in a list', () => {
		const form = buildForm( [ { id: 'wccs_cpf' } ] );
		const { errors } = wired();
		const input = form.querySelector( '#wccs_cpf' );

		errors.set( input, 'This CPF is not a valid number.' );

		const message = form.querySelector( '#wccs_cpf_wccs_error' );

		expect( message ).not.toBeNull();
		expect( message.textContent ).toBe( 'This CPF is not a valid number.' );
		expect( message.closest( '.form-row' ) ).toBe(
			input.closest( '.form-row' )
		);
	} );

	it( 'points the field at the message for a screen reader', () => {
		const form = buildForm( [ { id: 'wccs_cpf' } ] );
		const { errors } = wired();
		const input = form.querySelector( '#wccs_cpf' );

		errors.set( input, 'Wrong.' );

		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe(
			'wccs_cpf_wccs_error'
		);
	} );

	it( 'keeps a description another plugin already pointed at', () => {
		const form = buildForm( [ { id: 'wccs_cpf' } ] );
		const { errors } = wired();
		const input = form.querySelector( '#wccs_cpf' );

		input.setAttribute( 'aria-describedby', 'some-other-help' );

		errors.set( input, 'Wrong.' );

		expect( input.getAttribute( 'aria-describedby' ).split( ' ' ) ).toEqual(
			[ 'some-other-help', 'wccs_cpf_wccs_error' ]
		);

		errors.clear( input );

		expect( input.getAttribute( 'aria-describedby' ) ).toBe(
			'some-other-help'
		);
		expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );

	it( 'never erases what the customer typed', () => {
		const form = buildForm( [
			{ id: 'wccs_cpf', value: '529.982.247-24' },
		] );
		const { errors } = wired();
		const input = form.querySelector( '#wccs_cpf' );

		errors.set( input, 'Wrong.' );

		expect( input.value ).toBe( '529.982.247-24' );

		errors.clear( input );

		expect( input.value ).toBe( '529.982.247-24' );
	} );

	it( 'removes the message when the field is corrected', () => {
		const form = buildForm( [ { id: 'wccs_cpf' } ] );
		const { errors } = wired();
		const input = form.querySelector( '#wccs_cpf' );

		errors.set( input, 'Wrong.' );
		errors.clear( input );

		expect( form.querySelector( '#wccs_cpf_wccs_error' ) ).toBeNull();
		expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );

	it( 'summarises the errors as links to the fields', () => {
		const form = buildForm( [ { id: 'wccs_cpf' }, { id: 'wccs_cep' } ] );
		const { errors } = wired();

		errors.set( form.querySelector( '#wccs_cpf' ), 'Bad CPF.' );
		errors.set( form.querySelector( '#wccs_cep' ), 'Bad postcode.' );

		const summary = form.querySelector( '.wccs-error-summary' );

		expect( summary ).not.toBeNull();
		expect( summary.getAttribute( 'aria-live' ) ).toBe( 'polite' );

		const links = Array.from( summary.querySelectorAll( 'a' ) );

		expect( links.map( ( link ) => link.textContent ) ).toEqual( [
			'Bad CPF.',
			'Bad postcode.',
		] );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe( '#wccs_cpf' );
	} );

	it( 'removes the summary when there is nothing wrong', () => {
		const form = buildForm( [ { id: 'wccs_cpf' } ] );
		const { errors } = wired();

		errors.set( form.querySelector( '#wccs_cpf' ), 'Bad.' );
		errors.clear( form.querySelector( '#wccs_cpf' ) );

		expect( form.querySelector( '.wccs-error-summary' ) ).toBeNull();
	} );
} );

describe( 'checking the Suite fields', () => {
	it( 'accepts a value the local rule accepts', async () => {
		buildForm( [ { id: 'wccs_cpf', value: '52998224725' } ] );
		const { form, errors } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
			},
		} );

		await form.checkField( find( '#wccs_cpf' ) );

		expect( errors.invalid() ).toHaveLength( 0 );
	} );

	it( 'refuses a value the local rule refuses, with the server wording', async () => {
		buildForm( [ { id: 'wccs_cpf', value: '52998224724' } ] );
		const { form } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
			},
		} );

		await form.checkField( find( '#wccs_cpf' ) );

		expect( find( '#wccs_cpf_wccs_error' ).textContent ).toBe( 'Bad CPF.' );
	} );

	it( 'reports a rule it cannot check as a note, never as a pass', async () => {
		buildForm( [ { id: 'wccs_cpf', value: 'anything' } ] );
		const { form, errors } = wired( {
			rules: {
				wccs_cpf: [ rule( 'third.party', 'invalid_x', 'Bad.' ) ],
			},
		} );

		const allowed = await form.checkField( find( '#wccs_cpf' ) );

		// Told, and not blocked: the rule runs again on the server at submit,
		// which is the layer that owns it.
		expect( allowed ).toBe( true );
		expect( find( '#wccs_cpf' ).hasAttribute( 'aria-invalid' ) ).toBe(
			false
		);
		expect( find( '.wccs-field-note' ).textContent ).toContain(
			'checked when you place the order'
		);
		expect( errors.invalid() ).toHaveLength( 0 );
	} );

	it( 'asks the server for a rule it does not implement', async () => {
		buildForm( [ { id: 'wccs_cpf', value: 'anything' } ] );
		/** @type {any[]} */
		const asked = [];

		const { form } = wired( {
			rules: {
				wccs_cpf: [ rule( 'third.party', 'invalid_x', 'Bad.' ) ],
			},
			remote: {
				check: (
					/** @type {string} */ field,
					/** @type {any} */ value
				) => {
					asked.push( [ field, value ] );

					return Promise.resolve( {
						status: STATUS.INVALID,
						code: 'invalid_x',
						message: 'From the server.',
					} );
				},
			},
		} );

		const allowed = await form.checkField( find( '#wccs_cpf' ) );

		expect( asked ).toEqual( [ [ 'wccs_cpf', 'anything' ] ] );
		expect( allowed ).toBe( false );
		expect( find( '#wccs_cpf_wccs_error' ).textContent ).toBe(
			'From the server.'
		);
	} );
} );

describe( 'revalidating on submit', () => {
	it( 'blocks the order and focuses the first error', () => {
		const form = buildForm( [
			{ id: 'wccs_cpf', value: '52998224724' },
			{ id: 'wccs_cep', value: '0131010' },
		] );
		const { form: behaviour } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
				wccs_cep: [ rule( 'br.cep', 'invalid_postcode', 'Bad CEP.' ) ],
			},
		} );

		expect( behaviour.guard( form ) ).toBe( false );
		expect( document.activeElement ).toBe( find( '#wccs_cpf' ) );
	} );

	it( 'lets the order through when every field is fine', () => {
		const form = buildForm( [
			{ id: 'wccs_cpf', value: '52998224725' },
			{ id: 'wccs_cep', value: '01310100' },
		] );
		const { form: behaviour } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
				wccs_cep: [ rule( 'br.cep', 'invalid_postcode', 'Bad CEP.' ) ],
			},
		} );

		expect( behaviour.guard( form ) ).toBe( true );
		expect( document.querySelector( '.wccs-error-summary' ) ).toBeNull();
	} );

	it( 'decides again rather than remembering the last answer', () => {
		const form = buildForm( [ { id: 'wccs_cpf', value: '52998224724' } ] );
		const { form: behaviour } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
			},
		} );

		expect( behaviour.guard( form ) ).toBe( false );

		find( '#wccs_cpf' ).value = '52998224725';

		expect( behaviour.guard( form ) ).toBe( true );
		expect( find( '#wccs_cpf_wccs_error' ) ).toBeNull();
	} );

	it( 'is synchronous, because a promise is not a refusal', () => {
		// WooCommerce takes `false` to mean "abandon this order", and a promise is
		// always truthy: a guard that returned one would let every order through
		// while looking like it checked something.
		const form = buildForm( [ { id: 'wccs_cpf', value: '1' } ] );
		const { form: behaviour } = wired( {
			rules: {
				wccs_cpf: [ rule( 'br.cpf', 'invalid_cpf', 'Bad CPF.' ) ],
			},
		} );

		const answer = behaviour.guard( form );

		expect( typeof answer ).toBe( 'boolean' );
		expect( answer ).toBe( false );
	} );

	it( 'keeps a note from stopping the order', async () => {
		const form = buildForm( [ { id: 'wccs_cpf', value: 'x' } ] );
		const { form: behaviour } = wired( {
			rules: {
				wccs_cpf: [ rule( 'third.party', 'invalid_x', 'Bad.' ) ],
			},
		} );

		await behaviour.checkField( find( '#wccs_cpf' ) );

		expect( behaviour.guard( form ) ).toBe( true );
	} );
} );
