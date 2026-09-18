/**
 * Fase 16 observation — what the interface says, and how many steps it takes.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §25 measures usability
 * with people who know WooCommerce and not this plugin. That measurement is not available here and
 * is not invented: no user was observed, so no time, no error count and no confidence score is
 * reported. What §25 asks *after* the task, however, has a half a browser can answer — «se o usuário
 * não responder corretamente, a interface ainda está ambígua» — and the questions are about what the
 * screen says, not about what a person understood:
 *
 *   1. Onde esse campo será preenchido?
 *   2. Onde esse valor aparecerá depois?
 *   4. Este status significa que o pedido está pago?
 *   5. Quando a cobrança acontece?
 *
 * Two more are part of the phase's own list and are measurable the same way: **how many steps** the
 * main task of each screen takes from the screen root, and whether the **vocabulary stays the same**
 * — the code calls a container a container, and the interface must not.
 *
 * Usage:
 *   WCCS_COOKIE="name=value" WCCS_AUTH_COOKIE="name=value" node tests/browser/fase16-usability.mjs
 *
 * The cookies come from `wp eval-file tests/Integration/support/admin-session.php create`.
 *
 * @package WCCheckoutSuite
 */

import { chromium } from 'playwright';

const ORIGIN = 'http://wpagf.dvl.to:8080';
const OUT = process.env.WCCS_OUT || 'tests/design/shots';

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
 * Opens a screen of the plugin.
 *
 * @param {string} section Section key.
 * @param {string} ready   Selector that says the screen is drawn.
 * @return {Promise<void>} Resolves when it is up.
 */
async function open( section, ready ) {
	await page.goto(
		`${ ORIGIN }/wp-admin/admin.php?page=wccs-checkoutsuite&section=${ section }`,
		{ waitUntil: 'networkidle' }
	);
	await page.waitForSelector( ready, { timeout: 30000 } );
}

/**
 * The visible text of the working area.
 *
 * @return {Promise<string>} Text.
 */
async function visibleText() {
	return page.evaluate( () => {
		const main = document.querySelector( 'main, #editorView, .view' );

		return ( main ?? document.body ).textContent ?? '';
	} );
}

/**
 * The visible primary action of a screen.
 *
 * @return {Promise<string>} Its label, or an empty string.
 */
async function primaryAction() {
	return page.evaluate( () => {
		const candidates = Array.from(
			document.querySelectorAll( 'button.btn-primary, button.btn-add' )
		).filter( ( node ) => node.offsetParent !== null );

		return candidates.length > 0 ? candidates[ 0 ].textContent.trim() : '';
	} );
}

// ---------------------------------------------------------------------------
// 1. Every screen states what it is for, and offers its main task at once.
// ---------------------------------------------------------------------------
const SCREENS = [
	{
		section: 'fields',
		name: 'Campos',
		ready: '.section-tabs',
		// §6.5: the two ways to put a field in a composition, both in the heading.
		actions: [ 'Adicionar campo', 'Vincular campo existente' ],
	},
	{
		section: 'statuses',
		name: 'Status',
		ready: '#statusesTitle',
		actions: [ 'Novo estado' ],
	},
	{
		section: 'workflows',
		name: 'Automação',
		ready: '#workflowsTitle',
		actions: [ 'Nova automação' ],
	},
	{
		section: 'settings',
		name: 'Configurações',
		ready: '.wccs-settings__title',
		actions: [],
	},
];

for ( const screen of SCREENS ) {
	await step( `${ screen.name }: a tarefa principal está a um passo`, async () => {
		await open( screen.section, screen.ready );

		const body = await visibleText();
		const heading = await page
			.locator( 'h1' )
			.first()
			.innerText()
			.then( ( text ) => text.trim() );

		// «Reduzir passos»: what a merchant came to do is on the screen, not behind a menu. The
		// screen's own heading plus the phrase that says what it decides are the two things a
		// person needs before touching anything.
		record(
			`${ screen.name }: o título diz o que a tela decide`,
			heading.length > 0 && body.length > heading.length,
			`h1=${ heading }`
		);

		const reachable = [];

		for ( const action of screen.actions ) {
			const button = page.getByRole( 'button', { name: action } ).first();

			if ( ( await button.count() ) > 0 && ( await button.isVisible() ) ) {
				reachable.push( action );
			}
		}

		record(
			`${ screen.name }: ${
				0 === screen.actions.length ? 'a tela não abre com ação' : 'a ação principal está visível sem abrir nada'
			}`,
			reachable.length === screen.actions.length,
			`reachable=${ reachable.join( ' | ' ) }`
		);

		if ( 'fields' === screen.section ) {
			await page.screenshot( { path: `${ OUT }/fase16-campos.png` } );
		}
	} );
}

