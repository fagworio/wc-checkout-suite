/**
 * jQuery, provided by WordPress as a runtime global.
 *
 * The build externalises the import and records `jquery` in the asset manifest,
 * so WordPress loads it first; there is no npm package to resolve against, which
 * is why the module is declared here.
 */
declare module 'jquery' {
	/**
	 * The slice of jQuery this plugin uses.
	 */
	interface WccsJQuery {
		( ...args: any[] ): any;

		/**
		 * Binds a handler to an event on the matched set.
		 */
		on: (
			event: string,
			handler: ( ...args: any[] ) => void
		) => WccsJQuery;
	}

	const jquery: WccsJQuery;
	export default jquery;
}
