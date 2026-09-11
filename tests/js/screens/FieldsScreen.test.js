/**
 * Fields screen tests.
 *
 * This screen shipped without a test, and that is exactly how it came to render
 * nothing at all: a hook returned a new object on every render, the loading
 * effect depended on it, and the effect re-ran after each of its own updates
 * until React gave up. Nothing caught it because nothing rendered the screen.
 *
 * The regression guard is therefore the first thing here: the screen must load
 * **once**. A render loop is invisible in a snapshot of the DOM and obvious in a
 * call count.
 *
 * The client is a stub. What is under test is the screen's behaviour — what it
 * shows, in which state, and how many times it asks for it — not the transport,
 * which has its own suite.
 */

import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FieldsScreen from '../../../resources/admin/app/FieldsScreen';

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

/**
 * Builds a stub client that records its calls.
 *
 * @param {any} overrides Answers to override.
 * @return {any} Client stub.
 */
function client( overrides = {} ) {
	const draft = overrides.draft ?? doc( [ field() ] );

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
			sectionLocations: [
				{ value: 'billing', label: 'Billing', description: '' },
			],
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
		...overrides,
	};
}

describe( 'loading', () => {
	it( 'loads the schema once, not once per render', async () => {
		const stub = client();

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		// The regression guard. A screen whose loading effect depends on something
		// that changes when it loads will call this until React refuses to render;
		// the DOM would look plausible for a moment and the count would not.
		expect( stub.getDraft ).toHaveBeenCalledTimes( 1 );
		expect( stub.fieldTypes ).toHaveBeenCalledTimes( 1 );
		expect( stub.coreFields ).toHaveBeenCalledTimes( 1 );
		expect( stub.diff ).toHaveBeenCalledTimes( 1 );
		expect( stub.revisions ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says it is loading before the schema arrives', async () => {
		// A promise the test controls, so the pending state can be observed without
		// leaving an update to happen after the test ended.
		/** @type {Function} */
		let settle = () => {};

		const pending = new Promise( ( resolve ) => {
			settle = resolve;
		} );

		render(
			<FieldsScreen
				client={ client( { getDraft: jest.fn( () => pending ) } ) }
			/>
		);

		expect( screen.getByText( /Loading the schema/ ) ).toBeInTheDocument();

		settle( doc( [] ) );

		await screen.findByText( 'No fields yet' );
	} );
} );

describe( 'the schema', () => {
	it( 'lists the stored fields', async () => {
		render(
			<FieldsScreen
				client={ client( {
					draft: doc( [
						field(),
						field( { id: 'billing_ie', label: 'IE' } ),
					] ),
				} ) }
			/>
		);

		await screen.findByText( 'CPF' );

		expect( screen.getByText( 'IE' ) ).toBeInTheDocument();
		expect( screen.getByText( /2 active of 2/ ) ).toBeInTheDocument();
	} );

	it( 'invites the merchant to start when there is nothing', async () => {
		render( <FieldsScreen client={ client( { draft: doc( [] ) } ) } /> );

		await screen.findByText( 'No fields yet' );

		expect(
			screen.getByText( /Pick a ready-made field/ )
		).toBeInTheDocument();
	} );

	it( 'groups the fields by the section they are in', async () => {
		render(
			<FieldsScreen
				client={ client( {
					draft: doc( [ field( { section: 'shipping' } ) ] ),
				} ) }
			/>
		);

		await screen.findByText( 'CPF' );

		expect(
			screen.getByRole( 'heading', { name: 'Shipping' } )
		).toBeInTheDocument();
	} );
} );

describe( 'editing', () => {
	it( 'offers to undo an edit only after there is one', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		const undo = screen.getByRole( 'button', { name: 'Undo edit' } );

		expect( undo ).toBeDisabled();

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );

		expect(
			screen.getByRole( 'button', { name: 'Undo edit' } )
		).toBeEnabled();
	} );

	it( 'puts the edit back when undo is used', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );

		expect( screen.getByText( 'CPF (copy)' ) ).toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Undo edit' } ) );

		expect( screen.queryByText( 'CPF (copy)' ) ).not.toBeInTheDocument();
	} );

	it( 'marks the draft as changed without saving it', async () => {
		const user = userEvent.setup();
		const stub = client();

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		expect(
			screen.queryByText( 'Unsaved changes' )
		).not.toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );

		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
		expect( stub.saveDraft ).not.toHaveBeenCalled();
	} );

	it( 'saves the whole document when asked', async () => {
		const user = userEvent.setup();
		const stub = client();

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Save draft' } )
		);

		await waitFor( () =>
			expect( stub.saveDraft ).toHaveBeenCalledTimes( 1 )
		);

		const [ saved, revision ] = stub.saveDraft.mock.calls[ 0 ];

		expect( saved.fields ).toHaveLength( 2 );
		expect( revision ).toBe( 3 );
	} );
} );

