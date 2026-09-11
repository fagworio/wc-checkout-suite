<?php
/**
 * Validation outcome.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Immutable outcome of validating a value.
 *
 * A type never throws for an invalid value and never returns a bare boolean:
 * callers need a stable machine code per error so messages can be translated
 * and rendered next to the right field.
 *
 * @see \ROADMAP.md section 10
 */
final class ValidationResult {

	/**
	 * Collected errors.
	 *
	 * @var array<int, array{code: string, message: string, context: array<string, mixed>}>
	 */
	private array $errors;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{code: string, message: string, context: array<string, mixed>}> $errors Errors.
	 */
	private function __construct( array $errors ) {
		$this->errors = $errors;
	}

	/**
	 * A successful result.
	 *
	 * @return self
	 */
	public static function valid(): self {
		return new self( array() );
	}

	/**
	 * A failed result carrying one error.
	 *
	 * @param string               $code    Stable machine code.
	 * @param string               $message Translatable message.
	 * @param array<string, mixed> $context Optional context for rendering.
	 * @return self
	 */
	public static function invalid( string $code, string $message, array $context = array() ): self {
		return new self(
			array(
				array(
					'code'    => $code,
					'message' => $message,
					'context' => $context,
				),
			)
		);
	}

	/**
	 * Whether the value is acceptable.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Collected errors.
	 *
	 * @return array<int, array{code: string, message: string, context: array<string, mixed>}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Stable codes of every collected error.
	 *
	 * @return array<int, string>
	 */
	public function error_codes(): array {
		return array_map(
			static function ( array $error ): string {
				return $error['code'];
			},
			$this->errors
		);
	}

	/**
	 * Combines this result with another, preserving both sets of errors.
	 *
	 * @param self $other Result to merge.
	 * @return self
	 */
	public function merge( self $other ): self {
		return new self( array_merge( $this->errors, $other->errors ) );
	}
}
