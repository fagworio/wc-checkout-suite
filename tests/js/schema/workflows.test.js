/**
 * The automations a store runs.
 *
 * §13 is the section that configures them, and the phase's gate is an absence: nothing here charges
 * anything. The specs below pin the two halves of that — what an automation can say, and the fact
 * that the strategies the store cannot execute are not offered.
 */

import {
	createWorkflow,
	decisionRows,
	executableOptions,
	payloadOf,
	removeWorkflow,
	unavailableOptions,
	updateWorkflow,
	workflowIssues,
} from '../../../resources/admin/app/schema/workflows';

/** Vocabulary in the shape the route publishes it. */
const VOCABULARY = {
	triggers: [ { value: 'checkout_submitted', label: 'Checkout enviado' } ],
	decisions: [
		{ value: 'approve', label: 'Aprovar' },
		{ value: 'request_correction', label: 'Solicitar correção' },
		{ value: 'reject', label: 'Reprovar' },
		{ value: 'expire', label: 'Expirar' },
	],
	inventory: [
		{ value: 'none', label: 'Não reservar' },
		{ value: 'until_decision', label: 'Reservar até decisão' },
		{ value: 'hours', label: 'Reservar por X horas' },
	],
	payment: [
		{ value: 'none', label: 'Não iniciar pagamento' },
		{ value: 'capture_after_approval', label: 'Capturar após aprovação' },
	],
	events: [
		{ value: 'received', label: 'Pedido recebido para análise' },
		{ value: 'approved', label: 'Aprovação' },
	],
	executable: {
		inventory: [ 'none' ],
		payment: [ 'none' ],
	},
};

/**
 * One automation as the route reports it.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Workflow.
 */
function workflow( overrides = {} ) {
	return {
		...createWorkflow( 'Produtos químicos' ),
		id: 'produtos_quimicos',
		initial_status: 'analise_pendente',
		...overrides,
	};
}

