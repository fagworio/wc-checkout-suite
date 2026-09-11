<?php
/**
 * Validator registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Holds named validators that field definitions may reference by key.
 *
 * A definition stores `validators: [{key: "br.cnpj"}]`, never a callback name
 * taken from a request. The registry is the only place a key resolves to code.
 *
 * @see \ROADMAP.md sections 6 and 20
 */
final class ValidatorRegistry extends AbstractRegistry {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'validator';
	}

	/**
	 * Registers a validator.
	 *
	 * @param string   $key      Stable key.
	 * @param callable $validator Callable receiving ( mixed $value, FieldContext $context ) and returning ValidationResult.
	 * @param string   $source   Origin, for diagnostics.
	 * @return bool
	 */
	public function register_validator( string $key, callable $validator, string $source = 'core' ): bool {
		return $this->register( $key, $validator, $source );
	}

	/**
	 * Returns a validator callable or null.
	 *
	 * @param string $key Validator key.
	 * @return callable|null
	 */
	public function validator( string $key ): ?callable {
		$validator = $this->get( $key );

		return is_callable( $validator ) ? $validator : null;
	}

	/**
	 * Runs one validator by key.
	 *
	 * An unknown key is reported as an error rather than silently passing: a
	 * definition referencing a validator that no longer exists must never be
	 * treated as validated.
	 *
	 * @param string       $key     Validator key.
	 * @param mixed        $value   Value to validate.
	 * @param FieldContext $context Trusted context.
	 * @return ValidationResult
	 */
	public function run( string $key, mixed $value, FieldContext $context ): ValidationResult {
		$validator = $this->validator( $key );

		if ( null === $validator ) {
			return ValidationResult::invalid(
				'unknown_validator',
				sprintf(
					/* translators: %s: validator key */
					__( 'The validator "%s" is not registered.', 'wc-checkoutsuite' ),
					$key
				),
				array( 'key' => $key )
			);
		}

		$result = $validator( $value, $context );

		if ( $result instanceof ValidationResult ) {
			return $result;
		}

		return ValidationResult::invalid(
			'invalid_validator_result',
			sprintf(
				/* translators: %s: validator key */
				__( 'The validator "%s" did not return a ValidationResult.', 'wc-checkoutsuite' ),
				$key
			),
			array( 'key' => $key )
		);
	}
}
