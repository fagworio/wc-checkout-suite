/**
 * Blocks checkout entry point.
 *
 * Reads the payload the server published, turns it into components, and hands them
 * to whatever the checkout offers for placing them. The placing itself is
 * deliberately someone else's job: this bundle knows how to draw a field, and the
 * integration task that persists the value decides where in the form it goes. A
 * field that is drawn but cannot be saved is worse than a field that is not drawn,
 * because the customer fills it in.
 *
 * That is why the registration is **injected** rather than imported. The Blocks
 * checkout exposes its API as a runtime global, and a bundle that imported the
 * package would be claiming a build dependency this plugin does not have. What this
 * module does is the part it can prove: it finds the API, it reports the fields it
 * can render, and it says out loud when there is nowhere to put them.
 */

import { createElement } from '@wordpress/element';

import { componentFor, unrenderable } from './fields';
import { createFieldLifecycle, createValueStore } from './values';

/**
 * The payload the server wrote before this bundle loaded.
 *
 * @type {{fields?: Array<any>, report?: Array<any>}}
 */
const payload = /** @type {any} */ ( window ).wccsBlocks || {};

/**
 * The registry an extension contributes its own component to.
 *
 * Published before anything is drawn, and documented in docs/api/extension-contracts.md: a plugin
 * that wants its own React component in the checkout registers it here and declares the script
 * dependency `wc-checkout-suite-blocks`, which is what guarantees this object exists before its
 * script runs. A component registered here wins over the one this bundle would have used, so a
 * contributed type draws what its author wrote and not a degradation of it.
 *
 * @type {Record<string, any>}
 */
const contributed = /** @type {any} */ (
	window.wccsBlocksFields = /** @type {any} */ ( window )
		.wccsBlocksFields || {
		components: {},
	}
);

/**
 * Registers a component for a field type.
 *
 * @param {string}   key       Field type key.
 * @param {Function} component React component.
 * @return {void}
 */
contributed.register = function registerContributed( key, component ) {
	if (
		'string' === typeof key &&
		'' !== key &&
		'function' === typeof component
	) {
		contributed.components[ key ] = component;
	}
};

/**
 * Where the values live while the checkout re-renders itself.
 *
 * One store for the page, created once. A component is a view over it: the checkout
 * unmounts and mounts subtrees whenever the address, the shipping method or the
 * payment method changes, and a value kept inside a component would go with it.
 */
const lifecycle = createFieldLifecycle( {
	store: createValueStore(),
	fields: payload.fields ?? [],
} );

/**
 * The Blocks checkout API, when the platform has loaded it.
 *
 * `wc-blocks-checkout` is a script handle WooCommerce registers, and what it puts
 * on the window differs between versions: the check is for the members this bundle
 * knows how to use, not for a version number.
 *
 * @return {any|null} API, or null.
 */
export function checkoutApi() {
	const runtime = /** @type {any} */ ( window );
	const api =
		runtime.wc && runtime.wc.blocksCheckout
			? runtime.wc.blocksCheckout
			: null;

	if ( ! api ) {
		return null;
	}

	return 'object' === typeof api ? api : null;
}

/**
 * Builds the elements for the fields that have a component.
 *
 * @param {Array<any>}             fields   Field payloads.
 * @param {Record<string, any>}    values   Current values, by field identifier.
 * @param {Function}               onChange Called with the identifier and the new value.
 * @param {Record<string, string>} [errors] Messages, by field identifier.
 * @return {{elements: Array<any>, missing: Array<string>}} Elements, and the types with no component.
 */
export function buildFields(
	fields,
	values = {},
	onChange = () => {},
	errors = {}
) {
	const elements = [];
	const missing = [];

	for ( const field of fields ) {
		const component = componentFor( field );

		if ( ! component ) {
			missing.push( String( field.type ) );
			continue;
		}

		elements.push(
			// The component is a function component from this module; `createElement`
			// takes it as a type, which the JSDoc cannot express.
			createElement( /** @type {any} */ ( component ), {
				key: field.id,
				field,
				value: values[ field.id ] ?? values[ field.name ],
				error: errors[ field.id ],
				onChange: ( /** @type {any} */ value ) =>
					onChange( field.id, value ),
			} )
		);
	}

	return { elements, missing };
}

