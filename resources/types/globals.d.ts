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

	/**
	 * A rule the server published for one field.
	 */
	interface WccsCheckoutRule {
		key: string;
		code: string;
		message: string;
	}

	/**
	 * A visibility rule the browser is allowed to decide.
	 *
	 * Only the rules whose every source the page owns are published. The policy
	 * travels with the rule because the browser applies it too.
	 */
	interface WccsCheckoutCondition {
		policy: string;
		visible: Record< string, unknown >;
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
			rules?: Record< string, WccsCheckoutRule[] >;
			conditions?: Record< string, WccsCheckoutCondition >;
			uploads?: {
				url?: string;
				nonce?: string;
				available?: boolean;
				reason?: string;
				maxBytes?: number;
			};
			/**
			 * What the homologation matrix allows for each gateway the store offers.
			 *
			 * `undecided` is a published answer and not a gap: it is the difference
			 * between a gateway that was observed and one that was not.
			 */
			payments?: {
				decisions?: Record<
					string,
					{ mode?: string; withheld?: string[] }
				>;
				undecided?: number;
				reason?: string;
			};

			/**
			 * The wording of the order summary control, published by the server
			 * so the bundle hardcodes no string a translator has to find.
			 */
			summary?: {
				label?: string;
				show?: string;
				hide?: string;
			};
			validation?: {
				url?: string;
				nonce?: string;
				revision?: number;
			};
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
