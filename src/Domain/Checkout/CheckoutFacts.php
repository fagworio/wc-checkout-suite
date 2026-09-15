<?php
/**
 * What the store can answer for, when a checkout is cut down to the essentials.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Domain\Settings\CheckoutSettings;

/**
 * The facts a minimal checkout is verified against.
 *
 * Section 6.3 says a minimal checkout "não significa ignorar obrigações técnicas" and names what
 * has to be checked before it is saved: the gateway, the taxes, the delivery, the data the
 * integrations require and the legal rules. Four of those five are facts about the **store** and
 * not about the composition — they are true or false whatever the merchant's sections say — so
 * they are read once, here, from WooCommerce's own configuration.
 *
 * The fifth, what the integrations require, is about the **document**: it asks whether a field
 * another surface needs is still collected somewhere. That one cannot be answered here, because
 * the answer changes with the composition, and it is asked where the composition is.
 *
 * Every answer is a reading of WooCommerce and never a guess about it: a checklist that invented a
 * "yes" would agree with a store that cannot take a payment.
 *
 * @see \ROADMAP.md section 6.3
 */
final class CheckoutFacts {

	/**
	 * Every fact, as the editor reads them.
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		return array(
			'gateway'  => self::gateway(),
			'taxes'    => self::taxes(),
			'shipping' => self::shipping(),
			'legal'    => self::legal(),
		);
	}

	/**
	 * Whether the store can take a payment at all.
	 *
	 * The list is the one the checkout would render — `get_available_payment_gateways()` applies
	 * WooCommerce's own filters — rather than the set of gateways that exist, because a gateway
	 * that is configured and unavailable cannot close an order.
	 *
	 * @return bool
	 */
	public static function gateway(): bool {
		return array() !== CheckoutSettings::offered_gateways();
	}

	/**
	 * Whether the store calculates taxes.
	 *
	 * @return bool
	 */
	public static function taxes(): bool {
		return function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
	}

	/**
	 * Whether the store delivers.
	 *
	 * @return bool
	 */
	public static function shipping(): bool {
		return function_exists( 'wc_shipping_enabled' ) && wc_shipping_enabled();
	}

	/**
	 * Whether the store has legal terms to accept.
	 *
	 * A store without a terms page has nothing for a composition to drop, and a store with one has
	 * a page the checkout is expected to offer: either way this is the fact, and it is not a
	 * judgement about whether the terms are the right ones.
	 *
	 * @return bool
	 */
	public static function legal(): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return true;
		}

		return (int) wc_get_page_id( 'terms' ) > 0;
	}
}
