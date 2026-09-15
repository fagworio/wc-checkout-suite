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

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
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

/**
 * Duplicates a field through the row menu the design draws.
 *
 * The action moved into the row's own menu when the screen took the design's shape,
 * so reaching it is two clicks instead of one — which is the behaviour a merchant
 * performs, and the reason the test follows it rather than calling the action.
 *
 * @param {any}    user  user-event instance.
 * @param {string} label Field label.
 * @return {Promise<void>} Resolves when the action has been clicked.
 */
async function duplicateField( user, label ) {
	await user.click(
		screen.getByRole( 'button', { name: `Ações de ${ label }` } )
	);
	await user.click(
		screen.getByRole( 'button', {
			name: 'Duplicar como personalizado',
		} )
	);
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

		await screen.findByText( 'Esta seção está pronta para começar.' );
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
		expect( screen.getByText( /2 de 2 ativos/ ) ).toBeInTheDocument();
	} );

	it( 'invites the merchant to start when there is nothing', async () => {
		render( <FieldsScreen client={ client( { draft: doc( [] ) } ) } /> );

		await screen.findByText( 'Esta seção está pronta para começar.' );

		expect(
			screen.getByText( /Adicione campos complementares/ )
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
			screen.getByRole( 'heading', { name: 'Endereço de entrega' } )
		).toBeInTheDocument();
	} );

	it( 'separates checkout collection from customer display links', async () => {
		const user = userEvent.setup();
		render(
			<FieldsScreen
				client={ client( {
					draft: {
						...doc( [
							field( {
								destinations: {
									customer_order: {
										enabled: true,
										section: 'customer_documents',
									},
								},
							} ),
						] ),
						sections: [
							{
								id: 'customer_documents',
								title: 'Documentos',
								description: '',
								position: 10,
								location: 'order',
								areas: [ 'customer_order' ],
							},
						],
					},
				} ) }
			/>
		);

		await screen.findByText( 'CPF' );
		expect(
			screen.getByRole( 'heading', { name: 'Dados de cobrança' } )
		).toBeInTheDocument();

		await user.click( screen.getByRole( 'tab', { name: /Cliente/ } ) );

		expect(
			screen.getByRole( 'heading', { name: 'Documentos' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Vincular campo existente' } )
		).toBeInTheDocument();
	} );

	it( 'states the areas where the selected section is active', async () => {
		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		expect(
			screen.getByLabelText( 'Áreas em que esta seção está ativa' )
		).toHaveTextContent( 'Ativa em: Checkout' );
	} );
} );

