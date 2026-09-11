/**
 * Preview frame tests.
 *
 * The frame makes one promise that is easy to break silently: that what it shows
 * is a simulation rather than the live checkout, and that the device, adapter and
 * customer context it claims to be showing are actually the ones in effect. The
 * specs below check that promise through the accessibility tree and the rendered
 * structure rather than through pixel measurements, which belong to the visual
 * review in WCCS-063.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PreviewFrame from '../../../resources/admin/app/components/PreviewFrame';

/**
 * Capability entries keyed by adapter, mirroring the shape the shell passes in.
 *
 * @type {Record<string, { level: string, label: string, reason: string }[]>}
 */
const CAPABILITIES = {
	classic: [
		{
			level: 'native',
			label: 'Suite field types',
			reason: 'Rendered through the official checkout field filter.',
		},
		{
			level: 'limited',
			label: 'Core field reordering',
			reason: 'Shipping, tax and payment dependencies must be assessed first.',
		},
	],
	blocks: [
		{
			level: 'limited',
			label: 'Date field',
			reason: 'WooCommerce 11.1.0 registers only text, select and checkbox.',
		},
		{
			level: 'unsupported',
			label: 'File upload',
			reason: 'The additional-fields API is not a binary upload channel.',
		},
	],
};

/**
 * Returns the previewed surface element.
 *
 * @param {*} container Render container.
 * @return {*} The surface element.
 */
function surface( container ) {
	return container.querySelector( '.wccs-preview__surface' );
}

describe( 'PreviewFrame', () => {
	it( 'marks itself as a preview at all times', () => {
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		expect( screen.getByText( 'Preview' ) ).toBeInTheDocument();
	} );

	it( 'states that the values are synthetic and the live checkout is untouched', () => {
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		const statement = screen.getByText( /simulation/i );

		expect( statement ).toHaveTextContent( /placeholder values/i );
		expect( statement ).toHaveTextContent(
			/never touches the live checkout/i
		);
	} );

	it( 'lays the preview out at the selected device width', async () => {
		const user = userEvent.setup();
		const { container } = render(
			<PreviewFrame capabilities={ CAPABILITIES } />
		);

		expect( surface( container ) ).toHaveAttribute(
			'data-viewport',
			'desktop'
		);

		await user.click( screen.getByRole( 'button', { name: 'Mobile' } ) );

		expect( surface( container ) ).toHaveAttribute(
			'data-viewport',
			'mobile'
		);
	} );

	it( 'offers the three device classes', () => {
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		const devices = screen.getByRole( 'group', { name: 'Device' } );

		expect(
			within( devices )
				.getAllByRole( 'button' )
				.map( ( b ) => b.textContent )
		).toEqual( [ 'Desktop', 'Tablet', 'Mobile' ] );
	} );

	it( 'reports the selected option to assistive technology', async () => {
		const user = userEvent.setup();
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		const devices = screen.getByRole( 'group', { name: 'Device' } );
		const desktop = within( devices ).getByRole( 'button', {
			name: 'Desktop',
		} );
		const mobile = within( devices ).getByRole( 'button', {
			name: 'Mobile',
		} );

		expect( desktop ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( mobile ).toHaveAttribute( 'aria-pressed', 'false' );

		await user.click( mobile );

		expect( mobile ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( desktop ).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	it( 'shows the compatibility matrix of the adapter in use', () => {
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		const matrix = screen.getByRole( 'list' );

		expect(
			within( matrix )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'Suite field typesNativeRendered through the official checkout field filter.',
			'Core field reorderingLimitedShipping, tax and payment dependencies must be assessed first.',
		] );
	} );

	it( 'replaces the matrix when the adapter changes', async () => {
		const user = userEvent.setup();
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		expect( screen.queryByText( 'File upload' ) ).not.toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Blocks' } ) );

		expect( screen.getByText( 'File upload' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Suite field types' )
		).not.toBeInTheDocument();
	} );

	it( 'says the result is unverified when the adapter has no matrix entry', async () => {
		const user = userEvent.setup();
		render(
			<PreviewFrame capabilities={ { classic: CAPABILITIES.classic } } />
		);

		expect( screen.queryAllByRole( 'alert' ) ).toHaveLength( 0 );

		await user.click( screen.getByRole( 'button', { name: 'Blocks' } ) );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			/unverified/i
		);
		expect( screen.queryByRole( 'list' ) ).not.toBeInTheDocument();
	} );

	it( 'flags an adapter that declares no limitations at all', () => {
		render( <PreviewFrame capabilities={ { classic: [], blocks: [] } } /> );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( /unusual/i );
	} );

	it( 'hands the whole context to the caller renderer', async () => {
		const user = userEvent.setup();
		const renderPreview = jest.fn( () => <p>Custom preview</p> );

		render(
			<PreviewFrame
				capabilities={ CAPABILITIES }
				renderPreview={ renderPreview }
			/>
		);

		expect( renderPreview ).toHaveBeenLastCalledWith( {
			viewport: 'desktop',
			adapter: 'classic',
			personType: 'pf',
			customer: 'guest',
		} );

		await user.click( screen.getByRole( 'button', { name: 'Mobile' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Blocks' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Company' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Signed in' } ) );

		expect( renderPreview ).toHaveBeenLastCalledWith( {
			viewport: 'mobile',
			adapter: 'blocks',
			personType: 'pj',
			customer: 'logged-in',
		} );

		expect( screen.getByText( 'Custom preview' ) ).toBeInTheDocument();
	} );

	it( 'falls back to the synthetic sample when no renderer is given', () => {
		const { container } = render(
			<PreviewFrame capabilities={ CAPABILITIES } />
		);

		expect(
			container.querySelector( '.wccs-preview__sample' )
		).toBeInTheDocument();
	} );

	it( 'changes the sample documents with the person type', async () => {
		const user = userEvent.setup();
		render( <PreviewFrame capabilities={ CAPABILITIES } /> );

		expect( screen.getByLabelText( /CPF/ ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( /CNPJ/ ) ).not.toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Company' } ) );

		expect( screen.getByLabelText( /CNPJ/ ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( /CPF/ ) ).not.toBeInTheDocument();
	} );

	it( 'does not read anything from the network', async () => {
		const user = userEvent.setup();
		const original = globalThis.fetch;
		globalThis.fetch = jest.fn();

		try {
			render( <PreviewFrame capabilities={ CAPABILITIES } /> );

			await user.click(
				screen.getByRole( 'button', { name: 'Mobile' } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Blocks' } )
			);

			expect( globalThis.fetch ).not.toHaveBeenCalled();
		} finally {
			globalThis.fetch = original;
		}
	} );
} );
