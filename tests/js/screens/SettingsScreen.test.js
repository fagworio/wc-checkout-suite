/**
 * Settings and compatibility screen tests.
 *
 * Two properties, and they are the task's acceptance seen from the screen. The switch
 * reports what the server holds and sends one boolean when it is used — it decides
 * nothing itself, because the mode can differ from the switch (a gateway the record
 * marks unavailable makes the presentation step aside even with the switch on). And the
 * compatibility half is a read: the screen lists the gateways and says how many were
 * never homologated, and there is no control anywhere in it that could change that.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import SettingsScreen from '../../../resources/admin/app/SettingsScreen';

/**
 * A client that answers with the given state and records what it was asked.
 *
 * @param {Object} state State the server holds.
 * @return {{client: any, calls: Array<{method: string, args: Array<any>}>}} Client and log.
 */
function withState( state ) {
	/** @type {Array<{method: string, args: Array<any>}>} */
	const calls = [];
	let current = state;

	return {
		calls,
		client: {
			settings: async ( /** @type {any} */ signal ) => {
				calls.push( { method: 'settings', args: [ signal ] } );

				return current;
			},
			saveSettings: async (
				/** @type {boolean} */ enabled,
				/** @type {any} */ signal
			) => {
				calls.push( {
					method: 'saveSettings',
					args: [ enabled, signal ],
				} );
				current = { ...current, custom_checkout: enabled };

				return current;
			},
		},
	};
}

/**
 * The state this store is in: opted out, and seven gateways nobody ran.
 *
 * @param {Object} [overrides] Values to override.
 * @return {Object} State.
 */
function storeState( overrides = {} ) {
	return {
		custom_checkout: false,
		mode: 'store',
		reason: 'The custom checkout is not enabled on this store.',
		blocked_by: [],
		offered: [],
		gateways: [
			{
				id: 'ppcp-gateway',
				title: 'PayPal',
				version: '4.1.3',
				enabled: false,
				mode: 'undecided',
				withheld: [],
				tested: [],
				reason: 'This gateway has no homologation record.',
			},
			{
				id: 'bacs',
				title: 'Direct bank transfer',
				version: '1.0.0',
				enabled: false,
				mode: 'undecided',
				withheld: [],
				tested: [],
				reason: 'This gateway has no homologation record.',
			},
		],
		homologated: 0,
		undecided: 2,
		modes: [ 'decorated', 'compatible', 'unavailable', 'undecided' ],
		...overrides,
	};
}

describe( 'the settings screen', () => {
	it( 'shows the state the server holds', async () => {
		const { client } = withState( storeState() );

		render( <SettingsScreen client={ client } /> );

		await waitFor( () =>
			expect(
				screen.getByLabelText( /Custom checkout/ )
			).not.toBeChecked()
		);

		expect(
			screen.getByText( /Checkout mode: store/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /The custom checkout is not enabled/ )
		).toBeInTheDocument();
		expect( screen.getByText( /0 of 2 gateways/ ) ).toBeInTheDocument();
		expect(
			screen.getByText( /2 have never been tested/ )
		).toBeInTheDocument();
		expect( screen.getByText( 'ppcp-gateway' ) ).toBeInTheDocument();
	} );

	it( 'sends one boolean and reports what came back', async () => {
		const { client, calls } = withState( storeState() );

		render( <SettingsScreen client={ client } /> );

		const toggle = await screen.findByLabelText( /Custom checkout/ );

		fireEvent.click( toggle );

		await waitFor( () => expect( toggle ).toBeChecked() );

		expect(
			calls.filter( ( call ) => 'saveSettings' === call.method )
		).toHaveLength( 1 );
		expect(
			calls.find( ( call ) => 'saveSettings' === call.method )?.args[ 0 ]
		).toBe( true );
	} );

	it( 'reports the mode the server decided, not the switch', async () => {
		// A store with the switch on whose presentation is stepping aside anyway: the
		// screen has to say so, because the merchant's own choice is not what decides
		// the mode.
		const { client } = withState(
			storeState( {
				custom_checkout: true,
				mode: 'store',
				blocked_by: [ 'ppcp-gateway' ],
				reason: 'The custom checkout steps aside because the gateway homologation record marks these gateways unavailable: ppcp-gateway.',
			} )
		);

		render( <SettingsScreen client={ client } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( /Custom checkout/ ) ).toBeChecked()
		);

		expect(
			screen.getByText( /Checkout mode: store/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /stepping aside for: ppcp-gateway/ )
		).toBeInTheDocument();
	} );

	it( 'offers no way to change a gateway record', async () => {
		const { client } = withState( storeState() );

		render( <SettingsScreen client={ client } /> );

		await screen.findByText( /0 of 2 gateways/ );

		// The compatibility half is a read: one checkbox for the presentation and no
		// control at all over what is promised about a gateway.
		expect( screen.getAllByRole( 'checkbox' ) ).toHaveLength( 1 );
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 1 );
	} );

	it( 'is a read when it is the diagnostics section', async () => {
		const { client } = withState( storeState() );

		render( <SettingsScreen client={ client } editable={ false } /> );

		await screen.findByText( /0 of 2 gateways/ );

		expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
	} );

	it( 'says so when the state cannot be read', async () => {
		const client = {
			settings: async () => {
				throw new Error( 'offline' );
			},
			saveSettings: async () => ( {} ),
		};

		render( <SettingsScreen client={ client } /> );

		await waitFor( () =>
			expect(
				screen.getByText( /The settings could not be read/ )
			).toBeInTheDocument()
		);
	} );
} );
