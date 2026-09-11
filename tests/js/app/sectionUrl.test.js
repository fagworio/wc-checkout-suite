/**
 * Section URL tests.
 *
 * Two promises are made to the merchant here, and both are easy to break in
 * silence: a link to a tab opens that tab, and the address bar always shows the
 * tab on screen. A URL that opens the wrong section is worse than no URL, because
 * the person who follows it believes what they see.
 */

import {
	readSection,
	sectionHref,
	sectionUrl,
} from '../../../resources/admin/app/sectionUrl';

/**
 * The sections a build has.
 *
 * @type {string[]}
 */
const IDS = [ 'fields', 'sections', 'rules', 'appearance' ];

describe( 'reading a section from a URL', () => {
	it( 'finds the canonical parameter', () => {
		expect(
			readSection( '?page=wccs-checkoutsuite&section=rules', IDS )
		).toBe( 'rules' );
	} );

	it( 'accepts the shorthand a person writes by hand', () => {
		expect( readSection( '?page=wccs-checkoutsuite&rules', IDS ) ).toBe(
			'rules'
		);
	} );

	it( 'works without the leading question mark', () => {
		expect( readSection( 'section=fields', IDS ) ).toBe( 'fields' );
	} );

	it( 'prefers the canonical parameter when both are present', () => {
		expect( readSection( '?section=rules&fields', IDS ) ).toBe( 'rules' );
	} );

	it( 'ignores a section this build does not have', () => {
		expect( readSection( '?section=sidebar', IDS ) ).toBe( '' );
	} );

	it( 'returns nothing for an empty query', () => {
		expect( readSection( '', IDS ) ).toBe( '' );
	} );

	it( 'ignores the other parameters WordPress adds', () => {
		expect(
			readSection(
				'?page=wccs-checkoutsuite&noheader=1&section=sections',
				IDS
			)
		).toBe( 'sections' );
	} );
} );

describe( 'writing a section into a URL', () => {
	it( 'adds the canonical parameter without losing the others', () => {
		const query = sectionUrl( '?page=wccs-checkoutsuite', 'rules', IDS );

		expect( query ).toContain( 'page=wccs-checkoutsuite' );
		expect( query ).toContain( 'section=rules' );
	} );

	it( 'replaces an existing section rather than adding a second', () => {
		const query = sectionUrl( '?page=wccs&section=fields', 'rules', IDS );

		expect( query ).toContain( 'section=rules' );
		expect( query ).not.toContain( 'section=fields' );
	} );

	it( 'rewrites the shorthand into the canonical form', () => {
		const query = sectionUrl( '?page=wccs&rules', 'rules', IDS );

		expect( query ).toContain( 'section=rules' );
		expect( new URLSearchParams( query ).get( 'rules' ) ).toBeNull();
	} );

	it( 'leaves parameters it does not understand alone', () => {
		const query = sectionUrl( '?page=wccs&noheader=1', 'fields', IDS );

		expect( query ).toContain( 'noheader=1' );
	} );

	it( 'round-trips through the reader', () => {
		for ( const id of IDS ) {
			expect(
				readSection( sectionUrl( '?page=wccs', id, IDS ), IDS )
			).toBe( id );
		}
	} );
} );

describe( 'building the whole address', () => {
	it( 'keeps the path and the fragment', () => {
		const href = sectionHref(
			'https://example.test/wp-admin/admin.php?page=wccs-checkoutsuite#top',
			'rules',
			IDS
		);

		expect( href ).toContain( 'https://example.test/wp-admin/admin.php' );
		expect( href ).toContain( 'section=rules' );
		expect( href ).toContain( '#top' );
	} );

	it( 'returns the address unchanged when it cannot be parsed', () => {
		expect( sectionHref( 'not a url', 'rules', IDS ) ).toBe( 'not a url' );
	} );
} );
