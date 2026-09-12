/**
 * Form field wrapper.
 *
 * Wires a label, help text and error message to the control they belong to, so
 * the relationship exists in the accessibility tree and not only visually. The
 * required asterisk is always accompanied by text, because an asterisk on its
 * own explains nothing.
 */

import { cloneElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}  props            Component properties.
 * @param {string}  props.id         Control id. Required.
 * @param {string}  props.label      Visible label.
 * @param {string}  [props.help]     Optional help text.
 * @param {string}  [props.error]    Optional error message.
 * @param {boolean} [props.required] Whether the field is required.
 * @param {*}       props.children   The control, which receives the wiring.
 */
export default function Field( {
	id,
	label,
	help,
	error,
	required = false,
	children,
} ) {
	if ( ! id ) {
		throw new Error(
			'Field requires an id: a label must point at a control.'
		);
	}

	const helpId = help ? `${ id }-help` : undefined;
	const errorId = error ? `${ id }-error` : undefined;
	const describedBy =
		[ helpId, errorId ].filter( Boolean ).join( ' ' ) || undefined;

	return (
		<div
			className={ `form-group wccs-field${ error ? ' has-error' : '' }` }
		>
			<label className="form-label wccs-field__label" htmlFor={ id }>
				{ label }
				{ required ? (
					<>
						<span
							className="wccs-field__required"
							aria-hidden="true"
						>
							*
						</span>
						<span className="wccs-screen-reader-text">
							{ __( '(required)', 'wc-checkoutsuite' ) }
						</span>
					</>
				) : null }
			</label>

			{ cloneElement( children, {
				id,
				'aria-describedby': describedBy,
				'aria-invalid': error ? 'true' : undefined,
				required,
			} ) }

			{ help ? (
				<p className="form-help wccs-field__help" id={ helpId }>
					{ help }
				</p>
			) : null }

			{ error ? (
				<p className="form-error wccs-field__error" id={ errorId }>
					{ error }
				</p>
			) : null }
		</div>
	);
}