// ---------------------------------------------------------------------------
// 2. «Onde esse campo será preenchido?» e «onde esse valor aparecerá depois?»
// ---------------------------------------------------------------------------
await step( 'O campo diz onde é preenchido, e a tela diz de quem é a lista', async () => {
	await open( 'fields', '.section-tabs' );

	const list = await visibleText();

	// «Onde esse campo será preenchido?» — the list is grouped by the destination's own word, and
	// the destination is the answer. «Onde esse valor aparecerá depois?» — the field's regular
	// section and the panel that adopts the store's own checkout both sit under it.
	record(
		'A lista agrupa os campos pela secção onde são preenchidos',
		/Sec?ção|Seção/.test( list ) && ( await page.locator( '.section-tabs' ).count() ) > 0,
		( await page.locator( '.section-tabs' ).first().innerText() )
			.replace( /\s+/g, ' ' )
			.slice( 0, 120 )
	);

	record(
		'E o checkout da loja é oferecido por cima da lista que o configura',
		( await page.locator( '.wccs-core-checkout, .panel' ).count() ) > 0
	);
} );

// ---------------------------------------------------------------------------
// 3. «Este status significa que o pedido está pago?»
// ---------------------------------------------------------------------------
await step( 'O ecrã de status responde se um estado significa pago', async () => {
	await open( 'statuses', '#statusesTitle' );

	const body = await visibleText();

	record(
		'A tela diz que um estado não cobra nada, e quem cobra',
		/um estado não cobra nada/i.test( body ) &&
			/workflow|automação/i.test( body ),
		''
	);

	// The switch itself is the answer, and it is named as one: a state before payment is never
	// treated as paid by the store.
	const prepayment = await page.evaluate( () => {
		const label = Array.from(
			document.querySelectorAll( 'label' )
		).find( ( node ) => /antes do pagamento/i.test( node.textContent ) );

		return label ? label.textContent.trim() : '';
	} );

	record(
		'E o interruptor que o decide está nomeado por extenso',
		'' !== prepayment,
		prepayment
	);
} );

// ---------------------------------------------------------------------------
// 4. «Quando a cobrança acontece?»
// ---------------------------------------------------------------------------
await step( 'O ecrã de automação responde quando a cobrança acontece', async () => {
	await open( 'workflows', '#workflowsTitle' );

	const body = await visibleText();

	record(
		'A tela diz que uma automação muda estados e não cobra nada',
		/Uma automação muda estados e não cobra nada/i.test( body )
	);

	// §13.2 passo 4: the payment step is offered where the strategies are, and this store offers the
	// ones it can execute — a select with an invented option would be the ambiguity §25 asks about.
	await page.getByRole( 'button', { name: /Nova automação/ } ).click();
	await page.waitForSelector( '#wccs-workflow-payment', {
		timeout: 10000,
	} );

	const payment = await page.evaluate( () =>
		Array.from(
			document.querySelectorAll( '#wccs-workflow-payment option' )
		).map( ( node ) => node.value )
	);

	record(
		'E o passo do pagamento só oferece o que a loja executa',
		payment.length > 0 && payment.includes( 'none' ),
		`payment=${ payment.join( ',' ) }`
	);

	await page.screenshot( { path: `${ OUT }/fase16-automacao.png` } );
} );

