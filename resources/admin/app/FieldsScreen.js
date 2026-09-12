/**
 * Fields screen.
 *
 * The first screen that actually manages the schema: it lists the fields the
 * document holds, offers the create, edit, duplicate and archive actions, and
 * keeps the WooCommerce-owned fields visibly distinct from the merchant's own.
 *
 * Two boundaries are deliberate at this stage:
 *
 * - **Only the draft is touched.** Nothing here reaches the storefront; saving
 *   writes the draft and publishing is a separate, later decision. That is what
 *   keeps an accidental drag from changing a live checkout.
 * - **The per-type inspector is WCCS-017.** Editing here covers what every field
 *   has — its label, whether it is required, whether it is active — and not the
 *   settings a particular type declares.
 *
 * Protection is shown, explained and enforced twice. The buttons that would
 * destroy a WooCommerce field are not offered, with the reason next to them; and
 * the server refuses the write even if a caller ignores the interface, because a
 * hidden button is a courtesy and not a rule.
 *
 * @see ROADMAP.md sections 4, 7 and 19
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './components/Button';
import Dialog from './components/Dialog';
import Notice from './components/Notice';
import EmptyState from './components/EmptyState';
import ErrorSummary from './components/ErrorSummary';
import FieldPicker from './components/FieldPicker';
import FieldInspector from './components/FieldInspector';
import SortableList from './components/SortableList';
import PublishPanel from './components/PublishPanel';
import RevisionsList from './components/RevisionsList';
import IconButton from './components/IconButton';
import { TextField, SelectField, TextareaField } from './components/controls';
import BulkActions from './components/BulkActions';
import { Badge } from './components/Badge';
import { TopbarActions } from './design/TopbarActions';
import useUnsavedChanges from './api/useUnsavedChanges';
import useDocumentHistory from './schema/useDocumentHistory';
import {
	classifyFailure,
	missingExtension,
	unsupportedVersion,
} from './schema/failureState';
import {
	activeCount,
	adoptCoreField,
	createField,
	createSection,
	duplicateField,
	isProtected,
	moveField,
	moveSection,
	protectionReason,
	removeField,
	removeSection,
	sectionGroups,
	setFieldEnabled,
	updateField,
	updateSection,
} from './schema/fieldOperations';

/**
 * Default section when the store offers none.
 *
 * @type {string}
 */
const FALLBACK_SECTION = 'order';

/**
 * Fields screen.
 *
 * @param {Object} props        Component properties.
 * @param {any}    props.client REST client.
 * @return {*} Rendered element tree.
 */
