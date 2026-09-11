/**
 * Notice and badge tests.
 *
 * Both exist to make sure a state is never communicated by colour alone.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import Notice from '../../../resources/admin/app/components/Notice';
import {
	Badge,
	StatusBadge,
	CompatibilityBadge,
} from '../../../resources/admin/app/components/Badge';

describe( 'Notice', () => {
	it( 'announces an error immediately', () => {
		render( <Notice status="error">The document is invalid.</Notice> );

		const notice = screen.getByRole( 'alert' );
		expect( notice ).toHaveTextContent( 'The document is invalid.' );
	} );

	it( 'does not interrupt for information', () => {
		render( <Notice status="info">Nothing to configure yet.</Notice> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Nothing to configure yet.'
		);
	} );

	it( 'always shows a glyph alongside the wording', () => {
		const { container } = render(
			<Notice status="warning">Check the address.</Notice>
		);

		expect(
			container.querySelector( '.wccs-notice__glyph' )
		).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Check the address.'
		);
	} );

	it( 'dismisses through a control that names itself', async () => {
		const user = userEvent.setup();
		const onDismiss = jest.fn();

		render(
			<Notice status="info" onDismiss={ onDismiss }>
				Saved.
			</Notice>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Dismiss notice' } )
		);

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'omits the dismiss control when dismissing is not offered', () => {
		render( <Notice status="info">Saved.</Notice> );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'Badges', () => {
	it( 'states the status in words, not only in colour', () => {
		render( <StatusBadge status="published" /> );

		expect( screen.getByText( 'Published' ) ).toBeInTheDocument();
	} );

	it( 'falls back to the raw status instead of showing nothing', () => {
		render( <StatusBadge status="something-new" /> );

		expect( screen.getByText( 'something-new' ) ).toBeInTheDocument();
	} );

	it( 'states the compatibility level in words', () => {
		render( <CompatibilityBadge level="unsupported" /> );

		expect( screen.getByText( 'Unsupported' ) ).toBeInTheDocument();
	} );

	it( 'shows the reason next to the compatibility level', () => {
		render(
			<CompatibilityBadge
				level="limited"
				reason="Width depends on the container."
			/>
		);

		expect( screen.getByText( 'Limited' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Width depends on the container.' )
		).toBeInTheDocument();
	} );

	it( 'renders a plain badge', () => {
		render( <Badge tone="brand">Custom</Badge> );

		expect( screen.getByText( 'Custom' ) ).toHaveClass(
			'wccs-badge--brand'
		);
	} );
} );
