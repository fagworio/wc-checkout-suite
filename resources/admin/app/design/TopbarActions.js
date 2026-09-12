/**
 * The topbar's action slot.
 *
 * The design puts a screen's actions in the bar at the top of the window — theme,
 * save, publish — while the state those actions act on belongs to the screen. This
 * module is the seam between the two: the shell renders the slot, and a screen
 * renders its buttons into it.
 *
 * A portal rather than lifted state on purpose. Lifting the document into the shell
 * would make the frame own the merchant's work, and every screen that later needs
 * its own actions — the appearance preview publishes too — would have to ask the
 * shell for a slice of state it does not otherwise care about. The slot keeps the
 * ownership where the data is and the frame where the design puts it.
 */

import { createContext, createPortal, useContext } from '@wordpress/element';

/**
 * The slot's contract: the node the shell rendered, when it has one.
 *
 * @type {import('react').Context<{node: HTMLElement|null, setNode: (node: (HTMLElement|null)) => void}>}
 */
/**
 * The value used before a frame provides its own.
 *
 * Typed explicitly: without the annotation the default value narrows the context to
 * a slot that can only ever be null, and every consumer then disagrees with the
 * provider about what the context holds.
 *
 * @type {{node: HTMLElement|null, setNode: (node: (HTMLElement|null)) => void}}
 */
const EMPTY_SLOT = {
	node: null,
	setNode: () => {},
};

export const TopbarActionsContext = createContext( EMPTY_SLOT );

/**
 * Renders children into the topbar's action slot.
 *
 * When there is no slot — before the frame has mounted, and in any render that does
 * not include a frame at all — the actions are rendered where they are declared
 * instead. That fallback exists because the alternative is worse than a misplaced
 * button: a screen whose save action is missing cannot be saved, and the design's
 * position for a control is not worth losing the control over.
 *
 * @param {Object} props          Component properties.
 * @param {*}      props.children Actions to place in the bar.
 * @return {*} Portal, or the actions in place.
 */
export function TopbarActions( { children } ) {
	const { node } = useContext( TopbarActionsContext );

	return node ? createPortal( children, node ) : children;
}