/**
 * The checkout areas a field may be registered under.
 *
 * `registerCheckoutBlock` takes an area as `metadata.parent` and throws — not
 * returns — when it is missing or is not one of the platform's own areas. So the
 * names are stated here, mapped from the location the payload already carries, in
 * the same words WooCommerce's `innerBlockAreas` uses.
 *
 * A location with no area of its own lands in the fields column, which is the area
 * every default checkout page has. Guessing a narrower one would be a field the
 * merchant cannot place at all on a store whose page does not contain it.
 *
 * @type {Record<string, string>}
 */
export const PARENT_AREAS = {
	address: 'woocommerce/checkout-shipping-address-block',
	contact: 'woocommerce/checkout-contact-information-block',
	order: 'woocommerce/checkout-fields-block',
};

/**
 * The area a field belongs to, from the location the payload carries.
 *
 * @param {string} [location] Location, as the server mapped the section.
 * @return {string} Inner block area.
 */
export function parentFor( location ) {
	return PARENT_AREAS[ String( location ) ] ?? PARENT_AREAS.order;
}

/**
 * Registers the fields with the checkout.
 *
 * @param {Object}          [options]        Options.
 * @param {any}             [options.api]    Blocks checkout API. Null asks the window.
 * @param {Array<any>|null} [options.fields] Field payloads. Null reads the payload.
 * @param {Function|null}   [options.render] Called with the elements; the integration supplies it.
 * @return {{registered: Array<string>, missing: Array<string>, reason: string}} Outcome.
 */
export function register( { api = null, fields = null, render = null } = {} ) {
	const declared = fields ?? payload.fields ?? [];
	const resolved = api ?? checkoutApi();

	// What the components render comes from the store, and what they report goes
	// back into it. Nothing here reads the document to find a value again.
	const { elements, missing } = buildFields(
		declared,
		lifecycle.values(),
		lifecycle.onChange
	);

	if ( 'function' === typeof render ) {
		render( elements, declared );

		return {
			registered: declared.map( ( field ) => field.id ),
			missing,
			reason: '',
		};
	}

	if ( ! resolved ) {
		// The checkout API is not here: this bundle was loaded on a page that is
		// not the Blocks checkout, or the platform did not register it. Rendering
		// nothing is the only honest answer, and the reason travels so the caller
		// can say so.
		return {
			registered: [],
			missing,
			reason: 'no_blocks_checkout_api',
		};
	}

	if ( 'function' !== typeof resolved.registerCheckoutBlock ) {
		return {
			registered: [],
			missing,
			reason: 'no_registration_api',
		};
	}

	// The blocks are built by the integration task, which owns the metadata and the
	// Store API namespace the values travel under. Until it does, this is where the
	// seam is, and it is a seam rather than a guess.
	for ( const field of declared ) {
		if ( missing.includes( String( field.type ) ) ) {
			continue;
		}

		resolved.registerCheckoutBlock( {
			metadata: {
				name: `wc-checkoutsuite/${ field.name }`,
				title: field.label,
				// Registration without an area is refused by the platform, and a
				// refusal here is thrown: the field never registers and every
				// checkout page load logs an error. The area is the one the field's
				// location belongs to, which is what the payload carries.
				parent: parentFor( field.location ),
			},
			component: () => elements.shift() ?? null,
		} );
	}

	return {
		registered: declared.map( ( field ) => field.id ),
		missing,
		reason: '',
	};
}

/**
 * The types this build cannot render, said in words.
 *
 * @return {Array<string>} Messages.
 */
export function missingMessages() {
	const { missing } = buildFields( payload.fields ?? [] );

	return [ ...new Set( missing ) ].map( ( type ) =>
		unrenderable( /** @type {any} */ ( { type } ) )
	);
}

if (
	'undefined' !== typeof window &&
	payload.fields &&
	payload.fields.length
) {
	register();
}
