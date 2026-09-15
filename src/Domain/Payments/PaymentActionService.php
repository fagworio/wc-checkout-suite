<?php
/**
 * Asking a gateway to do something, once.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

use WC_Order;
use WCCheckoutSuite\Domain\Workflow\WorkflowEngine;
use WCCheckoutSuite\Domain\Workflow\WorkflowRepository;

/**
 * The `PaymentActionService` of §13.7 and §20, and the gate of this phase written as control flow.
 *
 * §20 lists seven rules and calls them non-negotiable. Read together they are one sentence: **a
 * payment happens when a gateway says it happened, once.** Everything below is that sentence.
 *
 * - `execute()` is the only way an action reaches a gateway. It asks the capability registry first
 *   (Fase 11: no action without a proven capability), then the adapter registry (is there anybody to
 *   ask?), then records the call **before** making it, then makes it, then records what came back.
 * - **A payment whose outcome is unknown is never retried.** The record written before the call is
 *   what makes that possible: an intent with no result is a call that may or may not have happened,
 *   and asking a gateway a second time is exactly the duplicate the gate forbids. The store stops,
 *   says so on the order, and leaves it to a person who can look at the gateway's own record. The
 *   alternative — retrying a capture that may have succeeded — is the failure mode this phase exists
 *   to prevent, so the cost of stopping is a note and the cost of guessing is a double charge.
 * - `confirm()` is the callback path, and it is idempotent by the order's own paid state: a webhook
 *   delivered twice finds an order that is already paid and does nothing.
 * - **Only `confirmed` calls `payment_complete()`** (rule 4), and the status after it is
 *   WooCommerce's own decision (rule 5). To get there the order has to be in a state WooCommerce
 *   accepts for completion — its own four are `on-hold`, `pending`, `failed` and `cancelled`, and a
 *   custom state is **not** among them — so the service returns the order to `pending` first and
 *   says why in a note. Without that step a confirmation would be recorded by the gateway and
 *   silently ignored by the store.
 * - **The fallback needs no gateway.** When the capability or the adapter is missing, the answer is
 *   WooCommerce's own pay-for-order page, which §13.2 step 4 names for exactly this case.
 *
 * @see \ROADMAP.md sections 13.4, 13.7, 20
 */
final class PaymentActionService {

	/**
	 * Prefix of the keys this service records in an order's log.
	 */
	public const KEY_PREFIX = 'payment:';

	/**
	 * Constructor.
	 *
	 * @param GatewayCapabilityRegistry|null $capabilities Capability registry.
	 * @param PaymentAdapterRegistry|null    $adapters     Adapter registry.
	 * @param WorkflowRepository|null        $log          The order's audit log.
	 */
	public function __construct(
		private ?GatewayCapabilityRegistry $capabilities = null,
		private ?PaymentAdapterRegistry $adapters = null,
		private ?WorkflowRepository $log = null
	) {
		$this->capabilities = $capabilities ?? new GatewayCapabilityRegistry();
		$this->adapters     = $adapters ?? new PaymentAdapterRegistry();
		$this->log          = $log ?? new WorkflowRepository();
	}

	/**
	 * Whether an action may be asked of an order's gateway at all.
	 *
	 * The two halves of "may I": the gateway proved the capability, and there is an adapter to call.
	 * Reported as a value rather than a bare boolean so a screen can say which of the two is missing.
	 *
	 * @param mixed  $order  Order or order identifier.
	 * @param string $action Action key.
	 * @return array{offered: bool, gateway: string, proven: bool, adapter: bool, reason: string}
	 */
	public function available( $order, string $action ): array {
		$found   = WorkflowEngine::order( $order );
		$gateway = null === $found ? '' : (string) $found->get_payment_method();

		if ( GatewayCapabilities::is_fallback( $action ) ) {
			return array(
				'offered' => true,
				'gateway' => $gateway,
				'proven'  => true,
				'adapter' => true,
				'reason'  => __( 'O link «Pagar pedido» é da própria WooCommerce.', 'wc-checkoutsuite' ),
			);
		}

		$proven  = $this->capabilities->supports( $gateway, $action, $this->version_of( $gateway ) );
		$adapter = $this->adapters->can_ask( $gateway, $action );

		return array(
			'offered' => $proven && $adapter,
			'gateway' => $gateway,
			'proven'  => $proven,
			'adapter' => $adapter,
			'reason'  => $proven && $adapter
				? ''
				: ( $proven
					? sprintf(
						/* translators: 1: action key, 2: gateway identifier */
						__( 'A capability «%1$s» está comprovada para «%2$s», mas nenhum adapter registado sabe executá-la.', 'wc-checkoutsuite' ),
						$action,
						$gateway
					)
					: $this->capabilities->fallback( $gateway, $action, $this->version_of( $gateway ) )['reason'] ),
		);
	}

