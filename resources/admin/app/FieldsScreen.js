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
import { TextField, SelectField, CheckboxField } from './components/controls';
import FieldManagerView from './views/FieldManagerView';
import useUnsavedChanges from './api/useUnsavedChanges';
import { sectionHref } from './sectionUrl';
import { Icon } from './design/icons';
import useDocumentHistory from './schema/useDocumentHistory';
import {
	classifyFailure,
	missingExtension,
	unsupportedVersion,
} from './schema/failureState';
import {
	adoptCoreField,
	bulkImpact,
	conditionDependents,
	createField,
	createSection,
	duplicateField,
	isProtected,
	moveField,
	moveSection,
	reorderField,
	protectionReason,
	removeField,
	removeSection,
	removeSectionWithDependents,
	sectionImpact,
	sectionGroups,
	setFieldEnabled,
	uniqueSectionId,
	updateField,
	updateSection,
} from './schema/fieldOperations';

/**
 * Default section when the store offers none.
 *
 * @type {string}
 */
const FALLBACK_SECTION = 'order';

/** The three stable work areas of the editor, mapped to real destinations. */
const EDITOR_AREAS = [
	{
		id: 'checkout',
		label: __( 'Checkout', 'wc-checkoutsuite' ),
		description: __(
			'Campos preenchidos durante a compra.',
			'wc-checkoutsuite'
		),
	},
	{
		id: 'customer_order',
		label: __( 'Cliente', 'wc-checkoutsuite' ),
		description: __(
			'Minha conta → Pedidos → Ver pedido.',
			'wc-checkoutsuite'
		),
	},
	{
		id: 'admin_order',
		label: __( 'Pedido', 'wc-checkoutsuite' ),
		description: __(
			'Admin → WooCommerce → Pedidos → Editar pedido.',
			'wc-checkoutsuite'
		),
	},
];

/** Human location shown beside each section area choice. */
/** @type {Record<string, string>} */
const SECTION_AREA_REFERENCE = {
	checkout: __(
		'Preenchido pelo cliente durante a finalização da compra.',
		'wc-checkoutsuite'
	),
	admin_order: __(
		'Exibido para a equipe em WooCommerce → Pedidos → Editar pedido.',
		'wc-checkoutsuite'
	),
	customer_order: __(
		'Exibido ao cliente em Minha conta → Pedidos → Ver pedido.',
		'wc-checkoutsuite'
	),
	order_received: __(
		'Exibido na página mostrada ao cliente após concluir o pedido.',
		'wc-checkoutsuite'
	),
	customer_email: __(
		'Exibido nos e-mails enviados ao cliente sobre o pedido.',
		'wc-checkoutsuite'
	),
	admin_email: __(
		'Exibido nos e-mails internos enviados para a loja.',
		'wc-checkoutsuite'
	),
	customer_profile: __(
		'Exibido no perfil do cliente no painel administrativo.',
		'wc-checkoutsuite'
	),
	public_api: __(
		'Disponibilizado pela API pública, quando autorizada.',
		'wc-checkoutsuite'
	),
};

/** Local draft handoff used when the shell swaps the editor for another screen. */
const LOCAL_DRAFT_KEY = 'wccs-local-draft';

/**
 * Fields screen.
 *
 * @param {Object} props            Component properties.
 * @param {any}    props.client     REST client.
 * @param {string} [props.view]     View the frame is showing: `fields`,
 *                                  `appearance`, `archive` or `rules`.
 * @param {string} [props.siteName] Store name, for the preview's mock header.
 * @return {*} Rendered element tree.
 */
