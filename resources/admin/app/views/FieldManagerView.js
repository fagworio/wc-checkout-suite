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

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Dialog from '../components/Dialog';
import CheckoutProfilesPanel from '../components/CheckoutProfilesPanel';
import CoreCheckoutPanel from '../components/CoreCheckoutPanel';
import FieldPicker from '../components/FieldPicker';
import ArchiveView from './ArchiveView';
import FieldProperties from './FieldProperties';
import PreviewView from './PreviewView';
import RulesView from './RulesView';
import Notice from '../components/Notice';
import RevisionsList from '../components/RevisionsList';
import Button from '../components/Button';
import AccountMenuList from '../components/AccountMenuList';
import AccountNativeFields from '../components/AccountNativeFields';
import SurfaceTabs from '../components/SurfaceTabs';
import ContextTabs from '../components/ContextTabs';
import ContainerList from '../components/ContainerList';
import PreviewPanel from '../components/PreviewPanel';
import { Toast } from '../design/Toast';
import { TopbarActions } from '../design/TopbarActions';
import { Icon } from '../design/icons';
import { sectionCopy } from '../design/sectionMeta';
import {
	activeEntry,
	collectsAt,
	containerWords,
	navigation,
} from '../design/destinations';
import { useNarrowViewport } from '../design/useNarrowViewport';
import { typeGlyph } from '../design/typeGlyph';

/** @typedef {import('./FieldManagerModel').FieldManagerModel} FieldManagerModel */

/**
 * The design's eyebrow and heading for the editor.
 *
 * @type {string}
 */
const EYEBROW = 'CAMPOS DO CHECKOUT';

/** @type {Record<string, string>} WooCommerce account endpoint icons. */
const ACCOUNT_MENU_ICONS = {
	dashboard: 'home',
	orders: 'file',
	downloads: 'download',
	'edit-address': 'location',
	'payment-methods': 'card',
	'edit-account': 'user',
};

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
 * Context tabs for each destination, matching the references without leaking Checkout-only
 * adapter terminology into account, order, profile or e-mail editors.
 *
 * @param {string}     area        Destination.
 * @param {Array<any>} accountMenu WooCommerce account pages.
 * @return {Array<{id: string, label: string, icon?: string, children?: Array<any>}>} Contexts.
 */
function contextOptions( area, accountMenu = [] ) {
	if ( 'checkout' === area ) {
		return [
			{
				id: 'classic',
				label: __( 'Classic Checkout', 'wc-checkoutsuite' ),
			},
			{
				id: 'blocks',
				label: __( 'Checkout Blocks', 'wc-checkoutsuite' ),
			},
		];
	}

	if ( 'customer_account' === area ) {
		const pages = accountMenu
			.filter( ( item ) => ! item.logout )
			.map( ( item ) => ( {
				...item,
				icon: item.icon ?? ACCOUNT_MENU_ICONS[ item.id ] ?? 'fields',
			} ) );

		if ( pages.length > 0 ) {
			return pages;
		}

		return [
			{
				id: 'dashboard',
				label: __( 'Painel', 'wc-checkoutsuite' ),
				icon: 'home',
			},
			{
				id: 'edit-account',
				label: __( 'Detalhes da conta', 'wc-checkoutsuite' ),
				icon: 'user',
			},
		];
	}

	if ( 'customer_order' === area ) {
		return [
			{ id: 'view-order', label: __( 'Ver pedido', 'wc-checkoutsuite' ) },
		];
	}

	if ( 'admin_order' === area ) {
		return [
			{
				id: 'admin-order',
				label: __( 'Editar pedido', 'wc-checkoutsuite' ),
			},
		];
	}

	if ( 'admin_customer_profile' === area ) {
		return [
			{
				id: 'profile',
				label: __( 'Perfil do cliente', 'wc-checkoutsuite' ),
			},
		];
	}

	if ( 'customer_email' === area || 'admin_email' === area ) {
		return [
			{
				id: 'customer_email',
				label: __( 'Cliente', 'wc-checkoutsuite' ),
			},
			{ id: 'admin_email', label: __( 'Loja', 'wc-checkoutsuite' ) },
		];
	}

	return [];
}

/**
 * Returns the WooCommerce account menu item a customer section belongs to.
 *
 * @param {any} section Account container.
 * @return {string} Menu endpoint or custom account slug.
 */
function accountPageFor( section ) {
	const account = section?.presentation?.account ?? {};

	return account.page || account.slug || '';
}

/**
 * The field manager's editor view.
 *
 * @param {{model: FieldManagerModel}} props Component properties and view-model contract.
 * @return {*} Rendered element tree.
 */
