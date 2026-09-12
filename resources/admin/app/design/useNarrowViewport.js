/**
 * Whether the window is at the width the design considers narrow.
 *
 * The prototype draws the field properties in the editor's right column, and below
 * 870px it hides that column and opens the same properties in a dialog instead. The
 * decision is the stylesheet's, so the query here is the same one the ported
 * stylesheet uses — read from one place rather than repeated as a magic number in
 * the view.
 *
 * A browser without `matchMedia` is treated as wide, which is the layout that needs
 * no dialog: a surface that cannot measure itself should not hide its controls.
 */

import { useEffect, useState } from '@wordpress/element';

/**
 * The query the design's stylesheet switches the editor layout at.
 *
 * @type {string}
 */
export const NARROW_QUERY = '(max-width: 870px)';

/**
 * Reads the current answer, when the browser can be asked.
 *
 * @return {boolean} Whether the window is narrow.
 */
function matches() {
	if ( 'function' !== typeof globalThis.matchMedia ) {
		return false;
	}

	return Boolean( globalThis.matchMedia( NARROW_QUERY ).matches );
}

/**
 * Tracks the width the design calls narrow.
 *
 * @return {boolean} Whether the window is narrow.
 */
export function useNarrowViewport() {
	const [ narrow, setNarrow ] = useState( matches );

	useEffect( () => {
		if ( 'function' !== typeof globalThis.matchMedia ) {
			return undefined;
		}

		const media = globalThis.matchMedia( NARROW_QUERY );

		setNarrow( Boolean( media.matches ) );

		const onChange = ( /** @type {{matches: boolean}} */ event ) =>
			setNarrow( Boolean( event.matches ) );

		// `addListener` is the form older browsers ship; jsdom is one of them.
		if ( 'function' === typeof media.addEventListener ) {
			media.addEventListener( 'change', onChange );

			return () => media.removeEventListener( 'change', onChange );
		}

		if ( 'function' === typeof media.addListener ) {
			media.addListener( onChange );

			return () => media.removeListener( onChange );
		}

		return undefined;
	}, [] );

	return narrow;
}
