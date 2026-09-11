/**
 * Upload tests.
 *
 * Every clause of the acceptance is a state transition, so this suite drives the
 * machine with a transport it controls: a transfer that can be held halfway, a
 * transfer that fails, and a transfer that is aborted. None of those can be produced
 * reliably against a real connection, and all three are what the clauses are about.
 *
 * "Sem upload duplicado" is asserted four different ways, because it is four
 * different accidents: two clicks, a retry, a second add of the same file, and a
 * removal while something is still travelling.
 */

import { STATE, createUploader } from '../../../resources/upload/uploader';

/**
 * A transport that never settles until it is told to.
 *
 * @return {Record<string, any>} Transport and its controls.
 */
function controlled() {
	/** @type {Array<any>} */
	const calls = [];
	/** @type {Array<any>} */
	const pending = [];

	const transfer = jest.fn( ( request ) => {
		calls.push( request );

		return new Promise( ( resolve, reject ) => {
			pending.push( { request, resolve, reject } );
		} );
	} );

	return {
		transfer,
		calls,
		pending,
		settle: ( /** @type {number} */ index, /** @type {any} */ answer ) =>
			pending[ index ].resolve( answer ),
		fail: (
			/** @type {number} */ index,
			/** @type {string} */ code,
			/** @type {string} */ message = ''
		) => pending[ index ].reject( { code, message } ),
	};
}

/**
 * A file.
 *
 * @param {string} name Name.
 * @return {any} File stand-in.
 */
const file = ( name ) => ( { name } );

