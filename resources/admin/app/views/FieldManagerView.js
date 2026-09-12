/**
 * The field manager, as the design draws it.
 *
 * This component is the design's editor view and nothing else: the page heading and
 * its actions, the context bar, the panel that lists a section's fields, the
 * properties column beside it, and the two dialogs the design opens from the bar.
 * Everything it shows comes from the screen that owns the document — the draft, the
 * publication report, the undo history — so that the frame and the state stay apart.
 *
 * The markup, the classes and the order of the elements are the prototype's
 * (`roadmap/fields.html`). Two of its elements are stated in the design's own words
 * because they are design copy — the eyebrow and the heading — and the rest read from
 * the document: a tab is a section the merchant has, a row is a field they configured.
 *
 * Where the prototype shows a demonstration value, this shows the real one and says
 * so: `Rascunho de exemplo` is the state of the draft, `Revisão local 1` is the
 * revision the server holds, and the status line at the foot reports whether there is
 * anything to save.
 *
 * @package
 */

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Dialog from '../components/Dialog';
import FieldPicker from '../components/FieldPicker';
import ArchiveView from './ArchiveView';
import FieldProperties from './FieldProperties';
import RulesView from './RulesView';
import Notice from '../components/Notice';
import PublishPanel from '../components/PublishPanel';
import RevisionsList from '../components/RevisionsList';
import Button from '../components/Button';
import { TopbarActions } from '../design/TopbarActions';
import { Icon } from '../design/icons';
import { sectionCopy } from '../design/sectionMeta';
import { typeGlyph } from '../design/typeGlyph';

/**
 * The design's eyebrow and heading for the editor.
 *
 * @type {string}
 */
const EYEBROW = 'FIELD MANAGER';

/**
 * How wide a field is, in the design's words: a percentage of the row.
 *
 * @param {any} field Field definition.
 * @return {string} Percentage.
 */
function widthLabel( field ) {
	const columns = Number( field?.layout?.desktop ?? 12 );

	return `${ Math.round( ( columns / 12 ) * 100 ) }%`;
}

/**
 * The name a type is shown under.
 *
 * The registry is the authority: it is what the merchant chose and what the server
 * translated. A type that is no longer registered keeps its key, which is exactly the
 * state worth showing.
 *
 * @param {any} field   Field definition.
 * @param {any} catalog Field catalog from the server.
 * @return {string} Type label.
 */
function typeLabel( field, catalog ) {
	// Keyed by the type, not a list: the route publishes `types` as a map so a
	// caller that knows the key does not have to search for it.
	const entry = catalog?.types?.[ field.type ];

	return entry?.label ?? field.type;
}

/**
 * The field manager's editor view.
 *
 * The model is typed loosely on purpose: it is the whole of the screen's state and
 * the actions that change it, and writing that shape twice — once where it is built
 * and once here — would let the two drift while claiming to check each other.
 *
 * @param {Object} props       Component properties.
 * @param {any}    props.model Everything the view shows and the actions it calls.
 * @return {*} Rendered element tree.
 */
