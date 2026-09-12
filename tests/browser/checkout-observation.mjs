/**
 * The browser observation of the store's checkout: WCCS-063.
 *
 * WCCS-063 needs a rendered page, and every phase from F04 onwards recorded that there
 * was none to open. There is one. This script opens the store's own checkout with a real
 * browser at each of the five widths the acceptance names and reports what the rendered
 * page actually says about the two halves of the plugin:
 *
 *   - that the request delivered the presentation, its tokens and the field payload, and
 *     that the bundle ran and found the Blocks checkout API it needs;
 *   - that the plugin's field reached the checkout at all — the native one through
 *     WooCommerce's own additional-fields API;
 *   - that no width overflows horizontally, that a focused control keeps its ring, that
 *     zoom does not break the layout, and that a reduced-motion preference is honoured.
 *
 * Two things it cannot do, and says so instead of pretending:
 *
 *   1. The store runs WooCommerce's "coming soon" mode, so an anonymous visitor is
 *      answered with the coming-soon screen and never reaches the checkout. Pass a
 *      logged-in cookie of a user who can `manage_woocommerce` (the store's own bypass)
 *      through `WCCS_COOKIE="name=value"`; without it the script reports what the
 *      anonymous page is and stops asserting about a checkout that was not served.
 *   2. This plugin draws its controlled fields through a block it registers with the
 *      Blocks checkout, and WooCommerce renders only the inner blocks the checkout page
 *      actually contains. This store's checkout page does not contain it, and putting it
 *      there would be editing the merchant's page. The plugin's own region is therefore
 *      exercised by `tests/browser/field-component-observation.mjs`, against the real
 *      bundle and the real stylesheet, and this script records the absence with its
 *      reason instead of counting it as a defect.
 *
 * It writes nothing to the store and changes nothing: it reads pages.
 */
import { chromium } from 'playwright';

const CHECKOUT = process.env.WCCS_URL || 'http://wpagf.dvl.to:8080/finalizar-compra/';
const ORIGIN = new globalThis.URL( CHECKOUT ).origin;
const PRODUCT = process.env.WCCS_PRODUCT || '';
const COOKIE = process.env.WCCS_COOKIE || '';
const WIDTHS = [320, 375, 768, 1280, 1440];
const findings = [];
const notes = [];

const record = (label, ok, detail = '') => findings.push({ label, ok: Boolean(ok), detail });
const note = (message) => notes.push(message);

// The store is served over plain HTTP, and crypto.randomUUID exists only in a secure context, so
// the Blocks checkout's scripts threw before this plugin could draw anything. The fix is a browser
// switch and not a change to the environment: Chromium can be told to treat one origin as secure,
// which is what makes it possible to observe a store that has not been given TLS yet.
const browser = await chromium.launch({
	executablePath: '/usr/bin/google-chrome',
	args: [
		'--no-sandbox',
		`--unsafely-treat-insecure-origin-as-secure=${ORIGIN}`,
	],
});

// The checkout block renders only when there is something to check out. The first run of this
// script opened the page with an empty cart and found that out: a page answering 200 is not the
// same thing as a checkout rendering. The product is added through the storefront's own route,
// which is what a customer does.
const seed = await browser.newPage({ viewport: { width: 1280, height: 900 } });

if (PRODUCT) {
	await seed.goto(`${ORIGIN}/?add-to-cart=${PRODUCT}`, { waitUntil: 'networkidle', timeout: 45000 });
	note(`Cart seeded with product ${PRODUCT}`);
} else {
	note('No product was given, so the checkout was opened with an empty cart.');
}

const state = await seed.context().storageState();

// The store's own bypass for the coming-soon screen is a capability, not an option: a session
// cookie of a user who can manage WooCommerce sees the live store. The cookie is handed in and
// never minted here, so this script never authenticates on its own.
if (COOKIE) {
	const separator = COOKIE.indexOf('=');
	const name = separator > 0 ? COOKIE.slice(0, separator) : '';
	const value = separator > 0 ? COOKIE.slice(separator + 1) : '';
	const host = new globalThis.URL( ORIGIN ).hostname;

	state.cookies.push({ name, value, domain: host, path: '/', expires: -1, httpOnly: true, secure: false, sameSite: 'Lax' });
	note(`Authenticated as a store user through ${name}; the coming-soon screen is bypassed by the store's own rule.`);
} else {
	note('No cookie was given. If the store is in coming-soon mode, the checkout will not be served.');
}

