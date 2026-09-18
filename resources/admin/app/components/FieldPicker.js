/**
 * The picker: choosing a field to add, as the design draws it.
 *
 * Two steps, in one dialog. The first is the catalogue — categories down the side, the
 * types and presets in a grid, a search over both — and the second configures the one
 * that was chosen: its name, its key, the section it belongs to, how wide it is and
 * whether it is required, with a sample beside it.
 *
 * The list is the server's, not this file's. Types come from the registry through the
 * catalogue route, so a type another plugin registers appears here without this file
 * knowing it exists; the marks beside them are the design's, and a type the design never
 * drew gets a stable fallback rather than no glyph at all.
 *
 * WooCommerce's own checkout fields are the design's missing half: the prototype's
 * catalogue is entirely its own types, and this plugin lets a merchant customise a field
 * WooCommerce already has. They are a category of their own here, drawn with the same
 * card, so the capability is not lost to the port.
 *
 * @package
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ConditionBuilder from './ConditionBuilder';
import { SettingsControls } from './SettingsControls';
import { Icon } from '../design/icons';
import { typeGlyph } from '../design/typeGlyph';

/**
 * Glyph per server category, from the design's own set.
 *
 * @type {Record<string, string>}
 */
const CATEGORY_GLYPHS = {
	all: 'fields',
	text: 'edit',
	choice: 'sliders',
	datetime: 'calendar',
	number: 'barcode',
	address: 'location',
	upload: 'upload',
	layout: 'layers',
	general: 'code',
	presets: 'location',
	core: 'shield',
};

/**
 * Turns a label into a key the schema accepts.
 *
 * The server owns the rule and refuses a bad key; this only proposes something valid so
 * the merchant is not asked to type a key before they have named the field.
 *
 * @param {string} label Label.
 * @return {string} Suggested key.
 */
function suggestedKey( label ) {
	const slug = label
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 64 );

	if ( '' === slug ) {
		return 'campo';
	}

	return /^[a-z]/.test( slug ) ? slug : `campo_${ slug }`;
}

/**
 * The picker.
 *
 * @param {Object}                                            props                 Component properties.
 * @param {import('../schema/types').FieldCatalog|null}       props.catalog         Field catalogue.
 * @param {import('../schema/types').CoreFieldInventory|null} props.coreFields      WooCommerce's own fields.
 * @param {string}                                            props.section         Section a new field joins.
 * @param {Array<{id: string, label: string}>}                props.sections        Sections to choose from.
 * @param {Function}                                          props.onSectionChange Called with a section id.
 * @param {Function}                                          props.onChooseType    Called with the choice to create.
 * @param {Function}                                          props.onAdoptCore     Called with a core field to adopt.
 * @param {Function}                                          props.onClose         Called to close the dialog.
 * @param {boolean}                                           [props.open]          Whether the dialog is open.
 * @param {'checkout'|'my_account'}                           [props.surface]       Where a new field is collected.
 * @param {boolean}                                           [props.lockedSection] Whether the section is fixed by the editor context.
 * @param {string}                                            [props.sectionLabel]  Human label for the fixed section.
 * @return {*} Rendered element tree.
 */