export default function FieldManagerView( { model } ) {
	const {
		document: doc,
		composition,
		groups,
		section,
		onSectionChange,
		catalog,
		coreFields,
		sectionOptions,
		sections,
		linkSections,
		area,
		areas,
		accountMenu: serverAccountMenu = [],
		onAreaChange,
		loading,
		dirty,
		saving,
		saved,
		localDraftRestored,
		onDiscardLocalDraft,
		failure,
		refusal,
		problems,
		missingExtensions,
		unsupported,
		selected,
		onToggleSelected,
		onBulk,
		bulkImpactFor,
		onClearSelection,
		editing,
		onEdit,
		onDuplicate,
		onToggleEnabled,
		onMove,
		onReorder,
		onRemove,
		onProtect,
		onCreateField,
		onAdoptCore,
		onAdoptAccount,
		onToggleAccount,
		onHideCore,
		edits,
		onSave,
		report,
		revisions,
		restoring,
		restored,
		onRestore,
		reference,
		onExport,
		onPreview,
		onOpenSection,
		onCreateSection,
		onLinkExisting,
		isProtected,
		sectionEditor,
		sectionDraft,
		linkDialog,
		removalDialog,
		migrationDialog,
		profiles,
		activeProfile,
		onProfileSelect,
		onCreateProfile,
		onUpdateProfile,
		onRemoveProfile,
		onMoveProfile,
		profileRefusal,
		onDismissProfileRefusal,
	} = model;

	/** What this destination calls the group of fields the merchant works on. */
	const words = containerWords( area );

	/**
	 * Native account pages come from WooCommerce. Draft-only WCCS pages are added
	 * locally until the merchant publishes them and WooCommerce can return them.
	 */
	const accountPages = useMemo( () => {
		const supportedNativePages = new Set(
			( catalog?.accountSurfaces ?? [] ).map(
				( /** @type {any} */ surface ) => surface.value
			)
		);
		const nativePages = serverAccountMenu
			.filter(
				( /** @type {any} */ item ) =>
					! item.logout &&
					( Boolean( item.custom ) ||
						supportedNativePages.has( item.id ) )
			)
			.map( ( /** @type {any} */ item ) => ( {
				...item,
				custom: Boolean( item.custom ),
			} ) );
		const known = new Set(
			nativePages.map( ( /** @type {any} */ item ) => item.id )
		);
		const draftPages = ( doc?.sections ?? [] )
			.filter( ( /** @type {any} */ entry ) => {
				const destinations = Array.isArray( entry.areas )
					? entry.areas
					: [ entry.destination ?? 'checkout' ];
				const account = entry.presentation?.account ?? {};

				return (
					destinations.includes( 'customer_account' ) &&
					'' === ( account.page ?? '' ) &&
					'' !== ( account.slug ?? '' ) &&
					! known.has( account.slug )
				);
			} )
			.map( ( /** @type {any} */ entry ) => {
				const account = entry.presentation?.account ?? {};

				return {
					id: account.slug,
					label: account.menu_label ?? entry.title ?? entry.id,
					custom: true,
					enabled: entry.enabled !== false,
					icon: account.icon ?? 'fields',
				};
			} );

		return [ ...nativePages, ...draftPages ];
	}, [ serverAccountMenu, catalog, doc ] );

	/** The navigation entry this destination belongs to, and its siblings. */
	const areaGroup =
		navigation().find(
			( /** @type {any} */ candidate ) =>
				candidate.id === activeEntry( area )
		) ?? null;

	/** The design's own view state: the search box and the origin filter. */
	const [ search, setSearch ] = useState( '' );
	const [ origin, setOrigin ] = useState( 'all' );
	const [ surfaceContext, setSurfaceContext ] = useState( '' );
	const accountContextSectionRef = useRef( '' );
	const [ menuOpen, setMenuOpen ] = useState(
		/** @type {string|null} */ ( null )
	);
	const [ pickerOpen, setPickerOpen ] = useState( false );

	/** Whether the window is narrow enough for the properties to open in a dialog. */
	const narrow = useNarrowViewport();
	/**
	 * The bulk action waiting for the merchant to confirm it, or null.
	 *
	 * The design's bar acts on click. A bulk change touches every selected field at
	 * once and cannot be undone from the bar, so the action passes through the
	 * design's own dialog first, which states what it will do and what it will leave
	 * alone — the impact WCCS-020 asks the confirmation to state.
	 */
	const [ acknowledged, setAcknowledged ] = useState( false );
	const [ pendingBulk, setPendingBulk ] = useState(
		/** @type {'enable'|'disable'|'archive'|null} */ ( null )
	);
	const [ historyOpen, setHistoryOpen ] = useState( false );

	/**
	 * The design's toast: the message on screen and a counter that restarts its
	 * timer. Announcing is the only way to change it, so the timer cannot be
	 * outrun by two messages that happen to be identical.
	 */
	const [ announcement, setAnnouncement ] = useState( {
		token: 0,
		message: '',
	} );

	/**
	 * Puts a message in the toast and the live region.
	 *
	 * @param {string} message Message to announce.
	 * @return {void}
	 */
	const announce = ( message ) =>
		setAnnouncement( ( current ) => ( {
			token: current.token + 1,
			message,
		} ) );

	// The prototype closes the open row menu on Escape. Focus goes back to the button
	// that opened it, because a control that closes by keyboard and leaves the focus
	// nowhere is a keyboard trap in reverse: the next Tab starts from the top of the
	// screen.
	useEffect( () => {
		if ( ! menuOpen ) {
			return undefined;
		}

		const onKey = ( /** @type {KeyboardEvent} */ event ) => {
			if ( 'Escape' !== event.key ) {
				return;
			}

			const opened = menuOpen;

			setMenuOpen( null );
			globalThis.requestAnimationFrame?.( () =>
				globalThis.document
					?.getElementById( `wccs-menu-${ opened }` )
					?.focus()
			);
		};

		globalThis.document?.addEventListener( 'keydown', onKey );

		return () =>
			globalThis.document?.removeEventListener( 'keydown', onKey );
	}, [ menuOpen ] );

	/**
	 * The row a drag is carrying and the row it is over.
	 *
	 * Held here rather than in the row so that the whole list agrees on which row
	 * is being dragged: the styles that mark the dragged row and the drop target
	 * are on two different rows.
	 */
	const [ drag, setDrag ] = useState( {
		/** @type {string|null} */
		id: null,
		/** @type {string|null} */
		over: null,
	} );

	/**
	 * Announces a move in the design's words: which field, and where it landed.
	 *
	 * @param {string} label    Field label.
	 * @param {number} position One-based position after the move.
	 * @return {void}
	 */
	const announceMove = ( label, position ) =>
		announce(
			sprintf(
				/* translators: 1: field label, 2: position number. */
				__( '%1$s movido para a posição %2$d.', 'wc-checkoutsuite' ),
				label,
				position
			)
		);

	const activeArea = areas.find(
		( /** @type {any} */ entry ) => entry.id === area
	);
	const contexts = contextOptions( area, accountPages );
	const storedContext = contexts.some(
		( item ) => item.id === surfaceContext
	)
		? surfaceContext
		: '';
	const contextActive =
		'checkout' === area
			? reference
			: storedContext ||
			  ( contexts.some( ( item ) => item.id === area )
					? area
					: contexts[ 0 ]?.id );
	const accountContextSection =
		'customer_account' === area
			? groups.find(
					( /** @type {any} */ group ) =>
						accountPageFor( group.section ) === contextActive
			  ) ?? null
			: null;
	// A restored local draft can keep the old checkout section id while the account
	// menu already points at a custom page. Prefer the section belonging to that page
	// so the builder, its actions and its fields never describe different targets.
	// Minha conta has a second address inside the same destination: the WooCommerce
	// page currently selected in the account menu. A section left over from the
	// previous context must never win here, or the page list and the editor describe
	// different targets and "Ações da página" acts on the wrong section.
	const accountContextOwnsSelection =
		'customer_account' === area &&
		contexts.some(
			( /** @type {any} */ item ) => item.id === contextActive
		);
	const activeAccountPage = contexts.find(
		( /** @type {any} */ item ) => item.id === contextActive
	);
	const persistedCurrent = accountContextOwnsSelection
		? accountContextSection
		: groups.find(
				( /** @type {any} */ group ) => group.section.id === section
		  ) ?? accountContextSection;
	// Native account pages do not have a WCCS section until the merchant adds the
	// first complementary field. Keep a virtual section while they are empty so
	// the editor remains useful and points at the selected WooCommerce page.
	const virtualAccountSection =
		accountContextOwnsSelection && ! persistedCurrent && activeAccountPage
			? {
					declared: false,
					fields: [],
					section: {
						id: contextActive,
						title: activeAccountPage.label,
						description: '',
						areas: [ 'customer_account' ],
						location: 'account',
						presentation: {
							account: { page: contextActive },
						},
					},
			  }
			: null;
	const current = persistedCurrent ?? virtualAccountSection;
	const copy = sectionCopy(
		current?.section ?? { id: section, title: '', description: '' },
		Boolean( current?.declared )
	);
	const accountContextEmpty =
		'customer_account' === area &&
		accountContextOwnsSelection &&
		! accountContextSection;
	const accountContextSectionId = accountContextSection?.section.id;
	const selectAccountPage = ( /** @type {string} */ id ) => {
		onEdit( null );
		setSurfaceContext( id );
		const firstSection = groups.find(
			( /** @type {any} */ group ) =>
				accountPageFor( group.section ) === id
		);

		if ( firstSection ) {
			onSectionChange( firstSection.section.id );
		}
	};

	useEffect( () => {
		if (
			'customer_account' !== area ||
			! accountContextSectionId ||
			accountContextSectionId === section
		) {
			return;
		}

		// Keep the parent section state aligned when the account context came from a
		// preserved session rather than from the current click.
		onSectionChange( accountContextSectionId );
	}, [ area, accountContextSectionId, section, onSectionChange ] );

	useEffect( () => {
		if ( 'customer_account' !== area || ! current ) {
			accountContextSectionRef.current = '';
			return;
		}

		// Keep the section's account page in sync when the section itself changes.
		// `contexts` is intentionally rebuilt from WooCommerce's menu, so using its
		// identity as a trigger would undo a deliberate click on a native page with
		// no WCCS section (Painel, Pedidos, Downloads, etc.) on every render.
		const marker = `${ area }:${ current.section.id }`;
		if ( accountContextSectionRef.current === marker ) {
			return;
		}

		accountContextSectionRef.current = marker;
		const page = accountPageFor( current.section );
		if ( page && contexts.some( ( item ) => item.id === page ) ) {
			setSurfaceContext( page );
		}
	}, [ current, area, contexts ] );
	const sectionAreaIds = current?.section.areas ?? [ 'checkout' ];
	const sectionAreas = sectionAreaIds
		.map( ( /** @type {string} */ id ) =>
			areas.find( ( /** @type {any} */ entry ) => entry.id === id )
		)
		.filter( Boolean );

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

	/**
	 * A field's label, for the places that name the selected fields.
	 *
	 * @param {string} id Field identifier.
	 * @return {string} Label.
	 */
	const labelOf = ( id ) =>
		( doc?.fields ?? [] ).find(
			( /** @type {any} */ field ) => field.id === id
		)?.label ?? id;

	/** What the pending bulk action would change, and what it would not. */
	const bulkReport = pendingBulk ? bulkImpactFor( pendingBulk ) : null;

	const editingField =
		( doc?.fields ?? [] ).find(
			( /** @type {any} */ field ) => field.id === editing
		) ?? null;

	/**
	 * Applies a bulk action and announces what it changed.
	 *
	 * The screen applies a bulk action all or nothing: when one selected field
	 * cannot take the change, nothing is applied and the reason goes to the
	 * notice the screen already shows. So an announcement only exists for the
	 * case where the document really changed.
	 *
	 * @param {'enable'|'disable'|'archive'} action Action to apply.
	 * @return {void}
	 */
	const runBulk = ( action ) => {
		const result = onBulk( action );

		if ( ! result?.ok ) {
			return;
		}

		const count = result.count;

		if ( 'enable' === action ) {
			announce(
				sprintf(
					/* translators: %d: number of fields. */
					__(
						'%d campo(s) habilitado(s) no rascunho.',
						'wc-checkoutsuite'
					),
					count
				)
			);

			return;
		}

		if ( 'disable' === action ) {
			announce(
				sprintf(
					/* translators: %d: number of fields. */
					__(
						'%d campo(s) desativado(s) no rascunho.',
						'wc-checkoutsuite'
					),
					count
				)
			);

			return;
		}

		announce(
			sprintf(
				/* translators: %d: number of fields. */
				__(
					'%d campo(s) arquivado(s) no rascunho.',
					'wc-checkoutsuite'
				),
				count
			)
		);
	};

	/**
	 * Runs the export and announces it, the way the design does.
	 *
	 * @return {Promise<void>}
	 */
	const runExport = async () => {
		if ( await onExport() ) {
			announce(
				__(
					'Exportada apenas a configuração dos campos. Nenhum dado preenchido na prévia.',
					'wc-checkoutsuite'
				)
			);
		}
	};

	/**
	 * Announces an undo or a redo, in the design's words.
	 *
	 * The design names what was undone — "Desfeito: Reordenar campo." — and falls back
	 * to the bare verb when the edit has no name of its own.
	 *
	 * @param {string}  label   What the step was.
	 * @param {boolean} forward Whether it was a redo.
	 * @return {void}
	 */
	const announceStep = ( label, forward = false ) => {
		if ( ! label && ! forward ) {
			return;
		}

		if ( '' === label ) {
			announce(
				forward
					? __( 'Refeito.', 'wc-checkoutsuite' )
					: __( 'Desfeito.', 'wc-checkoutsuite' )
			);

			return;
		}

		if ( forward ) {
			announce(
				sprintf(
					/* translators: %s: what the edit was. */
					__( 'Refeito: %s.', 'wc-checkoutsuite' ),
					label
				)
			);

			return;
		}

		announce(
			sprintf(
				/* translators: %s: what the edit was. */
				__( 'Desfeito: %s.', 'wc-checkoutsuite' ),
				label
			)
		);
	};

	/**
	 * Moves a row one place and says so, the way the design announces it.
	 *
	 * @param {any}         field     Field being moved.
	 * @param {number}      index     Its index among the rows on screen.
	 * @param {'up'|'down'} direction Direction of the move.
	 * @return {void}
	 */
	const moveRow = ( field, index, direction ) => {
		onMove( field.id, direction );
		announceMove( field.label, 'up' === direction ? index : index + 2 );

		// The row the merchant was holding is the row they are still holding: after the
		// move, focus goes back to that field's handle. Without it the keyboard sends
		// the next keypress to whatever the browser picked, which is how a second
		// Alt+Arrow goes somewhere else entirely.
		globalThis.requestAnimationFrame?.( () => {
			globalThis.document
				?.getElementById( `wccs-drag-${ field.id }` )
				?.focus();
		} );
	};

	/**
	 * Starts carrying a row, unless the design disables its handle.
	 *
	 * @param {any}                                    field Field being dragged.
	 * @param {import('react').DragEvent<HTMLElement>} event Drag event.
	 * @return {void}
	 */
	const startDrag = ( field, event ) => {
		setDrag( { id: field.id, over: null } );

		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setData( 'text/plain', field.id );
		}
	};

	/**
	 * Drops a row onto another one.
	 *
	 * The refusal under an active filter is the design's: with a search or an
	 * origin filter on, the rows on screen are not the order the document has, so
	 * the position a drop lands on would not be the position the merchant sees.
	 *
	 * @param {any} field  Field being dropped.
	 * @param {any} target Row it is dropped on.
	 * @return {void}
	 */
	const dropRow = ( field, target ) => {
		setDrag( { id: null, over: null } );

		if ( field.id === target.id ) {
			return;
		}

		if ( filtered ) {
			announce(
				__(
					'Limpe a busca e os filtros antes de reordenar.',
					'wc-checkoutsuite'
				)
			);

			return;
		}

		const to = rows.findIndex(
			( /** @type {any} */ row ) => row.id === target.id
		);

		onReorder( field.id, target.id );
		announceMove( field.label, to + 1 );
	};

	// The topbar carries the one action that changes the storefront and the one that shows
	// it. Saving writes and publishes in the same press (`roadmap/ESPECIFICACAO-SECOES-WCCS.md`
	// §16): there is no draft to review afterwards, so a second button for it would be a
	// step the merchant has to take for the first one to mean anything.
	/** @type {string} */
	let saveLabel = __( 'Salvar alterações', 'wc-checkoutsuite' );
	if ( saving ) {
		saveLabel = __( 'Salvando…', 'wc-checkoutsuite' );
	}
	const topbarActions = (
		<TopbarActions>
			<button type="button" className="btn" onClick={ onPreview }>
				<span>{ __( 'Visualizar checkout', 'wc-checkoutsuite' ) }</span>
				<Icon name="eye" />
			</button>
			<button
				type="button"
				className="btn btn-primary"
				disabled={ ! dirty || saving }
				onClick={ onSave }
			>
				<Icon name="save" />
				<span>{ saveLabel }</span>
			</button>
		</TopbarActions>
	);

	/**
	 * Whether the checkout shows this section's title.
	 *
	 * Absent means shown: a section written before the switch existed is a section
	 * whose title WooCommerce already drew, and the switch is there to let the
	 * merchant hide it — never to hide it by default.
	 *
	 * @type {boolean}
	 */
	const sectionShowsTitle =
		false !==
		( current?.section?.presentation &&
		'show_title' in current.section.presentation
			? current.section.presentation.show_title
			: undefined );

	// The field properties are one element with two homes, as the design draws them: the
	// editor's right column on a wide window, and a dialog over the list below 870px,
	// where the stylesheet hides that column.
	const properties = editingField ? (
		<FieldProperties
			field={ editingField }
			catalog={ catalog }
			sections={ sections }
			linkSections={ linkSections }
			fields={ ( doc?.fields ?? [] ).map(
				( /** @type {any} */ entry ) => ( {
					id: entry.id,
					label: entry.label,
				} )
			) }
			onChange={ model.onChangeField }
			onDuplicate={ () => onDuplicate( editingField.id ) }
			onArchive={ () => onToggleEnabled( editingField.id ) }
			onProtect={ () => onProtect( editingField.id ) }
			reference={ model.reference }
		/>
	) : null;

	// Four of the frame's destinations are views of this one document — the editor, the
	// preview, the archive and the rules — so they are rendered here, where the document
	// lives, and switching between them does not reload anything. The branch sits after
	// every hook on purpose: a component that returns before its hooks changes the order
	// they run in, which React refuses.
	if ( 'archive' === model.view ) {
		return (
			<>
				{ topbarActions }
				<ArchiveView
					fields={ doc?.fields ?? [] }
					catalog={ catalog }
					onRestore={ model.onRestoreField }
					onBack={ model.onBackToEditor }
				/>
			</>
		);
	}

	if ( 'rules' === model.view ) {
		return (
			<>
				{ topbarActions }
				<RulesView onBack={ model.onBackToEditor } />
			</>
		);
	}

	if ( 'appearance' === model.view ) {
		return (
			<>
				{ topbarActions }
				<PreviewView
					document={ doc }
					siteName={ model.siteName }
					onBack={ model.onBackToEditor }
				/>
			</>
		);
	}

	return (
		<>
			{ topbarActions }

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
							{ activeArea?.label ??
								__( 'Campos do checkout', 'wc-checkoutsuite' ) }
							<span className="heading-dot">.</span>
						</h1>
						<p>
							{ activeArea?.description ??
								__(
									'Adicione e organize os campos exibidos no checkout da sua loja.',
									'wc-checkoutsuite'
								) }
						</p>
					</div>
					{ /* Both ways are offered everywhere (§9): a field can be created here
					     and bound to this container, or an existing one reused. Which of the
					     two is the main action is what the destination is for — where a
					     customer fills a value in, creating is the ordinary path; where the
					     value is only shown, reusing what was collected is (§6). */ }
					{ collectsAt( area ) ? (
						<>
							<button
								type="button"
								className="btn btn-primary btn-add"
								disabled={
									! collectsAt( area ) ||
									( accountContextEmpty &&
										'edit-address' === contextActive )
								}
								onClick={ () => setPickerOpen( true ) }
							>
								<Icon name="plus" />
								{ __( 'Adicionar campo', 'wc-checkoutsuite' ) }
							</button>
							<button
								type="button"
								className="btn"
								disabled={ ! section }
								onClick={ onLinkExisting }
							>
								{ __(
									'Vincular campo existente',
									'wc-checkoutsuite'
								) }
							</button>
						</>
					) : (
						<>
							<button
								type="button"
								className="btn btn-primary btn-add"
								disabled={ ! section }
								onClick={ onLinkExisting }
							>
								<Icon name="plus" />
								{ __(
									'Vincular campo existente',
									'wc-checkoutsuite'
								) }
							</button>
							<button
								type="button"
								className="btn"
								disabled={
									collectsAt( area ) &&
									( ! accountContextEmpty ||
										'edit-account' === contextActive )
								}
								onClick={ () => setPickerOpen( true ) }
							>
								{ __( 'Adicionar campo', 'wc-checkoutsuite' ) }
							</button>
						</>
					) }
				</div>

				<SurfaceTabs active={ area } onChange={ onAreaChange } />

				{ /* A group opens its own destinations under it, rather than putting every
				     destination in one row of checkboxes (§3). */ }
				{ areaGroup && areaGroup.members.length > 1 ? (
					<div
						className="wccs-editor-subareas"
						role="tablist"
						aria-label={ sprintf(
							/* translators: %s: group name, such as Admin. */
							__( 'Dentro de %s', 'wc-checkoutsuite' ),
							areaGroup.label
						) }
					>
						{ areaGroup.members.map(
							( /** @type {any} */ member ) => (
								<button
									key={ member.id }
									type="button"
									role="tab"
									aria-selected={ member.id === area }
									className={
										member.id === area ? 'active' : ''
									}
									onClick={ () => onAreaChange( member.id ) }
								>
									<strong>{ member.label }</strong>
									<small>{ member.description }</small>
								</button>
							)
						) }
					</div>
				) : null }

				{ /* A store may run more than one checkout (§6.3), and they are all compositions of the
				     same document. The strip is offered where the checkouts are: the checkout
				     destination, which is what a profile composes. */ }
				{ 'checkout' === area ? (
					<CheckoutProfilesPanel
						profiles={ profiles }
						active={ activeProfile }
						onSelect={ onProfileSelect }
						onCreate={ onCreateProfile }
						onUpdate={ onUpdateProfile }
						onRemove={ onRemoveProfile }
						onMove={ onMoveProfile }
						vocabulary={ catalog?.conditions ?? {} }
						fields={ doc?.fields ?? [] }
						facts={ catalog?.checkoutFacts ?? null }
						factsChecked={ Boolean( catalog ) }
						refusal={ profileRefusal }
						onDismissRefusal={ onDismissProfileRefusal }
					/>
				) : null }

				<div className="contextbar">
					<ContextTabs
						active={ contextActive }
						label={ __(
							'Contexto da superfície',
							'wc-checkoutsuite'
						) }
						items={ contexts }
						onChange={ ( next ) => {
							if (
								'checkout' !== area &&
								( 'customer_email' === next ||
									'admin_email' === next )
							) {
								onAreaChange( next );

								return;
							}

							setSurfaceContext( next );
							if ( 'customer_account' === area ) {
								onEdit( null );
							}

							if ( 'blocks' === next && 'blocks' !== reference ) {
								announce(
									__(
										'Modo Blocks: limitações de posição e tipos foram sinalizadas.',
										'wc-checkoutsuite'
									)
								);
							}
							if ( 'checkout' === area ) {
								model.onReferenceChange( next );
							}
						} }
					/>
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
									<li
										key={ entry.field }
									>{ `${ entry.label } — ${ entry.type }` }</li>
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
						<p>
							<strong>{ saved }</strong>{ ' ' }
							{ ( model.urls?.checkout ?? '' ) !== '' ? (
								// The address the store actually serves, not a copy drawn
								// in the admin: the merchant asked to see the result of a
								// save, and the result is the storefront.
								<a
									className="wccs-notice__link"
									href={ model.urls.checkout }
									target="_blank"
									rel="noreferrer"
								>
									{ __( 'Ver checkout', 'wc-checkoutsuite' ) }
								</a>
							) : (
								<button
									type="button"
									className="wccs-notice__link"
									onClick={ model.onPreview }
								>
									{ __( 'Ver checkout', 'wc-checkoutsuite' ) }
								</button>
							) }
							{ ( model.urls?.account ?? '' ) !== '' ? (
								<>
									{ ' ' }
									<a
										className="wccs-notice__link"
										href={ model.urls.account }
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'Abrir em Minha Conta',
											'wc-checkoutsuite'
										) }
									</a>
								</>
							) : null }
							{ localDraftRestored ? (
								<>
									{ ' ' }
									<button
										type="button"
										className="wccs-notice__link"
										onClick={ onDiscardLocalDraft }
									>
										{ __(
											'Descartar edição local',
											'wc-checkoutsuite'
										) }
									</button>
								</>
							) : null }
						</p>
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
					{ /* As seções do checkout, na coluna da esquerda. O que era uma tira
					     horizontal de separadores passa a ser a lista do desenho: ícone, nome e a
					     frase que diz o que a seção guarda. É a mesma escolha, com lugar
					     para a explicar. */ }
					{ 'customer_account' === area ? (
						<AccountMenuList
							items={ contexts }
							active={ contextActive }
							onSelect={ selectAccountPage }
							onCreate={ onCreateSection }
						/>
					) : (
						<ContainerList
							groups={ groups }
							active={ section }
							onSelect={ onSectionChange }
							onCreate={ onCreateSection }
							words={ words }
							label={ sprintf(
								/* translators: %s: what this destination calls its containers. */
								__( '%s desta área', 'wc-checkoutsuite' ),
								words.many
							) }
						/>
					) }

					<div
						className={
							'editor-column' +
							( accountContextEmpty
								? ' account-context-empty'
								: '' )
						}
					>
						{ 'edit-account' === contextActive ? (
							<AccountNativeFields
								inventory={ coreFields?.account }
								document={ doc }
								pageLabel={ activeAccountPage?.label }
								editing={ editing }
								onAdopt={ onAdoptAccount }
								onEdit={ onEdit }
								onToggle={ onToggleAccount }
							/>
						) : null }

						{ 'edit-address' === contextActive ? (
							<div className="account-address-notice">
								<Notice
									status="info"
									title={ __(
										'Endereços do WooCommerce',
										'wc-checkoutsuite'
									) }
								>
									<p>
										{ __(
											'Esta página tem submenus próprios para Cobrança e Entrega. Os campos são renderizados e gravados pelo formulário nativo do WooCommerce.',
											'wc-checkoutsuite'
										) }
									</p>
									<p>
										{ __(
											'Campos nativos continuam sob o controle do WooCommerce. Você pode adicionar campos complementares nesta página usando o editor abaixo.',
											'wc-checkoutsuite'
										) }
									</p>
									{ activeAccountPage?.children?.length ? (
										<ul className="account-address-notice__links">
											{ activeAccountPage.children.map(
												(
													/** @type {any} */ child
												) => (
													<li key={ child.id }>
														<a
															href={ child.url }
															target="_blank"
															rel="noreferrer"
														>
															{ child.label }
														</a>
													</li>
												)
											) }
										</ul>
									) : null }
								</Notice>
							</div>
						) : null }

						<div className="panel builder-panel">
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
										<p>
											{ __(
												'Gerencie os campos desta seção. Arraste para reordenar.',
												'wc-checkoutsuite'
											) }
										</p>
										{ sectionAreas.length > 0 ? (
											<p
												className="wccs-section-areas"
												aria-label={ __(
													'Áreas em que esta seção está ativa',
													'wc-checkoutsuite'
												) }
											>
												<strong>
													{ __(
														'Ativa em:',
														'wc-checkoutsuite'
													) }
												</strong>{ ' ' }
												{ sectionAreas
													.map(
														(
															/** @type {any} */ entry
														) => entry.label
													)
													.join( ', ' ) }
											</p>
										) : null }
									</div>
								</div>
								{ /* §6.4: o título é do comerciante e pode ser escondido no
								     checkout sem perder o nome no admin. A seção implícita do
								     WooCommerce não tem onde guardar a escolha, e o controlo
								     di-lo em vez de fingir que a guardou. */ }
								<label
									className="switch-row section-title-switch"
									htmlFor="wccs-section-show-title"
								>
									<input
										id="wccs-section-show-title"
										type="checkbox"
										checked={ sectionShowsTitle }
										disabled={ ! current?.declared }
										title={
											current?.declared
												? undefined
												: __(
														'Uma seção do próprio WooCommerce não guarda esta escolha: crie uma seção personalizada para a controlar.',
														'wc-checkoutsuite'
												  )
										}
										onChange={ (
											/** @type {{ target: { checked: boolean } }} */ event
										) =>
											model.onToggleSectionTitle?.(
												event.target.checked
											)
										}
									/>
									<span>
										{ __(
											'Exibir título da seção',
											'wc-checkoutsuite'
										) }
									</span>
								</label>

								<span
									className="badge"
									aria-label={ __(
										'Quantidade de campos na seção',
										'wc-checkoutsuite'
									) }
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
								</span>
								<button
									type="button"
									className="text-btn"
									disabled={ ! current?.declared }
									onClick={ () =>
										onOpenSection(
											current?.section.id ?? null
										)
									}
									aria-label={ sprintf(
										/* translators: 1: what this destination calls the actions, 2: container title. */
										__( '%1$s: %2$s', 'wc-checkoutsuite' ),
										words.actions,
										copy.title
									) }
								>
									{ words.actions }
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
											onClick={ () => {
												setAcknowledged( false );
												setPendingBulk( 'enable' );
											} }
										>
											{ __(
												'Habilitar',
												'wc-checkoutsuite'
											) }
										</button>
										<button
											type="button"
											className="text-btn"
											onClick={ () => {
												setAcknowledged( false );
												setPendingBulk( 'disable' );
											} }
										>
											{ __(
												'Desativar',
												'wc-checkoutsuite'
											) }
										</button>
										<button
											type="button"
											className="text-btn danger"
											onClick={ () => {
												setAcknowledged( false );
												setPendingBulk( 'archive' );
											} }
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
								role="list"
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
										// Blocks mode places core fields itself, so their
										// handle is inert there — the design's rule, and the
										// same one its capability badges state.
										const moveDisabled =
											'blocks' === reference && isCore;
										const isDragging = drag.id === field.id;
										const isDropTarget =
											drag.over === field.id &&
											! isDragging;

										return (
											<div
												key={ field.id }
												data-row-id={ field.id }
												role="listitem"
												className={
													'field-row' +
													( isSelected
														? ' selected'
														: '' ) +
													( field.enabled
														? ''
														: ' disabled' ) +
													( isDragging
														? ' dragging'
														: '' ) +
													( isDropTarget
														? ' drag-over'
														: '' )
												}
												onDragOver={ ( event ) => {
													if (
														! drag.id ||
														isDragging
													) {
														return;
													}

													event.preventDefault();

													if (
														drag.over !== field.id
													) {
														setDrag( {
															id: drag.id,
															over: field.id,
														} );
													}
												} }
												onDrop={ ( event ) => {
													const carried = (
														doc?.fields ?? []
													).find(
														(
															/** @type {any} */ entry
														) =>
															entry.id === drag.id
													);

													if ( ! carried ) {
														return;
													}

													event.preventDefault();
													dropRow( carried, field );
												} }
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
													id={ `wccs-drag-${ field.id }` }
													className="drag-handle"
													draggable={ ! moveDisabled }
													title={ __(
														'Arraste ou use Alt + setas para mover',
														'wc-checkoutsuite'
													) }
													aria-label={ sprintf(
														/* translators: %s: field label. */
														__(
															'Ordenar %s. Alt e setas para mover.',
															'wc-checkoutsuite'
														),
														field.label
													) }
													disabled={ moveDisabled }
													onDragStart={ ( event ) =>
														startDrag(
															field,
															event
														)
													}
													onDragEnd={ () =>
														setDrag( {
															id: null,
															over: null,
														} )
													}
													onKeyDown={ ( event ) => {
														if ( ! event.altKey ) {
															return;
														}

														if (
															'ArrowUp' ===
																event.key &&
															moveUp
														) {
															event.preventDefault();
															moveRow(
																field,
																index,
																'up'
															);
														}

														if (
															'ArrowDown' ===
																event.key &&
															moveDown
														) {
															event.preventDefault();
															moveRow(
																field,
																index,
																'down'
															);
														}
													} }
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
															id={ `wccs-menu-${ field.id }` }
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
																	moveRow(
																		field,
																		index,
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
																	moveRow(
																		field,
																		index,
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
										onClick={ () =>
											announceStep( edits.undo() )
										}
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
										onClick={ () =>
											announceStep( edits.redo(), true )
										}
									>
										<Icon name="redo" />
									</button>
								</div>
							</div>
						</div>

						{ /* Os campos que a loja já tem nesta seção e que ainda não são
						     geridos aqui (§6.2). Deixaram de ser um painel à parte: são
						     as últimas linhas da lista, com a mesma cara das outras, e é
						     nelas que o comerciante usa o campo — para depois o
						     desativar, alterar ou deixar como está. */ }
						<CoreCheckoutPanel
							inventory={ coreFields }
							document={ composition ?? doc }
							inline
							/* A seção aberta pode ser um contentor do comerciante, e o
							   campo nativo pertence à **localização** do checkout
							   (`billing`, `shipping`, `order`): é a localização que
							   diz quais são os campos da loja que faltam aqui. */
							onlyKey={
								current?.section?.location ??
								current?.section?.id ??
								''
							}
							onAdoptField={ onAdoptCore }
							onHideField={ onHideCore }
						/>

						{ /* A prévia da seção, como o desenho a põe: o mesmo documento, os
						     mesmos rótulos, a mesma largura por campo — uma amostra do que o
						     cliente vai ver. Não é o renderizador do WooCommerce, e a prévia
						     completa continua a ser a da seção «Prévia do checkout». */ }
						<PreviewPanel
							title={ __(
								'Prévia da seção',
								'wc-checkoutsuite'
							) }
							description={ __(
								'Veja como esta seção aparecerá para o cliente.',
								'wc-checkoutsuite'
							) }
							onOpen={ model.onPreview }
							className="section-preview"
						>
							{ ( current?.fields ?? [] ).length === 0 ? (
								<p className="muted small">
									{ __(
										'Assim que houver um campo nesta seção, ele aparece aqui.',
										'wc-checkoutsuite'
									) }
								</p>
							) : (
								<div className="section-preview-grid">
									{ ( current?.fields ?? [] ).map(
										( /** @type {any} */ field ) => {
											const width = Number(
												field.layout?.desktop ?? 12
											);

											return (
												<div
													key={ field.id }
													className="section-preview-field"
													style={ {
														gridColumn: `span ${ Math.max(
															1,
															Math.min(
																12,
																width
															)
														) }`,
													} }
												>
													<span className="sample-label">
														{ field.label ||
															__(
																'Campo sem nome',
																'wc-checkoutsuite'
															) }
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
													</span>
													<span className="section-preview-control">
														{ field.description ||
															field.placeholder ||
															'' }
													</span>
												</div>
											);
										}
									) }
								</div>
							) }
						</PreviewPanel>

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
						{ properties && ! narrow ? (
							properties
						) : (
							<div
								className="add-field-panel"
								aria-label={ __(
									'Adicionar campo',
									'wc-checkoutsuite'
								) }
							>
								<div className="add-field-head">
									<h2>
										{ __(
											'Adicionar campo',
											'wc-checkoutsuite'
										) }
									</h2>
								</div>

								<p className="muted small">
									{ __(
										'Use os botões do cabeçalho para adicionar um campo novo ou vincular um campo existente. Escolha um campo da lista para editar as propriedades dele.',
										'wc-checkoutsuite'
									) }
								</p>

								{ /* O que o desenho põe por baixo do formulário: o campo
								     nativo da loja que esta seção ainda não adotou. É o
								     mesmo caminho do painel «Checkout padrão», aqui onde o
								     campo nasce. */ }
								{ ( coreFields?.fields ?? [] ).length > 0 ? (
									<div className="add-field-native">
										<h3>
											{ __(
												'Campos do próprio WooCommerce',
												'wc-checkoutsuite'
											) }
										</h3>
										<p className="muted small">
											{ sprintf(
												/* translators: 1: adopted count, 2: total count. */
												__(
													'%1$d de %2$d gerenciados nesta tela.',
													'wc-checkoutsuite'
												),
												(
													coreFields?.fields ?? []
												).filter(
													(
														/** @type {any} */ entry
													) => entry.managed
												).length,
												( coreFields?.fields ?? [] )
													.length
											) }
										</p>
										<ul className="add-field-native-list">
											{ ( coreFields?.fields ?? [] )
												.filter(
													(
														/** @type {any} */ entry
													) => ! entry.managed
												)
												.slice( 0, 6 )
												.map(
													(
														/** @type {any} */ entry
													) => (
														<li key={ entry.id }>
															<span>
																<strong>
																	{ entry.label ??
																		entry.id }
																</strong>
																<code>
																	{ entry.id }
																</code>
															</span>
															<button
																type="button"
																className="text-btn"
																onClick={ () =>
																	onAdoptCore(
																		entry
																	)
																}
															>
																<Icon name="plus" />
																{ __(
																	'Usar',
																	'wc-checkoutsuite'
																) }
															</button>
														</li>
													)
												) }
										</ul>
									</div>
								) : null }
							</div>
						) }
					</aside>
				</div>

				{ narrow && properties ? (
					<Dialog
						open
						title={ __(
							'Propriedades do campo',
							'wc-checkoutsuite'
						) }
						topBar
						className="mobile-inspector"
						onClose={ () => onEdit( null ) }
					>
						{ properties }
					</Dialog>
				) : null }

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
							onClick={ runExport }
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
					surface={ /** @type {'checkout'|'my_account'} */ ( area ) }
					open={ pickerOpen }
					onSectionChange={ onSectionChange }
					onChooseType={ ( /** @type {any} */ choice ) => {
						setPickerOpen( false );
						onCreateField( {
							...choice,
							...( accountContextEmpty && contextActive
								? {
										accountPage: contextActive,
										accountPageLabel:
											activeAccountPage?.label,
								  }
								: {} ),
						} );
					} }
					onAdoptCore={ ( /** @type {any} */ core ) => {
						setPickerOpen( false );
						onAdoptCore( core );
					} }
					onClose={ () => setPickerOpen( false ) }
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

			<Dialog
				open={ null !== pendingBulk }
				eyebrow={ __( 'Ação em massa', 'wc-checkoutsuite' ) }
				title={ __(
					'Confirmar alteração em massa?',
					'wc-checkoutsuite'
				) }
				subtitle={ __(
					'A alteração vai para o rascunho e não chega ao checkout antes de publicar.',
					'wc-checkoutsuite'
				) }
				onClose={ () => setPendingBulk( null ) }
				footer={
					<>
						<Button
							variant="secondary"
							onClick={ () => setPendingBulk( null ) }
						>
							{ __( 'Cancelar', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							variant="primary"
							disabled={
								( bulkReport?.dependents?.length ?? 0 ) > 0 &&
								! acknowledged
							}
							onClick={ () => {
								if ( pendingBulk ) {
									runBulk( pendingBulk );
								}

								setPendingBulk( null );
							} }
						>
							{ __( 'Confirmar', 'wc-checkoutsuite' ) }
						</Button>
					</>
				}
			>
				{ bulkReport ? (
					<>
						{ /* The design states the impact as a list of the fields it
						     applies to, each with the reason it does or does not
						     change — not as three totals the merchant has to map
						     back onto the rows themselves. */ }
						<ul className="impact-list">
							{ bulkReport.protected.map(
								( /** @type {string} */ id ) => (
									<li key={ `protected-${ id }` }>
										<Icon name="shield" />
										<span>
											<strong>{ labelOf( id ) }</strong>{ ' ' }
											{ __(
												'fica intocado: é da WooCommerce.',
												'wc-checkoutsuite'
											) }
										</span>
									</li>
								)
							) }
							{ bulkReport.unchanged.map(
								( /** @type {string} */ id ) => (
									<li key={ `unchanged-${ id }` }>
										<Icon name="circle" />
										<span>
											<strong>{ labelOf( id ) }</strong>{ ' ' }
											{ __(
												'já está nesse estado.',
												'wc-checkoutsuite'
											) }
										</span>
									</li>
								)
							) }
						</ul>
						{ bulkReport.dependents.length > 0 ? (
							<label
								className="confirm-check"
								htmlFor="wccs-confirm-dependents"
							>
								<input
									id="wccs-confirm-dependents"
									type="checkbox"
									checked={ acknowledged }
									onChange={ (
										/** @type {{target: {checked: boolean}}} */ event
									) =>
										setAcknowledged( event.target.checked )
									}
								/>
								<span>
									{ sprintf(
										/* translators: %s: comma-separated field labels. */
										__(
											'Autorizo também deixar sem origem a regra de %s.',
											'wc-checkoutsuite'
										),
										bulkReport.dependents
											.map( labelOf )
											.join( ', ' )
									) }
								</span>
							</label>
						) : null }
						<p className="form-help">
							{ sprintf(
								/* translators: 1: fields the action changes, 2: selected fields. */
								__(
									'%1$d de %2$d campo(s) selecionado(s) mudam. Nada foi alterado ainda.',
									'wc-checkoutsuite'
								),
								bulkReport.affected,
								bulkReport.total
							) }
						</p>
					</>
				) : null }
			</Dialog>

			{ sectionEditor }

			{ sectionDraft }

			{ linkDialog }
			{ removalDialog }
			{ migrationDialog }

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

			<Toast
				message={ announcement.message }
				token={ announcement.token }
			/>
		</>
	);
}