describe( 'workflows', () => {
	describe( 'creating one', () => {
		it( 'starts without an identifier, because the store assigns it', () => {
			const created = createWorkflow( 'Produtos químicos' );

			expect( created.id ).toBe( '' );
			expect( created.name ).toBe( 'Produtos químicos' );
			expect( created.trigger ).toBe( 'checkout_submitted' );
		} );

		it( 'starts doing nothing to stock and nothing to payment', () => {
			const created = createWorkflow( 'X' );

			expect( created.inventory_strategy ).toBe( 'none' );
			expect( created.payment_strategy ).toBe( 'none' );
		} );

		it( 'keeps what changed and leaves the rest', () => {
			const renamed = updateWorkflow( [ workflow() ], 0, {
				name: 'Químicos',
			} );

			expect( renamed[ 0 ].id ).toBe( 'produtos_quimicos' );
			expect( renamed[ 0 ].name ).toBe( 'Químicos' );
		} );

		it( 'removes one and leaves the rest', () => {
			const list = [ workflow(), workflow( { id: 'outro' } ) ];

			expect(
				removeWorkflow( list, 0 ).map( ( entry ) => entry.id )
			).toEqual( [ 'outro' ] );
		} );
	} );

	describe( 'what the store cannot execute is not offered', () => {
		it( 'offers only the strategies that can run', () => {
			expect(
				executableOptions( VOCABULARY, 'inventory' ).map(
					( entry ) => entry.value
				)
			).toEqual( [ 'none' ] );
			expect(
				executableOptions( VOCABULARY, 'payment' ).map(
					( entry ) => entry.value
				)
			).toEqual( [ 'none' ] );
		} );

		it( 'names the ones it is not offering, so the screen can say why', () => {
			expect(
				unavailableOptions( VOCABULARY, 'inventory' ).map(
					( entry ) => entry.value
				)
			).toEqual( [ 'until_decision', 'hours' ] );
			expect(
				unavailableOptions( VOCABULARY, 'payment' ).map(
					( entry ) => entry.value
				)
			).toEqual( [ 'capture_after_approval' ] );
		} );

		it( 'reads a vocabulary that was not answered as empty rather than as an error', () => {
			expect( executableOptions( {}, 'payment' ) ).toEqual( [] );
			expect( unavailableOptions( {}, 'payment' ) ).toEqual( [] );
		} );
	} );

	describe( 'what the screen can see wrong', () => {
		it( 'says an automation needs a name', () => {
			expect( workflowIssues( workflow( { name: '  ' } ) ) ).toHaveLength(
				1
			);
		} );

		it( 'says an automation needs a state to wait in', () => {
			expect(
				workflowIssues( workflow( { initial_status: '' } ) )[ 0 ]
			).toContain( 'espera' );
		} );

		it( 'says an expiration needs both halves', () => {
			expect(
				workflowIssues( workflow( { expires_after_hours: 72 } ) )[ 0 ]
			).toContain( 'expira' );
			expect(
				workflowIssues(
					workflow( {
						transitions: { expire: 'expirado' },
					} )
				)[ 0 ]
			).toContain( 'horas' );
		} );

		it( 'says a stock reservation by the hour needs its number', () => {
			expect(
				workflowIssues(
					workflow( {
						inventory_strategy: 'hours',
						inventory_hours: 0,
					} )
				)[ 0 ]
			).toContain( 'número de horas' );

			expect(
				workflowIssues(
					workflow( {
						inventory_strategy: 'hours',
						inventory_hours: 6,
					} )
				)
			).toEqual( [] );
		} );

		it( 'says nothing about an automation that is complete', () => {
			expect(
				workflowIssues(
					workflow( {
						expires_after_hours: 72,
						transitions: { expire: 'expirado' },
					} )
				)
			).toEqual( [] );
		} );
	} );

	describe( 'what goes to the store', () => {
		it( 'sends an empty identifier for an automation that has none', () => {
			expect( payloadOf( [ createWorkflow( 'Nova' ) ] )[ 0 ].id ).toBe(
				''
			);
		} );

		it( 'sends the whole definition the store validates', () => {
			expect(
				Object.keys( payloadOf( [ workflow() ] )[ 0 ] ).sort()
			).toEqual(
				[
					'communications',
					'conditions',
					'enabled',
					'expires_after_hours',
					'fallbacks',
					'id',
					'initial_status',
					'inventory_hours',
					'inventory_strategy',
					'name',
					'payment_strategy',
					'priority',
					'trigger',
					'transitions',
				].sort()
			);
		} );

		/**
		 * **An automation carries no payment command.**
		 *
		 * §12.4 and the gate of this phase: the strategy says what should happen, and what happens
		 * is a payment action with a gateway capability. There is no field here that charges, and
		 * this is where adding one fails.
		 */
		it( 'carries no payment command', () => {
			const keys = Object.keys( payloadOf( [ workflow() ] )[ 0 ] );

			expect( keys ).not.toContain( 'capture' );
			expect( keys ).not.toContain( 'authorize' );
			expect( keys ).not.toContain( 'charge' );
			expect( keys ).not.toContain( 'gateway' );
		} );
	} );

	describe( 'the decisions the editor draws', () => {
		it( 'draws one row per decision, with the transition it has', () => {
			const rows = decisionRows(
				VOCABULARY,
				[],
				workflow( {
					transitions: { approve: 'aprovado' },
				} )
			);

			expect( rows.map( ( row ) => row.value ) ).toEqual( [
				'approve',
				'request_correction',
				'reject',
				'expire',
			] );
			expect( rows[ 0 ].status ).toBe( 'aprovado' );
			expect( rows[ 1 ].status ).toBe( '' );
		} );

		it( 'offers the statuses the store has for every decision', () => {
			const rows = decisionRows(
				VOCABULARY,
				[ { value: 'aprovado', label: 'Aprovado' } ],
				workflow()
			);

			expect( rows[ 0 ].options ).toEqual( [
				{ value: 'aprovado', label: 'Aprovado' },
			] );
		} );
	} );
} );