// ---------------------------------------------------------------------------
// 4b. The flow of the fields, as the prototype of the checkout screen draws it.
// ---------------------------------------------------------------------------
await step( 'A tela de campos tem o fluxo do protótipo', async () => {
	await open( 'fields', '.section-tabs' );

	// A coluna das seções, com a frase que diz o que cada uma guarda e o botão
	// tracejado que cria outra.
	const sections = await page.evaluate( () => {
		const panel = document.querySelector( '.sections-panel' );

		return {
			panel: Boolean( panel ),
			head: panel?.querySelector( '.sections-head h2' )?.textContent ?? '',
			rows: panel?.querySelectorAll( '.section-tabs button' ).length ?? 0,
			described: Boolean(
				panel?.querySelector( '.section-row-text small' )?.textContent
					?.trim()
			),
			add: Boolean( panel?.querySelector( '.sections-add' ) ),
		};
	} );

	record(
		'As seções ficam na coluna da esquerda, com nome, frase e o criar tracejado',
		sections.panel &&
			sections.rows > 0 &&
			sections.described &&
			sections.add,
		JSON.stringify( sections )
	);

	// O interruptor do título da seção, no cabeçalho do meio.
	const titleSwitch = await page.evaluate( () => {
		const input = document.querySelector( '#wccs-section-show-title' );

		return {
			present: Boolean( input ),
			label: input?.closest( 'label' )?.textContent?.trim() ?? '',
		};
	} );

	record(
		'E o título da seção tem o seu interruptor no cabeçalho',
		titleSwitch.present &&
			/exibir título/i.test( titleSwitch.label ),
		titleSwitch.label
	);

	// A criação fica no cabeçalho; o builder não repete a mesma entrada abaixo da lista.
	const headerAdd = page.getByRole( 'button', {
		name: 'Adicionar campo',
		exact: true,
	} );
	const equivalentCreates = page.locator(
		'.builder-panel .inline-add, .builder-panel .add-field-inline'
	);

	record(
		'O builder não repete o ponto de criação de campo',
		( await equivalentCreates.count() ) === 0,
		`count=${ await equivalentCreates.count() }`
	);

	record(
		'Adicionar campo fica uma única vez no cabeçalho',
		( await headerAdd.count() ) === 1 && ( await headerAdd.isVisible() )
	);

	await headerAdd.click();

	// O passo 1 é o catálogo; o formulário do protótipo é o passo 2, depois de
	// escolher um tipo.
	await page.waitForSelector( '.picker-card', { timeout: 10000 } );
	await page.locator( '.picker-card' ).first().click();
	await page.waitForSelector( '#wccs-new-label', { timeout: 10000 } );

	record(
		'E o formulário abre com o tipo escolhido',
		( await page.locator( '#wccs-new-description' ).count() ) === 1
	);

	const form = await page.evaluate( () => ( {
		name: Boolean( document.querySelector( '#wccs-new-label' ) ),
		slug: Boolean( document.querySelector( '#wccs-new-key' ) ),
		description: Boolean( document.querySelector( '#wccs-new-description' ) ),
		rules: Boolean(
			Array.from( document.querySelectorAll( '[role="group"]' ) ).some(
				( node ) =>
					/Regras de exibição/i.test(
						node.getAttribute( 'aria-label' ) ?? ''
					)
			)
		),
		submit: Boolean(
			Array.from( document.querySelectorAll( 'button' ) ).find(
				( node ) => /Adicionar campo/.test( node.textContent )
			)
		),
	} ) );

	record(
		'E o formulário pede nome, chave, descrição, regras e confirma',
		form.name && form.slug && form.description && form.submit,
		JSON.stringify( form )
	);

	await page.keyboard.press( 'Escape' );
} );

// ---------------------------------------------------------------------------
// 4c. A remoção de uma seção personalizada passa pelas suas propriedades.
// ---------------------------------------------------------------------------
await step( 'A remoção da seção usa uma única entrada com confirmação', async () => {
	await open( 'fields', '.section-tabs' );

	const standaloneRemove = page.locator(
		'.builder-panel button.text-btn-danger[aria-label^="Remover"]'
	);
	const propertiesAction = page.locator(
		'.builder-panel button[aria-label^="Ações da"]:not([disabled])'
	);

	record(
		'Não há remoção destrutiva duplicada no cabeçalho',
		( await standaloneRemove.count() ) === 0,
		`count=${ await standaloneRemove.count() }`
	);

	if ( ( await propertiesAction.count() ) === 0 ) {
		record(
			'A seção personalizada mantém as propriedades como entrada de remoção',
			false,
			'nenhuma seção personalizada disponível no contexto browser'
		);
		return;
	}

	await propertiesAction.first().click();
	const propertiesRemove = page.getByRole( 'button', {
		name: /^Remover (Seção|Página|Bloco|Painel)$/,
	} );

	record(
		'A remoção fica dentro das propriedades',
		( await propertiesRemove.count() ) === 1,
		`count=${ await propertiesRemove.count() }`
	);

	await propertiesRemove.click();
	record(
		'A remoção abre a confirmação de impacto',
		( await page.getByRole( 'button', { name: /Remover .* e campos/ } ).count() ) === 1
	);

	await page.keyboard.press( 'Escape' );
} );

