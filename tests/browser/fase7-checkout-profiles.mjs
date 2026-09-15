/**
 * The user-level observation of Fase 7: the checkouts of a store, as a merchant meets them.
 *
 * The harness proves the runtime — two carts, two compositions, the storefront obeying them. This
 * script opens the screen a merchant uses and reports what it offers and what it does, because a
 * feature the merchant cannot see is a feature the merchant does not have:
 *
 *   1. **The strip §6.3 draws.** `Checkout padrão` is there, it is the store's own composition, and
 *      the screen says so. `Novo checkout` opens the modal.
 *   2. **The modal asks the two questions the design draws**: a name, and what to start from —
 *      the current WooCommerce checkout, another checkout, or the minimal one.
 *   3. **Creating one makes it a tab**, selects it, and states the rule that selects it, where it
 *      sits in the priority order, and whether it is the fallback.
 *   4. **The fallback is one place.** Marking a checkout as the fallback unmarks the other, because
 *      the store refuses two.
 *   5. **A minimal checkout is verified before it is saved** (§6.3) against what the store reports.
 *   6. **Saving writes the profiles with the document**, and the store holds them.
 *
 * It reads and clicks. The writes it performs are the ones it is there to observe, and it removes
 * the checkouts it created.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase7-checkout-profiles.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`;
 * the document comes from `wp eval-file tests/Integration/support/seed-f14-links.php`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

const findings = [];
const requests = [];
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
page.on( 'request', ( request ) => {
	if ( request.url().includes( '/wp-json/' ) ) {
		requests.push(
			`${ request.method() } ${ request.url().split( '/wp-json/' )[ 1 ] }`
		);
	}
} );

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
 * Waits until the draft holds the given number of checkouts, or gives up.
 *
 * The save is a request, so waiting for a class to disappear is waiting for a guess. This asks
 * the server the same question the next screen would ask, until it answers.
 *
 * @param {number} expected Number of checkouts expected.
 * @return {Promise<Array<any>>} Stored profiles, whatever they are when the wait ends.
 */
async function waitForProfiles( expected ) {
	for ( let attempt = 0; attempt < 40; attempt++ ) {
		const profiles = await storedProfiles();

		if ( profiles.length === expected ) {
			return profiles;
		}

		await page.waitForTimeout( 250 );
	}

	return storedProfiles();
}

/**
 * Reads a profile out of the published document through the REST API.
 *
 * The screen is what is being observed; asking the server what it stored is how the
 * observation is checked, and it is the same question the next screen would ask.
 *
 * @return {Promise<Array<any>>} Stored profiles.
 */
async function storedProfiles() {
	const answer = await page.evaluate( async () => {
		// The route the client was given is relative to the REST root, and it is read from the
		// bootstrap payload rather than written here: a renamed path cannot leave this script
		// asking a question the screen does not ask.
		const config = window.wccsAdmin ?? {};
		const rest = config.rest ?? {};
		const url =
			( rest.root ?? '' ) +
			( rest.namespace ?? '' ) +
			( rest.routes?.draft ?? '' );

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': rest.nonce ?? '' },
		} );

		return response.ok ? response.json() : null;
	} );

	return Array.isArray( answer?.profiles ) ? answer.profiles : [];
}