/** Reads what the rendered checkout says about this plugin. */
const readDelivery = () =>
	/**
	 * Reads the page.
	 *
	 * @return {Object} Observation.
	 */
	({
		checkout: document.querySelectorAll('.wc-block-checkout, form.checkout').length,
		comingSoon: document.querySelectorAll('.wp-block-woocommerce-coming-soon').length,
		bundle: Array.from(document.querySelectorAll('script[src]')).some((element) => (element.getAttribute('src') || '').includes('wc-checkout-suite/build/blocks/index.js')),
		classicBundle: Array.from(document.querySelectorAll('script[src]')).some((element) => (element.getAttribute('src') || '').includes('wc-checkout-suite/build/checkout/index.js')),
		tokens: Array.from(document.querySelectorAll('link[rel=stylesheet]')).some((element) => (element.getAttribute('href') || '').includes('design-tokens/tokens.css')),
		presentation: Array.from(document.querySelectorAll('link[rel=stylesheet]')).some((element) => (element.getAttribute('href') || '').includes('blocks/presentation.css')),
		payload: (() => {
			const runtime = /** @type {any} */ (window);
			return runtime.wccsBlocks && Array.isArray(runtime.wccsBlocks.fields)
				? runtime.wccsBlocks.fields.map((field) => field.name)
				: null;
		})(),
		registry: typeof /** @type {any} */ (window).wccsBlocksFields,
		registration: typeof (/** @type {any} */ (window).wc && /** @type {any} */ (window).wc.blocksCheckout
			? /** @type {any} */ (window).wc.blocksCheckout.registerCheckoutBlock
			: undefined),
		region: document.querySelectorAll('.wccs-blocks-field').length,
		nativeField: document.body.innerText.includes('Fixture note'),
		overflow: document.documentElement.scrollWidth - window.innerWidth,
	});

for (const width of WIDTHS) {
	const page = await browser.newPage({ viewport: { width, height: 900 }, storageState: state });
	const errors = [];
	page.on('pageerror', (error) => errors.push(String(error.message).slice(0, 120)));

	const response = await page.goto(CHECKOUT, { waitUntil: 'networkidle', timeout: 45000 });
	const status = response ? response.status() : 0;

	// Where the browser actually ended up. Seven rounds of measurement read a page that had
	// followed a redirect without ever asking where it landed: the checkout sends an empty cart
	// to the cart, and the cart is not a checkout — which is why nothing of this plugin was ever
	// on the page no matter what was changed.
	const landed = page.url();
	const redirected = !landed.startsWith(CHECKOUT);

	record(`The browser is on the checkout at ${width}px`, !redirected, `landed=${landed}`);

	const delivery = await page.evaluate(readDelivery);

	record(`The checkout answers at ${width}px`, 200 === status, `status=${status}`);
	record(
		`The store served a checkout and not the coming-soon screen at ${width}px`,
		0 === delivery.comingSoon && delivery.checkout > 0,
		`coming_soon=${delivery.comingSoon} checkout=${delivery.checkout}`
	);
	record(`No horizontal overflow at ${width}px`, delivery.overflow <= 1, `overflow=${delivery.overflow}px`);
	record(`No uncaught script error at ${width}px`, 0 === errors.length, errors.join(' | '));

	// What the request delivered. The bundle is worthless without the payload it reads, and the
	// payload is worthless without the stylesheet that presents it, so the three are asserted
	// together at every width.
	record(
		`The plugin's payload and bundle are on the page at ${width}px`,
		delivery.bundle && Array.isArray(delivery.payload) && delivery.payload.length > 0,
		`bundle=${delivery.bundle} payload=${JSON.stringify(delivery.payload)}`
	);
	record(
		`The presentation and its tokens are on the page at ${width}px`,
		delivery.presentation && delivery.tokens,
		`presentation=${delivery.presentation} tokens=${delivery.tokens}`
	);
	record(
		`The bundle found the Blocks checkout API at ${width}px`,
		'function' === delivery.registration,
		`registerCheckoutBlock=${delivery.registration} registry=${delivery.registry}`
	);
	record(
		`The document reached the rendered checkout at ${width}px`,
		delivery.nativeField,
		`native_field=${delivery.nativeField}`
	);

	if (0 === delivery.region) {
		note(
			`The plugin's own region is not placed on the checkout at ${width}px (regions=${delivery.region}): the checkout page's content does not contain the block this plugin registers, and WooCommerce renders the inner blocks the page contains. The component itself is observed by field-component-observation.mjs.`
		);
	}

	await page.close();
}

