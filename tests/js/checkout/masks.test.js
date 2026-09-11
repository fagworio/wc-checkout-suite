/**
 * Mask application tests.
 */

import { createLifecycle } from '../../../resources/checkout/lifecycle';
import { createMaskedFields } from '../../../resources/checkout/masks';
import { createValueKeeper } from '../../../resources/checkout/values';

/**
 * The masks the server would print for a checkout.
 *
 * @return {Record<string, any>} Mask entries by field identifier.
 */
function bootstrap() {
	return {
		wccs_cpf: {
			key: 'br.cpf',
			version: 1,
			definition: '000.000.000-00',
		},
		wccs_cnpj: {
			key: 'br.cnpj',
			version: 1,
			definition: '**.***.***/****-00',
		},
		wccs_phone: {
			key: 'br.phone.mobile',
			version: 1,
			definition: '(00) 00000-0000',
		},
		wccs_numeric: {
			key: 'numeric',
			version: 1,
			definition: { type: 'pattern', pattern: '0', lazy: true },
		},
		wccs_odd: {
			key: 'third.party',
			version: 1,
			definition: { type: 'number', min: 1, max: 10 },
		},
	};
}

/**
 * Adds a marked field to the page.
 *
 * @param {string} id      Field identifier.
 * @param {any}    options Element options.
 * @return {any} The input.
 */
function addField( id, options = {} ) {
	const input = document.createElement( 'input' );

	input.type = options.type || 'text';
	input.setAttribute( 'data-wccs-field', id );
	document.body.appendChild( input );

	return input;
}

/**
 * Wires the keeper and the masks to a lifecycle the way the checkout does.
 *
 * @param {Record<string, any>} definitions Masks.
 * @return {{lifecycle: any, keeper: any, masked: any}} Wired components.
 */
function wired( definitions = bootstrap() ) {
	const keeper = createValueKeeper();
	const masked = createMaskedFields( definitions, keeper.attribute );
	const lifecycle = createLifecycle();
	const selector = `[${ keeper.attribute }]`;

	// Registration order matters and is what the entry point relies on: the value
	// is restored before the mask is built over it.
	lifecycle.register( 'suite-values', {
		selector,
		start: ( element ) => keeper.restore( element ),
	} );
	lifecycle.register( 'suite-masks', {
		selector,
		start: ( element ) => masked.apply( element ),
	} );

	return { lifecycle, keeper, masked };
}

