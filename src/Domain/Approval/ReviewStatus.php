<?php
/**
 * The status an order waits in while its documents are reviewed.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Approval;

use WC_Order;
use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;

/**
 * Registration and application of the optional review state.
 *
 * **Nothing here exists until a store asks for it.** The statuses are read from the
 * published document, and a document that enables no complete flow registers no
 * status, adds no filter and hooks nothing: the store keeps exactly the order flow
 * WooCommerce gave it, which is what "does not change the status without being
 * configured" means when it is taken literally rather than promised.
 *
 * When a flow *is* configured, the order that carries the answer waits in the state
 * the merchant named. The hold happens after WooCommerce has created and saved the
 * order and after the gateway has had its say, so the store's own e-mails for the
 * state the order reached have already gone out; the review state itself sends
 * nothing, and the note left on the order plus the situation shown to the customer
 * (when the merchant asked for it) are what tell both sides where the order is.
 *
 * @see ROADMAP.md section 12.1
 */
final class ReviewStatus {

	/**
	 * The classic checkout, once the order exists and is saved.
	 */
	public const HOOK_CLASSIC = 'woocommerce_checkout_order_processed';

	/**
	 * The Store API checkout, which is the Blocks checkout.
	 */
	public const HOOK_API = 'woocommerce_store_api_checkout_order_processed';

	/**
	 * Registers the review state for the store that configured one.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'publish' ), 5 );
	}

	/**
	 * Publishes the statuses the published document asks for, or nothing.
	 *
	 * @return void
	 */
	public static function publish(): void {
		$statuses = self::statuses( PublishedDocument::read()->fields() );

		if ( array() === $statuses ) {
			return;
		}

		foreach ( $statuses as $status => $label ) {
			register_post_status(
				'wc-' . $status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders. */
					'label_count'               => _n_noop(
						'Waiting for review (%s)',
						'Waiting for review (%s)',
						'wc-checkoutsuite'
					),
				)
			);
		}

		add_filter(
			'wc_order_statuses',
			static function ( $order_statuses ) use ( $statuses ) {
				foreach ( $statuses as $status => $label ) {
					$order_statuses[ 'wc-' . $status ] = $label;
				}

				return $order_statuses;
			}
		);

		add_action( self::HOOK_CLASSIC, array( self::class, 'hold_classic' ), 20, 3 );
		add_action( self::HOOK_API, array( self::class, 'hold_api' ), 20, 1 );
	}

	/**
	 * The states a document asks for, keyed by status and labelled by the merchant.
	 *
	 * An incomplete flow asks for nothing: it is reported where it is written, and a
	 * store is never left waiting in a state the configuration did not finish naming.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, string> Label per status.
	 */
	public static function statuses( array $definitions ): array {
		$statuses = array();

		foreach ( ApprovalFlow::enabled_in( $definitions ) as $entry ) {
			$flow = $entry['flow'];

			if ( ! $flow->complete() ) {
				continue;
			}

			$statuses[ $flow->status() ] = $flow->label();
		}

		return $statuses;
	}

	/**
	 * Holds a classic checkout's order when its documents need reviewing.
	 *
	 * @param mixed $order_id    Order identifier.
	 * @param mixed $posted_data Posted data, unused.
	 * @param mixed $order       Order WooCommerce built, when it passes one.
	 * @return void
	 */
	public static function hold_classic( $order_id = 0, $posted_data = null, $order = null ): void {
		unset( $posted_data );

		if ( $order instanceof WC_Order ) {
			self::apply( $order );

			return;
		}

		$found = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( $found instanceof WC_Order ) {
			self::apply( $found );
		}
	}

	/**
	 * Holds a Store API checkout's order when its documents need reviewing.
	 *
	 * @param mixed $order Order the checkout created.
	 * @return void
	 */
	public static function hold_api( $order = null ): void {
		if ( $order instanceof WC_Order ) {
			self::apply( $order );
		}
	}

	/**
	 * Moves one order to the review state its documents ask for.
	 *
	 * The first field that needs a review decides, and an order already waiting is left
	 * where it is: applying the same state twice would add a second note and no
	 * information.
	 *
	 * @param WC_Order $order Order.
	 * @return bool Whether the order was held.
	 */
	public static function apply( WC_Order $order ): bool {
		$flows = ApprovalFlow::enabled_in( PublishedDocument::read()->fields() );

		if ( array() === $flows ) {
			return false;
		}

		$values = ( new OrderFieldsService() )->read( $order );

		foreach ( $flows as $entry ) {
			if ( ! $entry['flow']->complete() ) {
				continue;
			}

			if ( ! ApprovalFlow::answered( $values->get( $entry['id'] ) ) ) {
				continue;
			}

			$status = $entry['flow']->status();

			if ( '' === $status || $order->get_status() === $status ) {
				return false;
			}

			$order->update_status(
				$status,
				__( 'Waiting for review: a document on this order needs approval.', 'wc-checkoutsuite' )
			);

			return true;
		}

		return false;
	}

	/**
	 * The review state to tell the customer about, when the store asked for it.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return string|null The label of the state, or null when there is nothing to say.
	 */
	public static function situation( WC_Order $order, array $definitions ): ?string {
		$status = (string) $order->get_status();

		foreach ( ApprovalFlow::enabled_in( $definitions ) as $entry ) {
			$flow = $entry['flow'];

			if ( ! $flow->complete() || ! $flow->shows_status() ) {
				continue;
			}

			if ( $flow->status() === $status ) {
				return $flow->label();
			}
		}

		return null;
	}
}
