/**
 * The panel that offers the store's own checkout.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.2: the screen
 * loads the real checkout and does not start empty. What is under test here is what the panel
 * says and what it offers — never that it invented a field list.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CoreCheckoutPanel from '../../../resources/admin/app/components/CoreCheckoutPanel';

/**
 * An inventory with two sections.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Inventory.
 */
function inventory( overrides = {} ) {
	const sections = [
		{
			key: 'billing',
			label: 'Cobrança',
			fields: [
				{
					id: 'billing_first_name',
					section: 'billing',
					label: 'Nome',
					type: 'text',
					nativeType: 'text',
					typeRemapped: false,
					required: true,
					priority: 10,
					classes: [],
					layout: { desktop: 6, tablet: 6, mobile: 12 },
					protected: true,
				},
				{
					id: 'billing_phone',
					section: 'billing',
					label: 'Telefone',
					type: 'text',
					nativeType: 'password',
					typeRemapped: true,
					required: false,
					priority: 20,
					classes: [],
					layout: { desktop: 6, tablet: 6, mobile: 12 },
					protected: true,
				},
			],
		},
	];

	return {
		available: true,
		reason: '',
		sections,
		fields: sections.flatMap( ( section ) => section.fields ),
		...overrides,
	};
}

/**
 * A document with the given fields.
 *
 * @param {any[]} fields Fields.
 * @return {any} Document.
 */
function doc( fields = [] ) {
	return { revision: 1, fields, sections: [], settings: {} };
}

/**
 * A definition of one native field.
 *
 * @param {string} id Identifier.
 * @return {any} Field.
 */
function definition( id ) {
	return { id, origin: 'core', type: 'text', label: id, section: 'billing' };
}

/**
 * Renders the panel.
 *
 * @param {Object} overrides Component properties to override.
 * @return {any} Rendered panel and the spies.
 */
function renderPanel( overrides = {} ) {
	const onAdoptField = jest.fn();
	const onHideField = jest.fn();
	const result = render(
		<CoreCheckoutPanel
			inventory={ inventory() }
			document={ doc() }
			onAdoptField={ onAdoptField }
			onHideField={ onHideField }
			{ ...overrides }
		/>
	);

	return { ...result, onAdoptField, onHideField };
}

describe( 'the fields the store already has', () => {
	it( 'lists the native fields of the store, as lines of the list', () => {
		renderPanel();

		const block = screen.getByRole( 'region', { name: 'Campos da loja' } );

		expect( within( block ).getByText( 'Nome' ) ).toBeInTheDocument();
		expect(
			within( block ).getByText( 'billing_first_name' )
		).toBeInTheDocument();
		expect( within( block ).getByText( 'Telefone' ) ).toBeInTheDocument();
		expect( within( block ).getAllByText( 'Nativo' ) ).toHaveLength( 2 );

		// Sem título, sem explicação e sem contador: o desenho mostra as linhas, e o
		// que se faz com um campo está na linha dele.
		expect(
			screen.queryByText( 'Checkout padrão' )
		).not.toBeInTheDocument();
		expect( screen.queryByText( /por adotar/ ) ).not.toBeInTheDocument();
	} );

	it( 'adopts one field when the merchant asks for it', async () => {
		const user = userEvent.setup();
		const { onAdoptField } = renderPanel();

		// The identifier is the contract with the merchant's screen and with the browser
		// observation: one button per native field, named by the field it adopts.
		const adopt = document.getElementById(
			'wccs-core-adopt-billing_first_name'
		);

		expect( adopt ).not.toBeNull();

		await user.click( /** @type {Element} */ ( adopt ) );

		expect( onAdoptField ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'billing_first_name' } )
		);
	} );

	it( 'offers to leave a native field out of the checkout', async () => {
		const user = userEvent.setup();
		const { onHideField } = renderPanel();

		const hide = document.getElementById(
			'wccs-core-hide-billing_first_name'
		);

		expect( hide ).not.toBeNull();

		await user.click( /** @type {Element} */ ( hide ) );

		expect( onHideField ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'billing_first_name' } )
		);
	} );

	it( 'marks what the document already manages, and offers nothing for it', () => {
		renderPanel( {
			document: doc( [ definition( 'billing_first_name' ) ] ),
		} );

		expect( screen.getByText( 'Gerenciado' ) ).toBeInTheDocument();
		expect(
			document.getElementById( 'wccs-core-adopt-billing_first_name' )
		).toBeNull();
		expect(
			document.getElementById( 'wccs-core-adopt-billing_phone' )
		).not.toBeNull();
	} );

	it( 'says at once when a native type had to be mapped', () => {
		renderPanel();

		// §6.7: a property that cannot be carried over is said now, not discovered later.
		expect(
			screen.getByText( 'tipo password gerenciado como text' )
		).toBeInTheDocument();
	} );

	it( 'says nothing when the store checkout could not be read', () => {
		renderPanel( {
			inventory: inventory( {
				available: false,
				reason: 'no WooCommerce',
			} ),
		} );

		expect(
			screen.queryByRole( 'region', { name: 'Campos da loja' } )
		).not.toBeInTheDocument();
	} );

	it( 'says nothing when everything is already managed', () => {
		renderPanel( {
			document: doc( [
				definition( 'billing_first_name' ),
				definition( 'billing_phone' ),
			] ),
		} );

		expect(
			screen.queryByRole( 'region', { name: 'Checkout padrão' } )
		).not.toBeInTheDocument();
	} );
} );
