/**
 * Inline notice.
 *
 * Every notice carries an icon and wording, never colour alone: the design
 * system states that colour is never the only signal, and an error announced
 * only by a red border is invisible to anyone who cannot see it. Errors and
 * warnings use `role="alert"` so they are announced immediately; information
 * and success use `role="status"` so they are not interruptions.
 */

import { __ } from '@wordpress/i18n';

/**
 * Glyph shown for each status, hidden from assistive technology because the
 * wording already carries the meaning.
 *
 * @type {Record<string, string>}
 */
const GLYPHS = {
	info: 'i',
	success: '✓',
	warning: '!',
	error: '×',
};

/**
 * @param {Object} props             Component properties.
 * @param {string} [props.status]    `info`, `success`, `warning` or `error`.
 * @param {string} [props.title]     Optional heading.
 * @param {*}      props.children    Notice body.
 * @param {*}      [props.onDismiss] Optional dismiss handler.
 * @return {*} Rendered element tree.
 */
export default function Notice( {
	status = 'info',
	title,
	children,
	onDismiss,
} ) {
	const assertive = 'error' === status || 'warning' === status;

	return (
		<div
			className={ `wccs-notice wccs-notice--${ status }` }
			role={ assertive ? 'alert' : 'status' }
		>
			<span className="wccs-notice__glyph" aria-hidden="true">
				{ GLYPHS[ status ] ?? GLYPHS.info }
			</span>

			<div className="wccs-notice__body">
				{ title ? (
					<p className="wccs-notice__title">{ title }</p>
				) : null }
				<div className="wccs-notice__text">{ children }</div>
			</div>

			{ onDismiss ? (
				<button
					type="button"
					className="wccs-icon-button wccs-notice__dismiss"
					aria-label={ __( 'Dismiss notice', 'wc-checkoutsuite' ) }
					onClick={ onDismiss }
				>
					<span aria-hidden="true">×</span>
				</button>
			) : null }
		</div>
	);
}
