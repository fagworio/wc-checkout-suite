/**
 * The states an order can wait in.
 *
 * §12 is the section that configures them, and §12.4 is the rule the module is shaped by: a status
 * is a state, and nothing on it may charge anything. The specs below pin both — what a status can
 * say, and the absence of anything a gateway could read — because the day somebody adds a payment
 * field to the screen is the day the promise stops being true.
 */

import {
	PALETTE,
	coreStatuses,
	createStatus,
	customStatuses,
	isPaid,
	isPrepayment,
	payloadOf,
	removeStatus,
	statusIssues,
	updateStatus,
} from '../../../resources/admin/app/schema/statuses';

/**
 * One status as the route reports it.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Status.
 */
function status( overrides = {} ) {
	return {
		id: 'analise_pendente',
		label: 'Análise pendente',
		customer_label: '',
		colour: '#FF59C0',
		active: true,
		show_customer: true,
		show_emails: true,
		manual: true,
		prepayment: true,
		description: '',
		custom: true,
		registered: true,
		...overrides,
	};
}

describe( 'order statuses', () => {
	describe( 'the list', () => {
		it( 'separates what the merchant configured from what WooCommerce owns', () => {
			const inventory = [
				status(),
				status( {
					id: 'processing',
					label: 'Processando',
					custom: false,
				} ),
			];

			expect(
				customStatuses( inventory ).map( ( entry ) => entry.id )
			).toEqual( [ 'analise_pendente' ] );
			expect(
				coreStatuses( inventory ).map( ( entry ) => entry.id )
			).toEqual( [ 'processing' ] );
		} );

		it( 'reads a list that was not answered as empty rather than as an error', () => {
			expect(
				customStatuses( /** @type {any} */ ( undefined ) )
			).toEqual( [] );
			expect( coreStatuses( /** @type {any} */ ( undefined ) ) ).toEqual(
				[]
			);
		} );
	} );

	describe( 'editing', () => {
		it( 'creates a state that declares itself a state before payment', () => {
			const created = createStatus( 'Análise pendente' );

			expect( created.id ).toBe( '' );
			expect( created.label ).toBe( 'Análise pendente' );
			expect( created.active ).toBe( true );
			expect( created.prepayment ).toBe( true );
			expect( PALETTE.map( ( entry ) => entry.value ) ).toContain(
				created.colour
			);
		} );

		it( 'keeps the identifier when the name changes', () => {
			const renamed = updateStatus( [ status() ], 0, {
				label: 'Em análise',
			} );

			expect( renamed[ 0 ].id ).toBe( 'analise_pendente' );
			expect( renamed[ 0 ].label ).toBe( 'Em análise' );
		} );

		it( 'removes one state and leaves the rest', () => {
			const list = [ status(), status( { id: 'aprovado' } ) ];

			expect(
				removeStatus( list, 0 ).map( ( entry ) => entry.id )
			).toEqual( [ 'aprovado' ] );
		} );
	} );

	describe( 'what the screen can see wrong', () => {
		it( 'says a state needs a name', () => {
			expect( statusIssues( status( { label: '   ' } ) ) ).toHaveLength(
				1
			);
			expect( statusIssues( status( { label: '   ' } ) )[ 0 ] ).toContain(
				'nome'
			);
		} );

		it( 'says a colour that is not a colour', () => {
			expect(
				statusIssues( status( { colour: 'vermelho' } ) )
			).toHaveLength( 1 );
			expect( statusIssues( status( { colour: '' } ) ) ).toHaveLength(
				0
			);
			expect(
				statusIssues( status( { colour: '#1f6feb' } ) )
			).toHaveLength( 0 );
		} );

		it( 'says nothing about a status that is complete', () => {
			expect( statusIssues( status() ) ).toEqual( [] );
		} );
	} );

	describe( 'what goes to the store', () => {
		it( 'sends an empty identifier for a state that does not have one yet', () => {
			const payload = payloadOf( [
				createStatus( 'Aguardando documentos' ),
			] );

			expect( payload[ 0 ].id ).toBe( '' );
			expect( payload[ 0 ].label ).toBe( 'Aguardando documentos' );
		} );

		it( 'sends the identifier a state already has, and nothing else', () => {
			const payload = payloadOf( [ status() ] );

			expect( Object.keys( payload[ 0 ] ).sort() ).toEqual(
				[
					'active',
					'colour',
					'customer_label',
					'description',
					'id',
					'label',
					'manual',
					'prepayment',
					'show_customer',
					'show_emails',
				].sort()
			);
		} );

		/**
		 * **A status carries nothing a gateway reads.**
		 *
		 * §12.4: um status customizado não é por si só um comando de cobrança. The list of keys is
		 * asserted whole so that adding a payment field to the screen fails here, where the reason
		 * is written down, rather than in a store where an order got charged by an import.
		 */
		it( 'carries no payment command', () => {
			const keys = Object.keys( payloadOf( [ status() ] )[ 0 ] );

			expect( keys ).not.toContain( 'capture' );
			expect( keys ).not.toContain( 'authorize' );
			expect( keys ).not.toContain( 'gateway' );
			expect( keys ).not.toContain( 'payment_action' );
			expect( keys ).not.toContain( 'paid' );
		} );
	} );

	describe( 'the states WooCommerce considers paid', () => {
		it( 'reads the keys with and without the prefix the platform writes', () => {
			expect(
				isPaid( [ 'processing', 'completed' ], 'processing' )
			).toBe( true );
			expect( isPaid( [ 'wc-processing' ], 'processing' ) ).toBe( true );
			expect( isPaid( [ 'processing' ], 'analise_pendente' ) ).toBe(
				false
			);
		} );

		it( 'reads a status that declares itself before payment', () => {
			expect( isPrepayment( status() ) ).toBe( true );
			expect( isPrepayment( status( { prepayment: false } ) ) ).toBe(
				false
			);
		} );
	} );
} );
