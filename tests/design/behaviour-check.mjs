/**
 * Behaviour check: the design's interactions, driven in the real store.
 *
 * The screenshots compare how the two documents look; this compares what they do.
 * Every step below performs the interaction a merchant performs and records what the
 * store answered — the toast it announced, the order the rows came back in, the state
 * a control is in. Nothing is asserted here: the script reports, and the report is
 * what gets read. A step that throws records its failure instead of ending the run,
 * so one broken interaction cannot hide the state of the others.
 *
 * It expects a store with a draft that has at least two fields in one section and a
 * published revision; `seed-design-draft.php` plus one publication provide that pair.
 */
import { chromium } from 'playwright';

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

/** @type {Record<string, any>} */
const report = {};
const errors = [];

page.on( 'pageerror', ( e ) => errors.push( String( e.message ).slice( 0, 200 ) ) );

/**
 * Runs one step, recording what it observed or why it could not.
 *
 * @param {string}   name Step name.
 * @param {Function} run  Step body, answering with what was observed.
 * @return {Promise<void>} Resolves once the step is recorded.
 */
async function step( name, run ) {
	try {
		report[ name ] = await run();
	} catch ( error ) {
		report[ name ] = { failed: String( error.message ).slice( 0, 200 ) };
	}
}

/**
 * The labels of the rows on screen, in the order they are drawn.
 *
 * @return {string[]} Labels.
 */
const rowOrder = () =>
	page.$$eval( '.wccs-admin .field-row .field-name strong', ( nodes ) =>
		nodes.map( ( node ) => node.textContent )
	);

/**
 * The toast on screen, if there is one.
 *
 * @return {Promise<string>} Message, or an empty string.
 */
const toast = () =>
	page
		.locator( '.wccs-admin .toast' )
		.textContent( { timeout: 1500 } )
		.catch( () => '' );

await page.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
await page.waitForTimeout( 1200 );

await step( 'the shell', async () => ( {
	nav: await page.$$eval( '.wccs-admin .nav button span', ( nodes ) =>
		nodes.map( ( node ) => node.textContent )
	),
	topbar: await page.$$eval( '.wccs-admin .top-actions button', ( nodes ) =>
		nodes.map( ( node ) =>
			( node.getAttribute( 'aria-label' ) || node.textContent ).trim()
		)
	),
	blocks: await page.evaluate( () => {
		const wanted = [
			'.page-heading',
			'.contextbar',
			'.section-tabs',
			'.builder-header',
			'.filterbar',
			'.field-row',
			'.builder-footer',
			'.tip-card',
			'.editor-footnote',
			'.bottom-status',
			'.inspector-tabs',
		];

		return wanted.filter( ( selector ) =>
			document.querySelector( `.wccs-admin ${ selector }` )
		);
	} ),
} ) );

await page.locator( '.wccs-admin .section-tabs button' ).first().click();
await page.waitForTimeout( 400 );

await step( 'the editor opens on a section with rows', async () => ( {
	order: await rowOrder(),
	tabs: await page.$$eval( '.wccs-admin .section-tabs button', ( nodes ) =>
		nodes.map( ( node ) => node.textContent.trim() )
	),
	status: await page
		.locator( '.wccs-admin .bottom-status' )
		.textContent(),
} ) );

await step( 'a row moves with its handle and says where it landed', async () => {
	const before = await rowOrder();
	const handle = page.locator( '.wccs-admin .field-row .drag-handle' ).first();

	await handle.focus();
	await page.keyboard.press( 'Alt+ArrowDown' );
	await page.waitForTimeout( 300 );

	return {
		before,
		after: await rowOrder(),
		toast: await toast(),
		// The row keeps the focus, so the next Alt+Arrow moves the same field.
		focused: await page.evaluate( () => document.activeElement?.id ?? '' ),
	};
} );

await step( 'the keyboard shortcut moves it back', async () => {
	const handle = page
		.locator( '.wccs-admin .field-row .drag-handle' )
		.nth( 1 );

	await handle.focus();
	await page.keyboard.press( 'Alt+ArrowUp' );
	await page.waitForTimeout( 300 );

	return { order: await rowOrder(), toast: await toast() };
} );

