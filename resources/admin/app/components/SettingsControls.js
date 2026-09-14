/**
 * The controls an inspector renders.
 *
 * Edits one field, showing only the properties that field actually supports.
 *
 * The controlling idea is ROADMAP.md section 428: "Exibir somente propriedades
 * suportadas". Two consequences follow, and both are implemented here rather than
 * documented as intentions:
 *
 * 1. **The type's own settings are generated, not written.** Each control comes
 *    from the `settingsSchema()` the type declares, so a type added by a
 *    third-party plugin gets an editor with no change to this file. A list of
 *    known settings written here would be a list that is wrong for every type it
 *    does not know about.
 *
 * 2. **A surface that does not apply says so.** Hiding the mask control on a type
 *    that cannot be masked is correct, but a panel that silently loses rows is
 *    how a merchant concludes the feature is broken. Each absent surface is
 *    stated in one line.
 *
 * The inspector writes only through `onChange`, which the screen turns into a
 * document operation. It performs no request of its own.
 *
 * @see ROADMAP.md sections 4, 5 and 428
 */

import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import IconButton from './IconButton';
import Notice from './Notice';
import {
	TextField,
	TextareaField,
	SelectField,
	CheckboxField,
} from './controls';

/**
 * Renders one control for a declared setting.
 *
 * The control is chosen from the schema, never from the property name: a type
 * that declares `maxLength` as a string gets a text input, as it asked for.
 *
 * @param {Object}              props          Component properties.
 * @param {string}              props.name     Setting name.
 * @param {Record<string, any>} props.rules    Declared rules.
 * @param {*}                   props.value    Current value.
 * @param {Function}            props.onChange Called with the new value.
 * @return {*} Rendered element tree.
 */
function SettingControl( { name, rules, value, onChange } ) {
	const id = `wccs-setting-${ name }`;
	const label = name;
	const help = [];
	const type = String( rules.type ?? 'string' );

	if ( rules.minLength || rules.maxLength ) {
		help.push(
			sprintf(
				/* translators: 1: minimum length, 2: maximum length. */
				__( 'Between %1$d and %2$d characters.', 'wc-checkoutsuite' ),
				Number( rules.minLength ?? 0 ),
				Number( rules.maxLength ?? 0 )
			)
		);
	}

	if ( rules.minimum || rules.maximum ) {
		help.push(
			sprintf(
				/* translators: 1: minimum value, 2: maximum value. */
				__( 'From %1$s to %2$s.', 'wc-checkoutsuite' ),
				String( rules.minimum ?? '—' ),
				String( rules.maximum ?? '—' )
			)
		);
	}

	if ( 'integer' === type || 'number' === type ) {
		return (
			<TextField
				id={ id }
				label={ label }
				type="number"
				value={ value ?? '' }
				help={ help.join( ' ' ) }
				min={ rules.minimum }
				max={ rules.maximum }
				step={ 'integer' === type ? 1 : 'any' }
				required={ Boolean( rules.required ) }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) =>
					onChange(
						'' === event.target.value
							? undefined
							: Number( event.target.value )
					)
				}
			/>
		);
	}

	if ( 'boolean' === type ) {
		return (
			<CheckboxField
				id={ id }
				label={ label }
				checked={ Boolean( value ) }
				help={ help.join( ' ' ) }
				onChange={ (
					/** @type {{ target: { checked: boolean } }} */ event
				) => onChange( event.target.checked ) }
			/>
		);
	}

	if ( 'enum' === type || Array.isArray( rules.enum ) ) {
		return (
			<SelectField
				id={ id }
				label={ label }
				value={ value ?? '' }
				help={ help.join( ' ' ) }
				required={ Boolean( rules.required ) }
				options={ ( rules.enum ?? [] ).map(
					( /** @type {any} */ option ) => ( {
						value: String( option ),
						label: String( option ),
					} )
				) }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) => onChange( event.target.value ) }
			/>
		);
	}

	return (
		<TextareaField
			id={ id }
			label={ label }
			value={ value ?? '' }
			help={ help.join( ' ' ) }
			required={ Boolean( rules.required ) }
			maxLength={ rules.maxLength }
			rows={ 3 }
			onChange={ ( /** @type {{ target: { value: string } }} */ event ) =>
				onChange( event.target.value )
			}
		/>
	);
}

