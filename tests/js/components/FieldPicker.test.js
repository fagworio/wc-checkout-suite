/**
 * Field picker tests.
 *
 * The picker is the only place a merchant chooses what to add, so what it offers
 * is a product decision with consequences. These specs pin down the three that
 * matter: the list comes from the server rather than from a hard-coded copy, the
 * search survives accents and composes with the category filter, and a
 * WooCommerce inventory that could not be read says so instead of looking like a
 * store with no core fields.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FieldPicker from '../../../resources/admin/app/components/FieldPicker';

/**
 * Builds a catalogue in the shape the server sends.
 *
 * @return {any} Catalogue.
 */
function catalog() {
	/**
	 * Builds one catalogue entry.
	 *
	 * @param {string} key      Type key.
	 * @param {string} label    Type label.
	 * @param {string} category Category key.
	 * @return {any} Catalogue entry.
	 */
	const type = ( key, label, category ) => ( {
		key,
		label,
		category,
		source: 'core',
		contractVersion: '1.0',
		supports: { value: true },
		valueSchema: { type: 'string' },
		settingsSchema: {},
	} );

	return {
		categories: [
			{
				key: 'text',
				label: 'Text and contact',
				types: [
					type( 'text', 'Text', 'text' ),
					type( 'email', 'Email', 'text' ),
				],
			},
			{
				key: 'address',
				label: 'Address',
				types: [
					type( 'country', 'Country', 'address' ),
					type( 'address_line', 'Endereço', 'address' ),
				],
			},
			{
				key: 'upload',
				label: 'Files',
				types: [ type( 'file', 'File', 'upload' ) ],
			},
		],
		types: {},
		presets: [
			{
				key: 'br.cpf',
				label: 'CPF',
				type: 'text',
				defaults: { label: 'CPF' },
				settings: { placeholder: '000.000.000-00' },
				group: 'br',
				enabled: true,
			},
			{
				key: 'br.cep',
				label: 'CEP',
				type: 'text',
				defaults: { label: 'CEP' },
				settings: { placeholder: '00000-000' },
				group: 'br',
				enabled: true,
			},
		],
	};
}

/**
 * Builds a core field inventory.
 *
 * @return {any} Inventory.
 */
function inventory() {
	return {
		available: true,
		reason: '',
		sections: [],
		fields: [
			{
				id: 'billing_first_name',
				section: 'billing',
				label: 'First name',
				type: 'text',
				required: true,
				priority: 10,
				layout: { desktop: 6, tablet: 6, mobile: 12 },
			},
			{
				id: 'billing_country',
				section: 'billing',
				label: 'Country / region',
				type: 'country',
				required: true,
				priority: 20,
				layout: { desktop: 12, tablet: 12, mobile: 12 },
			},
		],
	};
}

/**
 * Renders the picker with spies.
 *
 * @param {Object} overrides Properties to override.
 * @return {any} Render result and spies.
 */
function renderPicker( overrides = {} ) {
	const onChooseType = jest.fn();
	const onAdoptCore = jest.fn();

	const result = render(
		<FieldPicker
			catalog={ catalog() }
			coreFields={ inventory() }
			section="billing"
			sections={ [
				{ id: 'billing', label: 'Billing' },
				{ id: 'shipping', label: 'Shipping' },
			] }
			onSectionChange={ () => {} }
			onChooseType={ onChooseType }
			onAdoptCore={ onAdoptCore }
			onClose={ () => {} }
			{ ...overrides }
		/>
	);

	return { ...result, onChooseType, onAdoptCore };
}

/**
 * Returns the picker's search input.
 *
 * @return {*} Input element.
 */
function searchBox() {
	return screen.getByLabelText( 'Buscar um tipo de campo' );
}

/**
 * Chooses one entry from the grid and confirms it.
 *
 * The design's picker has two steps — pick, then configure — so a test that wants the
 * callback walks both, which is what a merchant does.
 *
 * @param {any}    user  user-event instance.
 * @param {string} label Entry label.
 * @return {Promise<void>} Resolves after the field was added.
 */
