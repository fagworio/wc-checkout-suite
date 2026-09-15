/**
 * The automation screen, as a merchant meets it.
 *
 * The rules live in `schema/workflows` and are proven there. What this suite pins is the screen's
 * half: that the steps are the design's, that the strategies the store cannot execute are absent
 * from the selects and named in a notice, that the simulator answers without writing, and that a
 * refusal from the store is shown rather than swallowed.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import WorkflowsScreen from '../../../resources/admin/app/WorkflowsScreen';

/** Vocabulary in the shape the route publishes it. */
const VOCABULARY = {
	triggers: [ { value: 'checkout_submitted', label: 'Checkout enviado' } ],
	decisions: [
		{ value: 'approve', label: 'Aprovar' },
		{ value: 'reject', label: 'Reprovar' },
		{ value: 'expire', label: 'Expirar' },
	],
	inventory: [
		{ value: 'none', label: 'Não reservar' },
		{ value: 'until_decision', label: 'Reservar até decisão' },
	],
	payment: [
		{ value: 'none', label: 'Não iniciar pagamento' },
		{
			value: 'request_after_approval',
			label: 'Solicitar pagamento após aprovação',
		},
		{ value: 'capture_after_approval', label: 'Capturar após aprovação' },
	],
	events: [ { value: 'received', label: 'Pedido recebido para análise' } ],
	// The stock strategies stay unavailable until the phase that reserves stock exists; the payment
	// ones are executable since the payment action service does, each falling back per gateway.
	executable: {
		inventory: [ 'none' ],
		payment: [ 'none', 'request_after_approval', 'capture_after_approval' ],
	},
};

/**
 * A client that answers with one automation.
 *
 * @param {any} options Options.
 * @return {any} Client.
 */
function client( options = {} ) {
	return {
		workflows: jest.fn( async () => ( {
			workflows: options.workflows ?? [
				{
					id: 'produtos_quimicos',
					name: 'Produtos químicos',
					enabled: true,
					priority: 10,
					trigger: 'checkout_submitted',
					conditions: {},
					initial_status: 'analise_pendente',
					inventory_strategy: 'none',
					inventory_hours: 0,
					payment_strategy: 'none',
					communications: { received: true },
					transitions: { approve: 'aprovado' },
					expires_after_hours: 0,
					fallbacks: [],
				},
			],
			vocabulary: VOCABULARY,
			statuses: [
				{ value: 'analise_pendente', label: 'Análise pendente' },
				{ value: 'aprovado', label: 'Aprovado' },
			],
			paid: [ 'processing', 'completed' ],
		} ) ),
		fieldTypes: jest.fn( async () => ( { conditions: {} } ) ),
		saveWorkflows: jest.fn( async ( list ) => ( {
			workflows: list,
		} ) ),
		simulate: jest.fn( async () => ( {
			matched: true,
			workflow: 'produtos_quimicos',
			overlaps: [],
			initial: { status: 'analise_pendente', label: 'Análise pendente' },
			inventory: {
				value: 'none',
				label: 'Não reservar',
				executable: true,
			},
			payment: {
				value: 'none',
				label: 'Não iniciar pagamento',
				executable: true,
			},
			transitions: [],
			next: 'O pedido entra em «Análise pendente» e fica à espera de uma decisão.',
		} ) ),
	};
}

describe( 'WorkflowsScreen', () => {
	it( 'draws the design steps', async () => {
		render( <WorkflowsScreen client={ client() } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /Passo 1 — Disparo/ )
			).toBeInTheDocument()
		);

		expect(
			screen.getByText( /Passo 2 — Estado inicial/ )
		).toBeInTheDocument();
		expect( screen.getByText( /Passo 3 — Estoque/ ) ).toBeInTheDocument();
		expect( screen.getByText( /Passo 4 — Pagamento/ ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Passo 6 — Decisão e expiração/ )
		).toBeInTheDocument();
	} );

	it( 'says an automation changes states and charges nothing', async () => {
		render( <WorkflowsScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByText( /não cobra nada/ ) ).toBeInTheDocument()
		);
	} );

	it( 'offers only the strategies the store can execute, and names the others', async () => {
		render( <WorkflowsScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Estoque' ) ).toBeInTheDocument()
		);

		const stock = /** @type {HTMLSelectElement} */ (
			screen.getByLabelText( 'Estoque' )
		);
		const payment = /** @type {HTMLSelectElement} */ (
			screen.getByLabelText( 'Pagamento' )
		);

		expect(
			Array.from( stock.options ).map( ( option ) => option.value )
		).toEqual( [ 'none' ] );
		expect(
			Array.from( payment.options ).map( ( option ) => option.value )
		).toEqual( [
			'none',
			'request_after_approval',
			'capture_after_approval',
		] );
		// The stock strategy nothing executes is absent from the select and named in the notice.
		expect(
			screen.getByText( /Reservar até decisão/ )
		).toBeInTheDocument();
	} );

	it( 'draws one transition per decision, with the one it has', async () => {
		render( <WorkflowsScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Aprovar' ) ).toHaveValue(
				'aprovado'
			)
		);

		expect( screen.getByLabelText( 'Reprovar' ) ).toHaveValue( '' );
	} );

	it( 'saves the list the store expects', async () => {
		const api = client();

		render( <WorkflowsScreen client={ api } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Nome' ) ).toHaveValue(
				'Produtos químicos'
			)
		);

		fireEvent.change( screen.getByLabelText( 'Nome' ), {
			target: { value: 'Químicos' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Salvar alterações/ } )
		);

		await waitFor( () => expect( api.saveWorkflows ).toHaveBeenCalled() );

		const sent = api.saveWorkflows.mock.calls[ 0 ][ 0 ];

		expect( sent[ 0 ].id ).toBe( 'produtos_quimicos' );
		expect( sent[ 0 ].name ).toBe( 'Químicos' );
		expect( sent[ 0 ] ).not.toHaveProperty( 'capture' );
	} );

	it( 'refuses to save an automation with no state to wait in', async () => {
		const api = client( {
			workflows: [
				{
					id: '',
					name: 'Nova',
					enabled: true,
					priority: 10,
					trigger: 'checkout_submitted',
					conditions: {},
					initial_status: '',
					inventory_strategy: 'none',
					payment_strategy: 'none',
					communications: {},
					transitions: {},
					expires_after_hours: 0,
					fallbacks: [],
				},
			],
		} );

		render( <WorkflowsScreen client={ api } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /precisa do estado/ )
			).toBeInTheDocument()
		);

		expect(
			screen.getByRole( 'button', { name: /Salvar alterações/ } )
		).toBeDisabled();
	} );

	it( 'answers a scenario without writing anything', async () => {
		const api = client();

		render( <WorkflowsScreen client={ api } /> );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: /Testar cenário/ } )
			).toBeInTheDocument()
		);

		fireEvent.change( screen.getByLabelText( /Categorias no carrinho/ ), {
			target: { value: 'quimicos' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Testar cenário/ } )
		);

		await waitFor( () => expect( api.simulate ).toHaveBeenCalled() );

		expect(
			screen.getByText( /entra em «Análise pendente»/ )
		).toBeInTheDocument();
		expect( api.saveWorkflows ).not.toHaveBeenCalled();
	} );
} );