/**
 * Editor for a list of plain strings.
 *
 * @param {Object}              props          Component properties.
 * @param {string}              props.name     Setting name.
 * @param {Record<string, any>} props.rules    Declared rules.
 * @param {string[]}            props.value    Current list.
 * @param {Function}            props.onChange Called with the new list.
 * @return {*} Rendered element tree.
 */
function StringListControl( { name, rules, value, onChange } ) {
	const items = Array.isArray( value ) ? value : [];
	const maxItems = Number( rules.maxItems ?? 40 );

	/**
	 * Replaces one entry.
	 *
	 * @param {number} index Index.
	 * @param {string} next  New value.
	 * @return {void}
	 */
	const replace = ( index, next ) => {
		const copy = [ ...items ];
		copy[ index ] = next;
		onChange( copy );
	};

	return (
		<fieldset className="wccs-inspector__group">
			<legend className="wccs-inspector__legend">{ name }</legend>

			{ items.length === 0 ? (
				<p className="wccs-inspector__hint">
					{ __( 'None yet.', 'wc-checkoutsuite' ) }
				</p>
			) : null }

			<ul className="wccs-inspector__rows">
				{ items.map( ( item, index ) => (
					<li
						key={ `${ name }-${ index }` }
						className="wccs-inspector__row"
					>
						<TextField
							id={ `wccs-setting-${ name }-${ index }` }
							label={ sprintf(
								/* translators: 1: setting name, 2: position. */
								__( '%1$s %2$d', 'wc-checkoutsuite' ),
								name,
								index + 1
							) }
							value={ String( item ) }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) => replace( index, event.target.value ) }
						/>
						<IconButton
							label={ sprintf(
								/* translators: 1: setting name, 2: position. */
								__( 'Remove %1$s %2$d', 'wc-checkoutsuite' ),
								name,
								index + 1
							) }
							icon="×"
							onClick={ () =>
								onChange(
									items.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</li>
				) ) }
			</ul>

			<Button
				size="small"
				disabled={ items.length >= maxItems }
				onClick={ () => onChange( [ ...items, '' ] ) }
			>
				{ sprintf(
					/* translators: %s: setting name. */
					__( 'Add %s', 'wc-checkoutsuite' ),
					name
				) }
			</Button>
		</fieldset>
	);
}

/**
 * A compact editor for array settings that people naturally type as a list.
 *
 * The schema remains an array — the API, validator and renderers never receive
 * a comma-separated string. `format` merely describes the most legible input
 * affordance for this setting.
 *
 * @param {Object}              props          Component properties.
 * @param {string}              props.name     Setting name.
 * @param {Record<string, any>} props.rules    Declared rules.
 * @param {string[]}            props.value    Current list.
 * @param {Function}            props.onChange Called with the parsed list.
 * @return {*} Rendered element tree.
 */
function CommaSeparatedListControl( { name, rules, value, onChange } ) {
	const items = Array.isArray( value ) ? value : [];
	const label = rules.label ?? name;
	const help =
		rules.help ?? __( 'Separate values with commas.', 'wc-checkoutsuite' );

	return (
		<TextField
			id={ `wccs-setting-${ name }` }
			label={ label }
			help={ help }
			required={ Boolean( rules.required ) }
			value={ items.join( ', ' ) }
			placeholder="pdf, jpg, png"
			onChange={ ( /** @type {{ target: { value: string } }} */ event ) =>
				onChange(
					event.target.value
						.split( ',' )
						.map( ( item ) => item.trim().replace( /^\.+/, '' ) )
						.filter( Boolean )
				)
			}
		/>
	);
}

/**
 * Editor for a list of value/label options.
 *
 * The shape is the one the type declared through `items.properties`, so the
 * editor does not have to know that choice fields exist.
 *
 * @param {Object}                     props          Component properties.
 * @param {string}                     props.name     Setting name.
 * @param {Record<string, any>}        props.rules    Declared rules.
 * @param {Array<Record<string, any>>} props.value    Current list.
 * @param {Function}                   props.onChange Called with the new list.
 * @return {*} Rendered element tree.
 */