describe( 'editing', () => {
	it( 'offers to undo an edit only after there is one', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		const undo = screen.getByRole( 'button', {
			name: 'Desfazer alteração',
		} );

		expect( undo ).toBeDisabled();

		await duplicateField( user, 'CPF' );

		expect(
			screen.getByRole( 'button', { name: 'Desfazer alteração' } )
		).toBeEnabled();
	} );

	it( 'puts the edit back when undo is used', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		await duplicateField( user, 'CPF' );

		expect( screen.getByText( 'CPF (copy)' ) ).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: 'Desfazer alteração' } )
		);

		expect( screen.queryByText( 'CPF (copy)' ) ).not.toBeInTheDocument();
	} );

	it( 'marks the draft as changed without saving it', async () => {
		const user = userEvent.setup();
		const stub = client();

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		expect(
			screen.queryByText( 'Alterações não salvas' )
		).not.toBeInTheDocument();

		await duplicateField( user, 'CPF' );

		expect(
			screen.getByText( 'Alterações não salvas' )
		).toBeInTheDocument();
		expect( stub.saveDraft ).not.toHaveBeenCalled();
	} );

	it( 'saves the whole document when asked', async () => {
		const user = userEvent.setup();
		const stub = client();

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		await duplicateField( user, 'CPF' );
		await user.click(
			screen.getByRole( 'button', { name: 'Atualizar campos' } )
		);

		await waitFor( () =>
			expect( stub.saveDraft ).toHaveBeenCalledTimes( 1 )
		);

		const [ saved, revision ] = stub.saveDraft.mock.calls[ 0 ];

		expect( saved.fields ).toHaveLength( 2 );
		expect( revision ).toBe( 3 );
	} );

	it( 'writes the draft over the draft route, and writes nothing else', async () => {
		const user = userEvent.setup();
		const { createClient } = await import(
			'../../../resources/admin/app/api/client'
		);

		/** @type {string[]} */
		const requests = [];
		const document = doc( [ field() ] );
		const transport = createClient( {
			root: 'https://example.test/wp-json/',
			namespace: 'wc-checkoutsuite/v1',
			nonce: 'nonce-value',
			routes: { draft: '/schema/draft' },
			fetchImpl: async ( url, options = {} ) => {
				requests.push( `${ options.method } ${ url }` );

				return {
					ok: true,
					status: 200,
					text: async () => JSON.stringify( document ),
				};
			},
			sleep: async () => {},
		} );

		// The reads keep their own stub: what is under test is where the save lands,
		// and that it is the only write the screen performs.
		const stub = client( { saveDraft: transport.saveDraft } );

		render( <FieldsScreen client={ stub } /> );

		await screen.findByText( 'CPF' );

		await duplicateField( user, 'CPF' );
		await user.click(
			screen.getByRole( 'button', { name: 'Atualizar campos' } )
		);

		await waitFor( () => expect( requests ).toHaveLength( 1 ) );

		expect( requests[ 0 ] ).toBe(
			'PUT https://example.test/wp-json/wc-checkoutsuite/v1/schema/draft'
		);
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

		await duplicateField( user, 'CPF' );
		await user.click(
			screen.getByRole( 'button', { name: 'Atualizar campos' } )
		);

		await screen.findByText( /could not be reached/ );

		// The edit is still there, and the screen still says it is unsaved.
		expect( screen.getByText( 'CPF (copy)' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Alterações não salvas' )
		).toBeInTheDocument();
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

		await duplicateField( user, 'CPF' );
		await user.click(
			screen.getByRole( 'button', { name: 'Atualizar campos' } )
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
		expect(
			screen.getByText( 'Esta seção está pronta para começar.' )
		).toBeInTheDocument();
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

describe( 'reordering', () => {
	/**
	 * Builds a draft with two fields in one section, in a known order.
	 *
	 * @return {any} Document.
	 */
	function twoFields() {
		return doc( [
			field( { id: 'billing_document', label: 'CPF', position: 10 } ),
			field( {
				id: 'billing_ie',
				label: 'IE',
				integration_id: 'wc-checkoutsuite/billing_ie',
				position: 20,
			} ),
		] );
	}

	/**
	 * The labels of the rows on screen, in the order they are drawn.
	 *
	 * @return {string[]} Labels.
	 */
	function rowOrder() {
		return Array.from(
			globalThis.document.querySelectorAll(
				'.field-row .field-name strong'
			)
		).map( ( node ) => node.textContent ?? '' );
	}

	/**
	 * Starts dragging one row's handle and drops it on another row.
	 *
	 * jsdom has no `DataTransfer`, so the transfer object is the smallest one the
	 * handlers use. That is the point: what is under test is where the drop lands,
	 * not the browser's drag payload.
	 *
	 * @param {string} from Label of the row being dragged.
	 * @param {string} to   Label of the row it is dropped on.
	 * @return {void}
	 */
	function drag( from, to ) {
		const handle = screen.getByRole( 'button', {
			name: `Ordenar ${ from }. Alt e setas para mover.`,
		} );
		const source = handle.closest( '.field-row' );
		const target = screen
			.getByRole( 'button', {
				name: `Ordenar ${ to }. Alt e setas para mover.`,
			} )
			.closest( '.field-row' );

		if ( ! source || ! target ) {
			throw new Error( 'The rows under test are not on screen.' );
		}

		const dataTransfer = {
			effectAllowed: '',
			setData: jest.fn(),
			getData: () => from,
		};

		fireEvent.dragStart( handle, { dataTransfer } );
		fireEvent.dragOver( target, { dataTransfer } );
		fireEvent.drop( target, { dataTransfer } );
		fireEvent.dragEnd( handle, { dataTransfer } );
	}

	it( 'moves a row onto the row it is dropped on', async () => {
		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		expect( rowOrder() ).toEqual( [ 'CPF', 'IE' ] );

		drag( 'IE', 'CPF' );

		expect( rowOrder() ).toEqual( [ 'IE', 'CPF' ] );
	} );

	it( 'announces where the field landed, as the design does', async () => {
		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		drag( 'IE', 'CPF' );

		// The design keeps a live region beside the toast, so the sentence is read
		// out whether or not the toast was seen. Both are asserted, in the order the
		// design puts them in the DOM.
		const announced = await screen.findAllByText(
			'IE movido para a posição 1.'
		);

		expect( announced ).toHaveLength( 2 );
		expect( announced[ 0 ] ).toHaveClass( 'sr-only' );
		expect( announced[ 1 ] ).toHaveClass( 'toast' );
	} );

	it( 'refuses to reorder while a filter is on, and says why', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		// Both fields are custom, so they stay on screen and only the *filter* is
		// active — which is the state the design refuses to reorder in.
		await user.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Filtrar campos por origem',
			} ),
			'custom'
		);

		drag( 'IE', 'CPF' );

		// The drag is refused before anything moves: with a filter on, the rows on
		// screen are not the order the document has, so the position a drop lands on
		// would not be the position the merchant pointed at.
		expect( rowOrder() ).toEqual( [ 'CPF', 'IE' ] );
		expect(
			await screen.findAllByText(
				'Limpe a busca e os filtros antes de reordenar.'
			)
		).toHaveLength( 2 );
	} );

	it( 'moves a row with the arrow keys on its handle', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		const handle = screen.getByRole( 'button', {
			name: 'Ordenar IE. Alt e setas para mover.',
		} );

		await user.click( handle );
		await user.keyboard( '[AltLeft>][ArrowUp][/AltLeft]' );

		expect( rowOrder() ).toEqual( [ 'IE', 'CPF' ] );
		expect(
			await screen.findAllByText( 'IE movido para a posição 1.' )
		).toHaveLength( 2 );

		// The row keeps the focus, so the next Alt+Arrow moves the same field instead
		// of starting over from wherever the browser decided to put it.
		await waitFor( () =>
			expect(
				globalThis.document.getElementById( 'wccs-drag-billing_ie' )
			).toHaveFocus()
		);

		await user.keyboard( '[AltLeft>][ArrowDown][/AltLeft]' );

		expect( rowOrder() ).toEqual( [ 'CPF', 'IE' ] );
	} );
} );

