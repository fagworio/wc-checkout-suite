/**
 * REST client tests.
 *
 * The behaviour under test is the contract with the server: what is sent, how
 * the answer is classified, and — the part that matters most — when a request is
 * repeated and when it is not.
 */

import {
	ApiError,
	StaleResponseError,
	createClient,
} from '../../../resources/admin/app/api/client';

/**
 * Builds a fetch response with a JSON body.
 *
 * @param {number} status HTTP status.
 * @param {Object} body   Body to serialise.
 * @return {Object} Response-like object.
 */
function jsonResponse( status, body ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: async () => ( undefined === body ? '' : JSON.stringify( body ) ),
	};
}

/**
 * Builds a fetch stub that pops queued answers and records every call.
 *
 * @param {any[]} queue Answers: a response object, or an Error to throw.
 * @return {Function} Fetch implementation with a `calls` array.
 */
function queuedFetch( queue ) {
	const impl = jest.fn( async () => {
		const next = queue.shift();

		if ( next instanceof Error ) {
			throw next;
		}

		if ( 'function' === typeof next ) {
			return next();
		}

		return next;
	} );

	return impl;
}

/**
 * Builds a client with test doubles.
 *
 * @param {any[]}  queue   Answers for fetch.
 * @param {Object} options Extra client options.
 * @return {any} Client and its spies.
 */
function buildClient( queue, options = {} ) {
	const fetchImpl = queuedFetch( queue );
	const sleep = jest.fn( async () => {} );

	// The fixture mirrors what the server actually prints: `rest_url()` stops at
	// `/wp-json`, and the namespace is published separately. An earlier version
	// of this fixture baked the namespace into the root, which is exactly why a
	// client that never joined the two kept passing its tests while asking the
	// site for `/wp-json/schema/draft`.
	const client = createClient( {
		root: 'https://example.test/wp-json/',
		namespace: 'wc-checkoutsuite/v1',
		nonce: 'nonce-value',
		routes: {
			draft: '/schema/draft',
			publish: '/schema/publish',
			revisions: '/schema/revisions',
			restore: '/schema/restore',
		},
		fetchImpl,
		sleep,
		...options,
	} );

	return { client, fetchImpl, sleep };
}

describe( 'createClient', () => {
	it( 'refuses to build without a nonce', () => {
		expect( () =>
			createClient( {
				root: 'https://example.test',
				nonce: '',
				routes: {},
			} )
		).toThrow( /requires a nonce/ );
	} );

	it( 'sends the nonce, credentials and a JSON body', async () => {
		const { client, fetchImpl } = buildClient( [
			jsonResponse( 200, { status: 'ok', revision: 4 } ),
		] );

		await client.saveDraft( { fields: [] }, 3 );

		const [ url, options ] = fetchImpl.mock.calls[ 0 ];

		expect( url ).toBe(
			'https://example.test/wp-json/wc-checkoutsuite/v1/schema/draft'
		);
		expect( options.method ).toBe( 'PUT' );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'nonce-value' );
		expect( JSON.parse( options.body ) ).toEqual( {
			schema: { fields: [] },
			expected_revision: 3,
		} );
	} );

	it( 'reads the draft from the route the server published', async () => {
		const { client, fetchImpl } = buildClient( [
			jsonResponse( 200, { revision: 1, fields: [] } ),
		] );

		const draft = await client.getDraft();

		expect( draft.revision ).toBe( 1 );
		expect( fetchImpl.mock.calls[ 0 ][ 0 ] ).toContain( '/schema/draft' );
		expect( fetchImpl.mock.calls[ 0 ][ 1 ].body ).toBeUndefined();
	} );
} );

describe( 'URL construction', () => {
	it( 'joins the REST root and the namespace', async () => {
		const { client, fetchImpl } = buildClient( [
			jsonResponse( 200, { revision: 0 } ),
		] );

		await client.getDraft();

		expect( fetchImpl.mock.calls[ 0 ][ 0 ] ).toBe(
			'https://example.test/wp-json/wc-checkoutsuite/v1/schema/draft'
		);
	} );

	it( 'does not double the slash when either side carries one', async () => {
		const { client, fetchImpl } = buildClient(
			[ jsonResponse( 200, { revision: 0 } ) ],
			{
				root: 'https://example.test/wp-json///',
				namespace: '/wc-checkoutsuite/v1/',
			}
		);

		await client.getDraft();

		expect( fetchImpl.mock.calls[ 0 ][ 0 ] ).toBe(
			'https://example.test/wp-json/wc-checkoutsuite/v1/schema/draft'
		);
	} );

	it( 'reaches the catalogue routes the same way', async () => {
		const { client, fetchImpl } = buildClient(
			[ jsonResponse( 200, {} ), jsonResponse( 200, {} ) ],
			{
				routes: {
					fieldTypes: '/field-types',
					coreFields: '/core-fields',
				},
			}
		);

		await client.fieldTypes();
		await client.coreFields();

		expect( fetchImpl.mock.calls[ 0 ][ 0 ] ).toBe(
			'https://example.test/wp-json/wc-checkoutsuite/v1/field-types'
		);
		expect( fetchImpl.mock.calls[ 1 ][ 0 ] ).toBe(
			'https://example.test/wp-json/wc-checkoutsuite/v1/core-fields'
		);
	} );
} );

