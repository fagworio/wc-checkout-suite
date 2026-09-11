/**
 * Failure state tests.
 *
 * The acceptance for this task names five states that must be "tratados":
 * empty, error, network, conflict and permission. The point of the classifier is
 * that they stop looking alike — four of them used to reach the screen as
 * `error.message`, which is a string, which is the same shape for all of them.
 *
 * One rule is checked for every state, because it is the rule the whole task
 * rests on: **a failure never discards work.** ROADMAP.md section 428 says "falha
 * não descarta trabalho", and a state whose advice is to start again would break
 * it.
 */

import {
	ApiError,
	StaleResponseError,
} from '../../../resources/admin/app/api/client';
import {
	classifyFailure,
	missingExtension,
	unsupportedVersion,
} from '../../../resources/admin/app/schema/failureState';

/**
 * Builds an ApiError the way the client raises one.
 *
 * The constructor takes a single details object, not a status and a body; a
 * fixture that passed them separately would build an error whose status is
 * undefined and would quietly classify as a generic failure.
 *
 * @param {number} status HTTP status.
 * @param {Object} data   Response body data.
 * @return {ApiError} Error.
 */
function apiError( status, data = {} ) {
	return new ApiError( { status, data } );
}

describe( 'every state keeps the work', () => {
	/** @type {Array<[string, any]>} */
	const cases = [
		[ 'forbidden', apiError( 403, { code: 'wccs_forbidden' } ) ],
		[ 'conflict', apiError( 409, { current_revision: 7 } ) ],
		[ 'validation', apiError( 422, { errors: [] } ) ],
		[ 'network', apiError( 0, { code: 'network_error' } ) ],
		[ 'stale', new StaleResponseError( 'GET /schema/draft' ) ],
		[ 'error', new Error( 'boom' ) ],
		[ 'error', undefined ],
	];

	it.each( cases )( 'keeps the work for %s', ( kind, error ) => {
		const state = classifyFailure( error );

		expect( state.kind ).toBe( kind );
		expect( state.keepsWork ).toBe( true );
		expect( state.recovery ).not.toBe( '' );
	} );
} );

describe( 'the states are told apart', () => {
	it( 'names an expired session as a permission problem', () => {
		const state = classifyFailure( apiError( 403, {} ) );

		expect( state.kind ).toBe( 'forbidden' );
		expect( state.message ).toMatch( /permission|session/i );
		expect( state.recovery ).toMatch( /reload/i );
	} );

	it( 'treats a conflict as information rather than an error', () => {
		const state = classifyFailure(
			apiError( 409, { current_revision: 7 } )
		);

		expect( state.kind ).toBe( 'conflict' );
		expect( state.status ).toBe( 'warning' );
		expect( state.revision ).toBe( 7 );
		expect( state.message ).toContain( '7' );
		expect( state.message ).toMatch( /not lost/i );
	} );

	it( 'keeps per-field problems on a validation failure', () => {
		const state = classifyFailure(
			apiError( 422, {
				errors: [ { code: 'unknown_type', context: { field: 'a' } } ],
			} )
		);

		expect( state.kind ).toBe( 'validation' );
		expect( state.fields ).toHaveLength( 1 );
	} );

	it( 'reads a transport failure as the network', () => {
		const state = classifyFailure( apiError( 0, {} ) );

		expect( state.kind ).toBe( 'network' );
		expect( state.message ).toMatch( /did not get an answer/i );
	} );

	it( 'reads a superseded answer as harmless', () => {
		const state = classifyFailure( new StaleResponseError( 'key' ) );

		expect( state.kind ).toBe( 'stale' );
		expect( state.status ).toBe( 'warning' );
		expect( state.recovery ).toMatch( /nothing needs doing/i );
	} );

	it( 'falls back to a plain error for anything else', () => {
		const state = classifyFailure( new Error( 'boom' ) );

		expect( state.kind ).toBe( 'error' );
		expect( state.message ).toBe( 'boom' );
	} );

	it( 'survives being handed nothing at all', () => {
		const state = classifyFailure( undefined );

		expect( state.kind ).toBe( 'error' );
		expect( state.message ).not.toBe( '' );
	} );

	it( 'never throws while reporting a failure', () => {
		for ( const odd of [ null, 0, 'text', {}, [], Symbol( 'x' ) ] ) {
			expect( () => classifyFailure( odd ) ).not.toThrow();
		}
	} );
} );

describe( 'a missing extension', () => {
	const catalog = { types: { text: { key: 'text' } } };

	it( 'is reported for a type the catalogue does not have', () => {
		const entry = missingExtension(
			/** @type {any} */ ( {
				id: 'billing_x',
				label: 'X',
				type: 'gone.type',
			} ),
			/** @type {any} */ ( catalog )
		);

		expect( entry ).toEqual( {
			field: 'billing_x',
			label: 'X',
			type: 'gone.type',
		} );
	} );

	it( 'is not reported while the catalogue is loading', () => {
		expect(
			missingExtension(
				/** @type {any} */ ( { id: 'a', type: 'text' } ),
				null
			)
		).toBeNull();
	} );

	it( 'is not reported for a type that is registered', () => {
		expect(
			missingExtension(
				/** @type {any} */ ( { id: 'a', type: 'text' } ),
				/** @type {any} */ ( catalog )
			)
		).toBeNull();
	} );
} );

describe( 'an unsupported version', () => {
	it( 'is reported for a document written by a newer build', () => {
		const entry = unsupportedVersion(
			{ draft: { state: 'unsupported_version', stored_version: 9 } },
			'draft'
		);

		expect( entry ).toEqual( { slot: 'draft', storedVersion: 9 } );
	} );

	it( 'is not reported for an empty slot', () => {
		expect(
			unsupportedVersion(
				{ draft: { state: 'absent', stored_version: null } },
				'draft'
			)
		).toBeNull();
	} );

	it( 'is not reported for a readable slot', () => {
		expect(
			unsupportedVersion(
				{ draft: { state: 'readable', stored_version: 1 } },
				'draft'
			)
		).toBeNull();
	} );

	it( 'is not reported before the report arrives', () => {
		expect( unsupportedVersion( undefined, 'draft' ) ).toBeNull();
	} );
} );
