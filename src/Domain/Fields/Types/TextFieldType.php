<?php
/**
 * Textual field types.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * String-valued types: text, textarea, email, url and tel.
 *
 * These differ only by format and multiline behaviour, so they share one
 * implementation configured per key rather than five near-identical classes.
 *
 * @see \ROADMAP.md section 5
 */
final class TextFieldType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key       Type key.
	 * @param string $label     Translatable label.
	 * @param string $format    One of `plain`, `email`, `url`, `tel`.
	 * @param bool   $multiline Whether the value may contain line breaks.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private string $format = 'plain',
		private bool $multiline = false
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
			'maxLength' => $this->multiline ? 20000 : 2000,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'minLength'   => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 20000,
			),
			'maxLength'   => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 20000,
			),
			'placeholder' => array(
				'type'      => 'string',
				'maxLength' => 200,
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
			'maskable'    => 'plain' === $this->format || 'tel' === $this->format,
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

		return $this->multiline ? trim( $value ) : trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * An absent value is never a format error: requiredness is a rule of the
	 * field definition and of the conditional engine, not of the type.
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
				__( 'This field expects text.', 'wc-checkoutsuite' )
			);
		}

		$min = $context->setting( 'minLength' );
		$max = $context->setting( 'maxLength' );

		if ( is_int( $min ) && mb_strlen( $value ) < $min ) {
			return ValidationResult::invalid(
				'too_short',
				sprintf(
					/* translators: %d: minimum number of characters */
					__( 'Please enter at least %d characters.', 'wc-checkoutsuite' ),
					$min
				),
				array( 'minLength' => $min )
			);
		}

		if ( is_int( $max ) && mb_strlen( $value ) > $max ) {
			return ValidationResult::invalid(
				'too_long',
				sprintf(
					/* translators: %d: maximum number of characters */
					__( 'Please enter at most %d characters.', 'wc-checkoutsuite' ),
					$max
				),
				array( 'maxLength' => $max )
			);
		}

		return $this->validate_format( $value );
	}

	/**
	 * Applies the format rule of this type.
	 *
	 * @param string $value Value.
	 * @return ValidationResult
	 */
	private function validate_format( string $value ): ValidationResult {
		switch ( $this->format ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					return ValidationResult::invalid(
						'invalid_email',
						__( 'Please enter a valid email address.', 'wc-checkoutsuite' )
					);
				}
				break;

			case 'url':
				$sanitized = esc_url_raw( $value );

				if ( '' === $sanitized || $sanitized !== $value ) {
					return ValidationResult::invalid(
						'invalid_url',
						__( 'Please enter a valid URL.', 'wc-checkoutsuite' )
					);
				}
				break;

			case 'tel':
				// Format by country is a preset concern; the type only rejects
				// characters that can never appear in a phone number.
				if ( 1 !== preg_match( '/^[0-9+().\-\s]+$/', $value ) ) {
					return ValidationResult::invalid(
						'invalid_phone',
						__( 'Please enter a valid phone number.', 'wc-checkoutsuite' )
					);
				}
				break;
		}

		return ValidationResult::valid();
	}
}
