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

		return self::report( $id, $decision, $moved, $moved ? 'applied' : 'already_in_status', $workflow );
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
