<?php
/**
 * Choice field types.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Types backed by a list of options: select, radio, multiselect and checkbox-group.
 *
 * Option keys are stable: a label may change, the stored key never does.
 *
 * @see \ROADMAP.md section 5
 */
final class ChoiceFieldType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key      Type key.
	 * @param string $label    Translatable label.
	 * @param bool   $multiple Whether the value is a list of option keys.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private bool $multiple = false
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function valueSchema(): array {
		if ( $this->multiple ) {
			return array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
		}

		return array( 'type' => 'string' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'options' => array(
				'type'     => 'array',
				'required' => true,
				'minItems' => 1,
				'maxItems' => 500,
				// Declared, and therefore enforced and rendered: without this an
				// option could be any value at all and the inspector would have to
				// guess the shape it is editing.
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
						'label' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
					),
				),
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
			'multiple'    => $this->multiple,
			'maskable'    => false,
			'conditional' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		if ( $this->multiple ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			return array_values( array_unique( array_map( 'strval', $value ) ) );
		}

		return is_string( $value ) ? trim( $value ) : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Rejects any value that is not one of the declared option keys. This is the
	 * server-side revalidation that stops a tampered select from storing an
	 * arbitrary value.
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) || array() === $value ) {
			return ValidationResult::valid();
		}

		$allowed = $this->allowed_keys( $context );

		if ( array() === $allowed ) {
			return ValidationResult::invalid(
				'no_options_configured',
				__( 'This field has no selectable options configured.', 'wc-checkoutsuite' )
			);
		}

		if ( $this->multiple ) {
			if ( ! is_array( $value ) ) {
				return ValidationResult::invalid(
					'invalid_type',
					__( 'This field expects a list of choices.', 'wc-checkoutsuite' )
				);
			}

			foreach ( $value as $single ) {
				if ( ! in_array( (string) $single, $allowed, true ) ) {
					return ValidationResult::invalid(
						'invalid_choice',
						sprintf(
							/* translators: %s: submitted value */
							__( '"%s" is not one of the available choices.', 'wc-checkoutsuite' ),
							(string) $single
						),
						array( 'value' => (string) $single )
					);
				}
			}

			return ValidationResult::valid();
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid(
				'invalid_type',
				__( 'This field expects a single choice.', 'wc-checkoutsuite' )
			);
		}

		if ( ! in_array( $value, $allowed, true ) ) {
			return ValidationResult::invalid(
				'invalid_choice',
				sprintf(
					/* translators: %s: submitted value */
					__( '"%s" is not one of the available choices.', 'wc-checkoutsuite' ),
					$value
				),
				array( 'value' => $value )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Option keys declared in the field settings.
	 *
	 * @param FieldContext $context Context carrying the settings.
	 * @return array<int, string>
	 */
	private function allowed_keys( FieldContext $context ): array {
		$options = $context->setting( 'options', array() );

		if ( ! is_array( $options ) ) {
			return array();
		}

		$keys = array();

		foreach ( $options as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) ) {
				$keys[] = (string) $option['value'];
				continue;
			}

			if ( is_string( $option ) ) {
				$keys[] = $option;
			}
		}

		return $keys;
	}
}