async function addField( user, label ) {
	await openConfigure( user, label );
	await user.click(
		screen.getByRole( 'button', { name: 'Adicionar campo' } )
	);
}

/**
 * Opens the configure step for one entry without confirming it.
 *
 * @param {any}    user  user-event instance.
 * @param {string} label Entry label.
 * @return {Promise<void>} Resolves when the configure step is open.
 */
async function openConfigure( user, label ) {
	await user.click(
		within( grid() ).getByRole( 'button', {
			name: new RegExp( `^${ label }` ),
		} )
	);
}

/**
 * The category rail.
 *
 * The cards name their category too, so a query for a category has to be scoped: the
 * rail and the grid are two lists that share a vocabulary.
 *
 * @return {*} Rail element.
 */
function rail() {
	return screen.getByRole( 'navigation', {
		name: 'Categorias de campos',
	} );
}

/**
 * The grid of entries.
 *
 * @return {*} Grid element.
 */
function grid() {
	return document.querySelector( '.picker-grid' );
}
describe( 'what the picker offers', () => {
	it( 'renders a category per group the server sent, plus all of them', () => {
		renderPicker();

		const categories = within( rail() );

		expect(
			categories.getByRole( 'button', { name: /Todos os campos/ } )
		).toBeInTheDocument();
		expect(
			categories.getByRole( 'button', { name: /Text and contact/ } )
		).toBeInTheDocument();
		expect(
			categories.getByRole( 'button', { name: /Address/ } )
		).toBeInTheDocument();
		expect(
			categories.getByRole( 'button', { name: /Files/ } )
		).toBeInTheDocument();
	} );

	it( 'offers no category it was not given', () => {
		renderPicker( { catalog: { categories: [], types: {}, presets: [] } } );

		expect(
			within( rail() ).queryByRole( 'button', {
				name: /Text and contact/,
			} )
		).not.toBeInTheDocument();
	} );

	it( 'counts what each category holds', () => {
		renderPicker();

		expect(
			within(
				within( rail() ).getByRole( 'button', {
					name: /Text and contact/,
				} )
			).getByText( '2' )
		).toBeInTheDocument();
	} );

	it( 'offers the WooCommerce fields of the store as their own category', () => {
		renderPicker();

		expect(
			within( rail() ).getByRole( 'button', {
				name: /Campos da WooCommerce/,
			} )
		).toBeInTheDocument();
	} );

	it( 'offers the presets the server sent as a category', () => {
		renderPicker();

		expect(
			within( rail() ).getByRole( 'button', {
				name: /Presets Brasil/,
			} )
		).toBeInTheDocument();
	} );

	it( 'shows every entry when nothing is filtered', () => {
		renderPicker();

		expect( within( grid() ).getAllByRole( 'button' ).length ).toBe( 9 );
	} );
} );

describe( 'searching and filtering', () => {
	it( 'narrows the list to what matches, ignoring case and accents', async () => {
		const user = userEvent.setup();

		renderPicker();

		await user.type( searchBox(), 'endereco' );

		expect(
			within( grid() ).getByRole( 'button', { name: /Endereço/ } )
		).toBeInTheDocument();
		expect(
			within( grid() ).queryByRole( 'button', { name: /Email/ } )
		).not.toBeInTheDocument();
	} );

	it( 'finds an entry through its category name', async () => {
		const user = userEvent.setup();

		renderPicker();

		await user.type( searchBox(), 'files' );

		expect(
			within( grid() ).getByRole( 'button', { name: /File/ } )
		).toBeInTheDocument();
	} );

	it( 'explains itself when nothing matches', async () => {
		const user = userEvent.setup();

		renderPicker();

		await user.type( searchBox(), 'zzzz' );

		expect(
			screen.getByText( 'Nenhum tipo encontrado.' )
		).toBeInTheDocument();
	} );

	it( 'restricts the list to one category', async () => {
		const user = userEvent.setup();

		renderPicker();

		await user.click(
			within( rail() ).getByRole( 'button', { name: /Address/ } )
		);

		expect(
			within( grid() ).getByRole( 'button', { name: /Country/ } )
		).toBeInTheDocument();
		expect(
			within( grid() ).queryByRole( 'button', { name: /Email/ } )
		).not.toBeInTheDocument();
	} );

	it( 'composes the category with the search term', async () => {
		const user = userEvent.setup();

		renderPicker();

		await user.click(
			within( rail() ).getByRole( 'button', { name: /Address/ } )
		);
		await user.type( searchBox(), 'email' );

		expect(
			screen.getByText( 'Nenhum tipo encontrado.' )
		).toBeInTheDocument();
	} );
} );

