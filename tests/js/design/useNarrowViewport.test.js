/**
 * Narrow-viewport tests.
 *
 * The design hides the editor's right column below 870px and opens the same field
 * properties in a dialog instead. What is under test is that the screen follows the
 * stylesheet's own breakpoint: the measurement is the browser's, and the dialog is
 * what the merchant gets when the column is not there.
 */

import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FieldsScreen from '../../../resources/admin/app/FieldsScreen';

/**
 * The `matchMedia` implementation a test asks for.
 *
 * @param {boolean} narrow Whether the query should report a narrow window.
 * @return {any} Mock.
 */
function media( narrow ) {
	const listeners = new Set();

	return jest.fn( () => ( {
		matches: narrow,
		media: '(max-width: 870px)',
		addEventListener: (
			/** @type {string} */ name,
			/** @type {any} */ fn
		) => {
			if ( 'change' === name ) {
				listeners.add( fn );
			}
		},
		removeEventListener: ( /** @type {any} */ fn ) => {
			listeners.delete( fn );
		},
		/**
		 * Moves the window, so the listener can be exercised.
		 *
		 * @param {boolean} value New answer.
		 */
		emit: ( value ) =>
			listeners.forEach( ( fn ) => fn( { matches: value } ) ),
	} ) );
}

/**
 * A field definition.
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
 * A client stub with one field in the draft.
 *
 * @return {any} Client stub.
 */
function client() {
	const draft = {
		revision: 3,
		fields: [ field() ],
		sections: [],
		settings: {},
	};

	return {
		getDraft: jest.fn( async () => draft ),
		fieldTypes: jest.fn( async () => ( {
			categories: [],
			types: {
				text: {
					key: 'text',
					label: 'Text',
					category: 'text',
					source: 'core',
					contractVersion: '1.0',
					supports: { value: true, maskable: true },
					valueSchema: { type: 'string' },
					settingsSchema: {},
				},
			},
			presets: [],
			masks: [],
			vocabulary: {
				storageScopes: [],
				storageSensitivities: [],
				visibilityKeys: [],
				hiddenValuePolicies: [],
			},
			sectionLocations: [ { value: 'billing', label: 'Cobrança' } ],
		} ) ),
		coreFields: jest.fn( async () => ( {
			available: true,
			reason: '',
			sections: [],
			fields: [],
		} ) ),
		diff: jest.fn( async () => ( {
			diff: {
				empty: true,
				total_changes: 0,
				fields: { added: [], removed: [], changed: [] },
				sections: { added: [], removed: [], changed: [] },
				order: [],
				published: { revision: 1, updated_at: '' },
				draft: { revision: 3, updated_at: '' },
			},
			validation: { valid: true, errors: [] },
			incompatibilities: {
				total: 0,
				adapters: { classic: [], blocks: [] },
				store: [],
			},
			adapters: [],
			storage: {
				draft: { state: 'readable', stored_version: 1 },
				published: { state: 'readable', stored_version: 1 },
			},
		} ) ),
		revisions: jest.fn( async () => ( { revisions: [] } ) ),
		saveDraft: jest.fn( async () => draft ),
		publish: jest.fn( async () => ( { status: 'ok' } ) ),
		restore: jest.fn( async () => ( { status: 'ok' } ) ),
	};
}

/**
 * The row's own button, which is what opens the properties.
 *
 * The design labels both it and the row menu with the field's name, so the test
 * reaches the control the way the screen draws it.
 *
 * @return {HTMLElement} Button.
 */
function fieldRow() {
	return /** @type {HTMLElement} */ (
		globalThis.document.querySelector( '.field-info' )
	);
}

describe( 'the field properties on a narrow window', () => {
	afterEach( () => {
		delete ( /** @type {any} */ ( globalThis ).matchMedia );
	} );

	it( 'stays in the column while the window is wide', async () => {
		const user = userEvent.setup();

		globalThis.matchMedia = media( false );

		render( <FieldsScreen client={ client() } /> );

		await screen.findByRole( 'listitem' );
		await user.click( fieldRow() );

		// A wide window does not mount the dialog at all: the properties are in the
		// column, and one home for them is what keeps every control id unique.
		expect(
			globalThis.document.querySelector( '.mobile-inspector' )
		).toBeNull();
		// eslint-disable-next-line no-console
		expect( screen.getByLabelText( 'Nome do campo' ) ).toBeInTheDocument();
	} );

	it( 'opens in the design dialog when a field is chosen', async () => {
		const user = userEvent.setup();

		globalThis.matchMedia = media( true );

		render( <FieldsScreen client={ client() } /> );

		await screen.findByRole( 'listitem' );

		expect(
			globalThis.document.querySelector( '.mobile-inspector' )
		).toBeNull();

		await user.click( fieldRow() );

		const dialog = await waitFor( () => {
			const node =
				globalThis.document.querySelector( '.mobile-inspector' );

			expect( node?.hasAttribute( 'open' ) ).toBe( true );

			return node;
		} );

		// The design's compact head names the surface, and the properties are
		// inside it — the column is hidden by the stylesheet at this width.
		expect( dialog ).toHaveTextContent( 'Propriedades do campo' );
		expect(
			within( /** @type {HTMLElement} */ ( dialog ) ).getByLabelText(
				'Nome do campo'
			)
		).toBeInTheDocument();
	} );

	it( 'closes when the properties are dismissed', async () => {
		const user = userEvent.setup();

		globalThis.matchMedia = media( true );

		render( <FieldsScreen client={ client() } /> );

		await screen.findByRole( 'listitem' );
		await user.click( fieldRow() );

		await waitFor( () =>
			expect(
				globalThis.document.querySelector( '.mobile-inspector' )
			).not.toBeNull()
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Fechar propriedades' } )
		);

		// Dismissing clears the selection, which is what the dialog was opened on.
		await waitFor( () =>
			expect(
				globalThis.document.querySelector( '.mobile-inspector' )
			).toBeNull()
		);
	} );
} );
