/**
 * Section URL tests.
 *
 * Two promises are made to the merchant here, and both are easy to break in
 * silence: a link to a tab opens that tab, and the address bar always shows the
 * tab on screen. A URL that opens the wrong section is worse than no URL, because
 * the person who follows it believes what they see.
 *
 * The destination inside a screen is the same promise one level down — «abre o
 * pedido no admin» — and it is written by its own pair of functions so that the
 * two parameters never overwrite each other.
 */

import {
	areaHref,
	readArea,
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

/**
 * The destinations a build has.
 *
 * @type {string[]}
 */
const AREAS = [
	'checkout',
	'customer_account',
	'customer_order',
	'admin_order',
	'admin_customer_profile',
];

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

describe( 'the destination inside a screen', () => {
	it( 'reads the destination the link names', () => {
		expect(
			readArea(
				'?page=wccs-checkoutsuite&section=fields&area=admin_order',
				AREAS
			)
		).toBe( 'admin_order' );
	} );

	it( 'ignores a destination this build does not have', () => {
		expect( readArea( '?area=sidebar', AREAS ) ).toBe( '' );
		expect( readArea( '', AREAS ) ).toBe( '' );
	} );

	it( 'writes the destination and leaves the screen it is on alone', () => {
		const href = areaHref(
			'https://example.test/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields',
			'admin_customer_profile',
			AREAS
		);

		expect( href ).toContain( 'section=fields' );
		expect( href ).toContain( 'page=wccs-checkoutsuite' );
		expect( href ).toContain( 'area=admin_customer_profile' );
	} );

	it( 'does not put a destination the build does not have in the address', () => {
		const href = 'https://example.test/wp-admin/admin.php?page=wccs';

		expect( areaHref( href, 'sidebar', AREAS ) ).toBe( href );
	} );

	it( 'survives a round trip', () => {
		const href = areaHref(
			'https://example.test/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields',
			'customer_order',
			AREAS
		);

		expect( readArea( new URL( href ).search, AREAS ) ).toBe(
			'customer_order'
		);
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
