/**
 * The strip of checkouts, as §6.3 draws it.
 *
 * The rules themselves live in `schema/profiles` and are proven there. What this suite pins is the
 * screen's half: that the store's own checkout is offered as a tab and said to be the store's own,
 * that a profile states the rule that selects it, that the only fallback cannot be deleted and says
 * why, that the creation modal asks for a name and where to start from, and that a minimal
 * composition is checked against what the store reports.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';

import CheckoutProfilesPanel from '../../../resources/admin/app/components/CheckoutProfilesPanel';

/**
 * A profile.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Profile.
 */
function profile( overrides = {} ) {
	return {
		id: 'digital',
		name: 'Checkout digital',
		enabled: true,
		source: 'woocommerce_current',
		priority: 10,
		fallback: false,
		conditions: {},
		sections: [ { id: 'contato', title: 'Contato', location: 'billing' } ],
		presentation: {},
		...overrides,
	};
}

/** The condition vocabulary the rule sentence needs. */
const VOCABULARY = {
	operators: [
		{
			key: 'contains',
			label: 'contains',
			takesValue: true,
			valueTypes: [ 'string' ],
			sourceTypes: [ 'string', 'list' ],
			negated: false,
		},
	],
	sources: [
		{
			key: 'cart_categories',
			label: 'Categories in the cart',
			type: 'list',
			scope: 'server',
			isReference: false,
		},
	],
};

/**
 * The whole panel, with everything stubbed.
 *
 * @param {Object} props Values to override.
 * @return {any} Render result.
 */
function renderPanel( props = {} ) {
	return render(
		<CheckoutProfilesPanel
			profiles={ [] }
			active=""
			onSelect={ () => {} }
			onCreate={ () => {} }
			onUpdate={ () => {} }
			onRemove={ () => {} }
			onMove={ () => {} }
			vocabulary={ VOCABULARY }
			fields={ [] }
			facts={ null }
			factsChecked={ false }
			refusal=""
			onDismissRefusal={ () => {} }
			{ ...props }
		/>
	);
}

