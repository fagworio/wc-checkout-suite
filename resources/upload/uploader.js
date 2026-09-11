/**
 * Uploading a file, once, and knowing what happened to it.
 *
 * The state machine every upload surface shares: the classic checkout's DOM
 * component and the Block checkout's React one both drive this and both get the same
 * behaviour, because the behaviour is the part with the acceptance on it.
 *
 * The clause that shapes the design is "sem upload duplicado", and it is two
 * different problems that are usually solved as one:
 *
 * 1. **One transfer per file.** Clicking the button twice, dropping a file on a
 *    field that is already uploading, or a retry landing while the first attempt is
 *    still in flight must not send the bytes twice. The guard is a state, not a
 *    disabled attribute: a disabled button can be re-enabled by whoever renders it,
 *    and a state machine that refuses to start answers the same way from anywhere.
 * 2. **A retry is the same upload, not a new one.** A failure leaves the file where
 *    it is and re-sends it. What is never done is starting a second upload for a file
 *    that already has a token — a token means the store holds those bytes, and
 *    sending them again would leave the first copy behind as an orphan nobody
 *    references.
 *
 * What travels over the network is injectable, so the machine can be tested without
 * one. That is not a convenience: the interesting states are a transfer that is
 * halfway, one that was cancelled, and one that failed after the server had already
 * accepted it, and none of those can be produced reliably with a real connection.
 */

/**
 * The states one file can be in.
 *
 * @type {Record<string, string>}
 */
export const STATE = {
	IDLE: 'idle',
	UPLOADING: 'uploading',
	UPLOADED: 'uploaded',
	FAILED: 'failed',
	CANCELLED: 'cancelled',
};

/**
 * A file being uploaded.
 *
 * @typedef {Object} Upload
 * @property {string}   id       Local identifier.
 * @property {any}      file     The file itself.
 * @property {string}   state    One of STATE.
 * @property {number}   progress Percentage, 0 to 100.
 * @property {string}   token    Token the server gave, once it accepted.
 * @property {string}   code     Stable refusal code.
 * @property {string}   message  Message the customer reads.
 * @property {Function} [abort]  Stops the transfer, when one is running.
 */

/**
 * What makes two files the same file.
 *
 * Name, size and modification time, which is what the browser gives a `File` and
 * what distinguishes two documents a customer might both call `invoice.pdf`.
 *
 * @param {any} entry File.
 * @return {string} Key.
 */
function identity( entry ) {
	return [
		entry?.name ?? '',
		entry?.size ?? '',
		entry?.lastModified ?? '',
	].join( '|' );
}

/**
 * The uploader.
 *
 * @typedef {Object} Uploader
 * @property {() => Upload[]}                      list   The uploads, in order.
 * @property {(files: any[]) => Promise<Upload[]>} add    Adds files and starts them.
 * @property {(id: string) => Promise<Upload>}     retry  Sends a stopped upload again.
 * @property {(id: string) => void}                cancel Stops one in flight.
 * @property {(id: string) => Promise<boolean>}    drop   Removes one.
 * @property {() => string[]}                      tokens The tokens the form should submit.
 */

/**
 * Creates the uploader.
 *
 * @param {Object}   [options]            Options.
 * @param {Function} [options.transfer]   Sends one file. Receives `{file, onProgress, signal}` and resolves with `{token}` or rejects with `{code, message}`.
 * @param {Function} [options.remove]     Removes one token.
 * @param {Function} [options.onChange]   Called with the list of uploads.
 * @param {Function} [options.identifier] Produces a local identifier.
 * @return {Uploader} Uploader.
 */
