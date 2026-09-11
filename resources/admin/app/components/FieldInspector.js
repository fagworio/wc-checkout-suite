/**
 * Field inspector.
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

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import IconButton from './IconButton';
import Notice from './Notice';
import Tabs from './Tabs';
import { Badge } from './Badge';
import {
	TextField,
	TextareaField,
	SelectField,
	CheckboxField,
} from './controls';
import { isProtected, protectionReason } from '../schema/fieldOperations';

/**
 * Viewports a width can be set for.
 *
 * @type {Array<{key: 'desktop'|'tablet'|'mobile', label: string}>}
 */
const VIEWPORTS = [
	{ key: 'desktop', label: __( 'Desktop', 'wc-checkoutsuite' ) },
	{ key: 'tablet', label: __( 'Tablet', 'wc-checkoutsuite' ) },
	{ key: 'mobile', label: __( 'Mobile', 'wc-checkoutsuite' ) },
];

/**
 * Column widths offered on the 12 column grid.
 *
 * 12, 6, 4 and 3 are the divisions that land on whole columns at every
 * breakpoint the design tokens define.
 *
 * @type {number[]}
 */
const WIDTHS = [ 12, 6, 4, 3 ];

/**
 * Vocabulary used before the catalogue arrives.
 *
 * An empty vocabulary rather than a partial one: the inspector then offers no
 * choice at all, which is honest while loading, instead of offering a subset that
 * would disappear a moment later.
 *
 * @type {import('../schema/types').DefinitionVocabulary}
 */
const EMPTY_VOCABULARY = {
	storageScopes: [],
	storageSensitivities: [],
	visibilityKeys: [],
	hiddenValuePolicies: [],
};

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
 * @param {Object}                              props          Component properties.
 * @param {Record<string, Record<string, any>>} [props.schema] Declared settings schema.
 * @param {Record<string, any>}                 props.value    Current settings.
 * @param {Function}                            props.onChange Called with the new settings.
 * @return {*} Rendered element tree.
 */
