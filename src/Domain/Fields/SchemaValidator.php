<?php
/**
 * Minimal schema validation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Validates an array of settings against a declared schema.
 *
 * This is a deliberately small subset of JSON Schema: enough to declare and
 * enforce type settings with limits, without pulling in a full schema library
 * or inventing a framework larger than the plugin.
 *
 * Unknown properties are rejected. Accepting them would be mass assignment: a
 * client could then write settings a type never declared.
 *
 * @see \ROADMAP.md sections 6 and 20
 */
final class SchemaValidator {

	/**
	 * Validates a value map against a property schema.
	 *
	 * @param array<string, mixed> $values Values to validate.
	 * @param array<string, mixed> $schema Schema: property => rules.
	 * @return ValidationResult
	 */
	public static function validate( array $values, array $schema ): ValidationResult {
		$result = ValidationResult::valid();

		foreach ( $values as $property => $value ) {
			if ( ! array_key_exists( (string) $property, $schema ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'unknown_setting',
						sprintf(
							/* translators: %s: setting name */
							__( 'The setting "%s" is not supported.', 'wc-checkoutsuite' ),
							(string) $property
						),
						array( 'property' => (string) $property )
					)
				);
				continue;
			}

			$rules  = is_array( $schema[ $property ] ) ? $schema[ $property ] : array();
			$result = $result->merge( self::validate_property( (string) $property, $value, $rules ) );
		}

		foreach ( $schema as $property => $rules ) {
			$rules = is_array( $rules ) ? $rules : array();

			if ( ! empty( $rules['required'] ) && ! array_key_exists( (string) $property, $values ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'missing_required_setting',
						sprintf(
							/* translators: %s: setting name */
							__( 'The setting "%s" is required.', 'wc-checkoutsuite' ),
							(string) $property
						),
						array( 'property' => (string) $property )
					)
				);
			}
		}

		return $result;
	}

	/**
	 * Validates one property against its rules.
	 *
	 * @param string               $property Property name.
	 * @param mixed                $value    Value.
	 * @param array<string, mixed> $rules    Rules.
	 * @return ValidationResult
	 */
	private static function validate_property( string $property, mixed $value, array $rules ): ValidationResult {
		$result = ValidationResult::valid();

		if ( isset( $rules['type'] ) && ! self::matches_type( $value, (string) $rules['type'] ) ) {
			return ValidationResult::invalid(
				'invalid_setting_type',
				sprintf(
					/* translators: 1: setting name, 2: expected type */
					__( 'The setting "%1$s" must be of type %2$s.', 'wc-checkoutsuite' ),
					$property,
					(string) $rules['type']
				),
				array(
					'property' => $property,
					'expected' => (string) $rules['type'],
				)
			);
		}

		if ( isset( $rules['enum'] ) && is_array( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) {
			return ValidationResult::invalid(
				'invalid_setting_value',
				sprintf(
					/* translators: 1: setting name, 2: comma separated list of accepted values */
					__( 'The setting "%1$s" must be one of: %2$s.', 'wc-checkoutsuite' ),
					$property,
					implode( ', ', array_map( 'strval', $rules['enum'] ) )
				),
				array( 'property' => $property )
			);
		}

		if ( is_string( $value ) ) {
			$length = mb_strlen( $value );

			if ( isset( $rules['minLength'] ) && $length < (int) $rules['minLength'] ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'setting_too_short',
						sprintf(
							/* translators: 1: setting name, 2: minimum length */
							__( 'The setting "%1$s" must be at least %2$d characters long.', 'wc-checkoutsuite' ),
							$property,
							(int) $rules['minLength']
						),
						array( 'property' => $property )
					)
				);
			}

			if ( isset( $rules['maxLength'] ) && $length > (int) $rules['maxLength'] ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'setting_too_long',
						sprintf(
							/* translators: 1: setting name, 2: maximum length */
							__( 'The setting "%1$s" must be at most %2$d characters long.', 'wc-checkoutsuite' ),
							$property,
							(int) $rules['maxLength']
						),
						array( 'property' => $property )
					)
				);
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $rules['minimum'] ) && $value < $rules['minimum'] ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'setting_below_minimum',
						sprintf(
							/* translators: 1: setting name, 2: minimum value */
							__( 'The setting "%1$s" must be at least %2$s.', 'wc-checkoutsuite' ),
							$property,
							(string) $rules['minimum']
						),
						array( 'property' => $property )
					)
				);
			}

			if ( isset( $rules['maximum'] ) && $value > $rules['maximum'] ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'setting_above_maximum',
						sprintf(
							/* translators: 1: setting name, 2: maximum value */
							__( 'The setting "%1$s" must be at most %2$s.', 'wc-checkoutsuite' ),
							$property,
							(string) $rules['maximum']
						),
						array( 'property' => $property )
					)
				);
			}
		}

		if ( isset( $rules['minItems'] ) && is_array( $value ) && count( $value ) < (int) $rules['minItems'] ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'setting_too_few_items',
					sprintf(
						/* translators: 1: setting name, 2: minimum number of items */
						__( 'The setting "%1$s" must contain at least %2$d items.', 'wc-checkoutsuite' ),
						$property,
						(int) $rules['minItems']
					),
					array( 'property' => $property )
				)
			);
		}

		if ( isset( $rules['maxItems'] ) && is_array( $value ) && count( $value ) > (int) $rules['maxItems'] ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'setting_too_many_items',
					sprintf(
						/* translators: 1: setting name, 2: maximum number of items */
						__( 'The setting "%1$s" must contain at most %2$d items.', 'wc-checkoutsuite' ),
						$property,
						(int) $rules['maxItems']
					),
					array( 'property' => $property )
				)
			);
		}

		// Item rules. Without them an array setting only had to be a list of the
		// right length, so an option could be the string "banana" and pass: the
		// shape a choice field actually needs was declared nowhere and enforced
		// nowhere.
		if ( isset( $rules['items'] ) && is_array( $rules['items'] ) && is_array( $value ) && array_is_list( $value ) ) {
			$item_rules = $rules['items'];
			$properties = isset( $item_rules['properties'] ) && is_array( $item_rules['properties'] )
				? $item_rules['properties']
				: array();

			foreach ( $value as $index => $item ) {
				$label = sprintf( '%s[%d]', $property, (int) $index );

				if ( array() === $properties ) {
					$item_result = self::validate_property( $label, $item, $item_rules );
				} elseif ( is_array( $item ) && ! array_is_list( $item ) ) {
					// Object items reuse the settings routine, so required keys and
					// unknown keys behave identically at both levels instead of a
					// second, weaker implementation growing here.
					$item_result = self::validate( $item, $properties );
				} else {
					$item_result = ValidationResult::invalid(
						'invalid_setting_type',
						sprintf(
							/* translators: 1: setting name, 2: expected type */
							__( 'The setting "%1$s" must be of type %2$s.', 'wc-checkoutsuite' ),
							$label,
							'object'
						),
						array(
							'property' => $property,
							'item'     => (int) $index,
						)
					);
				}

				foreach ( $item_result->errors() as $error ) {
					$item_context             = $error['context'];
					$item_context['property'] = $property;
					$item_context['item']     = (int) $index;

					$result = $result->merge(
						ValidationResult::invalid( (string) $error['code'], (string) $error['message'], $item_context )
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Whether a value satisfies a declared type.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Declared type.
	 * @return bool
	 */
	public static function matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ! array_is_list( $value ),
			'null'    => null === $value,
			'mixed'   => true,
			default   => false,
		};
	}
}
