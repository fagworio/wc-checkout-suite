<?php
/**
 * Classic checkout wiring.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

/**
 * Connects the stored schema to the classic checkout.
 *
 * The document read here is the published one and nothing else, which is the
 * promise the draft/publish split exists to keep: a merchant editing a label
 * cannot change what a customer sees before pressing publish. Where that read
 * happens, and why it happens at the point of use, is
 * {@see PublishedDocument}'s business.
 *
 * @see ROADMAP.md section 7
 */
final class ClassicCheckout {

	/**
	 * Registers the checkout hooks.
	 *
	 * Registered unconditionally: `woocommerce_checkout_fields` only fires on a
	 * checkout request, so an admin screen or a REST call pays nothing for it.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'filter_fields' ), 20 );
	}

	/**
	 * Applies the published schema to the checkout fields.
	 *
	 * @param array<string, mixed> $fields Fields WooCommerce and other plugins built.
	 * @return array<string, mixed>
	 */
	public static function filter_fields( array $fields ): array {
		$document = PublishedDocument::read();

		if ( array() === $document->fields() ) {
			return $fields;
		}

		$adapter = new ClassicAdapter();
		$fields  = $adapter->apply( $fields, $document->fields(), $document->sections() );

		/**
		 * Filters what the classic adapter could not render.
		 *
		 * A field the classic checkout cannot show, or shows as something less
		 * than its type promises, is reported instead of being faked. The
		 * diagnostics section reads this.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array{field: string, type: string, level: string, reason: string}> $report Report entries.
		 */
		do_action( 'wccs_classic_adapter_report', $adapter->report() );

		return $fields;
	}
}
