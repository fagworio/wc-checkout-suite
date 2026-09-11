/**
 * Tabs tests.
 *
 * Covers the WAI-ARIA tabs pattern: one tab stop for the list, arrow keys that
 * move focus, Home and End, and a panel tied to its tab.
 */

import { useState } from '@wordpress/element';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import Tabs from '../../../resources/admin/app/components/Tabs';

/**
 * Tabs driven by local state, the way a real screen uses them.
 *
 * @param {Object} props      Component properties.
 * @param {*[]}    props.tabs Tab list.
 * @return {*} Rendered element tree.
 */
function Harness( { tabs } ) {
	const [ active, setActive ] = useState( tabs[ 0 ].id );

	return (
		<Tabs
			tabs={ tabs }
			active={ active }
			onSelect={ setActive }
			label="Field properties"
			renderPanel={ ( /** @type {string} */ id ) => <p>Panel { id }</p> }
		/>
	);
}

const TABS = [
	{ id: 'general', label: 'General' },
	{ id: 'validation', label: 'Validation' },
	{ id: 'display', label: 'Display' },
];

describe( 'Tabs', () => {
	it( 'is a single tab stop: only the selected tab is in the tab order', () => {
		render( <Harness tabs={ TABS } /> );

		expect(
			screen.getByRole( 'tab', { name: 'General' } )
		).toHaveAttribute( 'tabindex', '0' );
		expect(
			screen.getByRole( 'tab', { name: 'Validation' } )
		).toHaveAttribute( 'tabindex', '-1' );
		expect(
			screen.getByRole( 'tab', { name: 'Display' } )
		).toHaveAttribute( 'tabindex', '-1' );
	} );

	it( 'marks the selected tab with aria-selected', () => {
		render( <Harness tabs={ TABS } /> );

		expect(
			screen.getByRole( 'tab', { name: 'General' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen.getByRole( 'tab', { name: 'Validation' } )
		).toHaveAttribute( 'aria-selected', 'false' );
	} );

	it( 'moves selection and focus with the arrow keys', async () => {
		const user = userEvent.setup();

		render( <Harness tabs={ TABS } /> );

		screen.getByRole( 'tab', { name: 'General' } ).focus();

		await user.keyboard( '{ArrowRight}' );

		expect(
			screen.getByRole( 'tab', { name: 'Validation' } )
		).toHaveFocus();
		expect(
			screen.getByRole( 'tab', { name: 'Validation' } )
		).toHaveAttribute( 'aria-selected', 'true' );

		await user.keyboard( '{ArrowLeft}' );

		expect( screen.getByRole( 'tab', { name: 'General' } ) ).toHaveFocus();
	} );

	it( 'wraps around at both ends', async () => {
		const user = userEvent.setup();

		render( <Harness tabs={ TABS } /> );

		screen.getByRole( 'tab', { name: 'General' } ).focus();

		await user.keyboard( '{ArrowLeft}' );
		expect( screen.getByRole( 'tab', { name: 'Display' } ) ).toHaveFocus();

		await user.keyboard( '{ArrowRight}' );
		expect( screen.getByRole( 'tab', { name: 'General' } ) ).toHaveFocus();
	} );

	it( 'jumps to the first and last tab with Home and End', async () => {
		const user = userEvent.setup();

		render( <Harness tabs={ TABS } /> );

		screen.getByRole( 'tab', { name: 'General' } ).focus();

		await user.keyboard( '{End}' );
		expect( screen.getByRole( 'tab', { name: 'Display' } ) ).toHaveFocus();

		await user.keyboard( '{Home}' );
		expect( screen.getByRole( 'tab', { name: 'General' } ) ).toHaveFocus();
	} );

	it( 'ties the panel to the selected tab', () => {
		render( <Harness tabs={ TABS } /> );

		const panel = screen.getByRole( 'tabpanel' );
		const tab = screen.getByRole( 'tab', { name: 'General' } );

		expect( panel ).toHaveAttribute( 'aria-labelledby', tab.id );
		expect( tab ).toHaveAttribute( 'aria-controls', panel.id );
		expect( panel ).toHaveTextContent( 'Panel general' );
	} );
} );
