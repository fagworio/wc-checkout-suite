/**
 * Dialog tests.
 *
 * The modal API is shimmed in tests/js/setup.js because jsdom models the `open`
 * attribute but not `showModal()`. What is asserted here is the component's
 * contract with the platform: it asks to open when opened, closes when closed,
 * refuses to close silently on Escape, and names itself for assistive
 * technology. Focus containment is native and is verified in a browser in
 * WCCS-063.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import Dialog from '../../../resources/admin/app/components/Dialog';

describe( 'Dialog', () => {
	it( 'asks the platform to open when it becomes open', () => {
		render(
			<Dialog open title="Delete field" onClose={ () => {} }>
				Body
			</Dialog>
		);

		expect(
			screen.getByRole( 'dialog', { hidden: true } )
		).toHaveAttribute( 'open' );
	} );

	it( 'is closed while the open prop is false', () => {
		render(
			<Dialog open={ false } title="Delete field" onClose={ () => {} }>
				Body
			</Dialog>
		);

		expect( document.querySelector( 'dialog' ) ).not.toHaveAttribute(
			'open'
		);
	} );

	it( 'takes its accessible name from the visible title', () => {
		render(
			<Dialog open title="Delete field" onClose={ () => {} }>
				Body
			</Dialog>
		);

		expect(
			screen.getByRole( 'dialog', { name: 'Delete field' } )
		).toBeInTheDocument();
	} );

	it( 'offers a close control with an accessible name', async () => {
		const user = userEvent.setup();
		const onClose = jest.fn();

		render(
			<Dialog open title="Delete field" onClose={ onClose }>
				Body
			</Dialog>
		);

		await user.click( screen.getByRole( 'button', { name: 'Fechar' } ) );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks the caller before closing on Escape', () => {
		const onClose = jest.fn();

		render(
			<Dialog open title="Delete field" onClose={ onClose }>
				Body
			</Dialog>
		);

		const dialog = screen.getByRole( 'dialog', { hidden: true } );
		const cancel = new window.Event( 'cancel', {
			bubbles: true,
			cancelable: true,
		} );

		fireEvent( dialog, cancel );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
		// The component prevents the default so a caller can refuse to close,
		// for example while a save is still in flight.
		expect( cancel.defaultPrevented ).toBe( true );
	} );

	it( 'renders the footer actions when given', () => {
		render(
			<Dialog
				open
				title="Confirm"
				onClose={ () => {} }
				footer={ <button type="button">Confirm</button> }
			>
				Body
			</Dialog>
		);

		expect(
			screen.getByRole( 'button', { name: 'Confirm' } )
		).toBeInTheDocument();
	} );
} );
