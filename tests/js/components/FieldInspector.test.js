/**
 * Field inspector tests.
 *
 * The inspector's whole job is stated in ROADMAP.md section 428: "Exibir somente
 * propriedades suportadas". These specs therefore do two things in equal measure —
 * they check that a supported surface is present *and operable*, and that an
 * unsupported one is absent *and accounted for*, because a panel that silently
 * loses rows is how a merchant concludes the feature is broken.
 *
 * The settings controls are checked against schemas the type declares, not
 * against a list of known settings: the point of generating them is that a type
 * nobody wrote code for still gets an editor.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FieldInspector from '../../../resources/admin/app/components/FieldInspector';

/**
 * Builds a catalogue in the shape the server sends.
 *
 * @param {Object} overrides Catalogue parts to override.
 * @return {any} Catalogue.
 */
function catalog( overrides = {} ) {
	return {
		categories: [],
		presets: [],
		types: {
			text: {
				key: 'text',
				label: 'Text',
				category: 'text',
				source: 'core',
				contractVersion: '1.0',
				supports: { value: true, maskable: true, conditional: true },
				valueSchema: { type: 'string' },
				settingsSchema: {
					minLength: { type: 'integer', minimum: 0, maximum: 20000 },
					maxLength: { type: 'integer', minimum: 1, maximum: 20000 },
					placeholder: { type: 'string', maxLength: 200 },
				},
			},
			heading: {
				key: 'heading',
				label: 'Heading',
				category: 'layout',
				source: 'core',
				contractVersion: '1.0',
				supports: { value: false, maskable: false, conditional: true },
				valueSchema: { type: 'null' },
				settingsSchema: {
					content: { type: 'string', maxLength: 20000 },
				},
			},
			select: {
				key: 'select',
				label: 'Select',
				category: 'choice',
				source: 'core',
				contractVersion: '1.0',
				supports: { value: true, maskable: false, conditional: true },
				valueSchema: { type: 'string' },
				settingsSchema: {
					options: {
						type: 'array',
						required: true,
						minItems: 1,
						maxItems: 500,
						items: {
							type: 'object',
							properties: {
								value: { type: 'string', required: true },
								label: { type: 'string', required: true },
							},
						},
					},
				},
			},
			file: {
				key: 'file',
				label: 'File',
				category: 'upload',
				source: 'core',
				contractVersion: '1.0',
				supports: { value: true, maskable: false, conditional: true },
				valueSchema: { type: 'string' },
				settingsSchema: {
					allowedExtensions: {
						type: 'array',
						required: true,
						minItems: 1,
						maxItems: 40,
						items: { type: 'string', maxLength: 20 },
					},
				},
			},
		},
		masks: [
			{
				key: 'numeric',
				version: 1,
				definition: '0',
				appliesTo: [ 'generic' ],
				declarative: true,
			},
			{
				key: 'alphanumeric',
				version: 2,
				definition: '*',
				appliesTo: [ 'generic' ],
				declarative: true,
			},
		],
		vocabulary: {
			storageScopes: [
				{
					value: 'order',
					label: 'With the order',
					description: 'On the order.',
				},
				{
					value: 'customer',
					label: 'On the customer',
					description: 'Remembered.',
				},
				{
					value: 'none',
					label: 'Not stored',
					description: 'Discarded.',
				},
			],
			storageSensitivities: [
				{
					value: 'public',
					label: 'Not personal',
					description: 'Nobody.',
				},
				{
					value: 'personal',
					label: 'Personal',
					description: 'A person.',
				},
				{
					value: 'sensitive',
					label: 'Sensitive',
					description: 'A document.',
				},
			],
			visibilityKeys: [
				{
					value: 'admin_order',
					label: 'Order screen, for staff',
					description: 'Staff.',
				},
				{
					value: 'public_api',
					label: 'Store API and webhooks',
					description: 'Outside.',
				},
			],
			hiddenValuePolicies: [
				{
					value: 'discard',
					label: 'Discard it',
					description: 'Refused.',
				},
				{
					value: 'preserve',
					label: 'Keep what was already saved',
					description: 'Kept.',
				},
			],
		},
		...overrides,
	};
}

/**
 * Builds a field definition.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field definition.
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
 * Renders the inspector with a spy.
 *
 * @param {Object} overrides Props to override.
 * @return {any} Render result and the change spy.
 */
function renderInspector( overrides = {} ) {
	const onChange = jest.fn();
	const result = render(
		<FieldInspector
			field={ field() }
			catalog={ catalog() }
			onChange={ onChange }
			{ ...overrides }
		/>
	);

	return { ...result, onChange };
}

/**
 * Opens one inspector tab.
 *
 * @param {*}      user  User event session.
 * @param {string} label Tab label.
 * @return {Promise<void>} Resolves once the tab is open.
 */