describe( 'error classification', () => {
	it( 'marks a 403 as forbidden and keeps the machine code', async () => {
		const { client } = buildClient( [
			jsonResponse( 403, {
				code: 'wccs_forbidden',
				message: 'Not allowed.',
			} ),
		] );

		const error = await client
			.getDraft()
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( error ).toBeInstanceOf( ApiError );
		expect( error.isForbidden ).toBe( true );
		expect( error.code ).toBe( 'wccs_forbidden' );
		expect( error.message ).toBe( 'Not allowed.' );
	} );

	it( 'marks a 409 as a conflict and reports the winning revision', async () => {
		const { client } = buildClient( [
			jsonResponse( 409, {
				code: 'wccs_revision_conflict',
				message: 'Another administrator changed this configuration.',
				data: { status: 409, current_revision: 7 },
			} ),
		] );

		const error = await client
			.saveDraft( {}, 3 )
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( error.isConflict ).toBe( true );
		expect( error.currentRevision ).toBe( 7 );
	} );

	it( 'marks a 422 as a validation failure and exposes the field errors', async () => {
		const { client } = buildClient( [
			jsonResponse( 422, {
				code: 'wccs_invalid_schema',
				message: 'The schema was not published.',
				data: {
					status: 422,
					errors: [
						{ code: 'unknown_type', context: { field: 'doc' } },
					],
				},
			} ),
		] );

		const error = await client
			.publish( 1 )
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( error.isValidationFailure ).toBe( true );
		expect( error.fieldErrors ).toHaveLength( 1 );
		expect( error.fieldErrors[ 0 ].code ).toBe( 'unknown_type' );
	} );

	it( 'treats a transport failure as transient', async () => {
		const { client } = buildClient(
			[
				new Error( 'network down' ),
				new Error( 'network down' ),
				new Error( 'network down' ),
			],
			{ retries: 0 }
		);

		const error = await client
			.getDraft()
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( error.status ).toBe( 0 );
		expect( error.code ).toBe( 'network_error' );
		expect( error.isTransient ).toBe( true );
	} );
} );

describe( 'safe retry', () => {
	it( 'repeats a read after a server error and then succeeds', async () => {
		const { client, fetchImpl, sleep } = buildClient( [
			jsonResponse( 500, { code: 'server_error' } ),
			jsonResponse( 200, { revision: 2 } ),
		] );

		const draft = await client.getDraft();

		expect( draft.revision ).toBe( 2 );
		expect( fetchImpl ).toHaveBeenCalledTimes( 2 );
		expect( sleep ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'repeats a read after a transport failure', async () => {
		const { client, fetchImpl } = buildClient( [
			new Error( 'network down' ),
			jsonResponse( 200, { revision: 3 } ),
		] );

		await client.getDraft();

		expect( fetchImpl ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'gives up after the allowed attempts and reports the server error', async () => {
		const { client, fetchImpl } = buildClient( [
			jsonResponse( 503, { code: 'unavailable' } ),
			jsonResponse( 503, { code: 'unavailable' } ),
			jsonResponse( 503, { code: 'unavailable' } ),
		] );

		const error = await client
			.getDraft()
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( fetchImpl ).toHaveBeenCalledTimes( 3 );
		expect( error.status ).toBe( 503 );
	} );

	it( 'never repeats a write, because a lost answer would look like a conflict', async () => {
		const { client, fetchImpl, sleep } = buildClient( [
			jsonResponse( 500, { code: 'server_error' } ),
			jsonResponse( 200, { revision: 4 } ),
		] );

		const error = await client
			.saveDraft( {}, 3 )
			.catch( ( /** @type {any} */ caught ) => caught );

		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
		expect( sleep ).not.toHaveBeenCalled();
		expect( error.isTransient ).toBe( true );
	} );

	it( 'never repeats a write after a transport failure either', async () => {
		const { client, fetchImpl } = buildClient( [
			new Error( 'network down' ),
		] );

		await client.publish( 1 ).catch( () => {} );

		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not repeat an aborted request', async () => {
		const controller = new AbortController();
		const aborted = new Error( 'aborted' );
		aborted.name = 'AbortError';

		const { client, fetchImpl } = buildClient( [ aborted ] );

		controller.abort();

		await client.getDraft( controller.signal ).catch( () => {} );

		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not repeat a decision the server already made', async () => {
		const { client, fetchImpl } = buildClient( [
			jsonResponse( 409, { code: 'wccs_revision_conflict' } ),
		] );

		await client.saveDraft( {}, 1 ).catch( () => {} );

		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'out of order responses', () => {
	it( 'drops an answer that a newer request already superseded', async () => {
		/** @type {Array<Function>} */
		const resolvers = [];

		const fetchImpl = jest.fn(
			() =>
				new Promise( ( resolve ) => {
					resolvers.push( resolve );
				} )
		);

		const client = createClient( {
			root: 'https://example.test/v1',
			nonce: 'n',
			routes: { draft: '/schema/draft' },
			fetchImpl,
			sleep: async () => {},
		} );

		const first = client.request( '/schema/draft', { key: 'draft' } );
		const second = client.request( '/schema/draft', { key: 'draft' } );

		// The newer request answers first.
		resolvers[ 1 ]( jsonResponse( 200, { revision: 2 } ) );
		resolvers[ 0 ]( jsonResponse( 200, { revision: 1 } ) );

		await expect( second ).resolves.toEqual( { revision: 2 } );
		await expect( first ).rejects.toBeInstanceOf( StaleResponseError );
	} );
} );
