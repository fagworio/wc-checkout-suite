<?php
/**
 * The workflow engine: it runs a workflow once, and only once.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WC_Order;
use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WCCheckoutSuite\Domain\Stock\InventoryReservationService;

/**
 * The engine §13.7 names, with the gate of this phase built into it.
 *
 * The gate is **idempotency without a payment action**, and both halves are structural rather than
 * promises:
 *
 * - **Idempotent.** Every effect is keyed and recorded in the order's audit log *before* it is
 *   applied ({@see WorkflowRepository::append()}). A repeated webhook, a retried request and an
 *   operator pressing approve twice arrive with the same key, and the second arrival finds the
 *   entry and does nothing. The order carries the record, so the answer survives a restart, a
 *   cache flush and a second worker.
 * - **No payment action.** Nothing here touches a gateway. It moves a status, records what it did
 *   and — when the workflow asks the customer to be told — leaves the telling to the surface that
 *   already sends order e-mails. The strategies that would charge or reserve are refused by the
 *   validator by name, so a workflow in this build cannot even ask for one.
 *
 * The engine runs at the two moments WooCommerce creates an order: the classic checkout and the
 * Store API one. Those are the moments §13.2's "checkout enviado" means, and they are before a
 * gateway has had its say, which is what lets an order wait.
 *
 * @see \ROADMAP.md sections 12.4, 13.2, 13.4, 13.7
 */
final class WorkflowEngine {

	/**
	 * Order created by the classic checkout.
	 */
	public const HOOK_CLASSIC = 'woocommerce_checkout_order_processed';