describe( 'announcements', () => {
	/**
	 * Two fields in the same section, so a bulk action has something to change.
	 *
	 * @return {any} Document.
	 */
	function twoFields() {
		return doc( [
			field(),
			field( {
				id: 'billing_ie',
				label: 'IE',
				integration_id: 'wc-checkoutsuite/billing_ie',
				position: 20,
			} ),
		] );
	}

	it( 'says what a bulk action changed, and only what it changed', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar IE' } )
		);

		await user.click( screen.getByRole( 'button', { name: 'Desativar' } ) );

		// The design's bar acts on click. The confirmation is where the impact is
		// stated before anything changes — and where cancelling is possible.
		expect(
			await screen.findByText(
				/2 de 2 campo\(s\) selecionado\(s\) mudam\./
			)
		).toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Confirmar' } ) );

		expect(
			await screen.findAllByText(
				'2 campo(s) desativado(s) no rascunho.'
			)
		).toHaveLength( 2 );
	} );

	it( 'changes nothing when the confirmation is dismissed', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client( { draft: twoFields() } ) } /> );

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Desativar' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Cancelar' } ) );

		expect(
			screen.queryAllByText( '1 campo(s) desativado(s) no rascunho.' )
		).toHaveLength( 0 );
		expect(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		).toBeChecked();
		expect(
			screen.queryByText( 'Alterações não salvas' )
		).not.toBeInTheDocument();
	} );

	it( 'says nothing when a bulk action is refused', async () => {
		const user = userEvent.setup();

		render(
			<FieldsScreen
				client={ client( {
					draft: doc( [
						field(),
						field( {
							id: 'billing_first_name',
							label: 'Nome',
							origin: 'core',
							integration_id:
								'wc-checkoutsuite/billing_first_name',
							position: 20,
						} ),
					] ),
				} ) }
			/>
		);

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar Nome' } )
		);

		await user.click( screen.getByRole( 'button', { name: 'Desativar' } ) );

		// The confirmation names the selected field it will not touch before the
		// action runs, rather than reporting it afterwards.
		// The design names the field it will not touch, and why, in the impact list.
		expect(
			await screen.findByText( /fica intocado: é da WooCommerce\./ )
		).toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Confirmar' } ) );

		// A core field cannot be archived, and the screen applies a bulk action all
		// or nothing — so there is no count to announce, and the reason is stated
		// where the screen already states refusals.
		expect(
			screen.queryAllByText( '2 campo(s) desativado(s) no rascunho.' )
		).toHaveLength( 0 );
		expect(
			await screen.findByText( /belongs to WooCommerce/ )
		).toBeInTheDocument();
	} );

	it( 'announces the export the design announces', async () => {
		const user = userEvent.setup();
		const click = jest
			.spyOn( globalThis.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => {} );
		const createObjectURL = jest.fn( () => 'blob:test' );

		globalThis.URL.createObjectURL = createObjectURL;
		globalThis.URL.revokeObjectURL = jest.fn();

		render(
			<FieldsScreen
				client={ client( {
					exportSchema: jest.fn( async () => ( {
						ok: true,
						data: { revision: 1 },
					} ) ),
				} ) }
			/>
		);

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'button', { name: 'Exportar configuração' } )
		);

		// The screen's own `document` is the schema, so a download that reaches for
		// the page has to say so; before it did, this button threw and no file was
		// ever produced.
		expect( createObjectURL ).toHaveBeenCalledTimes( 1 );
		expect( click ).toHaveBeenCalledTimes( 1 );

		// The announcement arrives from a promise, so the toast is a render after the
		// live region: wait for the visible one, then assert both carry the sentence.
		const message =
			'Exportada apenas a configuração dos campos. Nenhum dado preenchido na prévia.';

		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			message
		);
		expect( screen.getAllByText( message ) ).toHaveLength( 2 );

		click.mockRestore();
	} );
} );

