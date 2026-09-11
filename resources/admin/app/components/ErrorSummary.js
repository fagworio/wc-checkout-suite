/**
 * Error summary.
 *
 * Placed at the top of a form, it announces how many problems exist, lists each
 * one as a link to its field, and takes focus so a screen reader reads it before
 * the user starts tabbing. Colour is never the signal: the wording carries it.
 *
 * @see ROADMAP.md section 10
 */

import { useEffect, useRef } from '@wordpress/element';
import { _n, sprintf } from '@wordpress/i18n';

/**
 * @param {Object} props                Component properties.
 * @param {*[]}    [props.errors]       Errors as `{ fieldId, message }`.
 * @param {*}      [props.onFocusField] Called with a field id when its link is used.
 * @return {*} Rendered element tree, or null when there is nothing to report.
 */
export default function ErrorSummary( { errors = [], onFocusField } ) {
	/** @type {{ current: HTMLDivElement|null }} */
	const ref = useRef( null );

	useEffect( () => {
		if ( errors.length > 0 && ref.current ) {
			ref.current.focus();
		}
	}, [ errors.length ] );

	if ( errors.length === 0 ) {
		return null;
	}

	return (
		<div
			className="wccs-error-summary"
			role="alert"
			tabIndex={ -1 }
			ref={ ref }
		>
			<p className="wccs-error-summary__title">
				{ sprintf(
					/* translators: %d: number of problems found. */
					_n(
						'%d problem prevents saving.',
						'%d problems prevent saving.',
						errors.length,
						'wc-checkoutsuite'
					),
					errors.length
				) }
			</p>
			<ul className="wccs-error-summary__list">
				{ errors.map( ( error ) => (
					<li key={ error.fieldId }>
						<a
							href={ `#${ error.fieldId }` }
							onClick={ ( event ) => {
								event.preventDefault();
								if ( onFocusField ) {
									onFocusField( error.fieldId );
								}
							} }
						>
							{ error.message }
						</a>
					</li>
				) ) }
			</ul>
		</div>
	);
}
