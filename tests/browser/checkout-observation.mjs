/**
 * The first browser observation: the Blocks checkout, at the five widths.
 *
 * WCCS-063 needs a rendered page, and every phase from F04 onwards recorded that there was
 * none to open. There is one. This script publishes a document with one field, opens the
 * store's own checkout with a real browser at each width the acceptance names, and reports
 * what the rendered page actually says: whether the plugin's region is there, whether the
 * page overflows horizontally, whether a focused control keeps its ring, and whether a
 * reduced-motion preference is honoured.
 *
 * It writes nothing to the store and changes nothing: it reads pages.
 */
import { chromium } from 'playwright';

const URL = process.env.WCCS_URL || 'http://wpagf.dvl.to:8080/finalizar-compra/';
const WIDTHS = [320, 375, 768, 1280, 1440];
const findings = [];
const notes = [];

const record = (label, ok, detail = '') => findings.push({ label, ok: Boolean(ok), detail });

const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });

for (const width of WIDTHS) {
	const page = await browser.newPage({ viewport: { width, height: 900 } });
	const errors = [];
	page.on('pageerror', (error) => errors.push(String(error.message).slice(0, 120)));

	const response = await page.goto(URL, { waitUntil: 'networkidle', timeout: 45000 });
	const status = response ? response.status() : 0;
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
	const region = await page.locator('.wccs-blocks-field').count();
	const scope = await page.evaluate(() => document.body.className.includes('woocommerce-checkout'));

	record(`The checkout answers at ${width}px`, 200 === status, `status=${status}`);
	record(`No horizontal overflow at ${width}px`, overflow <= 1, `overflow=${overflow}px`);
	record(`The page is a checkout at ${width}px`, scope);
	record(`The plugin region is on the page at ${width}px`, region > 0, `regions=${region}`);
	record(`No uncaught script error at ${width}px`, 0 === errors.length, errors.join(' | '));

	await page.close();
}

// Focus and reduced motion, read once: the same page, one control focused.
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
await page.goto(URL, { waitUntil: 'networkidle', timeout: 45000 });

const control = page.locator('.wccs-blocks-field input, .wccs-blocks-field textarea, .wccs-blocks-field select').first();

if (await control.count()) {
	await control.focus();
	const outline = await control.evaluate((element) => {
		const style = getComputedStyle(element);
		return { width: style.outlineWidth, style: style.outlineStyle };
	});
	record('A focused control keeps a visible ring', parseFloat(outline.width) > 0 && 'none' !== outline.style, JSON.stringify(outline));
} else {
	notes.push('No control of this plugin was on the page to focus — the region count above says why.');
}

const transitions = await page.evaluate(() => Array.from(document.querySelectorAll('*')).filter((element) => {
	const value = getComputedStyle(element).transitionDuration;
	return value && '0s' !== value && parseFloat(value) > 0.5;
}).length);

record('Reduced motion: no long transition survives the preference', 0 === transitions, `elements=${transitions}`);

const widths = await page.evaluate(() => Array.from(document.querySelectorAll('.wccs-blocks-field')).slice(0, 3).map((element) => Math.round(element.getBoundingClientRect().width)));
notes.push(`Region widths at 1280px: ${JSON.stringify(widths)}`);

await browser.close();

for (const finding of findings) {
	console.log(`${finding.ok ? 'PASS' : 'FAIL'}  ${finding.label}${finding.detail ? '  [' + finding.detail + ']' : ''}`);
}

for (const note of notes) {
	console.log(`NOTE  ${note}`);
}

const failed = findings.filter((finding) => !finding.ok).length;
console.log(`RESULT: ${findings.length - failed} passed, ${failed} failed, ${notes.length} notes`);
process.exit(failed > 0 ? 1 : 0);
