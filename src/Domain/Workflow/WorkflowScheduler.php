<?php
/**
 * The clock: an order that waits too long is decided for it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WC_Order;

/**
 * §13.5's expiration, and the safety net under it.
 *
 * Two mechanisms, because one is not enough. A single event is scheduled for the exact moment a
 * workflow said its order expires — precise, and lost if the action table is cleared, a deploy
 * changes the code, or the site was down when the moment passed. Behind it a recurring sweep asks
 * "which orders are past their deadline?" and answers the same question from the orders themselves.
 *
 * Both go through {@see WorkflowTransitionService::decide()}, so both are idempotent by the same
 * record: an order that the event expired and the sweep also found is expired once, and the entry
 * in its log is what says so.
 *
 * Expiration does **nothing to stock and nothing to a payment**, because this build executes neither
 * strategy. What §13.5 asks for beyond the status — releasing a reservation, voiding an
 * authorisation — is a release and a void on services that do not exist yet, and inventing them
 * here would be inventing the thing the next two phases are for.
 *
 * @see \ROADMAP.md section 13.5
 */
final class WorkflowScheduler {

	/**
	 * The scheduled moment.
	 */
	public const HOOK_EXPIRE = 'wccs_workflow_expire';

	/**
	 * The recurring sweep.
	 */
	public const HOOK_SWEEP = 'wccs_workflow_sweep';

	/**
	 * How many orders one sweep looks at.
	 *
	 * A bound rather than everything: a sweep that tried to expire every waiting order in one
	 * request would be a request that times out on the store it is meant to help, and the next run
	 * picks up where this one stopped.
	 */
	public const SWEEP_LIMIT = 50;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK_EXPIRE, array( self::class, 'on_expire' ), 10, 1 );
		add_action( self::HOOK_SWEEP, array( self::class, 'on_sweep' ) );

		if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_SWEEP );
		}
	}

	/**
	 * Hook callback for the scheduled moment.
	 *
	 * @param mixed $order_id Order identifier.
	 * @return void
	 */
	public static function on_expire( $order_id ): void {
		self::expire( $order_id );
	}

	/**
	 * Hook callback for the recurring sweep.
	 *
	 * @return void
	 */
	public static function on_sweep(): void {
		self::sweep();
	}

	/**
	 * Schedules the moment an order expires.
	 *
	 * @param WC_Order           $order    Order.
	 * @param WorkflowDefinition $workflow Workflow.
	 * @param string             $deadline ISO-8601 moment.
	 * @return bool Whether an event was scheduled.
	 */
	public static function schedule( WC_Order $order, WorkflowDefinition $workflow, string $deadline ): bool {
		if ( ! $workflow->expires() || '' === $deadline ) {
			return false;
		}

		$when = (int) strtotime( $deadline );

		if ( $when <= 0 ) {
			return false;
		}

		// Already scheduled for this order and this moment: a retried checkout request must not
		// queue a second expiration.
		if ( wp_next_scheduled( self::HOOK_EXPIRE, array( (int) $order->get_id() ) ) ) {
			return false;
		}

		return (bool) wp_schedule_single_event( $when, self::HOOK_EXPIRE, array( (int) $order->get_id() ) );
	}

	/**
	 * Drops a pending expiration, because the order was decided.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function cancel( WC_Order $order ): void {
		$timestamp = wp_next_scheduled( self::HOOK_EXPIRE, array( (int) $order->get_id() ) );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_EXPIRE, array( (int) $order->get_id() ) );
		}
	}

	/**
	 * Expires one order, if it is still waiting and past its deadline.
	 *
	 * @param mixed $order_id Order identifier.
	 * @return array<string, mixed> Report.
	 */
	public static function expire( $order_id ): array {
		$order = WorkflowEngine::order( $order_id );

		if ( null === $order ) {
			return array(
				'workflow' => '',
				'applied'  => false,
				'reason'   => 'no_order',
			);
		}

		$repository = new WorkflowRepository();
		$state      = $repository->state( (int) $order->get_id() );
		$id         = (string) ( $state['workflow'] ?? '' );

		if ( '' === $id ) {
			return array(
				'workflow' => '',
				'applied'  => false,
				'reason'   => 'no_workflow',
			);
		}

		$workflow = $repository->find( $id );

		if ( null === $workflow || ! $workflow->expires() ) {
			return array(
				'workflow' => $id,
				'applied'  => false,
				'reason'   => 'no_expiration',
			);
		}

		if ( ! self::is_due( $state ) ) {
			return array(
				'workflow' => $id,
				'applied'  => false,
				'reason'   => 'not_due',
			);
		}

		// A decision already made is a decision: an order that was approved a minute before its
		// deadline is not expired by a job that was queued an hour earlier.
		foreach ( Workflows::manual_decisions() as $decision ) {
			if ( WorkflowTransitionService::decided( (int) $order->get_id(), $decision ) ) {
				return array(
					'workflow' => $id,
					'applied'  => false,
					'reason'   => 'decided',
				);
			}
		}

		return WorkflowTransitionService::decide( $order, Workflows::DECISION_EXPIRE );
	}

	/**
	 * Looks for orders past their deadline.
	 *
	 * The question is asked of the orders in the statuses the workflows start in, which is a query
	 * the orders table answers — and not of every order with a meta key, which is a query that grows
	 * with the store's history.
	 *
	 * @return array<string, mixed> Report.
	 */
	public static function sweep(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'checked' => 0,
				'expired' => array(),
			);
		}

		$workflows = ( new WorkflowRepository() )->all();
		$statuses  = array();

		foreach ( $workflows as $workflow ) {
			if ( $workflow->is_enabled() && $workflow->expires() && '' !== $workflow->initial_status() ) {
				$statuses[ $workflow->initial_status() ] = true;
			}
		}

		if ( array() === $statuses ) {
			return array(
				'checked' => 0,
				'expired' => array(),
			);
		}

		$orders = wc_get_orders(
			array(
				'status' => array_keys( $statuses ),
				'limit'  => self::SWEEP_LIMIT,
				'return' => 'ids',
			)
		);

		$expired = array();

		foreach ( (array) $orders as $order_id ) {
			$report = self::expire( $order_id );

			if ( ! empty( $report['applied'] ) ) {
				$expired[] = (int) $order_id;
			}
		}

		return array(
			'checked' => count( (array) $orders ),
			'expired' => $expired,
		);
	}

	/**
	 * Whether an order is past the deadline its workflow gave it.
	 *
	 * @param array<string, mixed> $state Stored workflow state.
	 * @return bool
	 */
	private static function is_due( array $state ): bool {
		$deadline = (string) ( $state['deadline'] ?? '' );

		if ( '' !== $deadline ) {
			$when = (int) strtotime( $deadline );

			return $when > 0 && $when <= time();
		}

		// A store whose workflow changed its mind about the hours still has orders waiting: the
		// deadline is computed from when they entered, so the answer is the same either way.
		$hours   = (int) ( $state['hours'] ?? 0 );
		$entered = (int) strtotime( (string) ( $state['entered'] ?? '' ) );

		if ( $hours <= 0 || $entered <= 0 ) {
			return false;
		}

		return ( $entered + ( $hours * HOUR_IN_SECONDS ) ) <= time();
	}
}
