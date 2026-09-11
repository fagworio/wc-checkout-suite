/**
 * The fields this plugin renders in the Blocks checkout.
 *
 * The Blocks additional-fields API knows three types — text, select and checkbox —
 * and everything else has to be drawn by whoever configured it. These are those
 * components: a textarea, a radio group, a multi-select, and the three temporal
 * fields, each optionally masked.
 *
 * Four decisions are worth stating, because each of them is a place where a
 * component library usually starts lying:
 *
 * 1. **They are controlled, and the value is owned by the caller.** The Blocks
 *    checkout keeps the state; these components render it and report changes. A
 *    component that held its own copy would survive a re-render with a value the
 *    checkout does not have, which is the duplication the phase gate names.
 *
 * 2. **A mask formats, it never decides.** The mask comes from the server as a
 *    definition, is applied to the input element, and what leaves the component is
 *    what the customer sees. The canonical value is the server's business —
 *    ADR-0003 rule 5 — and computing it here would be the client making a decision
 *    the store owns.
 *
 * 3. **A required field says so to assistive technology.** `required` and
 *    `aria-required` both travel, and an invalid state is announced through
 *    `aria-invalid` with the message beside the field rather than as a colour.
 *
 * 4. **A type with no component renders nothing and says so.** Unknown types are
 *    refused by the server before they get here, but a payload can arrive from a
 *    version that knows one this build does not: the component returns null and
 *    records the type, instead of rendering a text input that would collect
 *    something the store cannot store.
 */

import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import IMask from 'imask';

import { keyboardFor, optionsFor } from '../checkout/masks';

/**
 * Field payloads, as the server publishes them.
 *
 * @typedef {Object} ControlledField
 * @property {string}                                          id            Integration identifier.
 * @property {string}                                          name          Field identifier.
 * @property {string}                                          label         Label the customer reads.
 * @property {string}                                          type          Suite field type.
 * @property {string}                                          location      Blocks location.
 * @property {boolean}                                         required      Whether it is required.
 * @property {string}                                          [description] Help text.
 * @property {Array<{value: string, label: string}>}           [options]     Choices.
 * @property {{key: string, version: number, definition: any}} [mask]        Mask to apply.
 */

/**
 * The props every controlled field receives.
 *
 * Declared once and used by every component: the checkout owns the value, so a
 * component's contract is always the same three things and an optional message.
 *
 * @typedef {Object} FieldProps
 * @property {ControlledField} field    Field payload.
 * @property {any}             value    Current value.
 * @property {Function}        onChange Called with the new value.
 * @property {string}          [error]  Message to show beside the control.
 */

/**
 * Builds the props of an input from a field payload.
 *
 * @param {ControlledField} field    Field.
 * @param {string}          value    Current value.
 * @param {Function}        onChange Change handler.
 * @return {Object} Props.
 */
function control( field, value, onChange ) {
	return {
		id: `wccs-blocks-${ field.name }`,
		name: field.id,
		value: 'string' === typeof value ? value : '',
		required: Boolean( field.required ),
		'aria-required': field.required ? 'true' : undefined,
		'aria-describedby': field.description
			? `wccs-blocks-${ field.name }-description`
			: undefined,
		onChange: ( /** @type {any} */ event ) =>
			onChange( event.target.value ),
	};
}

/**
 * Whether a field carries a mask this build understands.
 *
 * @param {ControlledField} field Field.
 * @return {Object|null} IMask options, or null.
 */
function maskOptions( field ) {
	return field && field.mask ? optionsFor( field.mask.definition ) : null;
}

/**
 * The keyboard a masked field asks for.
 *
 * A numeric keypad is right for eleven digits and makes an alphanumeric document
 * impossible to type, so the pattern decides — the same rule the classic checkout
 * applies, read from the same helper.
 *
 * @param {ControlledField} field Field.
 * @return {string|undefined} `inputmode` value.
 */
function inputMode( field ) {
	const options = maskOptions( field );

	return options ? keyboardFor( options ) || undefined : undefined;
}

/**
 * The props of a masked input.
 *
 * A mask and a React controlled input do not coexist: React assigns `value` to the
 * element directly, which bypasses the mask's own listener, so the mask never sees
 * what was typed and the field stops being formatted. What is done instead is the
 * arrangement IMask is built for — the element owns the text, the mask formats it,
 * and the value is reported to the checkout on every accepted change.
 *
 * The checkout still owns the value, and one rule keeps that true without fighting
 * the customer: the value coming from outside is written into the element only when
 * the element is **not** the one being typed into. A restore after a refresh, or a
 * value the server corrected, therefore lands; a keystroke is never overwritten by
 * the value it just produced.
 *
 * @param {any}             ref      Element ref.
 * @param {ControlledField} field    Field.
 * @param {any}             value    Value the checkout holds.
 * @param {Function}        onChange Called with the masked value.
 * @return {Object} Props for the input.
 */