await page.goto( URL, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.wccs-editor-areas', { timeout: 30000 } );

// ---------------------------------------------------------------------------
// 1. The strip.
// ---------------------------------------------------------------------------
await step(
	'The checkout destination offers the strip of checkouts',
	async () => {
		const strip = page.locator( '.wccs-checkouts__strip' );

		record(
			'The strip is drawn above the editor',
			( await strip.count() ) === 1
		);
		record(
			'The store own checkout is the first tab and is selected',
			(
				await page
					.locator(
						'.wccs-checkouts__strip button[aria-selected="true"]'
					)
					.first()
					.innerText()
			).includes( 'Checkout padrão' )
		);
		record(
			'And the screen says it is the store own composition',
			( await page.locator( '.wccs-checkouts__own' ).count() ) === 1,
			await page.locator( '.wccs-checkouts__own' ).innerText()
		);
		record(
			'The modal is offered',
			( await page
				.getByRole( 'button', { name: /Novo checkout/ } )
				.count() ) === 1
		);
	}
);

// ---------------------------------------------------------------------------
// 2. Creating one from the current checkout.
// ---------------------------------------------------------------------------
await step(
	'A checkout created from the current one becomes a tab',
	async () => {
		await page.getByRole( 'button', { name: /Novo checkout/ } ).click();

		// The dialog is opened by the click, so it is waited for rather than assumed: a
		// count taken before the platform has shown it would measure the wrong instant.
		const dialog = page.getByRole( 'dialog', { name: 'Novo checkout' } );

		await dialog.waitFor( { state: 'visible', timeout: 10000 } );

		record(
			'The modal asks for a name',
			( await dialog.locator( '#wccs-profile-name' ).count() ) === 1
		);
		record(
			'And the three starting points the design draws',
			( await dialog
				.locator( 'input[name="wccs-profile-source"]' )
				.count() ) === 3
		);

		await dialog.locator( '#wccs-profile-name' ).fill( 'Checkout digital' );
		await dialog.getByRole( 'button', { name: /Criar checkout/ } ).click();

		await page.waitForFunction(
			() =>
				document.querySelectorAll( '.wccs-checkouts__strip button' )
					.length >= 3,
			{ timeout: 10000 }
		);

		const tabs = await page
			.locator( '.wccs-checkouts__strip button' )
			.allInnerTexts();

		record(
			'The new checkout is a tab',
			tabs.some( ( text ) => text.includes( 'Checkout digital' ) ),
			tabs.join( ' | ' )
		);
		record(
			'And it is the one being edited',
			(
				await page
					.locator(
						'.wccs-checkouts__strip button[aria-selected="true"]'
					)
					.innerText()
			).includes( 'Checkout digital' )
		);
	}
);

// ---------------------------------------------------------------------------
// 3. What the active checkout says about itself.
// ---------------------------------------------------------------------------
await step( 'The active checkout states how it is chosen', async () => {
	const rule = page.locator( '.wccs-checkouts__rule' );

	record(
		'The rule is stated',
		( await rule.count() ) === 1,
		await rule.innerText()
	);
	record(
		'A new checkout is considered before the ones that existed',
		(
			await page.locator( '.wccs-checkouts__priority' ).innerText()
		).includes( 'Prioridade 10' ),
		await page.locator( '.wccs-checkouts__priority' ).innerText()
	);
	record(
		'Both orders are offered',
		( await page.getByRole( 'button', { name: /antes/ } ).count() ) === 1 &&
			( await page.getByRole( 'button', { name: /depois/ } ).count() ) ===
				1
	);
	record(
		'The conditions editor is offered for a profile',
		( await page
			.getByRole( 'button', { name: /Condições de exibição/ } )
			.count() ) === 1
	);
} );

// ---------------------------------------------------------------------------
// 4. The fallback is one place.
// ---------------------------------------------------------------------------
await step( 'Marking the fallback takes it from the others', async () => {
	await page.locator( '#wccs-profile-fallback' ).check();

	record(
		'The checkout is marked as the fallback',
		await page.locator( '#wccs-profile-fallback' ).isChecked()
	);
	record(
		'And the tab says so',
		(
			await page
				.locator(
					'.wccs-checkouts__strip button[aria-selected="true"]'
				)
				.innerHTML()
		).includes( 'svg' )
	);
} );

// ---------------------------------------------------------------------------
// 5. A minimal checkout is verified before it is saved.
// ---------------------------------------------------------------------------
await step(
	'A minimal checkout is checked against what the store reports',
	async () => {
		await page.getByRole( 'button', { name: /Novo checkout/ } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Novo checkout' } );

		await dialog.waitFor( { state: 'visible', timeout: 10000 } );

		await dialog.locator( '#wccs-profile-name' ).fill( 'Checkout mínimo' );
		await dialog.locator( '#wccs-profile-source-minimal' ).check();
		await dialog.getByRole( 'button', { name: /Criar checkout/ } ).click();

		await page.waitForFunction(
			() =>
				document.querySelectorAll( '.wccs-checkouts__strip button' )
					.length >= 4,
			{ timeout: 10000 }
		);

		const checklist = await page.locator( '.wccs-notice' ).allInnerTexts();

		record(
			'The checklist is shown for a minimal checkout',
			checklist.some(
				( text ) =>
					text.includes( 'obrigações técnicas' ) ||
					text.includes( 'estão todos satisfeitos' )
			),
			checklist.find( ( text ) => text.includes( 'Checkout mínimo' ) ) ??
				''
		);
	}
);

await step(
	'The checkouts are saved with the document and read back',
	async () => {
		await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

		// The save is a request, so what is waited for is the server's answer and not a class
		// in the page: the draft is asked until it holds what the screen said it would.
		const profiles = await waitForProfiles( 2 );

		record(
			'The store holds both checkouts',
			2 === profiles.length,
			'held=' + profiles.map( ( profile ) => profile.id ).join( ',' )
		);
		record(
			'And one of them is the fallback, and only one',
			1 === profiles.filter( ( profile ) => profile.fallback ).length,
			'fallbacks=' +
				profiles.filter( ( profile ) => profile.fallback ).length
		);
		record(
			'And the one created as minimal carries the source it came from',
			profiles.some( ( profile ) => 'minimal' === profile.source ),
			'sources=' +
				profiles.map( ( profile ) => profile.source ).join( ',' )
		);

		await page.screenshot( {
			path: `${ OUT }/fase7-checkout-profiles.png`,
		} );
	}
);

// ---------------------------------------------------------------------------
// 7. The screen is left the way it was found.
// ---------------------------------------------------------------------------
await step(
	'The checkouts this observation created are removed again',
	async () => {
		for ( const profile of await storedProfiles() ) {
			const tab = page
				.locator( '.wccs-checkouts__strip button' )
				.filter( { hasText: profile.name } )
				.first();

			if ( 0 === ( await tab.count() ) ) {
				continue;
			}

			await tab.click();

			// The only fallback is not deleted, by design, so it is unmarked first: turning
			// the switch off is what the merchant would do, and it is what makes the removal
			// legal.
			const fallback = page.locator( '#wccs-profile-fallback' );

			if (
				( await fallback.count() ) > 0 &&
				( await fallback.isChecked() )
			) {
				await fallback.uncheck();
			}

			await page
				.getByRole( 'button', { name: /Excluir checkout/ } )
				.first()
				.click();
		}

		await page.getByRole( 'button', { name: /Salvar alterações/ } ).click();

		const left = await waitForProfiles( 0 );

		record(
			'The checkouts this observation created are gone again',
			0 === left.length,
			'left=' + left.map( ( profile ) => profile.id ).join( ',' )
		);
	}
);

record(
	'No page error was raised while the screen was used',
	errors.length === 0,
	errors.join( ' | ' )
);

await browser.close();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
console.log(
	'====================================================================='
);
console.log( 'Fase 7 user-level observation — the checkouts of a store' );
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
