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
	 */
	public const DEFAULT_KEY = 'always';

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
	 * @return ConditionEvaluatorInterface
	 */
	public function active(): ConditionEvaluatorInterface {
		$evaluator = $this->evaluator( self::DEFAULT_KEY );

		return $evaluator ?? new PermissiveConditionEvaluator();
	}
}