function useMaskedControl( ref, field, value, onChange ) {
	const handler = useRef( onChange );
	handler.current = onChange;

	const mask = useRef( /** @type {any} */ ( null ) );

	useEffect( () => {
		const options = maskOptions( field );

		if ( ! ref.current || ! options ) {
			return undefined;
		}

		const created = IMask( ref.current, options );

		const accept = () => handler.current( created.value );

		created.on( 'accept', accept );

		if ( 'string' === typeof value && '' !== value ) {
			created.value = value;
		}

		mask.current = created;

		return () => {
			created.off( 'accept', accept );
			created.destroy();
			mask.current = null;
		};
		// The mask is built once per field: re-creating it on every keystroke would
		// throw away the cursor position and the text the customer is typing.
	}, [ ref, field ] );

	useEffect( () => {
		const created = mask.current;

		if ( ! created || ! ref.current ) {
			return;
		}

		if ( ref.current === ref.current.ownerDocument.activeElement ) {
			return;
		}

		const next = 'string' === typeof value ? value : '';

		if ( created.value !== next ) {
			created.value = next;
		}
	}, [ ref, value ] );

	return {
		id: `wccs-blocks-${ field.name }`,
		name: field.id,
		defaultValue: 'string' === typeof value ? value : '',
		required: Boolean( field.required ),
		'aria-required': field.required ? 'true' : undefined,
		'aria-describedby': field.description
			? `wccs-blocks-${ field.name }-description`
			: undefined,
	};
}

/**
 * The wrapper every component shares.
 *
 * One place decides how a label, a description and an invalid state are wired
 * together, so a new component cannot get the accessible name wrong.
 *
 * @param {Object}          props          Properties.
 * @param {ControlledField} props.field    Field.
 * @param {*}               props.children Control.
 * @param {string}          [props.error]  Message.
 * @return {*} Rendered element tree.
 */
function Wrapper( { field, children, error } ) {
	return (
		<div className="wccs-blocks-field">
			<label
				className="wccs-blocks-field__label"
				htmlFor={ `wccs-blocks-${ field.name }` }
			>
				{ field.label }
				{ field.required ? <span aria-hidden="true"> *</span> : null }
			</label>

			{ field.description ? (
				<p
					className="wccs-blocks-field__description"
					id={ `wccs-blocks-${ field.name }-description` }
				>
					{ field.description }
				</p>
			) : null }

			{ children }

			{ error ? (
				<p className="wccs-blocks-field__error" role="alert">
					{ error }
				</p>
			) : null }
		</div>
	);
}

/**
 * A multi-line text field.
 *
 * @param {FieldProps} props Properties.
 * @return {*} Rendered element tree.
 */
export function TextareaField( props ) {
	const { field, value, onChange, error } = props;

	return (
		<Wrapper field={ field } error={ error }>
			<textarea
				{ ...control( field, value, onChange ) }
				aria-invalid={ error ? 'true' : undefined }
				rows={ 4 }
			/>
		</Wrapper>
	);
}

/**
 * A group of radio buttons.
 *
 * @param {FieldProps} props Properties.
 * @return {*} Rendered element tree.
 */
export function RadioField( props ) {
	const { field, value, onChange, error } = props;
	const options = field.options ?? [];

	return (
		<Wrapper field={ field } error={ error }>
			<fieldset
				className="wccs-blocks-field__choices"
				aria-invalid={ error ? 'true' : undefined }
			>
				<legend className="wccs-screen-reader-text">
					{ field.label }
				</legend>

				{ options.map( ( option ) => (
					<label
						key={ option.value }
						htmlFor={ `wccs-blocks-${ field.name }-${ option.value }` }
					>
						<input
							id={ `wccs-blocks-${ field.name }-${ option.value }` }
							type="radio"
							name={ field.id }
							value={ option.value }
							checked={ option.value === value }
							required={ Boolean( field.required ) }
							onChange={ () => onChange( option.value ) }
						/>
						<span>{ option.label }</span>
					</label>
				) ) }
			</fieldset>
		</Wrapper>
	);
}

