/**
 * Local edit history.
 *
 * Undo and redo over the document being edited, and nothing else.
 *
 * ROADMAP.md section 428 is explicit that the two mechanisms are separate:
 * "Undo local de edição e histórico de publicação são mecanismos separados". They
 * answer different questions and they must not be confused for one another:
 *
 * | | Local undo | Publication history |
 * |---|---|---|
 * | Covers | edits not yet saved | versions the store has run |
 * | Lives | in this browser tab | on the server |
 * | Answers | "put that back" | "what was published, and when" |
 * | Undoing it | forgets an edit | publishes again, as a new revision |
 *
 * Merging them would make "undo" ambiguous, and the ambiguity would be resolved
 * by whichever one a developer reached for first — which is how a merchant
 * discovers that undo rewrote what the store ran last week.
 *
 * The stack is bounded. An unbounded history of full documents is unbounded
 * memory, and the merchant's problem is the last few edits, not the last
 * thousand.
 *
 * The stack lives in a ref because its depth is all the interface needs; keeping
 * the documents in state would re-render the screen for every remembered edit,
 * and mutating it outside a state updater keeps the operations free of the
 * side effects that break under concurrent rendering.
 *
 * The operations are stable across renders on purpose. A hook that returns new
 * callbacks every time cannot be used in a dependency array, and the screen that
 * consumed this one depended on the whole returned object: every render produced
 * a new one, which re-ran the effect that had just set it, which produced
 * another render. The field screen did not render at all until it was fixed.
 *
 * @see ROADMAP.md section 428
 */

import { useCallback, useMemo, useRef, useState } from '@wordpress/element';

/**
 * How many edits are remembered.
 */
export const HISTORY_LIMIT = 50;

/**
 * Local edit history over one document.
 *
 * @param {*} initial Document the editor starts from.
 * @return {{ document: any, commit: (next: any) => void, reset: (next: any) => void, undo: () => void, redo: () => void, canUndo: boolean, canRedo: boolean, depth: {past: number, future: number} }} Current document, the operations and their availability.
 */
export default function useDocumentHistory( initial = null ) {
	const [ present, setPresent ] = useState( initial );
	const [ depth, setDepth ] = useState( { past: 0, future: 0 } );

	/**
	 * The current document, readable from the operations.
	 *
	 * The operations read it from here rather than closing over the state value,
	 * which is what makes them stable. A hook whose callbacks change identity on
	 * every render cannot be put in a dependency array without looping, and the
	 * screen that used it did exactly that: it depended on the object this hook
	 * returns, which changed on every render, which ran the effect again.
	 *
	 * @type {{ current: any }}
	 */
	const current = useRef( initial );

	/**
	 * Past and future documents.
	 *
	 * @type {{ current: { past: any[], future: any[] } }}
	 */
	const stack = useRef( { past: [], future: [] } );

	/**
	 * Publishes how deep each direction is.
	 *
	 * @return {void}
	 */
	const sync = useCallback( () => {
		setDepth( {
			past: stack.current.past.length,
			future: stack.current.future.length,
		} );
	}, [] );

	/**
	 * Replaces the document, remembering the one it replaces.
	 *
	 * Anything on the redo stack is discarded: after a new edit the future it
	 * described is no longer reachable. That is what every editor does, and the
	 * alternative — keeping a branch — is a feature nobody asked for.
	 *
	 * @param {*} next New document, or a function of the current one.
	 * @return {void}
	 */
	const commit = useCallback(
		( /** @type {any} */ next ) => {
			const previous = current.current;
			const value = 'function' === typeof next ? next( previous ) : next;

			if ( value === previous ) {
				return;
			}

			stack.current.past.push( previous );

			if ( stack.current.past.length > HISTORY_LIMIT ) {
				stack.current.past.shift();
			}

			stack.current.future = [];

			current.current = value;
			setPresent( value );
			sync();
		},
		[ sync ]
	);

	/**
	 * Replaces the document without remembering it.
	 *
	 * Used when the server is the authority: a reload, a save the server answered
	 * with its own revision, a publication. Recording those as editable steps
	 * would let the merchant "undo" into a state the server never had.
	 *
	 * @param {*} next New document.
	 * @return {void}
	 */
	const reset = useCallback(
		( /** @type {any} */ next ) => {
			stack.current = { past: [], future: [] };
			current.current = next;
			setPresent( next );
			sync();
		},
		[ sync ]
	);

	/**
	 * Steps back one edit.
	 *
	 * @return {void}
	 */
	const undo = useCallback( () => {
		const previous = stack.current.past.pop();

		if ( undefined === previous ) {
			return;
		}

		stack.current.future.push( current.current );
		current.current = previous;

		setPresent( previous );
		sync();
	}, [ sync ] );

	/**
	 * Steps forward one edit.
	 *
	 * @return {void}
	 */
	const redo = useCallback( () => {
		const next = stack.current.future.pop();

		if ( undefined === next ) {
			return;
		}

		stack.current.past.push( current.current );
		current.current = next;

		setPresent( next );
		sync();
	}, [ sync ] );

	// Memoised so the identity only changes when something observable does. The
	// operations themselves are stable, which is what lets a caller depend on one
	// of them instead of on the whole object.
	return useMemo(
		() => ( {
			document: present,
			commit,
			reset,
			undo,
			redo,
			canUndo: depth.past > 0,
			canRedo: depth.future > 0,
			depth,
		} ),
		[ present, commit, reset, undo, redo, depth ]
	);
}
