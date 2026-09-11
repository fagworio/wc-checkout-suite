/**
 * Ambient declarations for the globals the plugin exposes in the browser.
 */

export {};

declare global {
	/**
	 * Navigation entry handed to the administration application.
	 */
	interface WccsAdminSection {
		id: string;
		label: string;
	}

	/**
	 * REST descriptor printed into the page.
	 *
	 * The routes come from the controllers themselves, so the client never holds
	 * a path the server does not register.
	 */
	interface WccsAdminRest {
		root?: string;
		namespace?: string;
		nonce?: string;
		routes?: Record< string, string >;
	}

	/**
	 * Bootstrap payload printed by the plugin before the bundle runs.
	 */
	interface WccsAdminBootstrap {
		version?: string;
		mountId?: string;
		sections?: WccsAdminSection[];
		rest?: WccsAdminRest;
	}

	/**
	 * A declarative mask the checkout bundle applies to one field.
	 */
	interface WccsCheckoutMask {
		key: string;
		version: number;
		definition: string | Record< string, unknown >;
	}

	interface Window {
		/**
		 * What the checkout bundle needs before it can run.
		 *
		 * Printed by the server from the published schema, so the masks the
		 * browser applies are the masks the store actually published rather than
		 * a list the bundle carries.
		 */
		wccsCheckout?: {
			masks?: Record< string, WccsCheckoutMask >;
		};

		/**
		 * Bootstrap payload of the administration application.
		 */
		wccsAdmin?: WccsAdminBootstrap;

		/**
		 * Bootstrap descriptor of the admin application, exposed for debugging.
		 */
		wccsAdminBootstrap?: () => {
			namespace: string;
			label: string;
		};
	}
}
