/**
 * The archive and the rules, as the design draws them.
 *
 * Two screens that do very little and are easy to get wrong: the archive lists the fields
 * that left the form and offers to bring one back, and the rules state what the editor
 * guarantees. What is asserted here is the part that is a decision — which fields the
 * archive shows, what restoring calls, and that the rules screen says the six things
 * rather than five.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ArchiveView from '../../../resources/admin/app/views/ArchiveView';
import RulesView from '../../../resources/admin/app/views/RulesView';

/**
 * A field in the shape the document stores.
 *
 * @param {Object} overrides Overrides.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'documento',
		integration_id: 'wc-checkoutsuite/documento',
		origin: 'custom',
		type: 'text',
		label: 'Documento',
		section: 'billing',
		enabled: true,
		required: false,
		position: 10,
		layout: { desktop: 12, tablet: 12, mobile: 12 },
		settings: {},
		conditions: {},
		storage: { scope: 'order', sensitivity: 'personal' },
		visibility: { admin_order: true },
		...overrides,
	};
}

/**
 * A catalogue with just enough for the labels the screen shows.
 *
 * Typed loosely: it is a fixture, and listing every field of the real payload here would
 * be a second copy of the contract to keep in step.
 *
 * @type {any}
 */
const CATALOG = {
	types: { text: { key: 'text', label: 'Text', supports: { value: true } } },
	sectionLocations: [ { value: 'billing', label: 'Billing' } ],
};

describe( 'the archive', () => {
	it( 'lists the fields that are no longer in the form', () => {
		render(
			<ArchiveView
				fields={ [
					field( { enabled: true } ),
					field( { id: 'nota', label: 'Nota', enabled: false } ),
				] }
				catalog={ CATALOG }
				onRestore={ () => {} }
				onBack={ () => {} }
			/>
		);

		expect( screen.getByText( 'Nota' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Documento' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps WooCommerce fields out of it, because they cannot be archived', () => {
		render(
			<ArchiveView
				fields={ [
					field( {
						id: 'billing_first_name',
						label: 'First name',
						origin: 'core',
						enabled: false,
					} ),
				] }
				catalog={ CATALOG }
				onRestore={ () => {} }
				onBack={ () => {} }
			/>
		);

		expect(
			screen.getByText( 'Nenhum campo arquivado.' )
		).toBeInTheDocument();
	} );

	it( 'hands back the field that should come back', async () => {
		const user = userEvent.setup();
		const onRestore = jest.fn();

		render(
			<ArchiveView
				fields={ [
					field( { id: 'nota', label: 'Nota', enabled: false } ),
				] }
				catalog={ CATALOG }
				onRestore={ onRestore }
				onBack={ () => {} }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: /Restaurar/ } ) );

		expect( onRestore ).toHaveBeenCalledWith( 'nota' );
	} );

	it( 'offers a way back to the editor, from both states', async () => {
		const user = userEvent.setup();
		const onBack = jest.fn();

		const { unmount } = render(
			<ArchiveView
				fields={ [] }
				catalog={ CATALOG }
				onRestore={ () => {} }
				onBack={ onBack }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Voltar aos campos' } )
		);

		expect( onBack ).toHaveBeenCalledTimes( 1 );

		unmount();

		render(
			<ArchiveView
				fields={ [ field( { enabled: false } ) ] }
				catalog={ CATALOG }
				onRestore={ () => {} }
				onBack={ onBack }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: /Voltar ao editor/ } )
		);

		expect( onBack ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'the editor rules', () => {
	it( 'states each contract the editor enforces, with its source', () => {
		render( <RulesView onBack={ () => {} } /> );

		const cards = document.querySelectorAll( '.rule-card' );

		expect( cards ).toHaveLength( 6 );

		expect(
			screen.getByRole( 'heading', {
				name: 'Nativos não são apagados',
			} )
		).toBeInTheDocument();
		expect(
			within( /** @type {HTMLElement} */ ( cards[ 1 ] ) ).getByText(
				'ROADMAP / §7'
			)
		).toBeInTheDocument();
	} );

	it( 'says nothing about the Checkout Sidebar except that it is out of scope', () => {
		render( <RulesView onBack={ () => {} } /> );

		expect(
			screen.getByText( /Checkout Sidebar permanece fora do escopo/ )
		).toBeInTheDocument();
	} );
} );