await step( 'the mode switch announces what Blocks limits', async () => {
	await page
		.locator( '.wccs-admin .contextbar .segmented button' )
		.nth( 1 )
		.click();
	await page.waitForTimeout( 300 );

	const said = await toast();

	await page
		.locator( '.wccs-admin .contextbar .segmented button' )
		.nth( 0 )
		.click();
	await page.waitForTimeout( 200 );

	return { toast: said };
} );

await step( 'duplicating a field announces the undo that follows', async () => {
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.waitForTimeout( 200 );

	const count = await page.locator( '.wccs-admin .field-row' ).count();

	await page
		.locator( '.wccs-admin .row-menu-trigger' )
		.first()
		.click();
	await page
		.getByRole( 'button', { name: 'Duplicar como personalizado' } )
		.click();
	await page.waitForTimeout( 300 );

	const afterAdd = await page.locator( '.wccs-admin .field-row' ).count();

	await page
		.getByRole( 'button', { name: 'Desfazer alteração' } )
		.click();
	await page.waitForTimeout( 300 );

	const said = await toast();

	await page
		.getByRole( 'button', { name: 'Refazer alteração' } )
		.click();
	await page.waitForTimeout( 300 );

	const forward = await toast();

	return {
		before: count,
		afterAdd,
		undo: said,
		redo: forward,
		afterRedo: await page.locator( '.wccs-admin .field-row' ).count(),
	};
} );

await step( 'the properties open beside the list', async () => {
	await page.locator( '.wccs-admin .field-info' ).first().click();
	await page.waitForTimeout( 300 );

	return {
		tabs: await page.$$eval(
			'.wccs-admin .inspector-tabs button',
			( nodes ) => nodes.map( ( node ) => node.textContent )
		),
		head: await page
			.locator( '.wccs-admin .inspector-title h2' )
			.textContent(),
		footer: await page
			.locator( '.wccs-admin .inspector-footer' )
			.textContent(),
	};
} );

await step( 'the mask lives in the second tab', async () => {
	await page.locator( '.wccs-admin .inspector-tabs button' ).nth( 1 ).click();
	await page.waitForTimeout( 300 );

	return {
		note: await page
			.locator( '.wccs-admin .inspector-note' )
			.first()
			.textContent(),
		mask: await page
			.locator( '#wccs-field-mask' )
			.count(),
		conditions: await page.locator( '.wccs-conditions' ).count(),
	};
} );

await step( 'a bulk action asks before it changes anything', async () => {
	await page
		.locator( '.wccs-admin .field-row .row-check input' )
		.first()
		.click();
	await page.getByRole( 'button', { name: 'Desativar' } ).click();
	await page.waitForTimeout( 400 );

	const stats = await page.$$eval( '.wccs-admin .publish-stat', () => [] );
	const impact = await page
		.locator( '.wccs-admin .impact-list' )
		.count();
	const acknowledgement = await page
		.locator( '.wccs-admin .confirm-check' )
		.count();
	const confirm = page.getByRole( 'button', { name: 'Confirmar' } );
	const disabledWhileUnchecked = await confirm.isDisabled();

	if ( acknowledgement ) {
		await page.locator( '.wccs-admin .confirm-check input' ).check();
	}

	const enabledAfterChecking = await confirm.isEnabled();

	await confirm.click();
	await page.waitForTimeout( 400 );

	return {
		stats,
		impact,
		acknowledgement,
		disabledWhileUnchecked,
		enabledAfterChecking,
		toast: await toast(),
	};
} );