describe( 'CheckoutProfilesPanel', () => {
	it( 'offers the store own checkout first, and says what it is', () => {
		renderPanel();

		const tab = screen.getByRole( 'tab', { name: /Checkout padrão/ } );

		expect( tab ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByText( /existe sempre/ ) ).toBeInTheDocument();
	} );

	it( 'keeps one active checkout in the sidebar order and protects the default', () => {
		renderPanel( {
			layout: 'sidebar',
			profiles: [
				profile(),
				profile( { id: 'b2b', name: 'Checkout B2B' } ),
			],
			active: 'digital',
		} );

		const list = screen.getByRole( 'tablist', {
			name: 'Checkouts da loja',
		} );
		const tabs = within( list ).getAllByRole( 'tab' );

		expect( tabs.map( ( tab ) => tab.textContent ) ).toEqual( [
			expect.stringContaining( 'Checkout padrão' ),
			expect.stringContaining( 'Checkout digital' ),
			expect.stringContaining( 'Checkout B2B' ),
		] );
		expect(
			tabs.filter(
				( tab ) => 'true' === tab.getAttribute( 'aria-selected' )
			)
		).toHaveLength( 1 );
		expect(
			within( list ).getByRole( 'button', { name: 'Novo checkout' } )
		).toBeInTheDocument();
	} );

	it( 'does not expose deletion for Checkout padrão', () => {
		renderPanel( {
			layout: 'sidebar',
			profiles: [ profile() ],
			active: '',
		} );

		expect(
			screen.queryByRole( 'button', { name: 'Excluir checkout' } )
		).not.toBeInTheDocument();
	} );

	it( 'lists the profiles the store has and marks the fallback', () => {
		renderPanel( {
			profiles: [
				profile(),
				profile( {
					id: 'padrao',
					name: 'Checkout B2B',
					fallback: true,
				} ),
			],
			active: 'digital',
		} );

		expect(
			screen.getByRole( 'tab', { name: /Checkout digital/ } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen.getByRole( 'tab', { name: /Checkout B2B/ } )
		).toBeInTheDocument();
	} );

	it( 'states the rule that selects the active checkout', () => {
		renderPanel( {
			profiles: [
				profile( {
					conditions: {
						source: 'cart_categories',
						operator: 'contains',
						value: 'quimicos',
					},
				} ),
			],
			active: 'digital',
		} );

		const sentence = /** @type {HTMLElement} */ (
			screen.getByText( /Usar quando:/ ).closest( 'p' )
		);

		expect( sentence ).not.toBeNull();
		expect( sentence.textContent ).toContain( 'quimicos' );
	} );

	it( 'says an unconditional checkout is the one for any cart it can be chosen by', () => {
		renderPanel( { profiles: [ profile() ], active: 'digital' } );

		expect(
			screen.getByText( /Sempre que este checkout puder ser escolhido/ )
		).toBeInTheDocument();
	} );

	it( 'asks for the two reviews of the order, not only one', () => {
		/** @type {Array<any>} */
		const moves = [];
		renderPanel( {
			profiles: [ profile() ],
			active: 'digital',
			onMove: ( /** @type {string} */ id, /** @type {number} */ delta ) =>
				moves.push( [ id, delta ] ),
		} );

		fireEvent.click( screen.getByRole( 'button', { name: /antes/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: /depois/ } ) );

		expect( moves ).toEqual( [
			[ 'digital', -1 ],
			[ 'digital', 1 ],
		] );
	} );

	it( 'refuses to delete the only fallback, and states the reason', () => {
		renderPanel( {
			profiles: [ profile( { fallback: true } ) ],
			active: 'digital',
			refusal: 'only_fallback',
		} );

		expect(
			screen.getByText( /único checkout que responde/ )
		).toBeInTheDocument();
	} );

	it( 'warns about two checkouts that answer the same cart, with the values the merchant typed', () => {
		const conditions = {
			source: 'cart_categories',
			operator: 'contains',
			value: 'quimicos',
		};

		renderPanel( {
			profiles: [
				profile( { id: 'restrito', name: 'Restrito', conditions } ),
				profile( { id: 'outro', name: 'Outro', conditions } ),
			],
			active: 'restrito',
		} );

		fireEvent.change( screen.getByLabelText( /Verificar sobreposição/ ), {
			target: { value: 'quimicos' },
		} );

		expect(
			screen.getByText( /podem ser escolhidos pelas mesmas condições/ )
		).toBeInTheDocument();
		expect( screen.getByText( /Restrito.*Outro/ ) ).toBeInTheDocument();
	} );

	it( 'creates a checkout from the name and the starting point the merchant chose', () => {
		/** @type {Array<any>} */
		const created = [];
		renderPanel( {
			onCreate: ( /** @type {any} */ choice ) => created.push( choice ),
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Novo checkout/ } )
		);

		const dialog = screen.getByRole( 'dialog', {
			hidden: true,
			name: 'Novo checkout',
		} );

		fireEvent.change( within( dialog ).getByLabelText( 'Nome' ), {
			target: { value: 'Checkout B2B' },
		} );
		fireEvent.click( within( dialog ).getByLabelText( /Checkout mínimo/ ) );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: /Criar checkout/ } )
		);

		expect( created ).toEqual( [
			{ name: 'Checkout B2B', source: 'minimal', from: null },
		] );
	} );

	it( 'does not create a checkout without a name', () => {
		/** @type {Array<any>} */
		const created = [];
		renderPanel( {
			onCreate: ( /** @type {any} */ choice ) => created.push( choice ),
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Novo checkout/ } )
		);

		const dialog = screen.getByRole( 'dialog', {
			hidden: true,
			name: 'Novo checkout',
		} );

		expect(
			within( dialog ).getByRole( 'button', { name: /Criar checkout/ } )
		).toBeDisabled();
		expect( created ).toEqual( [] );
	} );

	it( 'checks a minimal composition against what the store reports', () => {
		renderPanel( {
			profiles: [ profile( { source: 'minimal' } ) ],
			active: 'digital',
			facts: { gateway: true, taxes: true, shipping: false, legal: true },
			factsChecked: true,
		} );

		expect(
			screen.getByText( /não ignora obrigações técnicas/ )
		).toBeInTheDocument();
		expect( screen.getByText( /Entrega/ ) ).toBeInTheDocument();
	} );

	it( 'says a minimal composition that answers everything has nothing to fix', () => {
		renderPanel( {
			profiles: [ profile( { source: 'minimal' } ) ],
			active: 'digital',
			facts: { gateway: true, taxes: true, shipping: true, legal: true },
			factsChecked: true,
		} );

		expect(
			screen.getByText( /estão todos satisfeitos/ )
		).toBeInTheDocument();
	} );

	it( 'reports a required field the minimal composition left behind, by name', () => {
		renderPanel( {
			profiles: [ profile( { source: 'minimal' } ) ],
			active: 'digital',
			fields: [
				{
					id: 'licenca',
					label: 'Licença química',
					enabled: true,
					required: true,
					section: 'documentacao',
				},
			],
			facts: { gateway: true, taxes: true, shipping: true, legal: true },
			factsChecked: true,
		} );

		expect( screen.getByText( /Licença química/ ) ).toBeInTheDocument();
	} );

	it( 'does not claim a minimal composition was verified when the store was not asked', () => {
		renderPanel( {
			profiles: [ profile( { source: 'minimal' } ) ],
			active: 'digital',
			facts: null,
			factsChecked: false,
		} );

		expect(
			screen.getByText( /ainda não foram verificadas/ )
		).toBeInTheDocument();
	} );
} );
