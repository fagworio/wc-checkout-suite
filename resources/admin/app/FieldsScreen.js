/**
 * Fields screen.
 *
 * The first screen that actually manages the schema: it lists the fields the
 * document holds, offers the create, edit, duplicate and archive actions, and
 * keeps the WooCommerce-owned fields visibly distinct from the merchant's own.
 *
 * Two boundaries are deliberate at this stage:
 *
 * - **Saving is one action.** «Salvar alterações» writes the draft and publishes it, so
 *   the store runs what the merchant just configured without a second step
 *   (`roadmap/ESPECIFICACAO-SECOES-WCCS.md` §16). The draft slot stays the editing
 *   buffer under the interface, which is what keeps the compare-and-swap conflict check
 *   and the revision history working; the vocabulary of a two-step flow is not shown.
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
import {
	collectsAt,
	containerWords,
	isCustomerDestination,
	navigation,
	offeredInSentence,
} from './design/destinations';
import { Icon } from './design/icons';
import { adoptCoreSection } from './schema/coreCheckout';
import useDocumentHistory from './schema/useDocumentHistory';
import {
	classifyFailure,
	missingExtension,
	unsupportedVersion,
} from './schema/failureState';
import {
	createProfile,
	moveProfile,
	readableProfiles,
	removeProfile,
	setFallback,
	updateProfile,
	withComposition,
	compositionOf,
	withProfiles,
} from './schema/profiles';
import {
	adoptCoreField,
	ambiguousDestinations,
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
	resolveAmbiguousDestinations,
	removeSectionWithDependents,
	repairLegacyDraft,
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

/**
 * Whether the work area the merchant is in holds the customer's own values.
 *
 * The two customer surfaces are one store with two readers: the customer's page in My
 * Account and the panel staff read on the customer's profile. The registry in
 * `design/destinations.js` decides which destinations those are.
 *
 * @param {string} area Editor work area.
 * @return {boolean} Whether it is a customer surface.
 */
function isCustomerArea( area ) {
	return isCustomerDestination( area );
}

/** Local draft handoff used when the shell swaps the editor for another screen. */
const LOCAL_DRAFT_KEY = 'wccs-local-draft';

/**
 * Returns the logical default location for a section created in one work area.
 *
 * @param {string} area Editor work area.
 * @return {string} Logical section location.
 */
function defaultSectionLocation( area ) {
	if ( 'checkout' === area ) {
		return 'billing';
	}

	if ( isCustomerArea( area ) ) {
		return 'account';
	}

	return 'order';
}

/**
 * Settings that make a section a real, independent Minha conta page.
 *
 * @param {{section: import('./schema/types').SectionDefinition, document: import('./schema/types').SchemaDocument, apply: Function, surfaces?: Array<{value: string, label: string, description: string}>}} props Component properties.
 * @return {*} Account presentation controls.
 */