	/**
	 * Order created by the Store API (the Blocks checkout).
	 */
	public const HOOK_API = 'woocommerce_store_api_checkout_order_processed';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK_CLASSIC, array( self::class, 'handle' ), 20, 1 );
		add_action( self::HOOK_API, array( self::class, 'handle' ), 20, 1 );
	}

	/**
	 * Hook callback.
	 *
	 * Separate from {@see self::run()} because an action callback returns nothing: the report
	 * `run()` gives is for the callers that ask for it — the screen, the diagnostics and the proofs
	 * — and not something WordPress would know what to do with.
	 *
	 * @param mixed $order Order or order identifier.
	 * @return void
	 */
	public static function handle( $order ): void {
		self::run( $order );
	}

	/**
	 * Runs the workflow an order enters, once.
	 *
	 * @param mixed $order Order or order identifier.
	 * @return array<string, mixed> Report of what was done, and of what was not.
	 */
	public static function run( $order ): array {
		$found = self::order( $order );

		if ( null === $found ) {
			return array(
				'workflow' => '',
				'applied'  => false,
				'reason'   => 'no_order',
			);
		}

		$repository = new WorkflowRepository();
		$state      = $repository->state( (int) $found->get_id() );

		if ( array() !== $state ) {
			// The order already entered a workflow. Where it is now is the outcome of that
			// automation, and a second creation event is not a reason to run it again.
			return array(
				'workflow' => (string) ( $state['workflow'] ?? '' ),
				'applied'  => false,
				'reason'   => 'already_entered',
			);
		}

		$context  = self::context( $found );
		$workflow = WorkflowEvaluator::resolve( $repository->raw(), Workflows::TRIGGER_CHECKOUT_SUBMITTED, $context );

		if ( null === $workflow ) {
			return array(
				'workflow' => '',
				'applied'  => false,
				'reason'   => 'no_workflow',
			);
		}

		$key = self::key( $workflow->id(), 'entered' );

		if ( ! $repository->append(
			$found,
			$key,
			array(
				'workflow' => $workflow->id(),
				'event'    => Workflows::EVENT_RECEIVED,
				'status'   => $workflow->initial_status(),
			)
		) ) {
			// A log that already holds the entry means the effect has been applied, or is about to
			// be by the request that wrote it. Either way, not again.
			return array(
				'workflow' => $workflow->id(),
				'applied'  => false,
				'reason'   => 'already_applied',
			);
		}

		$hours    = $workflow->expires() ? $workflow->expires_after_hours() : 0;
		$deadline = $hours > 0 ? gmdate( 'c', time() + ( $hours * HOUR_IN_SECONDS ) ) : '';

		$repository->remember( $found, $workflow->id(), $hours, $deadline );

		$moved = self::move( $found, $workflow->initial_status(), $workflow->name() );

		// §21: the hold is taken when the workflow takes the order, not when the checkout created it
		// — the order may wait for hours, and the platform's default hold is one hour. The service
		// reads the length from this workflow, so a strategy of `none` holds nothing.
		$reservation = ( new InventoryReservationService( $repository ) )->reserve( $found );

		if ( $hours > 0 ) {
			WorkflowScheduler::schedule( $found, $workflow, $deadline );
		}

		$found->save();

		return array(
			'workflow' => $workflow->id(),
			'applied'  => $moved,
			'reason'   => $moved ? 'entered' : 'already_in_status',
			'status'   => $workflow->initial_status(),
			'stock'    => $reservation,
			'event'    => $workflow->tells( Workflows::EVENT_RECEIVED ) ? Workflows::EVENT_RECEIVED : '',
			// What the workflow asked for and this build does not do. Reported rather than hidden:
			// the screen and the audit read the same answer.
			'deferred' => array(
				'inventory' => $workflow->inventory_strategy(),
				'payment'   => $workflow->payment_strategy(),
			),
		);
	}

	/**
	 * Moves an order into a status, recording why.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Status identifier.
	 * @param string   $note   Note left on the order.
	 * @return bool Whether the order moved.
	 */
	public static function move( WC_Order $order, string $status, string $note ): bool {
		if ( '' === $status || $order->get_status() === $status ) {
			return false;
		}

		$order->update_status(
			$status,
			sprintf(
				/* translators: %s: name of the workflow that moved the order. */
				__( 'Workflow «%s».', 'wc-checkoutsuite' ),
				$note
			)
		);

		return true;
	}

	/**
	 * The context a workflow's rule is answered against.
	 *
	 * Both halves of §13.2 step 1's example: what the cart held — the trusted cart context the
	 * checkout already builds — and what the order carries, which is where a field's answer lives
	 * once the order exists. A rule reading a field sees the value the customer submitted, not the
	 * one they typed afterwards.
	 *
	 * @param WC_Order $order Order.
	 * @return FieldContext
	 */
	public static function context( WC_Order $order ): FieldContext {
		$definitions = PublishedDocument::read()->fields();
		$values      = array();

		if ( array() !== $definitions ) {
			// What the order carries: the value the customer submitted at the checkout, which is
			// what a rule about "the licence was sent" is asking about.
			$snapshot = ( new OrderFieldsService() )->read( $order, $definitions );

			foreach ( $definitions as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

				if ( '' === $id ) {
					continue;
				}

				$value = $snapshot->get( $id );

				if ( null !== $value && '' !== $value && array() !== $value ) {
					$values[ $id ] = $value;
				}
			}

			// And what the customer's own profile holds, which the order alone cannot answer: a rule
			// about a document the store keeps for the customer is asking about the profile, and the
			// order's snapshot is only what that order happened to carry.
			$user_id = (int) $order->get_customer_id();

			if ( $user_id > 0 ) {
				$profile = ( new CustomerFieldsService() )->values( $user_id );

				foreach ( $definitions as $raw ) {
					if ( ! is_array( $raw ) ) {
						continue;
					}

					$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

					if ( '' === $id || isset( $values[ $id ] ) ) {
						continue;
					}

					$value = $profile[ $id ] ?? null;

					if ( is_scalar( $value ) && '' !== (string) $value ) {
						$values[ $id ] = (string) $value;
					}
				}
			}
		}

		return ( new CheckoutConditionContext() )->context( $values, 'workflow' );
	}

	/**
	 * The key one effect is recorded under.
	 *
	 * A workflow, what happened and — for a transition — which decision: the three things that
	 * together say "this exact thing has already happened to this order".
	 *
	 * @param string $workflow Workflow identifier.
	 * @param string $what     What happened: `entered`, `expired`, or a decision key.
	 * @return string
	 */
	public static function key( string $workflow, string $what ): string {
		return $workflow . ':' . $what;
	}

	/**
	 * The order behind whatever a hook handed over.
	 *
	 * @param mixed $order Order or identifier.
	 * @return WC_Order|null
	 */
	public static function order( $order ): ?WC_Order {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$found = function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : null;

		return $found instanceof WC_Order ? $found : null;
	}
}
