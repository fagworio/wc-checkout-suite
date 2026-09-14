/**
 * Where the controlled fields' values live while the checkout re-renders itself.
 *
 * The Blocks checkout replaces its own markup whenever the customer changes the
 * address, the shipping method or the payment method: React unmounts a subtree and
 * mounts a new one, and anything a component kept in its own state goes with it.
 * The classic checkout had the same problem for a different reason — its fragments
 * are replaced by the server — and solved it by capturing and restoring around the
 * AJAX call.
 *
 * This is the Blocks answer, and it is not the same mechanism. There is no fragment
 * to read back from and no event to hook: what survives a remount is state that was
 * never in the component to begin with. So the values live here, in one store owned
 * by the checkout's lifecycle, and a component is a view over it — it renders what
 * the store holds and reports what the customer typed. **Nothing reads the DOM to
 * find a value back**, which is the whole point: a value recovered from markup is a
 * value recovered from whatever React happened to render last.
 *
 * The hidden-value policy (section 11) is applied here as well as on the server,
 * and for the same reason it exists there: a field a rule hides either keeps what
 * was typed or loses it, and the two checkouts must agree about which. The server
 * remains the authority — it re-decides visibility and re-applies the policy when
 * the order is placed — and this half exists so the customer sees the same answer
 * the store will reach.
 */

/**
 * A value store.
 *
 * @typedef {Object} ValueStore
 * @property {(id: string) => any}                   get       Reads one value.
 * @property {(id: string, value: any) => void}      set       Writes one value.
 * @property {(id: string) => boolean}               has       Whether a value is held.
 * @property {(id: string) => void}                  forget    Drops one value.
 * @property {() => Record<string, any>}             all       Every value.
 * @property {(values: Record<string, any>) => void} replace   Replaces everything.
 * @property {Function}                              subscribe Listens for writes.
 */

/**
 * Creates the store.
 *
 * @param {Object}              [options]        Options.
 * @param {Record<string, any>} [options.values] Values to start with.
 * @return {ValueStore} Store.
 */
export function createValueStore( { values = {} } = {} ) {
	/** @type {Map<string, any>} */
	const held = new Map( Object.entries( values ) );
	/** @type {Set<() => void>} */
	const listeners = new Set();

	const notify = () => {
		for ( const listener of listeners ) {
			listener();
		}
	};

	return {
		get: ( id ) => held.get( id ),
		set: ( id, value ) => {
			if ( Object.is( held.get( id ), value ) ) {
				return;
			}

			held.set( id, value );
			notify();
		},
		has: ( id ) => held.has( id ),
		forget: ( id ) => {
			if ( held.delete( id ) ) {
				notify();
			}
		},
		all: () => Object.fromEntries( held ),
		replace: ( next ) => {
			held.clear();

			for ( const [ id, value ] of Object.entries( next ?? {} ) ) {
				held.set( id, value );
			}

			notify();
		},
		subscribe: ( /** @type {() => void} */ listener ) => {
			listeners.add( listener );

			return () => listeners.delete( listener );
		},
	};
}

/**
 * The lifecycle that owns the store.
 *
 * @typedef {Object} FieldLifecycle
 * @property {ValueStore}                                      store               The store itself.
 * @property {() => Record<string, any>}                       values              Values the components render.
 * @property {(id: string, value: any) => Record<string, any>} onChange            Records what was typed.
 * @property {Function}                                        subscribe           Listens for writes.
 * @property {(id: string, visible: boolean) => void}          applyVisibility     Applies the hidden-value policy.
 * @property {() => Record<string, any>}                       remount             Re-decides visibility after a rebuild.
 * @property {(field: any) => string}                          policyOf            The policy a field declared.
 *
 * @param    {Object}                                          [options]           Options.
 * @param    {ValueStore}                                      [options.store]     Store. One is created when absent.
 * @param    {Array<any>}                                      [options.fields]    Field payloads, as the server publishes them.
 * @param    {(field: any) => boolean}                         [options.isVisible] Answers whether a field is visible right now.
 * @return {FieldLifecycle} Lifecycle.
 */
export function createFieldLifecycle( {
	store = createValueStore(),
	fields = [],
	isVisible = () => true,
} = {} ) {
	/**
	 * The policy a field declared.
	 *
	 * @param {any} field Field payload.
	 * @return {string} `discard` unless the definition said otherwise.
	 */
	const policyOf = ( field ) =>
		'preserve' === field?.policy ? 'preserve' : 'discard';

	/**
	 * Applies visibility to one field's value.
	 *
	 * Called when a rule hides a field and when it shows it again. Hiding a discard
	 * field drops what it held; hiding a preserve field keeps it. Showing a field
	 * again never restores anything by itself — the value is either still in the
	 * store or it is gone, and either way that is the answer the server will reach.
	 *
	 * @param {string}  id      Field identifier.
	 * @param {boolean} visible Whether the field is visible.
	 * @return {void}
	 */
	const applyVisibility = ( id, visible ) => {
		if ( visible ) {
			return;
		}

		const field = fields.find( ( entry ) => entry.id === id );

		if ( 'discard' === policyOf( field ) ) {
			store.forget( id );
		}
	};

	/**
	 * The values the components should render.
	 *
	 * @return {Record<string, any>} Values.
	 */
	const values = () => store.all();

	/**
	 * Records what the customer typed.
	 *
	 * @param {string} id    Field identifier.
	 * @param {any}    value Value.
	 * @return {Record<string, any>} Every value after the write.
	 */
	const onChange = ( id, value ) => {
		store.set( id, value );

		return store.all();
	};

	/**
	 * Re-decides visibility for every field and answers with the values.
	 *
	 * The checkout calls this after it has rebuilt itself: the components are new,
	 * the store is not, and the answer is what the new components render. Nothing
	 * here looks at the document.
	 *
	 * @return {Record<string, any>} Values.
	 */
	const remount = () => {
		for ( const field of fields ) {
			applyVisibility( field.id, Boolean( isVisible( field ) ) );
		}

		return store.all();
	};

	return {
		store,
		values,
		onChange,
		applyVisibility,
		remount,
		policyOf,
		subscribe: store.subscribe,
	};
}
