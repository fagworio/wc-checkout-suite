/**
 * Lifecycle tests.
 *
 */

import { createLifecycle } from '../../../resources/checkout/lifecycle';

/**
 * Builds a lifecycle with one component that counts what it was started on.
 *
 * @param {string} selector Selector the component starts on.
 * @return {{lifecycle: any, started: Element[]}} Lifecycle and the elements it saw.
 */
function countingComponent( selector = '[data-wccs-field]' ) {
	/** @type {Element[]} */
	const started = [];
	const lifecycle = createLifecycle();

	lifecycle.register( 'counter', {
		selector,
		start: ( element ) => started.push( element ),
	} );

	return { lifecycle, started };
}

describe( 'the classic checkout component lifecycle', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	/**
	 * Adds a marked field to the page.
	 *
	 * @param {string} id Field identifier.
	 * @return {any} The input.
	 */
	function addField( id ) {
		const input = document.createElement( 'input' );

		input.type = 'text';
		input.setAttribute( 'data-wccs-field', id );
		document.body.appendChild( input );

		return input;
	}

	it( 'starts a component on every matching element', () => {
		addField( 'wccs_one' );
		addField( 'wccs_two' );

		const { lifecycle, started } = countingComponent();

		expect( lifecycle.run( document.body ) ).toBe( 2 );
		expect( started ).toHaveLength( 2 );
	} );

	it( 'never starts the same element twice', () => {
		addField( 'wccs_one' );

		const { lifecycle, started } = countingComponent();

		lifecycle.run( document.body );

		// Two passes with the DOM untouched, which is what a refresh that
		// replaced nothing of ours looks like.
		expect( lifecycle.run( document.body ) ).toBe( 0 );
		expect( lifecycle.run( document.body ) ).toBe( 0 );
		expect( started ).toHaveLength( 1 );
	} );

	it( 'starts an element that replaced another one', () => {
		const first = addField( 'wccs_one' );

		const { lifecycle, started } = countingComponent();

		lifecycle.run( document.body );

		first.replaceWith( addField( 'wccs_one' ) );

		expect( lifecycle.run( document.body ) ).toBe( 1 );
		expect( started ).toHaveLength( 2 );
		expect( started[ 1 ] ).not.toBe( first );
	} );

	it( 'starts only the elements that are new', () => {
		addField( 'wccs_kept' );

		const { lifecycle, started } = countingComponent();

		lifecycle.run( document.body );

		// A refresh replaced one of two fields and added a third.
		addField( 'wccs_new' );

		expect( lifecycle.run( document.body ) ).toBe( 1 );
		expect( started[ 1 ].getAttribute( 'data-wccs-field' ) ).toBe(
			'wccs_new'
		);
	} );

	it( 'tracks two components on the same element separately', () => {
		addField( 'wccs_one' );

		/** @type {Element[]} */
		const startedA = [];
		/** @type {Element[]} */
		const startedB = [];
		const lifecycle = createLifecycle();

		lifecycle.register( 'a', {
			selector: '[data-wccs-field]',
			start: ( element ) => startedA.push( element ),
		} );
		lifecycle.register( 'b', {
			selector: '[data-wccs-field]',
			start: ( element ) => startedB.push( element ),
		} );

		expect( lifecycle.run( document.body ) ).toBe( 2 );
		expect( startedA ).toHaveLength( 1 );
		expect( startedB ).toHaveLength( 1 );

		expect( lifecycle.run( document.body ) ).toBe( 0 );
	} );

	it( 'tracks elements by node, not by identifier', () => {
		const first = addField( 'wccs_same' );
		const second = addField( 'wccs_same' );

		const { lifecycle } = countingComponent();

		expect( lifecycle.run( document.body ) ).toBe( 2 );

		first.replaceWith( addField( 'wccs_same' ) );

		expect( lifecycle.run( document.body ) ).toBe( 1 );
		expect( second.isConnected ).toBe( true );
	} );

	it( 'searches only inside the element it is given', () => {
		addField( 'wccs_outside' );

		const scope = document.createElement( 'div' );
		const inside = document.createElement( 'input' );

		inside.setAttribute( 'data-wccs-field', 'wccs_inside' );
		scope.appendChild( inside );
		document.body.appendChild( scope );

		const { lifecycle, started } = countingComponent();

		expect( lifecycle.run( scope ) ).toBe( 1 );
		expect( started[ 0 ] ).toBe( inside );
	} );

	it( 'reports a component that throws without taking the others down', () => {
		addField( 'wccs_one' );
		addField( 'wccs_two' );

		const error = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		/** @type {Element[]} */
		const seen = [];
		const lifecycle = createLifecycle();

		lifecycle.register( 'broken', {
			selector: '[data-wccs-field]',
			start: () => {
				throw new Error( 'component failure' );
			},
		} );
		lifecycle.register( 'working', {
			selector: '[data-wccs-field]',
			start: ( element ) => seen.push( element ),
		} );

		expect( lifecycle.run( document.body ) ).toBe( 4 );
		expect( seen ).toHaveLength( 2 );
		expect( error ).toHaveBeenCalledTimes( 2 );
		expect( error.mock.calls[ 0 ][ 0 ] ).toContain( 'broken' );

		error.mockRestore();
	} );

	it( 'does not start a component again after it threw', () => {
		addField( 'wccs_one' );

		const error = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		let attempts = 0;
		const lifecycle = createLifecycle();

		lifecycle.register( 'broken', {
			selector: '[data-wccs-field]',
			start: () => {
				attempts += 1;
				throw new Error( 'component failure' );
			},
		} );

		lifecycle.run( document.body );

		expect( lifecycle.run( document.body ) ).toBe( 0 );
		expect( attempts ).toBe( 1 );

		error.mockRestore();
	} );

	it( 'refuses a component without a name, a selector or a start', () => {
		const lifecycle = createLifecycle();

		// The three shapes are invalid at runtime, which is the point of the
		// test, so each is passed through a cast that says so.
		expect( () =>
			lifecycle.register(
				'',
				/** @type {any} */ ( { selector: 'input', start: () => {} } )
			)
		).toThrow( TypeError );
		expect( () =>
			lifecycle.register(
				'x',
				/** @type {any} */ ( { start: () => {} } )
			)
		).toThrow( TypeError );
		expect( () =>
			lifecycle.register(
				'x',
				/** @type {any} */ ( { selector: 'input' } )
			)
		).toThrow( TypeError );
	} );

	it( 'answers zero when it is given nothing to search', () => {
		const { lifecycle } = countingComponent();

		expect( lifecycle.run( null ) ).toBe( 0 );
	} );
} );
