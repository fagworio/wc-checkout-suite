/**
 * REST client for the administrative schema endpoints.
 *
 * Two rules shape this module:
 *
 * 1. A retry is only safe when repeating the request cannot change the outcome.
 *    Reads are retried on transient failures; writes never are. Retrying a
 *    compare-and-swap write after a lost response would surface as a conflict
 *    the user did not cause, so the write path stays a single attempt and the
 *    caller decides what to do with the failure.
 *
 * 2. The server's answer is classified, not flattened. A 403, a 409 and a 422
 *    mean three different things to the screen, so each keeps its status, its
 *    machine code and its payload.
 *
 * @see ROADMAP.md section 19
 */

/**
 * Failure reported by the REST layer.
 */
export class ApiError extends Error {
	/**
	 * @param {{ status: number, code?: string, message?: string, data?: any }} details Error details.
	 */
	constructor( { status, code, message, data } ) {
		super( message || 'The request failed.' );

		this.name = 'ApiError';
		this.status = status;
		this.code = code || '';
		this.data = data || {};
	}

	/**
	 * Whether the current user is not allowed to perform the action.
	 *
	 * @return {boolean} True for 403.
	 */
	get isForbidden() {
		return 403 === this.status;
	}

	/**
	 * Whether another writer changed the revision first.
	 *
	 * @return {boolean} True for 409.
	 */
	get isConflict() {
		return 409 === this.status;
	}

	/**
	 * Whether the payload failed validation.
	 *
	 * @return {boolean} True for 422.
	 */
	get isValidationFailure() {
		return 422 === this.status;
	}

	/**
	 * Whether repeating the request could plausibly succeed.
	 *
	 * @return {boolean} True for a transport failure or a server error.
	 */
	get isTransient() {
		return 0 === this.status || this.status >= 500;
	}

	/**
	 * Revision the server currently holds, when it reported one.
	 *
	 * @return {number|null} Revision, or null.
	 */
	get currentRevision() {
		return 'number' === typeof this.data.current_revision
			? this.data.current_revision
			: null;
	}

	/**
	 * Validation errors reported by the server.
	 *
	 * @return {any[]} Errors, or an empty array.
	 */
	get fieldErrors() {
		return Array.isArray( this.data.errors ) ? this.data.errors : [];
	}
}

/**
 * Raised when a newer request for the same key already produced a response.
 *
 * The stale answer is dropped rather than applied: a slow first request must
 * never overwrite the result of a faster second one.
 */
export class StaleResponseError extends Error {
	/**
	 * @param {string} key Request key that was superseded.
	 */
	constructor( key ) {
		super( `A newer request for "${ key }" already settled.` );

		this.name = 'StaleResponseError';
		this.key = key;
	}
}

/**
 * Reads the response body, tolerating a body that is not JSON.
 *
 * @param {any} response Fetch response.
 * @return {Promise<any>} Parsed body, or null.
 */
async function readPayload( response ) {
	try {
		const text = await response.text();

		return '' === text ? null : JSON.parse( text );
	} catch {
		return null;
	}
}

/**
 * Builds a client bound to one namespace and nonce.
 *
 * @param {{ root: string, namespace?: string, nonce: string, routes: Record<string, string>, fetchImpl?: Function, sleep?: Function, retries?: number }} config Client configuration.
 * @return {any} Client with one method per operation.
 */
