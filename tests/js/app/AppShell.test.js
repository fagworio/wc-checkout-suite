/**
 * Application shell tests.
 *
 * The shell owns one thing the merchant notices when it is wrong: the address
 * bar. Two failures matter and neither is visible in a screenshot of the page —
 * a link that opens the wrong tab, and an address that stops matching the tab on
 * screen the moment it is copied.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AppShell from '../../../resources/admin/app/AppShell';

/**
 * The sections the plugin publishes.
 *
 * @type {Array<{id: string, label: string}>}
 */
const SECTIONS = [
	{ id: 'fields', label: 'Fields' },
	{ id: 'rules', label: 'Rules' },
	{ id: 'appearance', label: 'Appearance' },
];

/**
 * The name the navigation gives a section.
 *
 * The design names the two sections it draws — the field editor and the checkout
 * preview — and writes them in its own words; the sections the plugin ships that the
 * design does not draw keep the label the server publishes. The test asks for the name
 * a merchant sees, so the design can be reworded without this file silently stopping
 * finding the button it means.
 *
 * @param {{id: string, label: string}} section Section.
 * @return {string} Accessible name.
 */
function navName( section ) {
	const designed = {
		fields: 'Editor de campos',
		appearance: 'Prévia do checkout',
	};

	return designed[ section.id ] ?? section.label;
}

/** @type {{id: string, label: string}} */
const FIELDS = SECTIONS.find( ( section ) => 'fields' === section.id );

/** @type {{id: string, label: string}} */
const RULES = SECTIONS.find( ( section ) => 'rules' === section.id );

/** @type {{id: string, label: string}} */
const APPEARANCE = SECTIONS.find( ( section ) => 'appearance' === section.id );

/**
 * Puts a URL in place and returns a restore function.
 *
 * @param {string} search Query string.
 * @return {Function} Restore function.
 */
function withUrl( search ) {
	const before = window.location.href;
	const url = new URL( before );

	url.search = search;
	window.history.replaceState( null, '', url.href );

	return () => window.history.replaceState( null, '', before );
}

/**
 * Renders the shell.
 *
 * @return {*} Render result.
 */
function renderShell() {
	return render( <AppShell sections={ SECTIONS } version="0.1.0" /> );
}

describe( 'the section in the address bar', () => {
	it( 'opens the section the address names', () => {
		const restore = withUrl( '?page=wccs-checkoutsuite&section=rules' );

		try {
			renderShell();

			expect(
				screen.getByRole( 'button', { name: navName( RULES ) } )
			).toHaveAttribute( 'aria-current', 'page' );
		} finally {
			restore();
		}
	} );

	it( 'opens the section named by the shorthand form', () => {
		const restore = withUrl( '?page=wccs-checkoutsuite&rules' );

		try {
			renderShell();

			expect(
				screen.getByRole( 'button', { name: navName( RULES ) } )
			).toHaveAttribute( 'aria-current', 'page' );
		} finally {
			restore();
		}
	} );

	it( 'falls back to the first section when the address names none', () => {
		const restore = withUrl( '?page=wccs-checkoutsuite' );

		try {
			renderShell();

			expect(
				screen.getByRole( 'button', { name: navName( FIELDS ) } )
			).toHaveAttribute( 'aria-current', 'page' );
		} finally {
			restore();
		}
	} );

	it( 'falls back rather than opening nothing when the section is unknown', () => {
		const restore = withUrl( '?page=wccs-checkoutsuite&section=sidebar' );

		try {
			renderShell();

			expect(
				screen.getByRole( 'button', { name: navName( FIELDS ) } )
			).toHaveAttribute( 'aria-current', 'page' );
		} finally {
			restore();
		}
	} );
} );

describe( 'choosing a section', () => {
	it( 'writes the new section into the address', async () => {
		const user = userEvent.setup();
		const restore = withUrl( '?page=wccs-checkoutsuite' );

		try {
			renderShell();

			await user.click(
				screen.getByRole( 'button', { name: navName( RULES ) } )
			);

			expect( window.location.search ).toContain( 'section=rules' );
			expect( window.location.search ).toContain(
				'page=wccs-checkoutsuite'
			);
		} finally {
			restore();
		}
	} );

	it( 'keeps the address and the screen in step', async () => {
		const user = userEvent.setup();
		const restore = withUrl( '?page=wccs-checkoutsuite&section=fields' );

		try {
			renderShell();

			await user.click(
				screen.getByRole( 'button', { name: navName( APPEARANCE ) } )
			);

			expect(
				screen.getByRole( 'button', { name: navName( APPEARANCE ) } )
			).toHaveAttribute( 'aria-current', 'page' );
			expect( window.location.search ).toContain( 'section=appearance' );
		} finally {
			restore();
		}
	} );

	it( 'replaces the entry instead of filling the back button with tabs', async () => {
		const user = userEvent.setup();
		const restore = withUrl( '?page=wccs-checkoutsuite' );

		try {
			const push = jest.spyOn( window.history, 'pushState' );

			renderShell();

			await user.click(
				screen.getByRole( 'button', { name: navName( RULES ) } )
			);

			expect( push ).not.toHaveBeenCalled();

			push.mockRestore();
		} finally {
			restore();
		}
	} );
} );

describe( 'the shell itself', () => {
	it( 'still offers every section', () => {
		const restore = withUrl( '?page=wccs-checkoutsuite' );

		try {
			renderShell();

			for ( const section of SECTIONS ) {
				expect(
					screen.getByRole( 'button', {
						name: navName( section ),
					} )
				).toBeInTheDocument();
			}
		} finally {
			restore();
		}
	} );

	it( 'renders the field manager when that is the section', async () => {
		const restore = withUrl( '?page=wccs-checkoutsuite&section=fields' );

		try {
			const client = {
				getDraft: jest.fn( async () => ( {
					revision: 0,
					fields: [],
					sections: [],
					settings: {},
				} ) ),
				fieldTypes: jest.fn( async () => ( {
					categories: [],
					types: {},
					presets: [],
					masks: [],
					vocabulary: {
						storageScopes: [],
						storageSensitivities: [],
						visibilityKeys: [],
						hiddenValuePolicies: [],
					},
					sectionLocations: [],
				} ) ),
				coreFields: jest.fn( async () => ( {
					available: false,
					reason: 'WooCommerce is not active.',
					sections: [],
					fields: [],
				} ) ),
				diff: jest.fn( async () => ( {
					diff: {
						empty: true,
						total_changes: 0,
						fields: { added: [], removed: [], changed: [] },
						sections: { added: [], removed: [], changed: [] },
						order: [],
						published: { revision: 0, updated_at: '' },
						draft: { revision: 0, updated_at: '' },
					},
					validation: { valid: true, errors: [] },
					incompatibilities: {
						total: 0,
						adapters: {},
						store: [],
					},
					adapters: [],
					storage: {
						draft: { state: 'absent', stored_version: null },
						published: { state: 'absent', stored_version: null },
					},
				} ) ),
				revisions: jest.fn( async () => ( { revisions: [] } ) ),
			};

			render(
				<AppShell
					sections={ SECTIONS }
					version="0.1.0"
					client={ client }
				/>
			);

			// A deep link to the field manager has to arrive at the field manager,
			// not merely highlight its tab.
			await screen.findByText( 'No fields yet' );

			expect( screen.getByText( 'No fields yet' ) ).toBeInTheDocument();
		} finally {
			restore();
		}
	} );
} );
