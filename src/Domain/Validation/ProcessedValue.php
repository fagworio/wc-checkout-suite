<?php
/**
 * Processed field value.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Result of running one field value through the pipeline.
 *
 * @see \ROADMAP.md section 10
 */
final class ProcessedValue {

	/**
	 * Constructor.
	 *
	 * @param mixed            $value     Canonical value, or null when discarded.
	 * @param ValidationResult $result    Validation outcome.
	 * @param bool             $visible   Whether the field is visible under the current context.
	 * @param bool             $discarded Whether the value was discarded because the field is hidden.
	 */
	public function __construct(
		private mixed $value,
		private ValidationResult $result,
		private bool $visible,
		private bool $discarded
	) {
	}

	/**
	 * Canonical value, or null when discarded.
	 *
	 * @return mixed
	 */
	public function value(): mixed {
		return $this->value;
	}

	/**
	 * Validation outcome.
	 *
	 * @return ValidationResult
	 */
	public function result(): ValidationResult {
		return $this->result;
	}

	/**
	 * Whether the field is visible.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return $this->visible;
	}

	/**
	 * Whether the value was discarded as residual data of a hidden field.
	 *
	 * @return bool
	 */
	public function is_discarded(): bool {
		return $this->discarded;
	}

	/**
	 * Whether the value may be persisted.
	 *
	 * A discarded value is never stored, and an invalid value is never stored.
	 *
	 * @return bool
	 */
	public function is_storable(): bool {
		return ! $this->discarded && $this->result->is_valid();
	}
}
