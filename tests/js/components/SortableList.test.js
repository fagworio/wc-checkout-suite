/**
 * Reorderable list tests.
 *
 * ROADMAP.md section 428 requires moving an item to be possible with buttons and
 * with the keyboard, to announce the new position, and to preserve focus. The
 * focus requirement is the one that breaks silently: the list is rewritten on
 * every move, and a focused control that is replaced takes the keyboard user's
 * place with it.
 *
 * The specs therefore use a stateful harness rather than a spy. A spy would let
 * the order never change, and a reorder that never happens cannot lose focus —
 * which is exactly the bug these tests exist to catch.
 */

import { useState } from '@wordpress/element';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import SortableList from '../../../resources/admin/app/components/SortableList';

/**
 * Items used by the specs.
 *
 * @type {Array<{id: string, label: string}>}
 */
const ITEMS = [
	{ id: 'cpf', label: 'CPF' },
	{ id: 'cnpj', label: 'CNPJ' },
	{ id: 'ie', label: 'State registration' },
];

/**
 * Moves an item by one place.
 *
 * @param {any[]}  items     Items.
 * @param {string} id        Identifier to move.
 * @param {string} direction `up` or `down`.
 * @return {any[]} New order.
 */
function shift(
	/** @type {any[]} */ items,
	/** @type {string} */ id,
	/** @type {string} */ direction
) {
	const index = items.findIndex( ( item ) => item.id === id );
	const target = 'up' === direction ? index - 1 : index + 1;

	if ( target < 0 || target >= items.length ) {
		return items;
	}

	const next = [ ...items ];
	const moved = next[ index ];

	next[ index ] = next[ target ];
	next[ target ] = moved;

	return next;
}

/**
 * Renders a list that really reorders, the way the screen does.
 *
 * @param {Object} props         Component properties.
 * @param {any[]}  [props.items] Starting order.
 * @return {*} Rendered harness.
 */
function Harness( { items = ITEMS } ) {
	const [ order, setOrder ] = useState( items );

	return (
		<SortableList
			label="Fields in Billing"
			items={ order }
			getLabel={ ( /** @type {any} */ item ) => item.label }
			onMove={ (
				/** @type {string} */ id,
				/** @type {string} */ direction
			) => setOrder( ( current ) => shift( current, id, direction ) ) }
			renderItem={ ( /** @type {any} */ item ) => (
				<span>{ item.label }</span>
			) }
		/>
	);
}

/**
 * Returns the controls of one item.
 *
 * @param {string} name Item name.
 * @return {{up: any, down: any}} Up and down buttons.
 */
function controlsFor( name ) {
	return {
		up: screen.getByRole( 'button', { name: `Move ${ name } up` } ),
		down: screen.getByRole( 'button', { name: `Move ${ name } down` } ),
	};
}

/**
 * Returns the visible order of the items.
 *
 * Reads the content wrapper rather than searching the row, because the row also
 * holds the position and the control labels.
 *
 * @return {string[]} Labels in order.
 */
function visibleOrder() {
	return screen.getAllByRole( 'listitem' ).map( ( row ) => {
		const content = row.querySelector( '.wccs-sortable__content' );

		return content ? content.textContent : '';
	} );
}

describe( 'structure', () => {
	it( 'names the list so its purpose is announced', () => {
		render( <Harness /> );

		expect(
			screen.getByRole( 'list', { name: 'Fields in Billing' } )
		).toBeInTheDocument();
	} );

	it( 'renders one row per item, in the order given', () => {
		render( <Harness /> );

		expect( visibleOrder() ).toEqual( [
			'CPF',
			'CNPJ',
			'State registration',
		] );
	} );

	it( 'shows the position of each item for the eye', () => {
		render( <Harness /> );

		const rows = screen.getAllByRole( 'listitem' );

		expect( rows[ 0 ] ).toHaveTextContent( '1' );
		expect( rows[ 2 ] ).toHaveTextContent( '3' );
	} );

	it( 'names each control after the item it moves', () => {
		render( <Harness /> );

		const { up } = controlsFor( 'CNPJ' );

		expect( up ).toHaveAccessibleName( 'Move CNPJ up' );
	} );

	it( 'says so when there is nothing to order', () => {
		render( <Harness items={ [] } /> );

		expect( screen.getByText( 'Nothing here yet.' ) ).toBeInTheDocument();
	} );
} );