export default function FieldsScreen( { client } ) {
	/**
	 * The document and its local edit history.
	 *
	 * Undo here covers edits that have not been saved, and nothing else. The
	 * publication history is a different mechanism on the server: it answers "what
	 * did the store run, and when", and undoing it means publishing again as a new
	 * revision. ROADMAP.md section 428 keeps the two apart on purpose.
	 */
	const edits = useDocumentHistory( null );
	const document = edits.document;

	// The individual operations, not the object.
	//
	// The object this hook returns is memoised, but its identity still changes
	// whenever the document does — and `load` changes the document. Depending on
	// the object would therefore make `load` change after it ran, re-run the
	// mount effect, and loop. Depending on the stable operation does not.
	const resetDocument = edits.reset;
	const commitDocument = edits.commit;
	const [ catalog, setCatalog ] = useState(
		/** @type {import('./schema/types').FieldCatalog|null} */ ( null )
	);
	const [ coreFields, setCoreFields ] = useState(
		/** @type {import('./schema/types').CoreFieldInventory|null} */ ( null )
	);
	const [ loading, setLoading ] = useState( true );
	/**
	 * The classified state of the last failure.
	 *
	 * A string would collapse four states that ask for four different things —
	 * a conflict is not an error and an expired session is not a bug in the
	 * schema. See schema/failureState.js.
	 *
	 * @type {[any, Function]}
	 */
	const [ failure, setFailure ] = useState( /** @type {any} */ ( null ) );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( '' );
	const [ refusal, setRefusal ] = useState( '' );
	const [ problems, setProblems ] = useState(
		/** @type {Array<{fieldId?: string, message: string}>} */ ( [] )
	);
	const [ section, setSection ] = useState( FALLBACK_SECTION );
	/**
	 * Identifier of the field open in the inspector.
	 *
	 * The identifier is stored rather than the definition, so the inspector always
	 * reads the current draft. Holding the definition would let the dialog show a
	 * stale copy after the first change.
	 *
	 * @type {[string|null, Function]}
	 */
	const [ editing, setEditing ] = useState( null );

	// Annotating the argument rather than the destructured tuple: a JSDoc type on
	// the tuple does not reach useState and leaves the state as `never`.
	const [ editingSection, setEditingSection ] = useState(
		/** @type {import('./schema/types').SectionDefinition|null} */ ( null )
	);

	/** @type {[string, Function]} */
	const [ newSectionTitle, setNewSectionTitle ] = useState( '' );

	/** @type {[string, Function]} */
	const [ newSectionLocation, setNewSectionLocation ] = useState( 'billing' );
	/**
	 * The document as the server last confirmed it.
	 *
	 * Dirty is derived from this rather than set by hand, so undoing back to the
	 * saved state makes the screen clean again instead of claiming there is
	 * something to save.
	 *
	 * @type {[any, Function]}
	 */
	const [ savedDocument, setSavedDocument ] = useState( null );

	const dirty = null !== document && document !== savedDocument;

	/** @type {[any, Function]} */
	const [ report, setReport ] = useState( null );

	/** @type {[any[], Function]} */
	const [ revisions, setRevisions ] = useState( [] );

	/**
	 * Identifiers selected for a bulk action.
	 *
	 * @type {[string[], Function]}
	 */
	const [ selected, setSelected ] = useState( [] );

	/**
	 * Toggles one field's selection.
	 *
	 * @param {string} id Field identifier.
	 * @return {void}
	 */
	const toggleSelected = ( /** @type {string} */ id ) =>
		setSelected( ( /** @type {string[]} */ current ) =>
			current.includes( id )
				? current.filter(
						( /** @type {string} */ entry ) => entry !== id
				  )
				: [ ...current, id ]
		);

	const [ publishing, setPublishing ] = useState( false );
	const [ restoring, setRestoring ] = useState( false );
	const [ publishError, setPublishError ] = useState( '' );
	const [ restored, setRestored ] = useState( '' );

	/** @type {{ current: boolean }} */
	const mounted = useRef( true );

	useUnsavedChanges(
		dirty,
		__( 'You have unsaved checkout field changes.', 'wc-checkoutsuite' )
	);

	/**
	 * Loads the draft and the two catalogues.
	 *
	 * @return {Promise<void>} Resolves when loading settles.
	 */
	const load = useCallback( async () => {
		setLoading( true );
		setFailure( null );

		try {
			const [ draft, types, core, publication, history ] =
				await Promise.all( [
					client.getDraft(),
					client.fieldTypes(),
					client.coreFields(),
					client.diff(),
					client.revisions(),
				] );

			if ( ! mounted.current ) {
				return;
			}

			resetDocument( draft );
			setSavedDocument( draft );
			setCatalog( types );
			setCoreFields( core );
			setReport( publication );
			setRevisions( history?.revisions ?? [] );
		} catch ( caught ) {
			if ( mounted.current ) {
				setFailure( classifyFailure( caught ) );
			}
		} finally {
			if ( mounted.current ) {
				setLoading( false );
			}
		}
	}, [ client, resetDocument ] );

	useEffect( () => {
		mounted.current = true;
		load();

		return () => {
			mounted.current = false;
		};
	}, [ load ] );

	/**
	 * Applies an operation result, surfacing a refusal instead of swallowing it.
	 *
	 * @param {import('./schema/types').OperationResult} result Operation result.
	 * @return {void}
	 */
	const apply = useCallback(
		( /** @type {import('./schema/types').OperationResult} */ result ) => {
			if ( ! result.ok ) {
				setRefusal( result.reason );

				return;
			}

			setRefusal( '' );
			setProblems( [] );
			setSaved( '' );
			commitDocument( result.document );
		},
		[ commitDocument ]
	);

	/**
	 * Sections in display order, including the ones fields imply.
	 *
	 * @type {import('./schema/types').SectionGroup[]}
	 */
	const groups = useMemo(
		() => ( document ? sectionGroups( document ) : [] ),
		[ document ]
	);

	/**
	 * Fields whose type is not registered any more.
	 *
	 * The "extensão ausente" state: a plugin was deactivated and the schema still
	 * refers to a type it provided. The field and its stored value are intact.
	 */
	const missingExtensions = useMemo(
		() =>
			/** @type {Array<{field: string, label: string, type: string}>} */ (
				( document?.fields ?? [] )
					.map( ( /** @type {any} */ field ) =>
						missingExtension( field, catalog )
					)
					.filter( Boolean )
			),
		[ document, catalog ]
	);

	/**
	 * Slots the server could not read because they are newer than this build.
	 *
	 * Without this an unreadable document is indistinguishable from an empty one,
	 * and the screen would say "no fields yet" while the fields exist invisibly.
	 */
	const unsupported = useMemo(
		() =>
			/** @type {Array<{slot: string, storedVersion: number}>} */ (
				[ 'draft', 'published' ]
					.map( ( slot ) =>
						unsupportedVersion( report?.storage, slot )
					)
					.filter( Boolean )
			),
		[ report ]
	);

	/**
	 * Identifiers of the declared sections, in order.
	 *
	 * Used to disable "move earlier" on the first and "move later" on the last,
	 * because the positions of implied locations are not the merchant's to set.
	 *
	 * @type {string[]}
	 */
	const declaredSectionIds = useMemo(
		() =>
			groups
				.filter( ( group ) => group.declared )
				.map( ( group ) => group.section.id ),
		[ groups ]
	);

	/**
	 * Sections offered when adding a field.
	 *
	 * Declared sections come first, in their own order, followed by the domain
	 * locations the store has not declared a section for. All five locations are
	 * always usable: ROADMAP.md section 4 fixes them as domain concepts, and an
	 * adopted WooCommerce field already belongs to one.
	 *
	 * @type {Array<{key: string, label: string}>}
	 */
	const sectionOptions = useMemo( () => {
		/** @type {Array<{key: string, label: string}>} */
		const options = [];
		/** @type {Set<string>} */
		const seen = new Set();

		for ( const group of groups ) {
			if ( seen.has( group.section.id ) ) {
				continue;
			}

			seen.add( group.section.id );
			options.push( {
				key: group.section.id,
				label: group.section.title,
			} );
		}

		for ( const location of catalog?.sectionLocations ?? [] ) {
			if ( seen.has( location.value ) ) {
				continue;
			}

			seen.add( location.value );
			options.push( { key: location.value, label: location.label } );
		}

		return options;
	}, [ groups, catalog ] );

	/**
	 * Keeps the target section valid as the list of sections arrives.
	 *
	 * @return {void}
	 */
	useEffect( () => {
		if ( sectionOptions.some( ( entry ) => entry.key === section ) ) {
			return;
		}

		if ( sectionOptions.length > 0 ) {
			setSection( sectionOptions[ 0 ].key );
		}
	}, [ sectionOptions, section ] );

	/**
	 * Reloads the publication report and the history.
	 *
	 * Called after anything that changes either document, so the panel never shows
	 * a diff computed against a revision that no longer exists.
	 *
	 * @return {Promise<void>} Resolves when the refresh settles.
	 */
	const refreshPublication = useCallback( async () => {
		try {
			const [ publication, history ] = await Promise.all( [
				client.diff(),
				client.revisions(),
			] );

			if ( ! mounted.current ) {
				return;
			}

			setReport( publication );
			setRevisions( history?.revisions ?? [] );
		} catch ( caught ) {
			if ( mounted.current ) {
				setPublishError( classifyFailure( caught ).message );
			}
		}
	}, [ client ] );

	/**
	 * Saves the draft, keeping the revision the server owns.
	 *
	 * @return {Promise<void>} Resolves when saving settles.
	 */
	const save = useCallback( async () => {
		if ( ! document ) {
			return;
		}

		setSaving( true );
		setProblems( [] );
		setSaved( '' );

		try {
			const result = await client.saveDraft(
				document,
				document.revision
			);

			if ( ! mounted.current ) {
				return;
			}

			// The server answered with its own revision, so it is the authority
			// now: the edit is no longer local, and undoing into a state the
			// server never had would be a lie.
			resetDocument( result );
			setSavedDocument( result );
			setSaved( __( 'Draft saved.', 'wc-checkoutsuite' ) );

			// The report compares the draft with the published document, so a save
			// makes the one on screen stale.
			await refreshPublication();
		} catch ( caught ) {
			if ( ! mounted.current ) {
				return;
			}

			const state = classifyFailure( caught );

			setFailure( state );

			if ( 'validation' === state.kind ) {
				setProblems( state.fields ?? [] );
			}
		} finally {
			if ( mounted.current ) {
				setSaving( false );
			}
		}
	}, [ client, document, refreshPublication, resetDocument ] );

	/**
	 * Publishes the draft.
	 *
	 * @return {Promise<void>} Resolves when publication settles.
	 */
	const publish = useCallback( async () => {
		if ( ! document ) {
			return;
		}

		setPublishing( true );
		setPublishError( '' );
		setRestored( '' );

		try {
			await client.publish( document.revision );

			if ( ! mounted.current ) {
				return;
			}

			setSaved( __( 'Published.', 'wc-checkoutsuite' ) );
			await refreshPublication();
		} catch ( caught ) {
			if ( mounted.current ) {
				setPublishError( classifyFailure( caught ).message );
			}
		} finally {
			if ( mounted.current ) {
				setPublishing( false );
			}
		}
	}, [ client, document, refreshPublication ] );

	/**
	 * Publishes an earlier revision again.
	 *
	 * @param {number} revision Revision to restore.
	 * @return {Promise<void>} Resolves when the restore settles.
	 */
	const restore = useCallback(
		async ( /** @type {number} */ revision ) => {
			setRestoring( true );
			setPublishError( '' );
			setRestored( '' );

			try {
				await client.restore( revision );

				if ( ! mounted.current ) {
					return;
				}

				setRestored(
					sprintf(
						/* translators: %d: revision number. */
						__(
							'Revision %d was published again as a new revision.',
							'wc-checkoutsuite'
						),
						revision
					)
				);

				await refreshPublication();
			} catch ( caught ) {
				if ( mounted.current ) {
					setPublishError( classifyFailure( caught ).message );
				}
			} finally {
				if ( mounted.current ) {
					setRestoring( false );
				}
			}
		},
		[ client, refreshPublication ]
	);

	if ( loading ) {
		return (
			<Notice status="info">
				{ __( 'Loading the schema…', 'wc-checkoutsuite' ) }
			</Notice>
		);
	}

	if ( ! document ) {
		const state = failure ?? classifyFailure( null );

		return (
			<Notice status={ state.status } title={ state.title }>
				<p>{ state.message }</p>
				<p>{ state.recovery }</p>
			</Notice>
		);
	}

	const total = document.fields.length;

	// Read from the draft on every render so the inspector shows what is stored,
	// not a copy taken when the dialog opened.
	const editingField =
		document.fields.find(
			( /** @type {any} */ field ) => field.id === editing
		) ?? null;

	return (
		<div className="wccs-fields">
			<header className="wccs-fields__header">
				<div>
					<h2 className="wccs-fields__title">
						{ __( 'Fields', 'wc-checkoutsuite' ) }
					</h2>
					<p className="wccs-fields__summary">
						{ sprintf(
							/* translators: 1: active field count, 2: total field count. */
							__(
								'%1$d active of %2$d. Changes are saved to the draft and never reach the checkout until you publish.',
								'wc-checkoutsuite'
							),
							activeCount( document ),
							total
						) }
					</p>
				</div>
			</header>

			{ /* The design puts these in the bar at the top of the window; the state
			     they act on lives here, so they are rendered into the bar rather
			     than moved to the frame. */ }
			<TopbarActions>
				<div className="wccs-fields__actions">
					{ dirty ? (
						<Badge tone="warning">
							{ __( 'Unsaved changes', 'wc-checkoutsuite' ) }
						</Badge>
					) : null }

					{ /* Local undo covers edits that are not saved yet. The
					     publication history is a different mechanism on the
					     server, and nothing here reaches it. */ }
					<Button
						size="small"
						disabled={ ! edits.canUndo }
						onClick={ edits.undo }
					>
						{ __( 'Undo edit', 'wc-checkoutsuite' ) }
					</Button>
					<Button
						size="small"
						disabled={ ! edits.canRedo }
						onClick={ edits.redo }
					>
						{ __( 'Redo edit', 'wc-checkoutsuite' ) }
					</Button>

					<Button
						variant="primary"
						busy={ saving }
						disabled={ ! dirty || saving }
						onClick={ save }
					>
						{ __( 'Save draft', 'wc-checkoutsuite' ) }
					</Button>
				</div>
			</TopbarActions>

			{ saved ? <Notice status="success">{ saved }</Notice> : null }

			<PublishPanel
				report={ report }
				dirty={ dirty }
				publishing={ publishing }
				error={ publishError }
				onPublish={ publish }
			/>

			<RevisionsList
				revisions={ revisions }
				currentRevision={ report?.diff?.published?.revision ?? 0 }
				restoring={ restoring }
				restored={ restored }
				error=""
				onRestore={ restore }
			/>

			{ failure && document ? (
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
									<code>{ entry.field }</code> —{ ' ' }
									{ entry.type }
								</li>
							)
						) }
					</ul>
				</Notice>
			) : null }

			{ problems.length > 0 ? (
				<ErrorSummary errors={ problems } />
			) : null }

			{ refusal ? (
				<Notice status="warning" onDismiss={ () => setRefusal( '' ) }>
					{ refusal }
				</Notice>
			) : null }

			{ /* Shown only while something is selected; it renders nothing
			     otherwise. Kept outside the empty/filled branch because a
			     selection outlives neither of them. */ }
			<BulkActions
				document={ document }
				selected={ selected }
				sections={ sectionOptions }
				audiences={ catalog?.vocabulary?.visibilityKeys ?? [] }
				onApply={ ( result ) => {
					apply( result );
					setSelected( [] );
				} }
				onClear={ () => setSelected( [] ) }
			/>

			{ total === 0 ? (
				<EmptyState
					title={ __( 'No fields yet', 'wc-checkoutsuite' ) }
					description={ __(
						'Pick a ready-made field, a field type, or one of the fields WooCommerce already has, to start building the checkout.',
						'wc-checkoutsuite'
					) }
				/>
			) : (
				<div className="wccs-fields__groups">
					{ groups.map( ( group ) => {
						const declaredIndex = declaredSectionIds.indexOf(
							group.section.id
						);

						return (
							<section
								key={ group.section.id }
								className="wccs-fields__group"
								aria-labelledby={ `wccs-fields-section-${ group.section.id }` }
							>
								<header className="wccs-fields__group-header">
									<h3
										className="wccs-fields__group-title"
										id={ `wccs-fields-section-${ group.section.id }` }
									>
										{ group.section.title }
									</h3>

									{ group.declared ? (
										<>
											<Badge tone="neutral">
												{ (
													catalog?.sectionLocations ??
													[]
												).find(
													( entry ) =>
														entry.value ===
														group.section.location
												)?.label ??
													group.section.location }
											</Badge>

											<IconButton
												icon="↑"
												label={ sprintf(
													/* translators: %s: section title. */
													__(
														'Move section %s earlier',
														'wc-checkoutsuite'
													),
													group.section.title
												) }
												disabled={ 0 === declaredIndex }
												onClick={ () =>
													apply(
														moveSection(
															document,
															group.section.id,
															'up'
														)
													)
												}
											/>
											<IconButton
												icon="↓"
												label={ sprintf(
													/* translators: %s: section title. */
													__(
														'Move section %s later',
														'wc-checkoutsuite'
													),
													group.section.title
												) }
												disabled={
													declaredIndex ===
														declaredSectionIds.length -
															1 ||
													0 ===
														declaredSectionIds.length
												}
												onClick={ () =>
													apply(
														moveSection(
															document,
															group.section.id,
															'down'
														)
													)
												}
											/>
											<Button
												size="small"
												onClick={ () =>
													setEditingSection(
														group.section
													)
												}
											>
												{ __(
													'Rename',
													'wc-checkoutsuite'
												) }
											</Button>
											<Button
												size="small"
												variant="danger"
												onClick={ () =>
													apply(
														removeSection(
															document,
															group.section.id
														)
													)
												}
											>
												{ __(
													'Remove',
													'wc-checkoutsuite'
												) }
											</Button>
										</>
									) : (
										<span className="wccs-fields__group-note">
											{ __(
												'A WooCommerce location. Declare a section to rename it.',
												'wc-checkoutsuite'
											) }
										</span>
									) }
								</header>

								<SortableList
									label={ sprintf(
										/* translators: %s: section title. */
										__(
											'Fields in %s',
											'wc-checkoutsuite'
										),
										group.section.title
									) }
									items={ group.fields }
									getLabel={ ( /** @type {any} */ item ) =>
										item.label
									}
									emptyText={ __(
										'No fields in this section yet.',
										'wc-checkoutsuite'
									) }
									onMove={ (
										/** @type {string} */ id,
										/** @type {'up'|'down'} */ direction
									) =>
										apply(
											moveField( document, id, direction )
										)
									}
									renderItem={ (
										/** @type {any} */ field
									) => (
										<div
											className={
												'wccs-fields__row' +
												( field.enabled
													? ''
													: ' is-archived' )
											}
										>
											<input
												type="checkbox"
												className="wccs-fields__select"
												checked={ selected.includes(
													field.id
												) }
												aria-label={ sprintf(
													/* translators: %s: field label. */
													__(
														'Select %s',
														'wc-checkoutsuite'
													),
													field.label
												) }
												onChange={ () =>
													toggleSelected( field.id )
												}
											/>

											<div className="wccs-fields__row-main">
												<span className="wccs-fields__row-label">
													{ field.label }
												</span>
												<span className="wccs-fields__row-id">
													{ field.id } ·{ ' ' }
													{ field.type }
												</span>
											</div>

											<div className="wccs-fields__row-badges">
												{ isProtected( field ) ? (
													<Badge tone="brand">
														{ __(
															'WooCommerce',
															'wc-checkoutsuite'
														) }
													</Badge>
												) : null }
												{ field.required ? (
													<Badge tone="neutral">
														{ __(
															'Required',
															'wc-checkoutsuite'
														) }
													</Badge>
												) : null }
												{ ! field.enabled ? (
													<Badge tone="neutral">
														{ __(
															'Archived',
															'wc-checkoutsuite'
														) }
													</Badge>
												) : null }
											</div>

											<div className="wccs-fields__row-actions">
												<Button
													size="small"
													onClick={ () =>
														setEditing( field.id )
													}
												>
													{ __(
														'Edit',
														'wc-checkoutsuite'
													) }
												</Button>
												<Button
													size="small"
													onClick={ () =>
														apply(
															duplicateField(
																document,
																field.id
															)
														)
													}
												>
													{ __(
														'Duplicate',
														'wc-checkoutsuite'
													) }
												</Button>
												{ isProtected( field ) ? (
													<span className="wccs-fields__protected">
														{ protectionReason(
															field
														) }
													</span>
												) : (
													<>
														<Button
															size="small"
															onClick={ () =>
																apply(
																	setFieldEnabled(
																		document,
																		field.id,
																		! field.enabled
																	)
																)
															}
														>
															{ field.enabled
																? __(
																		'Archive',
																		'wc-checkoutsuite'
																  )
																: __(
																		'Restore',
																		'wc-checkoutsuite'
																  ) }
														</Button>
														<Button
															size="small"
															variant="danger"
															onClick={ () =>
																apply(
																	removeField(
																		document,
																		field.id
																	)
																)
															}
														>
															{ __(
																'Remove',
																'wc-checkoutsuite'
															) }
														</Button>
													</>
												) }
											</div>
										</div>
									) }
								/>
							</section>
						);
					} ) }
				</div>
			) }

			<section
				className="wccs-fields__sections"
				aria-labelledby="wccs-fields-add-section"
			>
				<h3
					className="wccs-fields__group-title"
					id="wccs-fields-add-section"
				>
					{ __( 'Add a section', 'wc-checkoutsuite' ) }
				</h3>

				<p className="wccs-fields__summary">
					{ __(
						'A section groups fields and says where they belong. The five locations are the concepts the checkout already has.',
						'wc-checkoutsuite'
					) }
				</p>

				<div className="wccs-fields__section-form">
					<TextField
						id="wccs-new-section-title"
						label={ __( 'Title', 'wc-checkoutsuite' ) }
						value={ newSectionTitle }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => setNewSectionTitle( event.target.value ) }
					/>

					<SelectField
						id="wccs-new-section-location"
						label={ __( 'Location', 'wc-checkoutsuite' ) }
						value={ newSectionLocation }
						options={ ( catalog?.sectionLocations ?? [] ).map(
							( entry ) => ( {
								value: entry.value,
								label: entry.label,
							} )
						) }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => setNewSectionLocation( event.target.value ) }
					/>

					<Button
						variant="secondary"
						disabled={ '' === newSectionTitle.trim() }
						onClick={ () => {
							apply(
								createSection( document, {
									title: newSectionTitle.trim(),
									location: newSectionLocation,
								} )
							);
							setNewSectionTitle( '' );
						} }
					>
						{ __( 'Add section', 'wc-checkoutsuite' ) }
					</Button>
				</div>
			</section>

			<section className="wccs-fields__picker">
				<h3 className="wccs-fields__group-title">
					{ __( 'Add a field', 'wc-checkoutsuite' ) }
				</h3>

				<FieldPicker
					catalog={ catalog }
					coreFields={ coreFields }
					section={ section }
					sections={ sectionOptions }
					onSectionChange={ setSection }
					onChooseType={ ( choice ) =>
						apply(
							createField( document, {
								...choice,
								label: choice.defaults?.label ?? choice.label,
								section,
								settings: choice.settings,
								layout: choice.defaults?.layout,
							} )
						)
					}
					onAdoptCore={ ( core ) =>
						apply( adoptCoreField( document, core ) )
					}
				/>
			</section>

			<Dialog
				open={ Boolean( editingSection ) }
				title={ __( 'Section', 'wc-checkoutsuite' ) }
				onClose={ () => setEditingSection( null ) }
				footer={
					<Button
						variant="primary"
						onClick={ () => setEditingSection( null ) }
					>
						{ __( 'Done', 'wc-checkoutsuite' ) }
					</Button>
				}
			>
				{ editingSection ? (
					<>
						<TextField
							id="wccs-section-title"
							label={ __( 'Title', 'wc-checkoutsuite' ) }
							value={ editingSection.title }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) =>
								apply(
									updateSection(
										document,
										editingSection.id,
										{ title: event.target.value }
									)
								)
							}
						/>

						<TextareaField
							id="wccs-section-description"
							label={ __( 'Description', 'wc-checkoutsuite' ) }
							value={ editingSection.description }
							maxLength={ 500 }
							rows={ 3 }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) =>
								apply(
									updateSection(
										document,
										editingSection.id,
										{ description: event.target.value }
									)
								)
							}
						/>

						<SelectField
							id="wccs-section-location"
							label={ __( 'Location', 'wc-checkoutsuite' ) }
							value={ editingSection.location }
							options={ ( catalog?.sectionLocations ?? [] ).map(
								( entry ) => ( {
									value: entry.value,
									label: entry.label,
								} )
							) }
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) =>
								apply(
									updateSection(
										document,
										editingSection.id,
										{ location: event.target.value }
									)
								)
							}
						/>
					</>
				) : null }
			</Dialog>

			<Dialog
				open={ Boolean( editingField ) }
				title={ __( 'Field properties', 'wc-checkoutsuite' ) }
				onClose={ () => setEditing( null ) }
				footer={
					<Button
						variant="primary"
						onClick={ () => setEditing( null ) }
					>
						{ __( 'Done', 'wc-checkoutsuite' ) }
					</Button>
				}
			>
				{ editingField ? (
					<FieldInspector
						field={ editingField }
						catalog={ catalog }
						hasConditions={ Boolean( catalog?.conditions ) }
						fields={ ( document?.fields ?? [] ).map(
							(
								/** @type {import('./schema/types').FieldDefinition} */ entry
							) => ( {
								id: entry.id,
								label: entry.label,
							} )
						) }
						onChange={ (
							/** @type {Partial<import('./schema/types').FieldDefinition>} */ changes
						) =>
							apply(
								updateField(
									document,
									editingField.id,
									changes
								)
							)
						}
					/>
				) : null }
			</Dialog>
		</div>
	);
}