describe( 'a failure does not discard work', () => {
	it( 'keeps the edit on screen when the save is refused', async () => {
		const user = userEvent.setup();
		const { ApiError } = await import(
			'../../../resources/admin/app/api/client'
		);

		const stub = client( {
			saveDraft: jest.fn( async () => {
				throw new ApiError( {
					status: 0,
					message: 'The network is down.',
				} );
			} ),
		} );

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Save draft' } )
		);

		await screen.findByText( /could not be reached/ );

		// The edit is still there, and the screen still says it is unsaved.
		expect( screen.getByText( 'CPF (copy)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Unsaved changes' ) ).toBeInTheDocument();
	} );

	it( 'explains a conflict as a conflict rather than as a generic failure', async () => {
		const user = userEvent.setup();
		const { ApiError } = await import(
			'../../../resources/admin/app/api/client'
		);

		const stub = client( {
			saveDraft: jest.fn( async () => {
				throw new ApiError( {
					status: 409,
					data: { current_revision: 9 },
				} );
			} ),
		} );

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		await user.click( screen.getByRole( 'button', { name: 'Duplicate' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Save draft' } )
		);

		await screen.findByText( /Someone else saved first/ );

		expect( screen.getByText( /revision 9/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'CPF (copy)' ) ).toBeInTheDocument();
	} );

	it( 'says a session has expired instead of showing the raw message', async () => {
		const { ApiError } = await import(
			'../../../resources/admin/app/api/client'
		);

		const stub = client( {
			getDraft: jest.fn( async () => {
				throw new ApiError( { status: 403, message: 'Forbidden' } );
			} ),
		} );

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( /not allowed to do this/ );

		// And it says what to do about it, which is the whole point of
		// classifying the failure instead of printing the server's message.
		expect( screen.getByText( /Reload the page/ ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Forbidden' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'states the server reports', () => {
	it( 'explains an unreadable document instead of leaving "no fields yet" alone', async () => {
		const base = await client().diff();

		const stub = client( {
			// An unreadable document reads as an empty one, which is what every
			// other caller wants and what the merchant must not be shown on its own.
			draft: doc( [] ),
			diff: jest.fn( async () => ( {
				...base,
				storage: {
					draft: { state: 'unsupported_version', stored_version: 9 },
					published: { state: 'readable', stored_version: 1 },
				},
			} ) ),
		} );

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( /written by a newer version/ );

		// Both, together: the empty state is truthful, and the explanation says
		// why it is empty and that nothing was lost.
		expect( screen.getByText( 'No fields yet' ) ).toBeInTheDocument();
		expect( screen.getByText( /schema version 9/ ) ).toBeInTheDocument();
	} );

	it( 'reports a field whose type is gone without pretending it is fine', async () => {
		render(
			<FieldsScreen
				client={ client( {
					draft: doc( [ field( { type: 'gone.type' } ) ] ),
				} ) }
			/>
		);

		const notice = (
			await screen.findByText( /no longer available/ )
		).closest( '.wccs-notice' );

		expect( notice ).not.toBeNull();

		// Scoped to the warning: the type name also appears on the field's own row,
		// which is correct and is not what this assertion is about.
		expect(
			within( /** @type {HTMLElement} */ ( notice ) ).getByText(
				/gone\.type/
			)
		).toBeInTheDocument();
	} );
} );
