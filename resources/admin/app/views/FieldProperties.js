/**
 * The field's properties, as the design draws them.
 *
 * One panel, one field: a head that names it and shows its key, three tabs — Geral,
 * Regras, Exibição — the body belonging to the active tab, a sample of the control, and
 * a footer with the two actions that act on the field as a whole. The markup and the
 * classes are the prototype's (`roadmap/fields.html`).
 *
 * Nothing the previous panel could edit was dropped in the move, and the two places
 * where this panel is wider than the design are marked below: the per-viewport widths
 * (the prototype draws only the desktop one) and the storage vocabulary, which the
 * prototype does not draw at all because its fields are not stored.
 *
 * The settings a type declares — options, limits, placeholders — are rendered by
 * `SettingsControls`, which reads them from the catalogue. That is what keeps this panel
 * from inventing a control for a type it has never seen.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { destination as destinationEntry } from '../design/destinations';

import ConditionBuilder from '../components/ConditionBuilder';
import { SettingsControls } from '../components/SettingsControls';
import { isProtected, protectionReason } from '../schema/fieldOperations';
import {
	addBinding,
	bindingsFor,
	ensureBinding,
	removeBinding,
	removeDestinationBindings,
	updateBinding,
	withBindings,
} from '../schema/bindings';
import { Icon } from '../design/icons';
import { typeGlyph } from '../design/typeGlyph';
import { nativeFieldSummary, nativeFieldSupport } from '../schema/coreCheckout';

/**
 * Viewports a width can be set for.
 *
 * @type {Array<{key: string, label: string}>}
 */
const VIEWPORTS = [
	{ key: 'desktop', label: __( 'desktop', 'wc-checkoutsuite' ) },
	{ key: 'tablet', label: __( 'tablet', 'wc-checkoutsuite' ) },
	{ key: 'mobile', label: __( 'mobile', 'wc-checkoutsuite' ) },
];

/**
 * Column widths offered on the twelve column grid.
 *
 * @type {Array<number>}
 */
const WIDTHS = [ 12, 6, 4, 3 ];

/**
 * The design's form group: a label, a control and an optional help line.
 *
 * @param {Object} props          Component properties.
 * @param {string} props.id       Control identifier.
 * @param {string} props.label    Visible label.
 * @param {*}      props.children The control.
 * @param {string} [props.help]   Help line.
 * @return {*} Rendered group.
 */
function Group( { id, label, children, help = '' } ) {
	return (
		<div className="form-group">
			<label htmlFor={ id }>{ label }</label>
			{ children }
			{ '' !== help ? <p className="form-help">{ help }</p> : null }
		</div>
	);
}

/**
 * The design's switch row.
 *
 * @param {Object}   props            Component properties.
 * @param {string}   props.id         Control identifier.
 * @param {string}   props.label      Strong line.
 * @param {string}   props.help       Small line.
 * @param {boolean}  props.checked    Whether it is on.
 * @param {boolean}  [props.disabled] Whether it can be changed.
 * @param {Function} props.onToggle   Called with the new value.
 * @return {*} Rendered row.
 */
function SwitchRow( { id, label, help, checked, disabled = false, onToggle } ) {
	return (
		<div className="switch-row">
			<div>
				<strong>{ label }</strong>
				<small>{ help }</small>
			</div>
			<label className="toggle" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					checked={ checked }
					disabled={ disabled }
					aria-label={ label }
					onChange={ (
						/** @type {{ target: { checked: boolean } }} */ event
					) => onToggle( event.target.checked ) }
				/>
				<span />
			</label>
		</div>
	);
}

/**
 * The field's properties panel.
 *
 * @param {Object}                                              props                Component properties.
 * @param {import('../schema/types').FieldDefinition}           props.field          Field being edited.
 * @param {import('../schema/types').FieldCatalog|null}         props.catalog        Field catalogue.
 * @param {Array<{id: string, label: string}>}                  props.sections       Sections the field may move to.
 * @param {Array<{id: string, label: string, areas: string[]}>} [props.linkSections]
 *                                                                                   Sections each destination may point at.
 * @param {Function}                                            props.onChange       Called with a partial definition.
 * @param {Array<{id: string, label: string}>}                  [props.fields]       Fields a rule may read.
 * @param {Function}                                            [props.onDuplicate]  Duplicate the field.
 * @param {Function}                                            [props.onArchive]    Archive the field.
 * @param {Function}                                            [props.onProtect]    Explain why it is protected.
 * @param {string}                                              [props.reference]    Checkout the merchant is looking at (`blocks` or `classic`).
 * @return {*} Rendered element tree.
 */