	/**
	 * Asks a gateway to perform one action, once.
	 *
	 * @param mixed                $order   Order or order identifier.
	 * @param string               $action  Action key.
	 * @param array<string, mixed> $context Anything the adapter may need, recorded with the call.
	 * @return array<string, mixed> Report.
	 */
	public function execute( $order, string $action, array $context = array() ): array {
		$found = WorkflowEngine::order( $order );

		if ( null === $found ) {
			return self::report( '', $action, '', false, 'no_order' );
		}

		$gateway = (string) $found->get_payment_method();

		if ( '' === $gateway ) {
			return self::report( '', $action, '', false, 'no_gateway' );
		}

		if ( GatewayCapabilities::is_fallback( $action ) ) {
			return $this->fall_back( $found, $action, 'fallback_requested' );
		}

		$availability = $this->available( $found, $action );

		if ( empty( $availability['offered'] ) ) {
			return $this->fall_back( $found, $action, 'not_available', $availability['reason'] );
		}

		$key      = self::KEY_PREFIX . $action;
		$order_id = (int) $found->get_id();

		// The outcome was already recorded: the same action on the same order is one action.
		if ( $this->log->has( $order_id, $key . ':result' ) ) {
			return array_merge(
				self::report( $gateway, $action, '', false, 'already_executed' ),
				array( 'recorded' => $this->recorded( $order_id, $key . ':result' ) )
			);
		}

		// The call was made and nothing was written back — a crash, a timeout, a request killed
		// halfway. Asking again is the duplicate this whole class exists to prevent, so the store
		// stops and says so.
		if ( $this->log->has( $order_id, $key . ':started' ) ) {
			$this->note(
				$found,
				sprintf(
					/* translators: %s: action key */
					__( 'A ação «%s» foi enviada ao gateway e o resultado não foi registado. A loja não repete a chamada: confirme no gateway antes de decidir.', 'wc-checkoutsuite' ),
					$action
				)
			);

			return self::report( $gateway, $action, '', false, 'outcome_unknown' );
		}

		// Recorded before the call, so a crash between the two leaves the intent behind. The order
		// carrying the record is what makes the guarantee survive a restart.
		$this->log->append(
			$found,
			$key . ':started',
			array(
				'gateway' => $gateway,
				'action'  => $action,
				'amount'  => isset( $context['amount'] ) ? (float) $context['amount'] : (float) $found->get_total(),
				'context' => $context,
			)
		);
		$found->save();

		$adapter = $this->adapters->for_gateway( $gateway );
		$result  = null === $adapter
			? new PaymentActionResult( PaymentActionResult::UNSUPPORTED )
			: $adapter->execute( $action, $found, array_merge( $context, array( 'key' => $key ) ) );

		$this->log->append( $found, $key . ':result', $result->to_array() );

		$completed = false;

		if ( $result->is_confirmed() ) {
			$completed = $this->complete( $found, $result->transaction_id(), $action );
		} else {
			$this->note( $found, $this->outcome_note( $action, $result ) );
		}

		$found->save();

		return array_merge(
			self::report( $gateway, $action, $result->status(), $completed, 'executed' ),
			array(
				'result'           => $result->to_array(),
				'transaction'      => $result->transaction_id(),
				'payment_complete' => $completed,
			)
		);
	}

