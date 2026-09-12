/**
 * The glyph the design draws for each field type.
 *
 * The prototype puts a two-character mark in the round badge at the start of a row —
 * `Aa` for text, `#` for a number, `PF/PJ` for the person type. Those marks are part
 * of the design, so they are ported as the prototype writes them rather than derived
 * from the type's name; a derived mark would look almost right and drift with every
 * translation.
 *
 * Two things this file deliberately does not do. It does not name the types: the
 * label a row shows comes from the field type registry, which is what the merchant
 * configured and what the server translated. And it does not decide what a type can
 * do: that is the capability matrix, which is verified against the installed
 * WooCommerce.
 *
 * @package
 */

/**
 * Marks, keyed by the identifier the plugin registers the type under.
 *
 * The prototype's own keys, mapped onto this plugin's registry: `cpf` and `cnpj` are
 * the Brazilian documents the prototype draws as `PF / PJ` and `ID`, `file` is its
 * upload, and the temporal types keep their own glyphs.
 *
 * @type {Record<string, string>}
 */
export const TYPE_GLYPHS = {
	text: 'Aa',
	textarea: '¶',
	email: '@',
	number: '#',
	tel: '☎',
	url: '↗',
	password: '••',
	select: '⌄',
	multiselect: '≡',
	radio: '◉',
	checkbox: '✓',
	'checkbox-group': '☑',
	country: '◎',
	state: 'UF',
	date: '31',
	time: '◷',
	datetime: '◴',
	hidden: '—',
	heading: 'H',
	paragraph: '¶',
	html: '<>',
	file: '↑',
	cpf: 'PF',
	cnpj: 'PJ',
	'person-type': 'PF/PJ',
	'address-number': 'Nº',
	'company-name': 'RS',
};

/**
 * The mark for a type, with a stable fallback for a contributed one.
 *
 * A type registered by another plugin has no mark in the design, and a row without a
 * glyph is a row that reads as broken. The fallback is the first two characters of
 * the type key, which is what the merchant will recognise it by.
 *
 * @param {string} type Field type key.
 * @return {string} Two-character mark.
 */
export function typeGlyph( type ) {
	if ( TYPE_GLYPHS[ type ] ) {
		return TYPE_GLYPHS[ type ];
	}

	// A contributed type is often namespaced (`vendor/type`): the part after the
	// slash is the name its author chose.
	const bare = String( type ).split( '/' ).pop() ?? '';

	return bare.slice( 0, 2 ).toUpperCase() || '?';
}