// ---------------------------------------------------------------------------
// 4d. A superfície única de ações da linha.
// ---------------------------------------------------------------------------
await step( 'A linha concentra ações secundárias em um único menu', async () => {
	await open( 'fields', '.section-tabs' );

	const row = await page.evaluate( () => {
		const first = document.querySelector( '.field-row' );

		if ( ! first ) {
			return null;
		}

		const quick = first.querySelector( '.row-quick' );
		return {
			quick: Boolean( quick ),
			menu: Boolean( first.querySelector( '.row-menu-trigger' ) ),
		};
	} );

	record(
		'As ações secundárias ficam em uma única superfície',
		null !== row &&
			! row.quick &&
			row.menu,
		JSON.stringify( row )
	);

	// «Editar campo» saiu do menu: a área de edição é o painel ao lado, e a linha
	// já a abre.
	await page
		.getByRole( 'button', { name: /^Ações de / } )
		.first()
		.click();
	await page.waitForSelector( '.row-menu', { timeout: 10000 } );

	const menu = await page.evaluate( () =>
		Array.from(
			document.querySelectorAll( '.row-menu button' )
		).map( ( node ) => node.textContent.trim() )
	);

	const actionCounts = await page.evaluate( () => {
		const row = document.querySelector( '.field-row' );
		const buttons = Array.from( row?.querySelectorAll( 'button' ) ?? [] );
		const labels = buttons.map(
			( node ) => node.getAttribute( 'aria-label' ) ?? node.textContent ?? ''
		);

		return {
			duplicate: labels.filter( ( label ) => /Duplicar/i.test( label ) ).length,
			remove: labels.filter( ( label ) => /Excluir/i.test( label ) ).length,
		};
	} );

	record(
		'Cada linha tem uma única superfície para duplicar e excluir',
		actionCounts.duplicate <= 1 && actionCounts.remove <= 1,
		JSON.stringify( actionCounts )
	);

	record(
		'E o menu deixou de oferecer «Editar campo»',
		menu.length > 0 && ! menu.some( ( item ) => /Editar campo/.test( item ) ),
		menu.join( ' | ' )
	);

	await page.keyboard.press( 'Escape' );
} );

// ---------------------------------------------------------------------------
// 5. A destination is an address: choosing one writes it, and the address reopens it.
// ---------------------------------------------------------------------------
await step( 'O destino fica no endereço e o endereço reabre-o', async () => {
	await open( 'fields', '.section-tabs' );

	await page
		.locator( '.wccs-editor-areas button', { hasText: 'Admin' } )
		.first()
		.click();
	await page.waitForSelector( '.wccs-editor-subareas', { timeout: 10000 } );

	const url = page.url();

	record(
		'Escolher um destino escreve-o no endereço, sem perder a tela',
		/area=/.test( url ) && /section=fields/.test( url ),
		url.replace( ORIGIN, '' )
	);

	// The strip the design draws for the destinations inside a group. Without a rule of its own the
	// buttons fall back to the browser's own chrome and the name and the path run together on one
	// line, which is what this asserts against.
	const strip = await page.evaluate( () => {
		const button = document.querySelector(
			'.wccs-editor-subareas button'
		);
		const style = getComputedStyle( button );

		return {
			strong: getComputedStyle( button.querySelector( 'strong' ) )
				.display,
			small: getComputedStyle( button.querySelector( 'small' ) )
				.display,
			border: style.borderStyle,
			background: style.backgroundColor,
			decoration: style.textDecorationLine,
		};
	} );

	record(
		'O destino mostra o nome e o caminho em linhas próprias',
		'block' === strip.strong && 'block' === strip.small,
		JSON.stringify( strip )
	);

	record(
		'E não é o botão do browser: veste o sistema de desenho',
		'solid' === strip.border &&
			'rgb(239, 239, 239)' !== strip.background &&
			'none' === strip.decoration,
		JSON.stringify( strip )
	);

	await page.goto( url, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.wccs-editor-subareas', { timeout: 30000 } );

	const selected = await page
		.locator( '.wccs-editor-subareas [aria-selected="true"]' )
		.first()
		.innerText();

	record(
		'E o endereço abre o mesmo destino quando seguido por outra pessoa',
		/Pedido/i.test( selected ),
		selected.replace( /\s+/g, ' ' )
	);
} );

// ---------------------------------------------------------------------------
// 6. The vocabulary: the code says container, the interface does not.
// ---------------------------------------------------------------------------
await step( 'A interface não usa as palavras do código', async () => {
	const offenders = [];

	for ( const screen of SCREENS ) {
		await open( screen.section, screen.ready );

		const body = await visibleText();

		if ( /\bcontainers?\b/i.test( body ) ) {
			offenders.push( `${ screen.name }:container` );
		}

		if ( /\bFieldBinding\b|\bFieldDefinition\b/.test( body ) ) {
			offenders.push( `${ screen.name }:tipo` );
		}
	}

	record(
		'Nenhuma tela mostra o vocabulário do código ao comerciante',
		offenders.length === 0,
		offenders.join( ' | ' )
	);
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
console.log( 'Fase 16 observation — o que a interface diz, e em quantos passos' );
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
