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

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './components/Button';
import Dialog from './components/Dialog';
import Notice from './components/Notice';
import ContainerProperties from './components/ContainerProperties';
import { TextField, SelectField, CheckboxField } from './components/controls';
import FieldManagerView from './views/FieldManagerView';
import {
	collectsAt,
	containerWords,
	isCustomerDestination,
	navigation,
	offeredInSentence,
} from './design/destinations';
import { Icon } from './design/icons';
import { adoptCoreSection } from './schema/coreCheckout';
import useFieldsDocument from './schema/useFieldsDocument';
import useFieldsNavigation from './schema/useFieldsNavigation';
import useSectionEditor from './schema/useSectionEditor';
import useFieldEditor from './schema/useFieldEditor';
import { addBinding, withBindings } from './schema/bindings';
import {
	classifyFailure,
	missingExtension,
	unsupportedVersion,
} from './schema/failureState';
import {
	createProfile,
	isCheckoutSection,
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
	adoptAccountField,
	adoptCoreField,
	bulkImpact,
	conditionDependents,
	createField,
	createSection,
	isProtected,
	moveSection,
	protectionReason,
	resolveAmbiguousDestinations,
	removeSectionWithDependents,
	sectionImpact,
	sectionGroups,
	setFieldEnabled,
	setAccountFieldEnabled,
	updateField,
	updateSection,
} from './schema/fieldOperations';

/**
 * Every destination the editor can open, for the address bar.
 *
 * Read from the same vocabulary the strip is drawn from, so a link can only name a
 * destination this build has — and a destination added to the design is linkable
 * the moment it exists, without a second list to keep in step.
 *
 * @type {Array<string>}
 */
const AREA_IDS = navigation().flatMap( ( /** @type {any} */ entry ) =>
	( entry.members ?? [] ).map( ( /** @type {any} */ member ) => member.id )
);

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
				id="wccs-account-section-icon"
				label={ __( 'Ícone da aba', 'wc-checkoutsuite' ) }
				value={ account.icon ?? section.icon ?? 'fields' }
				options={ [ 'user', 'fields', 'file', 'mail' ].map(
					( value ) => ( {
						value,
						label: value,
					} )
				) }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) => update( { icon: event.target.value } ) }
			/>
			<TextField
				id="wccs-account-section-position"
				type="number"
				min="0"
				label={ __( 'Posição no menu', 'wc-checkoutsuite' ) }
				value={ account.position ?? 0 }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) =>
					update( {
						position: Math.max(
							0,
							Number( event.target.value ) || 0
						),
					} )
				}
			/>
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
 * @param {Object}                                                             props                Component properties.
 * @param {any}                                                                props.client         REST client.
 * @param {string}                                                             [props.view]         View the frame is showing: `fields`,
 *                                                                                                  `appearance`, `archive` or `rules`.
 * @param {string}                                                             [props.siteName]     Store name, for the preview's mock header.
 * @param {Object}                                                             [props.urls]         Storefront addresses the success message links to.
 * @param {Array<{id: string, label: string, url?: string, logout?: boolean}>} [props.accountMenu]  WooCommerce account menu.
 * @param {string}                                                             [props.checkoutMode] Which checkout the store runs (`blocks` or
 *                                                                                                  `classic`), read from the store by the server.
 * @param {'all'|'checkout'}                                                   [props.scope]        Optional destination scope for specialized entries.
 * @return {*} Rendered element tree.
 */
