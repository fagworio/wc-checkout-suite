/**
 * Tabs.
 *
 * Implements the WAI-ARIA tabs pattern: a single tab stop for the whole list
 * (roving tabindex), arrow keys that move focus between tabs, Home and End, and
 * a panel tied to its tab through `aria-controls`/`aria-labelledby`. Selection
 * follows focus, which is the pattern's default for cheap panels.
 */

import { useRef } from '@wordpress/element';

/**
 * @param {Object} props             Component properties.
 * @param {*[]}    props.tabs        Tabs as `{ id, label }`.
 * @param {string} props.active      Identifier of the selected tab.
 * @param {*}      props.onSelect    Called with the identifier to select.
 * @param {*}      props.renderPanel Called with the active tab identifier.
 * @param {string} props.label       Accessible name of the tab list.
 * @return {*} Rendered element tree.
 */
export default function Tabs( { tabs, active, onSelect, renderPanel, label } ) {
	/** @type {{ current: Record<string, HTMLButtonElement|null> }} */
	const refs = useRef( {} );

	if ( tabs.length === 0 ) {
		return null;
	}

	const activeIndex = Math.max(
		0,
		tabs.findIndex( ( tab ) => tab.id === active )
	);

	/**
	 * Moves selection to an index, wrapping around and keeping focus in sync.
	 *
	 * @param {number} index Target index.
	 * @return {void}
	 */
	const moveTo = ( index ) => {
		const wrapped = ( index + tabs.length ) % tabs.length;
		const target = tabs[ wrapped ];

		onSelect( target.id );

		const node = refs.current[ target.id ];

		if ( node ) {
			node.focus();
		}
	};

	/**
	 * Handles key presses on the tab list.
	 *
	 * @param {*} event Keyboard event.
	 * @return {void}
	 */
	const onKeyDown = ( event ) => {
		switch ( event.key ) {
			case 'ArrowRight':
				event.preventDefault();
				moveTo( activeIndex + 1 );
				break;
			case 'ArrowLeft':
				event.preventDefault();
				moveTo( activeIndex - 1 );
				break;
			case 'Home':
				event.preventDefault();
				moveTo( 0 );
				break;
			case 'End':
				event.preventDefault();
				moveTo( tabs.length - 1 );
				break;
		}
	};

	return (
		<div className="wccs-tabs">
			{ /* eslint-disable-next-line jsx-a11y/interactive-supports-focus -- The WAI-ARIA tabs pattern puts focus on the tabs themselves; the tablist is deliberately not a tab stop. */ }
			<div
				className="wccs-tabs__list"
				role="tablist"
				aria-label={ label }
				onKeyDown={ onKeyDown }
			>
				{ tabs.map( ( tab ) => {
					const selected = tab.id === tabs[ activeIndex ].id;

					return (
						<button
							key={ tab.id }
							type="button"
							role="tab"
							id={ `wccs-tab-${ tab.id }` }
							className={
								'wccs-tabs__tab' +
								( selected ? ' is-active' : '' )
							}
							aria-selected={ selected ? 'true' : 'false' }
							aria-controls={ `wccs-tabpanel-${ tab.id }` }
							// Roving tabindex: only the selected tab is in the tab order.
							tabIndex={ selected ? 0 : -1 }
							ref={ ( node ) => {
								refs.current[ tab.id ] = node;
							} }
							onClick={ () => onSelect( tab.id ) }
						>
							{ tab.label }
						</button>
					);
				} ) }
			</div>

			<div
				className="wccs-tabs__panel"
				id={ `wccs-tabpanel-${ tabs[ activeIndex ].id }` }
				role="tabpanel"
				aria-labelledby={ `wccs-tab-${ tabs[ activeIndex ].id }` }
				tabIndex={ 0 }
			>
				{ renderPanel( tabs[ activeIndex ].id ) }
			</div>
		</div>
	);
}
