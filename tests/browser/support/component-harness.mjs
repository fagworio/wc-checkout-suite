/**
 * The component harness the browser observations share.
 *
 * WCCS-063 and WCCS-064 both need the plugin's own field component rendered in a real
 * browser, and neither can get it from this store's checkout page: the page does not
 * contain the block this plugin registers, and putting it there would be editing the
 * merchant's page. So the component is rendered into a document the observation owns,
 * with the built bundle and the store's own stylesheets, and the two scripts share the
 * one implementation rather than each keeping a copy that can drift.
 *
 * The function is passed to `page.evaluate`, so it must not close over module scope:
 * everything it needs arrives in its single argument.
 *
 * @package WCCheckoutSuite
 */

export const renderComponent = async ( { bundle, width, payload, focus = false, zoom = false } ) => {
	const frame = document.createElement( 'iframe' );
	frame.setAttribute( 'title', 'wccs component harness' );
	frame.style.cssText = `width:${ width }px;height:700px;border:0;display:block`;
	document.body.appendChild( frame );

	const doc = frame.contentDocument;
	const view = frame.contentWindow;

	doc.open();
	doc.write( '<!doctype html><html><head></head><body><div class="wc-block-checkout"><div id="wccs-component-harness"></div></div></body></html>' );
	doc.close();

	// The store's own stylesheets, so the region is presented exactly as it is on the
	// checkout — including the tokens the presentation reads.
	document.querySelectorAll( 'link[rel=stylesheet]' ).forEach( ( link ) => {
		const copy = doc.createElement( 'link' );
		copy.rel = 'stylesheet';
		copy.href = link.href;
		doc.head.appendChild( copy );
	} );

	view.wp = /** @type {any} */ ( window ).wp;
	view.ReactDOM = /** @type {any} */ ( window ).ReactDOM;
	// The bundle is built with the automatic JSX runtime, which the page has as its own
	// script dependency: without it the component throws on its first element.
	view.ReactJSXRuntime = /** @type {any} */ ( window ).ReactJSXRuntime;
	view.wccsBlocks = payload;

	const captured = [];

	view.wc = {
		blocksCheckout: {
			registerCheckoutBlock: ( registration ) => captured.push( registration ),
		},
	};

	try {
		await new Promise( ( resolve, reject ) => {
			const script = doc.createElement( 'script' );
			script.src = bundle;
			script.onload = () => resolve( undefined );
			script.onerror = () => reject( new Error( 'the bundle did not load' ) );
			doc.head.appendChild( script );
		} );
	} catch ( error ) {
		return { rendered: false, reason: String( error.message ) };
	}

	if ( 0 === captured.length ) {
		return { rendered: false, reason: 'nothing_registered' };
	}

	const element = captured[ 0 ].component();

	if ( ! element ) {
		return { rendered: false, reason: 'no_component' };
	}

	/** @type {any} */ ( view ).wp.element.render( element, doc.getElementById( 'wccs-component-harness' ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );

	const region = doc.querySelector( '.wccs-blocks-field' );
	const control = region ? region.querySelector( 'textarea, input, select' ) : null;
	const label = region ? region.querySelector( 'label' ) : null;
	const htmlFor = label ? label.getAttribute( 'for' ) : null;

	const observation = {
		rendered: Boolean( region ),
		width,
		registeredName: captured[ 0 ].metadata ? captured[ 0 ].metadata.name : null,
		registeredParent: captured[ 0 ].metadata ? captured[ 0 ].metadata.parent : null,
		controlTag: control ? control.tagName.toLowerCase() : null,
		controlType: control ? control.getAttribute( 'type' ) : null,
		controlHeight: control ? Math.round( control.getBoundingClientRect().height ) : 0,
		labelled: Boolean( htmlFor && control && control.id === htmlFor ),
		labelText: label ? label.textContent.trim() : null,
		regionWidth: region ? Math.round( region.getBoundingClientRect().width ) : 0,
		regionRight: region ? Math.round( region.getBoundingClientRect().right ) : 0,
		overflow: doc.documentElement.scrollWidth - view.innerWidth,
		documentWidth: view.innerWidth,
	};

	if ( focus && control ) {
		control.focus();

		const style = view.getComputedStyle( control );

		observation.focused = doc.activeElement === control;
		observation.outlineWidth = parseFloat( style.outlineWidth ) || 0;
		observation.outlineStyle = style.outlineStyle;
		observation.boxShadow = style.boxShadow;
		observation.transitionDuration = style.transitionDuration;
		observation.transitionsInRegion = Array.from( region.querySelectorAll( '*' ) ).filter( ( child ) => {
			const value = view.getComputedStyle( child ).transitionDuration;

			return value && '0s' !== value && parseFloat( value ) > 0.5;
		} ).length;
	}

	if ( zoom ) {
		// Playwright cannot drive the browser's own zoom UI, and this is the layout
		// consequence of it: the document is laid out as if the viewport were half as
		// wide. What is asserted is reflow, which is what a zoomed reader experiences.
		doc.documentElement.style.zoom = '2';
		void doc.documentElement.offsetWidth;
		observation.zoomOverflow = doc.documentElement.scrollWidth - view.innerWidth;
		doc.documentElement.style.zoom = '';
	}

	// The tokens are declared on the region itself and on nothing else, which is what
	// keeps this plugin from restyling a page it is not on. Reading one from the region
	// and the same one from the document root asserts both halves of that.
	// The facts a screen reader depends on: one control, a name for it, and nothing
	// hiding the region from assistive technology. A real screen reader is not driven
	// here, and the report says so.
	observation.controls = region ? region.querySelectorAll( 'textarea, input, select' ).length : 0;
	observation.hiddenFromAt = Boolean(
		region && (
			region.getAttribute( 'aria-hidden' ) === 'true'
			|| 'none' === view.getComputedStyle( region ).display
			|| 'hidden' === view.getComputedStyle( region ).visibility
		)
	);
	observation.token = region ? view.getComputedStyle( region ).getPropertyValue( '--wccs-ink' ).trim() : '';
	observation.rootToken = view.getComputedStyle( doc.documentElement ).getPropertyValue( '--wccs-ink' ).trim();

	return observation;
};