// ---------------------------------------------------------------------------
// Focus, zoom and reduced motion, read on one page.
// ---------------------------------------------------------------------------
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce', storageState: state });
await page.goto(CHECKOUT, { waitUntil: 'networkidle', timeout: 45000 });

const control = page.locator('.wccs-blocks-field textarea, .wccs-blocks-field input, .wc-block-checkout input[type=text], .wc-block-checkout input[type=email], .wc-block-checkout select').first();

if (await control.count()) {
	await control.focus();

	const focused = await control.evaluate((element) => {
		const style = getComputedStyle(element);
		return {
			isActive: document.activeElement === element,
			outlineWidth: parseFloat(style.outlineWidth) || 0,
			outlineStyle: style.outlineStyle,
			boxShadow: style.boxShadow,
			borderColor: style.borderColor,
			height: Math.round(element.getBoundingClientRect().height),
		};
	});

	// A focus ring is either an outline or a shadow: which one a theme chooses is its business,
	// what matters is that the focused control is visibly different from the unfocused one.
	const ring = (focused.outlineWidth > 0 && 'none' !== focused.outlineStyle) || (focused.boxShadow && 'none' !== focused.boxShadow);

	record('The focused control is the active element', focused.isActive, JSON.stringify(focused));
	record('A focused control keeps a visible ring', Boolean(ring), JSON.stringify(focused));
	record('The control is a usable touch target', focused.height >= 40, `height=${focused.height}px`);
} else {
	record('A control was on the page to focus', false, 'no checkout control was found');
}

const transitions = await page.evaluate(() => Array.from(document.querySelectorAll('.wc-block-checkout *, .wccs-blocks-field *')).filter((element) => {
	const value = getComputedStyle(element).transitionDuration;
	return value && '0s' !== value && parseFloat(value) > 0.5;
}).length);

record('Reduced motion: no long transition survives the preference', 0 === transitions, `elements=${transitions}`);

// Zoom is applied as CSS zoom on the root element: Playwright cannot drive the browser's own
// zoom UI, and this is the same layout consequence — the page is laid out as if the viewport were
// half as wide. What the assertion is about is reflow, which is what a zoomed user experiences.
const zoomOverflow = await page.evaluate(() => {
	document.documentElement.style.zoom = '2';
	void document.documentElement.offsetWidth;
	const overflow = document.documentElement.scrollWidth - window.innerWidth;
	document.documentElement.style.zoom = '';
	return overflow;
});

record('Zoom to 200% does not overflow horizontally', zoomOverflow <= 1, `overflow=${zoomOverflow}px`);

await seed.close();
await browser.close();

for (const finding of findings) {
	console.log(`${finding.ok ? 'PASS' : 'FAIL'}  ${finding.label}${finding.detail ? '  [' + finding.detail + ']' : ''}`);
}

for (const message of notes) {
	console.log(`NOTE  ${message}`);
}

const failed = findings.filter((finding) => !finding.ok).length;
console.log(`RESULT: ${findings.length - failed} passed, ${failed} failed, ${notes.length} notes`);
process.exit(failed > 0 ? 1 : 0);
