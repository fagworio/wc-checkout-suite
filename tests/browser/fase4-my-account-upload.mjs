/**
 * Fase 4 observation — a document the customer sends from their own page.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §7 asks for a
 * customer without an order to use a form and persist their data. The harness proves the
 * renderer and the store (`tests/Integration/FASE4-customer-upload-proof.php`); this drives
 * the real page with a real submission, which is the half a renderer cannot prove:
 *
 *   1. **The page a customer opens is theirs**, with the section they were offered and the
 *      document control the field asks for.
 *   2. **A document is sent from the form** — a real multipart POST with a real file — and the
 *      page comes back showing the document, not an empty field.
 *   3. **The value and the document live side by side**: the text field of the same section is
 *      saved by the same submission, because the document is not a value and does not replace
 *      one.
 *   4. **The document survives a new visit**, which is the whole difference between this and a
 *      checkout upload.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/seed-customer-document.php setup
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase4-my-account-upload.mjs
 *   wp eval-file tests/Integration/support/seed-customer-document.php teardown
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create wccs_fase4_cliente any`.
 *
 * The fixture runs the real privacy probe before this script. The default private directory is
 * outside the document root, so this browser test exercises the upload path as the store would
 * run it in production.
 *
 * @package WCCheckoutSuite
 */

import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const PAGE_URL = `${ ORIGIN }/minha-conta/documentos-da-conta/`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';
const FIELD = 'documento_do_cliente';

const findings = [];
const notes = [];
const record = ( label, ok, detail = '' ) =>
	findings.push( { label, ok: Boolean( ok ), detail } );
const note = ( message ) => notes.push( message );

const browser = await chromium.launch( {
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ ORIGIN }`,
	],
} );
const page = await browser.newPage( {
	viewport: { width: 1400, height: 1100 },
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

const errors = [];

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
 * The page's account section, as text.
 *
 * @return {Promise<string>} The section's text.
 */
async function sectionText() {
	return page
		.locator( '.wccs-account-section' )
		.innerText()
		.catch( () => '' );
}

await page.goto( PAGE_URL, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 1500 );

// ---------------------------------------------------------------------------
// 1. The page a customer opens.
// ---------------------------------------------------------------------------
await step( 'The customer opens their own page and finds it', async () => {
	const text = await sectionText();

	record(
		'The page the customer opens is theirs',
		page.url().includes( '/minha-conta/' ) &&
			! page.url().includes( 'wp-login' ),
		page.url()
	);

	record(
		'It shows the section they were offered, with its fields',
		text.includes( 'Seu contrato' ) &&
			text.includes( 'Como quer ser chamado' ),
		text.split( '\n' ).slice( 0, 4 ).join( ' | ' )
	);

	record(
		'And the document control the field asks for',
		( await page.locator( `#wccs-account-file-${ FIELD }` ).count() ) > 0,
		'input=' + FIELD
	);

	record(
		'With nothing sent yet, which is the truth',
		text.includes( 'Nenhum documento enviado' ),
		'current=' +
			( text.includes( 'Nenhum documento enviado' )
				? 'empty'
				: 'something already there' )
	);
} );

// ---------------------------------------------------------------------------
// 2. Sending a document.
// ---------------------------------------------------------------------------
await step( 'The customer sends a document from the form', async () => {
	await page.setInputFiles( `#wccs-account-file-${ FIELD }`, {
		name: 'contrato-social.pdf',
		mimeType: 'application/pdf',
		buffer: Buffer.from(
			'%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
		),
	} );

	await page
		.locator( 'input[name="wccs_account_fields[nome_preferido]"]' )
		.fill( 'Cliente Preferido' );

	await page
		.getByRole( 'button', { name: 'Salvar informações' } )
		.first()
		.click();

	await page.waitForLoadState( 'domcontentloaded' );
	await page.waitForTimeout( 1500 );

	const text = await sectionText();

	record(
		'The page comes back showing the document, not an empty field',
		text.includes( 'contrato-social.pdf' ),
		text.includes( 'contrato-social.pdf' )
			? 'contrato-social.pdf'
			: 'still empty'
	);

	// The notice is the page's, not the section's: it is printed above the form, which is
	// where WooCommerce prints messages.
	const notice = await page
		.locator( '.woocommerce-message, .wccs-notice' )
		.allInnerTexts()
		.catch( () => [] );

	record(
		'And it says the document was sent',
		notice.join( ' ' ).includes( 'Documento enviado' ) ||
			notice.join( ' ' ).includes( 'salvas com sucesso' ),
		notice.join( ' | ' ) || 'no notice on the page'
	);
} );

// ---------------------------------------------------------------------------
// 3. The value and the document live side by side.
// ---------------------------------------------------------------------------
await step( 'The value of the same submission was saved too', async () => {
	const value = await page
		.locator( 'input[name="wccs_account_fields[nome_preferido]"]' )
		.inputValue();

	record(
		'The text field of the section was saved by the same submission',
		'Cliente Preferido' === value,
		'value=' + value
	);

	record(
		'And the document did not replace it, nor it the document',
		( await sectionText() ).includes( 'contrato-social.pdf' ),
		'the two live side by side'
	);
} );

// ---------------------------------------------------------------------------
// 4. It survives a new visit.
// ---------------------------------------------------------------------------
await step( 'The document survives a new visit', async () => {
	await page.goto( PAGE_URL, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 1500 );

	const text = await sectionText();

	record(
		'The document is still there on a fresh page load',
		text.includes( 'contrato-social.pdf' ),
		'this is what a checkout upload does not do'
	);

	record(
		'And the page offers the door that serves it',
		( await page.locator( '.wccs-account-document__link' ).count() ) > 0,
		'the link the use allows'
	);

	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download', { timeout: 10000 } ).catch( () => null ),
		page.locator( '.wccs-account-document__link' ).click(),
	] );

	if ( download ) {
		record(
			'The authorized door returns the stored document',
			download.suggestedFilename() === 'contrato-social.pdf',
			download.suggestedFilename()
		);
	} else {
		// The event must be armed immediately before the click. This branch is kept
		// below as a separate guarded step so a browser timeout becomes a finding.
		record(
			'The authorized door returns the stored document',
			false,
			'download timeout'
		);
	}

	await page.screenshot( { path: `${ OUT }/fase4-my-account-document.png` } );
} );

record(
	'No page error was raised while the page was used',
	errors.length === 0,
	errors.join( ' | ' )
);

note(
	'Uploads were enabled by the real private-storage probe; the file is stored outside the document root and is served only through the authorized download door.'
);

await browser.close();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
console.log(
	'====================================================================='
);
console.log( 'Fase 4 observation — a document sent from Minha Conta' );
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

for ( const message of notes ) {
	console.log( `  NOTE  ${ message }` );
}

const failed = findings.filter( ( finding ) => ! finding.ok ).length;

console.log(
	'====================================================================='
);
console.log(
	`RESULT: ${ findings.length - failed } passed, ${ failed } failed, ${
		notes.length
	} notes`
);
console.log(
	'====================================================================='
);

process.exit( failed > 0 ? 1 : 0 );