export default function FieldsScreen( {
	client,
	view = 'fields',
	siteName = '',
	urls = {},
	accountMenu = [],
	checkoutMode = '',
	scope = 'all',
} ) {
	/**
	 * The document and its local edit history.
	 *
	 * Undo here covers edits that have not been saved, and nothing else. The
	 * publication history is a different mechanism on the server: it answers "what
	 * did the store run, and when", and undoing it means publishing again as a new
	 * revision. ROADMAP.md section 428 keeps the two apart on purpose.
	 */
	const documentLifecycle = useFieldsDocument( { client } );
	const {
		document,
		catalog,
		coreFields,
		loading,
		failure,
		saving,
		saved,
		setSaved,
		localDraftRestored,
		report,
		revisions,
		restoring,
		publishError,
		restored,
		commitDocument,
		edits,
		discardLocalDraft,
		save,
		restore,
		problems,
		clearProblems,
		legacyDestinations,
		legacyPromptOpen,
		setLegacyDestinations,
		setLegacyPromptOpen,
	} = documentLifecycle;

	// The individual operations, not the object.
	//
	// The object this hook returns is memoised, but its identity still changes
	// whenever the document does — and `load` changes the document. Depending on
	// the object would therefore make `load` change after it ran, re-run the
	// mount effect, and loop. Depending on the stable operation does not.
	/**
	 * The classified state of the last failure.
	 *
	 * A string would collapse four states that ask for four different things —
	 * a conflict is not an error and an expired session is not a bug in the
	 * schema. See schema/failureState.js.
	 *
	 * @type {[string, Function]}
	 */
	const [ refusal, setRefusal ] = useState( '' );
	const navigationState = useFieldsNavigation( {
		areaIds: 'checkout' === scope ? [ 'checkout' ] : AREA_IDS,
		checkoutMode,
	} );
	const {
		mode,
		setMode,
		area,
		selectArea,
		section,
		setSection,
		activeProfile,
		setActiveProfile,
		goTo,
		onPreview,
		onOpenRules,
	} = navigationState;

	useEffect( () => {
		if ( 'checkout' !== scope ) {
			return;
		}

		// A specialized entry can reuse this component instance after the advanced editor
		// was on another destination or profile. Reset both identities before the checkout
		// composition is rendered so the entry never exposes the previous context.
		if ( 'checkout' !== area ) {
			selectArea( 'checkout' );
		}

		if ( '' !== activeProfile ) {
			setActiveProfile( '' );
		}
	}, [ scope, area, activeProfile, selectArea, setActiveProfile ] );

	/**
	 * The retired destination keys this document still carries.
	 *
	 * `customer_profile` was one destination and is now two, so a stored link cannot be
	 * migrated on the merchant's behalf: only they know which surface was meant.
	 */
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
	/**
	 * The destination the editor is open on.
	 *
	 * The address bar decides it, for the same reason it decides the section: «abre
	 * o pedido no admin» is a link a colleague can be sent, and a tab whose click
	 * leaves the address alone is a tab nobody can link to.
	 *
	 * @type {[string, Function]}
	 */
	/**
	 * The checkout the strip is showing: an empty string is the store's own
	 * composition, and anything else is a profile's id (§6.3).
	 */
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

	const sectionEditor = useSectionEditor( { composition } );
	const {
		sectionDraftOpen,
		editingSectionId,
		setEditingSectionId,
		editingSection,
		sectionRemoval,
		setSectionRemoval,
		newSectionTitle,
		setNewSectionTitle,
		newSectionLocation,
		setNewSectionLocation,
		openNewSection,
		closeNewSection,
	} = sectionEditor;

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
	 * The document as the server last confirmed it.
	 *
	 * Dirty is derived from this rather than set by hand, so undoing back to the
	 * saved state makes the screen clean again instead of claiming there is
	 * something to save.
	 *
	 * @type {boolean}
	 */
	const dirty = documentLifecycle.dirty;

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
			clearProblems();
			setSaved( '' );
			commitDocument( result.document, label );
		},
		[ clearProblems, commitDocument, setSaved ]
	);

	const fieldEditor = useFieldEditor( { document, apply } );
	const {
		selected,
		setSelected,
		toggleSelected,
		editing,
		setEditing,
		editingField,
		duplicate,
		toggleEnabled,
		move,
		reorder,
		remove,
	} = fieldEditor;

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
		[ apply, document, setLegacyDestinations, setLegacyPromptOpen ]
	);

	const compositionForGroups = useMemo( () => {
		if ( ! composition || ! composingProfile || 'checkout' !== area ) {
			return composition;
		}

		const ownedSectionIds = new Set(
			( composition.sections ?? [] )
				.filter( isCheckoutSection )
				.map( ( /** @type {any} */ entry ) => entry.id )
		);

		return {
			...composition,
			fields: ( composition.fields ?? [] ).filter(
				( /** @type {any} */ field ) =>
					ownedSectionIds.has( field.section ?? 'order' )
			),
		};
	}, [ composition, composingProfile, area ] );

	/**
	 * Sections in display order, including the ones fields imply.
	 *
	 * @type {import('./schema/types').SectionGroup[]}
	 */
	const groups = useMemo(
		() =>
			compositionForGroups
				? sectionGroups( compositionForGroups, area )
				: [],
		[ compositionForGroups, area ]
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

	const activeSection =
		sectionOptions.find( ( entry ) => entry.id === section )?.id ??
		sectionOptions[ 0 ]?.id ??
		'';

	/**
	 * Keeps the target section valid as the list of sections arrives.
	 *
	 * @return {void}
	 */
	useEffect( () => {
		if ( ! activeSection || activeSection === section ) {
			return;
		}

		setSection( activeSection );
	}, [ activeSection, section, setSection ] );

	/**
	 * Reloads the publication report and the history.
	 *
	 * Called after anything that changes either document, so the panel never shows
	 * a diff computed against a revision that no longer exists.
	 *
	 * @return {Promise<void>} Resolves when the refresh settles.
	 */

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

	/**
	 * Requests removal of the section currently open in the properties dialog.
	 *
	 * The action is deliberately shared by the dialog footer and the dependency
	 * confirmation: the merchant can always see the destructive action without
	 * scrolling through the section properties, while fields and links still get
	 * the same dependency protection before anything is removed.
	 *
	 * @param {string|null} id Section identifier.
	 * @return {void}
	 */
	const requestSectionRemovalById = ( /** @type {string|null} */ id ) => {
		if ( ! id ) {
			return;
		}

		const impact = sectionImpact( composition, id );
		// Even an empty section is destructive configuration. Always show the same
		// explicit confirmation so the merchant never deletes an account tab by
		// clicking through an action menu accidentally.
		setSectionRemoval( {
			id,
			impact,
		} );
	};

	const requestSectionRemoval = () =>
		requestSectionRemovalById( editingSection ? editingSection.id : null );

	return (
		<>
			<FieldManagerView
				model={ {
					scope,
					document,
					composition,
					groups,
					section: activeSection,
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
					accountMenu,
					onAreaChange: selectArea,
					onToggleSectionTitle: (
						/** @type {boolean} */ checked
					) => {
						const found = composition?.sections?.find(
							( /** @type {any} */ entry ) =>
								entry.id === activeSection
						);

						if ( ! found ) {
							// An implied section of WooCommerce has nowhere to store
							// the choice, and the control is disabled for it — so
							// this is a guard and not a silent no-op.
							return;
						}

						applyComposed(
							updateSection( composition, activeSection, {
								presentation: {
									...( found.presentation ?? {} ),
									show_title: checked,
								},
							} ),
							__( 'Exibir título da seção', 'wc-checkoutsuite' )
						);
					},
					loading,
					dirty,
					saving,
					saved,
					localDraftRestored,
					onDiscardLocalDraft: discardLocalDraft,
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
					onEdit: ( id ) => setEditing( id ),
					onDuplicate: duplicate,
					onToggleEnabled: toggleEnabled,
					onMove: move,
					onReorder: reorder,
					onRemove: remove,
					onProtect: explainProtection,
					onCreateField: ( /** @type {any} */ choice ) => {
						// A field can be created in any destination the merchant works in
						// (§9), and creating it here binds it here. Where its value is
						// *collected* is a property of the field, not of the container it was
						// created in: it is the checkout, unless the destination is one of the
						// two customer surfaces, where the value lives with the customer.
						const collectedHere = collectsAt( area );
						const customerSurface = isCustomerArea( area );
						let targetDocument = document;
						let targetSection = collectedHere
							? activeSection
							: 'order';

						// A native account page is a real WooCommerce context but has no WCCS
						// container until the merchant adds the first complementary field. Create
						// that container as part of the same draft operation so the new field has a
						// valid home and is rendered after the native form instead of disappearing.
						if (
							customerSurface &&
							choice.accountPage &&
							! document.sections.some(
								( /** @type {any} */ entry ) =>
									entry.presentation?.account?.page ===
									choice.accountPage
							)
						) {
							const created = createSection( document, {
								title:
									choice.accountPageLabel ??
									choice.accountPage,
								// `customer_account` is the destination area, not the logical
								// insertion target. Account containers are inserted at the
								// account location, just like containers created from the page
								// dialog. Keeping those concepts separate is required by the
								// server's ContainerDefinition contract.
								location: defaultSectionLocation( area ),
								areas: [ 'customer_account' ],
								presentation: {
									show_title: false,
									account: {
										page: choice.accountPage,
										mode: 'edit',
									},
								},
							} );

							if ( ! created.ok ) {
								apply( created );

								return;
							}

							targetDocument = created.document;
							targetSection = created.section?.id ?? 'order';
						}

						const createdField = createField( targetDocument, {
							...choice,
							label: choice.defaults?.label ?? choice.label,
							// A destination that only shows values collects nothing: the
							// field is created with its collection home in the checkout, so
							// it is not a definition nobody can ever fill in.
							section: targetSection,
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
									section: targetSection,
									...( customerSurface
										? { mode: 'edit' }
										: {} ),
									...( customerSurface &&
									choice.supports?.file
										? {
												actions: [
													'show_metadata',
													'view',
													'download',
													'resubmit',
												],
										  }
										: {} ),
								},
							},
						} );
						apply( createdField );

						if ( createdField.ok && choice.accountPage ) {
							setSection( targetSection );
						}
					},
					onAdoptCore: ( /** @type {any} */ core ) =>
						apply( adoptCoreField( document, core ) ),
					onAdoptAccount: ( /** @type {any} */ core ) => {
						const result = adoptAccountField( document, core );

						if ( result.ok && result.field ) {
							setEditing( result.field.id );
						}

						apply(
							result,
							__( 'Editar campo nativo', 'wc-checkoutsuite' )
						);
					},
					onToggleAccount: (
						/** @type {any} */ core,
						/** @type {boolean} */ enabled
					) =>
						apply(
							setAccountFieldEnabled( document, core, enabled ),
							enabled
								? __(
										'Restaurar campo nativo',
										'wc-checkoutsuite'
								  )
								: __(
										'Ocultar campo nativo',
										'wc-checkoutsuite'
								  )
						),
					// «Não mostrar»: o campo passa a ser gerido e já nasce desligado no
					// checkout. É a resposta para «não quero este campo» num campo do
					// WooCommerce, que não se apaga da loja — só se deixa de mostrar.
					onHideCore: ( /** @type {any} */ core ) => {
						const adopted = adoptCoreField( document, core );

						if ( ! adopted.ok ) {
							apply( adopted );

							return;
						}

						apply(
							setFieldEnabled( adopted.document, core.id, false )
						);
					},
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
					onReferenceChange: ( value ) => setMode( value ),
					onPreview,
					onOpenRules,
					onExport: exportConfig,
					isProtected,
					onOpenSection: ( /** @type {string|null} */ id ) =>
						id &&
						composition.sections.some(
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
						openNewSection( {
							location: defaultSectionLocation( area ),
						} );
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
									'Excluir esta aba removerá permanentemente o item do menu, sua rota e suas configurações. Os campos personalizados vinculados também serão removidos; campos nativos bloqueiam a exclusão. Esta ação não pode ser desfeita pelo botão Desfazer.',
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
											! activeSection
										}
										onClick={ () => {
											const field = document.fields.find(
												( /** @type {any} */ entry ) =>
													entry.id === linkFieldId
											);

											if ( ! field ) {
												return;
											}

											const bindings = addBinding(
												field,
												area,
												activeSection
											);

											apply(
												updateField(
													document,
													field.id,
													{
														...withBindings(
															field,
															bindings
														),
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
							onClose={ closeNewSection }
							footer={
								<>
									<Button
										variant="secondary"
										onClick={ closeNewSection }
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
														// Preserve the existing account defaults without asking
														// optional presentation choices during creation.
														show_title:
															isCustomerArea(
																area
															),
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
																		icon: 'user',
																		position: 5,
																		mode: 'edit',
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
											// Use the identifier returned by the operation, not a value
											// calculated from the previous composition. The section options
											// effect can run between these state updates; pointing at the
											// old document made it fall back to the first section and sent
											// the next field to the wrong container.
											setSection(
												result.section?.id ??
													activeSection
											);
											closeNewSection();
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
								<Notice status="info">
									{ __(
										'Esta seção criará uma nova aba autenticada em Minha conta. Ela não será exibida no checkout ou nos pedidos.',
										'wc-checkoutsuite'
									) }
								</Notice>
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
								<>
									<Button
										variant="danger"
										onClick={ requestSectionRemoval }
									>
										{ sprintf(
											/* translators: %s: destination-specific container name. */
											__(
												'Remover %s',
												'wc-checkoutsuite'
											),
											containerWords( area ).one
										) }
									</Button>
									<Button
										variant="primary"
										onClick={ () =>
											setEditingSectionId( null )
										}
									>
										{ __( 'Done', 'wc-checkoutsuite' ) }
									</Button>
								</>
							}
						>
							{ editingSection ? (
								<>
									<ContainerProperties
										value={ editingSection }
										words={ containerWords( area ) }
										onChange={ ( changes ) =>
											applyComposed(
												updateSection(
													composition,
													editingSection.id,
													changes
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
