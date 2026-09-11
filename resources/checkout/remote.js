/**
 * Remote validation transport.
 *
 * Section 10 allows an AJAX call only when the answer needs something the browser
 * does not have. When it is made, it has to be made carefully: a checkout is a
 * form somebody is typing into, and every one of the properties below exists
 * because of a way an unattended request spoils that.
 *
 * - **Debounced.** A request per keystroke is a request per keystroke.
 * - **Aborted.** A new question about a field cancels the previous one for that
 *   field, so two answers cannot race to the same screen.
 * - **Identified.** Every request carries an id the server echoes, and a response
 *   whose id is not the one the field is waiting for is discarded rather than
 *   shown.
 * - **Bounded.** A request that takes longer than the timeout is abandoned. The
 *   answer to that is `unavailable`, never `valid`: letting a timeout release a
 *   critical rule silently is exactly what section 10 forbids.
 * - **Cancellable.** A caller can stop waiting for a field, which is what an
 *   interface does when the field changes or the page moves on.
 *
 * Nothing here decides whether a value is acceptable. The server does, against the
 * published document, and the answer is a hint for the field: the same rules are
 * checked again on submit, and this transport has no way to make the server skip
 * that.
 */

/**
 * The statuses a caller has to handle.
 *
 * `cancelled` is not a failure: it means the question stopped being interesting,
 * because a newer one replaced it or because the caller let it go. An interface
 * must not show it as anything.
 */
export const STATUS = {
	VALID: 'valid',
	INVALID: 'invalid',
	UNAVAILABLE: 'unavailable',
	CANCELLED: 'cancelled',
	/** The field declares no rule, so there is nothing to answer. */
	SKIP: 'skip',
};

/**
 * Creates the transport.
 *
 * @param {Object}        [options]             Options.
 * @param {string}        [options.url]         Endpoint address, printed by the server.
 * @param {string}        [options.nonce]       Nonce printed by the server.
 * @param {number}        [options.revision]    Revision the page was rendered from.
 * @param {number}        [options.debounce]    Milliseconds to wait before asking.
 * @param {number}        [options.timeout]     Milliseconds before giving up on an answer.
 * @param {Function|null} [options.fetchImpl]   Fetch implementation, or null when the browser has none.
 * @param {string}        [options.contentType] Content type of the request body.
 * @return {Object} Transport.
 */
export function createRemoteValidation( options = {} ) {
	const {
		url = '',
		nonce = '',
		debounce = 400,
		timeout = 5000,
		fetchImpl = typeof window !== 'undefined' ? window.fetch : undefined,
		contentType = 'application/x-www-form-urlencoded; charset=UTF-8',
	} = options;

	let revision = Number( options.revision ) || 0;
	let counter = 0;

	/** @type {Map<string, string>} */
	const latest = new Map();

	/** @type {Map<string, {timer: any, resolve: Function}>} */
	const waiting = new Map();

	/** @type {Map<string, AbortController>} */
	const flying = new Map();

	/**
	 * Builds a request identifier.
	 *
	 * Unique per page and per field, and echoed by the server, which is what makes
	 * an out-of-order answer detectable at all.
	 *
	 * @param {string} field Field identifier.
	 * @return {string} Identifier.
	 */
	function identifier( field ) {
		counter += 1;

		return `${ field }:${ counter }:${ Date.now() }`;
	}

	/**
	 * Stops waiting on a field and cancels whatever is in flight.
	 *
	 * @param {string} field Field identifier.
	 * @return {void}
	 */
	function cancel( field ) {
		const pending = waiting.get( field );

		if ( pending ) {
			clearTimeout( pending.timer );
			waiting.delete( field );
			pending.resolve( { status: STATUS.CANCELLED } );
		}

		const controller = flying.get( field );

		if ( controller ) {
			flying.delete( field );
			controller.abort();
		}

		latest.delete( field );
	}

	/**
	 * Sends one request.
	 *
	 * @param {string}   field   Field identifier.
	 * @param {any}      value   Value as the customer has it.
	 * @param {string}   id      Request identifier.
	 * @param {Function} resolve Resolver of the caller's promise.
	 * @return {void}
	 */
	function send( field, value, id, resolve ) {
		if ( 'function' !== typeof fetchImpl ) {
			resolve( { status: STATUS.UNAVAILABLE, code: 'no_transport' } );

			return;
		}

		const controller = new AbortController();

		let timedOut = false;

		const timer = setTimeout( () => {
			timedOut = true;
			controller.abort();
		}, timeout );

		flying.set( field, controller );

		const settle = ( /** @type {any} */ result ) => {
			clearTimeout( timer );

			if ( flying.get( field ) === controller ) {
				flying.delete( field );
			}

			resolve( result );
		};

		const body = new URLSearchParams( {
			field,
			value: 'string' === typeof value ? value : JSON.stringify( value ),
			revision: String( revision ),
			request_id: id,
			nonce,
		} );

		fetchImpl( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': contentType },
			body: body.toString(),
			signal: controller.signal,
		} )
			.then( ( /** @type {any} */ response ) => response.json() )
			.then( ( /** @type {any} */ answer ) => {
				// Two guards, because one is not enough. The field may have moved
				// on since this request was sent, and the answer may be to a
				// question this page never asked — a response arriving after a
				// navigation, or a reply the endpoint did not produce.
				if ( latest.get( field ) !== id || answer?.request_id !== id ) {
					settle( { status: STATUS.CANCELLED } );

					return;
				}

				settle( {
					status: answer.status,
					code: answer.code || '',
					message: answer.message || '',
					revision: Number( answer.revision ) || revision,
					schemaChanged: Boolean( answer.schema_changed ),
				} );
			} )
			.catch( () => {
				if ( latest.get( field ) !== id ) {
					settle( { status: STATUS.CANCELLED } );

					return;
				}

				// A timed-out or failed request is `unavailable` and never
				// `valid`. A critical rule that releases because the network was
				// slow is a rule that does not exist.
				settle( {
					status: STATUS.UNAVAILABLE,
					code: timedOut ? 'timeout' : 'network',
				} );
			} );
	}

	/**
	 * Asks about one value, replacing whatever was pending for that field.
	 *
	 * @param {string} field Field identifier.
	 * @param {any}    value Value as the customer has it.
	 * @return {Promise<{status: string, code?: string, message?: string, revision?: number, schemaChanged?: boolean}>} Result.
	 */
	function check( field, value ) {
		return new Promise( ( resolve ) => {
			cancel( field );

			const id = identifier( field );

			latest.set( field, id );

			const timer = setTimeout( () => {
				waiting.delete( field );
				send( field, value, id, resolve );
			}, debounce );

			waiting.set( field, { timer, resolve } );
		} );
	}

	/**
	 * Sets the revision the next request will name.
	 *
	 * The server answers against the published revision and reports whether the
	 * one named here was current, so a caller that has just been told it was not
	 * can catch up without reloading.
	 *
	 * @param {number} next Revision.
	 * @return {void}
	 */
	function setRevision( next ) {
		revision = Number( next ) || 0;
	}

	return { check, cancel, setRevision };
}