export function createUploader( {
	transfer,
	remove,
	onChange = () => {},
	identifier = () => `upload-${ Math.random().toString( 36 ).slice( 2 ) }`,
} = {} ) {
	/** @type {Map<string, Upload>} */
	const uploads = new Map();

	/** @type {Map<string, any>} */
	const inFlight = new Map();

	/**
	 * The list, in the order the files were added.
	 *
	 * @return {Upload[]} Uploads.
	 */
	const list = () => Array.from( uploads.values() );

	/**
	 * Publishes the list.
	 *
	 * @return {Upload[]} Uploads.
	 */
	const publish = () => {
		const current = list();
		onChange( current );

		return current;
	};

	/**
	 * Records a change to one upload.
	 *
	 * @param {string} id     Identifier.
	 * @param {Object} change Fields to change.
	 * @return {Upload[]} Uploads.
	 */
	const patch = ( id, change ) => {
		const current = uploads.get( id );

		if ( ! current ) {
			return list();
		}

		uploads.set( id, { ...current, ...change } );

		return publish();
	};

	/**
	 * Starts one upload, unless it is already running.
	 *
	 * @param {string} id Upload identifier.
	 * @return {Promise<Upload>} The upload, settled.
	 */
	const start = ( id ) => {
		const current = uploads.get( id );

		if ( ! current ) {
			return Promise.resolve( /** @type {any} */ ( null ) );
		}

		// The guard. A second start while the first is in flight is the exact way a
		// double click becomes two files on the server, and it is refused here rather
		// than by disabling whatever control the customer pressed.
		if ( inFlight.has( id ) ) {
			return inFlight.get( id );
		}

		// A file the server already accepted is not sent again: the token is the
		// store's record that it holds those bytes.
		if ( '' !== current.token ) {
			return Promise.resolve( current );
		}

		patch( id, {
			state: STATE.UPLOADING,
			progress: 0,
			code: '',
			message: '',
		} );

		/** @type {any} */
		let controller = null;

		// The transport is injected, and may be absent: an uploader without one is a
		// state machine that records files and sends nothing, which is what a store
		// with uploads unavailable runs.
		const send = 'function' === typeof transfer ? transfer : null;
		const promise = Promise.resolve(
			send
				? send( {
						file: current.file,
						onProgress: ( /** @type {number} */ percent ) =>
							patch( id, {
								progress: Math.max(
									0,
									Math.min( 100, percent )
								),
							} ),
						signal: ( /** @type {any} */ abort ) => {
							controller = abort;
						},
				  } )
				: { token: '' }
		)
			.then( ( /** @type {any} */ answer ) => {
				inFlight.delete( id );
				controller = null;

				const settled = patch( id, {
					state: STATE.UPLOADED,
					progress: 100,
					token: String( answer?.token ?? '' ),
				} ).find( ( entry ) => entry.id === id );

				return /** @type {Upload} */ ( settled );
			} )
			.catch( ( /** @type {any} */ error ) => {
				inFlight.delete( id );
				controller = null;

				const cancelled = 'cancelled' === error?.code;
				const settled = patch( id, {
					state: cancelled ? STATE.CANCELLED : STATE.FAILED,
					code: String( error?.code ?? 'failed' ),
					message: String( error?.message ?? '' ),
				} ).find( ( entry ) => entry.id === id );

				return /** @type {Upload} */ ( settled );
			} );

		inFlight.set( id, promise );

		// The abort handle is published as a function on the record so a caller does
		// not have to know how the transfer was made to be able to stop it.
		uploads.set( id, {
			.../** @type {Upload} */ ( uploads.get( id ) ),
			abort: () => {
				/** @type {any} */ ( controller )?.();
			},
		} );

		return promise;
	};

	return {
		list,

		/**
		 * Adds files and starts uploading them.
		 *
		 * A file already being uploaded, or already accepted, is not added again. That
		 * is the accident the button produces: a double click, or a customer choosing
		 * the same file twice, gives two records for one document, and the store would
		 * hold two copies with only one of them referenced. A file that failed or was
		 * cancelled is not skipped — that is what `retry` is for.
		 *
		 * @param {any[]} files Files.
		 * @return {Promise<Upload[]>} The uploads, settled.
		 */
		add: ( files ) => {
			const started = [];

			for ( const file of files ) {
				const key = identity( file );
				const existing = list().find(
					( entry ) =>
						identity( entry.file ) === key &&
						STATE.FAILED !== entry.state &&
						STATE.CANCELLED !== entry.state
				);

				if ( existing ) {
					started.push( Promise.resolve( existing ) );

					continue;
				}

				const id = identifier();

				uploads.set( id, {
					id,
					file,
					state: STATE.IDLE,
					progress: 0,
					token: '',
					code: '',
					message: '',
				} );

				started.push( start( id ) );
			}

			publish();

			return Promise.all( started );
		},

		/**
		 * Retries a failed upload, sending the same file again.
		 *
		 * @param {string} id Identifier.
		 * @return {Promise<Upload>} The upload, settled.
		 */
		retry: ( id ) => {
			const current = uploads.get( id );

			if ( ! current ) {
				return Promise.resolve( /** @type {any} */ ( null ) );
			}

			// Only what actually stopped can be retried. An upload that succeeded has
			// a token and nothing to re-send; one that is running is already running.
			if (
				STATE.FAILED !== current.state &&
				STATE.CANCELLED !== current.state
			) {
				return Promise.resolve( current );
			}

			patch( id, {
				state: STATE.IDLE,
				code: '',
				message: '',
				progress: 0,
			} );

			return start( id );
		},

		/**
		 * Cancels an upload in flight.
		 *
		 * A cancelled upload keeps its file, so the customer can try again without
		 * choosing it a second time, and it is not on the server: the token is only
		 * ever set from an answer that arrived.
		 *
		 * @param {string} id Identifier.
		 * @return {void}
		 */
		cancel: ( id ) => {
			const current = uploads.get( id );

			if ( ! current || 'function' !== typeof current.abort ) {
				return;
			}

			/** @type {any} */ ( current.abort )();
		},

		/**
		 * Removes one upload, on the server when it has a token.
		 *
		 * @param {string} id Identifier.
		 * @return {Promise<boolean>} Whether it was removed.
		 */
		drop: async ( id ) => {
			const current = uploads.get( id );

			if ( ! current ) {
				return false;
			}

			if ( '' !== current.token && 'function' === typeof remove ) {
				await remove( current.token );
			}

			uploads.delete( id );
			publish();

			return true;
		},

		/**
		 * The tokens the form should submit.
		 *
		 * Only accepted uploads: a file that is still travelling, or one that failed,
		 * is not something the store holds and must not travel as if it were.
		 *
		 * @return {string[]} Tokens.
		 */
		tokens: () =>
			list()
				.filter( ( entry ) => '' !== entry.token )
				.map( ( entry ) => entry.token ),
	};
}