export default function FieldPicker( {
	catalog,
	coreFields,
	section,
	sections,
	onSectionChange,
	onChooseType,
	onAdoptCore,
	onClose,
	open = false,
	surface = 'checkout',
	lockedSection = false,
	sectionLabel = '',
} ) {
	const [ category, setCategory ] = useState( 'all' );
	const [ query, setQuery ] = useState( '' );
	const [ chosen, setChosen ] = useState( /** @type {any} */ ( null ) );
	const [ label, setLabel ] = useState( '' );
	const [ key, setKey ] = useState( '' );
	const [ required, setRequired ] = useState( false );
	const [ width, setWidth ] = useState( 12 );
	const [ description, setDescription ] = useState( '' );
	const [ settings, setSettings ] = useState(
		/** @type {Record<string, any>} */ ( {} )
	);
	const [ conditions, setConditions ] = useState(
		/** @type {Record<string, any>} */ ( {} )
	);

	// The dialog stays mounted so the native <dialog> can close gracefully. Reset only
	// on the closed -> open transition; resetting on every render would erase a choice
	// while the merchant is filling its configuration.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		setCategory( 'all' );
		setQuery( '' );
		setChosen( null );
		setLabel( '' );
		setKey( '' );
		setRequired( false );
		setWidth( 12 );
		setDescription( '' );
		setSettings( {} );
		setConditions( {} );
	}, [ open ] );

	/** Every entry the catalogue offers: types, presets and WooCommerce's own fields. */
	const entries = useMemo( () => {
		const list = /** @type {Array<any>} */ ( [] );

		( catalog?.categories ?? [] ).forEach( ( /** @type {any} */ group ) => {
			( group.types ?? [] ).forEach( ( /** @type {any} */ type ) => {
				list.push( {
					kind: 'type',
					key: type.key,
					label: type.label,
					category: group.key,
					categoryLabel: group.label,
					glyph: typeGlyph( type.key ),
				} );
			} );
		} );

		( catalog?.presets ?? [] ).forEach( ( /** @type {any} */ preset ) => {
			list.push( {
				kind: 'preset',
				key: preset.key,
				type: preset.type,
				label: preset.label,
				category: 'presets',
				categoryLabel: __( 'Presets Brasil', 'wc-checkoutsuite' ),
				glyph: typeGlyph( preset.type ),
				preset,
			} );
		} );

		( coreFields?.fields ?? [] ).forEach( ( /** @type {any} */ core ) => {
			list.push( {
				kind: 'core',
				key: core.id,
				label: core.label ?? core.id,
				category: 'core',
				categoryLabel: __(
					'Campos da WooCommerce',
					'wc-checkoutsuite'
				),
				glyph: typeGlyph( core.type ?? 'text' ),
				core,
			} );
		} );

		return list;
	}, [ catalog, coreFields ] );

	/** The design's category rail, built from what the catalogue publishes. */
	const categories = useMemo( () => {
		const rail = /** @type {Array<any>} */ ( [
			{
				key: 'all',
				label: __( 'Todos os campos', 'wc-checkoutsuite' ),
				count: entries.length,
			},
		] );

		( catalog?.categories ?? [] ).forEach( ( /** @type {any} */ group ) => {
			if ( 0 === ( group.types ?? [] ).length ) {
				return;
			}

			rail.push( {
				key: group.key,
				label: group.label,
				count: group.types.length,
			} );
		} );

		if ( ( catalog?.presets ?? [] ).length > 0 ) {
			rail.push( {
				key: 'presets',
				label: __( 'Presets Brasil', 'wc-checkoutsuite' ),
				count: catalog?.presets?.length ?? 0,
			} );
		}

		if ( ( coreFields?.fields ?? [] ).length > 0 ) {
			rail.push( {
				key: 'core',
				label: __( 'Campos da WooCommerce', 'wc-checkoutsuite' ),
				count: coreFields?.fields?.length ?? 0,
			} );
		}

		return rail;
	}, [ catalog, coreFields, entries ] );

	const normalise = ( /** @type {string} */ value ) =>
		value
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' );

	const visible = entries.filter( ( entry ) => {
		const matchesCategory =
			'all' === category || entry.category === category;

		if ( 'my_account' === surface ) {
			const type =
				catalog?.types?.[ entry.preset?.type ?? entry.key ] ?? null;

			// Account fields are customer-owned. Native checkout fields remain out of
			// this picker, while uploads are allowed because the account renderer has
			// its own multipart form and private document store.
			if ( 'core' === entry.kind || ! type?.supports?.value ) {
				return false;
			}
		}

		const haystack = normalise(
			`${ entry.label } ${ entry.key } ${ entry.categoryLabel }`
		);

		return matchesCategory && haystack.includes( normalise( query ) );
	} );

	/**
	 * Opens the configure step for one entry.
	 *
	 * A WooCommerce field is not created: it is adopted, so it goes straight back to
	 * the caller and the second step never opens for it.
	 *
	 * @param {any} entry Chosen entry.
	 * @return {void}
	 */
	const choose = ( entry ) => {
		if ( 'core' === entry.kind ) {
			onAdoptCore( entry.core );

			return;
		}

		setChosen( entry );
		setLabel( entry.label );
		setKey( suggestedKey( entry.key ) );
		setRequired( false );
		setWidth( 12 );
		// The form is the field's first version, so it starts from the preset and
		// from nothing else — never from the field chosen before it.
		setDescription( '' );
		setSettings(
			/** @type {Record<string, any>} */ ( entry.preset?.settings ?? {} )
		);
		setConditions( {} );
	};

	// What the chosen type declares about itself. Read from the catalogue, because a
	// control for a type this build has never seen cannot be invented here.
	const chosenType = chosen
		? catalog?.types?.[ chosen.preset?.type ?? chosen.key ] ?? null
		: null;

	if ( chosen ) {
		return (
			<>
				<div className="create-body">
					<div>
						<div className="form-group">
							<label htmlFor="wccs-new-label">
								{ __( 'Nome do campo *', 'wc-checkoutsuite' ) }
							</label>
							<input
								id="wccs-new-label"
								className="input"
								maxLength={ 100 }
								value={ label }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) => setLabel( event.target.value ) }
							/>
						</div>

						<div className="form-group">
							<label htmlFor="wccs-new-key">
								{ __(
									'Chave de integração *',
									'wc-checkoutsuite'
								) }
							</label>
							<input
								id="wccs-new-key"
								className="input"
								maxLength={ 64 }
								spellCheck={ false }
								value={ key }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) => setKey( event.target.value ) }
							/>
							<p className="form-help">
								{ __(
									'Identifica este campo para integrações e valores salvos. Use uma chave estável, como billing_document; ela não é o nome exibido. Letras minúsculas, números e underscore; comece com uma letra. Depois de publicar, não a altere.',
									'wc-checkoutsuite'
								) }
							</p>
						</div>

						<div className="form-group">
							<label htmlFor="wccs-new-description">
								{ __(
									'Descrição (opcional)',
									'wc-checkoutsuite'
								) }
							</label>
							<textarea
								id="wccs-new-description"
								className="input"
								rows={ 3 }
								maxLength={ 280 }
								value={ description }
								placeholder={ __(
									'Explique ao cliente o que enviar aqui.',
									'wc-checkoutsuite'
								) }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) => setDescription( event.target.value ) }
							/>
						</div>

						<div className="form-group">
							{ lockedSection ? (
								<>
									<p className="form-label">
										{ __(
											'Seção atual',
											'wc-checkoutsuite'
										) }
									</p>
									<div
										className="input field-picker-context"
										aria-label={ __(
											'Seção atual',
											'wc-checkoutsuite'
										) }
									>
										{ sectionLabel || section }
									</div>
									<p className="form-help">
										{ __(
											'O campo será criado nesta seção do checkout selecionado.',
											'wc-checkoutsuite'
										) }
									</p>
								</>
							) : (
								<>
									<label htmlFor="wccs-new-section">
										{ __( 'Seção', 'wc-checkoutsuite' ) }
									</label>
									<select
										id="wccs-new-section"
										className="input"
										value={ section }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											onSectionChange(
												event.target.value
											)
										}
									>
										{ sections.map(
											( /** @type {any} */ entry ) => (
												<option
													key={ entry.id }
													value={ entry.id }
												>
													{ entry.label ?? entry.id }
												</option>
											)
										) }
									</select>
								</>
							) }
						</div>

						<div className="form-row">
							<div className="form-group">
								<label htmlFor="wccs-new-width">
									{ __(
										'Largura no desktop',
										'wc-checkoutsuite'
									) }
								</label>
								<select
									id="wccs-new-width"
									className="input"
									value={ String( width ) }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										setWidth( Number( event.target.value ) )
									}
								>
									<option value="12">
										{ __(
											'100% · linha inteira',
											'wc-checkoutsuite'
										) }
									</option>
									<option value="6">
										{ __(
											'50% · meia linha',
											'wc-checkoutsuite'
										) }
									</option>
									<option value="4">
										{ __(
											'33% · um terço',
											'wc-checkoutsuite'
										) }
									</option>
								</select>
							</div>

							<div className="form-group">
								<label htmlFor="wccs-new-required">
									{ __(
										'Obrigatoriedade',
										'wc-checkoutsuite'
									) }
								</label>
								<select
									id="wccs-new-required"
									className="input"
									value={ required ? 'true' : 'false' }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										setRequired(
											'true' === event.target.value
										)
									}
								>
									<option value="false">
										{ __( 'Opcional', 'wc-checkoutsuite' ) }
									</option>
									<option value="true">
										{ __(
											'Obrigatório',
											'wc-checkoutsuite'
										) }
									</option>
								</select>
							</div>
						</div>
					</div>

					{ chosenType ? (
						<div
							className="form-group"
							role="group"
							aria-label={ __(
								'Configurações do tipo',
								'wc-checkoutsuite'
							) }
						>
							<p className="form-label">
								{ __(
									'Configurações do tipo',
									'wc-checkoutsuite'
								) }
							</p>
							<SettingsControls
								schema={ chosenType.settingsSchema }
								value={ settings }
								onChange={ (
									/** @type {Record<string, any>} */ next
								) => setSettings( next ) }
							/>
						</div>
					) : null }

					<div
						className="form-group"
						role="group"
						aria-label={ __(
							'Regras de exibição',
							'wc-checkoutsuite'
						) }
					>
						<p className="form-label">
							{ __( 'Regras de exibição', 'wc-checkoutsuite' ) }
						</p>
						<ConditionBuilder
							value={ conditions }
							vocabulary={ catalog?.conditions ?? {} }
							fields={ [] }
							onChange={ (
								/** @type {Record<string, any>} */ next
							) => setConditions( next ) }
						/>
						<p className="form-help">
							{ __(
								'Sem regra, o campo aparece sempre. A validação final é do servidor.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>

					<aside className="create-aside">
						<div className="field-glyph" aria-hidden="true">
							{ chosen.glyph }
						</div>
						<h3>{ chosen.label }</h3>
						<p>
							{ __(
								'O campo entra no rascunho. Nada chega ao checkout antes de publicar.',
								'wc-checkoutsuite'
							) }
						</p>
						<span className="badge purple">
							{ chosen.categoryLabel }
						</span>

						<div className="sample-box">
							<span className="sample-label">
								{ label ||
									__( 'Campo sem nome', 'wc-checkoutsuite' ) }
								{ required ? (
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
							/>
						</div>
					</aside>
				</div>

				<div className="dialog-footer">
					<p className="form-help">
						{ __(
							'Tipos são estruturas. Presets combinam tipo, máscara e regras.',
							'wc-checkoutsuite'
						) }
					</p>
					<button
						type="button"
						className="btn"
						onClick={ () => setChosen( null ) }
					>
						{ __( 'Voltar', 'wc-checkoutsuite' ) }
					</button>
					<button
						type="button"
						className="btn btn-primary"
						disabled={ '' === label.trim() || '' === key.trim() }
						onClick={ () =>
							onChooseType( {
								type: chosen.preset?.type ?? chosen.key,
								preset: chosen.preset?.key ?? null,
								label: label.trim(),
								idHint: key.trim(),
								section,
								required,
								description: description.trim(),
								conditions,
								settings:
									Object.keys( settings ).length > 0
										? settings
										: undefined,
								layout: { desktop: width },
								// A preset carries its own settings and defaults, and
								// the capabilities belong to the type it is built on:
								// without them the field would be created with
								// defaults the server refuses.
								defaults: chosen.preset?.defaults ?? undefined,
								supports:
									catalog?.types?.[
										chosen.preset?.type ?? chosen.key
									]?.supports ?? {},
							} )
						}
					>
						<Icon name="plus" />
						{ __( 'Adicionar campo', 'wc-checkoutsuite' ) }
					</button>
				</div>
			</>
		);
	}

	return (
		<>
			<div className="picker-main">
				<nav
					className="picker-categories"
					aria-label={ __(
						'Categorias de campos',
						'wc-checkoutsuite'
					) }
				>
					{ categories.map( ( entry ) => (
						<button
							key={ entry.key }
							type="button"
							className={ entry.key === category ? 'active' : '' }
							aria-pressed={ entry.key === category }
							onClick={ () => setCategory( entry.key ) }
						>
							<Icon
								name={
									CATEGORY_GLYPHS[ entry.key ] ?? 'fields'
								}
							/>
							{ entry.label }
							<small>{ entry.count }</small>
						</button>
					) ) }
				</nav>

				<div className="picker-content">
					<label className="search" htmlFor="wccs-picker-search">
						<Icon name="search" />
						<input
							id="wccs-picker-search"
							value={ query }
							placeholder={ __(
								'Buscar texto, CPF, arquivo…',
								'wc-checkoutsuite'
							) }
							aria-label={ __(
								'Buscar um tipo de campo',
								'wc-checkoutsuite'
							) }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) => setQuery( event.target.value ) }
						/>
					</label>

					<div className="picker-caption">
						{ sprintf(
							/* translators: %d: number of entries. */
							__(
								'%d tipos e presets disponíveis',
								'wc-checkoutsuite'
							),
							visible.length
						) }
					</div>

					<div className="picker-grid">
						{ 0 === visible.length ? (
							<div className="empty-state">
								<Icon name="search" />
								<h3>
									{ __(
										'Nenhum tipo encontrado.',
										'wc-checkoutsuite'
									) }
								</h3>
								<p>
									{ __(
										'Tente outro termo ou outra categoria.',
										'wc-checkoutsuite'
									) }
								</p>
							</div>
						) : (
							visible.map( ( entry ) => (
								<button
									key={ `${ entry.kind }-${ entry.key }` }
									type="button"
									data-testid={ `field-type-${ entry.key }` }
									className="picker-card"
									onClick={ () => choose( entry ) }
								>
									<span
										className="field-glyph"
										aria-hidden="true"
									>
										{ entry.glyph }
									</span>
									<Icon name="plus" />
									<strong>{ entry.label }</strong>
									<small>{ entry.categoryLabel }</small>
								</button>
							) )
						) }
					</div>
				</div>
			</div>

			<div className="dialog-footer">
				<p className="form-help">
					{ false === coreFields?.available
						? sprintf(
								/* translators: %s: reason the inventory could not be read. */
								__(
									'Os campos da WooCommerce não puderam ser listados: %s',
									'wc-checkoutsuite'
								),
								coreFields?.reason ?? ''
						  )
						: __(
								'Tipos são estruturas. Presets Brasil combinam tipo, máscara e regras. Senhas ficam no fluxo nativo.',
								'wc-checkoutsuite'
						  ) }
				</p>
				<button
					type="button"
					className="btn"
					onClick={ () => onClose?.() }
				>
					{ __( 'Cancelar', 'wc-checkoutsuite' ) }
				</button>
			</div>
		</>
	);
}
