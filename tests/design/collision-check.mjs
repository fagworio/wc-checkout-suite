/**
 * Class-name collision check.
 *
 * The port took the design's class names verbatim. Some of them are also wp-admin's
 * own, and a host rule can therefore move, hide or shrink an element the design
 * placed — which is how `.row-actions` spent a round parked 129 987px off screen with
 * the row menu inside it, unreachable by mouse.
 *
 * This looks for the same pattern elsewhere before someone clicks it: it collects the
 * class names our stylesheet defines, collects the class names wp-admin's own
 * stylesheets define, and for every name in both it measures the elements we actually
 * rendered. A collision is only a suspicion; what matters is whether one of our
 * elements ends up somewhere a merchant cannot reach.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const ADMIN = `${ORIGIN}/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [ '--no-sandbox', `--unsafely-treat-insecure-origin-as-secure=${ORIGIN}` ],
} );
const page = await browser.newPage( { viewport: { width: 1440, height: 950 } } );

for ( const pair of [ process.env.WCCS_COOKIE, process.env.WCCS_AUTH_COOKIE ].filter( Boolean ) ) {
	const i = pair.indexOf( '=' );

	await page.context().addCookies( [ {
		name: pair.slice( 0, i ),
		value: pair.slice( i + 1 ),
		domain: 'wpagf.dvl.to',
		path: '/',
		expires: -1,
		httpOnly: true,
		secure: false,
		sameSite: 'Lax',
	} ] );
}

// The names the ported stylesheet defines.
const design = [
	...new Set(
		Array.from(
			readFileSync(
				'resources/admin/app/design/fields.css',
				'utf8'
			).matchAll( /\.([a-z][a-z0-9-]{2,})\b/g )
		).map( ( match ) => match[ 1 ] )
	),
];

const errors = [];

page.on( 'pageerror', ( e ) => errors.push( String( e.message ).slice( 0, 160 ) ) );
await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 1200 );

/**
 * Measures every element of the given classes that the screen rendered.
 *
 * @param {string[]} names Class names to look at.
 * @return {Promise<any[]>} One entry per class that is on screen.
 */
const measure = ( names ) =>
	page.evaluate( ( wanted ) => {
		const viewport = { w: window.innerWidth, h: window.innerHeight };

		return wanted
			.map( ( name ) => {
				const nodes = Array.from(
					document.querySelectorAll( `.wccs-admin .${ name }` )
				);

				if ( 0 === nodes.length ) {
					return null;
				}

				// Only what a merchant should be able to reach: an element inside a
				// closed dialog or an inactive tab is hidden on purpose, and counting
				// it would bury the real finding under honest ones. `offsetParent` is
				// null exactly when nothing renders the element.
				const bad = nodes.filter( ( node ) => {
					// `checkVisibility` answers for SVG too, where `offsetParent` does
					// not exist: an `<svg class="icon">` is not an HTMLElement, so the
					// property is undefined and every hidden icon looked reachable.
					const rendered =
						'function' === typeof node.checkVisibility
							? node.checkVisibility( {
									checkVisibilityCSS: true,
									checkOpacity: true,
							  } )
							: null !== node.offsetParent;

					if ( ! rendered ) {
						return false;
					}

					const box = node.getBoundingClientRect();

					return (
						box.right < 0 ||
						box.left > viewport.w ||
						box.bottom < 0 ||
						( 0 === box.width && 0 === box.height )
					);
				} );

				return {
					name,
					count: nodes.length,
					unreachable: bad.length,
					where: bad.length
						? Math.round( bad[ 0 ].getBoundingClientRect().x )
						: null,
				};
			} )
			.filter( Boolean )
			.filter( ( entry ) => entry.unreachable > 0 );
	}, names );

// Which of the design's names wp-admin's own stylesheets also describe.
const collisions = await page.evaluate( ( names ) => {
	const mine = new Set( names );
	const found = new Set();

	for ( const sheet of Array.from( document.styleSheets ) ) {
		const href = sheet.href ?? '';

		if ( ! href.includes( '/wp-admin/' ) && ! href.includes( '/wp-includes/' ) ) {
			continue;
		}

		let rules;

		try {
			rules = sheet.cssRules;
		} catch ( error ) {
			continue;
		}

		for ( const rule of Array.from( rules ?? [] ) ) {
			if ( ! rule.selectorText ) {
				continue;
			}

			for ( const match of rule.selectorText.matchAll(
				/\.([a-z][a-z0-9-]{2,})\b/g
			) ) {
				if ( mine.has( match[ 1 ] ) ) {
					found.add( match[ 1 ] );
				}
			}
		}
	}

	return Array.from( found ).sort();
}, design );

const report = {
	designNames: design.length,
	collisions,
	brokenInEditor: await measure( collisions ),
};

// And the same question on the other views, where the design puts its own blocks.
for ( const [ label, button ] of [
	[ 'archive', 'Arquivados' ],
	[ 'rules', 'Regras do editor' ],
	[ 'preview', 'Prévia do checkout' ],
] ) {
	await page.getByRole( 'button', { name: button } ).click();
	await page.waitForTimeout( 500 );
	report[ `brokenIn${ label }` ] = await measure( collisions );
}

report.errors = errors;
console.log( JSON.stringify( report, null, 1 ) );
await browser.close();
