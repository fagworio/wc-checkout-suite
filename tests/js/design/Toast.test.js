/**
 * Toast tests.
 *
 * The design's toast is two nodes with one message: the visible toast and the
 * screen-reader live region. What is under test is that they agree, that the
 * message survives long enough to be read, and that a second announcement
 * restarts the countdown instead of inheriting the first one's remaining time.
 */

import { act, render, screen } from '@testing-library/react';

import {
	Toast,
	TOAST_DISMISS_MS,
} from '../../../resources/admin/app/design/Toast';

describe( 'the design toast', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders nothing before there is anything to say', () => {
		render( <Toast /> );

		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
	} );

	it( 'announces the message in the toast and in the live region', () => {
		render( <Toast message="Rascunho salvo." token={ 1 } /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Rascunho salvo.'
		);
		expect(
			globalThis.document.querySelector( '.sr-only[aria-live="polite"]' )
				?.textContent
		).toBe( 'Rascunho salvo.' );
	} );

	it( 'hides the toast after the design keeps it on screen', () => {
		render( <Toast message="Rascunho salvo." token={ 1 } /> );

		act( () => {
			jest.advanceTimersByTime( TOAST_DISMISS_MS - 1 );
		} );

		expect( screen.getByRole( 'status' ) ).toBeInTheDocument();

		act( () => {
			jest.advanceTimersByTime( 1 );
		} );

		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the live region after the toast goes away', () => {
		render( <Toast message="Rascunho salvo." token={ 1 } /> );

		act( () => {
			jest.advanceTimersByTime( TOAST_DISMISS_MS );
		} );

		// A live region that empties itself when the toast fades would take the
		// message away from anyone who had not read it yet.
		expect(
			globalThis.document.querySelector( '.sr-only[aria-live="polite"]' )
				?.textContent
		).toBe( 'Rascunho salvo.' );
	} );

	it( 'restarts the countdown when the same message is announced again', () => {
		const { rerender } = render(
			<Toast message="Rascunho salvo." token={ 1 } />
		);

		act( () => {
			jest.advanceTimersByTime( TOAST_DISMISS_MS - 1000 );
		} );

		// Same words, new announcement: only the token changes, which is exactly the
		// case a message-only dependency would get wrong.
		rerender( <Toast message="Rascunho salvo." token={ 2 } /> );

		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Rascunho salvo.'
		);
	} );
} );
