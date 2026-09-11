/**
 * The per-field error surface.
 *
 * Section 10 lists what the customer experience has to be, and every one of these
 * is one of those lines made mechanical:
 *
 * - the message sits **beside the field** it is about, not in a list at the top;
 * - the field carries `aria-invalid` and points at the message with
 *   `aria-describedby`, so a screen reader reads the two together;
 * - **the value is never touched** — an error is not a reason to erase what
 *   somebody typed;
 * - the message goes away **when the field is corrected**, which is when a rule
 *   stops being true rather than when the customer submits again;
 * - the summary is a list of **links** to the fields, and it is announced
 *   politely rather than shouted.
 *
 * Nothing here decides anything: it renders what a rule answered, and every rule
 * belongs to somebody else.
 */

/**
 * Creates the error surface.
 *
 * @param {Object} [options]           Options.
 * @param {string} [options.attribute] Attribute the server marks its fields with.
 * @return {Object} Surface.
 */
export function createFieldErrors( options = {} ) {
	const { attribute = 'data-wccs-field' } = options;

	const SUMMARY_CLASS = 'wccs-error-summary';
	const MESSAGE_CLASS = 'wccs-field-error';
	const NOTE_CLASS = 'wccs-field-note';

	/**
	 * The row the message belongs in.
	 *
	 * WooCommerce wraps every field in a `.form-row`, and that is where its own
	 * inline errors go. Falling back to the parent keeps this working on a
	 * template that does not use the wrapper, rather than appending a message
	 * nowhere.
	 *
	 * @param {any} element Field element.
	 * @return {any} The row.
	 */
	function rowFor( element ) {
		return element.closest( '.form-row' ) || element.parentNode;
	}

	/**
	 * The identifier the message carries and the field points at.
	 *
	 * @param {string} field Field identifier.
	 * @return {string} Identifier.
	 */
	function messageId( field ) {
		return `${ field }_wccs_error`;
	}

	/**
	 * Returns the message element for a field, if it is rendered.
	 *
	 * @param {any} element Field element.
	 * @return {any} The message, or null.
	 */
	function messageFor( element ) {
		const field = element.getAttribute( attribute );
		const row = rowFor( element );

		return field && row
			? row.querySelector( `#${ messageId( field ) }` )
			: null;
	}

	/**
	 * Records an error for one field.
	 *
	 * @param {any}     element   Field element.
	 * @param {string}  message   What to tell the customer.
	 * @param {boolean} [isError] Whether this is a refusal or a note.
	 * @return {void}
	 */
	function set( element, message, isError = true ) {
		const field = element.getAttribute( attribute );

		if ( ! field || ! message ) {
			return;
		}

		const row = rowFor( element );

		if ( ! row ) {
			return;
		}

		let rendered = row.querySelector( `#${ messageId( field ) }` );

		if ( ! rendered ) {
			rendered = document.createElement( 'p' );
			rendered.id = messageId( field );
			row.appendChild( rendered );
		}

		rendered.className = isError ? MESSAGE_CLASS : NOTE_CLASS;
		rendered.textContent = message;

		// A note is not a refusal. "The check could not be made" is something the
		// customer should read and is not something wrong with what they typed,
		// so the field is not marked invalid for it — and that is the difference
		// between an error and an apology.
		if ( ! isError ) {
			element.removeAttribute( 'aria-invalid' );

			return;
		}

		element.setAttribute( 'aria-invalid', 'true' );

		// Merged rather than replaced: WooCommerce and other plugins already point
		// some fields at a description with this attribute, and overwriting it
		// would take their text away from a screen reader.
		const described = ( element.getAttribute( 'aria-describedby' ) || '' )
			.split( /\s+/ )
			.filter( Boolean );

		if ( ! described.includes( rendered.id ) ) {
			described.push( rendered.id );
			element.setAttribute( 'aria-describedby', described.join( ' ' ) );
		}

		summarise( row.ownerDocument || document );
	}

	/**
	 * Removes the error for one field.
	 *
	 * @param {any} element Field element.
	 * @return {void}
	 */
	function clear( element ) {
		const field = element.getAttribute( attribute );
		const rendered = messageFor( element );

		if ( rendered && rendered.parentNode ) {
			rendered.parentNode.removeChild( rendered );
		}

		element.removeAttribute( 'aria-invalid' );

		const described = ( element.getAttribute( 'aria-describedby' ) || '' )
			.split( /\s+/ )
			.filter( Boolean )
			.filter(
				( /** @type {string} */ id ) => id !== messageId( field )
			);

		if ( arrayHas( described ) ) {
			element.setAttribute( 'aria-describedby', described.join( ' ' ) );
		} else {
			element.removeAttribute( 'aria-describedby' );
		}

		summarise( rowFor( element )?.ownerDocument || document );
	}

	/**
	 * Removes every error the Suite put on the page.
	 *
	 * @param {any} [root] Element to search under.
	 * @return {void}
	 */
	function clearAll( root = document ) {
		root.querySelectorAll(
			`.${ MESSAGE_CLASS }, .${ NOTE_CLASS }`
		).forEach( ( /** @type {any} */ message ) => {
			message.parentNode?.removeChild( message );
		} );

		root.querySelectorAll( `[${ attribute }]` ).forEach(
			( /** @type {any} */ element ) => {
				element.removeAttribute( 'aria-invalid' );

				const described = (
					element.getAttribute( 'aria-describedby' ) || ''
				)
					.split( /\s+/ )
					.filter( Boolean )
					.filter(
						( /** @type {string} */ id ) =>
							! id.endsWith( '_wccs_error' )
					);

				if ( arrayHas( described ) ) {
					element.setAttribute(
						'aria-describedby',
						described.join( ' ' )
					);
				} else {
					element.removeAttribute( 'aria-describedby' );
				}
			}
		);

		root.querySelectorAll( `.${ SUMMARY_CLASS }` ).forEach(
			( /** @type {any} */ summary ) => {
				summary.parentNode?.removeChild( summary );
			}
		);
	}

	/**
	 * Whether an array has anything in it.
	 *
	 * @param {any[]} values Values.
	 * @return {boolean} Whether it is not empty.
	 */
	function arrayHas( values ) {
		return values.length > 0;
	}

	/**
	 * The fields currently showing an error, in page order.
	 *
	 * @param {any} [root] Element to search under.
	 * @return {any[]} Fields.
	 */
	function invalid( root = document ) {
		return Array.from( root.querySelectorAll( `[${ attribute }]` ) ).filter(
			( element ) => 'true' === element.getAttribute( 'aria-invalid' )
		);
	}

	/**
	 * Puts the cursor in the first field showing an error.
	 *
	 * @param {any} [root] Element to search under.
	 * @return {any|null} The field that was focused.
	 */
	function focusFirst( root = document ) {
		const first = invalid( root )[ 0 ];

		if ( ! first ) {
			return null;
		}

		first.focus();

		if ( 'function' === typeof first.scrollIntoView ) {
			first.scrollIntoView( { block: 'center' } );
		}

		return first;
	}

	/**
	 * Rebuilds the summary from the errors currently on the page.
	 *
	 * A link per error, because a summary that only lists text makes the customer
	 * hunt for the field it is about — which is the one thing the summary exists
	 * to save them from.
	 *
	 * @param {any} [root] Element to search under.
	 * @return {void}
	 */
	function summarise( root = document ) {
		const fields = invalid( root );
		const form =
			root.querySelector( 'form.checkout, form.woocommerce-checkout' ) ||
			root;
		let summary = form.querySelector( `.${ SUMMARY_CLASS }` );

		if ( 0 === fields.length ) {
			summary?.parentNode?.removeChild( summary );

			return;
		}

		if ( ! summary ) {
			summary = document.createElement( 'div' );
			summary.className = SUMMARY_CLASS;
			// Polite rather than assertive: an error the customer is already
			// looking at does not need to interrupt what is being read out.
			summary.setAttribute( 'aria-live', 'polite' );

			form.insertBefore( summary, form.firstChild );
		}

		summary.textContent = '';

		const list = document.createElement( 'ul' );

		fields.forEach( ( element ) => {
			const field = element.getAttribute( attribute );
			const message = messageFor( element );
			const item = document.createElement( 'li' );
			const link = document.createElement( 'a' );

			link.href = `#${ element.id || field }`;
			link.textContent = message ? message.textContent : field;

			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				element.focus();
			} );

			item.appendChild( link );
			list.appendChild( item );
		} );

		summary.appendChild( list );
	}

	return { set, clear, clearAll, invalid, focusFirst, summarise, messageId };
}
