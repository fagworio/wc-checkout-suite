<?php
/**
 * Classic checkout wiring.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

/**
 * Connects the stored schema to the classic checkout.
 *
 * The published document is the only one this class can reach. The draft is
 * written by the admin, is full of half-finished work, and reaching it from the
 * storefront would mean a merchant editing a label changes what a customer sees
 * before pressing publish — which is the promise the whole draft/publish split
 * exists to keep.
 *
 * The repository is built inside the filter rather than here. Its definition
 * validator needs the list of checkout fields WooCommerce owns, and that list
 * cannot be read before WooCommerce has finished starting; `plugins_loaded` is
 * too early and the checkout filter is long after. Building it at the point of
 * use makes the timing correct by construction rather than by remembering.
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
		$document = self::published();

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
		 * @since 0.1.0
		 *
		 * @param array<int, array{field: string, type: string, level: string, reason: string}> $report Report entries.
		 */
		do_action( 'wccs_classic_adapter_report', $adapter->report() );

		return $fields;
	}

	/**
	 * The published document.
	 *
	 * @return \WCCheckoutSuite\Domain\Schema\SchemaDocument
	 */
	private static function published(): \WCCheckoutSuite\Domain\Schema\SchemaDocument {
		$repository = new SchemaRepository(
			Registries::instance()->definition_validator(),
			new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
		);

		return $repository->read( SchemaRepository::SLOT_PUBLISHED );
	}
}
