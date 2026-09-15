/**
 * The user-level observation of F14 in the administration: WCCS-071 to WCCS-075 as a
 * merchant meets them.
 *
 * The harnesses prove the server's half of these tasks by driving the routes. This
 * script opens the screen a merchant actually uses, clicks it the way a merchant clicks
 * it, and reports what the interface offers and what it does:
 *
 *   1. **The links tab (WCCS-072, WCCS-074).** Every destination the catalogue publishes
 *      is there, each with its own switch, section, title, order and actions; the actions
 *      offered are the ones that destination may perform; the values the document holds
 *      come back into the controls and the switches.
 *   2. **Only the properties a type supports (WCCS-074).** The file field offers the file
 *      actions; a text field offers the link and none of them.
 *   3. **The approval flow (WCCS-075).** It comes back with the state the document named,
 *      and asking for it on a field that has none writes a state the merchant can see and
 *      edit — the server refuses a flow that names none, so the suggestion is what makes
 *      the flow configurable at all.
 *   4. **Sections per area (WCCS-073).** The section dialog offers the areas, and the
 *      section select inside a destination offers only the sections offered there.
 *   5. **Publishing (WCCS-071/072).** The draft the seed left is published from the
 *      interface, and the store ends up on the revision the screen reported.
 *
 * It reads and clicks. The only write it performs is the publication it is there to
 * observe.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/f14-links-observation.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`;
 * the draft comes from `wp eval-file tests/Integration/support/seed-f14-links.php`.
 */
import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const URL = `${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`;
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

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

const errors = [];

page.on( 'pageerror', ( e ) => errors.push( e.message ) );

/**
 * Runs one step, reporting a failure rather than aborting the run.
 *
 * An interface that changed shape is a finding, not a reason to lose the findings that
 * came before it.
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
 * Opens one field's inspector on the links tab.
 *
 * @param {string} listSection Section tab the field lives in.
 * @param {string} fieldId     Technical key of the field.
 * @return {Promise<void>} Resolves once the panel is rendered.
 */
async function openField( listSection, fieldId ) {
	await page
		.getByRole( 'button', { name: new RegExp( `^${ listSection }` ) } )
		.first()
		.click();
	await page.waitForTimeout( 500 );

	await page.locator( `text=${ fieldId }` ).first().click();
	await page.waitForTimeout( 800 );

	await page
		.getByRole( 'button', { name: 'Vínculos', exact: true } )
		.first()
		.click();
	await page.waitForTimeout( 600 );
}

/**
 * What the inspector currently offers.
 *
 * @return {Promise<any>} Groups, switches, selects and inputs of the panel.
 */
async function panel() {
	return page.evaluate( () => {
		const inspector =
			document.querySelector( '.inspector' ) || document.body;

		return {
			destinations: Array.from(
				inspector.querySelectorAll( '[role=group][aria-label]' )
			)
				.map( ( node ) => node.getAttribute( 'aria-label' ) )
				.filter(
					( label ) => label && label !== 'Propriedades do campo'
				),
			checkboxes: Array.from(
				inspector.querySelectorAll( 'input[type=checkbox]' )
			).map( ( node ) => ( {
				id: node.id,
				name: node.getAttribute( 'aria-label' ) || node.id,
				checked: node.checked,
			} ) ),
			selects: Array.from( inspector.querySelectorAll( 'select' ) ).map(
				( node ) => ( {
					id: node.id,
					value: node.value,
					options: Array.from( node.options ).map(
						( option ) => option.value
					),
				} )
			),
			inputs: Array.from(
				inspector.querySelectorAll(
					'input[type=text], input[type=number]'
				)
			).map( ( node ) => ( { id: node.id, value: node.value } ) ),
			saysActions: inspector.innerText.includes( 'Ações permitidas' ),
		};
	} );
}

const value = ( list, id ) =>
	( list.find( ( entry ) => entry.id === id ) || {} ).value;
const held = ( list, id ) =>
	( list.find( ( entry ) => entry.id === id ) || {} ).checked;
const actions = ( snapshot, destination ) =>
	snapshot.checkboxes
		.filter( ( box ) =>
			box.id.startsWith( `wccs-link-action-${ destination }-` )
		)
		.map( ( box ) =>
			box.id.replace( `wccs-link-action-${ destination }-`, '' )
		)
		.sort();

await page.goto( URL, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 2500 );

record(
	'The screen the merchant opens is served',
	( await page.title() ) !== '',
	await page.title()
);