async function openTab( user, label ) {
	await user.click( screen.getByRole( 'tab', { name: label } ) );
}

describe( 'tabs', () => {
	it( 'offers the groups the planning fixes', () => {
		renderInspector();

		expect(
			screen.getAllByRole( 'tab' ).map( ( tab ) => tab.textContent )
		).toEqual( [
			'General',
			'Appearance',
			'Mask and validation',
			'Storage and visibility',
			'Advanced',
		] );
	} );

	it( 'adds the conditions tab only once the engine exists', () => {
		renderInspector( { hasConditions: true } );

		expect(
			screen.getByRole( 'tab', { name: 'Conditions' } )
		).toBeInTheDocument();
	} );
} );

describe( 'general', () => {
	it( 'edits the label', () => {
		const { onChange } = renderInspector();

		fireEvent.change( screen.getByLabelText( /^Label/ ), {
			target: { value: 'Documento' },
		} );

		expect( onChange ).toHaveBeenLastCalledWith( { label: 'Documento' } );
	} );

	it( 'edits the description, which the definition had nowhere to keep before', () => {
		const { onChange } = renderInspector();

		fireEvent.change( screen.getByLabelText( /Description/ ), {
			target: { value: 'Como no documento' },
		} );

		expect( onChange ).toHaveBeenLastCalledWith( {
			description: 'Como no documento',
		} );
	} );

	it( 'edits whether the field is required', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await user.click( screen.getByLabelText( /Required/ ) );

		expect( onChange ).toHaveBeenLastCalledWith( { required: true } );
	} );

	it( 'does not let a requirement WooCommerce imposes be switched off', () => {
		renderInspector( {
			field: field( { origin: 'core', required: true } ),
		} );

		expect( screen.getByLabelText( /Required/ ) ).toBeDisabled();
		expect(
			screen.getByText( /WooCommerce requires this field/ )
		).toBeInTheDocument();
	} );
} );

describe( 'appearance', () => {
	it( 'offers a width for each device class', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Appearance' );

		expect(
			screen.getByLabelText( /Width on Desktop/ )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Width on Tablet/ )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Width on Mobile/ )
		).toBeInTheDocument();
	} );

	it( 'reports a width change without disturbing the other devices', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await openTab( user, 'Appearance' );
		await user.selectOptions(
			screen.getByLabelText( /Width on Desktop/ ),
			'6'
		);

		expect( onChange ).toHaveBeenLastCalledWith( {
			layout: { desktop: 6, tablet: 12, mobile: 12 },
		} );
	} );
} );

describe( 'mask, only where supported', () => {
	it( 'offers the registered masks on a maskable type', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Mask and validation' );

		const select = screen.getByRole( 'combobox', { name: 'Mask' } );
		const options = within( select )
			.getAllByRole( 'option' )
			.map( ( option ) => option.textContent );

		expect( options ).toEqual( [ 'No mask', 'numeric', 'alphanumeric' ] );
	} );

	it( 'records the mask with the version it was configured against', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await openTab( user, 'Mask and validation' );
		await user.selectOptions(
			screen.getByRole( 'combobox', { name: 'Mask' } ),
			'alphanumeric'
		);

		expect( onChange ).toHaveBeenLastCalledWith( {
			mask: { key: 'alphanumeric', version: 2 },
		} );
	} );

	it( 'clears the mask when none is chosen', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( { mask: { key: 'numeric', version: 1 } } ),
		} );

		await openTab( user, 'Mask and validation' );
		await user.selectOptions(
			screen.getByRole( 'combobox', { name: 'Mask' } ),
			''
		);

		expect( onChange ).toHaveBeenLastCalledWith( { mask: null } );
	} );

	it( 'states that a type cannot be masked instead of hiding the row', async () => {
		const user = userEvent.setup();
		renderInspector( { field: field( { type: 'select' } ) } );

		await openTab( user, 'Mask and validation' );

		expect( screen.queryByRole( 'combobox', { name: 'Mask' } ) ).toBeNull();
		expect(
			screen.getByText( /This type cannot be masked/ )
		).toBeInTheDocument();
	} );
} );

