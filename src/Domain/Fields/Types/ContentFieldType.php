<?php
/**
 * Non-input field types.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Content types: hidden, heading, paragraph and restricted HTML.
 *
 * A heading or paragraph is presentation: it must never create a stored value.
 * Only `hidden` carries data, and a hidden input is still untrusted input — it
 * is not proof of price, permission or identity.
 *
 * @see \ROADMAP.md sections 5 and 11
 */
final class ContentFieldType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key         One of `hidden`, `heading`, `paragraph`, `html`.
	 * @param string $label       Translatable label.
	 * @param bool   $stores_value Whether the type produces a stored value.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private bool $stores_value = false
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
		if ( ! $this->stores_value ) {
			return array( 'type' => 'null' );
		}

		return array(
			'type'      => 'string',
			'maxLength' => 500,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		if ( 'html' === $this->key ) {
			return array(
				'content'     => array(
					'type'      => 'string',
					'maxLength' => 20000,
				),
				'allowedTags' => array(
					'type'     => 'array',
					'maxItems' => 40,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 40,
					),
				),
			);
		}

		if ( $this->stores_value ) {
			return array(
				'default' => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			);
		}

		return array(
			'content' => array(
				'type'      => 'string',
				'maxLength' => 20000,
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
			'value'       => $this->stores_value,
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
		if ( ! $this->stores_value ) {
			return null;
		}

		return is_string( $value ) ? trim( $value ) : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( ! $this->stores_value ) {
			if ( null === $value || '' === $value ) {
				return ValidationResult::valid();
			}

			return ValidationResult::invalid(
				'value_not_allowed',
				__( 'This field is presentational and cannot store a value.', 'wc-checkoutsuite' )
			);
		}

		if ( self::is_absent( $value ) ) {
			return ValidationResult::valid();
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid(
				'invalid_type',
				__( 'This field expects text.', 'wc-checkoutsuite' )
			);
		}

		if ( mb_strlen( $value ) > 500 ) {
			return ValidationResult::invalid(
				'too_long',
				__( 'Please enter at most 500 characters.', 'wc-checkoutsuite' )
			);
		}

		return ValidationResult::valid();
	}
}
