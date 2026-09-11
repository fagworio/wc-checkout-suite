/**
 * Unsaved work guard.
 *
 * A screen that silently loses an edit is worse than one that refuses to leave.
 * While there is unsaved work the browser is asked to confirm before unloading,
 * and the guard is removed the moment the work is saved or discarded.
 *
 * The confirmation text is ignored by every modern browser on purpose; setting
 * it is kept only for older ones.
 */

import { useEffect } from '@wordpress/element';

/**
 * Guards against losing unsaved work.
 *
 * @param {boolean} isDirty Whether there is unsaved work.
 * @param {string}  message Fallback message for browsers that still show it.
 * @return {void}
 */
export default function useUnsavedChanges( isDirty, message ) {
	useEffect( () => {
		if ( ! isDirty ) {
			return undefined;
		}

		/**
		 * Asks the browser to confirm before unloading.
		 *
		 * @param {Event} event Unload event.
		 * @return {string} Legacy return value.
		 */
		const confirmLeaving = ( event ) => {
			event.preventDefault();

			// `returnValue` is typed as a boolean in the DOM lib, but every
			// browser that still honours the prompt reads it as a string.
			/** @type {any} */ ( event ).returnValue = message;

			return message;
		};

		window.addEventListener( 'beforeunload', confirmLeaving );

		return () => {
			window.removeEventListener( 'beforeunload', confirmLeaving );
		};
	}, [ isDirty, message ] );
}