export function createClient( {
	root,
	namespace = '',
	nonce,
	routes,
	fetchImpl,
	sleep,
	retries = 2,
} ) {
	if ( ! nonce ) {
		throw new Error( 'The REST client requires a nonce.' );
	}

	/** @type {Function|null} */
	const candidate =
		fetchImpl || ( 'undefined' !== typeof fetch ? fetch : null );

	if ( ! candidate ) {
		throw new Error( 'No fetch implementation is available.' );
	}

	// Narrowed once, so the request path never has to ask whether it exists.
	/** @type {Function} */
	const doFetch = candidate;

	/** @type {Function} */
	const wait =
		sleep ||
		( ( /** @type {number} */ ms ) =>
			new Promise( ( resolve ) => setTimeout( resolve, ms ) ) );

	// The REST root WordPress reports stops at `/wp-json`; the namespace is part
	// of the route, not of the root. Joining the two here is what keeps the
	// client from asking `/wp-json/schema/draft`, which no controller registers.
	// The namespace was published into the page all along and was simply unused.
	const base = [
		String( root || '' ).replace( /\/+$/, '' ),
		String( namespace || '' ).replace( /^\/+|\/+$/g, '' ),
	]
		.filter( Boolean )
		.join( '/' );
	const latest = new Map();
	let counter = 0;

	/**
	 * Sends one request.
	 *
	 * @param {string}                                                              path      Route path, relative to the REST root.
	 * @param {{ method?: string, data?: any, signal?: AbortSignal, key?: string }} [options] Request options.
	 * @return {Promise<any>} Parsed response body.
	 */
	async function send( path, options = {} ) {
		const {
			method = 'GET',
			data,
			signal,
			key = `${ method } ${ path }`,
		} = options;

		// Only a read is repeated automatically. A write is never retried: see
		// the module comment.
		const mayRetry = 'GET' === method;
		const id = ++counter;

		latest.set( key, id );

		let attempt = 0;

		for (;;) {
			let response;

			try {
				response = await doFetch( `${ base }${ path }`, {
					method,
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body:
						undefined === data ? undefined : JSON.stringify( data ),
					signal,
				} );
			} catch ( transportError ) {
				// An aborted request is the caller's decision, never retried.
				if ( signal && signal.aborted ) {
					throw transportError;
				}

				if ( mayRetry && attempt < retries ) {
					attempt += 1;
					await wait( 100 * attempt );
					continue;
				}

				throw new ApiError( {
					status: 0,
					code: 'network_error',
					message:
						transportError instanceof Error
							? transportError.message
							: String( transportError ),
				} );
			}

			const payload = await readPayload( response );

			if ( ! response.ok ) {
				const error = new ApiError( {
					status: response.status,
					code: payload && payload.code,
					message: payload && payload.message,
					data: payload && payload.data,
				} );

				if ( error.isTransient && mayRetry && attempt < retries ) {
					attempt += 1;
					await wait( 100 * attempt );
					continue;
				}

				throw error;
			}

			if ( latest.get( key ) !== id ) {
				throw new StaleResponseError( key );
			}

			return payload;
		}
	}

	return {
		request: send,
		getDraft: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.draft, { method: 'GET', signal } ),
		// Catalogue reads. They are reads, so the retry rule applies to them and
		// not to the writes below.
		fieldTypes: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.fieldTypes, { method: 'GET', signal } ),
		coreFields: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.coreFields, { method: 'GET', signal } ),
		revisions: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.revisions, { method: 'GET', signal } ),
		// The opt-in and the compatibility state. The read is a read; the write changes
		// one boolean and answers with the state it produced, so the screen never has to
		// guess what its own write did.
		settings: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.settings, { method: 'GET', signal } ),
		uploads: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.uploads, { method: 'GET', signal } ),
		probeUploads: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.uploads, { method: 'POST', signal } ),
		saveSettings: (
			/** @type {boolean} */ enabled,
			/** @type {AbortSignal} */ signal
		) =>
			send( routes.settings, {
				method: 'POST',
				data: { custom_checkout: enabled },
				signal,
			} ),
		// The store's own order statuses. The read is a read; the write replaces the
		// whole list, because a status is a row in a list and not an entity with its
		// own address — two requests that each changed one row would be two chances
		// for the list to be half-written.
		statuses: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.statuses, { method: 'GET', signal } ),
		saveStatuses: (
			/** @type {Array<any>} */ statuses,
			/** @type {number|AbortSignal|undefined} */ revisionOrSignal,
			/** @type {AbortSignal} */ signal
		) => {
			const revision =
				typeof revisionOrSignal === 'number'
					? revisionOrSignal
					: undefined;

			return send( routes.statuses, {
				method: 'POST',
				data: {
					statuses,
					...( undefined !== revision ? { revision } : {} ),
				},
				signal:
					typeof revisionOrSignal === 'number'
						? signal
						: revisionOrSignal,
			} );
		},
		// The automations of §13. The simulation is a route of its own and not a flag on
		// the write: a simulation that could be mistaken for a save is a simulation
		// somebody would run by accident.
		workflows: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.workflows, { method: 'GET', signal } ),
		saveWorkflows: (
			/** @type {Array<any>} */ workflows,
			/** @type {number|AbortSignal|undefined} */ revisionOrSignal,
			/** @type {AbortSignal} */ signal
		) => {
			const revision =
				typeof revisionOrSignal === 'number'
					? revisionOrSignal
					: undefined;

			return send( routes.workflows, {
				method: 'POST',
				data: {
					workflows,
					...( undefined !== revision ? { revision } : {} ),
				},
				signal:
					typeof revisionOrSignal === 'number'
						? signal
						: revisionOrSignal,
			} );
		},
		simulate: (
			/** @type {Record<string, any>} */ sample,
			/** @type {AbortSignal} */ signal
		) =>
			send( routes.simulate, {
				method: 'POST',
				data: { sample },
				signal,
			} ),
		// What publishing would change. A read, so the retry rule applies.
		diff: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.diff, { method: 'GET', signal } ),
		saveDraft: (
			/** @type {any} */ schema,
			/** @type {number} */ expectedRevision,
			/** @type {AbortSignal} */ signal
		) =>
			send( routes.draft, {
				method: 'PUT',
				data: { schema, expected_revision: expectedRevision },
				signal,
			} ),
		publish: (
			/** @type {number} */ expectedRevision,
			/** @type {AbortSignal} */ signal
		) =>
			send( routes.publish, {
				method: 'POST',
				data: { expected_revision: expectedRevision },
				signal,
			} ),
		// The schema as a file. A read, so it takes the retry rule: the merchant
		// pressed a button and the answer is a document, not a change.
		exportSchema: ( /** @type {AbortSignal} */ signal ) =>
			send( routes.schemaExport, { method: 'GET', signal } ),
		restore: (
			/** @type {number} */ revision,
			/** @type {AbortSignal} */ signal
		) =>
			send( routes.restore, {
				method: 'POST',
				data: { revision },
				signal,
			} ),
	};
}