	/**
	 * The callback path: a gateway says a payment happened.
	 *
	 * Idempotent by the order's own paid state rather than by a counter of ours: an order that is
	 * already paid has already received this news, and WooCommerce's own transition is what decides
	 * where it goes (§20 rule 5). A webhook delivered twice therefore does nothing the second time —
	 * including not reducing stock twice, which `payment_complete()` would.
	 *
	 * @param mixed  $order          Order or order identifier.
	 * @param string $transaction_id Identifier the gateway gave.
	 * @param string $source         Where the news came from, for the audit.
	 * @return array<string, mixed> Report.
	 */
	public function confirm( $order, string $transaction_id = '', string $source = '' ): array {
		$found = WorkflowEngine::order( $order );

		if ( null === $found ) {
			return self::report( '', 'confirm', '', false, 'no_order' );
		}

		$gateway  = (string) $found->get_payment_method();
		$order_id = (int) $found->get_id();
		$key      = self::KEY_PREFIX . 'confirm';

		if ( $found->is_paid() || null !== $found->get_date_paid() ) {
			return self::report( $gateway, 'confirm', PaymentActionResult::CONFIRMED, false, 'already_paid' );
		}

		if ( $this->log->has( $order_id, $key . ':result' ) ) {
			return self::report( $gateway, 'confirm', PaymentActionResult::CONFIRMED, false, 'already_confirmed' );
		}

		$this->log->append(
			$found,
			$key . ':started',
			array(
				'gateway'  => $gateway,
				'source'   => $source,
				'expected' => $transaction_id,
			)
		);

		$completed = $this->complete( $found, $transaction_id, 'confirm' );

		$this->log->append(
			$found,
			$key . ':result',
			array(
				'status'         => $completed ? PaymentActionResult::CONFIRMED : PaymentActionResult::PENDING,
				'transaction_id' => $transaction_id,
				'message'        => $source,
			)
		);

		$found->save();

		return self::report(
			$gateway,
			'confirm',
			$completed ? PaymentActionResult::CONFIRMED : PaymentActionResult::PENDING,
			$completed,
			$completed ? 'confirmed' : 'not_completed'
		);
	}

	/**
	 * Concludes the payment on the order, once, and lets WooCommerce decide the status.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $transaction_id Identifier the gateway gave.
	 * @param string   $action         Action that led here, for the note.
	 * @return bool Whether the payment was concluded.
	 */
	private function complete( WC_Order $order, string $transaction_id, string $action ): bool {
		if ( $order->is_paid() || null !== $order->get_date_paid() ) {
			return false;
		}

		// WooCommerce accepts a completion only from four of its own statuses, and a state this
		// store created is not one of them. Returning the order to `pending` first is what makes
		// `payment_complete()` do anything at all; without it a gateway could confirm a payment and
		// the store would keep the order exactly where it was.
		if ( ! self::platform_accepts_completion( $order ) ) {
			$order->update_status(
				'pending',
				sprintf(
					/* translators: %s: action that was performed */
					__( 'Payment confirmed by the gateway (%s): returned to WooCommerce\'s own awaiting-payment state so its transition can decide the order.', 'wc-checkoutsuite' ),
					$action
				)
			);
		}

		$order->payment_complete( $transaction_id );

		$order->add_order_note(
			sprintf(
				/* translators: 1: action, 2: transaction identifier */
				__( 'Payment concluded through «%1$s». Transaction: %2$s.', 'wc-checkoutsuite' ),
				$action,
				'' !== $transaction_id ? $transaction_id : __( '(none given)', 'wc-checkoutsuite' )
			)
		);

		return true;
	}