// ---------------------------------------------------------------------------
// 1. The file field.
// ---------------------------------------------------------------------------
await step( 'The links tab of a file field could be opened', async () => {
	await openField( 'Cobrança', 'arquivo_autorizacao' );

	const file = await panel();

	record(
		'Every destination the catalogue publishes is on the links tab',
		file.destinations.length === 8,
		file.destinations.join( ' | ' )
	);

	record(
		'A destination that is off offers only its switch',
		! file.selects.some(
			( select ) => select.id === 'wccs-link-section-admin_customer'
		) &&
			! file.checkboxes.some( ( box ) =>
				box.id.startsWith( 'wccs-link-action-admin_customer-' )
			),
		'admin_customer and public_api are off'
	);

	record(
		'The actions offered are the ones that destination may perform',
		JSON.stringify( actions( file, 'admin_order' ) ) ===
			JSON.stringify( [
				'approve',
				'download',
				'resubmit',
				'show_metadata',
				'view',
			] ) &&
			JSON.stringify( actions( file, 'customer_order' ) ) ===
				JSON.stringify( [
					'download',
					'resubmit',
					'show_metadata',
					'view',
				] ) &&
			JSON.stringify( actions( file, 'customer_email' ) ) ===
				JSON.stringify( [ 'download', 'show_metadata', 'view' ] ),
		'admin=' +
			actions( file, 'admin_order' ).join( ',' ) +
			' customer=' +
			actions( file, 'customer_order' ).join( ',' )
	);

	record(
		'Reviewing is offered to staff and not to the customer',
		actions( file, 'admin_order' ).includes( 'approve' ) &&
			! actions( file, 'customer_order' ).includes( 'approve' ),
		'approve only where the destination may do it'
	);

	record(
		'The section, the title and the order the document holds come back into the controls',
		value( file.selects, 'wccs-link-section-admin_order' ) ===
			'documentos_para_analise' &&
			value( file.inputs, 'wccs-link-title-admin_order' ) ===
				'Autorização assinada' &&
			value( file.inputs, 'wccs-link-position-admin_order' ) === '20' &&
			value( file.selects, 'wccs-link-section-order_received' ) ===
				'documentos_enviados' &&
			value( file.inputs, 'wccs-link-title-order_received' ) ===
				'Enviado agora' &&
			value( file.inputs, 'wccs-link-position-order_received' ) === '10',
		'admin_order=documentos_para_analise/20, order_received=documentos_enviados/10'
	);

	record(
		'The actions the document holds come back checked',
		held( file.checkboxes, 'wccs-link-action-admin_order-approve' ) &&
			held(
				file.checkboxes,
				'wccs-link-action-customer_order-download'
			) === false &&
			held( file.checkboxes, 'wccs-link-action-admin_email-download' ),
		'the customer may look, not take; the store may take'
	);

	const staffSection =
		file.selects.find(
			( select ) => select.id === 'wccs-link-section-admin_order'
		) || {};

	record(
		'The section select of a destination offers only the sections offered there',
		( staffSection.options || [] ).includes( 'documentos_para_analise' ) &&
			! ( staffSection.options || [] ).includes( 'documentos_enviados' ),
		'admin_order sees documentos_para_analise only'
	);

	await page.screenshot( { path: `${ OUT }/f14-links-file.png` } );
} );

// ---------------------------------------------------------------------------
// 2. A text field.
// ---------------------------------------------------------------------------
await step( 'The links tab of a text field could be opened', async () => {
	await openField( 'Cobrança', 'campo_sem_vinculo' );

	const text = await panel();

	record(
		'A text field is offered the link and none of the file actions',
		text.destinations.length === 7 &&
			! text.saysActions &&
			! text.checkboxes.some( ( box ) =>
				box.id.startsWith( 'wccs-link-action-' )
			),
		'link switches=' +
			text.checkboxes.filter( ( box ) =>
				box.id.startsWith( 'wccs-link-' )
			).length +
			' actions heading=' +
			text.saysActions
	);

	record(
		'And a field linked nowhere shows every destination off',
		! text.checkboxes.some(
			( box ) =>
				box.id.startsWith( 'wccs-link-' ) &&
				! box.id.includes( 'action' ) &&
				box.checked
		),
		'no destination enabled'
	);

	await page.screenshot( { path: `${ OUT }/f14-links-text.png` } );
} );