export default function FieldsScreen( {
	client,
	view = 'fields',
	siteName = '',
} ) {
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
	 * Whether the dialog that creates a section is open.
	 *
	 * The design draws the five checkout locations as fixed tabs; this plugin lets a
	 * merchant add one of their own, so the control lives in the tab strip and opens
	 * the same kind of dialog the rest of the screen uses.
	 *
	 * @type {[boolean, Function]}
	 */
	const [ sectionDraftOpen, setSectionDraftOpen ] = useState( false );

	/**
	 * The checkout the screen is configuring for.
	 *
	 * The design's context bar switches between the classic checkout and the Blocks
	 * checkout, because the same document renders differently in each and the
	 * capability matrix that says so is per adapter. It is view state: the document
	 * is the same one either way.
	 *
	 * @type {[string, Function]}
	 */
	const [ mode, setMode ] = useState( 'classic' );
	const [ area, setArea ] = useState( 'checkout' );
	const [ linkDialogOpen, setLinkDialogOpen ] = useState( false );
	const [ linkFieldId, setLinkFieldId ] = useState( '' );
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
	const [ editingSectionId, setEditingSectionId ] = useState(
		/** @type {string|null} */ ( null )
	);
	const [ sectionRemoval, setSectionRemoval ] = useState(
		/** @type {{id: string, impact: {fields: string[], links: string[], approvals: string[]}}|null} */ (
			null
		)
	);

	/**
	 * Resolve the section from the current document on every render. Keeping the
	 * object itself in state made the inspector display the value from before the
	 * previous edit was applied.
	 *
	 * @type {import('./schema/types').SectionDefinition|null}
	 */
	const editingSection = useMemo(
		() =>
			document?.sections?.find(
				( /** @type {any} */ entry ) => entry.id === editingSectionId
			) ?? null,
		[ document, editingSectionId ]
	);

	/** @type {[string, Function]} */
	const [ newSectionTitle, setNewSectionTitle ] = useState( '' );

	/** @type {[string, Function]} */
	const [ newSectionLocation, setNewSectionLocation ] = useState( 'billing' );
	/**
	 * The areas a section being created is offered in.
	 *
	 * A section is offered somewhere or it cannot be chosen; the checkout is where a
	 * section has always lived, so that is what starts ticked.
	 *
	 * @type {[string[], Function]}
	 */
	const [ newSectionAreas, setNewSectionAreas ] = useState( [ 'checkout' ] );
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

	/**
	 * Sends the merchant to another screen of the suite.
	 *
	 * The design's footnote and tip card link to the rules and to the checkout
	 * preview. Both are sections of this screen's shell, and the shell reads the
	 * address bar, so navigating is replacing the query parameter.
	 *
	 * @param {string} id Section identifier.
	 * @return {void}
	 */
	const goTo = ( id ) => {
		const known = [
			'fields',
			'appearance',
			'sections',
			'rules',
			'checkout-page',
			'import-export',
			'diagnostics',
			'settings',
		];

		if ( window.history?.replaceState ) {
			window.history.replaceState(
				null,
				'',
				sectionHref( window.location.href, id, known )
			);
		}

		// The frame owns the active section; this asks it to move.
		window.dispatchEvent(
			new CustomEvent( 'wccs:navigate', { detail: { section: id } } )
		);
	};

	const onPreview = () => goTo( 'appearance' );

	const onOpenRules = () => goTo( 'rules' );

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
					( client.getActive ?? client.getDraft )(),
					client.fieldTypes(),
					client.coreFields(),
					client.diff(),
					client.revisions(),
				] );

			if ( ! mounted.current ) {
				return;
			}

			let local = null;
			try {
				const handoffEnabled =
					window.location.search.includes( 'wccs-checkoutsuite' ) ||
					window.document.body?.classList.contains( 'wccs-admin' );
				const stored = handoffEnabled
					? window.sessionStorage?.getItem( LOCAL_DRAFT_KEY )
					: null;
				local = stored ? JSON.parse( stored ) : null;
			} catch {
				local = null;
			}

			if ( local?.baseRevision === draft.revision && local.document ) {
				resetDocument( local.document );
				setSaved(
					__(
						'Há uma edição local não salva preservada nesta sessão.',
						'wc-checkoutsuite'
					)
				);
			} else {
				window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
				resetDocument( draft );
			}
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
		if (
			! document ||
			! savedDocument ||
			document === savedDocument ||
			( ! window.location.search.includes( 'wccs-checkoutsuite' ) &&
				! window.document.body?.classList.contains( 'wccs-admin' ) )
		) {
			return;
		}

		try {
			window.sessionStorage?.setItem(
				LOCAL_DRAFT_KEY,
				JSON.stringify( {
					baseRevision: savedDocument.revision,
					document,
				} )
			);
		} catch {
			// Storage is an enhancement for an internal navigation; the editor still
			// works when the browser blocks sessionStorage.
		}
	}, [ document, savedDocument ] );

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
	 * @param {import('./schema/types').OperationResult} result  Operation result.
	 * @param {string}                                   [label] What the edit was.
	 * @return {void}
	 */
	const apply = useCallback(
		(
			/** @type {import('./schema/types').OperationResult} */ result,
			label = ''
		) => {
			if ( ! result.ok ) {
				setRefusal( result.reason );

				return;
			}

			setRefusal( '' );
			setProblems( [] );
			setSaved( '' );
			commitDocument( result.document, label );
		},
		[ commitDocument ]
	);

	/**
	 * Sections in display order, including the ones fields imply.
	 *
	 * @type {import('./schema/types').SectionGroup[]}
	 */
	const groups = useMemo(
		() => ( document ? sectionGroups( document, area ) : [] ),
		[ document, area ]
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
	 * Sections offered when adding a field.
	 *
	 * Declared sections come first, in their own order, followed by the domain
	 * locations the store has not declared a section for. All five locations are
	 * always usable: ROADMAP.md section 4 fixes them as domain concepts, and an
	 * adopted WooCommerce field already belongs to one.
	 *
	 * @type {Array<{id: string, label: string}>}
	 */
	const sectionOptions = useMemo( () => {
		/** @type {Array<{id: string, label: string}>} */
		const options = [];
		/** @type {Set<string>} */
		const seen = new Set();

		for ( const group of groups ) {
			if ( seen.has( group.section.id ) ) {
				continue;
			}

			seen.add( group.section.id );
			options.push( {
				id: group.section.id,
				label: group.section.title,
			} );
		}

		// Native locations are collection concepts. They are useful fallbacks only
		// while configuring Checkout; adding them in a display area would retain a
		// stale Checkout selection after the merchant changes area.
		if ( 'checkout' === area ) {
			for ( const location of catalog?.sectionLocations ?? [] ) {
				if ( seen.has( location.value ) ) {
					continue;
				}

				seen.add( location.value );
				options.push( { id: location.value, label: location.label } );
			}
		}

		return options;
	}, [ groups, catalog, area ] );

	/**
	 * Keeps the target section valid as the list of sections arrives.
	 *
	 * @return {void}
	 */
	useEffect( () => {
		if ( sectionOptions.some( ( entry ) => entry.id === section ) ) {
			return;
		}

		if ( sectionOptions.length > 0 ) {
			setSection( sectionOptions[ 0 ].id );
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
	 * Applies the current editor document to the active checkout configuration.
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
			const result = await ( client.updateActive ?? client.saveDraft )(
				document,
				document.revision
			);

			if ( ! mounted.current ) {
				return;
			}

			// The server is authoritative after the write. Read the active document
			// because the update route assigns the next revision on the server.
			const active = result?.fields
				? result
				: await ( client.getActive ?? client.getDraft )();
			resetDocument( active );
			setSavedDocument( active );
			window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
			setSaved(
				document.revision > 0
					? __(
							'Campos atualizados com sucesso.',
							'wc-checkoutsuite'
					  )
					: __( 'Campos salvos com sucesso.', 'wc-checkoutsuite' )
			);

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
		if ( ! document || dirty ) {
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
	}, [ client, document, dirty, refreshPublication ] );

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

	// Read from the draft on every render so the inspector shows what is stored,
	// not a copy taken when the dialog opened.
	const editingField =
		document.fields.find(
			( /** @type {any} */ field ) => field.id === editing
		) ?? null;

	/** @type {Record<string, string>} */
	const destinationNames = {
		admin_order: __(
			'Pedido no admin (WooCommerce → Pedidos)',
			'wc-checkoutsuite'
		),
		customer_order: __(
			'Pedido na conta do cliente (Minha conta → Pedidos)',
			'wc-checkoutsuite'
		),
		order_received: __( 'Página de pedido recebido', 'wc-checkoutsuite' ),
		customer_email: __( 'E-mail enviado ao cliente', 'wc-checkoutsuite' ),
		admin_email: __( 'E-mail enviado à loja', 'wc-checkoutsuite' ),
		customer_profile: __( 'Perfil do cliente', 'wc-checkoutsuite' ),
		public_api: __( 'API pública', 'wc-checkoutsuite' ),
	};
	const dependencyField = ( /** @type {string} */ id ) =>
		document.fields.find( ( /** @type {any} */ field ) => field.id === id )
			?.label ?? id;

	/**
	 * Applies a bulk action to everything selected.
	 *
	 * The design's bar offers enable, disable and archive. In this schema archiving
	 * *is* disabling — a field that is not rendered keeps its definition and its
	 * stored values, which is the promise the footnote makes — so the two actions
	 * share one operation rather than pretending to be different states.
	 *
	 * @param {'enable'|'disable'|'archive'} action Action to apply.
	 * @return {{ok: boolean, count: number}} Whether it applied, and to how many.
	 */
	const bulk = ( action ) => {
		let next = document;
		let refused = '';

		document.fields
			.filter( ( /** @type {any} */ field ) =>
				selected.includes( field.id )
			)
			.forEach( ( /** @type {any} */ field ) => {
				const result = setFieldEnabled(
					next,
					field.id,
					'enable' === action
				);

				if ( result.ok ) {
					next = result.document;
				} else {
					refused = result.reason;
				}
			} );

		const attempted = selected.length;

		if ( '' !== refused ) {
			setRefusal( refused );
			setSelected( [] );

			return { ok: false, count: 0 };
		}

		apply(
			{ ok: true, reason: '', document: next },
			__( 'Ação em massa', 'wc-checkoutsuite' )
		);
		setSelected( [] );

		return { ok: true, count: attempted };
	};

	/**
	 * Downloads the published schema as the file the export route produces.
	 *
	 * The button exists in the design's footer and does what it says: the merchant
	 * gets the document they can import into another store, produced by the same
	 * controller the import reads.
	 *
	 * @return {Promise<boolean>} Whether the file was produced.
	 */
	const exportConfig = async () => {
		const answer = await client.exportSchema();

		if ( ! answer?.ok ) {
			setRefusal(
				answer?.message ??
					__(
						'The export could not be produced.',
						'wc-checkoutsuite'
					)
			);

			return false;
		}

		const blob = new Blob( [ JSON.stringify( answer.data, null, 2 ) ], {
			type: 'application/json',
		} );
		const url = URL.createObjectURL( blob );

		// The screen's `document` is the schema, so the page has to be named
		// explicitly here: reaching for `document` resolves to the schema, and the
		// download then never starts.
		const page = globalThis.document;
		const link = page.createElement( 'a' );

		link.href = url;
		link.download = 'wc-checkoutsuite-schema.json';
		page.body.appendChild( link );
		link.click();
		link.remove();
		URL.revokeObjectURL( url );

		return true;
	};

	/**
	 * Explains, in the place the screen already reports refusals, why a WooCommerce
	 * field cannot be deleted.
	 *
	 * @param {string|null} id Field identifier.
	 * @return {void}
	 */
	const explainProtection = ( id ) => {
		const field = document?.fields.find(
			( /** @type {any} */ entry ) => entry.id === id
		);

		setRefusal(
			field
				? protectionReason( field )
				: __(
						'That field is not in this document.',
						'wc-checkoutsuite'
				  )
		);
	};

	return (
		<>
			<FieldManagerView
				model={ {
					document,
					groups,
					section,
					onSectionChange: setSection,
					catalog,
					coreFields,
					sectionOptions,
					sections: groups.map( ( /** @type {any} */ group ) => ( {
						id: group.section.id,
						label: group.section.title ?? group.section.id,
						areas: group.section.areas ?? [ 'checkout' ],
					} ) ),
					area,
					areas: EDITOR_AREAS,
					onAreaChange: setArea,
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
					onToggleSelected: toggleSelected,
					onBulk: bulk,
					bulkImpactFor: (
						/** @type {'enable'|'disable'|'archive'} */ action
					) => ( {
						...bulkImpact( document, selected, action ),
						dependents: conditionDependents( document, selected ),
					} ),
					onClearSelection: () => setSelected( [] ),
					editing,
					onEdit: setEditing,
					onDuplicate: ( /** @type {string|null} */ id ) =>
						id &&
						apply(
							duplicateField( document, id ),
							__( 'Duplicar campo', 'wc-checkoutsuite' )
						),
					onToggleEnabled: ( /** @type {string} */ id ) => {
						const field = document.fields.find(
							( /** @type {any} */ entry ) => entry.id === id
						);

						apply(
							setFieldEnabled(
								document,
								id,
								! ( field?.enabled ?? true )
							)
						);
					},
					onMove: (
						/** @type {string} */ id,
						/** @type {'up'|'down'} */ direction
					) =>
						apply(
							moveField( document, id, direction ),
							__( 'Reordenar campo', 'wc-checkoutsuite' )
						),
					onReorder: (
						/** @type {string} */ id,
						/** @type {string} */ targetId
					) =>
						apply(
							reorderField( document, id, targetId ),
							__( 'Reordenar campo', 'wc-checkoutsuite' )
						),
					onRemove: ( /** @type {string|null} */ id ) =>
						id && apply( removeField( document, id ) ),
					onProtect: explainProtection,
					onCreateField: ( /** @type {any} */ choice ) => {
						if ( 'checkout' !== area ) {
							setRefusal(
								__(
									'Campos são criados no Checkout. Nesta área, vincule um campo existente.',
									'wc-checkoutsuite'
								)
							);
							return;
						}

						apply(
							createField( document, {
								...choice,
								label: choice.defaults?.label ?? choice.label,
								section,
								settings: choice.settings,
								layout: choice.defaults?.layout,
							} )
						);
					},
					onAdoptCore: ( /** @type {any} */ core ) =>
						apply( adoptCoreField( document, core ) ),
					onChangeField: (
						/** @type {Partial<import('./schema/types').FieldDefinition>} */ changes
					) =>
						editingField &&
						apply(
							updateField( document, editingField.id, changes )
						),
					edits,
					onSave: save,
					publishing,
					publishError,
					report,
					onPublish: publish,
					revisions,
					restoring,
					restored,
					onRestore: restore,
					view,
					onBackToEditor: () => goTo( 'fields' ),
					onRestoreField: ( /** @type {string} */ id ) =>
						apply( setFieldEnabled( document, id, true ) ),
					siteName,
					reference: mode,
					onReferenceChange: setMode,
					onPreview,
					onOpenRules,
					onExport: exportConfig,
					isProtected,
					onOpenSection: ( /** @type {string|null} */ id ) =>
						id &&
						document.sections.some(
							( /** @type {any} */ entry ) => entry.id === id
						)
							? setEditingSectionId( id )
							: setRefusal(
									__(
										'Esta seção implícita do WooCommerce não pode ser editada. Crie uma seção personalizada para configurar nome e áreas.',
										'wc-checkoutsuite'
									)
							  ),
					onCreateSection: () => {
						setNewSectionAreas( [ area ] );
						setNewSectionLocation(
							'checkout' === area ? 'billing' : 'order'
						);
						setSectionDraftOpen( true );
					},
					onLinkExisting: () => {
						setLinkFieldId( '' );
						setLinkDialogOpen( true );
					},
					removalDialog: sectionRemoval ? (
						<Dialog
							open={ true }
							title={ __(
								'Remover seção e dependências',
								'wc-checkoutsuite'
							) }
							onClose={ () => setSectionRemoval( null ) }
							footer={
								<>
									<Button
										variant="secondary"
										onClick={ () =>
											setSectionRemoval( null )
										}
									>
										{ __( 'Cancelar', 'wc-checkoutsuite' ) }
									</Button>
									<Button
										variant="primary"
										onClick={ () => {
											const result =
												removeSectionWithDependents(
													document,
													sectionRemoval.id
												);
											if ( result.ok ) {
												apply( result );
												setEditingSectionId( null );
												setSectionRemoval( null );
											} else {
												setRefusal( result.reason );
											}
										} }
									>
										{ __(
											'Remover seção e campos',
											'wc-checkoutsuite'
										) }
									</Button>
								</>
							}
						>
							<p>
								{ __(
									'Esta ação remove a seção e os itens abaixo. Ela não pode ser desfeita pelo botão Desfazer.',
									'wc-checkoutsuite'
								) }
							</p>
							{ sectionRemoval.impact.fields.length > 0 ? (
								<>
									<strong>
										{ __(
											'Campos que serão removidos',
											'wc-checkoutsuite'
										) }
									</strong>
									<ol className="wccs-dependency-list">
										{ sectionRemoval.impact.fields.map(
											( /** @type {string} */ id ) => (
												<li key={ id }>
													{ document.fields.find(
														(
															/** @type {any} */ field
														) => field.id === id
													)?.label ?? id }{ ' ' }
													<code>{ id }</code>
												</li>
											)
										) }
									</ol>
								</>
							) : null }
							{ sectionRemoval.impact.links.length > 0 ? (
								<>
									<strong>
										{ __(
											'Vínculos de exibição que serão removidos',
											'wc-checkoutsuite'
										) }
									</strong>
									<p>
										{ __(
											'Os campos abaixo continuarão existindo. Apenas deixarão de aparecer neste local de exibição.',
											'wc-checkoutsuite'
										) }
									</p>
									<ol className="wccs-dependency-list">
										{ sectionRemoval.impact.links.map(
											(
												/** @type {string} */ reference
											) => {
												const [ fieldId, destination ] =
													reference.split( ':' );
												return (
													<li key={ reference }>
														<strong>
															{ dependencyField(
																fieldId
															) }
														</strong>{ ' ' }
														—{ ' ' }
														{ destinationNames[
															destination
														] ?? destination }
													</li>
												);
											}
										) }
									</ol>
								</>
							) : null }
							{ sectionRemoval.impact.approvals.length > 0 ? (
								<>
									<strong>
										{ __(
											'Fluxos de aprovação que serão removidos',
											'wc-checkoutsuite'
										) }
									</strong>
									<p>
										{ __(
											'São revisões humanas configuradas para esses campos.',
											'wc-checkoutsuite'
										) }
									</p>
									<ol className="wccs-dependency-list">
										{ sectionRemoval.impact.approvals.map(
											( /** @type {string} */ id ) => (
												<li key={ id }>
													<strong>
														{ dependencyField(
															id
														) }
													</strong>{ ' ' }
													—{ ' ' }
													{
														destinationNames[
															document.fields.find(
																(
																	/** @type {any} */ field
																) =>
																	field.id ===
																	id
															)?.approval?.area ??
																'admin_order'
														]
													}
												</li>
											)
										) }
									</ol>
								</>
							) : null }
						</Dialog>
					) : null,
					linkDialog: (
						<Dialog
							open={ linkDialogOpen }
							title={ __(
								'Vincular campo existente',
								'wc-checkoutsuite'
							) }
							onClose={ () => setLinkDialogOpen( false ) }
							footer={
								<>
									<Button
										variant="secondary"
										onClick={ () =>
											setLinkDialogOpen( false )
										}
									>
										{ __( 'Cancelar', 'wc-checkoutsuite' ) }
									</Button>
									<Button
										variant="primary"
										disabled={
											'' === linkFieldId ||
											'checkout' === area
										}
										onClick={ () => {
											const field = document.fields.find(
												( /** @type {any} */ entry ) =>
													entry.id === linkFieldId
											);

											if ( ! field ) {
												return;
											}

											apply(
												updateField(
													document,
													field.id,
													{
														destinations: {
															...( field.destinations ??
																{} ),
															[ area ]: {
																...( field
																	.destinations?.[
																	area
																] ?? {} ),
																enabled: true,
																section,
															},
														},
													}
												)
											);
											setLinkDialogOpen( false );
										} }
									>
										{ __(
											'Vincular campo',
											'wc-checkoutsuite'
										) }
									</Button>
								</>
							}
						>
							<p>
								{ __(
									'Este vínculo altera apenas a exibição autorizada. A coleta, a chave e os valores do pedido permanecem os mesmos.',
									'wc-checkoutsuite'
								) }
							</p>
							<SelectField
								id="wccs-link-existing-field"
								label={ __( 'Campo', 'wc-checkoutsuite' ) }
								value={ linkFieldId }
								options={ [
									{
										value: '',
										label: __(
											'Selecione um campo',
											'wc-checkoutsuite'
										),
									},
									...( document.fields ?? [] )
										.filter(
											( /** @type {any} */ field ) =>
												field.enabled
										)
										.map(
											( /** @type {any} */ field ) => ( {
												value: field.id,
												label: `${ field.label } (${ field.id })`,
											} )
										),
								] }
								onChange={ (
									/** @type {{target: {value: string}}} */ event
								) => setLinkFieldId( event.target.value ) }
							/>
						</Dialog>
					),
					sectionDraft: (
						<Dialog
							open={ sectionDraftOpen }
							title={ __( 'Add a section', 'wc-checkoutsuite' ) }
							onClose={ () => setSectionDraftOpen( false ) }
							footer={
								<>
									<Button
										variant="secondary"
										onClick={ () =>
											setSectionDraftOpen( false )
										}
									>
										{ __( 'Cancel', 'wc-checkoutsuite' ) }
									</Button>
									<Button
										variant="primary"
										disabled={
											'' === newSectionTitle.trim() ||
											0 === newSectionAreas.length
										}
										onClick={ () => {
											const title =
												newSectionTitle.trim();
											const result = createSection(
												document,
												{
													title,
													location:
														newSectionLocation,
													areas: newSectionAreas,
												}
											);

											if ( ! result.ok ) {
												return;
											}

											apply( result );
											setSection(
												uniqueSectionId(
													document,
													title
												)
											);
											setNewSectionTitle( '' );
											setNewSectionAreas( [
												'checkout',
											] );
											setSectionDraftOpen( false );
										} }
									>
										{ __(
											'Add section',
											'wc-checkoutsuite'
										) }
									</Button>
								</>
							}
						>
							<p className="muted small">
								{ __(
									'A section groups fields and says where they belong. The five locations are the concepts the checkout already has.',
									'wc-checkoutsuite'
								) }
							</p>

							<TextField
								id="wccs-new-section-title"
								label={ __( 'Title', 'wc-checkoutsuite' ) }
								value={ newSectionTitle }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) => setNewSectionTitle( event.target.value ) }
							/>

							{ 'checkout' === area ? (
								<>
									<SelectField
										id="wccs-new-section-location"
										label={ __(
											'Location',
											'wc-checkoutsuite'
										) }
										value={ newSectionLocation }
										options={ (
											catalog?.sectionLocations ?? []
										).map(
											( /** @type {any} */ entry ) => ( {
												value: entry.value,
												label: entry.label,
											} )
										) }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											setNewSectionLocation(
												event.target.value
											)
										}
									/>
									<div className="form-label">
										{ __( 'Areas', 'wc-checkoutsuite' ) }
									</div>
									{ ( catalog?.sectionAreas ?? [] ).map(
										( /** @type {any} */ entry ) => (
											<CheckboxField
												key={ entry.value }
												id={ `wccs-new-section-area-${ entry.value }` }
												label={ entry.label }
												help={
													SECTION_AREA_REFERENCE[
														entry.value
													] ?? entry.description
												}
												checked={ newSectionAreas.includes(
													entry.value
												) }
												onChange={ (
													/** @type {{ target: { checked: boolean } }} */ event
												) =>
													setNewSectionAreas(
														event.target.checked
															? [
																	...newSectionAreas,
																	entry.value,
															  ]
															: newSectionAreas.filter(
																	(
																		/** @type {string} */ key
																	) =>
																		key !==
																		entry.value
															  )
													)
												}
											/>
										)
									) }
								</>
							) : (
								<Notice status="info">
									{ __(
										'Esta seção será usada apenas para organizar a exibição nesta área. Para mostrar valores nela, vincule campos existentes.',
										'wc-checkoutsuite'
									) }
								</Notice>
							) }
							{ 0 === newSectionAreas.length ? (
								<Notice status="warning">
									{ __(
										'Escolha pelo menos uma área para criar a seção.',
										'wc-checkoutsuite'
									) }
								</Notice>
							) : null }
						</Dialog>
					),
					sectionEditor: (
						<Dialog
							open={ null !== editingSectionId }
							title={ __( 'Section', 'wc-checkoutsuite' ) }
							onClose={ () => setEditingSectionId( null ) }
							footer={
								<Button
									variant="primary"
									onClick={ () =>
										setEditingSectionId( null )
									}
								>
									{ __( 'Done', 'wc-checkoutsuite' ) }
								</Button>
							}
						>
							{ editingSection ? (
								<>
									<TextField
										id="wccs-section-title"
										label={ __(
											'Title',
											'wc-checkoutsuite'
										) }
										value={ editingSection.title }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											apply(
												updateSection(
													document,
													editingSection.id,
													{
														title: event.target
															.value,
													}
												)
											)
										}
									/>

									<SelectField
										id="wccs-section-location"
										label={ __(
											'Location',
											'wc-checkoutsuite'
										) }
										value={ editingSection.location }
										options={ (
											catalog?.sectionLocations ?? []
										).map(
											( /** @type {any} */ entry ) => ( {
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
													{
														location:
															event.target.value,
													}
												)
											)
										}
									/>

									<div className="form-label">
										{ __( 'Areas', 'wc-checkoutsuite' ) }
									</div>
									{ ( catalog?.sectionAreas ?? [] ).map(
										( /** @type {any} */ entry ) => (
											<CheckboxField
												key={ entry.value }
												id={ `wccs-section-area-${ entry.value }` }
												label={ entry.label }
												help={
													SECTION_AREA_REFERENCE[
														entry.value
													] ?? entry.description
												}
												checked={ (
													editingSection.areas ?? []
												).includes( entry.value ) }
												onChange={ (
													/** @type {{ target: { checked: boolean } }} */ event
												) => {
													const current =
														editingSection.areas ??
														[];
													if (
														! event.target
															.checked &&
														current.length <= 1
													) {
														setRefusal(
															__(
																'Uma seção precisa permanecer ativa em pelo menos uma área.',
																'wc-checkoutsuite'
															)
														);
														return;
													}

													apply(
														updateSection(
															document,
															editingSection.id,
															{
																areas: event
																	.target
																	.checked
																	? [
																			...current,
																			entry.value,
																	  ]
																	: current.filter(
																			(
																				/** @type {string} */ key
																			) =>
																				key !==
																				entry.value
																	  ),
															}
														)
													);
												} }
											/>
										)
									) }

									<div className="inline-actions">
										<button
											type="button"
											className="icon-btn small-icon"
											aria-label={ __(
												'Move the section left',
												'wc-checkoutsuite'
											) }
											onClick={ () =>
												apply(
													moveSection(
														document,
														editingSection.id,
														'up'
													)
												)
											}
										>
											<Icon name="back" />
										</button>
										<button
											type="button"
											className="icon-btn small-icon"
											aria-label={ __(
												'Move the section right',
												'wc-checkoutsuite'
											) }
											onClick={ () =>
												apply(
													moveSection(
														document,
														editingSection.id,
														'down'
													)
												)
											}
										>
											<Icon name="arrow" />
										</button>
									</div>

									<Button
										variant="secondary"
										onClick={ () => {
											const impact = sectionImpact(
												document,
												editingSection.id
											);
											const result = removeSection(
												document,
												editingSection.id
											);

											if ( result.ok ) {
												apply( result );
												setEditingSectionId( null );
											} else {
												setSectionRemoval( {
													id: editingSection.id,
													impact,
												} );
											}
										} }
									>
										{ __(
											'Remove section',
											'wc-checkoutsuite'
										) }
									</Button>
								</>
							) : null }
						</Dialog>
					),
				} }
			/>
		</>
	);
}