function AccountSectionPresentation( {
	section,
	document,
	apply,
	surfaces = [],
} ) {
	const presentation = section.presentation ?? {};
	const account = presentation.account ?? {};
	const page = account.page ?? '';
	const onNativePage = '' !== page;
	const surface = surfaces.find(
		( /** @type {any} */ entry ) => entry.value === page
	);
	const update = ( /** @type {Record<string, unknown>} */ changes ) =>
		apply(
			updateSection( document, section.id, {
				presentation: {
					...presentation,
					account: { ...account, ...changes },
				},
			} )
		);

	return (
		<>
			{ /* §7.4: a section lives on a page of its own or inside a page WooCommerce already
			     has. The list is the server's, so the editor cannot offer a page the store would
			     refuse — and the pages that are not there have their reason in the vocabulary. */ }
			<SelectField
				id="wccs-account-section-page"
				label={ __( 'Onde esta seção aparece', 'wc-checkoutsuite' ) }
				value={ page }
				options={ [
					{
						value: '',
						label: __(
							'Página própria (nova aba)',
							'wc-checkoutsuite'
						),
					},
					...surfaces.map( ( /** @type {any} */ entry ) => ( {
						value: entry.value,
						label: entry.label,
					} ) ),
				] }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) => update( { page: event.target.value } ) }
			/>

			{ onNativePage ? (
				<>
					<Notice status="info">
						{ surface?.description ??
							__(
								'A seção aparece dentro de uma página que a Minha conta já tem.',
								'wc-checkoutsuite'
							) }
					</Notice>
					<TextField
						id="wccs-account-section-menu-label"
						label={ __( 'Título da seção', 'wc-checkoutsuite' ) }
						value={ account.menu_label ?? section.title }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => update( { menu_label: event.target.value } ) }
					/>
				</>
			) : (
				<>
					<Notice status="info">
						{ __(
							'Esta é uma aba autenticada de Minha conta. Os valores pertencem ao cliente e não a um pedido.',
							'wc-checkoutsuite'
						) }
					</Notice>
					<TextField
						id="wccs-account-section-slug"
						label={ __( 'Endereço da aba', 'wc-checkoutsuite' ) }
						help={ __(
							'Utilize minúsculas, números e hífens. Exemplo: meus-documentos.',
							'wc-checkoutsuite'
						) }
						value={ account.slug ?? '' }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => update( { slug: event.target.value } ) }
					/>
					<TextField
						id="wccs-account-section-menu-label"
						label={ __( 'Nome no menu', 'wc-checkoutsuite' ) }
						value={ account.menu_label ?? section.title }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => update( { menu_label: event.target.value } ) }
					/>
				</>
			) }
			<SelectField
				id="wccs-account-section-mode"
				label={ __( 'Modo', 'wc-checkoutsuite' ) }
				value={ account.mode ?? 'edit' }
				options={ [
					{
						value: 'edit',
						label: __( 'Formulário editável', 'wc-checkoutsuite' ),
					},
					{
						value: 'view',
						label: __( 'Somente consulta', 'wc-checkoutsuite' ),
					},
				] }
				onChange={ (
					/** @type {{ target: { value: 'edit'|'view' } }} */ event
				) => update( { mode: event.target.value } ) }
			/>
			<CheckboxField
				id="wccs-account-section-show-title"
				label={ __( 'Exibir título no conteúdo', 'wc-checkoutsuite' ) }
				help={ __(
					'O nome no menu continua visível quando o título é ocultado.',
					'wc-checkoutsuite'
				) }
				checked={ false !== presentation.show_title }
				onChange={ (
					/** @type {{ target: { checked: boolean } }} */ event
				) =>
					apply(
						updateSection( document, section.id, {
							presentation: {
								...presentation,
								show_title: event.target.checked,
							},
						} )
					)
				}
			/>
		</>
	);
}

/**
 * Fields screen.
 *
 * @param {Object} props                Component properties.
 * @param {any}    props.client         REST client.
 * @param {string} [props.view]         View the frame is showing: `fields`,
 *                                      `appearance`, `archive` or `rules`.
 * @param {string} [props.siteName]     Store name, for the preview's mock header.
 * @param {Object} [props.urls]         Storefront addresses the success message links to.
 * @param {string} [props.checkoutMode] Which checkout the store runs (`blocks` or
 *                                      `classic`), read from the store by the server.
 * @return {*} Rendered element tree.
 */
