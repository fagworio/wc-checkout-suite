/**
 * The design's icon set.
 *
 * Every path is the prototype's own (`roadmap/fields.html`), kept in its own
 * coordinates and with its own 1.65px stroke so the icons render identically.
 * The `<i data-icon="…">` elements the prototype fills with `outerHTML` become a
 * component here, and the markup is parsed into React elements once at load
 * rather than injected as HTML.
 */

import { createElement } from '@wordpress/element';

/**
 * The prototype's paths, keyed by the name it uses in `data-icon`.
 *
 * @type {Record<string, string>}
 */
export const ICON_PATHS = {
	fields: '<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 8h8M8 12h8M8 16h5"/>',
	branch: '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="6" cy="19" r="2"/><path d="M6 7v10M6 13h6a6 6 0 0 0 6-3"/>',
	eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
	archive:
		'<rect x="3" y="3" width="18" height="5" rx="1.5"/><path d="M5 8v12h14V8M9 12h6"/>',
	shield: '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
	moon: '<path d="M20 15A8 8 0 0 1 9 4a8 8 0 1 0 11 11Z"/>',
	sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/>',
	arrow: '<path d="M5 12h14M14 7l5 5-5 5"/>',
	back: '<path d="M19 12H5M10 7l-5 5 5 5"/>',
	save: '<path d="M5 3h12l4 4v14H3V3h2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
	plus: '<path d="M12 5v14M5 12h14"/>',
	search: '<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
	check: '<path d="m5 12 4 4L19 6"/>',
	close: '<path d="m6 6 12 12M6 18 18 6"/>',
	spark: '<path d="m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4L12 3Z"/>',
	user: '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
	home: '<path d="m3 11 9-8 9 8v9H3v-9Z"/><path d="M9 20v-6h6v6"/>',
	mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
	location:
		'<path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/>',
	file: '<path d="M14 3H5v18h14V8l-5-5ZM14 3v5h5M8 12h8M8 16h6"/>',
	grip: '<circle cx="8" cy="5" r="1"/><circle cx="16" cy="5" r="1"/><circle cx="8" cy="12" r="1"/><circle cx="16" cy="12" r="1"/><circle cx="8" cy="19" r="1"/><circle cx="16" cy="19" r="1"/>',
	up: '<path d="M12 20V4M6 10l6-6 6 6"/>',
	down: '<path d="M12 4v16M6 14l6 6 6-6"/>',
	more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
	copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M15 8V4H4v11h4"/>',
	trash: '<path d="M4 7h16M10 7V5h4v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
	edit: '<path d="m14 5 5 5M4 20l5-1L20 8l-5-5L4 14v6Z"/>',
	lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4M12 14v3"/>',
	undo: '<path d="m8 5-5 5 5 5M3 10h11a6 6 0 0 1 0 12"/>',
	redo: '<path d="m16 5 5 5-5 5M21 10H10a6 6 0 0 0 0 12"/>',
	info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/>',
	circle: '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/>',
	download: '<path d="M12 3v12M7 10l5 5 5-5M4 17v4h16v-4"/>',
	upload: '<path d="M4 16v4h16v-4M12 16V4M7 9l5-5 5 5"/>',
	history: '<path d="M3 5v5h5M3 10a9 9 0 1 1 1 8M12 7v5l3 2"/>',
	reset: '<path d="M3 5v5h5M3 10a9 9 0 1 1 1 8"/>',
	desktop:
		'<rect x="3" y="3" width="18" height="13" rx="2"/><path d="M12 16v5M8 21h8"/>',
	tablet: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>',
	mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
	card: '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
	diamond:
		'<path d="m12 2 10 10-10 10L2 12 12 2Z"/><path d="m7 7 5 5 5-5M7 17l5-5 5 5"/>',
	barcode: '<path d="M3 5v14M7 5v14M10 5v14M15 5v14M18 5v14M21 5v14"/>',
	layers: '<path d="m12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5"/>',
	code: '<path d="m8 6-6 6 6 6M16 6l6 6-6 6M14 3l-4 18"/>',
	calendar:
		'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 14h3M14 14h3M7 17h3"/>',
	sliders:
		'<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="2" fill="var(--surface)"/><circle cx="16" cy="12" r="2" fill="var(--surface)"/><circle cx="10" cy="18" r="2" fill="var(--surface)"/>',
	power: '<path d="M12 2v10M7 5a8 8 0 1 0 10 0"/>',
};

/**
 * Turns a hyphenated SVG attribute into the camel-case React expects.
 *
 * @param {string} name Attribute name.
 * @return {string} React attribute name.
 */
function reactAttribute( name ) {
	return name.replace( /-([a-z])/g, ( _, letter ) => letter.toUpperCase() );
}

/**
 * Parses one icon's markup into React elements.
 *
 * The markup is authored in this repository and never comes from the request, so
 * no sanitising step is needed; parsing it means no HTML is injected into the
 * page, which is the part that would need one.
 *
 * @param {string} markup SVG children.
 * @return {Array<*>} Elements.
 */
function children( markup ) {
	const elements = [];
	const pattern =
		/<(rect|circle|path|line|polyline|ellipse|polygon)\s*([^>]*?)\/?>/g;

	let match;
	let key = 0;

	while ( null !== ( match = pattern.exec( markup ) ) ) {
		/** @type {Record<string, any>} */
		const attributes = { key: key++ };

		match[ 2 ].replace( /([a-zA-Z-]+)="([^"]*)"/g, ( _, name, value ) => {
			attributes[ reactAttribute( name ) ] = value;

			return '';
		} );

		elements.push( createElement( match[ 1 ], attributes ) );
	}

	return elements;
}

/**
 * An icon from the design's set.
 *
 * @param {Object} props             Component properties.
 * @param {string} props.name        Icon name, as the prototype writes it.
 * @param {string} [props.className] Extra classes.
 * @return {*} Rendered element.
 */
export function Icon( { name, className = 'icon' } ) {
	return createElement(
		'svg',
		{
			className,
			viewBox: '0 0 24 24',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: '1.65',
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': 'true',
		},
		children( ICON_PATHS[ name ] ?? ICON_PATHS.fields )
	);
}
