/**
 * Blocks registration tests.
 *
 * The registration call is the seam between this bundle and the checkout, and the
 * platform refuses it by throwing. A registration that throws is worse than one that
 * does nothing: the field never appears *and* every checkout page load carries an
 * uncaught error, which is what the browser observation found on a live store — the
 * server half had passed, because the harnesses injected the booleans and stubbed the
 * API.
 *
 * So these specs pin the contract the platform actually enforces: a name that is a
 * non-empty string, an area from the platform's own list, and one component per
 * declared field. The area list is repeated here on purpose: a test that imported the
 * list from the code under test would agree with any typo in it.
 */

import {
	PARENT_AREAS,
	parentFor,
	register,
} from '../../../resources/blocks/index';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The names WooCommerce's `innerBlockAreas` accepts, as its own build publishes them.
 *
 * @type {Array<string>}
 */
const PLATFORM_AREAS = [
	'woocommerce/checkout',
	'woocommerce/checkout-fields-block',
	'woocommerce/checkout-totals-block',
	'woocommerce/checkout-contact-information-block',
	'woocommerce/checkout-shipping-address-block',
	'woocommerce/checkout-billing-address-block',
	'woocommerce/checkout-shipping-method-block',
	'woocommerce/checkout-shipping-methods-block',
	'woocommerce/checkout-pickup-options-block',
	'woocommerce/checkout-payment-block',
];

/**
 * A field payload.
 *
 * @param {Object} overrides Overrides.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'wc-checkoutsuite/wccs_note',
		name: 'wccs_note',
		label: 'Note',
		type: 'textarea',
		control: 'textarea',
		location: 'address',
		required: false,
		section: 'wccs_fixture',
		policy: 'discard',
		...overrides,
	};
}

/**
 * A checkout API that validates what the platform validates.
 *
 * @param {Array<Object>} calls Collected registrations.
 * @return {Object} API.
 */
function api( calls ) {
	return {
		registerCheckoutBlock( /** @type {any} */ registration ) {
			const metadata = registration.metadata;

			if ( 'object' !== typeof metadata || null === metadata ) {
				throw new Error( 'metadata must be an object' );
			}

			if ( 'string' !== typeof metadata.name || '' === metadata.name ) {
				throw new Error( 'name must be a non-empty string' );
			}

			if (
				'string' !== typeof metadata.parent ||
				! PLATFORM_AREAS.includes( metadata.parent )
			) {
				throw new Error(
					`parent must be one of the platform areas, got ${ String(
						metadata.parent
					) }`
				);
			}

			calls.push( registration );
		},
	};
}

describe( 'the area a field is registered under', () => {
	it( 'maps every location the server can send to an area the platform accepts', () => {
		for ( const area of Object.values( PARENT_AREAS ) ) {
			expect( PLATFORM_AREAS ).toContain( area );
		}

		expect( parentFor( 'address' ) ).toBe(
			'woocommerce/checkout-shipping-address-block'
		);
		expect( parentFor( 'contact' ) ).toBe(
			'woocommerce/checkout-contact-information-block'
		);
		expect( parentFor( 'order' ) ).toBe(
			'woocommerce/checkout-fields-block'
		);
	} );

	it( 'falls back to the fields column rather than to nothing', () => {
		expect( parentFor( undefined ) ).toBe( PARENT_AREAS.order );
		expect( parentFor( 'something-new' ) ).toBe( PARENT_AREAS.order );
	} );
} );

describe( 'registering with the checkout API', () => {
	it( 'registers one block per field, with a name and an accepted area', () => {
		/** @type {Array<any>} */
		const calls = [];
		const fields = [
			field(),
			field( {
				id: 'wc-checkoutsuite/wccs_contact',
				name: 'wccs_contact',
				location: 'contact',
			} ),
			field( {
				id: 'wc-checkoutsuite/wccs_other',
				name: 'wccs_other',
				location: 'order',
			} ),
		];

		const outcome = register( { api: api( calls ), fields, render: null } );

		expect( outcome.reason ).toBe( '' );
		expect( calls ).toHaveLength( 3 );
		expect( calls.map( ( call ) => call.metadata.name ) ).toEqual( [
			'wc-checkoutsuite/wccs_note',
			'wc-checkoutsuite/wccs_contact',
			'wc-checkoutsuite/wccs_other',
		] );
		expect( calls.map( ( call ) => call.metadata.parent ) ).toEqual( [
			'woocommerce/checkout-shipping-address-block',
			'woocommerce/checkout-contact-information-block',
			'woocommerce/checkout-fields-block',
		] );
	} );

	it( 'keeps each registered component stable across repeated renders', () => {
		/** @type {Array<any>} */
		const calls = [];
		const fields = [
			field(),
			field( {
				id: 'wc-checkoutsuite/wccs_other',
				name: 'wccs_other',
				location: 'order',
			} ),
		];

		register( { api: api( calls ), fields } );

		const first = calls[ 0 ].component( {} );
		const second = calls[ 1 ].component( {} );

		expect( first.props.field.name ).toBe( 'wccs_note' );
		expect( second.props.field.name ).toBe( 'wccs_other' );
		expect( calls[ 0 ].component( {} ).props.field.name ).toBe(
			'wccs_note'
		);
		expect( calls[ 1 ].component( {} ).props.field.name ).toBe(
			'wccs_other'
		);
	} );

	it( 'updates the checkout extension data with the value the customer types', async () => {
		const user = userEvent.setup();
		/** @type {Array<any>} */
		const calls = [];
		/** @type {Array<any>} */
		const extensionCalls = [];
		const fields = [ field() ];

		register( { api: api( calls ), fields } );
		render(
			calls[ 0 ].component( {
				checkoutExtensionData: {
					setExtensionData: (
						/** @type {string} */ namespace,
						/** @type {string} */ key,
						/** @type {any} */ value
					) => extensionCalls.push( [ namespace, key, value ] ),
				},
			} )
		);

		await user.type( screen.getByLabelText( /Note/ ), 'sent' );

		expect( extensionCalls[ extensionCalls.length - 1 ] ).toEqual( [
			'wc-checkoutsuite',
			'wccs_note',
			'sent',
		] );
	} );

	it( 'registers nothing when there is no API, and says why instead of throwing', () => {
		const outcome = register( { api: null, fields: [ field() ] } );

		expect( outcome.registered ).toEqual( [] );
		expect( outcome.reason ).toBe( 'no_blocks_checkout_api' );
	} );

	it( 'hands the integration its own renderer when one is given', () => {
		/** @type {Array<number>} */
		const received = [];

		const outcome = register( {
			api: api( [] ),
			fields: [ field() ],
			render: ( /** @type {Array<any>} */ elements ) =>
				received.push( elements.length ),
		} );

		expect( received ).toEqual( [ 1 ] );
		expect( outcome.registered ).toEqual( [
			'wc-checkoutsuite/wccs_note',
		] );
	} );
} );