export default function FieldsScreen( {
	client,
	view = 'fields',
	siteName = '',
	urls = {},
	checkoutMode = '',
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
	 * The retired destination keys this document still carries.
	 *
	 * `customer_profile` was one destination and is now two, so a stored link cannot be
	 * migrated on the merchant's behalf: only they know which surface was meant.
	 */
	const [ legacyDestinations, setLegacyDestinations ] = useState(
		/** @type {string[]} */ ( [] )
	);

	/** Whether the migration choice is on screen. */
	const [ legacyPromptOpen, setLegacyPromptOpen ] = useState( false );

	/**
	 * The checkout the screen is configuring for.
	 *
	 * The design's context bar switches between the classic checkout and the Blocks
	 * checkout, because the same document renders differently in each and the
	 * capability matrix that says so is per adapter. It is view state: the document
	 * is the same one either way.
	 *
	 * It starts on the checkout **the store actually runs**, which the server reads
	 * (§6.2): a screen that assumed the classic checkout would tell a merchant that a
	 * native field can be reordered here when their own checkout cannot honour it
	 * (§6.7). Switching it is a preview of the other one, and changes nothing stored.
	 *
	 * @type {[string, Function]}
	 */
	const [ mode, setMode ] = useState( checkoutMode || 'classic' );
	const [ area, setArea ] = useState( 'checkout' );
	/**
	 * The checkout the strip is showing: an empty string is the store's own
	 * composition, and anything else is a profile's id (§6.3).
	 */
	const [ activeProfile, setActiveProfile ] = useState( '' );
	/**
	 * The composition the section list is editing.
	 *
	 * §6.4: a checkout profile owns its own containers, and the strip above the list is what decides
	 * whose they are. With a profile selected the screen reads and writes `profiles[i].sections`;
	 * with none it reads and writes the document, which is the store's own checkout.
	 *
	 * @type {import('./schema/profiles').CheckoutProfile|null}
	 */
	const composingProfile = useMemo(
		() =>
			'checkout' === area && '' !== activeProfile
				? ( document?.profiles ?? [] ).find(
						( /** @type {any} */ entry ) =>
							entry.id === activeProfile
				  ) ?? null
				: null,
		[ document, area, activeProfile ]
	);

	/**
	 * The document as the section editor sees it.
	 *
	 * @type {import('./schema/types').SchemaDocument}
	 */
	const composition = useMemo(
		() => compositionOf( document, composingProfile ),
		[ document, composingProfile ]
	);

	/**
	 * The code of the last refusal the profile rules answered with.
	 *
	 * A code and not a sentence: the rule belongs to `schema/profiles` and the
	 * wording belongs to the screen, which is what keeps the two from drifting.
	 */
	const [ profileRefusal, setProfileRefusal ] = useState( '' );
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
			composition?.sections?.find(
				( /** @type {any} */ entry ) => entry.id === editingSectionId
			) ?? null,
		[ composition, editingSectionId ]
	);

	/** @type {[string, Function]} */
	const [ newSectionTitle, setNewSectionTitle ] = useState( '' );

	/** @type {[string, Function]} */
	const [ newSectionLocation, setNewSectionLocation ] = useState( 'billing' );
	/** @type {[string, Function]} */
	const [ newSectionIcon, setNewSectionIcon ] = useState( 'user' );
	const [ newSectionMode, setNewSectionMode ] = useState(
		/** @type {'edit'|'view'} */ ( 'edit' )
	);
	const [ newSectionShowTitle, setNewSectionShowTitle ] = useState( false );
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

			const candidate =
				local?.baseRevision === draft.revision && local.document
					? local.document
					: draft;
			const repaired = repairLegacyDraft( candidate );
			const applied = repaired.changed ? repaired.document : candidate;
			const legacy = ambiguousDestinations( applied );

			setLegacyDestinations( legacy );
			setLegacyPromptOpen( legacy.length > 0 );

			if ( repaired.changed ) {
				resetDocument( applied );
				setSaved(
					__(
						'Encontramos dados antigos incompletos nesta edição local e os corrigimos. Revise e salve para continuar.',
						'wc-checkoutsuite'
					)
				);
			} else if (
				local?.baseRevision === draft.revision &&
				local.document
			) {
				resetDocument( applied );
				setSaved(
					__(
						'Há uma edição local não salva preservada nesta sessão.',
						'wc-checkoutsuite'
					)
				);
			} else {
				window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
				resetDocument( applied );
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
	 * Applies a change to the profile list.
	 *
	 * The profiles travel inside the document (§3.4), so a change to them is a change to the same
	 * draft every other edit goes through: the same undo history, the same unsaved-work guard and
	 * the same single save.
	 *
	 * @param {Array<any>} next  Next profile list.
	 * @param {string}     label History label.
	 * @return {void}
	 */
	/**
	 * Applies an operation to the composition being edited (§6.4).
	 *
	 * Section edits run against the composed document and are written back to their owner, so the
	 * store's own checkout and each profile keep their own list while the screen shows one of them.
	 *
	 * @param {import('./schema/types').OperationResult} result  Operation result.
	 * @param {string}                                   [label] What the edit was.
	 * @return {void}
	 */
	const applyComposed = useCallback(
		(
			/** @type {import('./schema/types').OperationResult} */ result,
			label = ''
		) => {
			if ( ! result.ok ) {
				apply( result, label );

				return;
			}

			apply(
				{
					...result,
					document: withComposition(
						document,
						composingProfile ? composingProfile.id : '',
						result.document
					),
				},
				label
			);
		},
		[ apply, document, composingProfile ]
	);

	const applyProfiles = useCallback(
		( /** @type {Array<any>} */ next, /** @type {string} */ label ) => {
			if ( ! document ) {
				return;
			}

			apply(
				{
					ok: true,
					document: withProfiles( document, next ),
					reason: '',
				},
				label
			);
		},
		[ apply, document ]
	);

	/**
	 * Applies the merchant's answer to a retired destination key.
	 *
	 * @param {string} chosen Destination the merchant chose.
	 * @return {void}
	 */
	const chooseDestination = useCallback(
		( /** @type {string} */ chosen ) => {
			if ( ! document ) {
				return;
			}

			const resolved = resolveAmbiguousDestinations( document, chosen );

			setLegacyDestinations( [] );
			setLegacyPromptOpen( false );

			if ( ! resolved.changed ) {
				return;
			}

			apply(
				{ ok: true, document: resolved.document, reason: '' },
				__( 'Destino da configuração antiga', 'wc-checkoutsuite' )
			);
		},
		[ apply, document ]
	);

	/**
	 * Sections in display order, including the ones fields imply.
	 *
	 * @type {import('./schema/types').SectionGroup[]}
	 */
	const groups = useMemo(
		() => ( composition ? sectionGroups( composition, area ) : [] ),
		[ composition, area ]
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
	/**
	 * Every section the document declares, with the areas it is offered in.
	 *
	 * The links tab asks a different question from the section tabs. A destination's
	 * section select has to offer the sections offered *there*, and that may be a section
	 * the active work area does not group — the order screen's own section, while the
	 * merchant is looking at Checkout. Offering only the active area's groups left those
	 * selects with no options at all, so a link whose section was already stored showed
	 * as empty and could not be pointed anywhere.
	 *
	 * @type {Array<{id: string, label: string, areas: string[]}>}
	 */
	const declaredSections = useMemo(
		() =>
			( document?.sections ?? [] )
				.filter( ( /** @type {any} */ entry ) => entry && entry.id )
				.map( ( /** @type {any} */ entry ) => ( {
					id: entry.id,
					label: entry.title ?? entry.id,
					areas: entry.areas ?? [ 'checkout' ],
				} ) ),
		[ document ]
	);

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
	 * Saves the current document and puts it to work in one action.
	 *
	 * Two writes under one button. The draft is the editing buffer — the compare-and-swap
	 * against its revision is what stops two editors from overwriting each other, and the
	 * revision history is built from what publishing produces — so the save writes it and
	 * then publishes it. That is one action for the merchant and no second timeline.
	 *
	 * The two writes can come apart, and the interface says so instead of choosing:
	 * a save whose publication failed leaves the work stored but the store still running
	 * the previous revision, which is a state the merchant has to be able to see and
	 * retry. Reporting "saved" would be a lie about the storefront, and refusing the
	 * whole save would throw away work the server accepted.
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
		setPublishError( '' );

		try {
			const result = await client.saveDraft(
				document,
				document.revision
			);

			// The server is authoritative after the write: it owns the revision.
			const stored = result?.fields ? result : await client.getDraft();
			resetDocument( stored );
			setSavedDocument( stored );
			window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );

			// The second write does not depend on this screen still being on page. The two
			// writes are one action, and stopping between them because a screen was
			// replaced would leave the work stored, the store on the previous revision and
			// nobody told — the one outcome this flow cannot accept.
			try {
				await client.publish( stored.revision );
			} catch ( publication ) {
				setPublishError( classifyFailure( publication ).message );
				setSaved(
					__(
						'Alterações guardadas, mas a loja ainda corre a revisão anterior. Tente guardar de novo para publicar.',
						'wc-checkoutsuite'
					)
				);
				await refreshPublication();

				return;
			}

			setSaved(
				__( 'Alterações salvas com sucesso.', 'wc-checkoutsuite' )
			);

			// The report compares the draft with the published document, so a save
			// makes the one on screen stale.
			await refreshPublication();
		} catch ( caught ) {
			// A refused save says so, on the screen the merchant is looking at. Reporting
			// it only when this instance is still mounted is how a refusal becomes silence:
			// the request reached the server, was refused, and the person who pressed the
			// button is told nothing at all.
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
		my_account: __( 'Minha conta — aba personalizada', 'wc-checkoutsuite' ),
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
					composition,
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
					linkSections: declaredSections,
					area,
					areas: navigation(),
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
						// A field can be created in any destination the merchant works in
						// (§9), and creating it here binds it here. Where its value is
						// *collected* is a property of the field, not of the container it was
						// created in: it is the checkout, unless the destination is one of the
						// two customer surfaces, where the value lives with the customer.
						const collectedHere = collectsAt( area );
						const customerSurface = isCustomerArea( area );

						apply(
							createField( document, {
								...choice,
								label: choice.defaults?.label ?? choice.label,
								// A destination that only shows values collects nothing: the
								// field is created with its collection home in the checkout, so
								// it is not a definition nobody can ever fill in.
								section: collectedHere ? section : 'order',
								settings: choice.settings,
								layout: choice.defaults?.layout,
								collectionSurface: customerSurface
									? 'my_account'
									: 'checkout',
								...( customerSurface
									? {
											storage: {
												scope: 'customer',
												sensitivity: 'personal',
											},
									  }
									: {} ),
								destinations: {
									[ area ]: {
										enabled: true,
										section,
										...( customerSurface
											? { mode: 'edit' }
											: {} ),
									},
								},
							} )
						);
					},
					onAdoptCore: ( /** @type {any} */ core ) =>
						apply( adoptCoreField( document, core ) ),
					onAdoptCoreSection: ( /** @type {any} */ coreSection ) =>
						apply( adoptCoreSection( document, coreSection ) ),
					onChangeField: (
						/** @type {Partial<import('./schema/types').FieldDefinition>} */ changes
					) =>
						editingField &&
						apply(
							updateField( document, editingField.id, changes )
						),
					edits,
					onSave: save,
					publishError,
					urls,
					report,
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
										'Esta seção implícita do WooCommerce não pode ser editada. Crie uma seção personalizada para dar-lhe um nome.',
										'wc-checkoutsuite'
									)
							  ),
					onCreateSection: () => {
						setNewSectionLocation( defaultSectionLocation( area ) );
						setNewSectionIcon( 'user' );
						setNewSectionMode( 'edit' );
						setNewSectionShowTitle( isCustomerArea( area ) );
						setSectionDraftOpen( true );
					},
					onLinkExisting: () => {
						setLinkFieldId( '' );
						setLinkDialogOpen( true );
					},
					removalDialog: sectionRemoval ? (
						<Dialog
							open={ true }
							title={ sprintf(
								/* translators: %s: what this destination calls its container. */
								__(
									'Remover %s e dependências',
									'wc-checkoutsuite'
								),
								containerWords( area ).one
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
													composition,
													sectionRemoval.id
												);
											if ( result.ok ) {
												applyComposed( result );
												setEditingSectionId( null );
												setSectionRemoval( null );
											} else {
												setRefusal( result.reason );
											}
										} }
									>
										{ sprintf(
											/* translators: %s: what this destination calls its container. */
											__(
												'Remover %s e campos',
												'wc-checkoutsuite'
											),
											containerWords( area ).one
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
								{ isCustomerArea( area )
									? __(
											'Nesta área, somente campos armazenados no cliente podem ser vinculados. Eles ficam independentes de pedidos.',
											'wc-checkoutsuite'
									  )
									: __(
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
												field.enabled &&
												( ! isCustomerArea( area ) ||
													'customer' ===
														field.storage?.scope )
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
							title={ containerWords( area ).create }
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
											'' === newSectionTitle.trim()
										}
										onClick={ () => {
											const title =
												newSectionTitle.trim();
											const result = createSection(
												composition,
												{
													title,
													location:
														newSectionLocation,
													// The container belongs to the destination the merchant is
													// working in: creating one here offers it here (§18).
													areas: [ area ],
													presentation: {
														show_title:
															newSectionShowTitle,
														...( isCustomerArea(
															area
														)
															? {
																	account: {
																		slug: title
																			.toLocaleLowerCase(
																				'pt-BR'
																			)
																			.normalize(
																				'NFD'
																			)
																			.replace(
																				/[\u0300-\u036f]/g,
																				''
																			)
																			.replace(
																				/[^a-z0-9]+/g,
																				'-'
																			)
																			.replace(
																				/^-+|-+$/g,
																				''
																			),
																		menu_label:
																			title,
																		icon: newSectionIcon,
																		position: 5,
																		mode: newSectionMode,
																	},
															  }
															: {} ),
													},
												}
											);

											if ( ! result.ok ) {
												return;
											}

											applyComposed( result );
											setSection(
												uniqueSectionId(
													composition,
													title
												)
											);
											setNewSectionTitle( '' );
											setSectionDraftOpen( false );
										} }
									>
										{ containerWords( area ).submit }
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
								label={ __( 'Nome', 'wc-checkoutsuite' ) }
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
											'Local',
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
								</>
							) : null }
							{ /* Where the container lives is the destination the merchant is
							     working in: creating one here offers it here (§18). The
							     multi-area list is not a choice the form asks again. */ }
							<p className="form-help">
								{ offeredInSentence( [ area ] ) }
							</p>
							{ isCustomerArea( area ) ? (
								<>
									<Notice status="info">
										{ __(
											'Esta seção criará uma nova aba autenticada em Minha conta. Ela não será exibida no checkout ou nos pedidos.',
											'wc-checkoutsuite'
										) }
									</Notice>
									<SelectField
										id="wccs-new-section-icon"
										label={ __(
											'Ícone',
											'wc-checkoutsuite'
										) }
										value={ newSectionIcon }
										options={ [
											'user',
											'fields',
											'file',
											'mail',
										].map( ( value ) => ( {
											value,
											label: value,
										} ) ) }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											setNewSectionIcon(
												event.target.value
											)
										}
									/>
									<SelectField
										id="wccs-new-section-mode"
										label={ __(
											'Modo',
											'wc-checkoutsuite'
										) }
										value={ newSectionMode }
										options={ [
											{
												value: 'edit',
												label: __(
													'Formulário editável',
													'wc-checkoutsuite'
												),
											},
											{
												value: 'view',
												label: __(
													'Somente consulta',
													'wc-checkoutsuite'
												),
											},
										] }
										onChange={ (
											/** @type {{ target: { value: 'edit'|'view' } }} */ event
										) =>
											setNewSectionMode(
												event.target.value
											)
										}
									/>
									<CheckboxField
										id="wccs-new-section-show-title"
										label={ __(
											'Exibir título no conteúdo',
											'wc-checkoutsuite'
										) }
										help={ __(
											'O nome no menu continua visível mesmo quando este título é ocultado.',
											'wc-checkoutsuite'
										) }
										checked={ newSectionShowTitle }
										onChange={ (
											/** @type {{ target: { checked: boolean } }} */ event
										) =>
											setNewSectionShowTitle(
												event.target.checked
											)
										}
									/>
								</>
							) : null }
							{ 'checkout' !== area &&
							! isCustomerArea( area ) ? (
								<Notice status="info">
									{ __(
										'Esta seção será usada apenas para organizar a exibição nesta área. Para mostrar valores nela, vincule campos existentes.',
										'wc-checkoutsuite'
									) }
								</Notice>
							) : null }
						</Dialog>
					),
					migrationDialog: legacyPromptOpen ? (
						<Dialog
							open
							size="default"
							eyebrow={ __( 'MIGRAÇÃO', 'wc-checkoutsuite' ) }
							title={ __(
								'Escolha o destino desta configuração antiga',
								'wc-checkoutsuite'
							) }
							subtitle={ __(
								'A área «Perfil do cliente» foi separada em duas: a página do próprio cliente em Minha conta e o painel que a equipa vê no perfil dele. Nada é adivinhado — escolha onde estes campos devem aparecer.',
								'wc-checkoutsuite'
							) }
							// There is nothing to decide later: the server refuses a document
							// that still carries the retired key, so closing this without an
							// answer would leave a store that cannot save its own fields.
							onClose={ () => setLegacyPromptOpen( true ) }
							footer={
								<>
									<Button
										variant="secondary"
										onClick={ () =>
											chooseDestination(
												'customer_account'
											)
										}
									>
										{ __(
											'Minha conta (o cliente)',
											'wc-checkoutsuite'
										) }
									</Button>
									<Button
										variant="primary"
										onClick={ () =>
											chooseDestination(
												'admin_customer_profile'
											)
										}
									>
										{ __(
											'Perfil do cliente (a equipa)',
											'wc-checkoutsuite'
										) }
									</Button>
								</>
							}
						>
							<Notice status="warning">
								{ sprintf(
									/* translators: %s: comma separated destination keys. */
									__(
										'Esta configuração usa %s, que já não existe. Os campos, os valores guardados e as seções permanecem; o que muda é onde aparecem.',
										'wc-checkoutsuite'
									),
									legacyDestinations.join( ', ' )
								) }
							</Notice>
							<p>
								{ __(
									'Escolha «Minha conta» para os valores que o próprio cliente preenche e vê. Escolha «Perfil do cliente» para os valores que a equipa lê e edita no perfil do cliente. Pode vincular o mesmo campo aos dois depois de escolher.',
									'wc-checkoutsuite'
								) }
							</p>
						</Dialog>
					) : null,
					sectionEditor: (
						<Dialog
							open={ null !== editingSectionId }
							title={ containerWords( area ).one }
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
											'Nome',
											'wc-checkoutsuite'
										) }
										value={ editingSection.title }
										onChange={ (
											/** @type {{ target: { value: string } }} */ event
										) =>
											applyComposed(
												updateSection(
													composition,
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
											'Local',
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
											applyComposed(
												updateSection(
													composition,
													editingSection.id,
													{
														location:
															event.target.value,
													}
												)
											)
										}
									/>

									{ /* The destinations a stored container is offered in are
									     read here, not chosen: it was created in one of them,
									     and a document may legitimately offer the same group
									     of fields to more than one reader. */ }
									<p className="form-help">
										{ offeredInSentence(
											editingSection.areas ?? []
										) }
									</p>
									{ ( editingSection.areas ?? [] ).some(
										( /** @type {string} */ offered ) =>
											isCustomerArea( offered )
									) ? (
										<AccountSectionPresentation
											section={ editingSection }
											document={ composition }
											apply={ applyComposed }
											surfaces={
												catalog?.accountSurfaces ?? []
											}
										/>
									) : null }

									<div className="inline-actions">
										<button
											type="button"
											className="icon-btn small-icon"
											aria-label={ __(
												'Move the section left',
												'wc-checkoutsuite'
											) }
											onClick={ () =>
												applyComposed(
													moveSection(
														composition,
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
												applyComposed(
													moveSection(
														composition,
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
												composition,
												editingSection.id
											);
											const result = removeSection(
												composition,
												editingSection.id
											);

											if ( result.ok ) {
												applyComposed( result );
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
					profiles: readableProfiles( document ),
					activeProfile,
					profileRefusal,
					onProfileSelect: ( /** @type {string} */ id ) => {
						setProfileRefusal( '' );
						setActiveProfile( id );
					},
					onDismissProfileRefusal: () => setProfileRefusal( '' ),
					onCreateProfile: ( /** @type {any} */ choice ) => {
						const stored = readableProfiles( document );
						const created = createProfile(
							document,
							choice.name,
							choice.source,
							stored.find(
								( /** @type {any} */ entry ) =>
									entry.id === choice.from
							) ?? null
						);

						applyProfiles(
							[ ...stored, created ],
							__( 'Criar checkout', 'wc-checkoutsuite' )
						);
						setActiveProfile( created.id );
					},
					onUpdateProfile: (
						/** @type {string} */ id,
						/** @type {any} */ changes
					) => {
						const stored = readableProfiles( document );

						// The fallback is one place, so choosing it is choosing it for one
						// checkout and taking it from the others — which is what the store
						// accepts, and the alternative is writing something it refuses.
						const next = changes.fallback
							? updateProfile(
									setFallback( stored, id ),
									id,
									changes
							  )
							: updateProfile( stored, id, changes );

						applyProfiles(
							next,
							__( 'Alterar checkout', 'wc-checkoutsuite' )
						);
					},
					onRemoveProfile: ( /** @type {string} */ id ) => {
						const removed = removeProfile(
							readableProfiles( document ),
							id
						);

						if ( removed.refusal ) {
							setProfileRefusal( removed.refusal );

							return;
						}

						setProfileRefusal( '' );
						applyProfiles(
							removed.profiles,
							__( 'Excluir checkout', 'wc-checkoutsuite' )
						);

						if ( activeProfile === id ) {
							setActiveProfile( '' );
						}
					},
					onMoveProfile: (
						/** @type {string} */ id,
						/** @type {number} */ delta
					) =>
						applyProfiles(
							moveProfile(
								readableProfiles( document ),
								id,
								delta
							),
							__( 'Reordenar checkouts', 'wc-checkoutsuite' )
						),
				} }
			/>
		</>
	);
}