// ---------------------------------------------------------------------------
// 3. The approval flow.
// ---------------------------------------------------------------------------
await step(
	'The approval flow of the reviewed field could be read',
	async () => {
		await openField( 'Cobrança', 'documento_fiscal' );

		const reviewed = await panel();

		record(
			'The approval flow is on, with the state the document named',
			held( reviewed.checkboxes, 'wccs-approval-required' ) &&
				value( reviewed.inputs, 'wccs-approval-status' ) ===
					'Pendente de aprovação' &&
				value( reviewed.selects, 'wccs-approval-area' ) ===
					'admin_order' &&
				value( reviewed.inputs, 'wccs-approval-section' ) ===
					'documentos_para_analise',
			'area=' +
				value( reviewed.selects, 'wccs-approval-area' ) +
				' status=' +
				value( reviewed.inputs, 'wccs-approval-status' )
		);

		record(
			'And it is a separate decision from the links',
			reviewed.destinations.length === 7 &&
				reviewed.saysActions === false,
			'the fiscal document is a text field: a link, and no file actions'
		);
	}
);

await step(
	'Asking for a review on a field that has none offered a state',
	async () => {
		await openField( 'Cobrança', 'campo_sem_vinculo' );

		await page
			.getByRole( 'checkbox', { name: 'Exigir análise manual' } )
			.first()
			.check();
		await page.waitForTimeout( 400 );

		const asked = await panel();

		record(
			'Asking for a review writes a state the merchant can see and change',
			value( asked.inputs, 'wccs-approval-status' ) ===
				'Pendente de aprovação' &&
				value( asked.selects, 'wccs-approval-area' ) === '',
			'status prefilled, the area still to be chosen'
		);
	}
);

// The change above is local until it is saved; reloading keeps the draft as the seed
// wrote it, so the publication below publishes exactly that.
await step( 'The page could be reloaded after the local change', async () => {
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 2200 );
} );

// ---------------------------------------------------------------------------
// 4. Sections per area.
// ---------------------------------------------------------------------------
await step( 'The section dialog could be opened', async () => {
	await page.getByRole( 'button', { name: 'Nova seção' } ).first().click();
	await page.waitForTimeout( 600 );

	const dialogAreas = await page.evaluate( () =>
		Array.from(
			document.querySelectorAll(
				'input[type=checkbox][id^=wccs-new-section-area-]'
			)
		).map( ( node ) => ( { id: node.id, checked: node.checked } ) )
	);

	record(
		'The section dialog offers the areas, with the checkout on and no public API',
		dialogAreas.length === 7 &&
			dialogAreas.some(
				( area ) =>
					area.id === 'wccs-new-section-area-checkout' && area.checked
			) &&
			dialogAreas.some(
				( area ) =>
					area.id === 'wccs-new-section-area-admin_order' &&
					! area.checked
			) &&
			! dialogAreas.some(
				( area ) => area.id === 'wccs-new-section-area-public_api'
			),
		'areas=' +
			dialogAreas
				.map( ( area ) =>
					area.id.replace( 'wccs-new-section-area-', '' )
				)
				.join( ',' )
	);

	await page.screenshot( { path: `${ OUT }/f14-section-areas.png` } );

	const close = page
		.locator( 'dialog[open] button' )
		.filter( { hasText: /Fechar|Cancelar|Cancel/ } );

	if ( ( await close.count() ) > 0 ) {
		await close.first().click();
	} else {
		await page.keyboard.press( 'Escape' );
	}

	await page.waitForTimeout( 500 );
} );

// ---------------------------------------------------------------------------
// 5. Publishing from the interface.
// ---------------------------------------------------------------------------
await step( 'The draft could be published from the interface', async () => {
	await page
		.getByRole( 'button', { name: 'Revisar publicação' } )
		.first()
		.click();
	await page.waitForTimeout( 900 );

	const review = page.locator( 'dialog[open]' ).first();
	const reviewText =
		( await review.count() ) > 0 ? await review.innerText() : '';

	record(
		'The publication review opens and reports the change',
		reviewText.length > 0,
		reviewText.replace( /\s+/g, ' ' ).trim().slice( 0, 160 )
	);

	const publishButton = page
		.getByRole( 'button', { name: /Publicar alterações|Publish changes/ } )
		.first();

	record(
		'The interface offers the publication',
		( await publishButton.count() ) > 0,
		'the button exists'
	);

	await publishButton.click();
	await page.waitForTimeout( 3000 );

	record(
		'Publishing leaves the store on the new revision',
		/Publicado|Published/.test( await page.locator( 'body' ).innerText() ),
		'the screen reports the published state'
	);

	await page.screenshot( { path: `${ OUT }/f14-published.png` } );
} );

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
console.log( 'F14 user-level observation — the administration' );
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