export default function FieldProperties( {
	field,
	catalog,
	sections = [],
	linkSections = [],
	onChange,
	fields = [],
	onDuplicate,
	onArchive,
	onProtect,
	reference = '',
} ) {
	const [ tab, setTab ] = useState( 'general' );

	if ( ! field ) {
		return (
			<div className="empty-state">
				<Icon name="sliders" />
				<h3>
					{ __( 'Um campo, todos os detalhes.', 'wc-checkoutsuite' ) }
				</h3>
				<p>
					{ __(
						'Selecione um campo para editar suas propriedades.',
						'wc-checkoutsuite'
					) }
				</p>
			</div>
		);
	}

	const type = catalog?.types?.[ field.type ] ?? null;
	const vocabulary = /** @type {any} */ ( catalog?.vocabulary ?? {} );

	/**
	 * The catalogue's destinations, named the way the editor names them.
	 *
	 * The server's vocabulary is written for the store — its policy text lists those
	 * labels — and the editor has its own words for the same places (§2, §14): «Pedido»,
	 * «Painel do cliente», «Minha conta». The key is what the document stores; only what
	 * the merchant reads is replaced here.
	 *
	 * @param {any} entry Vocabulary entry.
	 * @return {string} Label.
	 */
	const destinationLabel = ( entry ) =>
		destinationEntry( entry.value )?.label ?? entry.label;
	const layout = /** @type {Record<string, number>} */ (
		/** @type {unknown} */ ( field.layout ?? {} )
	);
	const storesValue = false !== type?.supports?.value;
	const maskable = true === type?.supports?.maskable;
	// The file actions are the file's: showing a name, opening it, taking a copy,
	// approving it and sending a new version are decisions about a document, so a
	// type that stores no file is offered none of them.
	const storesFile = true === type?.supports?.file;
	const protectedField = isProtected( field );
	const mask = field.mask ?? null;
	const desktop = Number( field.layout?.desktop ?? 12 );

	const tabs = [
		{ id: 'general', label: __( 'Geral', 'wc-checkoutsuite' ) },
		{ id: 'rules', label: __( 'Regras', 'wc-checkoutsuite' ) },
		{ id: 'links', label: __( 'Vínculos', 'wc-checkoutsuite' ) },
		{ id: 'advanced', label: __( 'Exibição', 'wc-checkoutsuite' ) },
	];

	/** The approval flow, or the empty one. */
	const approval = field.approval ?? {};

	/**
	 * Writes the approval flow, or clears it.
	 *
	 * @param {any} changes What changed.
	 * @return {void}
	 */
	const changeApproval = ( changes ) =>
		onChange( {
			approval: { ...approval, ...changes },
		} );

	/**
	 * Writes the uses of this field, and the map they project to.
	 *
	 * The list is the authority and the map is derived from it, so the two are written
	 * together and cannot disagree — which is the rule the server keeps too.
	 *
	 * @param {Array<any>} bindings The uses.
	 * @return {void}
	 */
	const changeBindings = ( bindings ) =>
		onChange( withBindings( field, bindings ) );

	/**
	 * The controls of one use of the field in one destination.
	 *
	 * The first use keeps the identifiers the panel always had, so what the merchant sees
	 * and what a test addresses do not change when a second use appears; the ones after it
	 * are numbered.
	 *
	 * @param {any}    entry   Vocabulary entry of the destination.
	 * @param {any}    binding The use.
	 * @param {number} index   Position in this destination's list.
	 * @param {number} total   How many uses this destination has.
	 * @return {*} Rendered use.
	 */
	const renderUse = ( entry, binding, index, total ) => {
		const suffix = 0 === index ? '' : `-${ index + 1 }`;

		return (
			<div
				className="binding-row"
				key={ binding.id ?? `${ entry.value }-${ index }` }
			>
				{ total > 1 ? (
					<div className="binding-row-head">
						<span>
							{ sprintf(
								/* translators: %d: position of the use in the list. */
								__( 'Uso %d', 'wc-checkoutsuite' ),
								index + 1
							) }
						</span>
						<button
							type="button"
							className="text-btn"
							id={ `wccs-link-remove-${ entry.value }${ suffix }` }
							onClick={ () =>
								changeBindings(
									removeBinding( field, binding.id )
								)
							}
						>
							{ __( 'Remover uso', 'wc-checkoutsuite' ) }
						</button>
					</div>
				) : null }

				<Group
					id={ `wccs-link-section-${ entry.value }${ suffix }` }
					label={ __( 'Seção neste destino', 'wc-checkoutsuite' ) }
				>
					<select
						id={ `wccs-link-section-${ entry.value }${ suffix }` }
						className="input"
						value={ binding.container_id ?? '' }
						onChange={ (
							/** @type {{target: {value: string}}} */ event
						) =>
							changeBindings(
								updateBinding( field, binding.id, {
									container_id: event.target.value,
								} )
							)
						}
					>
						<option value="">
							{ __( 'Seção do campo', 'wc-checkoutsuite' ) }
						</option>
						{ /* Only the sections offered in this destination's
						     area: a section from another area would be
						     accepted by the form and refused by the server. */ }
						{ linkSections
							.filter( ( /** @type {any} */ option ) =>
								( option.areas ?? [] ).includes( entry.value )
							)
							.map( ( /** @type {any} */ option ) => (
								<option key={ option.id } value={ option.id }>
									{ option.label ?? option.id }
								</option>
							) ) }
					</select>
				</Group>

				<Group
					id={ `wccs-link-title-${ entry.value }${ suffix }` }
					label={ __( 'Título apresentado', 'wc-checkoutsuite' ) }
					help={ __(
						'Como o campo é chamado neste destino.',
						'wc-checkoutsuite'
					) }
				>
					<input
						id={ `wccs-link-title-${ entry.value }${ suffix }` }
						className="input"
						type="text"
						value={ binding.label_override ?? '' }
						onChange={ (
							/** @type {{target: {value: string}}} */ event
						) =>
							changeBindings(
								updateBinding( field, binding.id, {
									label_override: event.target.value,
								} )
							)
						}
					/>
				</Group>

				<Group
					id={ `wccs-link-position-${ entry.value }${ suffix }` }
					label={ __( 'Ordem de exibição', 'wc-checkoutsuite' ) }
				>
					<input
						id={ `wccs-link-position-${ entry.value }${ suffix }` }
						className="input"
						type="number"
						min={ 0 }
						step={ 10 }
						value={ binding.position ?? 0 }
						onChange={ (
							/** @type {{target: {value: string}}} */ event
						) =>
							changeBindings(
								updateBinding( field, binding.id, {
									position: Number( event.target.value ),
								} )
							)
						}
					/>
				</Group>

				{ /* Editable is a decision of the use, not of the field: the same
				     definition may be written here and only read there. */ }
				<SwitchRow
					id={ `wccs-link-editable-${ entry.value }${ suffix }` }
					label={ __( 'Quem lê pode alterar', 'wc-checkoutsuite' ) }
					help={ __(
						'Desligado, o valor é apenas mostrado neste destino.',
						'wc-checkoutsuite'
					) }
					checked={ false !== binding.editable }
					onToggle={ ( /** @type {boolean} */ next ) =>
						changeBindings(
							updateBinding( field, binding.id, {
								editable: next,
							} )
						)
					}
				/>

				{ storesFile ? (
					<>
						<div className="form-label">
							{ __( 'Ações permitidas', 'wc-checkoutsuite' ) }
						</div>
						{ ( vocabulary.destinationActions ?? [] )
							.filter( ( /** @type {any} */ action ) =>
								( entry.actions ?? [] ).includes( action.value )
							)
							.map( ( /** @type {any} */ action ) => (
								<SwitchRow
									key={ action.value }
									id={ `wccs-link-action-${ entry.value }-${ action.value }${ suffix }` }
									label={ action.label }
									help={ action.description ?? '' }
									checked={ (
										binding.permissions ?? []
									).includes( action.value ) }
									onToggle={ (
										/** @type {boolean} */ next
									) => {
										const current =
											binding.permissions ?? [];

										changeBindings(
											updateBinding( field, binding.id, {
												permissions: next
													? [
															...current,
															action.value,
													  ]
													: current.filter(
															(
																/** @type {string} */ key
															) =>
																key !==
																action.value
													  ),
											} )
										);
									} }
								/>
							) ) }
					</>
				) : null }
			</div>
		);
	};

	return (
		<>
			<div className="inspector-head">
				<div className="eyebrow">
					{ __( 'PROPRIEDADES DO CAMPO', 'wc-checkoutsuite' ) }
				</div>
				<div className="inspector-title">
					<div className="field-glyph" aria-hidden="true">
						{ typeGlyph( field.type ) }
					</div>
					<div>
						<h2 id="wccs-inspector-title">
							{ field.label ||
								__( 'Campo sem nome', 'wc-checkoutsuite' ) }
						</h2>
						<span
							className={
								'badge' + ( protectedField ? '' : ' purple' )
							}
						>
							{ type?.label ?? field.type }
						</span>
					</div>
				</div>
				<p className="inspector-key">
					<Icon name="lock" /> { field.id }
				</p>
			</div>

			<div
				className="inspector-tabs"
				role="group"
				aria-label={ __( 'Propriedades do campo', 'wc-checkoutsuite' ) }
			>
				{ tabs.map( ( entry ) => (
					<button
						key={ entry.id }
						type="button"
						className={ entry.id === tab ? 'active' : '' }
						aria-pressed={ entry.id === tab }
						onClick={ () => setTab( entry.id ) }
					>
						{ entry.label }
					</button>
				) ) }
			</div>

			<div className="inspector-body">
				{ 'general' === tab ? (
					<>
						{ /* §6.7: what this checkout will and will not take from an edit of a
						     native field, said before the merchant types, not after saving. */ }
						{ protectedField ? (
							<div
								className="native-support"
								id="wccs-native-support"
							>
								<p className="muted small">
									{ nativeFieldSummary( reference ) }
								</p>
								<ul>
									{ nativeFieldSupport( reference ).map(
										( property ) => (
											<li
												key={ property.key }
												id={ `wccs-native-${ property.key }` }
											>
												<span aria-hidden="true">
													{ property.applied
														? '✓'
														: '—' }
												</span>{ ' ' }
												{ property.label }
												<small className="muted">
													{ property.applied
														? __(
																'aplicado',
																'wc-checkoutsuite'
														  )
														: __(
																'da plataforma',
																'wc-checkoutsuite'
														  ) }
												</small>
											</li>
										)
									) }
								</ul>
							</div>
						) : null }

						<Group
							id="wccs-field-label"
							label={ __( 'Nome do campo', 'wc-checkoutsuite' ) }
						>
							<input
								id="wccs-field-label"
								className="input"
								maxLength={ 100 }
								value={ field.label ?? '' }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) => onChange( { label: event.target.value } ) }
							/>
						</Group>

						<Group
							id="wccs-field-key"
							label={ __(
								'Chave de integração',
								'wc-checkoutsuite'
							) }
							help={ __(
								'Chave estável. Alterar o nome não altera o armazenamento.',
								'wc-checkoutsuite'
							) }
						>
							<input
								id="wccs-field-key"
								className="input"
								readOnly
								value={ field.id }
							/>
						</Group>

						<Group
							id="wccs-field-description"
							label={ __(
								'Descrição de ajuda',
								'wc-checkoutsuite'
							) }
							help={ __(
								'Informação exibida abaixo do campo.',
								'wc-checkoutsuite'
							) }
						>
							<textarea
								id="wccs-field-description"
								className="input"
								rows={ 2 }
								maxLength={ 500 }
								value={ field.description ?? '' }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) =>
									onChange( {
										description: event.target.value,
									} )
								}
							/>
						</Group>

						{ /* The controls a type declares, read from the catalogue. */ }
						<SettingsControls
							schema={ type?.settingsSchema }
							value={ field.settings }
							onChange={ (
								/** @type {Record<string, any>} */ settings
							) => onChange( { settings } ) }
						/>

						<div className="divider" />

						<SwitchRow
							id="wccs-field-required"
							label={ __(
								'Campo obrigatório',
								'wc-checkoutsuite'
							) }
							help={
								protectedField && field.required
									? protectionReason( field )
									: __(
											'Exigido apenas quando o campo estiver visível.',
											'wc-checkoutsuite'
									  )
							}
							checked={ Boolean( field.required ) }
							disabled={ protectedField && field.required }
							onToggle={ ( /** @type {boolean} */ next ) =>
								onChange( { required: next } )
							}
						/>

						<SwitchRow
							id="wccs-field-enabled"
							label={ __(
								'Campo habilitado',
								'wc-checkoutsuite'
							) }
							help={ __(
								'Desativar preserva a definição e os valores guardados.',
								'wc-checkoutsuite'
							) }
							checked={ Boolean( field.enabled ) }
							onToggle={ ( /** @type {boolean} */ next ) =>
								onChange( { enabled: next } )
							}
						/>

						<div className="divider" />

						<div className="form-group">
							<span className="form-label">
								{ __(
									'Largura no desktop',
									'wc-checkoutsuite'
								) }
							</span>
							<div
								className="width-buttons"
								role="group"
								aria-label={ __(
									'Largura do campo',
									'wc-checkoutsuite'
								) }
							>
								{ [ 12, 6, 4 ].map( ( width ) => (
									<button
										key={ width }
										type="button"
										className={
											desktop === width ? 'active' : ''
										}
										aria-pressed={ desktop === width }
										onClick={ () =>
											onChange( {
												layout: {
													...( field.layout ?? {} ),
													desktop: width,
												},
											} )
										}
									>
										{ Math.round( ( width / 12 ) * 100 ) }%
									</button>
								) ) }
							</div>
							<p className="form-help">
								{ __(
									'Tablet e mobile acompanham o grid responsivo do checkout.',
									'wc-checkoutsuite'
								) }
							</p>
						</div>

						{ /* The prototype draws one width. This plugin stores one per
						     viewport, so the other two keep a control rather than
						     losing the setting. */ }
						<div className="form-row">
							{ VIEWPORTS.filter(
								( viewport ) => 'desktop' !== viewport.key
							).map( ( viewport ) => (
								<Group
									key={ viewport.key }
									id={ `wccs-field-width-${ viewport.key }` }
									label={ sprintf(
										/* translators: %s: device name. */
										__(
											'Largura em %s',
											'wc-checkoutsuite'
										),
										viewport.label
									) }
								>
									<select
										id={ `wccs-field-width-${ viewport.key }` }
										className="input"
										value={ String(
											layout[ viewport.key ] ?? 12
										) }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onChange( {
												layout: {
													...( field.layout ?? {} ),
													[ viewport.key ]: Number(
														event.target.value
													),
												},
											} )
										}
									>
										{ WIDTHS.map( ( width ) => (
											<option
												key={ width }
												value={ String( width ) }
											>
												{ sprintf(
													/* translators: %d: number of columns. */
													__(
														'%d de 12 colunas',
														'wc-checkoutsuite'
													),
													width
												) }
											</option>
										) ) }
									</select>
								</Group>
							) ) }
						</div>

						<Group
							id="wccs-field-section"
							label={ __( 'Seção', 'wc-checkoutsuite' ) }
							help={
								protectedField
									? __(
											'Campo nativo permanece no mapeamento de origem.',
											'wc-checkoutsuite'
									  )
									: __(
											'Mover para outra seção preserva a chave.',
											'wc-checkoutsuite'
									  )
							}
						>
							<select
								id="wccs-field-section"
								className="input"
								disabled={ protectedField }
								value={ field.section ?? 'order' }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) =>
									onChange( {
										section: event.target.value,
									} )
								}
							>
								{ 0 === sections.length ? (
									<option value={ field.section ?? 'order' }>
										{ field.section ?? 'order' }
									</option>
								) : (
									sections.map( ( entry ) => (
										<option
											key={ entry.id }
											value={ entry.id }
										>
											{ entry.label ?? entry.id }
										</option>
									) )
								) }
							</select>
						</Group>
					</>
				) : null }

				{ 'rules' === tab ? (
					<>
						<div className="inspector-note">
							<Icon name="info" />
							<strong>
								{ __(
									'Máscara não é validação.',
									'wc-checkoutsuite'
								) }
							</strong>
							<br />
							{ __(
								'A máscara formata o que é digitado; o servidor rejeita o valor adulterado de qualquer forma.',
								'wc-checkoutsuite'
							) }
						</div>

						<div className="divider" />

						{ maskable ? (
							<Group
								id="wccs-field-mask"
								label={ __(
									'Máscara de entrada',
									'wc-checkoutsuite'
								) }
								help={ __(
									'A máscara molda o que é digitado. Nunca substitui a validação.',
									'wc-checkoutsuite'
								) }
							>
								<select
									id="wccs-field-mask"
									className="input"
									value={ mask?.key ?? '' }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) => {
										const chosen = (
											catalog?.masks ?? []
										).find(
											( /** @type {any} */ entry ) =>
												entry.key === event.target.value
										);

										onChange( {
											mask: chosen
												? {
														key: chosen.key,
														version: chosen.version,
												  }
												: null,
										} );
									} }
								>
									<option value="">
										{ __(
											'Sem máscara',
											'wc-checkoutsuite'
										) }
									</option>
									{ ( catalog?.masks ?? [] ).map(
										( /** @type {any} */ entry ) => (
											<option
												key={ entry.key }
												value={ entry.key }
											>
												{ entry.label ?? entry.key }
											</option>
										)
									) }
								</select>
							</Group>
						) : (
							<p className="form-help">
								{ __(
									'Este tipo não pode ser mascarado, portanto nenhuma máscara é oferecida.',
									'wc-checkoutsuite'
								) }
							</p>
						) }

						<div className="divider" />

						<div className="form-label">
							{ __(
								'Quando exibir este campo?',
								'wc-checkoutsuite'
							) }
						</div>
						<ConditionBuilder
							value={ field.conditions ?? {} }
							vocabulary={ catalog?.conditions ?? {} }
							fieldId={ field.id ?? '' }
							fields={ fields }
							onChange={ ( /** @type {any} */ conditions ) =>
								onChange( { conditions } )
							}
						/>

						{ storesValue ? (
							<Group
								id="wccs-field-hidden-policy"
								label={ __(
									'Ao ocultar o campo',
									'wc-checkoutsuite'
								) }
								help={ __(
									'Um campo oculto não aplica obrigatoriedade. Preservar exige política explícita.',
									'wc-checkoutsuite'
								) }
							>
								<select
									id="wccs-field-hidden-policy"
									className="input"
									value={
										field.hidden_value_policy ?? 'discard'
									}
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										onChange( {
											hidden_value_policy:
												event.target.value,
										} )
									}
								>
									{ (
										vocabulary.hiddenValuePolicies ?? []
									).map( ( /** @type {any} */ entry ) => (
										<option
											key={ entry.value }
											value={ entry.value }
										>
											{ entry.label }
										</option>
									) ) }
								</select>
							</Group>
						) : null }
					</>
				) : null }

				{ 'links' === tab ? (
					<>
						<div className="form-label">
							{ __( 'Vínculos e exibição', 'wc-checkoutsuite' ) }
						</div>
						<p className="form-help">
							{ __(
								'Cada destino decide por si. Publicar o campo não o mostra em lugar nenhum: só aparece onde for vinculado aqui.',
								'wc-checkoutsuite'
							) }
						</p>

						{ ( vocabulary.destinations ?? [] ).map(
							( /** @type {any} */ entry ) => {
								const uses = bindingsFor( field, entry.value );
								const on = uses.length > 0;

								return (
									<div
										className="condition-row"
										key={ entry.value }
										role="group"
										aria-label={ destinationLabel( entry ) }
									>
										<div className="condition-row-head">
											<span>
												{ destinationLabel( entry ) }
											</span>
										</div>

										{ /* Using the field there and showing it there are
										     the same statement (§3.3): the switch adds the
										     first use, and turning it off removes them all. */ }
										<SwitchRow
											id={ `wccs-link-${ entry.value }` }
											label={ sprintf(
												/* translators: %s: destination name. */
												__(
													'Mostrar em %s',
													'wc-checkoutsuite'
												),
												destinationLabel( entry )
											) }
											help={ entry.description ?? '' }
											checked={ on }
											onToggle={ (
												/** @type {boolean} */ next
											) =>
												changeBindings(
													next
														? ensureBinding(
																field,
																entry.value
														  )
														: removeDestinationBindings(
																field,
																entry.value
														  )
												)
											}
										/>

										{ on ? (
											<>
												{ uses.map(
													(
														/** @type {any} */ binding,
														/** @type {number} */ index
													) =>
														renderUse(
															entry,
															binding,
															index,
															uses.length
														)
												) }

												<button
													type="button"
													className="text-btn"
													id={ `wccs-link-add-${ entry.value }` }
													onClick={ () =>
														changeBindings(
															addBinding(
																field,
																entry.value,
																''
															)
														)
													}
												>
													{ __(
														'Adicionar uso nesta área',
														'wc-checkoutsuite'
													) }
												</button>
											</>
										) : null }
									</div>
								);
							}
						) }

						<div className="divider" />

						<div className="form-label">
							{ __( 'Fluxo de aprovação', 'wc-checkoutsuite' ) }
						</div>
						<p className="form-help">
							{ __(
								'Desligado, isto é apenas uma informação vinculada ao pedido: não muda estado nem bloqueia o processamento.',
								'wc-checkoutsuite'
							) }
						</p>
						<SwitchRow
							id="wccs-approval-required"
							label={ __(
								'Exigir análise manual',
								'wc-checkoutsuite'
							) }
							help=""
							checked={ Boolean( approval.require_review ) }
							onToggle={ ( /** @type {boolean} */ next ) =>
								changeApproval(
									next
										? {
												// A suggestion, written where the
												// merchant can see and change it: the
												// server refuses a flow that names no
												// state rather than inventing one.
												require_review: true,
												status:
													approval.status ??
													'Pendente de aprovação',
										  }
										: { require_review: false }
								)
							}
						/>

						{ approval.require_review ? (
							<>
								<Group
									id="wccs-approval-area"
									label={ __(
										'Área de análise',
										'wc-checkoutsuite'
									) }
									help={ __(
										'Obrigatória: sem ela o servidor recusa a configuração em vez de a completar por ti.',
										'wc-checkoutsuite'
									) }
								>
									<select
										id="wccs-approval-area"
										className="input"
										value={ approval.area ?? '' }
										onChange={ (
											/** @type {{target: {value: string}}} */ event
										) =>
											changeApproval( {
												area: event.target.value,
											} )
										}
									>
										<option value="">
											{ __(
												'Escolha uma área',
												'wc-checkoutsuite'
											) }
										</option>
										{ ( vocabulary.destinations ?? [] ).map(
											( /** @type {any} */ entry ) => (
												<option
													key={ entry.value }
													value={ entry.value }
												>
													{ destinationLabel(
														entry
													) }
												</option>
											)
										) }
									</select>
								</Group>

								<Group
									id="wccs-approval-section"
									label={ __(
										'Seção da análise',
										'wc-checkoutsuite'
									) }
								>
									<input
										id="wccs-approval-section"
										className="input"
										type="text"
										value={ approval.section ?? '' }
										onChange={ (
											/** @type {{target: {value: string}}} */ event
										) =>
											changeApproval( {
												section: event.target.value,
											} )
										}
									/>
								</Group>

								<Group
									id="wccs-approval-status"
									label={ __(
										'Estado durante a análise',
										'wc-checkoutsuite'
									) }
								>
									<input
										id="wccs-approval-status"
										className="input"
										type="text"
										value={ approval.status ?? '' }
										onChange={ (
											/** @type {{target: {value: string}}} */ event
										) =>
											changeApproval( {
												status: event.target.value,
											} )
										}
									/>
								</Group>

								<SwitchRow
									id="wccs-approval-correction"
									label={ __(
										'Permitir pedido de correção',
										'wc-checkoutsuite'
									) }
									help=""
									checked={ Boolean(
										approval.allow_correction
									) }
									onToggle={ (
										/** @type {boolean} */ next
									) =>
										changeApproval( {
											allow_correction: next,
										} )
									}
								/>
								<SwitchRow
									id="wccs-approval-resubmit"
									label={ __(
										'Permitir reenvio pelo cliente',
										'wc-checkoutsuite'
									) }
									help=""
									checked={ Boolean(
										approval.allow_resubmit
									) }
									onToggle={ (
										/** @type {boolean} */ next
									) =>
										changeApproval( {
											allow_resubmit: next,
										} )
									}
								/>
								<SwitchRow
									id="wccs-approval-show"
									label={ __(
										'Mostrar a situação ao cliente',
										'wc-checkoutsuite'
									) }
									help=""
									checked={ Boolean( approval.show_status ) }
									onToggle={ (
										/** @type {boolean} */ next
									) =>
										changeApproval( {
											show_status: next,
										} )
									}
								/>
							</>
						) : null }

						<div className="divider" />

						{ /* §7.7 and §10.4: the value's journey is decided direction by direction, and
						     nothing is assumed bidirectional. */ }
						<div className="form-label">
							{ __( 'Fluxo do valor', 'wc-checkoutsuite' ) }
						</div>
						<p className="form-help">
							{ __(
								'Cada direção é uma decisão. Quem pode alterar em Minha conta e no painel da equipa é decidido em cada vínculo acima; aqui decide-se o que viaja até ao checkout.',
								'wc-checkoutsuite'
							) }
						</p>
						<SwitchRow
							id="wccs-sync-to-checkout"
							label={ __(
								'Perfil → checkout',
								'wc-checkoutsuite'
							) }
							help={ __(
								'O checkout começa com o valor que o cliente já tem no perfil.',
								'wc-checkoutsuite'
							) }
							checked={ Boolean( field.sync?.to_checkout ) }
							onToggle={ ( /** @type {boolean} */ next ) =>
								onChange( {
									sync: {
										...( field.sync ?? {} ),
										to_checkout: next,
									},
								} )
							}
						/>
					</>
				) : null }

				{ 'advanced' === tab ? (
					<>
						<div className="inspector-note">
							<Icon name="lock" />
							<strong>
								{ __( 'Contrato estável', 'wc-checkoutsuite' ) }
							</strong>
							<br />
							{ sprintf(
								/* translators: 1: type key, 2: origin. */
								__(
									'Tipo: %1$s · Origem: %2$s',
									'wc-checkoutsuite'
								),
								field.type,
								protectedField
									? __( 'WooCommerce', 'wc-checkoutsuite' )
									: __(
											'Personalizado da Suite',
											'wc-checkoutsuite'
									  )
							) }
							<br />
							{ __(
								'Trocar tipo ou normalização exige migração.',
								'wc-checkoutsuite'
							) }
						</div>

						<div className="divider" />

						<span className="form-label">
							{ __(
								'Armazenamento e visibilidade',
								'wc-checkoutsuite'
							) }
						</span>

						{ protectedField ? (
							<p className="form-help">
								{ protectionReason( field ) }
							</p>
						) : (
							<>
								<Group
									id="wccs-field-storage-scope"
									label={ __(
										'Onde o valor é guardado',
										'wc-checkoutsuite'
									) }
								>
									<select
										id="wccs-field-storage-scope"
										className="input"
										disabled={ ! storesValue }
										value={ field.storage?.scope ?? 'none' }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onChange( {
												storage: {
													sensitivity:
														field.storage
															?.sensitivity ??
														'personal',
													scope: event.target.value,
												},
											} )
										}
									>
										{ (
											vocabulary.storageScopes ?? []
										).map( ( /** @type {any} */ entry ) => (
											<option
												key={ entry.value }
												value={ entry.value }
											>
												{ destinationLabel( entry ) }
											</option>
										) ) }
									</select>
								</Group>

								<Group
									id="wccs-field-storage-sensitivity"
									label={ __(
										'Quão sensível é o valor',
										'wc-checkoutsuite'
									) }
								>
									<select
										id="wccs-field-storage-sensitivity"
										className="input"
										value={
											field.storage?.sensitivity ??
											'personal'
										}
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onChange( {
												storage: {
													scope:
														field.storage?.scope ??
														'order',
													sensitivity:
														event.target.value,
												},
											} )
										}
									>
										{ (
											vocabulary.storageSensitivities ??
											[]
										).map( ( /** @type {any} */ entry ) => (
											<option
												key={ entry.value }
												value={ entry.value }
											>
												{ destinationLabel( entry ) }
											</option>
										) ) }
									</select>
								</Group>

								<div className="inspector-note">
									{ __(
										'API pública: não expor. Arquivos e dados pessoais exigem autorização específica.',
										'wc-checkoutsuite'
									) }
								</div>
							</>
						) }

						<div className="divider" />

						<div className="form-label">
							{ __(
								'Identidade e compatibilidade',
								'wc-checkoutsuite'
							) }
						</div>
						<dl className="wccs-inspector__identity">
							<dt>
								{ __( 'Identificador', 'wc-checkoutsuite' ) }
							</dt>
							<dd>{ field.id }</dd>
							<dt>
								{ __(
									'Chave de integração',
									'wc-checkoutsuite'
								) }
							</dt>
							<dd>{ field.integration_id }</dd>
							<dt>{ __( 'Tipo', 'wc-checkoutsuite' ) }</dt>
							<dd>{ field.type }</dd>
							<dt>{ __( 'Preset', 'wc-checkoutsuite' ) }</dt>
							<dd>{ field.preset ?? '—' }</dd>
						</dl>
					</>
				) : null }
			</div>

			<div className="inline-sample">
				<div className="form-label">
					{ __( 'PRÉVIA DO CAMPO', 'wc-checkoutsuite' ) }
					<span>{ __( 'AMOSTRA', 'wc-checkoutsuite' ) }</span>
				</div>
				<div className="sample-box">
					<span className="sample-label">
						{ field.label ||
							__( 'Campo sem nome', 'wc-checkoutsuite' ) }
						{ field.required ? (
							<span className="required-star">*</span>
						) : null }
					</span>
					<input
						className="sample-input"
						readOnly
						tabIndex={ -1 }
						placeholder={ __(
							'Amostra do campo',
							'wc-checkoutsuite'
						) }
						value={ field.description ?? '' }
					/>
				</div>
			</div>

			<div className="inspector-footer">
				{ onDuplicate ? (
					<button
						type="button"
						className="text-btn"
						onClick={ () => onDuplicate?.() }
					>
						<Icon name="copy" />
						{ __( 'Duplicar', 'wc-checkoutsuite' ) }
					</button>
				) : null }

				{ protectedField ? (
					<button
						type="button"
						className="text-btn"
						onClick={ () => onProtect?.() }
					>
						<Icon name="shield" />
						{ __( 'Nativo protegido', 'wc-checkoutsuite' ) }
					</button>
				) : (
					<button
						type="button"
						className="text-btn danger"
						onClick={ () => onArchive?.() }
					>
						<Icon name="archive" />
						{ __( 'Arquivar', 'wc-checkoutsuite' ) }
					</button>
				) }
			</div>
		</>
	);
}
