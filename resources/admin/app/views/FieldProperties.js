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

import ConditionBuilder from '../components/ConditionBuilder';
import { SettingsControls } from '../components/SettingsControls';
import { isProtected, protectionReason } from '../schema/fieldOperations';
import { Icon } from '../design/icons';
import { typeGlyph } from '../design/typeGlyph';

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
 * @param {Object}                                      props               Component properties.
 * @param {import('../schema/types').FieldDefinition}   props.field         Field being edited.
 * @param {import('../schema/types').FieldCatalog|null} props.catalog       Field catalogue.
 * @param {Array<{id: string, label: string}>}          props.sections      Sections the field may move to.
 * @param {Function}                                    props.onChange      Called with a partial definition.
 * @param {Array<{id: string, label: string}>}          [props.fields]      Fields a rule may read.
 * @param {Function}                                    [props.onDuplicate] Duplicate the field.
 * @param {Function}                                    [props.onArchive]   Archive the field.
 * @param {Function}                                    [props.onProtect]   Explain why it is protected.
 * @return {*} Rendered element tree.
 */
export default function FieldProperties( {
	field,
	catalog,
	sections = [],
	onChange,
	fields = [],
	onDuplicate,
	onArchive,
	onProtect,
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
	const layout = /** @type {Record<string, number>} */ (
		/** @type {unknown} */ ( field.layout ?? {} )
	);
	const storesValue = false !== type?.supports?.value;
	const maskable = true === type?.supports?.maskable;
	const protectedField = isProtected( field );
	const mask = field.mask ?? null;
	const desktop = Number( field.layout?.desktop ?? 12 );

	const tabs = [
		{ id: 'general', label: __( 'Geral', 'wc-checkoutsuite' ) },
		{ id: 'rules', label: __( 'Regras', 'wc-checkoutsuite' ) },
		{ id: 'advanced', label: __( 'Exibição', 'wc-checkoutsuite' ) },
	];

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
												{ entry.label }
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
												{ entry.label }
											</option>
										) ) }
									</select>
								</Group>

								<div className="divider" />

								<span className="form-label">
									{ __(
										'Onde o valor pode aparecer',
										'wc-checkoutsuite'
									) }
								</span>

								{ ( vocabulary.visibilityKeys ?? [] ).map(
									( /** @type {any} */ entry ) => (
										<SwitchRow
											key={ entry.value }
											id={ `wccs-field-visibility-${ entry.value }` }
											label={ entry.label }
											help={ entry.description ?? '' }
											checked={ Boolean(
												field.visibility?.[
													entry.value
												]
											) }
											onToggle={ (
												/** @type {boolean} */ next
											) =>
												onChange( {
													visibility: {
														...( field.visibility ??
															{} ),
														[ entry.value ]: next,
													},
												} )
											}
										/>
									)
								) }

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
