/**
 * Remote validation transport tests.
 *
 * @package
 */

import {
	STATUS,
	createRemoteValidation,
} from '../../../resources/checkout/remote';

/**
 * A fetch that answers what the test tells it to, when the test says so.
 *
 * The transport's whole job is to behave correctly around a request that is slow,
 * superseded or never answered, so the fake has to be able to hold an answer open
 * rather than resolve immediately.
 *
 * @return {any} A fetch implementation with its calls and resolvers.
 */
function fakeFetch() {
	/** @type {any[]} */
	const calls = [];
	/** @type {any[]} */
	const pending = [];

	/**
	 * A fetch that answers when the test says so.
	 *
	 * @param {string} url     Address.
	 * @param {any}    options Request options.
	 * @return {Promise<any>} The response.
	 */
	const impl = ( url, options ) => {
		calls.push( { url, options } );

		return new Promise( ( resolve, reject ) => {
			const settle = {
				options,
				body: new URLSearchParams( options.body ),
				respond: ( /** @type {any} */ body ) =>
					resolve( {
						json: () => Promise.resolve( body ),
					} ),
				fail: ( /** @type {any} */ error ) => reject( error ),
			};

			pending.push( settle );

			options.signal?.addEventListener( 'abort', () => {
				const error = new Error( 'aborted' );
				error.name = 'AbortError';
				reject( error );
			} );
		} );
	};

	impl.calls = calls;
	impl.pending = pending;

	return impl;
}

/**
 * Builds a transport wired to a fake fetch.
 *
 * Typed loosely: the pair this returns is a test double and the assertions read
 * whichever half they need.
 *
 * @param {any} [options] Overrides.
 * @return {any} Transport and fake.
 */
function transport( options = {} ) {
	const fetchImpl = options.fetchImpl || fakeFetch();

	/** @type {any} */
	const instance = createRemoteValidation( {
		url: 'https://example.test/wp-json/wccs/v1/validate',
		nonce: 'abc123',
		revision: 7,
		debounce: 400,
		timeout: 5000,
		fetchImpl,
		...options,
	} );

	return { remote: instance, fetchImpl };
}

/**
 * An answer the server would give.
 *
 * @param {Object} overrides Values to override.
 * @return {Object} Answer.
 */
function answer( overrides = {} ) {
	return {
		request_id: '',
		revision: 7,
		field: 'wccs_cpf',
		status: 'valid',
		code: '',
		message: '',
		schema_changed: false,
		...overrides,
	};
}

