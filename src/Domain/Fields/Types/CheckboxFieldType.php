<?php
/**
 * Boolean checkbox field type.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Single boolean checkbox.
 *
 * `false` is a real stored value and must survive persistence: it is exactly the
 * case `ROADMAP.md` section 5 lists as needing distinct semantics.
 */
final class CheckboxFieldType extends AbstractFieldType {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key(): string {
		return 'checkbox';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Checkbox', 'wc-checkoutsuite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function valueSchema(): array {
		return array( 'type' => 'boolean' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'label' => array(
				'type'      => 'string',
				'maxLength' => 300,
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
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		if ( null === $value || '' === $value ) {
			return false;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, array( 1, '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( null === $value || is_bool( $value ) ) {
			return ValidationResult::valid();
		}

		return ValidationResult::invalid(
			'invalid_type',
			__( 'This field expects a checked or unchecked value.', 'wc-checkoutsuite' )
		);
	}
}
