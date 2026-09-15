<?php
/**
 * What a workflow would do, without doing it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry;
use WCCheckoutSuite\Domain\Statuses\OrderStatusRepository;

/**
 * §13.6's simulator: the rule that matched, and everything that would follow.
 *
 * "O simulador não executa cobrança" is the section's one hard rule, and this class obeys it by
 * being unable to do anything else: it has no order, calls no transition and writes nothing. What it
 * does is answer, in the order a merchant reads it, what the configuration would decide.
 *
 * It is also the honest way to show the strategies this build cannot execute. A simulator that
 * reported "reservar até decisão" as if it would happen would be the same lie the validator refuses
 * to store; so the report names the strategy **and says whether the store can carry it out today**,
 * which is the same list the validator reads. One source of truth, two readers.
 *
 * @see \ROADMAP.md section 13.6
 */
final class WorkflowSimulator {

	/**
	 * Runs one scenario through the stored configuration.
	 *
	 * @param array<int, mixed>    $workflows Stored workflows.
	 * @param string               $trigger   Moment simulated.
	 * @param FieldContext         $context   Values the merchant typed.
	 * @param array<string, mixed> $sample    What the merchant described, echoed back.
	 * @return array<string, mixed> Report.
	 */
	public static function run( array $workflows, string $trigger, FieldContext $context, array $sample = array() ): array {
		$matching = WorkflowEvaluator::matching( $workflows, $trigger, $context );
		$chosen   = $matching[0] ?? null;

		if ( null === $chosen ) {
			return array(
				'trigger'     => $trigger,
				'sample'      => $sample,
				'matched'     => false,
				'workflow'    => '',
				'overlaps'    => array(),
				// No automation is a real answer: the store's own process handles the order, and
				// saying so is better than showing an empty panel.
				'next'        => __( 'Nenhum workflow corresponde: o pedido segue o processo da própria loja.', 'wc-checkoutsuite' ),
				'inventory'   => array(
					'value'      => Workflows::INVENTORY_NONE,
					'label'      => Workflows::inventory_strategies()[ Workflows::INVENTORY_NONE ],
					'executable' => true,
				),
				'payment'     => array(
					'value'      => Workflows::PAYMENT_NONE,
					'label'      => Workflows::payment_strategies()[ Workflows::PAYMENT_NONE ],
					'executable' => true,
				),
				'transitions' => array(),
				'fallbacks'   => array(),
				'expires'     => 0,
			);
		}

		$transitions = array();

		foreach ( $chosen->transitions() as $decision => $status ) {
			$transitions[] = array(
				'decision' => (string) $decision,
				'label'    => Workflows::decisions()[ (string) $decision ] ?? (string) $decision,
				'status'   => (string) $status,
				// What the customer would be told, which is the thing a merchant gets wrong: a
				// rejection that says nothing is a customer waiting for an order that will not come.
				'event'    => $chosen->tells( Workflows::event_for( (string) $decision ) )
					? Workflows::event_for( (string) $decision )
					: '',
			);
		}

		$overlaps = array();

		foreach ( WorkflowEvaluator::overlaps( $workflows, $trigger, $context ) as $pair ) {
			$overlaps[] = $pair['workflow'] . ' / ' . $pair['other'];
		}

		return array(
			'trigger'     => $trigger,
			'sample'      => $sample,
			'matched'     => true,
			'workflow'    => $chosen->id(),
			'name'        => $chosen->name(),
			'priority'    => $chosen->priority(),
			'overlaps'    => $overlaps,
			'initial'     => array(
				'status' => $chosen->initial_status(),
				'label'  => self::status_label( $chosen->initial_status() ),
			),
			'inventory'   => array(
				'value'      => $chosen->inventory_strategy(),
				'label'      => Workflows::inventory_strategies()[ $chosen->inventory_strategy() ] ?? $chosen->inventory_strategy(),
				'executable' => Workflows::can_execute( 'inventory', $chosen->inventory_strategy() ),
			),
			'payment'     => array(
				'value'      => $chosen->payment_strategy(),
				'label'      => Workflows::payment_strategies()[ $chosen->payment_strategy() ] ?? $chosen->payment_strategy(),
				'executable' => Workflows::can_execute( 'payment', $chosen->payment_strategy() ),
			),
			'transitions' => $transitions,
			'fallbacks'   => $chosen->fallbacks(),
			'expires'     => $chosen->expires() ? $chosen->expires_after_hours() : 0,
			'next'        => self::next_action( $chosen ),
		);
	}

	/**
	 * What happens first, in words.
	 *
	 * The simulation's one sentence, and the sentence the merchant is really asking for: not what the
	 * configuration contains but what the store will do with an order.
	 *
	 * @param WorkflowDefinition $workflow Workflow.
	 * @return string
	 */
	private static function next_action( WorkflowDefinition $workflow ): string {
		$label = self::status_label( $workflow->initial_status() );

		if ( $workflow->expires() ) {
			return sprintf(
				/* translators: 1: status label, 2: number of hours, 3: status the order expires into */
				__( 'O pedido entra em «%1$s» e, sem decisão em %2$d horas, passa a «%3$s».', 'wc-checkoutsuite' ),
				$label,
				$workflow->expires_after_hours(),
				self::status_label( $workflow->target_for( Workflows::DECISION_EXPIRE ) )
			);
		}

		return sprintf(
			/* translators: %s: status label */
			__( 'O pedido entra em «%s» e fica à espera de uma decisão.', 'wc-checkoutsuite' ),
			$label
		);
	}

	/**
	 * What a status is called, with WooCommerce's own labels included.
	 *
	 * @param string $status Status identifier.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		if ( '' === $status ) {
			return '';
		}

		$known = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		if ( isset( $known[ 'wc-' . $status ] ) ) {
			return (string) $known[ 'wc-' . $status ];
		}

		$found = ( new OrderStatusRepository() )->find( $status );

		return null === $found ? $status : $found->label();
	}

	/**
	 * Whether a rule matches, for the editor's own preview.
	 *
	 * Separate from `run()` because the editor asks it while the merchant is typing, before a
	 * workflow exists — and answering "does this rule match these values?" is the one question that
	 * does not need a workflow at all.
	 *
	 * @param array<string, mixed> $rules   Rule tree.
	 * @param FieldContext         $context Values.
	 * @return bool
	 */
	public static function matches( array $rules, FieldContext $context ): bool {
		return array() === $rules || ( new TreeConditionEvaluator() )->evaluate( $rules, $context );
	}

	/**
	 * The statuses a workflow may name, so an editor offers what exists.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function statuses(): array {
		return array_values(
			array_map(
				static fn( array $entry ): array => array(
					'value' => (string) $entry['id'],
					'label' => (string) $entry['label'],
				),
				OrderStatusRegistry::inventory()
			)
		);
	}
}
