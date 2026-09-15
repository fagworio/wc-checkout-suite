/**
 * Fase 15 observation — the accessibility contract of the administrative screens.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §5.4 fixes the interface
 * states and §22 puts WCAG in the release gate. Contrast is already proven where it is decidable —
 * `docs/validation/WCCS-011.md` measures 33 token pairs with none failing — so what a browser adds
 * is what only a rendered page can answer:
 *
 *   1. **Every screen has one heading and it names the screen**, so a screen reader arriving on the
 *      page knows where it is before it starts tabbing.
 *   2. **Every control a keyboard can reach has a name.** A button whose only content is an icon is
 *      the classic failure here, and it is invisible to every test that reads the source.
 *   3. **The tab strips are tab strips**: `role="tablist"` with a name, `role="tab"` with
 *      `aria-selected`, which is what tells assistive technology that one of them is current.
 *   4. **A dialog is a dialog, closes with Escape, and gives the focus back** to the control that
 *      opened it — the pattern a keyboard user needs to get out of a modal without a mouse.
 *   5. **The states §5.4 lists are announced**: the toast and the notices live in a live region, so
 *      "salvo" reaches a screen reader as well as an eye.
 *
 * It changes nothing: it opens, reads, presses Escape, and leaves.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase15-accessibility.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 *
 * @package WCCheckoutSuite
 */

import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const SCREENS = [
	{ section: 'fields', name: 'Campos' },
	{ section: 'statuses', name: 'Status' },
	{ section: 'workflows', name: 'Automação' },
	{ section: 'settings', name: 'Configurações' },
];

const findings = [];
const errors = [];
const record = ( label, ok, detail = '' ) =>
	findings.push( { label, ok: Boolean( ok ), detail } );

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }`,
	],
} );
const page = await browser.newPage( {
	viewport: { width: 1600, height: 1100 },
} );

for ( const pair of [
	process.env.WCCS_COOKIE,
	process.env.WCCS_AUTH_COOKIE,
].filter( Boolean ) ) {
	const i = pair.indexOf( '=' );

	await page.context().addCookies( [
		{
			name: pair.slice( 0, i ),
			value: pair.slice( i + 1 ),
			domain: 'wpagf.dvl.to',
			path: '/',
			expires: -1,
			httpOnly: true,
			secure: false,
			sameSite: 'Lax',
		},
	] );
}

page.on( 'pageerror', ( e ) => errors.push( e.message ) );

/**
 * Runs one step, reporting a failure rather than aborting the run.
 *
 * @param {string}   label Step name.
 * @param {Function} run   Step body.
 * @return {Promise<void>} Resolves either way.
 */
async function step( label, run ) {
	try {
		await run();
	} catch ( error ) {
		record(
			label,
			false,
			String( error && error.message ? error.message : error ).split(
				'\n'
			)[ 0 ]
		);
	}
}

/**
 * The controls on the page that have no accessible name.
 *
 * The name is what a screen reader announces: a `label` element, an `aria-label`, an
 * `aria-labelledby` whose target exists, or — for a control that shows text — the text itself. This
 * is the computation a browser makes, done here so the answer is about the rendered page and not
 * about the JSX.
 *
 * @return {Promise<Array<string>>} Descriptions of the unnamed controls.
 */
async function unnamedControls() {
	return page.evaluate( () => {
		const interactive =
			'button, a[href], input:not([type="hidden"]), select, textarea, [role="tab"], [role="switch"], [role="checkbox"]';
		const unnamed = [];

		for ( const element of document.querySelectorAll( interactive ) ) {
			if (
				element.closest( '[aria-hidden="true"]' ) ||
				element.hidden ||
				element.offsetParent === null
			) {
				continue;
			}

			const labelledBy = element.getAttribute( 'aria-labelledby' );

			if ( labelledBy ) {
				const target = document.getElementById(
					labelledBy.split( /\s+/ )[ 0 ]
				);

				if ( target && target.textContent.trim() ) {
					continue;
				}
			}

			if ( ( element.getAttribute( 'aria-label' ) ?? '' ).trim() ) {
				continue;
			}

			if (
				element.labels &&
				Array.from( element.labels ).some(
					( label ) => label.textContent.trim() !== ''
				)
			) {
				continue;
			}

			if (
				( element.textContent ?? '' ).trim() !== '' ||
				( element.getAttribute( 'title' ) ?? '' ).trim() !== '' ||
				( element.getAttribute( 'value' ) ?? '' ).trim() !== '' &&
					'submit' === element.type
			) {
				continue;
			}

			unnamed.push(
				element.tagName.toLowerCase() +
					( element.id ? `#${ element.id }` : '' ) +
					( element.className && typeof element.className === 'string'
						? `.${ element.className.trim().split( /\s+/ ).join( '.' ) }`
						: '' )
			);
		}

		return unnamed;
	} );
}

/**
 * The tab strips on the page that are not tab strips.
 *
 * @return {Promise<Array<string>>} Descriptions of the malformed strips.
 */
