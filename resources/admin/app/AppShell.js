/**
 * Administration shell.
 *
 * Establishes the frame every later screen renders inside: the navigation fixed
 * by the planning, a header carrying the current context, and a single content
 * region. Fields renders the field manager (F03) and Appearance renders the
 * visual preview. The remaining sections state plainly what they are waiting for
 * instead of showing a fake interface.
 *
 * The active section lives in the address bar as well as in state, so a tab can
 * be linked to and so copying the address always yields the tab on screen.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Notice, PreviewFrame } from './components';
import FieldsScreen from './FieldsScreen';
import SettingsScreen from './SettingsScreen';
import { readSection, sectionHref } from './sectionUrl';
import previewCapabilities from './previewCapabilities';

/**
 * Returns the section matching an identifier.
 *
 * The shape is written inline because the JSDoc linter cannot see ambient
 * declarations; the TypeScript check still enforces that it matches the
 * bootstrap payload at the call site in index.js.
 *
 * @param {{ id: string, label: string }[]} sections Section list.
 * @param {string}                          id       Section identifier.
 * @return {{ id: string, label: string }|undefined} Matching section, when present.
 */
function findSection( sections, id ) {
	return sections.find( ( section ) => section.id === id );
}

/**
 * Renders the content of the active section.
 *
 * Extracted from the shell so the section switch reads as a list of cases
 * instead of a nested conditional, and so each section's own state survives as
 * long as that section is on screen.
 *
 * @param {Object} props          Component properties.
 * @param {string} props.section  Active section identifier.
 * @param {Object} [props.client] REST client, when the section needs one.
 * @return {*} Rendered element tree.
 */
function SectionContent( { section, client } ) {
	if ( 'fields' === section && client ) {
		return <FieldsScreen client={ client } />;
	}

	if ( 'appearance' === section ) {
		return <PreviewFrame capabilities={ previewCapabilities } />;
	}

	// Settings is where the merchant turns the custom checkout on; Diagnostics is the
	// same state without the switch. One component, two sections, because they answer
	// one question between them.
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
 * @param {Object}                          props          Component properties.
 * @param {{ id: string, label: string }[]} props.sections Navigation sections.
 * @param {string}                          props.version  Plugin version.
 * @param {Object}                          [props.client] REST client.
 */
export default function AppShell( { sections, version, client } ) {
	const first = sections.length > 0 ? sections[ 0 ].id : '';
	const ids = sections.map( ( section ) => section.id );

	// The address bar decides the first section, so a link to a tab opens that
	// tab. An unknown or absent value falls back to the first section.
	const [ current, setCurrent ] = useState(
		() => readSection( window.location?.search ?? '', ids ) || first
	);

	useEffect( () => {
		// A section can disappear when the navigation changes; never leave the
		// user on a section that no longer exists.
		if ( sections.length > 0 && ! findSection( sections, current ) ) {
			setCurrent( sections[ 0 ].id );
		}
	}, [ sections, current ] );

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

	const active = findSection( sections, current );
	const title = active
		? active.label
		: __( 'WC CheckoutSuite', 'wc-checkoutsuite' );

	return (
		<div className="wccs-shell">
			<a className="wccs-shell__skip" href="#wccs-shell-content">
				{ __( 'Skip to content', 'wc-checkoutsuite' ) }
			</a>

			<nav
				className="wccs-shell__nav"
				aria-label={ __(
					'Checkout suite sections',
					'wc-checkoutsuite'
				) }
			>
				<p className="wccs-shell__nav-kicker">
					{ __( 'Your checkout', 'wc-checkoutsuite' ) }
				</p>
				<ul className="wccs-shell__nav-list">
					{ sections.map( ( section ) => (
						<li key={ section.id }>
							<button
								type="button"
								className={
									'wccs-shell__nav-item' +
									( section.id === current
										? ' is-active'
										: '' )
								}
								aria-current={
									section.id === current ? 'page' : undefined
								}
								onClick={ () => selectSection( section.id ) }
							>
								<span className="wccs-shell__nav-label">
									{ section.label }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			</nav>

			<div className="wccs-shell__main">
				<header className="wccs-shell__header">
					<h1 className="wccs-shell__title">{ title }</h1>
					{ version ? (
						<span className="wccs-shell__version">
							{ sprintf(
								/* translators: %s: plugin version. */
								__( 'Version %s', 'wc-checkoutsuite' ),
								version
							) }
						</span>
					) : null }
				</header>

				<main className="wccs-shell__content" id="wccs-shell-content">
					<SectionContent section={ current } client={ client } />
				</main>
			</div>
		</div>
	);
}
