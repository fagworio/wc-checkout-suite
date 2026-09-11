<?php
/**
 * Condition operator.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * One operator of the condition vocabulary.
 *
 * An operator is typed. Section 11 requires incompatible type and operator
 * combinations to be refused at configuration time, and the only way to refuse
 * them is for the operator to say what it accepts: "greater than" on a checkbox
 * is not a rule that evaluates to false, it is a rule that does not mean anything,
 * and a store that stores it has a condition nobody can reason about.
 *
 * @see \ROADMAP.md section 11
 */
final class Operator {

	/**
	 * Value types a leaf can compare against.
	 */
	public const TYPE_STRING = 'string';

	/**
	 * Numeric value type.
	 */
	public const TYPE_NUMBER = 'number';

	/**
	 * Boolean value type.
	 */
	public const TYPE_BOOLEAN = 'boolean';

	/**
	 * List value type.
	 */
	public const TYPE_LIST = 'list';

	/**
	 * Constructor.
	 *
	 * @param string             $key       Stable key, e.g. `equals`.
	 * @param string             $label     Translatable label.
	 * @param bool               $takes     Whether the operator compares against a value.
	 * @param array<int, string> $value     Value types the comparison value may have.
	 * @param array<int, string> $sources   Source value types the operator may read.
	 * @param bool               $negated   Whether the operator is a negation of another one.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private bool $takes,
		private array $value,
		private array $sources,
		private bool $negated = false
	) {
	}

	/**
	 * Stable key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Translatable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Whether the operator compares against a value.
	 *
	 * `is_empty` does not: it asks a question about whatever is there, and a leaf
	 * that carried a value for it would be carrying something nothing reads.
	 *
	 * @return bool
	 */
	public function takes_value(): bool {
		return $this->takes;
	}

	/**
	 * Value types the comparison value may have.
	 *
	 * @return array<int, string>
	 */
	public function value_types(): array {
		return $this->value;
	}

	/**
	 * Source value types the operator may read.
	 *
	 * @return array<int, string>
	 */
	public function source_types(): array {
		return $this->sources;
	}

	/**
	 * Whether the operator is a negation of another one.
	 *
	 * Recorded because section 11 asks for the negations explicitly, and because a
	 * reader is entitled to see that `not_equals` is `equals` negated rather than a
	 * second implementation that has to be kept in step.
	 *
	 * @return bool
	 */
	public function is_negated(): bool {
		return $this->negated;
	}

	/**
	 * Whether this operator accepts a value of that type.
	 *
	 * @param string $type Value type.
	 * @return bool
	 */
	public function accepts_value( string $type ): bool {
		return in_array( $type, $this->value, true );
	}

	/**
	 * Whether this operator can read a source of that type.
	 *
	 * A plain membership test. An earlier version also accepted anything when the
	 * list contained `mixed`, which is the type a *field reference* declares
	 * before it is resolved — and that made every operator accept every field,
	 * which is exactly what a typed operator is not. `mixed` is a value of the
	 * source catalogue, not a wildcard for the operator.
	 *
	 * @param string $type Source value type.
	 * @return bool
	 */
	public function accepts_source( string $type ): bool {
		return in_array( $type, $this->sources, true );
	}

	/**
	 * Exports the operator as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'takesValue'  => $this->takes,
			'valueTypes'  => $this->value,
			'sourceTypes' => $this->sources,
			'negated'     => $this->negated,
		);
	}
}