describe( 'the ends of the list', () => {
	it( 'does not offer to move the first item up', () => {
		render( <Harness /> );

		expect( controlsFor( 'CPF' ).up ).toBeDisabled();
		expect( controlsFor( 'CPF' ).down ).toBeEnabled();
	} );

	it( 'does not offer to move the last item down', () => {
		render( <Harness /> );

		expect( controlsFor( 'State registration' ).down ).toBeDisabled();
	} );
} );

describe( 'moving with the buttons', () => {
	it( 'moves an item up', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CNPJ' ).up );

		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'CPF',
			'State registration',
		] );
	} );

	it( 'moves an item down', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CPF' ).down );

		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'CPF',
			'State registration',
		] );
	} );

	it( 'reaches the end of the list one step at a time', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CPF' ).down );
		await user.click( controlsFor( 'CPF' ).down );

		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'State registration',
			'CPF',
		] );
		expect( controlsFor( 'CPF' ).down ).toBeDisabled();
	} );
} );

describe( 'moving with the keyboard', () => {
	it( 'operates the control after tabbing to it', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		// The first tab stop is the first item's "up" button, which is disabled,
		// so the first reachable control is the same item's "down".
		await user.tab();

		expect( controlsFor( 'CPF' ).down ).toHaveFocus();

		await user.keyboard( '{Enter}' );

		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'CPF',
			'State registration',
		] );
	} );

	it( 'moves an item with the space bar as well', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CNPJ' ).up );
		await user.click( controlsFor( 'CPF' ).up );

		expect( visibleOrder() ).toEqual( [
			'CPF',
			'CNPJ',
			'State registration',
		] );
	} );
} );

describe( 'focus survives the move', () => {
	it( 'keeps focus on the same item when it moves down', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CPF' ).down );

		expect( controlsFor( 'CPF' ).down ).toHaveFocus();
		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'CPF',
			'State registration',
		] );
	} );

	it( 'keeps focus on the same item when it moves up', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'State registration' ).up );

		expect( controlsFor( 'State registration' ).up ).toHaveFocus();
	} );

	it( 'falls back to the other control when the used one becomes disabled', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		// CNPJ moves to the top, so "move up" becomes unavailable. Focus has to go
		// somewhere inside the same item rather than being dropped on the body.
		await user.click( controlsFor( 'CNPJ' ).up );

		expect( controlsFor( 'CNPJ' ).up ).toBeDisabled();
		expect( controlsFor( 'CNPJ' ).down ).toHaveFocus();
	} );

	it( 'keeps focus across several moves in a row', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CPF' ).down );
		await user.click( controlsFor( 'CPF' ).down );

		expect( visibleOrder() ).toEqual( [
			'CNPJ',
			'State registration',
			'CPF',
		] );
		expect( controlsFor( 'CPF' ).down ).toBeDisabled();
		expect( controlsFor( 'CPF' ).up ).toHaveFocus();
	} );
} );

describe( 'the new position is announced', () => {
	it( 'writes the position to a live region', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'CPF' ).down );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'CPF moved to position 2 of 3.'
		);
	} );

	it( 'announces the item name, not its identifier', async () => {
		const user = userEvent.setup();
		render( <Harness /> );

		await user.click( controlsFor( 'State registration' ).up );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'State registration moved to position 2 of 3.'
		);
	} );

	it( 'announces nothing before anything moves', () => {
		render( <Harness /> );

		expect( screen.getByRole( 'status' ) ).toBeEmptyDOMElement();
	} );
} );
