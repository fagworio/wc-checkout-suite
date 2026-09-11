<?php
/**
 * WooCommerce load-order stubs for unit tests.
 *
 * This file deliberately declares WordPress's own global functions — `WC()` and
 * `did_action()` — because the behaviour under test is precisely how the code
 * behaves at different points in WordPress's load order, and that cannot be
 * simulated without standing in for those functions.
 *
 * It is excluded from the coding standard in phpcs.xml.dist for the same reason
 * tests/Integration is: the standard forbids unprefixed global functions and
 * multiple classes per file, and this is scaffolding that redefines global
 * functions on purpose and is never shipped. Everywhere else the standard still
 * applies.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

/**
 * Controllable stand-in for the WooCommerce container and its checkout object.
 */
final class WCCS_CoreFields_Stub {

	/**
	 * Value returned for `did_action( 'woocommerce_init' )`.
	 *
	 * @var int
	 */
	public static int $woocommerce_init_fired = 0;

	/**
	 * How many times the checkout object was requested.
	 *
	 * @var int
	 */
	public static int $checkout_calls = 0;

	/**
	 * Whether reading fields should throw.
	 *
	 * @var bool
	 */
	public static bool $throw_on_read = false;

	/**
	 * Fields the stub checkout returns.
	 *
	 * @var array<string, mixed>
	 */
	public static array $fields = array();

	/**
	 * Resets every counter and flag.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$woocommerce_init_fired = 0;
		self::$checkout_calls         = 0;
		self::$throw_on_read          = false;
		self::$fields                 = array();
	}

	/**
	 * Stub of the WooCommerce checkout object.
	 *
	 * @return WCCS_CoreFields_Stub_Checkout
	 */
	public static function checkout_object(): WCCS_CoreFields_Stub_Checkout {
		++self::$checkout_calls;

		return new WCCS_CoreFields_Stub_Checkout();
	}
}

/**
 * Stub of the WooCommerce checkout object.
 */
final class WCCS_CoreFields_Stub_Checkout {

	/**
	 * Returns the configured fields, or throws on demand.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException When the stub is configured to fail.
	 */
	public function get_checkout_fields(): array {
		if ( WCCS_CoreFields_Stub::$throw_on_read ) {
			throw new RuntimeException( 'deliberate failure' );
		}

		return WCCS_CoreFields_Stub::$fields;
	}
}

/**
 * Stub of the global `WC()` accessor.
 *
 * @return object The stub container.
 */
function WC() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for WooCommerce's own accessor on purpose.
	return new class() {
		/**
		 * Returns the stub checkout object.
		 *
		 * @return WCCS_CoreFields_Stub_Checkout
		 */
		public function checkout(): WCCS_CoreFields_Stub_Checkout {
			return WCCS_CoreFields_Stub::checkout_object();
		}
	};
}

/**
 * Stub of WordPress's `did_action()`.
 *
 * @param string $hook_name Hook name.
 * @return int
 */
function did_action( $hook_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for WordPress's own function on purpose.
	return 'woocommerce_init' === $hook_name ? WCCS_CoreFields_Stub::$woocommerce_init_fired : 0;
}
