<?php
/**
 * Which workflow an order enters.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Picks the workflow an order runs through.
 *
 * The same three rules the checkout profiles use, and for the same reason: an automation that
 * answered two ways would be two answers to one question.
 *
 * 1. **Only enabled workflows are considered**, and only those whose trigger is the one that fired.
 * 2. **The rule is asked of the shared engine** (§14), against the trusted context the server
 *    builds — the workflow's conditions are the same tree a field or a profile carries, validated
 *    by the same validator and answered by the same evaluator.
 * 3. **Priority decides, and the declaration order decides ties**, so the same order always runs
 *    through the same automation.
 *
 * Unlike the profiles, there is **no silent fallback**: an order that matches no workflow is an
 * order the store's own process handles, which is a real answer and not a missing one. A store that
 * wants every order to enter an automation writes a workflow with no conditions.
 *
 * @see \ROADMAP.md sections 13.2 and 14
 */
final class WorkflowEvaluator {

	/**
	 * The workflows that apply, best first.
	 *
	 * @param array<int, mixed> $workflows Stored workflows.
	 * @param string            $trigger   The moment that fired.
	 * @param FieldContext      $context   Trusted context.
	 * @return array<int, WorkflowDefinition>
	 */
	public static function matching( array $workflows, string $trigger, FieldContext $context ): array {
		$matching = array();

		foreach ( $workflows as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$workflow = WorkflowDefinition::from_array( $raw );

			if ( '' === $workflow->id() || ! $workflow->is_enabled() || $workflow->trigger() !== $trigger ) {
				continue;
			}

			if ( ! $workflow->is_unconditional() && ! ( new TreeConditionEvaluator() )->evaluate( $workflow->conditions(), $context ) ) {
				continue;
			}

			$matching[] = array(
				'workflow' => $workflow,
				'index'    => (int) $index,
			);
		}

		usort(
			$matching,
			static function ( array $a, array $b ): int {
				$by_priority = $b['workflow']->priority() <=> $a['workflow']->priority();

				return 0 !== $by_priority ? $by_priority : $a['index'] <=> $b['index'];
			}
		);

		return array_values( array_map( static fn( array $entry ): WorkflowDefinition => $entry['workflow'], $matching ) );
	}

	/**
	 * The workflow an order runs through, or null.
	 *
	 * @param array<int, mixed> $workflows Stored workflows.
	 * @param string            $trigger   The moment that fired.
	 * @param FieldContext      $context   Trusted context.
	 * @return WorkflowDefinition|null
	 */
	public static function resolve( array $workflows, string $trigger, FieldContext $context ): ?WorkflowDefinition {
		$matching = self::matching( $workflows, $trigger, $context );

		return $matching[0] ?? null;
	}

	/**
	 * The workflows that could apply to the same order, which is a configuration smell.
	 *
	 * The checkout profiles report an overlap as a warning (§6.9) and this does the same for
	 * automations: two workflows that both match start the same order in two directions, and while
	 * priority settles it, the merchant should be able to see it.
	 *
	 * @param array<int, mixed> $workflows Stored workflows.
	 * @param string            $trigger   The moment that fired.
	 * @param FieldContext      $context   Trusted context.
	 * @return array<int, array{workflow: string, other: string}>
	 */
	public static function overlaps( array $workflows, string $trigger, FieldContext $context ): array {
		$matching = self::matching( $workflows, $trigger, $context );
		$overlaps = array();

		foreach ( $matching as $index => $workflow ) {
			foreach ( array_slice( $matching, $index + 1 ) as $other ) {
				$overlaps[] = array(
					'workflow' => $workflow->id(),
					'other'    => $other->id(),
				);
			}
		}

		return $overlaps;
	}
}
