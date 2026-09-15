/**
 * Administration shell.
 *
 * The frame the design draws around every screen: the navigation column, the bar at
 * the top of the window, and the content region. The markup, the classes and the
 * measurements are the prototype's (`roadmap/fields.html`), so a change to the
 * design is a change to this file and to `design/fields.css` and nothing else.
 *
 * Three adaptations, each a fact about running inside wp-admin rather than a design
 * decision, and each stated where it happens below: the navigation also carries the
 * sections this plugin ships that the prototype does not draw, the small print at
 * the foot of the column states the store and the version instead of the
 * prototype's "local design environment", and the theme switch is the prototype's
 * own but applied to the document element the tokens already key off.
 *
 * The active section lives in the address bar as well as in state, so a tab can be
 * linked to and so copying the address always yields the tab on screen.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Notice, PreviewFrame } from './components';
import { Icon } from './design/icons';
import { TopbarActionsContext } from './design/TopbarActions';
import FieldsScreen from './FieldsScreen';
import SettingsScreen from './SettingsScreen';
import StatusesScreen from './StatusesScreen';
import WorkflowsScreen from './WorkflowsScreen';
import { readSection, sectionHref } from './sectionUrl';
import previewCapabilities from './previewCapabilities';

/**
 * Sections the design draws, with the words and the glyphs it draws them with.
 *
 * The identifier is the plugin's own, because it is the one the address bar carries
 * and the one the server knows; the label and the icon are the prototype's.
 *
 * @type {Array<{id: string, label: string, icon: string}>}
 */
const DESIGNED_SECTIONS = [
	{
		id: 'fields',
		label: __( 'Editor de campos', 'wc-checkoutsuite' ),
		icon: 'fields',
	},
	{
		id: 'appearance',
		label: __( 'Prévia do checkout', 'wc-checkoutsuite' ),
		icon: 'eye',
	},
	{
		id: 'archive',
		label: __( 'Arquivados', 'wc-checkoutsuite' ),
		icon: 'archive',
	},
	{
		id: 'rules',
		label: __( 'Regras do editor', 'wc-checkoutsuite' ),
		icon: 'shield',
	},
	{
		id: 'statuses',
		label: __( 'Status personalizados', 'wc-checkoutsuite' ),
		icon: 'layers',
	},
	{
		id: 'workflows',
		label: __( 'Automação de status', 'wc-checkoutsuite' ),
		icon: 'branch',
	},
];

/**
 * Destinations the field manager owns.
 *
 * @type {Array<string>}
 */
const MANAGER_VIEWS = [ 'fields', 'appearance', 'archive', 'rules' ];

/**
 * Glyph for a section, chosen from the design's own set.
 *
 * @type {Record<string, string>}
 */
const SECTION_ICONS = {
	settings: 'sliders',
	diagnostics: 'info',
	fields: 'fields',
	appearance: 'eye',
};

/**
 * Server menu entries that do not have a working screen yet. They are kept in
 * the server contract for the roadmap, but must not be advertised as clickable
 * destinations until their flows exist.
 *
 * @type {Set<string>}
 */
const UNAVAILABLE_SECTIONS = new Set( [
	'sections',
	'checkout-page',
	'import-export',
] );

/**
 * The logo the design draws in the column.
 *
 * @return {*} Rendered mark.
 */
function BrandMark() {
	return (
		<div className="brand-mark" aria-hidden="true">
			<svg viewBox="0 0 28 28" fill="none">
				<path
					d="M6 7h16M6 13h9M6 19h6M16 19l3 3 6-8"
					stroke="currentColor"
					strokeWidth="2.2"
					strokeLinecap="round"
					strokeLinejoin="round"
				/>
			</svg>
		</div>
	);
}

/**
 * Returns the section matching an identifier.
 *
 * @param {Array<{id: string, label: string, icon: string}>} sections Section list.
 * @param {string}                                           id       Identifier.
 * @return {{id: string, label: string, icon: string}|undefined} Matching section.
 */
function findSection( sections, id ) {
	return sections.find( ( section ) => section.id === id );
}

/**
 * The navigation the design draws, plus the sections this plugin ships.
 *
 * The plugin's own sections keep their server-provided labels: they are screens the
 * prototype does not cover, and naming them here would be inventing product wording
 * inside a stylesheet port.
 *
 * @param {Array<{id: string, label: string}>} serverSections Sections from the server.
 * @return {Array<{id: string, label: string, icon: string}>} Navigation items.
 */
function navigation( serverSections ) {
	// The design's four are always offered: they are views of one document, and the
	// screen that owns it renders all four. The server's list is about its own screens,
	// so it decides what is added beside them and not what exists at all.
	const designed = DESIGNED_SECTIONS;

	const extra = serverSections
		.filter(
			( section ) =>
				! UNAVAILABLE_SECTIONS.has( section.id ) &&
				! DESIGNED_SECTIONS.some( ( item ) => item.id === section.id )
		)
		.map( ( section ) => ( {
			id: section.id,
			label: section.label,
			icon: SECTION_ICONS[ section.id ] ?? 'sliders',
		} ) );

	return [ ...designed, ...extra ];
}

