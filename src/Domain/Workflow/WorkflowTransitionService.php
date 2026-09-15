<?php
/**
 * A decision moves an order, and the clock can make one too.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WC_Order;

/**
 * The decisions of §13.4 and §13.5, and the clock behind the last of them.
 *
 * A decision is a **transition**, not a payment: approving an order moves it to the status the
 * workflow names and records that it was approved. What pays is a payment action performed by a
 * transition that carries one and a gateway that declares it can be performed — §12.4's chain — and
 * that belongs to the phases that build it. This class is where that boundary is kept: there is no
 * call to a gateway in it, and the report of every decision says so.
 *
 * Idempotency is the whole design of `decide()`. Every decision writes its key to the order's log
 * before it moves anything, so a retried request, a double-clicked button and a webhook delivered
 * twice are one decision. The order carries the record, which is what makes the guarantee survive
 * the request that made it.
 *
 * @see \ROADMAP.md sections 13.4, 13.5
 */
final class WorkflowTransitionService {

	/**
	 * Applies a decision to an order.
	 *
	 * @param mixed                $order    Order or order identifier.
	 * @param string               $decision Decision key.
	 * @param array<string, mixed> $args     Extra context, recorded with the decision.
	 * @return array<string, mixed> Report.
	 */
	public static function decide( $order, string $decision, array $args = array() ): array {
		$found = WorkflowEngine::order( $order );

		if ( null === $found ) {
			return self::report( '', $decision, false, 'no_order' );
		}

		$repository = new WorkflowRepository();
		$state      = $repository->state( (int) $found->get_id() );
		$id         = (string) ( $state['workflow'] ?? '' );

		if ( '' === $id ) {
			return self::report( '', $decision, false, 'no_workflow' );
		}

		$workflow = $repository->find( $id );

		if ( null === $workflow ) {
			return self::report( $id, $decision, false, 'workflow_gone' );
		}

		if ( ! $workflow->answers( $decision ) ) {
			return self::report( $id, $decision, false, 'decision_not_configured' );
		}

		if ( ! $workflow->is_enabled() ) {
			return self::report( $id, $decision, false, 'workflow_disabled' );
		}

		$key   = WorkflowEngine::key( $id, $decision );
		$entry = array(
			'workflow' => $id,
			'decision' => $decision,
			'to'       => $workflow->target_for( $decision ),
			'event'    => Workflows::event_for( $decision ),
			'args'     => $args,
		);

		if ( ! $repository->append( $found, $key, $entry ) ) {
			return self::report( $id, $decision, false, 'already_decided', $workflow );
		}

		$moved = WorkflowEngine::move( $found, $workflow->target_for( $decision ), $workflow->name() );

		$found->save();

		if ( $moved ) {
			WorkflowScheduler::cancel( $found );
		}

		// §13.4's chain, and the half that is not a status: approving runs the payment action the
		// workflow's strategy asks for, and only a gateway that says the money moved concludes the
		// payment. A strategy that performs no action — `none`, or asking the customer to pay
		// through WooCommerce's own page — runs nothing, which is a decision and not a gap.
		$payment = self::perform_payment( $found, $workflow, $decision );

		return array_merge(
			self::report( $id, $decision, $moved, $moved ? 'applied' : 'already_in_status', $workflow ),
			array( 'payment_action' => $payment )
		);
	}

	/**
	 * Runs the payment action a decision asks for, when it asks for one.
	 *
	 * Only the decision that concludes a sale — approving — performs an action. Rejecting and
	 * expiring move the order and nothing else, which is what §13.4 and §13.5 ask for: a rejection
	 * that captured money would be the worst bug this plugin could ship.
	 *
	 * @param WC_Order           $order    Order.
	 * @param WorkflowDefinition $workflow Workflow.
	 * @param string             $decision Decision key.
	 * @return array<string, mixed> Report, or an empty array when the workflow performs nothing.
	 */
	private static function perform_payment( WC_Order $order, WorkflowDefinition $workflow, string $decision ): array {
		if ( Workflows::DECISION_APPROVE !== $decision ) {
			return array();
		}

		$strategy = $workflow->payment_strategy();
		$needed   = Workflows::capabilities_for( $strategy );

		if ( array() === $needed ) {
			return array(
				'strategy' => $strategy,
				'action'   => '',
				'reason'   => 'strategy_performs_nothing',
			);
		}

		// The last capability in the chain is the one that concludes the sale for this strategy:
		// a capture for `capture_after_approval`, a capture after an authorisation for
		// `authorize_now`, and the generation itself for `generate_after_approval`.
		$action = (string) end( $needed );

		return ( new \WCCheckoutSuite\Domain\Payments\PaymentActionService() )->execute(
			$order,
			$action,
			array(
				'workflow' => $workflow->id(),
				'decision' => $decision,
				'strategy' => $strategy,
				'amount'   => (float) $order->get_total(),
			)
		);
	}

	/**
	 * Whether a decision has already been made about an order.
	 *
	 * @param int    $order_id Order identifier.
	 * @param string $decision Decision key.
	 * @return bool
	 */
	public static function decided( int $order_id, string $decision ): bool {
		$state = ( new WorkflowRepository() )->state( $order_id );
		$id    = (string) ( $state['workflow'] ?? '' );

		if ( '' === $id ) {
			return false;
		}

		return ( new WorkflowRepository() )->has( $order_id, WorkflowEngine::key( $id, $decision ) );
	}

	/**
	 * The decisions this build lets a person make.
	 *
	 * @return array<int, string>
	 */
	public static function manual(): array {
		return Workflows::manual_decisions();
	}

	/**
	 * Builds the answer a caller gets, so every path says the same things.
	 *
	 * `payment` is in the report because its absence is the phase's gate: a caller that wants to know
	 * whether anything was charged is told, in the answer, that nothing was.
	 *
	 * @param string                  $id       Workflow identifier.
	 * @param string                  $decision Decision key.
	 * @param bool                    $applied  Whether the order moved.
	 * @param string                  $reason   Why.
	 * @param WorkflowDefinition|null $workflow Workflow, when one was found.
	 * @return array<string, mixed>
	 */
	private static function report( string $id, string $decision, bool $applied, string $reason, ?WorkflowDefinition $workflow = null ): array {
		return array(
			'workflow' => $id,
			'decision' => $decision,
			'applied'  => $applied,
			'reason'   => $reason,
			'status'   => null === $workflow ? '' : $workflow->target_for( $decision ),
			'event'    => null === $workflow || ! $workflow->tells( Workflows::event_for( $decision ) )
				? ''
				: Workflows::event_for( $decision ),
			'payment'  => 'none',
		);
	}
}
