/**
 * The section, kept in the address bar.
 *
 * Two things a merchant needs and that the screen did not offer:
 *
 * 1. **A linkable address for a tab.** `?page=wccs-checkoutsuite&section=rules`
 *    opens the rules section directly, so a colleague can be sent to it.
 * 2. **An address that matches what is on screen.** The URL is kept in step with
 *    the section, so copying it from the address bar always yields the tab the
 *    merchant is looking at. A link that opens the wrong tab is worse than no
 *    link at all.
 *
 * The shorthand form `?page=wccs-checkoutsuite&fields` is accepted on the way in,
 * because it is what someone writes by hand. It is not what the screen writes:
 * one canonical form means one thing to parse, and the shorthand is rewritten the
 * first time the section changes.
 *
 * `replaceState` is used rather than `pushState`. Tab switching is not
 * navigation, and filling the back button with a dozen tab clicks would make
 * leaving the screen a chore.
 *
 * @see ROADMAP.md section 428
 */

/**
 * Query parameter the screen writes.
 */
export const SECTION_PARAM = 'section';

/**
 * Reads the section named in a query string.
 *
 * An unknown value is ignored rather than returned: a link to a section this
 * build does not have should open the screen rather than a blank one.
 *
 * @param {string}   search Query string, with or without the leading `?`.
 * @param {string[]} ids    Identifiers this build has.
 * @return {string} Section identifier, or an empty string.
 */
export function readSection( search, ids ) {
	const known = Array.isArray( ids ) ? ids : [];

	if ( '' === ( search ?? '' ) ) {
		return '';
	}

	let params;

	try {
		params = new URLSearchParams( search );
	} catch {
		return '';
	}

	const explicit = params.get( SECTION_PARAM );

	if ( explicit && known.includes( explicit ) ) {
		return explicit;
	}

	// The shorthand: a bare parameter whose name is a section.
	for ( const key of params.keys() ) {
		if ( known.includes( key ) ) {
			return key;
		}
	}

	return '';
}

/**
 * Builds the query string for a section.
 *
 * @param {string}   search Query string to start from.
 * @param {string}   id     Section identifier.
 * @param {string[]} ids    Identifiers this build has.
 * @return {string} Query string, including the leading `?`.
 */
export function sectionUrl( search, id, ids ) {
	const known = Array.isArray( ids ) ? ids : [];
	let params;

	try {
		params = new URLSearchParams( search ?? '' );
	} catch {
		params = new URLSearchParams();
	}

	params.set( SECTION_PARAM, id );

	// Drop the shorthand once it has been understood, so the address has one
	// spelling instead of two.
	for ( const key of [ ...params.keys() ] ) {
		if ( SECTION_PARAM !== key && known.includes( key ) ) {
			params.delete( key );
		}
	}

	return `?${ params.toString() }`;
}

/**
 * Returns the page URL with the section set.
 *
 * Kept separate from the two functions above so they stay pure and testable
 * without a document.
 *
 * @param {string}   href Current URL.
 * @param {string}   id   Section identifier.
 * @param {string[]} ids  Identifiers this build has.
 * @return {string} New URL.
 */
export function sectionHref( href, id, ids ) {
	let url;

	try {
		url = new URL( href );
	} catch {
		return href;
	}

	const query = sectionUrl( url.search, id, ids );

	// The origin is kept: this returns an address, and an address without one is
	// only usable by a caller that already knows where it is.
	return `${ url.origin }${ url.pathname }${ query }${ url.hash }`;
}