function OptionListControl( { name, rules, value, onChange } ) {
	const items = Array.isArray( value ) ? value : [];
	const maxItems = Number( rules.maxItems ?? 500 );

	/**
	 * Replaces one field of one entry.
	 *
	 * @param {number} index Index.
	 * @param {string} key   Property.
	 * @param {string} next  New value.
	 * @return {void}
	 */
	const replace = ( index, key, next ) => {
		const copy = items.map( ( item, i ) =>
			i === index ? { ...item, [ key ]: next } : item
		);
		onChange( copy );
	};

	return (
		<fieldset className="wccs-inspector__group">
			<legend className="wccs-inspector__legend">
				{ __( 'Options', 'wc-checkoutsuite' ) }
			</legend>

			{ items.length === 0 ? (
				<Notice status="warning">
					{ __(
						'A field with choices needs at least one option before it can be published.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : null }

			<ul className="wccs-inspector__rows">
				{ items.map( ( item, index ) => (
					<li
						key={ `${ name }-${ index }` }
						className="wccs-inspector__row"
					>
						<TextField
							id={ `wccs-setting-${ name }-${ index }-value` }
							label={ __( 'Value', 'wc-checkoutsuite' ) }
							value={ String( item?.value ?? '' ) }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) => replace( index, 'value', event.target.value ) }
						/>
						<TextField
							id={ `wccs-setting-${ name }-${ index }-label` }
							label={ __( 'Label', 'wc-checkoutsuite' ) }
							value={ String( item?.label ?? '' ) }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) => replace( index, 'label', event.target.value ) }
						/>
						<IconButton
							label={ sprintf(
								/* translators: %d: position of the option. */
								__( 'Remove option %d', 'wc-checkoutsuite' ),
								index + 1
							) }
							icon="×"
							onClick={ () =>
								onChange(
									items.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</li>
				) ) }
			</ul>

			<Button
				size="small"
				disabled={ items.length >= maxItems }
				onClick={ () =>
					onChange( [ ...items, { value: '', label: '' } ] )
				}
			>
				{ __( 'Add option', 'wc-checkoutsuite' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Renders the settings a type declares.
 *
 * Exported because the design's inspection panel is a different component and this is
 * the half that knows how to read a type's declared settings. It moves to its own
 * module when the previous panel is deleted; until then, one implementation serves both.
 *
 * @param {Object}                              props          Component properties.
 * @param {Record<string, Record<string, any>>} [props.schema] Declared settings schema.
 * @param {Record<string, any>}                 props.value    Current settings.
 * @param {Function}                            props.onChange Called with the new settings.
 * @return {*} Rendered element tree.
 */
export function SettingsControls( { schema, value, onChange } ) {
	const declared = schema ?? {};
	const names = Object.keys( declared );

	if ( 0 === names.length ) {
		return (
			<p className="wccs-inspector__hint">
				{ __(
					'This type declares no settings of its own.',
					'wc-checkoutsuite'
				) }
			</p>
		);
	}

	/**
	 * Writes one setting, removing the key when the value is cleared.
	 *
	 * The server rejects an unknown property but accepts a missing one, so an
	 * emptied setting has to disappear rather than be sent as null.
	 *
	 * @param {string} name Setting name.
	 * @param {*}      next New value.
	 * @return {void}
	 */
	const write = ( name, next ) => {
		const copy = { ...( value ?? {} ) };

		if ( undefined === next || '' === next ) {
			delete copy[ name ];
		} else {
			copy[ name ] = next;
		}

		onChange( copy );
	};

	return (
		<>
			{ names.map( ( name ) => {
				const rules = declared[ name ] ?? {};
				const current = ( value ?? {} )[ name ];
				const items = rules.items ?? {};

				if ( 'array' === rules.type && items.properties ) {
					return (
						<OptionListControl
							key={ name }
							name={ name }
							rules={ rules }
							value={ current }
							onChange={ ( /** @type {any} */ next ) =>
								write( name, next )
							}
						/>
					);
				}

				if ( 'array' === rules.type ) {
					if ( 'comma-separated' === rules.format ) {
						return (
							<CommaSeparatedListControl
								key={ name }
								name={ name }
								rules={ rules }
								value={ current }
								onChange={ ( /** @type {any} */ next ) =>
									write( name, next )
								}
							/>
						);
					}

					return (
						<StringListControl
							key={ name }
							name={ name }
							rules={ rules }
							value={ current }
							onChange={ ( /** @type {any} */ next ) =>
								write( name, next )
							}
						/>
					);
				}

				return (
					<SettingControl
						key={ name }
						name={ name }
						rules={ rules }
						value={ current }
						onChange={ ( /** @type {any} */ next ) =>
							write( name, next )
						}
					/>
				);
			} ) }
		</>
	);
}