describe( 'remote validation', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'waits before asking, and asks once', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );

		expect( fetchImpl.calls ).toHaveLength( 0 );

		jest.advanceTimersByTime( 400 );

		expect( fetchImpl.calls ).toHaveLength( 1 );

		// The answer has to carry the id it was asked with: a response that does
		// not is discarded, which is what the next test asserts from the other
		// side.
		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
			} )
		);

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.VALID,
		} );
	} );

	it( 'sends the field, the value, the revision and a request id', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );

		jest.advanceTimersByTime( 400 );

		const body = fetchImpl.pending[ 0 ].body;

		expect( body.get( 'field' ) ).toBe( 'wccs_cpf' );
		expect( body.get( 'value' ) ).toBe( '123.456.789-09' );
		expect( body.get( 'revision' ) ).toBe( '7' );
		expect( body.get( 'request_id' ) ).not.toBe( '' );
		expect( body.get( 'nonce' ) ).toBe( 'abc123' );

		fetchImpl.pending[ 0 ].respond(
			answer( { request_id: body.get( 'request_id' ) } )
		);

		await result;
	} );

	it( 'sends nothing but the field and the value the server needs', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );

		jest.advanceTimersByTime( 400 );

		const keys = Array.from( fetchImpl.pending[ 0 ].body.keys() ).sort();

		// No regex, no callback, no path and no remote endpoint: section 10 lists
		// all four as things the endpoint must not accept, and the way to not
		// accept them is to have nowhere to put them.
		expect( keys ).toEqual(
			[ 'field', 'nonce', 'request_id', 'revision', 'value' ].sort()
		);

		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
			} )
		);

		await result;
	} );

	it( 'asks about the latest value only when a field changes quickly', async () => {
		const { remote, fetchImpl } = transport();

		const first = remote.check( 'wccs_cpf', '123' );
		jest.advanceTimersByTime( 100 );
		const second = remote.check( 'wccs_cpf', '1234' );
		jest.advanceTimersByTime( 100 );
		const third = remote.check( 'wccs_cpf', '12345' );

		jest.advanceTimersByTime( 400 );

		expect( fetchImpl.calls ).toHaveLength( 1 );
		expect( fetchImpl.pending[ 0 ].body.get( 'value' ) ).toBe( '12345' );

		await expect( first ).resolves.toMatchObject( {
			status: STATUS.CANCELLED,
		} );
		await expect( second ).resolves.toMatchObject( {
			status: STATUS.CANCELLED,
		} );

		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
			} )
		);

		await expect( third ).resolves.toMatchObject( {
			status: STATUS.VALID,
		} );
	} );

	it( 'aborts a request in flight when the field changes again', async () => {
		const { remote, fetchImpl } = transport();

		const first = remote.check( 'wccs_cpf', '123' );
		jest.advanceTimersByTime( 400 );

		expect( fetchImpl.calls ).toHaveLength( 1 );
		expect( fetchImpl.pending[ 0 ].options.signal.aborted ).toBe( false );

		const second = remote.check( 'wccs_cpf', '1234' );

		expect( fetchImpl.pending[ 0 ].options.signal.aborted ).toBe( true );

		await expect( first ).resolves.toMatchObject( {
			status: STATUS.CANCELLED,
		} );

		jest.advanceTimersByTime( 400 );
		fetchImpl.pending[ 1 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 1 ].body.get( 'request_id' ),
			} )
		);

		await expect( second ).resolves.toMatchObject( {
			status: STATUS.VALID,
		} );
	} );

	it( 'discards an answer that is not to the question the field asked', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );
		jest.advanceTimersByTime( 400 );

		// The server echoes the id it was given; an answer carrying a different
		// one did not come from this request.
		fetchImpl.pending[ 0 ].respond(
			answer( { request_id: 'somebody-elses' } )
		);

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.CANCELLED,
		} );
	} );

	it( 'gives up on a slow answer, and never calls it valid', async () => {
		const { remote } = transport( { timeout: 5000 } );

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );
		jest.advanceTimersByTime( 400 );

		jest.advanceTimersByTime( 5000 );

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.UNAVAILABLE,
			code: 'timeout',
		} );
	} );

	it( 'reports a failed request as unavailable', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );
		jest.advanceTimersByTime( 400 );

		fetchImpl.pending[ 0 ].fail( new Error( 'network down' ) );

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.UNAVAILABLE,
			code: 'network',
		} );
	} );

	it( 'reports no transport at all as unavailable', async () => {
		// `null` rather than `undefined`: an absent property takes the default,
		// and what is being tested is a browser that has no fetch to give.
		const remote = /** @type {any} */ (
			createRemoteValidation( { fetchImpl: null } )
		);

		const result = remote.check( 'wccs_cpf', '123' );

		jest.advanceTimersByTime( 400 );

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.UNAVAILABLE,
			code: 'no_transport',
		} );
	} );

	it( 'passes the refusal through with its code and its message', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '529.982.247-24' );
		jest.advanceTimersByTime( 400 );

		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
				status: 'invalid',
				code: 'invalid_cpf',
				message: 'This CPF is not a valid number.',
			} )
		);

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.INVALID,
			code: 'invalid_cpf',
			message: 'This CPF is not a valid number.',
		} );
	} );

	it( 'carries the schema change the server reports', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );
		jest.advanceTimersByTime( 400 );

		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
				revision: 9,
				schema_changed: true,
			} )
		);

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.VALID,
			revision: 9,
			schemaChanged: true,
		} );
	} );

	it( 'stops waiting when the caller cancels a field', async () => {
		const { remote, fetchImpl } = transport();

		const result = remote.check( 'wccs_cpf', '123.456.789-09' );

		jest.advanceTimersByTime( 400 );
		remote.cancel( 'wccs_cpf' );

		expect( fetchImpl.pending[ 0 ].options.signal.aborted ).toBe( true );

		await expect( result ).resolves.toMatchObject( {
			status: STATUS.CANCELLED,
		} );
	} );

	it( 'names the revision the caller last set', async () => {
		const { remote, fetchImpl } = transport();

		remote.setRevision( 12 );

		const result = remote.check( 'wccs_cpf', '123' );
		jest.advanceTimersByTime( 400 );

		expect( fetchImpl.pending[ 0 ].body.get( 'revision' ) ).toBe( '12' );

		fetchImpl.pending[ 0 ].respond(
			answer( {
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
			} )
		);

		await result;
	} );

	it( 'tracks two fields independently', async () => {
		const { remote, fetchImpl } = transport();

		const one = remote.check( 'wccs_cpf', '123' );
		const two = remote.check( 'wccs_cep', '01310100' );

		jest.advanceTimersByTime( 400 );

		expect( fetchImpl.calls ).toHaveLength( 2 );

		fetchImpl.pending[ 0 ].respond(
			answer( {
				field: 'wccs_cpf',
				request_id: fetchImpl.pending[ 0 ].body.get( 'request_id' ),
			} )
		);
		fetchImpl.pending[ 1 ].respond(
			answer( {
				field: 'wccs_cep',
				request_id: fetchImpl.pending[ 1 ].body.get( 'request_id' ),
				status: 'invalid',
				code: 'invalid_postcode',
			} )
		);

		await expect( one ).resolves.toMatchObject( { status: STATUS.VALID } );
		await expect( two ).resolves.toMatchObject( {
			status: STATUS.INVALID,
			code: 'invalid_postcode',
		} );
	} );
} );
