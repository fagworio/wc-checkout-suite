/**
 * The statuses screen, as a merchant meets it.
 *
 * The rules themselves live in `schema/statuses` and are proven there. What this suite pins is the
 * screen's half: that it lists what the store configured and what WooCommerce owns as two different
 * things, that a new state can be created and named, that the identifier is shown as permanent and
 * as not-yet-assigned, that saving sends the list the store expects, and that a refusal from the
 * store is shown rather than swallowed.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import StatusesScreen from '../../../resources/admin/app/StatusesScreen';

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
		customer_label: 'Estamos a analisar',
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

/**
 * A client that answers with the given inventory.
 *
 * @param {any} options Options.
 * @return {any} Client.
 */
function client( options = {} ) {
	/** @type {any} */
	const api = {
		statuses: jest.fn(
			async () =>
				options.read ?? {
					statuses: [
						status(),
						status( {
							id: 'processing',
							label: 'Processando',
							custom: false,
						} ),
					],
					paid: [ 'processing', 'completed' ],
				}
		),
		saveStatuses: jest.fn(
			options.save ??
				( async ( /** @type {any} */ list ) => ( {
					statuses: list.map( ( /** @type {any} */ entry ) => ( {
						...entry,
						id: entry.id || 'aguardando_docum',
						custom: true,
						registered: true,
					} ) ),
					paid: [ 'processing', 'completed' ],
				} ) )
		),
	};

	if ( options.fail ) {
		api.saveStatuses = jest.fn( async () => {
			throw Object.assign( new Error( 'recusado' ), {
				payload: {
					errors: [
						{
							code: 'status_id_reserved',
							message:
								'O identificador "processing" pertence à WooCommerce.',
						},
					],
				},
			} );
		} );
	}

	return api;
}

describe( 'StatusesScreen', () => {
	it( 'lists the states the merchant configured and the ones WooCommerce owns, apart', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByText( 'Análise pendente' ) ).toBeInTheDocument()
		);

		expect( screen.getByText( 'Da WooCommerce' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Processando' ) ).toBeInTheDocument();
	} );

	it( 'states that a status does not charge anything', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByText( /não cobra nada/ ) ).toBeInTheDocument()
		);
	} );

	it( 'says where the behaviour, the rules and the payment decision live', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /motor de workflow/ )
			).toBeInTheDocument()
		);
	} );

	it( 'shows the identifier as permanent and read-only', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Slug interno' ) ).toHaveValue(
				'analise_pendente'
			)
		);

		expect( screen.getByLabelText( 'Slug interno' ) ).toHaveAttribute(
			'readonly'
		);
	} );

	it( 'creates a state that has no identifier until it is saved', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect( screen.getByText( 'Novo estado' ) ).toBeInTheDocument()
		);

		fireEvent.click( screen.getByText( 'Novo estado' ) );

		expect(
			screen.getByText( 'o identificador é criado ao guardar' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Slug interno' ) ).toHaveValue( '' );
	} );

	it( 'refuses to send a state with no name', async () => {
		const api = client();

		render( <StatusesScreen client={ api } /> );

		await waitFor( () =>
			expect( screen.getByText( 'Novo estado' ) ).toBeInTheDocument()
		);

		fireEvent.click( screen.getByText( 'Novo estado' ) );

		expect(
			screen.getByText( /Um estado precisa de um nome/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Salvar alterações/ } )
		).toBeDisabled();
	} );

	it( 'saves the list the store expects, with the identifier of each state', async () => {
		const api = client();

		render( <StatusesScreen client={ api } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Nome do estado' ) ).toHaveValue(
				'Análise pendente'
			)
		);

		fireEvent.change( screen.getByLabelText( 'Nome do estado' ), {
			target: { value: 'Em análise' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Salvar alterações/ } )
		);

		await waitFor( () => expect( api.saveStatuses ).toHaveBeenCalled() );

		const sent = api.saveStatuses.mock.calls[ 0 ][ 0 ];

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].id ).toBe( 'analise_pendente' );
		expect( sent[ 0 ].label ).toBe( 'Em análise' );
		expect( sent[ 0 ] ).not.toHaveProperty( 'capture' );
	} );

	it( 'shows what the store refused, key by key', async () => {
		render( <StatusesScreen client={ client( { fail: true } ) } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Nome do estado' ) ).toHaveValue(
				'Análise pendente'
			)
		);

		fireEvent.change( screen.getByLabelText( 'Nome do estado' ), {
			target: { value: 'Outro nome' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Salvar alterações/ } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( /pertence à WooCommerce/ )
			).toBeInTheDocument()
		);
	} );

	it( 'says whether a pre-payment state is in the paid list', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /na lista de estados pagos da WooCommerce/ )
			).toBeInTheDocument()
		);

		expect( screen.getByText( /não está na lista/ ) ).toBeInTheDocument();
	} );

	it( 'makes the colour of a state a choice, not a text field', async () => {
		render( <StatusesScreen client={ client() } /> );

		await waitFor( () =>
			expect(
				screen.getByLabelText( 'Cor do estado' )
			).toBeInTheDocument()
		);

		const select = screen.getByLabelText( 'Cor do estado' );

		expect( select.tagName ).toBe( 'SELECT' );
		expect( select ).toHaveValue( '#FF59C0' );
	} );
} );
