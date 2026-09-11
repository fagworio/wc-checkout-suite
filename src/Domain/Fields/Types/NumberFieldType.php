<?php
/**
 * Numeric field type.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Real number type.
 *
 * Never used for documents: `ROADMAP.md` section 5 states explicitly that
 * `number` must not hold a CPF or CNPJ, because numeric handling truncates
 * leading zeros and cannot represent letters.
 */
final class NumberFieldType extends AbstractFieldType {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key(): string {
		return 'number';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Number', 'wc-checkoutsuite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function valueSchema(): array {
		return array( 'type' => 'number' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'min'  => array( 'type' => 'number' ),
			'max'  => array( 'type' => 'number' ),
			'step' => array(
				'type'    => 'number',
				'minimum' => 0,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function supports(): array {
		return array(
			'value'       => true,
			'multiple'    => false,
			'maskable'    => false,
			'conditional' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Accepts a numeric string and returns an int or float, so `0` stays `0`
	 * instead of collapsing into an absent value.
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );

		if ( 1 !== preg_match( '/^[+-]?\d+$/', $trimmed ) ) {
			return $trimmed;
		}

		return (int) $trimmed;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) ) {
			return ValidationResult::valid();
		}

		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return ValidationResult::invalid(
				'not_a_number',
				__( 'Please enter a number.', 'wc-checkoutsuite' )
			);
		}

		$min = $context->setting( 'min' );
		$max = $context->setting( 'max' );

		if ( is_int( $min ) || is_float( $min ) ) {
			if ( $value < $min ) {
				return ValidationResult::invalid(
					'below_minimum',
					sprintf(
						/* translators: %s: minimum value */
						__( 'Please enter a value of at least %s.', 'wc-checkoutsuite' ),
						(string) $min
					),
					array( 'min' => $min )
				);
			}
		}

		if ( is_int( $max ) || is_float( $max ) ) {
			if ( $value > $max ) {
				return ValidationResult::invalid(
					'above_maximum',
					sprintf(
						/* translators: %s: maximum value */
						__( 'Please enter a value of at most %s.', 'wc-checkoutsuite' ),
						(string) $max
					),
					array( 'max' => $max )
				);
			}
		}

		return ValidationResult::valid();
	}
}
