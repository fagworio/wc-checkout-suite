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
			destinations: [
				{
					value: 'admin_order',
					label: 'Order screen, for staff',
					description: 'Shown to staff when they open the order.',
					actions: [ 'show_metadata', 'view', 'download', 'approve' ],
				},
				{
					value: 'customer_order',
					label: 'Order screen, for the customer',
					description: 'Shown to the customer who placed the order.',
					actions: [ 'show_metadata', 'view' ],
				},
			],
			destinationActions: [
				{
					value: 'show_metadata',
					label: 'Show the file name and details',
					description: '',
				},
				{ value: 'view', label: 'Open it', description: '' },
				{ value: 'download', label: 'Download it', description: '' },
				{
					value: 'approve',
					label: 'Review and approve',
					description: 'Only for staff.',
				},
			],
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
		destinations: {},
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
			linkSections={ [
				{
					id: 'billing',
					label: 'Cobrança',
					areas: [ 'checkout', 'admin_order' ],
				},
			] }
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

	it( 'says what a native field edit changes, before it is typed', () => {
		renderInspector( {
			field: field( { id: 'billing_first_name', origin: 'core' } ),
			reference: 'classic',
		} );

		const support = document.getElementById( 'wccs-native-support' );

		expect( support ).not.toBeNull();
		expect( support?.textContent ).toContain(
			'escritas no campo real do WooCommerce'
		);
		// The label is applied; the required flag is the platform's, and the panel
		// says so rather than pretending the toggle writes it.
		expect(
			document.getElementById( 'wccs-native-label' )?.textContent
		).toContain( 'aplicado' );
		expect(
			document.getElementById( 'wccs-native-required' )?.textContent
		).toContain( 'da plataforma' );
	} );

	it( 'says the platform owns native fields on a Blocks store', () => {
		renderInspector( {
			field: field( { id: 'billing_first_name', origin: 'core' } ),
			reference: 'blocks',
		} );

		const support = document.getElementById( 'wccs-native-support' );

		expect( support?.textContent ).toContain( 'dono dos campos nativos' );
		expect(
			document.getElementById( 'wccs-native-label' )?.textContent
		).toContain( 'da plataforma' );
	} );

	it( 'says nothing of the sort about a field this plugin owns', () => {
		renderInspector( { reference: 'classic' } );

		expect( document.getElementById( 'wccs-native-support' ) ).toBeNull();
	} );
} );