describe( 'choosing', () => {
	it( 'shows the captured checkout section without offering a second destination', async () => {
		const user = userEvent.setup();

		renderPicker( {
			lockedSection: true,
			sectionLabel: 'Cobrança',
		} );
		await openConfigure( user, 'Text' );

		expect( screen.queryByLabelText( 'Seção' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Cobrança' ) ).toBeInTheDocument();
	} );

	it( 'hands back the type key, with the key the merchant accepted', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await addField( user, 'Text' );

		expect( onChooseType ).toHaveBeenCalledTimes( 1 );
		expect( onChooseType.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			type: 'text',
			label: 'Text',
			idHint: 'text',
			section: 'billing',
			layout: { desktop: 12 },
		} );
	} );

	it( 'lets the name and the key be changed before adding', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await openConfigure( user, 'Text' );

		const name = screen.getByLabelText( 'Nome do campo *' );
		const key = screen.getByLabelText( 'Chave de integração *' );

		await user.clear( name );
		await user.type( name, 'Documento' );
		await user.clear( key );
		await user.type( key, 'documento' );

		await user.click(
			screen.getByRole( 'button', { name: 'Adicionar campo' } )
		);

		expect( onChooseType.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			type: 'text',
			label: 'Documento',
			idHint: 'documento',
		} );
	} );

	it( 'hands back the preset, its type and its settings', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await addField( user, 'CPF' );

		const choice = onChooseType.mock.calls[ 0 ][ 0 ];

		expect( choice.type ).toBe( 'text' );
		expect( choice.preset ).toBe( 'br.cpf' );
		expect( choice.settings ).toEqual( {
			placeholder: '000.000.000-00',
		} );
	} );

	it( 'adopts a WooCommerce field without a configure step', async () => {
		const user = userEvent.setup();
		const { onAdoptCore, onChooseType } = renderPicker();

		await user.click(
			within( rail() ).getByRole( 'button', {
				name: /Campos da WooCommerce/,
			} )
		);
		await user.click(
			within( grid() ).getByRole( 'button', { name: /Country/ } )
		);

		expect( onAdoptCore ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'billing_country' } )
		);
		expect( onChooseType ).not.toHaveBeenCalled();
	} );

	it( 'offers to go back from the configure step without adding anything', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await openConfigure( user, 'Text' );
		await user.click( screen.getByRole( 'button', { name: 'Voltar' } ) );

		expect(
			within( rail() ).getByRole( 'button', { name: /Todos os campos/ } )
		).toBeInTheDocument();
		expect( onChooseType ).not.toHaveBeenCalled();
	} );
} );

describe( 'when the store fields cannot be read', () => {
	it( 'says why instead of looking like a store with no fields', () => {
		renderPicker( {
			coreFields: {
				available: false,
				reason: 'WooCommerce is not active.',
				sections: [],
				fields: [],
			},
		} );

		expect(
			screen.getByText( /WooCommerce is not active/ )
		).toBeInTheDocument();
		expect(
			within( rail() ).queryByRole( 'button', {
				name: /Campos da WooCommerce/,
			} )
		).not.toBeInTheDocument();
	} );
} );
