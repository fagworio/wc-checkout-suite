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

import FieldPicker, {
	normaliseSearch,
} from '../../../resources/admin/app/components/FieldPicker';

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
				types: [ type( 'country', 'Country', 'address' ) ],
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
			onChooseType={ onChooseType }
			onAdoptCore={ onAdoptCore }
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
	return screen.getByLabelText( /search fields/i );
}

describe( 'search normalisation', () => {
	it( 'ignores case and accents', () => {
		expect( normaliseSearch( 'Endereço' ) ).toBe( 'endereco' );
		expect( normaliseSearch( '  CPF  ' ) ).toBe( 'cpf' );
	} );
} );

describe( 'what the picker offers', () => {
	it( 'renders the categories the server sent', () => {
		renderPicker();

		expect(
			screen.getByRole( 'heading', { name: 'Text and contact' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Address' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Files' } )
		).toBeInTheDocument();
	} );

	it( 'offers no category it was not given', () => {
		renderPicker( {
			catalog: { categories: [], types: {}, presets: [] },
		} );

		expect(
			screen.queryByRole( 'heading', { name: 'Text and contact' } )
		).not.toBeInTheDocument();
	} );

	it( 'offers ready-made fields before raw types', () => {
		renderPicker();

		const presetHeading = screen.getByRole( 'heading', {
			name: 'Ready-made fields',
		} );
		const typeHeading = screen.getByRole( 'heading', {
			name: 'Text and contact',
		} );

		// 4 is DOCUMENT_POSITION_FOLLOWING. The numeric constant is used because
		// the test environment does not expose the DOM `Node` global to ESLint.
		expect( presetHeading.compareDocumentPosition( typeHeading ) ).toBe(
			4
		);
	} );

	it( 'offers the WooCommerce fields of the store', () => {
		renderPicker();

		expect(
			screen.getByRole( 'button', { name: /First name/ } )
		).toBeInTheDocument();
		const woo = screen.getByRole( 'region', {
			name: 'WooCommerce fields',
		} );

		expect(
			within( woo ).getByRole( 'button', { name: /Country \/ region/ } )
		).toBeInTheDocument();
	} );
} );

describe( 'choosing', () => {
	it( 'hands back the type key of a plain type', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await user.click( screen.getByRole( 'button', { name: /^Email/ } ) );

		expect( onChooseType ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'email', preset: null } )
		);
	} );

	it( 'hands back the preset, its type and its settings', async () => {
		const user = userEvent.setup();
		const { onChooseType } = renderPicker();

		await user.click( screen.getByRole( 'button', { name: /^CPF/ } ) );

		expect( onChooseType ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'text',
				preset: 'br.cpf',
				settings: { placeholder: '000.000.000-00' },
			} )
		);
	} );

	it( 'hands back the WooCommerce field that was chosen', async () => {
		const user = userEvent.setup();
		const { onAdoptCore } = renderPicker();

		await user.click(
			screen.getByRole( 'button', { name: /First name/ } )
		);

		expect( onAdoptCore ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'billing_first_name' } )
		);
	} );
} );

describe( 'searching', () => {
	it( 'narrows the list to what matches', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.type( searchBox(), 'coun' );

		const address = screen.getByRole( 'region', { name: 'Address' } );

		expect(
			within( address ).getByRole( 'button', { name: /Country/ } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /^Email/ } ) ).toBeNull();
	} );

	it( 'finds a type through its category name', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.type( searchBox(), 'upload' );

		expect(
			screen.getByRole( 'button', { name: /^File/ } )
		).toBeInTheDocument();
	} );

	it( 'explains itself when nothing matches', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.type( searchBox(), 'zzzz' );

		expect( screen.getByText( /Nothing matches/ ) ).toBeInTheDocument();
	} );

	it( 'can be cleared', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.type( searchBox(), 'zzzz' );
		await user.click(
			screen.getByRole( 'button', { name: /clear search/i } )
		);

		expect(
			screen.getByRole( 'button', { name: /^Email/ } )
		).toBeInTheDocument();
	} );
} );

describe( 'category filter', () => {
	it( 'is offered with an option per category plus all', () => {
		renderPicker();

		const group = screen.getByRole( 'group', { name: 'Category' } );

		expect(
			within( group )
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'All', 'Text and contact', 'Address', 'Files' ] );
	} );

	it( 'restricts the list to one category', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.click( screen.getByRole( 'button', { name: 'Address' } ) );

		const address = screen.getByRole( 'region', { name: 'Address' } );

		expect(
			within( address ).getByRole( 'button', { name: /Country/ } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /^Email/ } ) ).toBeNull();
	} );

	it( 'composes with the search term', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.click( screen.getByRole( 'button', { name: 'Address' } ) );
		await user.type( searchBox(), 'email' );

		expect( screen.getByText( /Nothing matches/ ) ).toBeInTheDocument();
	} );

	it( 'shows the category on each result when all are shown', async () => {
		const user = userEvent.setup();
		renderPicker();

		await user.click( screen.getByRole( 'button', { name: 'Address' } ) );
		await user.click( screen.getByRole( 'button', { name: 'All' } ) );

		const address = screen.getByRole( 'region', { name: 'Address' } );

		expect(
			within( address ).getByRole( 'button', { name: /Country/ } )
		).toHaveTextContent( 'address' );
	} );
} );

describe( 'when the store fields cannot be read', () => {
	it( 'says why instead of showing an empty list', () => {
		renderPicker( {
			coreFields: {
				available: false,
				reason: 'WooCommerce is not active.',
				sections: [],
				fields: [],
			},
		} );

		expect(
			screen.getByText( 'WooCommerce is not active.' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /No WooCommerce field matches/ )
		).not.toBeInTheDocument();
	} );
} );

describe( 'loading and failure', () => {
	it( 'says it is loading and offers nothing yet', () => {
		renderPicker( { busy: true } );

		expect(
			screen.getByText( /Loading the field list/ )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'group', { name: 'Category' } )
		).toBeNull();
	} );

	it( 'reports a failure to load the catalogue', () => {
		renderPicker( { error: 'The server refused the request.' } );

		expect(
			screen.getByText( 'The server refused the request.' )
		).toBeInTheDocument();
	} );
} );

describe( 'target section', () => {
	it( 'offers the section selector only when sections are given', () => {
		renderPicker();

		expect( screen.queryByLabelText( /add to section/i ) ).toBeNull();
	} );

	it( 'reports a new target section', async () => {
		const user = userEvent.setup();
		const onSectionChange = jest.fn();

		renderPicker( {
			sections: [
				{ key: 'billing', label: 'Billing' },
				{ key: 'shipping', label: 'Shipping' },
			],
			onSectionChange,
		} );

		await user.selectOptions(
			screen.getByLabelText( /add to section/i ),
			'shipping'
		);

		expect( onSectionChange ).toHaveBeenCalledWith( 'shipping' );
	} );
} );