describe( 'the links and display tab', () => {
	it( 'offers every destination the catalogue publishes, disabled', async () => {
		const user = userEvent.setup();

		renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		// Named the editor's way: the server's label is written for the store's own
		// documents, and the merchant reads the destination the editor calls it.
		expect(
			screen.getByRole( 'group', { name: 'Pedido' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Mostrar em Pedido',
			} )
		).not.toBeChecked();
	} );

	it( 'reports one destination without touching the others', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		const [ first ] = screen.getAllByRole( 'checkbox', {
			name: /^Mostrar em /,
		} );

		await user.click( first );

		// Turning a destination on is the same statement as using the field there (§3.3),
		// so what is written is one use — and the map, projected from it, so the two cannot
		// disagree. The other destination is not mentioned at all.
		expect( onChange ).toHaveBeenCalledTimes( 1 );

		const written = onChange.mock.calls[ 0 ][ 0 ];

		expect( written.bindings ).toHaveLength( 1 );
		expect( written.bindings[ 0 ] ).toEqual(
			expect.objectContaining( {
				field_id: 'billing_document',
				destination: 'admin_order',
				container_id: '',
				visible: true,
			} )
		);
		expect( written.destinations ).toEqual( {
			admin_order: expect.objectContaining( { enabled: true } ),
		} );
	} );

	it( 'shows every use of a destination and adds another one', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				bindings: [
					{
						id: 'billing_document@admin_order',
						field_id: 'billing_document',
						container_id: '',
						destination: 'admin_order',
						position: 10,
						visible: true,
						editable: true,
						label_override: 'Autorização',
						permissions: [],
					},
					{
						id: 'billing_document@admin_order/billing#2',
						field_id: 'billing_document',
						container_id: 'billing',
						destination: 'admin_order',
						position: 20,
						visible: true,
						editable: true,
						label_override: 'Conferido',
						permissions: [],
					},
				],
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		// Both uses are on screen, each with its own controls, and each can be removed
		// because neither is the only one.
		expect(
			screen.getAllByLabelText( 'Seção neste destino' )
		).toHaveLength( 2 );
		expect( screen.getAllByLabelText( 'Título apresentado' ) ).toHaveLength(
			2
		);
		expect( screen.getByDisplayValue( 'Autorização' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( 'Conferido' ) ).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: 'Remover uso' } )
		).toHaveLength( 2 );

		await user.click(
			screen.getByRole( 'button', {
				name: 'Adicionar uso nesta área',
			} )
		);

		const written = onChange.mock.calls[ 0 ][ 0 ];

		expect( written.bindings ).toHaveLength( 3 );
		expect( written.bindings[ 2 ] ).toEqual(
			expect.objectContaining( {
				destination: 'admin_order',
				container_id: '',
			} )
		);
		expect( written.bindings[ 2 ].id ).not.toBe( written.bindings[ 0 ].id );
	} );

	it( 'removes one use, leaving the others where they are', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				bindings: [
					{
						id: 'billing_document@admin_order',
						field_id: 'billing_document',
						container_id: '',
						destination: 'admin_order',
						position: 10,
						visible: true,
						editable: true,
					},
					{
						id: 'billing_document@admin_order/billing#2',
						field_id: 'billing_document',
						container_id: 'billing',
						destination: 'admin_order',
						position: 20,
						visible: true,
						editable: true,
					},
				],
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		const [ , second ] = screen.getAllByRole( 'button', {
			name: 'Remover uso',
		} );

		await user.click( second );

		const written = onChange.mock.calls[ 0 ][ 0 ];

		expect( written.bindings ).toHaveLength( 1 );
		expect( written.bindings[ 0 ].id ).toBe(
			'billing_document@admin_order'
		);
	} );

	it( 'turns a destination off by removing its uses, map included', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				destinations: {
					admin_order: { enabled: true, mode: 'edit' },
					customer_order: { enabled: false },
				},
				bindings: [
					{
						id: 'billing_document@admin_order',
						field_id: 'billing_document',
						container_id: '',
						destination: 'admin_order',
						position: 10,
						visible: true,
						editable: true,
					},
				],
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Mostrar em Pedido' } )
		);

		const written = onChange.mock.calls[ 0 ][ 0 ];

		expect( written.bindings ).toEqual( [] );
		// No use justifies the destination any more, so the map must not go on saying it
		// is on. The link that was already off stays: it is inert configuration.
		expect( written.destinations.admin_order ).toBeUndefined();
		expect( written.destinations.customer_order ).toEqual( {
			enabled: false,
		} );
	} );

	it( 'offers only the actions the destination may perform', async () => {
		const user = userEvent.setup();

		renderInspector( {
			catalog: catalog( { value: true, maskable: true, file: true } ),
			field: field( {
				destinations: { customer_order: { enabled: true } },
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		// Approving is a staff action: a customer destination does not offer it.
		expect(
			screen.getByRole( 'checkbox', { name: 'Open it' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', { name: 'Review and approve' } )
		).not.toBeInTheDocument();
	} );

	it( 'offers no file actions to a type that stores no file', async () => {
		const user = userEvent.setup();

		renderInspector( {
			field: field( {
				destinations: { admin_order: { enabled: true } },
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		// The link itself is a text field's business — where it is shown, in which
		// section and in what order. The actions are the file's, and this type has no
		// file to show, open, download, approve or replace.
		expect(
			screen.getByRole( 'group', { name: 'Pedido' } )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Seção neste destino' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Ações permitidas' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', { name: 'Open it' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', { name: 'Download it' } )
		).not.toBeInTheDocument();
	} );

	it( 'keeps an approval flow off until it is asked for', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		expect(
			screen.queryByLabelText( 'Área de análise' )
		).not.toBeInTheDocument();

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Exigir análise manual' } )
		);

		// Turning it on offers the state the design suggests, in the field the merchant
		// can edit. The server refuses a flow that names no state, so a suggestion here
		// is the difference between a flow that can be configured and one that cannot.
		expect( onChange ).toHaveBeenCalledWith( {
			approval: {
				require_review: true,
				status: 'Pendente de aprovação',
			},
		} );
	} );

	it( 'keeps a state the merchant already named', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				approval: {
					require_review: false,
					status: 'Aguardando conferência',
				},
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Exigir análise manual' } )
		);

		expect( onChange ).toHaveBeenCalledWith( {
			approval: {
				require_review: true,
				status: 'Aguardando conferência',
			},
		} );
	} );
} );

describe( 'a section offered per area', () => {
	it( 'offers a destination only the sections offered there', async () => {
		const user = userEvent.setup();

		renderInspector( {
			// The links tab reads the sections the document declares, with the areas each
			// one is offered in — not the list of places this field may move to.
			linkSections: [
				{
					id: 'checkout_only',
					label: 'Só no checkout',
					areas: [ 'checkout' ],
				},
				{
					id: 'para_analise',
					label: 'Para análise',
					areas: [ 'admin_order' ],
				},
			],
			field: field( {
				destinations: {
					admin_order: { enabled: true },
					customer_order: { enabled: true },
				},
			} ),
		} );

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		const [ staff, customer ] = screen.getAllByLabelText(
			'Seção neste destino'
		);

		// The staff destination may use the analysis section; the customer
		// destination may not, and offering it would let the form write something the
		// server refuses.
		expect(
			within( staff ).getByRole( 'option', { name: 'Para análise' } )
		).toBeInTheDocument();
		expect(
			within( customer ).queryByRole( 'option', { name: 'Para análise' } )
		).not.toBeInTheDocument();
		expect(
			within( customer ).queryByRole( 'option', {
				name: 'Só no checkout',
			} )
		).not.toBeInTheDocument();
	} );
	it( 'decides the flow of the value, one direction at a time', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await user.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		// §7.7 and §10.4: the journey to the checkout is a decision, and it is not the same
		// decision as who may edit the value on each surface.
		const prefill = screen.getByRole( 'checkbox', {
			name: 'Perfil → checkout',
		} );

		expect( prefill ).not.toBeChecked();

		await user.click( prefill );

		expect( onChange ).toHaveBeenCalledWith( {
			sync: { to_checkout: true },
		} );
	} );

	it( 'shows the flow the document already holds', async () => {
		renderInspector( {
			field: field( { sync: { to_checkout: true } } ),
		} );

		await userEvent
			.setup()
			.click( screen.getByRole( 'button', { name: 'Vínculos' } ) );

		expect(
			screen.getByRole( 'checkbox', { name: 'Perfil → checkout' } )
		).toBeChecked();
	} );
} );
