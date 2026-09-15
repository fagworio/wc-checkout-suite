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
use WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry;

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
	 * Status transition used by the classic checkout after the gateway responds.
	 */
	public const HOOK_CLASSIC = 'woocommerce_order_status_changed';

	/**
	 * Payment-complete transition used by the Blocks checkout and gateways.
	 */
	public const HOOK_API = 'woocommerce_payment_complete';

	/**
	 * Order meta recording that this order already entered its review state.
	 *
	 * The hold is a one-time event in the life of an order, not a property of its
	 * current status. Without a record of it, every later transition into a
	 * post-payment status — the approval itself, shipping the order, refunding it —
	 * would read as a fresh order carrying documents and put it back in review,
	 * silently undoing the merchant's decision and leaving the order in a state only
	 * this plugin could take it out of.
	 */
	public const META_HELD = '_wccs_review_held';

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
	 * Registration is the registry's now. The states a flow asks for are added to the store's list
	 * **with the identifier they already have** — orders are recorded in them — and the registry
	 * registers every active status from that list, which is what keeps one registration path
	 * instead of two that could disagree about a label.
	 *
	 * A store that enables no complete flow still adds no hook and registers no status of its own:
	 * the list it registers from is the merchant's, and a flow that was never configured
	 * contributes nothing to it.
	 *
	 * @return void
	 */
	public static function publish(): void {
		$statuses = self::statuses( PublishedDocument::read()->fields() );

		OrderStatusRegistry::migrate( $statuses );

		if ( array() === $statuses ) {
			return;
		}

		add_action( self::HOOK_CLASSIC, array( self::class, 'hold_after_status_change' ), 20, 4 );
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
	 * Holds a Store API checkout's order when its documents need reviewing.
	 *
	 * @param mixed $order Order the checkout created.
	 * @return void
	 */
	public static function hold_api( $order = null ): void {
		if ( $order instanceof WC_Order ) {
			self::apply( $order );

			return;
		}

		$found = function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : null;

		if ( $found instanceof WC_Order ) {
			self::apply( $found );
		}
	}

	/**
	 * Holds an order only after WooCommerce has moved it to a post-payment state.
	 *
	 * The checkout-created hook runs before the gateway is processed. Listening to
	 * the status transition keeps the review state out of the payment decision and
	 * makes repeated gateway callbacks harmless: only a transition into a state
	 * WooCommerce uses after payment is considered.
	 *
	 * @param mixed $order_id    Order identifier.
	 * @param mixed $from        Previous status.
	 * @param mixed $to          New status.
	 * @param mixed $order       Order object.
	 * @return void
	 */
	public static function hold_after_status_change( $order_id = 0, $from = '', $to = '', $order = null ): void {
		unset( $from );

		if ( ! self::is_post_payment_status( (string) $to ) ) {
			return;
		}

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
	 * Whether WooCommerce has reached a state after the gateway's decision.
	 *
	 * `on-hold` is deliberately **not** one of them. WooCommerce uses it for an order
	 * whose payment has not arrived — a bank transfer or a cheque the store is still
	 * waiting for — and moving an unpaid order into a review state would take it out
	 * of the payment flow the customer was told about. Such an order is held when the
	 * payment does arrive and the store moves it to `processing`.
	 *
	 * @param string $status Order status without the `wc-` prefix.
	 * @return bool
	 */
	public static function is_post_payment_status( string $status ): bool {
		return in_array( $status, array( 'processing', 'completed' ), true );
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
		if ( '' !== (string) $order->get_meta( self::META_HELD ) ) {
			// This order already waited in the review state. Where it is now is the
			// merchant's decision, and a later status change is not a reason to
			// revisit it.
			return false;
		}

		$definitions = PublishedDocument::read()->fields();
		$flows       = ApprovalFlow::enabled_in( $definitions );

		if ( array() === $flows ) {
			return false;
		}

		$values = ( new OrderFieldsService() )->read( $order, $definitions );

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

			// Recorded before the transition, so the status change this very call
			// fires cannot be read as a new order needing review.
			$order->update_meta_data( self::META_HELD, '1' );

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
