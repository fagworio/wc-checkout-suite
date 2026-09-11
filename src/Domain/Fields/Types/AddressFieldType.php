<?php
/**
 * Address field types.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Country and state types.
 *
 * The domain never reads WooCommerce: the adapter supplies the allowed lists
 * through the context. That keeps the domain independent of the checkout engine
 * and makes the same type usable in Classic, Blocks and the admin preview.
 *
 * @see \ROADMAP.md section 5
 */
final class AddressFieldType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key      One of `country`, `state`.
	 * @param string $label    Translatable label.
	 * @param string $list_key Context entry holding the allowed values.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private string $list_key
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
		return array(
			'type'      => 'string',
			'maxLength' => 64,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array();
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
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );

		return 'country' === $this->key ? strtoupper( $trimmed ) : $trimmed;
	}

	/**
	 * {@inheritDoc}
	 *
	 * When the adapter supplies no list the value is accepted as-is: refusing
	 * everything would block a checkout whose engine has not published the data
	 * yet, which is worse than accepting a value the engine will re-validate.
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) ) {
			return ValidationResult::valid();
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid(
				'invalid_type',
				__( 'This field expects a country or state code.', 'wc-checkoutsuite' )
			);
		}

		$allowed = $context->get( $this->list_key );

		if ( ! is_array( $allowed ) || array() === $allowed ) {
			return ValidationResult::valid();
		}

		if ( ! in_array( $value, array_map( 'strval', array_keys( $allowed ) ), true ) ) {
			if ( ! in_array( $value, array_map( 'strval', $allowed ), true ) ) {
				return ValidationResult::invalid(
					'invalid_code',
					sprintf(
						/* translators: %s: submitted value */
						__( '"%s" is not an available option.', 'wc-checkoutsuite' ),
						$value
					),
					array( 'value' => $value )
				);
			}
		}

		return ValidationResult::valid();
	}
}