/**
 * Renders the content of the active section.
 *
 * @param {Object} props                Component properties.
 * @param {string} props.section        Active section identifier.
 * @param {Object} [props.client]       REST client, when the section needs one.
 * @param {string} [props.siteName]     Store name, for the screens that draw it.
 * @param {Object} [props.urls]         Storefront addresses the screens link to.
 * @param {string} [props.checkoutMode] Which checkout the store runs.
 * @return {*} Rendered element tree.
 */
function SectionContent( { section, client, siteName, urls, checkoutMode } ) {
	// The design's four destinations are views of one document: the editor, the checkout
	// preview, the archive and the rules. They share the screen that owns the draft, so
	// switching between them keeps the work in progress and reloads nothing.
	if ( MANAGER_VIEWS.includes( section ) && client ) {
		return (
			<FieldsScreen
				client={ client }
				view={ section }
				siteName={ siteName }
				urls={ urls }
				checkoutMode={ checkoutMode }
			/>
		);
	}

	if ( MANAGER_VIEWS.includes( section ) ) {
		return <PreviewFrame capabilities={ previewCapabilities } />;
	}

	// Settings is where the merchant turns the custom checkout on; Diagnostics is the
	// same state without the switch. One component, two sections, because they answer
	// one question between them.
	// The states an order waits in (§12). They are not part of the schema document: a status
	// belongs to the workflow vocabulary and has its own screen, which is why this is not a
	// view of the field manager.
	if ( 'statuses' === section && client ) {
		return <StatusesScreen client={ client } />;
	}

	// The automations of §13. A workflow is not a view of the schema document either: it moves
	// orders, and it has its own screen under "Status e automações" (§4).
	if ( 'workflows' === section && client ) {
		return <WorkflowsScreen client={ client } />;
	}

	if ( 'settings' === section && client ) {
		return <SettingsScreen client={ client } />;
	}

	if ( 'diagnostics' === section && client ) {
		return <SettingsScreen client={ client } editable={ false } />;
	}

	return (
		<Notice
			status="info"
			title={ __( 'Not built yet', 'wc-checkoutsuite' ) }
		>
			{ __(
				'Fields, Appearance, Settings and Diagnostics are the sections that work today. The remaining sections are built in later stages of the roadmap.',
				'wc-checkoutsuite'
			) }
		</Notice>
	);
}

/**
 * Administration shell component.
 *
 * The return type is left to inference on purpose: annotating it as `Object`
 * makes TypeScript refuse to treat the component as a JSX element.
 *
 * @param {Object}                          props                Component properties.
 * @param {{ id: string, label: string }[]} props.sections       Navigation sections.
 * @param {string}                          props.version        Plugin version.
 * @param {string}                          [props.siteName]     Store name, for the column's foot.
 * @param {Object}                          [props.client]       REST client.
 * @param {Object}                          [props.urls]         Storefront addresses the screens link to.
 * @param {string}                          [props.checkoutMode] Which checkout the store runs.
 */