describe( 'type settings, generated from the declaration', () => {
	it( 'renders one control per declared setting', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Mask and validation' );

		expect( screen.getByLabelText( 'placeholder' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'minLength' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'maxLength' ) ).toBeInTheDocument();
	} );

	it( 'applies the declared limits to the control', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Mask and validation' );

		const maxLength = screen.getByLabelText( 'maxLength' );

		expect( maxLength ).toHaveAttribute( 'type', 'number' );
		expect( maxLength ).toHaveAttribute( 'min', '1' );
		expect( maxLength ).toHaveAttribute( 'max', '20000' );
	} );

	it( 'writes a declared setting', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await openTab( user, 'Mask and validation' );
		fireEvent.change( screen.getByLabelText( 'placeholder' ), {
			target: { value: '000' },
		} );

		expect( onChange ).toHaveBeenLastCalledWith( {
			settings: { placeholder: '000' },
		} );
	} );

	it( 'edits a list of strings declared by the type', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				type: 'file',
				settings: { allowedExtensions: [ 'pdf' ] },
			} ),
		} );

		await openTab( user, 'Mask and validation' );

		expect( screen.getByDisplayValue( 'pdf' ) ).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: /Add allowedExtensions/ } )
		);

		expect( onChange ).toHaveBeenLastCalledWith( {
			settings: { allowedExtensions: [ 'pdf', '' ] },
		} );
	} );

	it( 'edits options as value and label pairs', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector( {
			field: field( {
				type: 'select',
				settings: { options: [ { value: 'pf', label: 'Individual' } ] },
			} ),
		} );

		await openTab( user, 'Mask and validation' );

		expect( screen.getByDisplayValue( 'pf' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( 'Individual' ) ).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: /Add option/ } )
		);

		expect( onChange ).toHaveBeenLastCalledWith( {
			settings: {
				options: [
					{ value: 'pf', label: 'Individual' },
					{ value: '', label: '' },
				],
			},
		} );
	} );

	it( 'warns when a choice field has no options yet', async () => {
		const user = userEvent.setup();
		renderInspector( {
			field: field( { type: 'select', settings: { options: [] } } ),
		} );

		await openTab( user, 'Mask and validation' );

		expect(
			screen.getByText( /needs at least one option/ )
		).toBeInTheDocument();
	} );

	it( 'says so when the type declares no settings', async () => {
		const user = userEvent.setup();
		const bare = catalog();
		bare.types.text.settingsSchema = {};

		renderInspector( { catalog: bare } );

		await openTab( user, 'Mask and validation' );

		expect(
			screen.getByText( /declares no settings of its own/ )
		).toBeInTheDocument();
	} );

	it( 'shows the privacy note for a type that accepts uploads', async () => {
		const user = userEvent.setup();
		renderInspector( {
			field: field( { type: 'file', settings: {} } ),
		} );

		await openTab( user, 'Mask and validation' );

		expect( screen.getByText( /stored privately/ ) ).toBeInTheDocument();
	} );
} );

describe( 'storage and visibility, only where supported', () => {
	it( 'offers the storage scope and the sensitivity on a field the suite owns', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Storage and visibility' );

		expect(
			screen.getByLabelText( /Where the value is kept/ )
		).toBeEnabled();
		expect(
			screen.getByLabelText( /How sensitive the value is/ )
		).toBeInTheDocument();
	} );

	it( 'reports the storage of a WooCommerce field instead of letting it be chosen', async () => {
		const user = userEvent.setup();
		renderInspector( { field: field( { origin: 'core' } ) } );

		await openTab( user, 'Storage and visibility' );

		expect(
			screen.getByLabelText( /Where the value is kept/ )
		).toBeDisabled();
		expect(
			screen.getByText( /persists this field through its own flow/ )
		).toBeInTheDocument();
	} );

	it( 'offers the audiences the value may be shown to', async () => {
		const user = userEvent.setup();
		const { onChange } = renderInspector();

		await openTab( user, 'Storage and visibility' );

		await user.click( screen.getByLabelText( /Store API and webhooks/ ) );

		expect( onChange ).toHaveBeenLastCalledWith( {
			visibility: { admin_order: true, public_api: true },
		} );
	} );

	it( 'says there is nothing to show when the type stores no value', async () => {
		const user = userEvent.setup();
		renderInspector( { field: field( { type: 'heading' } ) } );

		await openTab( user, 'Storage and visibility' );

		expect(
			screen.getByText( /nothing to show anywhere/ )
		).toBeInTheDocument();
		expect(
			screen.queryByLabelText( /Store API and webhooks/ )
		).toBeNull();
		expect(
			screen.queryByLabelText( /When a rule hides the field/ )
		).toBeNull();
	} );
} );

describe( 'advanced', () => {
	it( 'shows the technical identity and offers no way to change it', async () => {
		const user = userEvent.setup();
		renderInspector();

		await openTab( user, 'Advanced' );

		expect( screen.getByText( 'billing_document' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'wc-checkoutsuite/billing_document' )
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'tabpanel' ) ).queryAllByRole( 'textbox' )
		).toHaveLength( 0 );
	} );
} );

describe( 'a WooCommerce field', () => {
	it( 'explains why it cannot be removed, wherever the inspector is open', async () => {
		const user = userEvent.setup();
		renderInspector( { field: field( { origin: 'core' } ) } );

		expect(
			screen.getByText( /shipping, tax and payment read it/ )
		).toBeInTheDocument();

		await openTab( user, 'Appearance' );

		expect(
			screen.getByText( /shipping, tax and payment read it/ )
		).toBeInTheDocument();
	} );
} );
