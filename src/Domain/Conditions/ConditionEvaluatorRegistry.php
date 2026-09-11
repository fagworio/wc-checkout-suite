<?php
/**
 * Condition evaluator registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\AbstractRegistry;

/**
 * Holds the condition evaluators available to the validation pipeline.
 */
final class ConditionEvaluatorRegistry extends AbstractRegistry {

	/**
	 * Contract version this registry accepts.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * Human readable kind of item, used in diagnostics.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'condition evaluator';
	}

	/**
	 * Key used when nothing else is configured.
	 *
	 * `rules` since WCCS-034. The engine was registered in WCCS-033 and answered
	 * nothing by default — deliberately, so the storefront could not change
	 * behaviour as a side effect of the engine existing. The policy task is what
	 * makes it the default: the trusted context answers for every source, the
	 * browser half evaluates the same tree, and the two halves have to agree on the
	 * same document or a field the browser hides would be refused by the server as
	 * a missing required one.
	 *
	 * `always` stays registered and reachable by name, because a document may pin
	 * the evaluator it was written against.
	 */
	public const DEFAULT_KEY = 'rules';

	/**
	 * Registers an evaluator.
	 *
	 * @param ConditionEvaluatorInterface $evaluator Evaluator.
	 * @param string                      $source    Origin, for diagnostics.
	 * @return bool
	 */
	public function register_evaluator( ConditionEvaluatorInterface $evaluator, string $source = 'core' ): bool {
		return $this->register( $evaluator->key(), $evaluator, $source );
	}

	/**
	 * Returns an evaluator or null.
	 *
	 * @param string $key Evaluator key.
	 * @return ConditionEvaluatorInterface|null
	 */
	public function evaluator( string $key ): ?ConditionEvaluatorInterface {
		$evaluator = $this->get( $key );

		return $evaluator instanceof ConditionEvaluatorInterface ? $evaluator : null;
	}

	/**
	 * Returns the evaluator to use when none is named, falling back to a
	 * permissive instance rather than leaving the pipeline without an answer.
	 *
	 * The fallback is unreachable in a booted plugin, since both `rules` and
	 * `always` are registered; it exists for a registry that was never booted, and
	 * it answers "visible" for the reason that evaluator exists: a pipeline with no
	 * engine must not start hiding fields.
	 *
	 * @return ConditionEvaluatorInterface
	 */
	public function active(): ConditionEvaluatorInterface {
		$evaluator = $this->evaluator( self::DEFAULT_KEY );

		return $evaluator ?? new PermissiveConditionEvaluator();
	}
}
