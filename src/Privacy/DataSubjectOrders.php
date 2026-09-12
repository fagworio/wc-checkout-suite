<?php
/**
 * The orders a privacy request is about.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Privacy;

use WC_Order;

/**
 * Finds the orders a person's request covers.
 *
 * Both halves of a request — the export and the erasure — need the same list, and the list
 * is the part that is easy to get wrong in a way nobody notices: an order found by email
 * only misses the orders a customer placed while logged in with another billing address,
 * and an order found by customer identifier only misses everything placed as a guest with
 * the same address. The two are asked together, and a test asserts that a person who has
 * both kinds of order gets both.
 *
 * `wc_get_orders()` is used rather than a query, because that is the call both order
 * backends answer: with HPOS authoritative the orders are not in the posts table, and a
 * privacy tool that read the wrong table would report a person's data as absent — which is
 * worse than reporting it twice.
 */
final class DataSubjectOrders {

	/**
	 * Every order a request for this address is about.
	 *
	 * @param string $email Email address the request came from.
	 * @return array<int, WC_Order> Orders.
	 */
	public static function for_email( string $email ): array {
		$email = trim( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}

		$orders = array();

		$by_email = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'objects',
				'status'        => array_keys( wc_get_order_statuses() ),
			)
		);

		foreach ( (array) $by_email as $order ) {
			if ( $order instanceof WC_Order ) {
				$orders[ $order->get_id() ] = $order;
			}
		}

		$user = get_user_by( 'email', $email );

		if ( $user instanceof \WP_User ) {
			$by_customer = wc_get_orders(
				array(
					'customer_id' => $user->ID,
					'limit'       => -1,
					'return'      => 'objects',
					'status'      => array_keys( wc_get_order_statuses() ),
				)
			);

			foreach ( (array) $by_customer as $order ) {
				if ( $order instanceof WC_Order ) {
					$orders[ $order->get_id() ] = $order;
				}
			}
		}

		ksort( $orders );

		return array_values( $orders );
	}
}
