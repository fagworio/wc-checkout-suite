/**
 * Talking to the upload endpoint.
 *
 * The transport both surfaces share, and the only file in this feature that knows
 * the request exists. It is deliberately not a `fetch`: an upload has to report
 * progress, and `fetch` cannot — the upload progress events live on `XMLHttpRequest`,
 * and a progress bar driven by a guess is a progress bar that lies.
 *
 * Three things are carried from the server rather than written here, because each of
 * them can be renamed by a store and a hardcoded one would be wrong the first time it
 * moved: the address, the nonce, and whether uploads are available at all.
 */

/**
 * Creates the transport the uploader drives.
 *
 * @param {Object} [options]         Options.
 * @param {string} [options.url]     Endpoint address.
 * @param {string} [options.nonce]   REST nonce.
 * @param {string} [options.field]   Field identifier the upload belongs to.
 * @param {any}    [options.request] XMLHttpRequest factory, for tests.
 * @return {Object} Transport with `transfer` and `remove`.
 */
export function createTransport( {
	url = '',
	nonce = '',
	field = '',
	// eslint-disable-next-line no-undef -- The global the browser provides; the bundle runs in a page, not in Node.
	request = () => new XMLHttpRequest(),
} = {} ) {
	/**
	 * Reads the answer, whichever shape it arrived in.
	 *
	 * @param {any} xhr Request.
	 * @return {any} Body.
	 */
	const body = ( xhr ) => {
		try {
			return JSON.parse( xhr.responseText || '{}' );
		} catch ( unparsable ) {
			// A body that is not JSON is a request that did not reach the plugin: a
			// proxy, a fatal error, a maintenance page. Answering with an empty object
			// lets the caller report a refusal rather than throw.
			void unparsable;

			return {};
		}
	};

	return {
		/**
		 * Sends one file.
		 *
		 * @param {Object}   options            Options.
		 * @param {any}      options.file       File.
		 * @param {Function} options.onProgress Progress callback.
		 * @param {Function} options.signal     Receives the abort handler.
		 * @return {Promise<any>} The answer, or a refusal.
		 */
		transfer: ( { file, onProgress, signal } ) =>
			new Promise( ( resolve, reject ) => {
				const xhr = request();

				xhr.open( 'POST', url, true );
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );

				xhr.upload.onprogress = ( /** @type {any} */ event ) => {
					if ( event.lengthComputable && event.total > 0 ) {
						onProgress(
							Math.round( ( event.loaded / event.total ) * 100 )
						);
					}
				};

				xhr.onload = () => {
					const answer = body( xhr );

					if (
						xhr.status >= 200 &&
						xhr.status < 300 &&
						answer.token
					) {
						resolve( answer );

						return;
					}

					reject( {
						code: String( answer.code ?? 'failed' ),
						message: String( answer.message ?? '' ),
					} );
				};

				xhr.onerror = () =>
					reject( {
						code: 'network',
						message: '',
					} );

				xhr.onabort = () =>
					reject( { code: 'cancelled', message: '' } );

				if ( 'function' === typeof signal ) {
					signal( () => xhr.abort() );
				}

				const data = new FormData();
				data.append( 'file', file );
				data.append( 'field', field );

				xhr.send( data );
			} ),

		/**
		 * Removes one upload.
		 *
		 * @param {string} token Token.
		 * @return {Promise<boolean>} Whether the server removed it.
		 */
		remove: ( token ) =>
			new Promise( ( resolve ) => {
				const xhr = request();

				xhr.open(
					'DELETE',
					`${ url }?token=${ encodeURIComponent( token ) }`,
					true
				);
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				xhr.onload = () =>
					resolve( xhr.status >= 200 && xhr.status < 300 );
				xhr.onerror = () => resolve( false );
				xhr.send();
			} ),
	};
}
