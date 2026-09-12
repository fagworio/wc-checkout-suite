/**
 * Form controls.
 *
 * Thin wrappers over native inputs so the browser keeps ownership of typing,
 * autofill, mobile keyboards and validation semantics. Each one is meant to be
 * used inside `Field`, which supplies the id, the description and the invalid
 * state.
 */

import Field from './Field';

/**
 * Single line text input.
 *
 * The documented properties are listed below; any other property is forwarded to
 * the native input, which is why the props object itself is left open. A closed
 * list would be a lie: `onChange`, `inputMode`, `autoComplete` and the rest are
 * part of the element's API, not of this component's.
 *
 * @param {any} props Component properties, including native input attributes.
 * @return {*} Rendered element tree.
 */
export function TextField( {
	id,
	label,
	type = 'text',
	value,
	help,
	error,
	...rest
} ) {
	return (
		<Field
			id={ id }
			label={ label }
			help={ help }
			error={ error }
			required={ rest.required }
		>
			<input
				className="input wccs-input"
				type={ type }
				value={ value ?? '' }
				{ ...rest }
			/>
		</Field>
	);
}

/**
 * Multiline text input.
 *
 * @param {any} props Component properties, including native textarea attributes.
 * @return {*} Rendered element tree.
 */
export function TextareaField( {
	id,
	label,
	value,
	help,
	error,
	rows = 4,
	...rest
} ) {
	return (
		<Field
			id={ id }
			label={ label }
			help={ help }
			error={ error }
			required={ rest.required }
		>
			<textarea
				className="input wccs-input wccs-input--textarea"
				rows={ rows }
				value={ value ?? '' }
				{ ...rest }
			/>
		</Field>
	);
}

/**
 * Select control.
 *
 * @param {any} props Component properties, including native select attributes.
 * @return {*} Rendered element tree.
 */
export function SelectField( {
	id,
	label,
	options = [],
	value,
	help,
	error,
	...rest
} ) {
	return (
		<Field
			id={ id }
			label={ label }
			help={ help }
			error={ error }
			required={ rest.required }
		>
			<select
				className="input wccs-input wccs-input--select"
				value={ value ?? '' }
				{ ...rest }
			>
				{
					/** @type {any[]} */ ( options ).map( ( option ) => (
						<option key={ option.value } value={ option.value }>
							{ option.label }
						</option>
					) )
				}
			</select>
		</Field>
	);
}

/**
 * Single checkbox with its label after the control.
 *
 * The label is placed after the input on purpose: a checkbox is read as part of
 * its own sentence, so putting the text first would separate the two.
 *
 * @param {any} props Component properties, including native input attributes.
 * @return {*} Rendered element tree.
 */
export function CheckboxField( { id, label, help, error, checked, ...rest } ) {
	if ( ! id ) {
		throw new Error(
			'CheckboxField requires an id: a label must point at a control.'
		);
	}

	const helpId = help ? `${ id }-help` : undefined;
	const errorId = error ? `${ id }-error` : undefined;
	const describedBy =
		[ helpId, errorId ].filter( Boolean ).join( ' ' ) || undefined;

	return (
		<div
			className={ `wccs-field wccs-field--checkbox${
				error ? ' has-error' : ''
			}` }
		>
			<label className="wccs-checkbox" htmlFor={ id }>
				<input
					id={ id }
					className="wccs-checkbox__input"
					type="checkbox"
					checked={ Boolean( checked ) }
					aria-describedby={ describedBy }
					aria-invalid={ error ? 'true' : undefined }
					{ ...rest }
				/>
				<span className="wccs-checkbox__label">{ label }</span>
			</label>

			{ help ? (
				<p className="wccs-field__help" id={ helpId }>
					{ help }
				</p>
			) : null }

			{ error ? (
				<p className="wccs-field__error" id={ errorId }>
					{ error }
				</p>
			) : null }
		</div>
	);
}
