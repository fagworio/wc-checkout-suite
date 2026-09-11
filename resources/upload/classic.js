/**
 * The upload field, in the classic checkout.
 *
 * A DOM component over the shared uploader: it renders the control, the list of
 * files, the progress, the buttons and the messages, and it changes nothing about
 * the behaviour it is rendering. That separation is what keeps the two checkouts
 * agreeing: the clauses of the acceptance live in the state machine, and this file
 * is a view.
 *
 * Two decisions about the interface are worth stating, because both are where an
 * accessible upload control is usually got wrong:
 *
 * - **Progress is announced, not just drawn.** The percentage goes into a live region
 *   that updates at a readable pace, because a bar is invisible to anyone not looking
 *   at it.
 * - **A refusal is attached to the field, not to the window.** The message is written
 *   beside the control with `role="alert"`, and the control keeps the file so it can
 *   be retried — the two things that make an error something a customer can act on.
 */

import { STATE, createUploader } from './uploader';
import { createTransport } from './transport';

/**
 * The message for one state.
 *
 * @param {any} entry Upload.
 * @return {string} Message.
 */
function describe( entry ) {
	if ( STATE.FAILED === entry.state ) {
		return entry.message || 'The file could not be uploaded.';
	}

	if ( STATE.CANCELLED === entry.state ) {
		return 'Upload cancelled.';
	}

	if ( STATE.UPLOADING === entry.state ) {
		return `Uploading ${ entry.progress }%`;
	}

	if ( STATE.UPLOADED === entry.state ) {
		return 'Uploaded';
	}

	return 'Ready to upload';
}

/**
 * Creates the component for one field.
 *
 * @param {Object}   options            Options.
 * @param {Element}  options.input      The file input the server rendered.
 * @param {Object}   options.config     Upload configuration: url, nonce, multiple.
 * @param {Function} [options.onChange] Called with the tokens after every change.
 * @return {{ start: () => void }} The component.
 */
export function createUploadField( {
	input,
	config = {},
	onChange = () => {},
} ) {
	if ( ! input || ! input.parentNode ) {
		return { start: () => {} };
	}

	// The server's input is hidden rather than removed: it stays the control the form
	// knows about, the label still points at it, and a checkout that submits the form
	// without JavaScript still submits the field — empty, which is the honest answer
	// for a file nobody could upload.
	input.classList.add( 'wccs-upload__input' );

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'wccs-upload';

	// Read as the element it has to be rather than the generic one `createElement`
	// returns: the component is handed an input by its caller and cannot use it
	// without knowing that, and asserting it here keeps the assertion in one place.
	const control = /** @type {any} */ ( input );

	const list = document.createElement( 'ul' );
	list.className = 'wccs-upload__list';

	/** @type {any} */
	let live = null;

	/** @type {any} */
	let error = null;

	/** @type {any} */
	let trigger = null;

	const settings = /** @type {Record<string, any>} */ ( config );

	const transport = /** @type {any} */ (
		createTransport( {
			url: settings.url || '',
			nonce: settings.nonce || '',
			field:
				input.getAttribute( 'data-wccs-field' ) ||
				( 'name' in input ? String( input.name ) : '' ),
		} )
	);

	const uploader = createUploader( {
		transfer: transport.transfer,
		remove: transport.remove,
		onChange: ( /** @type {any[]} */ entries ) => {
			render( entries );
			onChange( uploader.tokens() );
		},
	} );

	/**
	 * Rewrites the list.
	 *
	 * @param {any[]} entries Uploads.
	 * @return {void}
	 */
	const render = ( entries ) => {
		list.textContent = '';

		for ( const entry of entries ) {
			const item = document.createElement( 'li' );
			item.className = `wccs-upload__item is-${ entry.state }`;

			const name = document.createElement( 'span' );
			name.className = 'wccs-upload__name';
			name.textContent = entry.file?.name ?? '';

			const state = document.createElement( 'span' );
			state.className = 'wccs-upload__state';
			state.textContent = describe( entry );

			item.append( name, state );

			if ( STATE.UPLOADING === entry.state ) {
				item.append(
					button( 'Cancel', () => uploader.cancel( entry.id ) )
				);
			}

			if (
				STATE.FAILED === entry.state ||
				STATE.CANCELLED === entry.state
			) {
				item.append(
					button( 'Try again', () => uploader.retry( entry.id ) )
				);
			}

			item.append( button( 'Remove', () => uploader.drop( entry.id ) ) );

			list.append( item );
		}

		if ( ! live ) {
			return;
		}

		const uploading = entries.find(
			( entry ) => STATE.UPLOADING === entry.state
		);

		live.textContent = uploading ? describe( uploading ) : '';

		const failed = entries.find(
			( entry ) => STATE.FAILED === entry.state
		);

		if ( error ) {
			error.textContent = failed ? describe( failed ) : '';
			error.hidden = ! failed;
		}
	};

	/**
	 * A small button.
	 *
	 * @param {string}     label   Label.
	 * @param {() => void} onClick Handler.
	 * @return {any} Button.
	 */
	const button = (
		/** @type {string} */ label,
		/** @type {() => void} */ onClick
	) => {
		const element = document.createElement( 'button' );

		element.type = 'button';
		element.className = 'wccs-upload__action';
		element.textContent = label;
		element.addEventListener( 'click', onClick );

		return element;
	};

	const start = () => {
		trigger = button( 'Choose a file', () => control.click() );
		trigger.className = 'wccs-upload__trigger';

		live = document.createElement( 'p' );
		live.className = 'wccs-upload__progress';
		live.setAttribute( 'aria-live', 'polite' );

		error = document.createElement( 'p' );
		error.className = 'wccs-upload__error';
		error.setAttribute( 'role', 'alert' );
		error.hidden = true;

		if ( settings.multiple ) {
			input.setAttribute( 'multiple', 'multiple' );
		} else {
			input.removeAttribute( 'multiple' );
		}

		input.addEventListener( 'change', () => {
			const files = Array.from( control.files || [] );

			if ( 0 === files.length ) {
				return;
			}

			uploader.add( settings.multiple ? files : files.slice( 0, 1 ) );
		} );

		wrapper.append( trigger, live, error, list );
		control.parentNode.insertBefore( wrapper, control.nextSibling );
	};

	return { start };
}