await step( 'the publication review and the history open', async () => {
	await page.getByRole( 'button', { name: 'Revisar publicação' } ).click();
	await page.waitForTimeout( 400 );

	const publish = {
		stats: await page.$$eval( '.wccs-admin .publish-stat', ( nodes ) =>
			nodes.map( ( node ) => node.textContent )
		),
		diffs: await page.$$eval( '.wccs-admin .diff-row', ( nodes ) =>
			nodes.map( ( node ) => node.textContent )
		),
	};

	await page
		.locator( '.wccs-dialog[open] .dialog-footer button' )
		.first()
		.click();
	await page.waitForTimeout( 400 );

	await page.getByRole( 'button', { name: 'Revisões' } ).click();
	await page.waitForTimeout( 400 );

	const history = await page.$$eval( '.wccs-admin .history-row', ( nodes ) =>
		nodes.map( ( node ) => node.textContent )
	);

	await page
		.locator( '.wccs-dialog[open] .dialog-footer button' )
		.first()
		.click();
	await page.waitForTimeout( 300 );

	return { publish, history };
} );

await step( 'the archive and the rules are views of the same document', async () => {
	await page.getByRole( 'button', { name: 'Arquivados' } ).click();
	await page.waitForTimeout( 400 );

	const archive = {
		rows: await page.locator( '.wccs-admin .archive-row' ).count(),
		empty: await page.locator( '.wccs-admin .empty-state' ).count(),
		saveStillThere: await page
			.getByRole( 'button', { name: 'Salvar rascunho' } )
			.count(),
	};

	await page.getByRole( 'button', { name: 'Regras do editor' } ).click();
	await page.waitForTimeout( 400 );

	const rules = await page.locator( '.wccs-admin .rule-card' ).count();

	await page.getByRole( 'button', { name: 'Editor de campos' } ).click();
	await page.waitForTimeout( 400 );

	return { archive, rules, backOnEditor: await rowOrder() };
} );

await step( 'the preview is generated from the draft', async () => {
	await page.getByRole( 'button', { name: 'Prévia do checkout' } ).click();
	await page.waitForTimeout( 600 );

	return {
		fields: await page.locator( '#previewView .public-field' ).count(),
		sections: await page.locator( '#previewView .store-section' ).count(),
		caption: await page
			.locator( '#previewView .preview-caption' )
			.textContent(),
		publishedDisabled: await page
			.getByRole( 'button', { name: 'Publicado' } )
			.isDisabled(),
	};
} );

await step( 'the properties open in a dialog on a narrow window', async () => {
	const mobile = await browser.newPage( { viewport: { width: 390, height: 844 } } );

	await mobile.context().addCookies( await page.context().cookies() );
	mobile.on( 'pageerror', ( e ) =>
		errors.push( String( e.message ).slice( 0, 160 ) )
	);
	await mobile.goto( ADMIN, { waitUntil: 'networkidle', timeout: 45000 } );
	await mobile.waitForTimeout( 900 );
	await mobile.locator( '.wccs-admin .field-info' ).first().click();
	await mobile.waitForTimeout( 400 );

	const observed = {
		column: await mobile.evaluate( () =>
			getComputedStyle(
				document.querySelector( '.wccs-admin .inspector' )
			).display
		),
		dialogOpen: await mobile.evaluate( () =>
			Boolean( document.querySelector( '.mobile-inspector' )?.open )
		),
		head: await mobile
			.locator( '.mobile-inspector-top strong' )
			.textContent(),
	};

	await mobile.close();

	return observed;
} );

await step( 'wp-admin keeps its footer out of the screen', async () => {
	// Measured on the editor, which is the view that has the strip at the bottom: on
	// the preview there is nothing to measure and the comparison would say "clear"
	// without comparing anything.
	await page.getByRole( 'button', { name: 'Editor de campos' } ).click();
	await page.waitForTimeout( 500 );

	const measured = await page.evaluate( () => {
		const footer = document.getElementById( 'wpfooter' );
		const strip = document.querySelector( '.wccs-admin .bottom-status' );

		return {
			footerPosition: footer ? getComputedStyle( footer ).position : '',
			footerTop: footer ? Math.round( footer.getBoundingClientRect().top ) : 0,
			stripBottom: strip
				? Math.round( strip.getBoundingClientRect().bottom )
				: 0,
		};
	} );

	return { ...measured, clear: measured.footerTop >= measured.stripBottom };
} );

console.log( JSON.stringify( { report, errors }, null, 1 ) );
await browser.close();