describe( 'applying the checkout masks', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'formats a value typed into a masked field', () => {
		const cpf = addField( 'wccs_cpf' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		cpf.value = '12345678909';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.value ).toBe( '123.456.789-09' );
	} );

	it( 'leaves the cursor at the end after formatting', () => {
		const cpf = addField( 'wccs_cpf' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		cpf.value = '12345678909';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.selectionStart ).toBe( '123.456.789-09'.length );
		expect( cpf.selectionEnd ).toBe( '123.456.789-09'.length );
	} );

	it( 'accepts a pasted value as one input event', () => {
		const cpf = addField( 'wccs_cpf' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		// A paste reaches the field as a value change followed by an input event,
		// which is what this dispatches.
		cpf.value = '123.456.789-09';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.value ).toBe( '123.456.789-09' );

		cpf.value = '123.456.789-0912';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.value ).toBe( '123.456.789-09' );
	} );

	it( 'accepts a value filled in by autocomplete', () => {
		const cnpj = addField( 'wccs_cnpj' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		// Autofill sets the value and reports it as input, which is what the mask
		// listens to.
		cnpj.value = '12ABC34501DE35';
		cnpj.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cnpj.value ).toBe( '12.ABC.345/01DE-35' );
	} );

	it( 'does not lose a value that arrives without an input event', () => {
		const cnpj = addField( 'wccs_cnpj' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		// A browser that reported autofill as a change alone would leave the value
		// unformatted. It is not lost, and the canonical value never depended on
		// the mask: the server normalizes whatever arrives, formatted or not.
		cnpj.value = '12ABC34501DE35';
		cnpj.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( cnpj.value ).toBe( '12ABC34501DE35' );
	} );

	it( 'keeps the letters of an alphanumeric document', () => {
		const cnpj = addField( 'wccs_cnpj' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		cnpj.value = '12ABC34501DE35';
		cnpj.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cnpj.value ).toContain( 'ABC' );
		expect( cnpj.value ).toContain( 'DE' );
	} );

	it( 'applies the configuration form the generic masks use', () => {
		const numeric = addField( 'wccs_numeric' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		numeric.value = 'a1b2';
		numeric.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		// The generic `numeric` mask is a single digit position, so one digit
		// survives and the letters are refused. What the assertion proves is that
		// the configuration was translated at all: had it not been, the field
		// would be unmasked and the value would still read `a1b2`.
		expect( numeric.value ).toBe( '1' );
	} );

	it( 'asks for a numeric keyboard on a digits-only mask', () => {
		const cpf = addField( 'wccs_cpf' );
		const phone = addField( 'wccs_phone' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		expect( cpf.getAttribute( 'inputmode' ) ).toBe( 'numeric' );
		expect( phone.getAttribute( 'inputmode' ) ).toBe( 'numeric' );
	} );

	it( 'does not ask for one where the document can contain letters', () => {
		// A numeric keypad on an alphanumeric CNPJ makes the document impossible
		// to type, which is how a phone would break the value rather than the
		// mask breaking it.
		const cnpj = addField( 'wccs_cnpj' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		expect( cnpj.hasAttribute( 'inputmode' ) ).toBe( false );

		cnpj.value = '12ABC34501DE35';
		cnpj.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cnpj.value ).toBe( '12.ABC.345/01DE-35' );
	} );

	it( 'leaves a field with no mask alone', () => {
		const plain = addField( 'wccs_notes' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		plain.value = 'anything at all';
		plain.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( plain.value ).toBe( 'anything at all' );
	} );

	it( 'leaves a field unmasked when it cannot apply the mask, and says why', () => {
		// A third-party mask type the browser does not understand. Applying it as
		// the wrong thing would format the field with a pattern nobody can type
		// back, so the field is left alone and the reason is visible.
		const warn = jest
			.spyOn( console, 'warn' )
			.mockImplementation( () => {} );
		const odd = addField( 'wccs_odd' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		odd.value = '12';
		odd.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( odd.value ).toBe( '12' );
		expect( warn ).toHaveBeenCalledTimes( 1 );
		expect( warn.mock.calls[ 0 ][ 0 ] ).toContain( 'third.party' );

		warn.mockRestore();
	} );

	it( 'masks an element that replaced one, and formats the restored value', () => {
		const first = addField( 'wccs_cpf' );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		first.value = '12345678909';
		first.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( first.value ).toBe( '123.456.789-09' );

		keeper.capture( document.body );

		// The refresh replaces the field with an empty one.
		const replacement = addField( 'wccs_cpf' );
		first.replaceWith( replacement );

		lifecycle.run( document.body );

		expect( replacement.value ).toBe( '123.456.789-09' );
	} );

	it( 'does not mask a surviving element twice', () => {
		const cpf = addField( 'wccs_cpf' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		cpf.value = '12345678909';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.value ).toBe( '123.456.789-09' );

		// The cursor is put in the middle, which is where a person editing a
		// document would leave it.
		cpf.setSelectionRange( 4, 4 );

		// A refresh that replaced nothing of ours.
		lifecycle.run( document.body );

		expect( cpf.selectionStart ).toBe( 4 );
		expect( cpf.value ).toBe( '123.456.789-09' );
	} );

	it( 'lets the customer delete a character and reformats the rest', () => {
		const cpf = addField( 'wccs_cpf' );
		const { lifecycle } = wired();

		lifecycle.run( document.body );

		cpf.value = '12345678909';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		// Deleting the last digit, which is what a backspace leaves behind.
		cpf.value = '1234567890';
		cpf.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( cpf.value ).toBe( '123.456.789-0' );
	} );
} );