/**
 * A list where several options can be chosen.
 *
 * The value is an array, and a plain multi-select is used rather than a custom
 * listbox: it is the control every browser and screen reader already knows, and
 * the customer's platform supplies the interaction. The value is read from the
 * selected options on every change, so what leaves is exactly what is selected.
 *
 * @param {FieldProps} props Properties.
 * @return {*} Rendered element tree.
 */
export function MultiSelectField( props ) {
	const { field, value, onChange, error } = props;
	const options = field.options ?? [];
	const selected = Array.isArray( value ) ? value : [];

	return (
		<Wrapper field={ field } error={ error }>
			<select
				{ ...control( field, value, () => {} ) }
				multiple
				value={ selected }
				aria-invalid={ error ? 'true' : undefined }
				onChange={ ( /** @type {any} */ event ) =>
					onChange(
						Array.from( event.target.selectedOptions ).map(
							( /** @type {any} */ option ) => option.value
						)
					)
				}
			>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		</Wrapper>
	);
}

/**
 * One of the three temporal inputs.
 *
 * `date`, `time` and `datetime-local` are native inputs: the browser supplies the
 * picker, the keyboard and the format the customer's locale expects, and a custom
 * widget would replace all three with something worse. What the store keeps is the
 * canonical string the input produces, and the server decides whether it is
 * acceptable.
 *
 * @param {FieldProps} props Properties.
 * @return {*} Rendered element tree.
 */
export function TemporalField( props ) {
	const { field, value, onChange, error } = props;
	const ref = useRef( /** @type {any} */ ( null ) );

	const masked = null !== maskOptions( field );
	const maskedProps = useMaskedControl( ref, field, value, onChange );

	/** @type {Record<string, any>} */
	const kinds = {
		date: 'date',
		time: 'time',
		datetime: 'datetime-local',
	};

	return (
		<Wrapper field={ field } error={ error }>
			<input
				{ ...( masked
					? maskedProps
					: control( field, value, onChange ) ) }
				ref={ ref }
				type={ kinds[ field.type ] ?? 'text' }
				inputMode={ /** @type {any} */ ( inputMode( field ) ) }
				aria-invalid={ error ? 'true' : undefined }
			/>
		</Wrapper>
	);
}

/**
 * A single-line text field with a mask.
 *
 * Used for the masked presets, which have a native counterpart in name only: the
 * native text field cannot be given a mask, so a document typed into it would be
 * stored as typed and refused by the server. Rendering it here is what keeps the
 * mask and the value in one place.
 *
 * @param {FieldProps} props Properties.
 * @return {*} Rendered element tree.
 */
export function MaskedTextField( props ) {
	const { field, value, onChange, error } = props;
	const ref = useRef( /** @type {any} */ ( null ) );

	const maskedProps = useMaskedControl( ref, field, value, onChange );

	return (
		<Wrapper field={ field } error={ error }>
			<input
				{ ...maskedProps }
				ref={ ref }
				type="text"
				inputMode={ /** @type {any} */ ( inputMode( field ) ) }
				aria-invalid={ error ? 'true' : undefined }
			/>
		</Wrapper>
	);
}

/**
 * The components, by field type.
 *
 * @type {Record<string, Function>}
 */
export const COMPONENTS = {
	textarea: TextareaField,
	radio: RadioField,
	multiselect: MultiSelectField,
	'checkbox-group': MultiSelectField,
	date: TemporalField,
	time: TemporalField,
	datetime: TemporalField,
	text: MaskedTextField,
};

/**
 * The component for a field, or null.
 *
 * @param {ControlledField} field Field.
 * @return {Function|null} Component.
 */
export function componentFor( field ) {
	if ( ! field || 'string' !== typeof field.type ) {
		return null;
	}

	// A masked field of a type that is otherwise native is rendered here, because
	// the native input cannot take a mask.
	if ( 'text' === field.type && ! field.mask ) {
		return null;
	}

	return COMPONENTS[ field.type ] ?? null;
}

/**
 * The label of a field, for a message that has to name it.
 *
 * @param {ControlledField} field Field.
 * @return {string} Label.
 */
export function describeField( field ) {
	return field && field.label
		? field.label
		: __( 'A field', 'wc-checkoutsuite' );
}

/**
 * A message naming a field the build cannot render.
 *
 * @param {ControlledField} field Field.
 * @return {string} Message.
 */
export function unrenderable( field ) {
	return sprintf(
		/* translators: %s: field type. */
		__(
			'The field type "%s" has no component in this build, so the field is not shown. Update the plugin.',
			'wc-checkoutsuite'
		),
		String( field?.type ?? '' )
	);
}