describe( 'uploader', () => {
	it( 'reports progress as the transfer reports it', async () => {
		const transport = controlled();
		/** @type {any[]} */
		let seen = [];

		const uploader = createUploader( {
			transfer: transport.transfer,
			onChange: ( /** @type {any[]} */ list ) => {
				seen = list;
			},
		} );

		const started = uploader.add( [ file( 'a.txt' ) ] );

		expect( uploader.list()[ 0 ].state ).toBe( STATE.UPLOADING );

		transport.calls[ 0 ].onProgress( 40 );

		expect( uploader.list()[ 0 ].progress ).toBe( 40 );
		expect( seen[ 0 ].progress ).toBe( 40 );

		transport.settle( 0, { token: 'token-a' } );
		await started;

		expect( uploader.list()[ 0 ].state ).toBe( STATE.UPLOADED );
		expect( uploader.list()[ 0 ].progress ).toBe( 100 );
		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );
	} );

	it( 'sends one transfer when the button is pressed twice', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		const first = uploader.add( [ file( 'a.txt' ) ] );
		const second = uploader.add( [ file( 'a.txt' ) ] );

		// The same document, chosen twice: one record, one transfer. Without this the
		// store would hold two copies and reference one of them.
		expect( transport.transfer ).toHaveBeenCalledTimes( 1 );
		expect( uploader.list() ).toHaveLength( 1 );

		transport.settle( 0, { token: 'token-a' } );
		await Promise.all( [ first, second ] );

		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );
	} );

	it( 'accepts a different file with the same name', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		uploader.add( [ { name: 'invoice.pdf', size: 10, lastModified: 1 } ] );
		uploader.add( [ { name: 'invoice.pdf', size: 20, lastModified: 2 } ] );

		expect( transport.transfer ).toHaveBeenCalledTimes( 2 );
		expect( uploader.list() ).toHaveLength( 2 );
	} );

	it( 'adds the same file again after it failed, because that is a retry', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		const first = uploader.add( [ file( 'a.txt' ) ] );
		transport.fail( 0, 'file_too_large' );
		await first;

		uploader.add( [ file( 'a.txt' ) ] );

		expect( transport.transfer ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not start a second transfer for the same record while one is running', async () => {
		const transport = controlled();
		const uploader = createUploader( {
			transfer: transport.transfer,
			identifier: () => 'one',
		} );

		const started = uploader.add( [ file( 'a.txt' ) ] );

		// The same record, started again: this is the double click.
		const again = uploader.retry( 'one' );

		expect( transport.transfer ).toHaveBeenCalledTimes( 1 );

		transport.settle( 0, { token: 'token-a' } );
		await Promise.all( [ started, again ] );

		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );
	} );

	it( 'cancels a transfer in flight and keeps the file for another try', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		const started = uploader.add( [ file( 'a.txt' ) ] );

		uploader.cancel( uploader.list()[ 0 ].id );
		transport.fail( 0, 'cancelled' );

		await started;

		expect( uploader.list()[ 0 ].state ).toBe( STATE.CANCELLED );
		expect( uploader.tokens() ).toEqual( [] );
		expect( uploader.list()[ 0 ].file ).toEqual( file( 'a.txt' ) );
	} );

	it( 'retries a failed upload without leaving the first attempt behind', async () => {
		const transport = controlled();
		const uploader = createUploader( {
			transfer: transport.transfer,
			identifier: () => 'one',
		} );

		const first = uploader.add( [ file( 'a.txt' ) ] );
		transport.fail( 0, 'file_too_large', 'Too large.' );
		await first;

		expect( uploader.list()[ 0 ].state ).toBe( STATE.FAILED );
		expect( uploader.list()[ 0 ].code ).toBe( 'file_too_large' );
		expect( uploader.list()[ 0 ].message ).toBe( 'Too large.' );

		const retried = uploader.retry( 'one' );

		expect( transport.transfer ).toHaveBeenCalledTimes( 2 );
		expect( uploader.list() ).toHaveLength( 1 );

		transport.settle( 1, { token: 'token-a' } );
		await retried;

		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );
		expect( uploader.list() ).toHaveLength( 1 );
	} );

	it( 'never re-sends a file the store already accepted', async () => {
		const transport = controlled();
		const uploader = createUploader( {
			transfer: transport.transfer,
			identifier: () => 'one',
		} );

		const started = uploader.add( [ file( 'a.txt' ) ] );
		transport.settle( 0, { token: 'token-a' } );
		await started;

		const again = await uploader.retry( 'one' );

		expect( transport.transfer ).toHaveBeenCalledTimes( 1 );
		expect( again.token ).toBe( 'token-a' );
	} );

	it( 'removes what it accepted, on the server as well', async () => {
		const transport = controlled();
		const remove = jest.fn().mockResolvedValue( true );
		const uploader = createUploader( {
			transfer: transport.transfer,
			remove,
		} );

		const started = uploader.add( [ file( 'a.txt' ) ] );
		transport.settle( 0, { token: 'token-a' } );
		await started;

		await uploader.drop( uploader.list()[ 0 ].id );

		expect( remove ).toHaveBeenCalledWith( 'token-a' );
		expect( uploader.list() ).toEqual( [] );
		expect( uploader.tokens() ).toEqual( [] );
	} );

	it( 'does not ask the server to remove what it never accepted', async () => {
		const transport = controlled();
		const remove = jest.fn();
		const uploader = createUploader( {
			transfer: transport.transfer,
			remove,
		} );

		const started = uploader.add( [ file( 'a.txt' ) ] );
		transport.fail( 0, 'mime_not_allowed', 'No.' );
		await started;

		await uploader.drop( uploader.list()[ 0 ].id );

		expect( remove ).not.toHaveBeenCalled();
		expect( uploader.list() ).toEqual( [] );
	} );

	it( 'submits only the uploads the store holds', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		const started = uploader.add( [ file( 'a.txt' ), file( 'b.txt' ) ] );
		const results = /** @type {any} */ ( started );

		expect( uploader.tokens() ).toEqual( [] );

		transport.settle( 0, { token: 'token-a' } );
		await results[ 0 ];

		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );

		transport.fail( 1, 'quota_exceeded', 'No room.' );
		await results[ 1 ];

		expect( uploader.tokens() ).toEqual( [ 'token-a' ] );
	} );

	it( 'records the refusal the server sent rather than a generic failure', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		const started = uploader.add( [ file( 'a.txt' ) ] );
		transport.fail(
			0,
			'not_available',
			'Uploads are not available on this store.'
		);
		await started;

		expect( uploader.list()[ 0 ].code ).toBe( 'not_available' );
		expect( uploader.list()[ 0 ].message ).toBe(
			'Uploads are not available on this store.'
		);
	} );

	it( 'keeps the percentage inside the range a progress bar can draw', async () => {
		const transport = controlled();
		const uploader = createUploader( { transfer: transport.transfer } );

		uploader.add( [ file( 'a.txt' ) ] );

		transport.calls[ 0 ].onProgress( -10 );
		expect( uploader.list()[ 0 ].progress ).toBe( 0 );

		transport.calls[ 0 ].onProgress( 240 );
		expect( uploader.list()[ 0 ].progress ).toBe( 100 );
	} );
} );
