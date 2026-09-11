<?php
/**
 * Permissive condition evaluator.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Evaluator used until the real condition engine exists.
 *
 * It answers "visible" for every field and never hides anything, which is the
 * safe default: a checkout must not start hiding fields because the engine is
 * missing. The AST, operators and PHP/JS parity arrive in F06.
 *
 * @see \ROADMAP.md section 11
 */
final class PermissiveConditionEvaluator implements ConditionEvaluatorInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'always';
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
	 * @return bool Always true: this evaluator never hides a field.
	 */
	public function evaluate( array $rules, FieldContext $context ): bool {
		unset( $rules, $context );

		return true;
	}
}
