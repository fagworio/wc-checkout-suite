/**
 * The design's toast, and the announcement that goes with it.
 *
 * The prototype keeps two nodes for one message: a visible toast pinned to the
 * bottom of the window and a screen-reader live region that carries the same
 * text. Both are reproduced here, with the prototype's own timing — the toast
 * hides itself five seconds after it appears, and the live region keeps the last
 * message so assistive technology reads it once.
 *
 * The timer belongs to the component on purpose. A caller announces an action by
 * changing `token`, and each announcement restarts the countdown, which is what
 * the prototype's `clearTimeout`/`setTimeout` pair does.
 */

import { useEffect, useState } from '@wordpress/element';

/**
 * How long a toast stays on screen, in milliseconds.
 *
 * @type {number}
 */
export const TOAST_DISMISS_MS = 5000;

/**
 * Announces one message in the design's toast and live region.
 *
 * @param {Object} [props]         Component properties.
 * @param {string} [props.message] Message to announce. Empty renders nothing.
 * @param {number} [props.token]   Changes on every announcement, restarting the timer.
 * @return {*} The toast region.
 */
export function Toast( { message = '', token = 0 } = {} ) {
	const [ visible, setVisible ] = useState( Boolean( message ) );

	useEffect( () => {
		if ( ! message ) {
			setVisible( false );

			return undefined;
		}

		setVisible( true );

		const timer = setTimeout( () => setVisible( false ), TOAST_DISMISS_MS );

		return () => clearTimeout( timer );
	}, [ message, token ] );

	return (
		<>
			<p className="sr-only" aria-live="polite">
				{ message }
			</p>
			{ visible && message ? (
				<div
					className="toast"
					id="wccs-toast"
					role="status"
					aria-live="polite"
				>
					{ message }
				</div>
			) : null }
		</>
	);
}
