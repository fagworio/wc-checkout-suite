/**
 * Unsaved work guard tests.
 */

import { useState } from '@wordpress/element';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import useUnsavedChanges from '../../../resources/admin/app/api/useUnsavedChanges';

/**
 * Component that uses the guard and can toggle the dirty state.
 */
function Harness() {
	const [ dirty, setDirty ] = useState( true );

	useUnsavedChanges( dirty, 'You have unsaved changes.' );

	return (
		<button type="button" onClick={ () => setDirty( false ) }>
			Mark saved
		</button>
	);
}

/**
 * Dispatches a cancelable beforeunload event.
 *
 * @return {Event} The dispatched event.
 */
function fireBeforeUnload() {
	const event = new window.Event( 'beforeunload', { cancelable: true } );

	window.dispatchEvent( event );

	return event;
}

describe( 'useUnsavedChanges', () => {
	it( 'asks the browser to confirm while there is unsaved work', () => {
		render( <Harness /> );

		expect( fireBeforeUnload().defaultPrevented ).toBe( true );
	} );

	it( 'stops guarding once the work is saved', async () => {
		const user = userEvent.setup();

		render( <Harness /> );

		await user.click(
			screen.getByRole( 'button', { name: 'Mark saved' } )
		);

		expect( fireBeforeUnload().defaultPrevented ).toBe( false );
	} );

	it( 'removes its listener when it unmounts', () => {
		const remove = jest.spyOn( window, 'removeEventListener' );

		const { unmount } = render( <Harness /> );

		unmount();

		expect( remove ).toHaveBeenCalledWith(
			'beforeunload',
			expect.any( Function )
		);

		remove.mockRestore();
	} );
} );
