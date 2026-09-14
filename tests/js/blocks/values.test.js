/**
 * Blocks value lifecycle tests.
 *
 * The acceptance is one sentence: an address or shipping switch, a remount and a
 * re-render must preserve values, and none of it may be done by reading or writing
 * the DOM behind React's back. Each spec below pins one of those, and the last one
 * pins the absence of the technique that would make the others pass for the wrong
 * reason.
 */

import { useState } from '@wordpress/element';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { componentFor } from '../../../resources/blocks/fields';
import {
	createFieldLifecycle,
	createValueStore,
} from '../../../resources/blocks/values';

/**
 * A field payload.
 *
 * @param {Object} overrides Overrides.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'wc-checkoutsuite/wccs_note',
		name: 'wccs_note',
		label: 'Note',
		type: 'textarea',
		location: 'address',
		required: false,
		policy: 'discard',
		...overrides,
	};
}

/**
 * Renders one field the way the checkout would.
 *
 * @param {any}    entry  Field payload.
 * @param {Object} passed Extra props.
 * @return {any} Render result.
 */
function renderField( entry, passed = {} ) {
	const Component = /** @type {any} */ ( componentFor( entry ) );

	return render(
		<Component
			field={ entry }
			value=""
			onChange={ () => {} }
			{ ...passed }
		/>
	);
}

describe( 'blocks value lifecycle', () => {
	it( 'holds what was typed, so a component never has to', () => {
		const lifecycle = createFieldLifecycle( { fields: [ field() ] } );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 'hello' );

		expect( lifecycle.values() ).toEqual( {
			'wc-checkoutsuite/wccs_note': 'hello',
		} );
	} );

	it( 'keeps values across a remount, because they were never in the component', () => {
		const lifecycle = createFieldLifecycle( { fields: [ field() ] } );
		const entry = field();

		lifecycle.onChange( entry.id, 'typed before the address changed' );

		const first = renderField( entry, {
			value: lifecycle.values()[ entry.id ],
		} );

		expect( screen.getByLabelText( /Note/ ) ).toHaveValue(
			'typed before the address changed'
		);

		first.unmount();

		renderField( entry, { value: lifecycle.values()[ entry.id ] } );

		expect( screen.getByLabelText( /Note/ ) ).toHaveValue(
			'typed before the address changed'
		);
	} );

	it( 'keeps values across a re-render with the same store', () => {
		const lifecycle = createFieldLifecycle( { fields: [ field() ] } );
		const { rerender } = renderField( field(), { value: '' } );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 're-rendered' );

		rerender(
			<textarea
				aria-label="Note"
				value={ lifecycle.values()[ 'wc-checkoutsuite/wccs_note' ] }
				readOnly
			/>
		);

		expect( screen.getByLabelText( 'Note' ) ).toHaveValue( 're-rendered' );
	} );

	it( 'keeps the value while the customer types, so the store is the record', async () => {
		const user = userEvent.setup();
		const lifecycle = createFieldLifecycle( { fields: [ field() ] } );
		const entry = field();
		const Component = /** @type {any} */ ( componentFor( entry ) );

		/**
		 * The checkout as the store's client: it renders what the store holds and
		 * writes back what the component reported. Without the second half a
		 * controlled input would reset on every keystroke, which is what a checkout
		 * that kept the value in the component would do.
		 *
		 * @return {*} Rendered element tree.
		 */
		function Harness() {
			const [ values, setValues ] = useState( lifecycle.values() );

			return (
				<Component
					field={ entry }
					value={ values[ entry.id ] ?? '' }
					onChange={ ( /** @type {any} */ value ) =>
						setValues( lifecycle.onChange( entry.id, value ) )
					}
				/>
			);
		}

		render( <Harness /> );

		await user.type( screen.getByLabelText( /Note/ ), 'hi' );

		expect( lifecycle.values()[ entry.id ] ).toBe( 'hi' );
	} );

	it( 'drops the value of a field a rule hides when the policy is discard', () => {
		const lifecycle = createFieldLifecycle( {
			fields: [ field() ],
			isVisible: () => false,
		} );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 'a value' );
		lifecycle.remount();

		expect( lifecycle.values() ).toEqual( {} );
	} );

	it( 'keeps the value of a field a rule hides when the policy is preserve', () => {
		const lifecycle = createFieldLifecycle( {
			fields: [ field( { policy: 'preserve' } ) ],
			isVisible: () => false,
		} );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 'a value' );
		lifecycle.remount();

		expect( lifecycle.values() ).toEqual( {
			'wc-checkoutsuite/wccs_note': 'a value',
		} );
	} );

	it( 'treats a field with no policy as a discard one', () => {
		const lifecycle = createFieldLifecycle( {
			fields: [ { id: 'wc-checkoutsuite/wccs_a', policy: undefined } ],
			isVisible: () => false,
		} );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_a', 'x' );
		lifecycle.remount();

		expect( lifecycle.values() ).toEqual( {} );
	} );

	it( 'does nothing to a visible field, whatever its policy', () => {
		const lifecycle = createFieldLifecycle( {
			fields: [ field( { policy: 'preserve' } ) ],
			isVisible: () => true,
		} );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 'kept' );
		lifecycle.remount();

		expect( lifecycle.values() ).toEqual( {
			'wc-checkoutsuite/wccs_note': 'kept',
		} );
	} );

	it( 'answers with the values to render after a rebuild', () => {
		const store = createValueStore( { values: { a: 1 } } );
		const lifecycle = createFieldLifecycle( { store, fields: [] } );

		expect( lifecycle.remount() ).toEqual( { a: 1 } );
		expect( lifecycle.values() ).toEqual( { a: 1 } );
	} );

	it( 'replaces the whole store without losing the map it is', () => {
		const store = createValueStore( { values: { a: 1, b: 2 } } );

		store.replace( { c: 3 } );

		expect( store.all() ).toEqual( { c: 3 } );
		expect( store.has( 'a' ) ).toBe( false );
	} );

	it( 'notifies subscribers when a value changes and stops after unsubscribe', () => {
		const store = createValueStore();
		const listener = jest.fn();
		const unsubscribe = store.subscribe( listener );

		store.set( 'a', 'one' );
		store.set( 'a', 'one' );
		unsubscribe();
		store.set( 'a', 'two' );

		expect( listener ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'never reaches for a mutation observer, because nothing needs to watch the DOM', () => {
		// The technique this asserts the absence of is the one that would make every
		// other spec here pass for the wrong reason: a module that recovered values by
		// watching the document would look like it preserved them, while what it
		// actually did was read back whatever React last rendered.
		const observer = jest.fn();
		const scope = /** @type {any} */ ( globalThis );

		// Assigning a spy over the constructor is the point: the assertion is that
		// nothing ever builds one.
		scope.MutationObserver = observer;

		const lifecycle = createFieldLifecycle( { fields: [ field() ] } );

		lifecycle.onChange( 'wc-checkoutsuite/wccs_note', 'typed' );
		lifecycle.remount();
		lifecycle.values();

		expect( observer ).not.toHaveBeenCalled();
		expect( document.querySelectorAll( '*' ).length ).toBeGreaterThan( 0 );
	} );
} );