function SettingsControls( { schema, value, onChange } ) {
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

/**
 * Field inspector.
 *
 * @param {Object}                                      props                 Component properties.
 * @param {import('../schema/types').FieldDefinition}   props.field           Field being edited.
 * @param {import('../schema/types').FieldCatalog|null} props.catalog         Field catalogue.
 * @param {Function}                                    props.onChange        Called with a partial definition.
 * @param {boolean}                                     [props.hasConditions] Whether the conditions engine exists yet.
 * @return {*} Rendered element tree.
 */
export default function FieldInspector( {
	field,
	catalog,
	onChange,
	hasConditions = false,
} ) {
	const [ tab, setTab ] = useState( 'general' );

	const type = catalog?.types?.[ field?.type ] ?? null;
	const supports = type?.supports ?? {};
	const vocabulary = catalog?.vocabulary ?? EMPTY_VOCABULARY;
	const storesValue = false !== supports.value;
	const maskable = true === supports.maskable;
	const protectedField = isProtected( field );

	/** @type {Array<{id: string, label: string}>} */
	const tabs = useMemo( () => {
		/** @type {Array<{id: string, label: string}>} */
		const entries = [
			{ id: 'general', label: __( 'General', 'wc-checkoutsuite' ) },
			{ id: 'appearance', label: __( 'Appearance', 'wc-checkoutsuite' ) },
			{
				id: 'validation',
				label: __( 'Mask and validation', 'wc-checkoutsuite' ),
			},
			{
				id: 'storage',
				label: __( 'Storage and visibility', 'wc-checkoutsuite' ),
			},
			{ id: 'advanced', label: __( 'Advanced', 'wc-checkoutsuite' ) },
		];

		// Section 428 fixes six inspector groups. Conditions are the sixth and
		// belong to F06, so the tab appears only once there is an engine behind it
		// rather than as a panel that would open onto nothing.
		if ( hasConditions ) {
			entries.splice( 3, 0, {
				id: 'conditions',
				label: __( 'Conditions', 'wc-checkoutsuite' ),
			} );
		}

		return entries;
	}, [ hasConditions ] );

	const mask = field?.mask ?? null;

	return (
		<div className="wccs-inspector">
			<Tabs
				tabs={ tabs }
				active={ tab }
				onSelect={ setTab }
				label={ __( 'Field properties', 'wc-checkoutsuite' ) }
				renderPanel={ ( /** @type {string} */ active ) => (
					<div className="wccs-inspector__panel">
						{ 'general' === active ? (
							<>
								<TextField
									id="wccs-field-label"
									label={ __( 'Label', 'wc-checkoutsuite' ) }
									value={ field?.label ?? '' }
									required
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										onChange( {
											label: event.target.value,
										} )
									}
								/>

								<TextareaField
									id="wccs-field-description"
									label={ __(
										'Description',
										'wc-checkoutsuite'
									) }
									help={ __(
										'Shown under the field, to explain what is expected.',
										'wc-checkoutsuite'
									) }
									value={ field?.description ?? '' }
									maxLength={ 500 }
									rows={ 3 }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										onChange( {
											description: event.target.value,
										} )
									}
								/>

								<CheckboxField
									id="wccs-field-required"
									label={ __(
										'Required',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( field?.required ) }
									disabled={
										protectedField && field?.required
									}
									onChange={ (
										/** @type {{ target: { checked: boolean } }} */ event
									) =>
										onChange( {
											required: event.target.checked,
										} )
									}
								/>

								{ protectedField && field?.required ? (
									<p className="wccs-inspector__hint">
										{ __(
											'WooCommerce requires this field. Making it optional needs an impact assessment and is refused by the server.',
											'wc-checkoutsuite'
										) }
									</p>
								) : null }
							</>
						) : null }

						{ 'appearance' === active ? (
							<>
								{ VIEWPORTS.map( ( viewport ) => (
									<SelectField
										key={ viewport.key }
										id={ `wccs-field-width-${ viewport.key }` }
										label={ sprintf(
											/* translators: %s: device name. */
											__(
												'Width on %s',
												'wc-checkoutsuite'
											),
											viewport.label
										) }
										value={ String(
											field?.layout?.[ viewport.key ] ??
												12
										) }
										options={ WIDTHS.map( ( width ) => ( {
											value: String( width ),
											label: sprintf(
												/* translators: %d: number of columns. */
												__(
													'%d of 12 columns',
													'wc-checkoutsuite'
												),
												width
											),
										} ) ) }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onChange( {
												layout: {
													...( field?.layout ?? {} ),
													[ viewport.key ]: Number(
														event.target.value
													),
												},
											} )
										}
									/>
								) ) }
							</>
						) : null }

						{ 'validation' === active ? (
							<>
								{ maskable ? (
									<SelectField
										id="wccs-field-mask"
										label={ __(
											'Mask',
											'wc-checkoutsuite'
										) }
										help={ __(
											'A mask shapes what is typed. It never replaces validation.',
											'wc-checkoutsuite'
										) }
										value={ mask?.key ?? '' }
										options={ [
											{
												value: '',
												label: __(
													'No mask',
													'wc-checkoutsuite'
												),
											},
											...( catalog?.masks ?? [] ).map(
												( entry ) => ( {
													value: entry.key,
													label: entry.key,
												} )
											),
										] }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) => {
											const key = event.target.value;
											const chosen = (
												catalog?.masks ?? []
											).find(
												( entry ) => entry.key === key
											);

											onChange( {
												mask: chosen
													? {
															key: chosen.key,
															version:
																chosen.version,
													  }
													: null,
											} );
										} }
									/>
								) : (
									<p className="wccs-inspector__hint">
										{ __(
											'This type cannot be masked, so no mask is offered.',
											'wc-checkoutsuite'
										) }
									</p>
								) }

								<SettingsControls
									schema={ type?.settingsSchema }
									value={ field?.settings }
									onChange={ (
										/** @type {Record<string, any>} */ settings
									) => onChange( { settings } ) }
								/>

								{ type?.settingsSchema?.allowedExtensions ? (
									<Notice status="info">
										{ __(
											'Uploads are stored privately and reached through a token on the order, never through a public URL.',
											'wc-checkoutsuite'
										) }
									</Notice>
								) : null }
							</>
						) : null }

						{ 'storage' === active ? (
							<>
								{ protectedField ? (
									<Notice status="info">
										{ __(
											'WooCommerce persists this field through its own flow, so where its value is kept is not a choice here.',
											'wc-checkoutsuite'
										) }
									</Notice>
								) : null }

								<SelectField
									id="wccs-field-storage-scope"
									label={ __(
										'Where the value is kept',
										'wc-checkoutsuite'
									) }
									disabled={ protectedField || ! storesValue }
									value={ field?.storage?.scope ?? 'none' }
									options={ (
										vocabulary.storageScopes ?? []
									).map( ( entry ) => ( {
										value: entry.value,
										label: entry.label,
									} ) ) }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										onChange( {
											storage: {
												sensitivity:
													field?.storage
														?.sensitivity ??
													'personal',
												scope: event.target.value,
											},
										} )
									}
								/>

								{ ! storesValue ? (
									<p className="wccs-inspector__hint">
										{ __(
											'This type stores no value, so nothing is kept.',
											'wc-checkoutsuite'
										) }
									</p>
								) : null }

								<SelectField
									id="wccs-field-storage-sensitivity"
									label={ __(
										'How sensitive the value is',
										'wc-checkoutsuite'
									) }
									value={
										field?.storage?.sensitivity ??
										'personal'
									}
									options={ (
										vocabulary.storageSensitivities ?? []
									).map( ( entry ) => ( {
										value: entry.value,
										label: entry.label,
									} ) ) }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										onChange( {
											storage: {
												scope:
													field?.storage?.scope ??
													'order',
												sensitivity: event.target.value,
											},
										} )
									}
								/>

								{ storesValue ? (
									<fieldset className="wccs-inspector__group">
										<legend className="wccs-inspector__legend">
											{ __(
												'Where the value may be shown',
												'wc-checkoutsuite'
											) }
										</legend>

										{ (
											vocabulary.visibilityKeys ?? []
										).map( ( entry ) => (
											<CheckboxField
												key={ entry.value }
												id={ `wccs-field-visibility-${ entry.value }` }
												label={ entry.label }
												help={ entry.description }
												checked={ Boolean(
													field?.visibility?.[
														entry.value
													]
												) }
												onChange={ (
													/** @type {{ target: { checked: boolean } }} */ event
												) =>
													onChange( {
														visibility: {
															...( field?.visibility ??
																{} ),
															[ entry.value ]:
																event.target
																	.checked,
														},
													} )
												}
											/>
										) ) }
									</fieldset>
								) : (
									<p className="wccs-inspector__hint">
										{ __(
											'This type stores no value, so there is nothing to show anywhere.',
											'wc-checkoutsuite'
										) }
									</p>
								) }

								{ storesValue ? (
									<SelectField
										id="wccs-field-hidden-policy"
										label={ __(
											'When a rule hides the field',
											'wc-checkoutsuite'
										) }
										value={
											field?.hidden_value_policy ??
											'discard'
										}
										options={ (
											vocabulary.hiddenValuePolicies ?? []
										).map( ( entry ) => ( {
											value: entry.value,
											label: entry.label,
										} ) ) }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onChange( {
												hidden_value_policy:
													event.target.value,
											} )
										}
									/>
								) : null }
							</>
						) : null }

						{ 'advanced' === active ? (
							<dl className="wccs-inspector__identity">
								<dt>
									{ __( 'Identifier', 'wc-checkoutsuite' ) }
								</dt>
								<dd>{ field?.id }</dd>
								<dt>
									{ __(
										'Integration key',
										'wc-checkoutsuite'
									) }
								</dt>
								<dd>{ field?.integration_id }</dd>
								<dt>{ __( 'Type', 'wc-checkoutsuite' ) }</dt>
								<dd>{ field?.type }</dd>
								<dt>{ __( 'Preset', 'wc-checkoutsuite' ) }</dt>
								<dd>{ field?.preset ?? '—' }</dd>
								<dt>{ __( 'Origin', 'wc-checkoutsuite' ) }</dt>
								<dd>
									{ protectedField
										? __(
												'WooCommerce',
												'wc-checkoutsuite'
										  )
										: __(
												'Created here',
												'wc-checkoutsuite'
										  ) }
								</dd>
							</dl>
						) : null }
					</div>
				) }
			/>

			{ protectedField ? (
				<>
					<Badge tone="brand">
						{ __( 'WooCommerce field', 'wc-checkoutsuite' ) }
					</Badge>
					<p className="wccs-inspector__hint">
						{ protectionReason( field ) }
					</p>
				</>
			) : null }
		</div>
	);
}
