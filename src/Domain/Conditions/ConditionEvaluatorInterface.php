<?php
/**
 * Condition evaluator contract.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Evaluates a declarative condition document.
 *
 * This is the seam, not the engine. The AST, the operator set and the PHP/JS
 * parity belong to F06 (WCCS-031 to WCCS-033); what WCCS-009 fixes is that the
 * validation pipeline asks a single versioned contract for visibility and
 * requirement decisions, so replacing the evaluator never touches the pipeline.
 *
 * @see \ROADMAP.md section 11
 */
interface ConditionEvaluatorInterface {

	/**
	 * Stable key of the evaluator.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Version of this contract the evaluator implements.
	 *
	 * @return string
	 */
	public function contract_version(): string;

	/**
	 * Evaluates a condition document.
	 *
	 * @param array<string, mixed> $rules   Declarative rules, e.g. `['all' => [...]]`.
	 * @param FieldContext         $context Trusted server-side context.
	 * @return bool True when the rules match.
	 */
	public function evaluate( array $rules, FieldContext $context ): bool;
}