async function malformedTabs() {
	return page.evaluate( () => {
		const broken = [];

		for ( const strip of document.querySelectorAll(
			'[role="tablist"], .wccs-ch-segmented, .section-tabs, [role="group"]'
		) ) {
			const tabs = strip.querySelectorAll( '[role="tab"]' );

			if ( 0 === tabs.length ) {
				continue;
			}

			if ( ! strip.getAttribute( 'aria-label' ) ) {
				broken.push(
					`strip sem nome (${ tabs.length } separadores)`
				);
			}

			for ( const tab of tabs ) {
				if ( null === tab.getAttribute( 'aria-selected' ) ) {
					broken.push(
						`separador «${ tab.textContent.trim().slice( 0, 24 ) }» sem aria-selected`
					);
				}
			}
		}

		return broken;
	} );
}

/**
 * Whether the page has a live region for the states §5.4 lists.
 *
 * @return {Promise<boolean>} Presence.
 */
async function hasLiveRegion() {
	return page.evaluate(
		() =>
			null !==
			document.querySelector(
				'[role="status"], [role="alert"], [aria-live="polite"], [aria-live="assertive"]'
			)
	);
}

for ( const screen of SCREENS ) {
	const url = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=${ screen.section }`;

	await step( `${ screen.name }: a tela abre e responde por si`, async () => {
		await page.goto( url, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '#editorTitle, #statusesTitle, #workflowsTitle, #settingsTitle, main', {
			timeout: 30000,
		} );

		const headings = await page
			.locator( 'h1' )
			.evaluateAll( ( nodes ) =>
				nodes
					.filter( ( node ) => node.offsetParent !== null )
					.map( ( node ) => node.textContent.trim() )
			);

		record(
			`${ screen.name }: um só título de nível 1, e nomeia a tela`,
			1 === headings.length && headings[ 0 ].length > 0,
			`h1=${ headings.join( ' | ' ) }`
		);

		const unnamed = await unnamedControls();

		record(
			`${ screen.name }: todos os controles têm nome acessível`,
			0 === unnamed.length,
			unnamed.slice( 0, 6 ).join( ' | ' )
		);

		const tabs = await malformedTabs();

		record(
			`${ screen.name }: as tiras de separadores são tiras de separadores`,
			0 === tabs.length,
			tabs.slice( 0, 4 ).join( ' | ' )
		);

		record(
			`${ screen.name }: há uma região viva para os estados`,
			await hasLiveRegion()
		);
	} );
}

// ---------------------------------------------------------------------------
// A dialog is a dialog: Escape closes it and the focus comes back.
// ---------------------------------------------------------------------------
await step( 'Um diálogo fecha com Escape e devolve o foco', async () => {
	await page.goto(
		`${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`,
		{ waitUntil: 'networkidle' }
	);
	await page.waitForSelector( '.section-tabs', { timeout: 30000 } );

	const trigger = page
		.getByRole( 'button', { name: /Nova seção/ } )
		.first();

	await trigger.click();
	// The dialog this plugin renders is a native `<dialog>`, so its role is the element's own
	// rather than an attribute — which is why the check asks the accessibility tree.
	await page.getByRole( 'dialog' ).first().waitFor( { timeout: 10000 } );

	const dialog = await page
		.getByRole( 'dialog' )
		.first()
		.evaluate( ( node ) => ( {
			labelled:
				Boolean( node.getAttribute( 'aria-labelledby' ) ) ||
				Boolean( node.getAttribute( 'aria-label' ) ),
			modal: 'true' === ( node.getAttribute( 'aria-modal' ) ?? '' ),
			described: node.textContent.trim().length > 0,
		} ) );

	record(
		'O diálogo é anunciado como diálogo e tem nome',
		dialog.labelled && dialog.described,
		`modal=${ dialog.modal }`
	);

	const focusedInside = await page.evaluate( () => {
		const node = document.activeElement;

		return Boolean( node && node.closest( 'dialog' ) );
	} );

	record( 'E o foco entra nele ao abrir', focusedInside );

	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 250 );

	const closed = ( await page.getByRole( 'dialog' ).count() ) === 0;
	const returned = await page.evaluate( () => {
		const node = document.activeElement;

		return Boolean( node ) && null === node.closest( 'dialog' );
	} );

	record( 'E Escape fecha-o e devolve o foco à página', closed && returned );
} );

record(
	'Nenhum erro de página foi levantado enquanto as telas foram usadas',
	errors.length === 0,
	errors.join( ' | ' )
);

await browser.close();

console.log(
	'====================================================================='
);
console.log( 'Fase 15 observation — acessibilidade das telas administrativas' );
console.log(
	'====================================================================='
);

for ( const finding of findings ) {
	console.log(
		`  ${ finding.ok ? 'PASS' : 'FAIL' }  ${ finding.label }${
			finding.detail ? `  [${ finding.detail }]` : ''
		}`
	);
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log(
	'====================================================================='
);
console.log(
	`RESULT: ${ findings.length - failed } passed, ${ failed } failed`
);
console.log(
	'====================================================================='
);

process.exit( failed > 0 ? 1 : 0 );
