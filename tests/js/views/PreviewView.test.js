/**
 * Preview screen tests.
 *
 * The prototype's preview is filled from a demo document it keeps in the browser.
 * This one is filled from the draft the editor holds, so what is under test is that
 * link: the fields that reach the checkout surface are the enabled fields of the
 * draft, in their sections, with the widths and the required marks they carry — and
 * that the screen never claims more than it does.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PreviewView from '../../../resources/admin/app/views/PreviewView';

/**
 * Builds a field definition.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'billing_document',
		integration_id: 'wc-checkoutsuite/billing_document',
		origin: 'custom',
		type: 'text',
		preset: null,
		label: 'CPF',
		description: '',
		section: 'billing',
		enabled: true,
		required: false,
		position: 10,
		layout: { desktop: 12, tablet: 12, mobile: 12 },
		settings: {},
		mask: null,
		normalizer: null,
		conditions: {},
		hidden_value_policy: 'discard',
		storage: { scope: 'order', sensitivity: 'personal' },
		visibility: { admin_order: true, public_api: false },
		...overrides,
	};
}

/**
 * Builds a document.
 *
 * @param {any[]} fields Fields.
 * @return {any} Document.
 */
function doc( fields = [] ) {
	return { revision: 3, fields, sections: [], settings: {} };
}

describe( 'the connected preview', () => {
	it( 'draws the enabled fields of the draft', () => {
		render(
			<PreviewView
				document={ doc( [
					field( { label: 'CPF', required: true } ),
					field( {
						id: 'billing_ie',
						label: 'IE',
						enabled: false,
					} ),
				] ) }
				siteName="Loja do Zé"
				onBack={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /CPF/ ) ).toBeInTheDocument();

		// A disabled field is not in the checkout, so it is not in the preview either:
		// showing it would preview a form the customer never sees.
		expect( screen.queryByLabelText( /IE/ ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( '1 campo(s) na prévia' )
		).toBeInTheDocument();
	} );

	it( 'carries the field width into the grid', () => {
		render(
			<PreviewView
				document={ doc( [
					field( { layout: { desktop: 6, tablet: 12, mobile: 12 } } ),
				] ) }
				onBack={ () => {} }
			/>
		);

		expect(
			screen.getByLabelText( /CPF/ ).closest( '.public-field' )
		).toHaveStyle( { gridColumn: 'span 6' } );
	} );

	it( 'names the store and says the preview is the draft', () => {
		render(
			<PreviewView
				document={ doc( [ field() ] ) }
				siteName="Loja do Zé"
				onBack={ () => {} }
			/>
		);

		expect( screen.getByText( 'Loja do Zé' ) ).toBeInTheDocument();

		// The design offers a published source as well. There is no route to read the
		// published slot from here, so the control is disabled and says why instead of
		// switching to a document that does not exist.
		expect(
			screen.getByRole( 'button', { name: 'Publicado' } )
		).toBeDisabled();
	} );

	it( 'reports the required fields that are empty, and only those', async () => {
		const user = userEvent.setup();

		render(
			<PreviewView
				document={ doc( [
					field( { label: 'CPF', required: true } ),
					field( {
						id: 'billing_ie',
						label: 'IE',
						required: false,
					} ),
				] ) }
				onBack={ () => {} }
			/>
		);

		await user.click(
			screen.getByRole( 'button', {
				name: /Validar campos da prévia/,
			} )
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Campos obrigatórios sem valor: CPF'
		);

		await user.type( screen.getByLabelText( /CPF/ ), '123' );
		await user.click(
			screen.getByRole( 'button', {
				name: /Validar campos da prévia/,
			} )
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Os campos obrigatórios da prévia estão preenchidos'
		);
	} );

	it( 'says there is nothing to preview when no field is enabled', () => {
		render(
			<PreviewView
				document={ doc( [ field( { enabled: false } ) ] ) }
				onBack={ () => {} }
			/>
		);

		expect(
			screen.getByText( 'Nada para pré-visualizar ainda.' )
		).toBeInTheDocument();
	} );

	it( 'never asks for card data', () => {
		render(
			<PreviewView document={ doc( [ field() ] ) } onBack={ () => {} } />
		);

		expect(
			screen.getByText( /A Suite não coleta cartão ou CVV/ )
		).toBeInTheDocument();

		// The layout is the design's; the rule is the product's. No input in the
		// preview may be a card field, however the mock is redrawn.
		expect(
			globalThis.document.querySelectorAll( 'input[name*="card"]' )
		).toHaveLength( 0 );
		expect(
			globalThis.document.querySelectorAll( 'input[name*="cvv"]' )
		).toHaveLength( 0 );
	} );
} );
