<?php
/**
 * Native condition compilation result.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * What a rule compiled to, and what stood in the way.
 *
 * Both halves are always present, and the second is the one worth carrying: a
 * compiler that answers "no schema" alone leaves whoever reads the report to work
 * out why, and the answer to "why" is the difference between a rule that was
 * refused on purpose and one that was dropped by a table nobody updated.
 */
final class NativeConditionCompilation {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null                         $schema Rule for the native mechanism, or null.
	 * @param array<int, array{source: string, reason: string}> $notes  What could not be compiled, and why.
	 */
	public function __construct(
		private ?array $schema,
		private array $notes = array()
	) {
	}

	/**
	 * The compiled rule, in the shape the native mechanism expects.
	 *
	 * Unwrapped: the top level holds `cart`, `customer` and `checkout`, which is the
	 * form `Validation::is_valid_schema()` accepts directly.
	 *
	 * @return array<string, mixed>|null
	 */
	public function schema(): ?array {
		return $this->schema;
	}

	/**
	 * Whether a rule was compiled.
	 *
	 * @return bool
	 */
	public function is_compiled(): bool {
		return null !== $this->schema;
	}

	/**
	 * What could not be compiled, and why.
	 *
	 * @return array<int, array{source: string, reason: string}>
	 */
	public function notes(): array {
		return $this->notes;
	}
}
