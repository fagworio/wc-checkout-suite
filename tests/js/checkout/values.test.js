/**
 * Value keeper tests.
 *
 */

import { createLifecycle } from '../../../resources/checkout/lifecycle';
import { createValueKeeper } from '../../../resources/checkout/values';

/**
 * Wires a keeper to a lifecycle the way the checkout entry does.
 *
 * @return {{lifecycle: any, keeper: any}} Lifecycle and keeper.
 */
function wired() {
	const keeper = createValueKeeper();
	const lifecycle = createLifecycle();

	lifecycle.register( 'suite-values', {
		selector: `[${ keeper.attribute }]`,
		start: ( element ) => keeper.restore( element ),
	} );

	return { lifecycle, keeper };
}

describe( 'keeping checkout values across a refresh', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

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
		input.value = options.value || '';
		input.checked = Boolean( options.checked );
		input.setAttribute( 'data-wccs-field', id );
		document.body.appendChild( input );

		return input;
	}

	/**
	 * Reads a marked field back out of the page.
	 *
	 * @param {string} id Field identifier.
	 * @return {any} The element.
	 */
	function readField( id ) {
		return document.querySelector( `[data-wccs-field="${ id }"]` );
	}

	it( 'restores a value into an element that replaced the one that held it', () => {
		const first = addField( 'wccs_cpf' );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		first.value = '123.456.789-09';
		keeper.capture( document.body );

		// The refresh replaced the field with an empty one, which is what a
		// fragment replacement does.
		first.replaceWith( addField( 'wccs_cpf' ) );
		const replacement = readField( 'wccs_cpf' );

		lifecycle.run( document.body );

		expect( replacement.value ).toBe( '123.456.789-09' );
	} );

	it( 'leaves an element that survived the refresh alone', () => {
		const field = addField( 'wccs_cpf' );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		field.value = 'typed by the customer';
		keeper.capture( document.body );

		// Same node, so nothing was lost and nothing is touched.
		lifecycle.run( document.body );

		expect( field.value ).toBe( 'typed by the customer' );
	} );

	it( 'does not overwrite a value the server rendered into the replacement', () => {
		const first = addField( 'wccs_cpf', { value: 'old' } );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );
		keeper.capture( document.body );

		first.replaceWith(
			addField( 'wccs_cpf', { value: 'from the session' } )
		);
		lifecycle.run( document.body );

		expect( readField( 'wccs_cpf' ).value ).toBe( 'from the session' );
	} );

	it( 'restores an unchecked box that came back unchecked', () => {
		const first = addField( 'wccs_consent', { type: 'checkbox' } );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		first.checked = true;
		keeper.capture( document.body );

		first.replaceWith( addField( 'wccs_consent', { type: 'checkbox' } ) );
		lifecycle.run( document.body );

		expect( readField( 'wccs_consent' ).checked ).toBe( true );
	} );

	it( 'keeps an unchecked box unchecked', () => {
		const first = addField( 'wccs_consent', { type: 'checkbox' } );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );
		keeper.capture( document.body );

		first.replaceWith( addField( 'wccs_consent', { type: 'checkbox' } ) );
		lifecycle.run( document.body );

		expect( readField( 'wccs_consent' ).checked ).toBe( false );
	} );

	it( 'restores the radio button that was chosen', () => {
		const group = document.createElement( 'div' );

		group.innerHTML = `
			<input type="radio" data-wccs-field="wccs_size" value="s" />
			<input type="radio" data-wccs-field="wccs_size" value="m" />
		`;
		document.body.appendChild( group );

		const radios = document.querySelectorAll(
			'[data-wccs-field="wccs_size"]'
		);
		const medium = /** @type {any} */ ( radios[ 1 ] );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		medium.checked = true;
		keeper.capture( document.body );

		group.innerHTML = `
			<input type="radio" data-wccs-field="wccs_size" value="s" />
			<input type="radio" data-wccs-field="wccs_size" value="m" />
		`;

		lifecycle.run( document.body );

		const fresh = /** @type {any} */ (
			document.querySelectorAll( '[data-wccs-field="wccs_size"]' )
		);

		// The old nodes really were replaced, so what follows is about the new
		// ones rather than about a group that never moved.
		expect( medium.isConnected ).toBe( false );
		expect( fresh[ 0 ].checked ).toBe( false );
		expect( fresh[ 1 ].checked ).toBe( true );
	} );

	it( 'does not announce the restore, so a refresh cannot loop', () => {
		const first = addField( 'wccs_cpf' );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		first.value = 'abc';
		keeper.capture( document.body );

		first.replaceWith( addField( 'wccs_cpf' ) );

		/** @type {string[]} */
		const events = [];
		/** @param {Event} event Event. */
		const record = ( event ) => events.push( event.type );

		document.body.addEventListener( 'change', record );
		document.body.addEventListener( 'input', record );

		lifecycle.run( document.body );

		document.body.removeEventListener( 'change', record );
		document.body.removeEventListener( 'input', record );

		expect( events ).toEqual( [] );
	} );

	it( 'records nothing when nothing is marked', () => {
		document.body.innerHTML =
			'<input type="text" name="billing_first_name" />';

		const { keeper } = wired();

		expect( keeper.capture( document.body ) ).toBe( 0 );
	} );

	it( 'restores nothing on a first page load', () => {
		addField( 'wccs_cpf' );

		const { lifecycle } = wired();

		expect( lifecycle.run( document.body ) ).toBe( 1 );
		expect( readField( 'wccs_cpf' ).value ).toBe( '' );
	} );

	it( 'holds the most recent answer, not the first one', () => {
		const first = addField( 'wccs_cpf' );
		const { lifecycle, keeper } = wired();

		lifecycle.run( document.body );

		first.value = 'first';
		keeper.capture( document.body );
		first.value = 'second';
		keeper.capture( document.body );

		first.replaceWith( addField( 'wccs_cpf' ) );
		lifecycle.run( document.body );

		expect( readField( 'wccs_cpf' ).value ).toBe( 'second' );
	} );
} );
