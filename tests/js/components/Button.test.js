/**
 * Button and icon button tests.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import Button from '../../../resources/admin/app/components/Button';
import IconButton from '../../../resources/admin/app/components/IconButton';

describe( 'Button', () => {
	it( 'is reachable and activatable by keyboard', async () => {
		const user = userEvent.setup();
		const onClick = jest.fn();

		render( <Button onClick={ onClick }>Save draft</Button> );

		await user.tab();

		const button = screen.getByRole( 'button', { name: 'Save draft' } );
		expect( button ).toHaveFocus();

		await user.keyboard( '{Enter}' );
		expect( onClick ).toHaveBeenCalledTimes( 1 );

		await user.keyboard( ' ' );
		expect( onClick ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'uses the native button element so it keeps form semantics', () => {
		render( <Button>Send</Button> );

		expect(
			screen.getByRole( 'button', { name: 'Send' } )
		).toHaveAttribute( 'type', 'button' );
	} );

	it( 'announces a busy state instead of disabling itself', () => {
		render( <Button busy>Saving</Button> );

		const button = screen.getByRole( 'button' );
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
		expect( button ).not.toBeDisabled();
	} );

	it( 'does not activate when disabled', async () => {
		const user = userEvent.setup();
		const onClick = jest.fn();

		render(
			<Button disabled onClick={ onClick }>
				Delete
			</Button>
		);

		await user.click( screen.getByRole( 'button' ) );

		expect( onClick ).not.toHaveBeenCalled();
	} );
} );

describe( 'IconButton', () => {
	it( 'exposes its accessible name', () => {
		render( <IconButton label="Move up" icon="↑" /> );

		expect(
			screen.getByRole( 'button', { name: 'Move up' } )
		).toBeInTheDocument();
	} );

	it( 'refuses to render without an accessible name', () => {
		// An icon alone is announced as an unlabelled button, which is how a
		// toolbar becomes unusable with a screen reader.
		const silence = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		// @ts-expect-error The label is deliberately omitted: refusing to render without an accessible name is the contract under test.
		expect( () => render( <IconButton icon="↑" /> ) ).toThrow(
			/requires a label/
		);

		silence.mockRestore();
	} );
} );
