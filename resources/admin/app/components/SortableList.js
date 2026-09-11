/**
 * Reorderable list.
 *
 * Moving an item is offered through buttons and through the keyboard, and the new
 * position is announced. ROADMAP.md section 428 makes both paths mandatory: a
 * drag-and-drop list "tem alternativa 'Mover para cima/baixo', anúncio da posição
 * e suporte por teclado", and "reordenação preserva foco, grupo e ID".
 *
 * Three decisions carry that requirement:
 *
 * 1. **The buttons are the primary path, not a fallback.** They are always
 *    present, labelled with the item they move, and disabled at the ends. Nothing
 *    about reordering depends on a pointer.
 *
 * 2. **Focus survives the move.** Reordering rewrites the DOM, and a focused
 *    control that disappears takes the keyboard user's place with it. After each
 *    move the same item's control is focused again — falling back to the opposite
 *    control when the one that was used became disabled at the end of the list.
 *
 * 3. **The position is announced.** A silent reorder leaves a screen reader user
 *    with no idea whether anything happened, so the new position is written to a
 *    polite live region.
 *
 * The row itself carries no key handler. Putting one on a non-interactive list
 * item is an accessibility anti-pattern — the element announces itself as a list
 * item and then behaves like a control — and it is unnecessary: the two buttons
 * are ordinary buttons, so tabbing to one and pressing Enter is the keyboard
 * path, and it is the same path for everybody. An Alt+Arrow shortcut on the row
 * would be convenient and would also make the row lie about what it is.
 *
 * Pointer drag-and-drop is deliberately *not* implemented. It is the riskiest
 * interaction in this phase — the phase's stated main risk is that drag-and-drop
 * changes production by accident — and the accessible path above is what the
 * planning requires to exist. Adding dragging later must not remove it.
 *
 * @see ROADMAP.md sections 428 and 432
 */

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import IconButton from './IconButton';

/**
 * Returns the accessible name of one item.
 *
 * @param {Function|undefined} getLabel Caller-provided labeller.
 * @param {any}                item     Item.
 * @param {number}             index    Position.
 * @return {string} Label.
 */
function labelOf( getLabel, item, index ) {
	if ( typeof getLabel === 'function' ) {
		return String( getLabel( item, index ) );
	}

	return String( item?.label ?? item?.id ?? index + 1 );
}

/**
 * Reorderable list.
 *
 * @param {Object}   props             Component properties.
 * @param {any[]}    props.items       Items, each with a stable `id`.
 * @param {string}   props.label       Accessible name of the list.
 * @param {Function} props.onMove      Called with `( id, direction )`.
 * @param {Function} props.renderItem  Called with `( item, index )`.
 * @param {Function} [props.getLabel]  Called with `( item, index )` for names.
 * @param {string}   [props.emptyText] Shown when the list is empty.
 * @return {*} Rendered element tree.
 */
export default function SortableList( {
	items,
	label,
	onMove,
	renderItem,
	getLabel,
	emptyText = __( 'Nothing here yet.', 'wc-checkoutsuite' ),
} ) {
	const [ announcement, setAnnouncement ] = useState( '' );

	/** @type {{ current: Map<string, any> }} */
	const controls = useRef( new Map() );

	/**
	 * The move waiting to be reported and refocused.
	 *
	 * @type {{ current: { id: string, direction: 'up'|'down' }|null }}
	 */
	const pending = useRef( null );

	const list = useMemo(
		() => ( Array.isArray( items ) ? items : [] ),
		[ items ]
	);

	useEffect( () => {
		const move = pending.current;

		if ( ! move ) {
			return;
		}

		pending.current = null;

		const index = list.findIndex( ( item ) => item.id === move.id );

		if ( index < 0 ) {
			return;
		}

		setAnnouncement(
			sprintf(
				/* translators: 1: item name, 2: new position, 3: total number of items. */
				__(
					'%1$s moved to position %2$d of %3$d.',
					'wc-checkoutsuite'
				),
				labelOf( getLabel, list[ index ], index ),
				index + 1,
				list.length
			)
		);

		// Focus the same item's control. The one that was used may have become
		// disabled at the end of the list, so the opposite control is the
		// fallback: what matters is that focus stays with the item that moved.
		const used = controls.current.get( `${ move.id }:${ move.direction }` );
		const other = controls.current.get(
			`${ move.id }:${ 'up' === move.direction ? 'down' : 'up' }`
		);

		let target = null;

		if ( used && ! used.disabled ) {
			target = used;
		} else if ( other && ! other.disabled ) {
			target = other;
		}

		if ( target ) {
			target.focus();
		}
	}, [ list, getLabel ] );

	/**
	 * Requests a move and remembers it for the effect above.
	 *
	 * @param {string}      id        Item identifier.
	 * @param {'up'|'down'} direction Direction.
	 * @return {void}
	 */
	const move = ( id, direction ) => {
		pending.current = { id, direction };
		onMove( id, direction );
	};

	if ( 0 === list.length ) {
		return <p className="wccs-sortable__empty">{ emptyText }</p>;
	}

	return (
		<div className="wccs-sortable">
			<ul className="wccs-sortable__list" aria-label={ label }>
				{ list.map( ( item, index ) => {
					const name = labelOf( getLabel, item, index );
					const first = 0 === index;
					const last = index === list.length - 1;

					return (
						<li key={ item.id } className="wccs-sortable__item">
							<span
								className="wccs-sortable__position"
								aria-hidden="true"
							>
								{ index + 1 }
							</span>

							<div className="wccs-sortable__content">
								{ renderItem( item, index ) }
							</div>

							<div className="wccs-sortable__actions">
								<IconButton
									icon="↑"
									label={ sprintf(
										/* translators: %s: item name. */
										__( 'Move %s up', 'wc-checkoutsuite' ),
										name
									) }
									disabled={ first }
									onClick={ () => move( item.id, 'up' ) }
									ref={ ( /** @type {any} */ node ) => {
										if ( node ) {
											controls.current.set(
												`${ item.id }:up`,
												node
											);
										} else {
											controls.current.delete(
												`${ item.id }:up`
											);
										}
									} }
								/>
								<IconButton
									icon="↓"
									label={ sprintf(
										/* translators: %s: item name. */
										__(
											'Move %s down',
											'wc-checkoutsuite'
										),
										name
									) }
									disabled={ last }
									onClick={ () => move( item.id, 'down' ) }
									ref={ ( /** @type {any} */ node ) => {
										if ( node ) {
											controls.current.set(
												`${ item.id }:down`,
												node
											);
										} else {
											controls.current.delete(
												`${ item.id }:down`
											);
										}
									} }
								/>
							</div>
						</li>
					);
				} ) }
			</ul>

			<p className="wccs-screen-reader-text" role="status">
				{ announcement }
			</p>
		</div>
	);
}
