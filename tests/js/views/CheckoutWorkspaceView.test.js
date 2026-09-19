/**
 * Presentation boundary for the checkout workspace.
 *
 * The checkout document and its operations remain owned by FieldsScreen. This
 * suite only freezes the visual contract introduced by UI-002.
 */

import { render, screen } from '@testing-library/react';

import CheckoutWorkspaceView from '../../../resources/admin/app/views/CheckoutWorkspaceView';

describe( 'CheckoutWorkspaceView', () => {
	it( 'shows the active checkout name and type without replacing its content', () => {
		render(
			<CheckoutWorkspaceView
				checkoutName="Checkout digital"
				checkoutKind="Checkout alternativo"
			>
				<div data-testid="workspace-content">builder</div>
			</CheckoutWorkspaceView>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Checkout digital' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Checkout alternativo' )
		).toBeInTheDocument();
		expect( screen.getByTestId( 'workspace-content' ) ).toHaveTextContent(
			'builder'
		);
	} );

	it( 'marks the workspace heading for the active checkout', () => {
		render(
			<CheckoutWorkspaceView
				checkoutName="Checkout padrão"
				checkoutKind="Checkout padrão"
			>
				<div />
			</CheckoutWorkspaceView>
		);

		expect(
			screen.getByRole( 'region', { name: 'Checkout padrão' } )
		).toHaveClass( 'wccs-checkout-workspace-view' );
	} );
} );