export default function AppShell( {
	sections,
	version,
	siteName,
	client,
	urls = {},
	checkoutMode = '',
} ) {
	const items = useMemo( () => navigation( sections ), [ sections ] );
	const ids = items.map( ( item ) => item.id );
	const first = ids.length > 0 ? ids[ 0 ] : '';

	// The address bar decides the first section, so a link to a tab opens that
	// tab. An unknown or absent value falls back to the first section.
	const [ current, setCurrent ] = useState(
		() => readSection( window.location?.search ?? '', ids ) || first
	);

	/**
	 * The node the active screen renders its topbar actions into.
	 *
	 * Held in state rather than a ref so the screens render their actions again once
	 * the bar exists; a ref would leave the first render's portal with nothing to
	 * attach to and no reason to try again.
	 *
	 * @type {[HTMLElement|null, Function]}
	 */
	const [ actionsNode, setActionsNode ] = useState( null );

	/**
	 * The theme the design toggles between.
	 *
	 * The prototype writes the attribute on the document element, which is what the
	 * design tokens key off, so the switch behaves the same way here.
	 *
	 * @type {[string, Function]}
	 */
	const [ theme, setTheme ] = useState( 'light' );

	useEffect( () => {
		if ( document.documentElement ) {
			document.documentElement.dataset.theme = theme;
		}
	}, [ theme ] );

	useEffect( () => {
		// A section can disappear when the navigation changes; never leave the
		// user on a section that no longer exists.
		if ( items.length > 0 && ! findSection( items, current ) ) {
			setCurrent( items[ 0 ].id );
		}
	}, [ items, current ] );

	useEffect( () => {
		/**
		 * Moves to a section on a screen's request.
		 *
		 * The design puts links to other screens inside the editor — the tip card
		 * to the preview, the footnote to the rules — and the active section belongs
		 * to this frame, not to the screen that links. An event keeps the ownership
		 * where it is and lets a screen ask, instead of the screen reaching into the
		 * frame or the frame reaching into the screen's state.
		 *
		 * @param {any} event Custom event carrying the section identifier.
		 * @return {void}
		 */
		const onNavigate = ( event ) => {
			const id = event?.detail?.section;

			if ( id && findSection( items, id ) ) {
				selectSection( id );
			}
		};

		window.addEventListener( 'wccs:navigate', onNavigate );

		return () => window.removeEventListener( 'wccs:navigate', onNavigate );
	} );

	/**
	 * Selects a section and keeps the address in step with it.
	 *
	 * Replacing rather than pushing: switching tabs is not navigation, and a back
	 * button full of tab clicks makes leaving the screen a chore.
	 *
	 * @param {string} id Section identifier.
	 * @return {void}
	 */
	const selectSection = ( id ) => {
		setCurrent( id );

		if ( ! window.history?.replaceState ) {
			return;
		}

		window.history.replaceState(
			null,
			'',
			sectionHref( window.location.href, id, ids )
		);
	};

	const active = findSection( items, current );
	const title = active
		? active.label
		: __( 'WC CheckoutSuite', 'wc-checkoutsuite' );
	const dark = 'dark' === theme;

	const topbarContext = useMemo(
		() => ( {
			node: actionsNode,
			setNode: ( /** @type {HTMLElement|null} */ node ) =>
				setActionsNode( node ),
		} ),
		[ actionsNode ]
	);

	return (
		<TopbarActionsContext.Provider value={ topbarContext }>
			<a className="skip" href="#wccs-shell-content">
				{ __( 'Pular para o editor', 'wc-checkoutsuite' ) }
			</a>

			<div className="app">
				<aside
					className="sidebar"
					aria-label={ __(
						'Checkout suite sections',
						'wc-checkoutsuite'
					) }
				>
					<div className="brand">
						<BrandMark />
						<div className="brand-name">
							<small>WC</small>
							CheckoutSuite
						</div>
					</div>

					<div className="nav-wrap">
						<div className="nav-kicker">
							{ __( 'SEU CHECKOUT', 'wc-checkoutsuite' ) }
						</div>
						<nav className="nav">
							{ items.map( ( item ) => {
								const isActive = item.id === current;

								return (
									<button
										key={ item.id }
										type="button"
										className={ isActive ? 'active' : '' }
										aria-current={
											isActive ? 'page' : undefined
										}
										aria-label={ item.label }
										onClick={ () =>
											selectSection( item.id )
										}
									>
										<Icon name={ item.icon } />
										<span>{ item.label }</span>
									</button>
								);
							} ) }
						</nav>
					</div>

					<div className="sidebar-note">
						<div className="side-orbit" aria-hidden="true">
							<Icon name="spark" />
						</div>
						<strong>
							{ __(
								'Seu checkout, do seu jeito.',
								'wc-checkoutsuite'
							) }
						</strong>
						<p>
							{ __( 'Campos flexíveis.', 'wc-checkoutsuite' ) }
							<br />
							{ __(
								'Uma experiência consistente.',
								'wc-checkoutsuite'
							) }
						</p>
						<span className="side-pill">
							{ __( 'Field Manager', 'wc-checkoutsuite' ) }
						</span>
					</div>

					<div className="sidebar-bottom">
						<div className="avatar">WC</div>
						<div>
							{ /* The prototype's small print describes its own
							     demonstration environment. A store is not one, so
							     the slot states the two facts a support
							     conversation needs instead. */ }
							<strong>
								{ siteName ||
									__( 'Sua loja', 'wc-checkoutsuite' ) }
							</strong>
							<small>
								{ version
									? sprintf(
											/* translators: %s: plugin version. */
											__(
												'Versão %s',
												'wc-checkoutsuite'
											),
											version
									  )
									: __(
											'CheckoutSuite',
											'wc-checkoutsuite'
									  ) }
							</small>
						</div>
						<i className="live-dot" />
					</div>
				</aside>

				<div className="main-area">
					<header className="topbar">
						<div className="breadcrumb">
							<span>WooCommerce</span>
							<span>/</span>
							<strong>CheckoutSuite</strong>
							<span>/</span>
							<span>{ title }</span>
						</div>
						<div className="top-actions">
							<button
								type="button"
								className="icon-btn"
								onClick={ () =>
									setTheme( dark ? 'light' : 'dark' )
								}
								title={ __(
									'Alternar tema',
									'wc-checkoutsuite'
								) }
								aria-label={
									dark
										? __(
												'Ativar tema claro',
												'wc-checkoutsuite'
										  )
										: __(
												'Ativar tema escuro',
												'wc-checkoutsuite'
										  )
								}
							>
								<Icon name={ dark ? 'sun' : 'moon' } />
							</button>
							{ /* Where the active screen puts its own actions. */ }
							<span
								className="top-actions__slot"
								ref={ ( /** @type {any} */ node ) =>
									setActionsNode( node )
								}
							/>
						</div>
					</header>

					<main
						className="content"
						id="wccs-shell-content"
						tabIndex={ -1 }
					>
						<SectionContent
							section={ current }
							client={ client }
							siteName={ siteName }
							urls={ urls }
							checkoutMode={ checkoutMode }
						/>
					</main>
				</div>
			</div>
		</TopbarActionsContext.Provider>
	);
}
