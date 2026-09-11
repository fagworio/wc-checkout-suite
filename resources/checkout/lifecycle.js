/**
 * Classic checkout component lifecycle.
 *
 * WooCommerce's classic checkout refreshes itself over AJAX and fires
 * `updated_checkout` when the new HTML is in the page. The fragments it replaces
 * by default are the order review table and the payment box, but plugins and
 * themes add their own through `woocommerce_update_order_review_fragments`, so a
 * field of ours can be replaced as well.
 *
 * The rule this module enforces is one line long: **a component starts once per
 * element, not once per refresh.** Everything else follows from it.
 *
 * - An element that survived the refresh is never started again. Starting a
 *   masked input twice re-wraps it, and the second wrapper is the one that resets
 *   the value the customer typed.
 * - An element that was replaced is a new node the registry has never seen, so it
 *   starts again — which is exactly right, because the new node is empty.
 * - An element that was removed and re-added is also a new node, and starts
 *   again, for the same reason.
 *
 * Identity is per node, not per identifier or selector. Two inputs with the same
 * name are two elements; a replacement of one of them is a third. Tracking by
 * node is what makes "was this already started?" the same question as "did this
 * element survive the refresh?".
 */

/**
 * A component the lifecycle starts.
 *
 * @typedef {Object} LifecycleComponent
 * @property {string}                     selector Elements it starts on.
 * @property {(element: Element) => void} start    Called once per element.
 */

/**
 * A lifecycle registry.
 *
 * @typedef {Object} Lifecycle
 * @property {(name: string, component: LifecycleComponent) => Lifecycle} register Registers a component.
 * @property {(root?: Document|Element|null) => number}                   run      Starts what is new.
 */

/**
 * Creates a lifecycle registry.
 *
 * A factory rather than module state: a checkout has one, and a test has as many
 * as it wants without a reset.
 *
 * @return {Lifecycle} Registry.
 */
export function createLifecycle() {
	/** @type {Map<string, LifecycleComponent>} */
	const components = new Map();

	/** @type {WeakMap<Element, Set<string>>} */
	const startedOn = new WeakMap();

	/**
	 * Registers a component.
	 *
	 * @param {string}             name      Stable component name.
	 * @param {LifecycleComponent} component Component.
	 * @return {Lifecycle} The registry, for chaining.
	 */
	function register( name, component ) {
		if ( typeof name !== 'string' || '' === name ) {
			throw new TypeError( 'A component needs a non-empty name.' );
		}

		if (
			! component ||
			typeof component.selector !== 'string' ||
			'' === component.selector ||
			typeof component.start !== 'function'
		) {
			throw new TypeError(
				`The component "${ name }" needs a selector and a start function.`
			);
		}

		components.set( name, component );

		return registry;
	}

	/**
	 * Starts every component that has not been started on its element yet.
	 *
	 * @param {Document|Element|null} [root] Element to search under. Defaults to the document.
	 * @return {number} How many components were started in this pass.
	 */
	function run( root = document ) {
		if ( ! root ) {
			return 0;
		}

		let started = 0;

		components.forEach( ( component, name ) => {
			root.querySelectorAll( component.selector ).forEach(
				( element ) => {
					let names = startedOn.get( element );

					if ( names && names.has( name ) ) {
						return;
					}

					if ( ! names ) {
						names = new Set();
						startedOn.set( element, names );
					}

					// Marked before it is started, not after. A component that
					// triggers a refresh from inside its own start would otherwise
					// come back round and start itself a second time on the same
					// element, which is the thing this module exists to prevent.
					names.add( name );
					started += 1;

					try {
						component.start( element );
					} catch ( error ) {
						// One broken component must not take the checkout's other
						// components down with it, and it must not vanish either.
						// eslint-disable-next-line no-console
						console.error(
							`[wc-checkoutsuite] The component "${ name }" failed to start.`,
							error
						);
					}
				}
			);
		} );

		return started;
	}

	/** @type {Lifecycle} */
	const registry = { register, run };

	return registry;
}
