/**
 * Local edit history tests.
 *
 * The behaviour under test is what makes this mechanism *different* from the
 * publication history, and the difference is the whole reason ROADMAP.md section
 * 428 keeps them apart: this one covers edits that have not been saved, and it
 * forgets them when the server becomes the authority.
 *
 * A history that quietly reached the server would make "undo" destroy a version
 * the store ran, which is the failure the separation exists to prevent.
 */

import { act, renderHook } from '@testing-library/react';

import useDocumentHistory, {
	HISTORY_LIMIT,
} from '../../../resources/admin/app/schema/useDocumentHistory';

/**
 * Builds a document.
 *
 * @param {number} revision Revision.
 * @return {Object} Document.
 */
function doc( revision ) {
	return { revision, fields: [], sections: [], settings: {} };
}

describe( 'a fresh history', () => {
	it( 'starts on the document it was given', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		expect( result.current.document ).toEqual( doc( 1 ) );
	} );

	it( 'has nothing to undo or redo', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		expect( result.current.canUndo ).toBe( false );
		expect( result.current.canRedo ).toBe( false );
	} );
} );

describe( 'undoing an edit', () => {
	it( 'marks that there is something to undo', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );

		expect( result.current.document ).toEqual( doc( 2 ) );
		expect( result.current.canUndo ).toBe( true );
	} );

	it( 'goes back to the document before the edit', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );
		act( () => result.current.undo() );

		expect( result.current.document ).toEqual( doc( 1 ) );
		expect( result.current.canUndo ).toBe( false );
		expect( result.current.canRedo ).toBe( true );
	} );

	it( 'walks back through several edits', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );
		act( () => result.current.commit( doc( 3 ) ) );
		act( () => result.current.commit( doc( 4 ) ) );
		act( () => result.current.undo() );
		act( () => result.current.undo() );

		expect( result.current.document ).toEqual( doc( 2 ) );
	} );

	it( 'does nothing when there is nothing to undo', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.undo() );

		expect( result.current.document ).toEqual( doc( 1 ) );
	} );

	it( 'accepts a function of the current document', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () =>
			result.current.commit( ( /** @type {any} */ current ) => ( {
				...current,
				revision: current.revision + 1,
			} ) )
		);

		expect( result.current.document.revision ).toBe( 2 );
	} );

	it( 'ignores an edit that changes nothing', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );
		const same = result.current.document;

		act( () => result.current.commit( same ) );

		expect( result.current.canUndo ).toBe( false );
	} );
} );

describe( 'redoing an edit', () => {
	it( 'goes forward again', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );
		act( () => result.current.undo() );
		act( () => result.current.redo() );

		expect( result.current.document ).toEqual( doc( 2 ) );
		expect( result.current.canRedo ).toBe( false );
	} );

	it( 'is forgotten once a new edit is made', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );
		act( () => result.current.undo() );
		act( () => result.current.commit( doc( 3 ) ) );

		expect( result.current.canRedo ).toBe( false );
		expect( result.current.document ).toEqual( doc( 3 ) );
	} );

	it( 'does nothing when there is nothing to redo', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.redo() );

		expect( result.current.document ).toEqual( doc( 1 ) );
	} );
} );

describe( 'when the server becomes the authority', () => {
	it( 'forgets the local edits', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 1 ) ) );

		act( () => result.current.commit( doc( 2 ) ) );
		act( () => result.current.commit( doc( 3 ) ) );
		act( () => result.current.reset( doc( 9 ) ) );

		expect( result.current.document ).toEqual( doc( 9 ) );
		expect( result.current.canUndo ).toBe( false );
		expect( result.current.canRedo ).toBe( false );
	} );
} );

describe( 'the stack is bounded', () => {
	it( 'keeps at most the configured number of edits', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 0 ) ) );

		for ( let step = 1; step <= HISTORY_LIMIT + 10; step++ ) {
			act( () => result.current.commit( doc( step ) ) );
		}

		expect( result.current.depth.past ).toBe( HISTORY_LIMIT );
	} );

	it( 'still undoes through what it kept', () => {
		const { result } = renderHook( () => useDocumentHistory( doc( 0 ) ) );

		for ( let step = 1; step <= HISTORY_LIMIT + 10; step++ ) {
			act( () => result.current.commit( doc( step ) ) );
		}

		act( () => result.current.undo() );

		expect( result.current.document ).toEqual( doc( HISTORY_LIMIT + 9 ) );
	} );
} );
