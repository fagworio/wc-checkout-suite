<?php
/**
 * Hiding condition evaluator stub.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Stubs;

use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorInterface;
use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Condition evaluator that hides every field, standing in for the engine that
 * arrives in F06.
 */
final class HidingEvaluator implements ConditionEvaluatorInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'test.never';
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rules   Declarative rules.
	 * @param FieldContext         $context Trusted server-side context.
	 * @return bool Always false.
	 */
	public function evaluate( array $rules, FieldContext $context ): bool {
		unset( $rules, $context );

		return false;
	}
}