describe( 'announcing an undo', () => {
	it( 'says which edit was undone, as the design does', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		await duplicateField( user, 'CPF' );

		await user.click(
			screen.getByRole( 'button', { name: 'Desfazer alteração' } )
		);

		expect(
			await screen.findAllByText( 'Desfeito: Duplicar campo.' )
		).toHaveLength( 2 );

		// And the other direction names the same edit.
		await user.click(
			screen.getByRole( 'button', { name: 'Refazer alteração' } )
		);

		expect(
			await screen.findAllByText( 'Refeito: Duplicar campo.' )
		).toHaveLength( 2 );
	} );
} );

describe( 'a bulk action with consequences', () => {
	/**
	 * A draft where one field's rule reads another.
	 *
	 * @return {any} Document.
	 */
	function dependent() {
		return doc( [
			field(),
			field( {
				id: 'billing_ie',
				label: 'IE',
				integration_id: 'wc-checkoutsuite/billing_ie',
				position: 20,
				conditions: {
					all: [
						{
							source: 'field',
							operator: 'not_empty',
							field: 'billing_document',
						},
					],
				},
			} ),
		] );
	}

	it( 'waits for the merchant to accept what the action leaves behind', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client( { draft: dependent() } ) } /> );

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Desativar' } ) );

		// The design asks for the acknowledgement, and names the field it costs.
		const check = await screen.findByRole( 'checkbox', {
			name: /Autorizo também deixar sem origem a regra de IE\./,
		} );

		expect( check ).not.toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Confirmar' } )
		).toBeDisabled();

		await user.click( check );

		expect(
			screen.getByRole( 'button', { name: 'Confirmar' } )
		).toBeEnabled();

		await user.click( screen.getByRole( 'button', { name: 'Confirmar' } ) );

		expect(
			await screen.findAllByText(
				'1 campo(s) desativado(s) no rascunho.'
			)
		).toHaveLength( 2 );
	} );

	it( 'asks for nothing when no rule reads the field', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Selecionar CPF' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Desativar' } ) );

		expect(
			globalThis.document.querySelector( '.confirm-check' )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Confirmar' } )
		).toBeEnabled();
	} );
} );

describe( 'the row menu', () => {
	it( 'closes with Escape and gives the focus back', async () => {
		const user = userEvent.setup();

		render( <FieldsScreen client={ client() } /> );

		await screen.findByText( 'CPF' );

		await user.click(
			screen.getByRole( 'button', { name: 'Ações de CPF' } )
		);

		expect(
			screen.getByRole( 'button', { name: 'Editar campo' } )
		).toBeInTheDocument();

		await user.keyboard( '{Escape}' );

		await waitFor( () =>
			expect(
				screen.queryByRole( 'button', { name: 'Editar campo' } )
			).not.toBeInTheDocument()
		);

		// The button that opened it takes the focus back: without that, the next Tab
		// starts from the top of the screen and the merchant loses their place.
		await waitFor( () =>
			expect(
				globalThis.document.getElementById(
					'wccs-menu-billing_document'
				)
			).toHaveFocus()
		);
	} );
} );