export default function FieldManagerView( { model } ) {
	const {
		document: doc,
		groups,
		section,
		onSectionChange,
		catalog,
		coreFields,
		sectionOptions,
		sections,
		loading,
		dirty,
		saving,
		saved,
		failure,
		refusal,
		problems,
		missingExtensions,
		unsupported,
		selected,
		onToggleSelected,
		onBulk,
		onClearSelection,
		editing,
		onEdit,
		onDuplicate,
		onToggleEnabled,
		onMove,
		onRemove,
		onProtect,
		onCreateField,
		onAdoptCore,
		edits,
		onSave,
		publishing,
		publishError,
		report,
		onPublish,
		revisions,
		restoring,
		restored,
		onRestore,
		reference,
		onExport,
		onOpenSection,
		onCreateSection,
		isProtected,
		sectionEditor,
		sectionDraft,
	} = model;

	/** The design's own view state: the search box and the origin filter. */
	const [ search, setSearch ] = useState( '' );
	const [ origin, setOrigin ] = useState( 'all' );
	const [ menuOpen, setMenuOpen ] = useState(
		/** @type {string|null} */ ( null )
	);
	const [ pickerOpen, setPickerOpen ] = useState( false );
	const [ publishOpen, setPublishOpen ] = useState( false );
	const [ historyOpen, setHistoryOpen ] = useState( false );

	const current = groups.find(
		( /** @type {any} */ group ) => group.section.id === section
	);
	const copy = sectionCopy(
		current?.section ?? { id: section, title: '', description: '' },
		Boolean( current?.declared )
	);

	/** The rows the design's filter bar leaves on screen. */
	const rows = useMemo( () => {
		const term = search.trim().toLocaleLowerCase( 'pt-BR' );

		return ( current?.fields ?? [] ).filter(
			( /** @type {any} */ field ) => {
				const matchesOrigin =
					'all' === origin ||
					( 'disabled' === origin && ! field.enabled ) ||
					field.origin === origin;

				const haystack = `${ field.label } ${ field.id } ${ typeLabel(
					field,
					catalog
				) }`.toLocaleLowerCase( 'pt-BR' );

				return matchesOrigin && haystack.includes( term );
			}
		);
	}, [ current, search, origin, catalog ] );

	const visibleSelected = rows.filter( ( /** @type {any} */ field ) =>
		selected.includes( field.id )
	).length;

	const filtered = '' !== search.trim() || 'all' !== origin;

	const editingField =
		( doc?.fields ?? [] ).find(
			( /** @type {any} */ field ) => field.id === editing
		) ?? null;

	// Three of the frame's destinations are views of this one document — the editor, the
	// archive and the rules — so they are rendered here, where the document lives, and
	// switching between them does not reload anything. The branch sits after every hook
	// on purpose: a component that returns before its hooks changes the order they run
	// in, which React refuses.
	if ( 'archive' === model.view ) {
		return (
			<ArchiveView
				fields={ doc?.fields ?? [] }
				catalog={ catalog }
				onRestore={ model.onRestoreField }
				onBack={ model.onBackToEditor }
			/>
		);
	}

	if ( 'rules' === model.view ) {
		return <RulesView onBack={ model.onBackToEditor } />;
	}

	return (
		<>
			<TopbarActions>
				<button
					type="button"
					className="btn"
					disabled={ ! dirty || saving }
					onClick={ onSave }
				>
					<Icon name="save" />
					<span>{ __( 'Salvar rascunho', 'wc-checkoutsuite' ) }</span>
				</button>
				<button
					type="button"
					className="btn btn-primary"
					onClick={ () => setPublishOpen( true ) }
				>
					<span>
						{ __( 'Revisar publicação', 'wc-checkoutsuite' ) }
					</span>
					<Icon name="arrow" />
				</button>
			</TopbarActions>

			<section
				className="view active"
				id="editorView"
				aria-labelledby="editorTitle"
			>
				<div className="page-heading">
					<div>
						<div className="eyebrow">
							<span className="tiny-line" />
							{ EYEBROW }
						</div>
						<h1 id="editorTitle">
							{ __(
								'Campos que fazem a diferença',
								'wc-checkoutsuite'
							) }
							<span className="heading-dot">.</span>
						</h1>
						<p>
							{ __(
								'Monte o formulário, ajuste os detalhes e veja seu checkout ganhar forma.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>
					<button
						type="button"
						className="btn btn-primary btn-add"
						onClick={ () => setPickerOpen( true ) }
					>
						<Icon name="plus" />
						{ __( 'Adicionar campo', 'wc-checkoutsuite' ) }
					</button>
				</div>

				<div className="contextbar">
					<div
						className="segmented"
						role="group"
						aria-label={ __(
							'Contexto do checkout',
							'wc-checkoutsuite'
						) }
					>
						<button
							type="button"
							className={
								'classic' === reference ? 'active' : ''
							}
							aria-pressed={ 'classic' === reference }
							onClick={ () =>
								model.onReferenceChange( 'classic' )
							}
						>
							{ __( 'Classic Checkout', 'wc-checkoutsuite' ) }
						</button>
						<button
							type="button"
							className={ 'blocks' === reference ? 'active' : '' }
							aria-pressed={ 'blocks' === reference }
							onClick={ () =>
								model.onReferenceChange( 'blocks' )
							}
						>
							{ __( 'Checkout Blocks', 'wc-checkoutsuite' ) }
						</button>
					</div>
					<div className="context-status">
						{ /* The prototype labels its own example. The real
						     screen states the two facts a merchant needs
						     before saving: whether the draft differs from
						     what the store runs, and which revision it is. */ }
						<span
							className={ `badge ${ dirty ? 'amber' : 'green' }` }
						>
							<i className="dot" />
							{ dirty
								? __(
										'Alterações não salvas',
										'wc-checkoutsuite'
								  )
								: __( 'Rascunho salvo', 'wc-checkoutsuite' ) }
						</span>
						<span className="muted small">
							{ sprintf(
								/* translators: %d: draft revision. */
								__( 'Revisão %d', 'wc-checkoutsuite' ),
								Number( doc?.revision ?? 0 )
							) }
						</span>
					</div>
				</div>

				{ failure ? (
					<Notice status={ failure.status } title={ failure.title }>
						<p>{ failure.message }</p>
						<p>{ failure.recovery }</p>
					</Notice>
				) : null }

				{ unsupported.length > 0 ? (
					<Notice
						status="error"
						title={ __(
							'This schema was written by a newer version',
							'wc-checkoutsuite'
						) }
					>
						{ sprintf(
							/* translators: 1: revision version, 2: supported version. */
							__(
								'The stored %1$s document uses schema version %2$d, which this build cannot read. Its fields are not lost; they are invisible until the plugin is updated.',
								'wc-checkoutsuite'
							),
							unsupported[ 0 ].slot,
							unsupported[ 0 ].storedVersion
						) }
					</Notice>
				) : null }

				{ missingExtensions.length > 0 ? (
					<Notice
						status="warning"
						title={ __(
							'Some field types are no longer available',
							'wc-checkoutsuite'
						) }
					>
						<p>
							{ __(
								'A plugin that provided these field types is not active. The fields and their stored values are intact; only the code that renders them is missing.',
								'wc-checkoutsuite'
							) }
						</p>
						<ul>
							{ missingExtensions.map(
								( /** @type {any} */ entry ) => (
									<li key={ entry.field }>
										{ `${ entry.label } — ${ entry.type }` }
									</li>
								)
							) }
						</ul>
					</Notice>
				) : null }

				{ refusal ? (
					<Notice status="warning">
						<p>{ refusal }</p>
					</Notice>
				) : null }

				{ saved ? (
					<Notice status="success">
						<p>{ saved }</p>
					</Notice>
				) : null }

				{ 'blocks' === reference ? (
					<div className="mode-banner notice amber">
						<Icon name="info" />
						<p>
							<strong>
								{ __(
									'Modo Blocks · matriz de capacidades.',
									'wc-checkoutsuite'
								) }
							</strong>{ ' ' }
							{ __(
								'Campos nativos não têm ordem ou largura livre. Tipos da Suite usam componentes próprios; localizações precisam de integração homologada. A prévia não é o renderizador do WooCommerce.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>
				) : null }

				<div className="editor-grid">
					<div className="editor-column">
						<div className="panel builder-panel">
							<div
								className="section-tabs"
								role="group"
								aria-label={ __(
									'Seções do checkout',
									'wc-checkoutsuite'
								) }
							>
								{ groups.map( ( /** @type {any} */ group ) => {
									const tab = sectionCopy(
										group.section,
										Boolean( group.declared )
									);
									const isActive =
										group.section.id === section;

									return (
										<button
											key={ group.section.id }
											type="button"
											className={
												isActive ? 'active' : ''
											}
											aria-pressed={ isActive }
											onClick={ () =>
												onSectionChange(
													group.section.id
												)
											}
										>
											{ tab.label }
											<small>
												{ group.fields.length }
											</small>
										</button>
									);
								} ) }

								{ /* The design draws the five checkout
								     locations as fixed tabs. This plugin lets
								     a merchant add a section of their own, so
								     the control sits where sections are, in
								     the design's own tab language. */ }
								<button
									type="button"
									className="text-btn"
									onClick={ onCreateSection }
								>
									<Icon name="plus" />
									{ __( 'Nova seção', 'wc-checkoutsuite' ) }
								</button>
							</div>

							<div className="builder-header">
								<div className="panel-heading">
									<div
										className="section-icon"
										aria-hidden="true"
									>
										<Icon name={ copy.icon } />
									</div>
									<div>
										<h2>{ copy.title }</h2>
										<p>{ copy.description }</p>
									</div>
								</div>
								<button
									type="button"
									className="badge"
									onClick={ onOpenSection }
								>
									{ sprintf(
										/* translators: 1: enabled field count, 2: total field count. */
										__(
											'%1$d de %2$d ativos',
											'wc-checkoutsuite'
										),
										( current?.fields ?? [] ).filter(
											( /** @type {any} */ field ) =>
												field.enabled
										).length,
										( current?.fields ?? [] ).length
									) }
								</button>
							</div>

							<div className="filterbar">
								<label
									className="search"
									htmlFor="wccs-field-search"
								>
									<Icon name="search" />
									<input
										id="wccs-field-search"
										value={ search }
										placeholder={ __(
											'Buscar por nome, chave ou tipo…',
											'wc-checkoutsuite'
										) }
										aria-label={ __(
											'Buscar campos da seção',
											'wc-checkoutsuite'
										) }
										onChange={ (
											/** @type {{target: {value: string}}} */ event
										) => setSearch( event.target.value ) }
									/>
								</label>
								<select
									className="filter-select"
									value={ origin }
									aria-label={ __(
										'Filtrar campos por origem',
										'wc-checkoutsuite'
									) }
									onChange={ (
										/** @type {{target: {value: string}}} */ event
									) => setOrigin( event.target.value ) }
								>
									<option value="all">
										{ __(
											'Todos os campos',
											'wc-checkoutsuite'
										) }
									</option>
									<option value="core">
										{ __( 'Nativos', 'wc-checkoutsuite' ) }
									</option>
									<option value="custom">
										{ __(
											'Personalizados',
											'wc-checkoutsuite'
										) }
									</option>
									<option value="disabled">
										{ __(
											'Desativados',
											'wc-checkoutsuite'
										) }
									</option>
								</select>
							</div>

							{ selected.length > 0 ? (
								<div className="bulk-bar">
									<span>
										{ 1 === selected.length
											? __(
													'1 selecionado',
													'wc-checkoutsuite'
											  )
											: sprintf(
													/* translators: %d: number of selected fields. */
													__(
														'%d selecionados',
														'wc-checkoutsuite'
													),
													selected.length
											  ) }
									</span>
									<div>
										<button
											type="button"
											className="text-btn"
											onClick={ () => onBulk( 'enable' ) }
										>
											{ __(
												'Habilitar',
												'wc-checkoutsuite'
											) }
										</button>
										<button
											type="button"
											className="text-btn"
											onClick={ () =>
												onBulk( 'disable' )
											}
										>
											{ __(
												'Desativar',
												'wc-checkoutsuite'
											) }
										</button>
										<button
											type="button"
											className="text-btn danger"
											onClick={ () =>
												onBulk( 'archive' )
											}
										>
											{ __(
												'Arquivar',
												'wc-checkoutsuite'
											) }
										</button>
										<button
											type="button"
											className="icon-btn small-icon"
											aria-label={ __(
												'Limpar seleção',
												'wc-checkoutsuite'
											) }
											onClick={ onClearSelection }
										>
											<Icon name="close" />
										</button>
									</div>
								</div>
							) : null }

							<div className="table-heading">
								<label
									className="select-all"
									htmlFor="wccs-select-all"
								>
									<input
										id="wccs-select-all"
										type="checkbox"
										aria-label={ __(
											'Selecionar campos visíveis',
											'wc-checkoutsuite'
										) }
										checked={
											rows.length > 0 &&
											visibleSelected === rows.length
										}
										disabled={ 0 === rows.length }
										onChange={ () => {
											if (
												visibleSelected === rows.length
											) {
												onClearSelection();

												return;
											}

											rows.forEach(
												(
													/** @type {any} */ field
												) => {
													if (
														! selected.includes(
															field.id
														)
													) {
														onToggleSelected(
															field.id
														);
													}
												}
											);
										} }
									/>
									<span>CAMPO / CHAVE</span>
								</label>
								<span className="table-heading-right">
									TIPO <span>LARGURA</span> ATIVO
								</span>
							</div>

							<div
								className="field-list"
								aria-label={ __(
									'Campos da seção',
									'wc-checkoutsuite'
								) }
							>
								{ loading ? (
									<p className="muted small">
										{ __(
											'Carregando…',
											'wc-checkoutsuite'
										) }
									</p>
								) : null }

								{ ! loading && 0 === rows.length ? (
									<div className="empty-state">
										<Icon name="fields" />
										<h3>
											{ ( current?.fields ?? [] ).length >
											0
												? __(
														'Nenhum resultado',
														'wc-checkoutsuite'
												  )
												: __(
														'Esta seção está pronta para começar.',
														'wc-checkoutsuite'
												  ) }
										</h3>
										<p>
											{ ( current?.fields ?? [] ).length >
											0
												? __(
														'Ajuste a busca ou o filtro para encontrar seus campos.',
														'wc-checkoutsuite'
												  )
												: __(
														'Adicione campos complementares. Recursos de conta e senha continuam no fluxo nativo do WooCommerce.',
														'wc-checkoutsuite'
												  ) }
										</p>
									</div>
								) : null }

								{ rows.map(
									(
										/** @type {any} */ field,
										/** @type {number} */ index
									) => {
										const isSelected = editing === field.id;
										const isCore = 'core' === field.origin;
										const hasRules =
											( field.conditions?.rules ?? [] )
												.length > 0;
										const moveUp = ! filtered && index > 0;
										const moveDown =
											! filtered &&
											index < rows.length - 1;

										return (
											<div
												key={ field.id }
												className={
													'field-row' +
													( isSelected
														? ' selected'
														: '' ) +
													( field.enabled
														? ''
														: ' disabled' )
												}
											>
												<label
													className="row-check"
													htmlFor={ `wccs-select-${ field.id }` }
												>
													<input
														id={ `wccs-select-${ field.id }` }
														type="checkbox"
														checked={ selected.includes(
															field.id
														) }
														aria-label={ sprintf(
															/* translators: %s: field label. */
															__(
																'Selecionar %s',
																'wc-checkoutsuite'
															),
															field.label
														) }
														onChange={ () =>
															onToggleSelected(
																field.id
															)
														}
													/>
												</label>

												<button
													type="button"
													className="drag-handle"
													title={ __(
														'Arraste ou use as setas para mover',
														'wc-checkoutsuite'
													) }
													aria-label={ sprintf(
														/* translators: %s: field label. */
														__(
															'Ordenar %s',
															'wc-checkoutsuite'
														),
														field.label
													) }
													disabled={ filtered }
													onClick={ () => {} }
												>
													<Icon name="grip" />
												</button>

												<div
													className="field-glyph"
													aria-hidden="true"
												>
													{ typeGlyph( field.type ) }
												</div>

												<button
													type="button"
													className="field-info"
													aria-pressed={ isSelected }
													onClick={ () =>
														onEdit( field.id )
													}
												>
													<span className="field-name">
														<strong>
															{ field.label ||
																__(
																	'Campo sem nome',
																	'wc-checkoutsuite'
																) }
														</strong>
														{ field.required ? (
															<span
																className="required-star"
																aria-label={ __(
																	'obrigatório',
																	'wc-checkoutsuite'
																) }
															>
																*
															</span>
														) : null }
														{ hasRules ? (
															<span
																title={ __(
																	'Possui regra condicional',
																	'wc-checkoutsuite'
																) }
															>
																<Icon name="branch" />
															</span>
														) : null }
														{ isProtected(
															field
														) ? (
															<span
																title={ __(
																	'Campo estrutural protegido',
																	'wc-checkoutsuite'
																) }
															>
																<Icon name="lock" />
															</span>
														) : null }
													</span>
													<code>{ field.id }</code>
												</button>

												<div className="row-meta">
													<div className="type-meta">
														<span
															className={
																'badge' +
																( isCore
																	? ''
																	: ' purple' )
															}
														>
															{ typeLabel(
																field,
																catalog
															) }
														</span>
														<small>
															{ isCore
																? __(
																		'Nativo',
																		'wc-checkoutsuite'
																  )
																: __(
																		'Personalizado',
																		'wc-checkoutsuite'
																  ) }
														</small>
													</div>

													<span className="row-width">
														{ widthLabel( field ) }
													</span>

													<label
														className="toggle"
														htmlFor={ `wccs-toggle-${ field.id }` }
													>
														<input
															id={ `wccs-toggle-${ field.id }` }
															type="checkbox"
															checked={
																field.enabled
															}
															aria-label={
																field.enabled
																	? sprintf(
																			/* translators: %s: field label. */
																			__(
																				'Desativar %s',
																				'wc-checkoutsuite'
																			),
																			field.label
																	  )
																	: sprintf(
																			/* translators: %s: field label. */
																			__(
																				'Habilitar %s',
																				'wc-checkoutsuite'
																			),
																			field.label
																	  )
															}
															onChange={ () =>
																onToggleEnabled(
																	field.id
																)
															}
														/>
														<span />
													</label>

													<div className="row-actions">
														<button
															type="button"
															className="icon-btn row-menu-trigger"
															aria-expanded={
																menuOpen ===
																field.id
															}
															aria-label={ sprintf(
																/* translators: %s: field label. */
																__(
																	'Ações de %s',
																	'wc-checkoutsuite'
																),
																field.label
															) }
															onClick={ () =>
																setMenuOpen(
																	menuOpen ===
																		field.id
																		? null
																		: field.id
																)
															}
														>
															<Icon name="more" />
														</button>

														<div className="move-pair">
															<button
																type="button"
																aria-label={ sprintf(
																	/* translators: %s: field label. */
																	__(
																		'Mover %s para cima',
																		'wc-checkoutsuite'
																	),
																	field.label
																) }
																disabled={
																	! moveUp
																}
																onClick={ () =>
																	onMove(
																		field.id,
																		'up'
																	)
																}
															>
																<Icon name="up" />
															</button>
															<button
																type="button"
																aria-label={ sprintf(
																	/* translators: %s: field label. */
																	__(
																		'Mover %s para baixo',
																		'wc-checkoutsuite'
																	),
																	field.label
																) }
																disabled={
																	! moveDown
																}
																onClick={ () =>
																	onMove(
																		field.id,
																		'down'
																	)
																}
															>
																<Icon name="down" />
															</button>
														</div>

														{ menuOpen ===
														field.id ? (
															<div className="row-menu">
																<button
																	type="button"
																	onClick={ () => {
																		setMenuOpen(
																			null
																		);
																		onEdit(
																			field.id
																		);
																	} }
																>
																	<Icon name="edit" />
																	{ __(
																		'Editar campo',
																		'wc-checkoutsuite'
																	) }
																</button>
																<button
																	type="button"
																	onClick={ () => {
																		setMenuOpen(
																			null
																		);
																		onDuplicate(
																			field.id
																		);
																	} }
																>
																	<Icon name="copy" />
																	{ __(
																		'Duplicar como personalizado',
																		'wc-checkoutsuite'
																	) }
																</button>
																<button
																	type="button"
																	onClick={ () => {
																		setMenuOpen(
																			null
																		);
																		onToggleEnabled(
																			field.id
																		);
																	} }
																>
																	<Icon name="power" />
																	{ field.enabled
																		? __(
																				'Desativar campo',
																				'wc-checkoutsuite'
																		  )
																		: __(
																				'Habilitar campo',
																				'wc-checkoutsuite'
																		  ) }
																</button>
																{ isCore ? (
																	<button
																		type="button"
																		onClick={ () => {
																			setMenuOpen(
																				null
																			);
																			onProtect(
																				field.id
																			);
																		} }
																	>
																		<Icon name="shield" />
																		{ __(
																			'Por que não posso excluir?',
																			'wc-checkoutsuite'
																		) }
																	</button>
																) : (
																	<>
																		<button
																			type="button"
																			className="danger"
																			onClick={ () => {
																				setMenuOpen(
																					null
																				);
																				onToggleEnabled(
																					field.id
																				);
																			} }
																		>
																			<Icon name="archive" />
																			{ __(
																				'Arquivar campo',
																				'wc-checkoutsuite'
																			) }
																		</button>
																		<button
																			type="button"
																			className="danger"
																			onClick={ () => {
																				setMenuOpen(
																					null
																				);
																				onRemove(
																					field.id
																				);
																			} }
																		>
																			<Icon name="close" />
																			{ __(
																				'Excluir campo',
																				'wc-checkoutsuite'
																			) }
																		</button>
																	</>
																) }
															</div>
														) : null }
													</div>
												</div>
											</div>
										);
									}
								) }
							</div>

							<button
								type="button"
								className="inline-add"
								onClick={ () => setPickerOpen( true ) }
							>
								<Icon name="plus" />
								{ __(
									'Adicionar campo nesta seção',
									'wc-checkoutsuite'
								) }
							</button>

							<div className="builder-footer">
								<span>
									<Icon name="grip" />
									{ __(
										'Arraste pela alça ou use as setas para ordenar.',
										'wc-checkoutsuite'
									) }
								</span>
								<div>
									<button
										type="button"
										className="icon-btn small-icon"
										title={ __(
											'Desfazer',
											'wc-checkoutsuite'
										) }
										aria-label={ __(
											'Desfazer alteração',
											'wc-checkoutsuite'
										) }
										disabled={ ! edits.canUndo }
										onClick={ edits.undo }
									>
										<Icon name="undo" />
									</button>
									<button
										type="button"
										className="icon-btn small-icon"
										title={ __(
											'Refazer',
											'wc-checkoutsuite'
										) }
										aria-label={ __(
											'Refazer alteração',
											'wc-checkoutsuite'
										) }
										disabled={ ! edits.canRedo }
										onClick={ edits.redo }
									>
										<Icon name="redo" />
									</button>
								</div>
							</div>
						</div>

						<div className="editor-bottom">
							<div className="tip-card">
								<div className="tip-icon">
									<Icon name="branch" />
								</div>
								<div>
									<strong>
										{ __(
											'Um formulário que entende contexto.',
											'wc-checkoutsuite'
										) }
									</strong>
									<p>
										{ __(
											'CPF para pessoa física, CNPJ para jurídica. Configure em',
											'wc-checkoutsuite'
										) }{ ' ' }
										<b>
											{ __(
												'Regras',
												'wc-checkoutsuite'
											) }
										</b>{ ' ' }
										{ __(
											'e experimente na prévia.',
											'wc-checkoutsuite'
										) }
									</p>
								</div>
								<button
									type="button"
									className="icon-btn"
									aria-label={ __(
										'Ver campos no checkout',
										'wc-checkoutsuite'
									) }
									onClick={ model.onPreview }
								>
									<Icon name="arrow" />
								</button>
							</div>
							<div className="editor-footnote">
								<span>
									<Icon name="shield" />
									{ __(
										'Nativos protegidos. Personalizados arquivados, nunca apagados.',
										'wc-checkoutsuite'
									) }
								</span>
								<button
									type="button"
									className="text-btn"
									onClick={ model.onOpenRules }
								>
									{ __(
										'Entenda as regras',
										'wc-checkoutsuite'
									) }
									<Icon name="arrow" />
								</button>
							</div>
						</div>
					</div>

					<aside
						className="panel inspector"
						aria-label={ __(
							'Propriedades do campo',
							'wc-checkoutsuite'
						) }
					>
						{ editingField ? (
							<FieldProperties
								field={ editingField }
								catalog={ catalog }
								sections={ sections }
								fields={ ( doc?.fields ?? [] ).map(
									( /** @type {any} */ entry ) => ( {
										id: entry.id,
										label: entry.label,
									} )
								) }
								onChange={ model.onChangeField }
								onDuplicate={ () =>
									onDuplicate( editingField.id )
								}
								onArchive={ () =>
									onToggleEnabled( editingField.id )
								}
								onProtect={ () => onProtect( editingField.id ) }
							/>
						) : (
							<div className="inspector-head">
								<div className="eyebrow">
									{ __(
										'PROPRIEDADES DO CAMPO',
										'wc-checkoutsuite'
									) }
								</div>
								<p className="muted small">
									{ __(
										'Escolha um campo na lista para editar as propriedades dele.',
										'wc-checkoutsuite'
									) }
								</p>
							</div>
						) }
					</aside>
				</div>

				<div className="bottom-status">
					<span>
						<Icon name="circle" />
						<span>
							{ dirty
								? __(
										'Alterações não salvas. Salve um rascunho para continuar depois.',
										'wc-checkoutsuite'
								  )
								: __(
										'Tudo salvo no rascunho. Nada chega ao checkout antes de publicar.',
										'wc-checkoutsuite'
								  ) }
						</span>
					</span>
					<div>
						<button
							type="button"
							className="text-btn"
							onClick={ onExport }
						>
							<Icon name="download" />
							{ __(
								'Exportar configuração',
								'wc-checkoutsuite'
							) }
						</button>
						<button
							type="button"
							className="text-btn"
							onClick={ () => setHistoryOpen( true ) }
						>
							<Icon name="history" />
							{ __( 'Revisões', 'wc-checkoutsuite' ) }
						</button>
					</div>
				</div>
			</section>

			{ /* The dialogs the design opens from the bar and the panel. */ }
			<Dialog
				open={ pickerOpen }
				size="picker"
				eyebrow={ __( 'ADICIONAR CAMPO', 'wc-checkoutsuite' ) }
				title={ __(
					'O que seu checkout precisa?',
					'wc-checkoutsuite'
				) }
				subtitle={ __(
					'Escolha um tipo ou comece com um preset pronto.',
					'wc-checkoutsuite'
				) }
				onClose={ () => setPickerOpen( false ) }
			>
				<FieldPicker
					catalog={ catalog }
					coreFields={ coreFields }
					section={ section }
					sections={ sectionOptions }
					onSectionChange={ onSectionChange }
					onChooseType={ ( /** @type {any} */ choice ) => {
						setPickerOpen( false );
						onCreateField( choice );
					} }
					onAdoptCore={ ( /** @type {any} */ core ) => {
						setPickerOpen( false );
						onAdoptCore( core );
					} }
					onClose={ () => setPickerOpen( false ) }
				/>
			</Dialog>

			<Dialog
				open={ publishOpen }
				size="publish"
				eyebrow={ __( 'PUBLICAR', 'wc-checkoutsuite' ) }
				title={ __( 'Revisar publicação', 'wc-checkoutsuite' ) }
				subtitle={ __(
					'O rascunho vira a versão que a loja corre. Publicar cria uma revisão nova e mantém a anterior.',
					'wc-checkoutsuite'
				) }
				onClose={ () => setPublishOpen( false ) }
				footer={
					<>
						<Button
							variant="secondary"
							onClick={ () => setPublishOpen( false ) }
						>
							{ __( 'Fechar', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							variant="primary"
							busy={ publishing }
							disabled={ publishing }
							onClick={ onPublish }
						>
							{ __( 'Publicar alterações', 'wc-checkoutsuite' ) }
						</Button>
					</>
				}
			>
				<PublishPanel
					report={ report }
					dirty={ dirty }
					publishing={ publishing }
					error={ publishError }
					onPublish={ onPublish }
				/>
			</Dialog>

			<Dialog
				open={ historyOpen }
				size="publish"
				title={ __( 'Histórico de publicação', 'wc-checkoutsuite' ) }
				subtitle={ __(
					'Voltar a uma versão anterior publica-a de novo como uma revisão nova. Nada é apagado.',
					'wc-checkoutsuite'
				) }
				onClose={ () => setHistoryOpen( false ) }
				footer={
					<Button
						variant="secondary"
						onClick={ () => setHistoryOpen( false ) }
					>
						{ __( 'Fechar', 'wc-checkoutsuite' ) }
					</Button>
				}
			>
				<RevisionsList
					revisions={ revisions }
					currentRevision={ report?.diff?.published?.revision ?? 0 }
					restoring={ restoring }
					restored={ restored }
					error=""
					onRestore={ onRestore }
				/>
			</Dialog>

			{ sectionEditor }

			{ sectionDraft }

			{ problems.length > 0 ? (
				<Notice status="error">
					<ul>
						{ problems.map(
							(
								/** @type {any} */ problem,
								/** @type {number} */ index
							) => (
								<li key={ index }>{ problem.message }</li>
							)
						) }
					</ul>
				</Notice>
			) : null }
		</>
	);
}
