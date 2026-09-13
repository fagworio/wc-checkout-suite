<?php
/**
 * The values WooCommerce stored on behalf of the fields it rendered itself.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WC_Order;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * A second storage authority, read instead of duplicated.
 *
 * A Suite field whose type the Blocks adapter can hand to WooCommerce is registered as an
 * **additional checkout field**, and section 13's table says who persists it: the platform's
 * own API. The order then carries the value under WooCommerce's meta keys
 * (`_wc_billing/…`, `_wc_shipping/…`, `_wc_other/…`) and the Suite's payload never sees it,
 * because the Store API extension only carries the fields this plugin draws.
 *
 * The consequence, found by using the store rather than by reading it: on a store whose
 * fields are captured natively, **no Suite surface showed the value at all** — the customer's
 * page, the account, both e-mails and the order panel were empty, and the approval flow did
 * not hold the order either.
 *
 * This class is the answer, and it is deliberately a *reader*. Writing a copy into the Suite
 * payload would be a second meta for one answer, which ADR-0001 forbids; the value has one
 * authority, and a projection that wants to show it asks that authority. The three prefixes
 * are tried in turn rather than derived from a section, so a field that moves between
 * sections keeps being found, and the read costs three cached meta lookups.
 *
 * @see ROADMAP.md section 13
 * @see docs/adr/ADR-0001-storage-authority.md
 * @see docs/validation/F14-fluxos-de-utilizador.md (finding F-4)
 */
final class NativeOrderValues {

	/**
	 * Meta prefix WooCommerce uses for additional fields in the billing location.
	 */
	public const BILLING_PREFIX = '_wc_billing/';

	/**
	 * Meta prefix WooCommerce uses for additional fields in the shipping location.
	 */
	public const SHIPPING_PREFIX = '_wc_shipping/';

	/**
	 * Meta prefix WooCommerce uses for every other location.
	 */
	public const OTHER_PREFIX = '_wc_other/';

	/**
	 * The values the platform stored, keyed by field identifier.
	 *
	 * Only the fields this plugin owns and still shows are read: an archived definition has
	 * no policy left to allow it, exactly as the Suite's own reader decides.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, mixed> Values, keyed by field identifier.
	 */
	public static function all( WC_Order $order, array $definitions ): array {
		$values = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || ! $definition->is_enabled() || 'custom' !== $definition->origin() ) {
				continue;
			}

			$stored = $definition->to_array();
			$key    = isset( $stored['integration_id'] ) && '' !== (string) $stored['integration_id']
				? (string) $stored['integration_id']
				: 'wc-checkoutsuite/' . $id;

			foreach ( array( self::BILLING_PREFIX, self::SHIPPING_PREFIX, self::OTHER_PREFIX ) as $prefix ) {
				$value = $order->get_meta( $prefix . $key, true, 'edit' );

				if ( OrderFieldValues::is_answer( $value ) ) {
					$values[ $id ] = $value;

					break;
				}
			}
		}

		return $values;
	}

	/**
	 * Fills in what the Suite's own payload does not answer.
	 *
	 * The platform's value is used **only where the Suite has nothing to say**: a field the
	 * plugin persisted keeps its own value, and a field that was left blank stays blank. That
	 * is what makes this a fallback between two authorities rather than a merge of two copies
	 * of the same answer.
	 *
	 * @param array<string, mixed> $suite  Values from the Suite's payload.
	 * @param array<string, mixed> $native Values the platform stored.
	 * @return array<string, mixed> Both, with the Suite's answer winning where it exists.
	 */
	public static function merge( array $suite, array $native ): array {
		$merged = $suite;

		foreach ( $native as $id => $value ) {
			$id = (string) $id;

			if ( ! array_key_exists( $id, $merged ) || ! OrderFieldValues::is_answer( $merged[ $id ] ) ) {
				$merged[ $id ] = $value;
			}
		}

		return $merged;
	}
}