	/**
	 * Whether WooCommerce will accept a completion from the status the order is in.
	 *
	 * Read from WooCommerce rather than copied, and read through the same filter WooCommerce itself
	 * applies, so a store that extends the list extends this answer too.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function platform_accepts_completion( WC_Order $order ): bool {
		$statuses = array( 'on-hold', 'pending', 'failed', 'cancelled' );

		// Read from WooCommerce when this version has it, and named here when it does not. The
		// class is resolved dynamically on purpose: the list is a platform detail that moved into an
		// enum in WooCommerce 10.9, and a store on an older version has the same four in the filter
		// below. Reading what is there beats pinning a constant that does not exist yet.
		$class = '\Automattic\WooCommerce\Enums\OrderStatus';

		if ( class_exists( $class ) && defined( $class . '::PAYMENT_COMPLETE_STATUSES' ) ) {
			$statuses = (array) constant( $class . '::PAYMENT_COMPLETE_STATUSES' );
		}

		/** This filter is documented in WooCommerce. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied so a store that extends the list extends this answer too.
		$statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', $statuses, $order );

		return $order->has_status( (array) $statuses );
	}

	/**
	 * What the store does when it cannot ask the gateway.
	 *
	 * The fallback §13.2 step 4 names: the customer is sent to WooCommerce's own pay-for-order page.
	 * Nothing is charged and nothing is promised.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $action Action that was wanted.
	 * @param string   $why    Why the store fell back.
	 * @param string   $reason Sentence for the merchant.
	 * @return array<string, mixed> Report.
	 */
	private function fall_back( WC_Order $order, string $action, string $why, string $reason = '' ): array {
		$url = method_exists( $order, 'get_checkout_payment_url' ) ? (string) $order->get_checkout_payment_url() : '';

		if ( '' === $reason ) {
			$reason = $this->capabilities->fallback( (string) $order->get_payment_method(), $action, $this->version_of( (string) $order->get_payment_method() ) )['reason'];
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: action, 2: reason */
				__( 'No gateway action was performed («%1$s»). %2$s', 'wc-checkoutsuite' ),
				$action,
				$reason
			)
		);
		$order->save();

		return array_merge(
			self::report( (string) $order->get_payment_method(), $action, PaymentActionResult::UNSUPPORTED, false, $why ),
			array(
				'fallback' => GatewayCapabilities::FALLBACK,
				'url'      => $url,
				'reason'   => $reason,
			)
		);
	}

	/**
	 * The note one non-confirming outcome leaves.
	 *
	 * @param string              $action Action.
	 * @param PaymentActionResult $result Result.
	 * @return string
	 */
	private function outcome_note( string $action, PaymentActionResult $result ): string {
		$labels = array(
			PaymentActionResult::PENDING     => __( 'O gateway aceitou o pedido e ainda não decidiu', 'wc-checkoutsuite' ),
			PaymentActionResult::REFUSED     => __( 'O gateway recusou', 'wc-checkoutsuite' ),
			PaymentActionResult::UNSUPPORTED => __( 'O gateway não sabe executar esta ação', 'wc-checkoutsuite' ),
		);

		return sprintf(
			/* translators: 1: action, 2: what the gateway answered, 3: the gateway's own words */
			__( 'Payment action «%1$s»: %2$s. %3$s', 'wc-checkoutsuite' ),
			$action,
			$labels[ $result->status() ] ?? $result->status(),
			$result->message()
		);
	}

	/**
	 * Adds a note to an order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Note.
	 * @return void
	 */
	private function note( WC_Order $order, string $text ): void {
		$order->add_order_note( $text );
	}

	/**
	 * The recorded outcome of a key, if there is one.
	 *
	 * @param int    $order_id Order identifier.
	 * @param string $key      Key.
	 * @return array<string, mixed>
	 */
	private function recorded( int $order_id, string $key ): array {
		foreach ( $this->log->log( $order_id ) as $entry ) {
			if ( (string) ( $entry['key'] ?? '' ) === $key ) {
				return $entry;
			}
		}

		return array();
	}

	/**
	 * The installed version of a gateway, when the gateway reports one.
	 *
	 * The version the capability registry judges staleness against. A gateway that does not report
	 * one answers an empty string, and the registry treats an unknown version as "cannot judge"
	 * rather than as staleness.
	 *
	 * @param string $gateway Gateway identifier.
	 * @return string
	 */
	private function version_of( string $gateway ): string {
		if ( '' === $gateway || ! function_exists( 'WC' ) ) {
			return '';
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$found    = $gateways[ $gateway ] ?? null;

		return is_object( $found ) && isset( $found->version ) ? (string) $found->version : '';
	}

	/**
	 * Builds the answer every path gives, so a caller reads one shape.
	 *
	 * @param string $gateway Gateway.
	 * @param string $action  Action.
	 * @param string $status  Outcome, when there was one.
	 * @param bool   $done    Whether the store concluded the payment.
	 * @param string $reason  Why.
	 * @return array<string, mixed>
	 */
	private static function report( string $gateway, string $action, string $status, bool $done, string $reason ): array {
		return array(
			'gateway'          => $gateway,
			'action'           => $action,
			'status'           => $status,
			'payment_complete' => $done,
			'reason'           => $reason,
		);
	}
}
