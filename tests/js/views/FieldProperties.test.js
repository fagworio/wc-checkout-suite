/**
 * Field properties tests.
 *
 * This is the inspector the design draws, and until now it had no suite of its own:
 * the tests that covered an inspector belonged to the panel the design replaced. What
 * is under test here is what this one decides — which tabs it offers, that a mask is
 * offered only where the type declares it can take one, that the width buttons report
 * the grid they stand for, and that a field WooCommerce owns is not offered for
 * archiving but can explain itself instead.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FieldProperties from '../../../resources/admin/app/views/FieldProperties';

/**
 * Builds a catalog with one type.
 *
 * @param {Object} supports What the type declares it supports.
 * @return {any} Catalog.
 */
function catalog( supports = { value: true, maskable: true } ) {
	return {
		types: {
			text: {
				key: 'text',
				label: 'Text',
				category: 'text',
				source: 'core',
				contractVersion: '1.0',
				supports,
				valueSchema: { type: 'string' },
				settingsSchema: {},
			},
		},
		masks: [
			{ key: 'br.cpf', label: 'CPF', version: 1 },
			{ key: 'br.cep', label: 'CEP', version: 1 },
		],
		vocabulary: {
			storageScopes: [ { value: 'order', label: 'Order' } ],
			storageSensitivities: [ { value: 'personal', label: 'Personal' } ],
			visibilityKeys: [ { value: 'admin_order', label: 'Admin order' } ],
			hiddenValuePolicies: [
				{ value: 'discard', label: 'Discard' },
				{ value: 'keep', label: 'Keep' },
			],
		},
		conditions: { sources: [], operators: [] },
		sectionLocations: [ { value: 'billing', label: 'Cobrança' } ],
	};
}

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
		layout: { desktop: 6, tablet: 12, mobile: 12 },
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
 * Renders the inspector.
 *
 * @param {Object} overrides Component properties to override.
 * @return {any} Render result and the spies.
 */
function renderInspector( overrides = {} ) {
	const onChange = jest.fn();
	const onDuplicate = jest.fn();
	const onArchive = jest.fn();
	const onProtect = jest.fn();
	const result = render(
		<FieldProperties
			field={ field() }
			catalog={ catalog() }
			sections={ [ { id: 'billing', label: 'Cobrança' } ] }
			fields={ [ { id: 'billing_document', label: 'CPF' } ] }
			onChange={ onChange }
			onDuplicate={ onDuplicate }
			onArchive={ onArchive }
			onProtect={ onProtect }
			{ ...overrides }
		/>
	);

	return { ...result, onChange, onDuplicate, onArchive, onProtect };
}

describe( 'the field properties', () => {
	it( 'names the field, its key and its type', () => {
		renderInspector();

		expect(
			screen.getByRole( 'heading', { name: 'CPF' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'billing_document' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Text' ) ).toBeInTheDocument();
	} );

	it( 'opens on the general tab and offers the other two', async () => {
		const user = userEvent.setup();

		renderInspector();

		const tabs = screen.getByRole( 'group', {
			name: 'Propriedades do campo',
		} );

		expect(
			within( tabs ).getByRole( 'button', { name: 'Geral' } )
		).toHaveAttribute( 'aria-pressed', 'true' );

		await user.click(
			within( tabs ).getByRole( 'button', { name: 'Regras' } )
		);

		// The rules tab is the conditions builder's, which the design puts behind the
		// tab rather than in the general form.
		expect(
			screen.getByText(
				'Choose the values that make this field appear on the checkout.'
			)
		).toBeInTheDocument();
	} );

	it( 'offers a mask where the type declares it takes one', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		// The mask lives in the design's second tab, beside the note that says a mask
		// is formatting and not validation — which is where the prototype puts it.
		await user.click( screen.getByRole( 'button', { name: 'Regras' } ) );

		const select = screen.getByLabelText( 'Máscara de entrada' );

		await user.selectOptions( select, 'br.cep' );

		expect( onChange ).toHaveBeenCalledWith( {
			mask: { key: 'br.cep', version: 1 },
		} );
	} );

	it( 'says why no mask is offered where the type takes none', async () => {
		const user = userEvent.setup();

		renderInspector( {
			catalog: catalog( { value: true, maskable: false } ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Regras' } ) );

		expect(
			screen.getByText(
				'Este tipo não pode ser mascarado, portanto nenhuma máscara é oferecida.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByLabelText( 'Máscara de entrada' )
		).not.toBeInTheDocument();
	} );

	it( 'reports the grid a width button stands for', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		const group = screen.getByRole( 'group', {
			name: 'Largura do campo',
		} );

		await user.click(
			within( group ).getByRole( 'button', { name: '100%' } )
		);

		expect( onChange ).toHaveBeenCalledWith( {
			layout: { desktop: 12, tablet: 12, mobile: 12 },
		} );
	} );

	it( 'offers to archive a field this plugin owns', async () => {
		const user = userEvent.setup();
		const { onArchive } = renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Arquivar' } ) );

		expect( onArchive ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'offers the reason instead of archiving a field WooCommerce owns', async () => {
		const user = userEvent.setup();
		const { onArchive, onProtect } = renderInspector( {
			field: field( { id: 'billing_first_name', origin: 'core' } ),
		} );

		expect(
			screen.queryByRole( 'button', { name: 'Arquivar' } )
		).not.toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: 'Nativo protegido' } )
		);

		expect( onProtect ).toHaveBeenCalledTimes( 1 );
		expect( onArchive ).not.toHaveBeenCalled();
	} );

	it( 'keeps the duplicate action next to the destructive one', async () => {
		const user = userEvent.setup();
		const { onDuplicate } = renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Duplicar' } ) );

		expect( onDuplicate ).toHaveBeenCalledTimes( 1 );
	} );
} );
