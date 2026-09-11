/**
 * Bulk action tests.
 *
 * The two things worth defending here are the ones the acceptance names. First,
 * a bulk action asks before it acts: ROADMAP.md section 436 asks for operations
 * "com confirmação de impacto". Second, the confirmation says what the impact
 * actually is — which fields will change and, more importantly, which will be
 * left alone and why. A dialog that only counts the selection would let a
 * merchant agree to something other than what happens.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import BulkActions from '../../../resources/admin/app/components/BulkActions';

/**
 * Builds a field.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field definition.
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
function doc( fields ) {
	return { revision: 1, fields, sections: [], settings: {} };
}

/**
 * Renders the bar with a spy.
 *
 * @param {Object} overrides Props to override.
 * @return {any} Render result and the apply spy.
 */
function renderBar( overrides = {} ) {
	const onApply = jest.fn();
	const onClear = jest.fn();

	const result = render(
		<BulkActions
			document={ doc( [ field() ] ) }
			selected={ [ 'billing_document' ] }
			sections={ [
				{ key: 'billing', label: 'Billing' },
				{ key: 'shipping', label: 'Shipping' },
			] }
			audiences={ [
				{ value: 'admin_order', label: 'Order screen, for staff' },
				{ value: 'public_api', label: 'Store API and webhooks' },
			] }
			onApply={ onApply }
			onClear={ onClear }
			{ ...overrides }
		/>
	);

	return { ...result, onApply, onClear };
}

describe( 'when nothing is selected', () => {
	it( 'renders nothing at all', () => {
		const { container } = renderBar( { selected: [] } );

		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'the bar', () => {
	it( 'names the group for assistive technology', () => {
		renderBar();

		expect(
			screen.getByRole( 'group', { name: 'Bulk actions' } )
		).toBeInTheDocument();
	} );

	it( 'says how many fields are selected', () => {
		renderBar();

		expect( screen.getByText( '1 field(s) selected' ) ).toBeInTheDocument();
	} );

	it( 'drops the selection on request', async () => {
		const user = userEvent.setup();
		const { onClear } = renderBar();

		await user.click(
			screen.getByRole( 'button', { name: 'Clear selection' } )
		);

		expect( onClear ).toHaveBeenCalled();
	} );
} );

describe( 'confirming before acting', () => {
	it( 'asks before archiving', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );

		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( onApply ).not.toHaveBeenCalled();
	} );

	it( 'does nothing when the confirmation is cancelled', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		expect( onApply ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'applies once the confirmation is given', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );
		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Archive',
			} )
		);

		expect( onApply ).toHaveBeenCalledTimes( 1 );
		expect(
			onApply.mock.calls[ 0 ][ 0 ].document.fields[ 0 ].enabled
		).toBe( false );
	} );
} );

describe( 'the impact is stated, not counted', () => {
	it( 'says how many of the selection will change', async () => {
		const user = userEvent.setup();

		renderBar( {
			document: doc( [
				field( { id: 'a', enabled: true } ),
				field( { id: 'b', enabled: true } ),
				field( { id: 'c', enabled: true } ),
			] ),
			selected: [ 'a', 'b', 'c' ],
		} );

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );

		expect(
			screen.getByText( '3 of 3 selected field(s) will change.' )
		).toBeInTheDocument();
	} );

	it( 'names the fields WooCommerce owns instead of hiding them', async () => {
		const user = userEvent.setup();

		renderBar( {
			document: doc( [
				field( { id: 'a', enabled: true } ),
				field( { id: 'billing_first_name', origin: 'core' } ),
			] ),
			selected: [ 'a', 'billing_first_name' ],
		} );

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );

		const dialog = screen.getByRole( 'dialog' );

		expect(
			within( dialog ).getByText(
				/Left alone because WooCommerce owns them/
			)
		).toBeInTheDocument();
		expect(
			within( dialog ).getByText(
				'1 of 2 selected field(s) will change.'
			)
		).toBeInTheDocument();
	} );

	it( 'names the fields that are already in the target state', async () => {
		const user = userEvent.setup();

		renderBar( {
			document: doc( [
				field( { id: 'a', enabled: true } ),
				field( { id: 'b', enabled: false } ),
			] ),
			selected: [ 'a', 'b' ],
		} );

		await user.click( screen.getByRole( 'button', { name: 'Archive' } ) );

		expect(
			within( screen.getByRole( 'dialog' ) ).getByText(
				/Already in that state/
			)
		).toBeInTheDocument();
	} );
} );

describe( 'moving to a section', () => {
	it( 'asks with the section named', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.selectOptions(
			screen.getByLabelText( /Move to section/ ),
			'shipping'
		);
		await user.click( screen.getByRole( 'button', { name: 'Move' } ) );

		expect( screen.getByRole( 'dialog' ) ).toHaveTextContent(
			/Move the selected fields to Shipping/
		);

		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Move',
			} )
		);

		expect(
			onApply.mock.calls[ 0 ][ 0 ].document.fields[ 0 ].section
		).toBe( 'shipping' );
	} );
} );

describe( 'changing visibility', () => {
	it( 'shows for an audience', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.selectOptions(
			screen.getByLabelText( /^Visibility/ ),
			'public_api'
		);
		await user.click( screen.getByRole( 'button', { name: 'Show' } ) );
		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Show',
			} )
		);

		expect(
			onApply.mock.calls[ 0 ][ 0 ].document.fields[ 0 ].visibility
				.public_api
		).toBe( true );
	} );

	it( 'hides for an audience', async () => {
		const user = userEvent.setup();
		const { onApply } = renderBar();

		await user.click( screen.getByRole( 'button', { name: 'Hide' } ) );
		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Hide',
			} )
		);

		expect(
			onApply.mock.calls[ 0 ][ 0 ].document.fields[ 0 ].visibility
				.admin_order
		).toBe( false );
	} );
} );
