<?php
/**
 * What this plugin declares to the platform it runs on.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Support;

/**
 * The compatibility declarations WooCommerce asks a plugin for.
 *
 * WooCommerce's High-Performance Order Storage is opt-in **per plugin**: a plugin that reads and
 * writes orders through `WC_Order` works with either storage, but WooCommerce cannot know that by
 * itself. Until a plugin declares it, the store's own Features screen lists it as untested, and a
 * merchant turning HPOS on is asked to accept a warning about a plugin that in fact uses nothing but
 * the public order API.
 *
 * Declaring it is therefore not a formality and not a promise about code that does not exist: this
 * plugin never reads `wp_posts` or the orders table for order data, and both storages are exercised
 * by `F00-wccs-002-classic-hpos-proof.php`. What the declaration adds is the platform's own record of
 * an answer this plugin can already give.
 *
 * The declaration is made on `before_woocommerce_init`, which is the moment WooCommerce documents
 * for it and the only one where its container is ready to accept it. `declare_compatibility()`
 * returns false when the feature is unknown, and that is answered rather than thrown: a WooCommerce
 * build without the feature has nothing to declare.
 *
 * @see https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
 */
final class PlatformCompatibility {

	/**
	 * The HPOS feature identifier, as WooCommerce names it.
	 */
	public const FEATURE_HPOS = 'custom_order_tables';

	/**
	 * Registers the declarations.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare' ) );
	}

	/**
	 * Declares this plugin compatible with the features it supports.
	 *
	 * @return void
	 */
	public static function declare(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			self::FEATURE_HPOS,
			WCCS_PLUGIN_FILE,
			true
		);
	}
}
