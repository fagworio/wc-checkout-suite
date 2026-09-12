<?php
/**
 * Example field type.
 *
 * @package WCCheckoutSuiteExample
 */

declare( strict_types = 1 );

namespace WCCheckoutSuiteExample;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * A membership code field type contributed by an external plugin.
 *
 * It demonstrates every part of the contract an extension is expected to
 * provide: a stable key, a label, a contract version, a value schema, a
 * settings schema, declared capabilities, normalization and validation.
 *
 * @see \ROADMAP.md section 6
 */
final class MembershipCodeType extends AbstractFieldType {

	/**
	 * Stable type key.
	 */
	public const KEY = 'example.membership-code';

	/**
	 * Registers every type this example provides.
	 *
	 * @param FieldTypeRegistry $registry Field type registry.
	 * @return void
	 */
	public static function register_field_types( $registry ): void {
		$registry->register_type( new self(), 'wccs-example' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Membership code', 'wccs-example' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function valueSchema(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 24,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settingsSchema(): array {
		return array(
			'prefix' => array(
				'type'      => 'string',
				'maxLength' => 8,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports(): array {
		return array(
			'value'       => true,
			'multiple'    => false,
			'maskable'    => true,
			'conditional' => true,
			// The declaration that makes this type visible in both checkouts: it is
			// rendered by the text control that already exists, and what makes it a
			// membership code is normalize() and validate() below.
			'control'     => 'text',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value   Raw value.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed Canonical value.
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		unset( $context );

		if ( ! is_string( $value ) ) {
			return $value;
		}

		return mb_strtoupper( trim( $value ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value   Value to validate.
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
				__( 'The membership code must be text.', 'wccs-example' )
			);
		}

		$prefix = $context->setting( 'prefix' );

		if ( is_string( $prefix ) && '' !== $prefix && ! str_starts_with( $value, $prefix ) ) {
			return ValidationResult::invalid(
				'missing_prefix',
				sprintf(
					/* translators: %s: required prefix */
					__( 'The membership code must start with %s.', 'wccs-example' ),
					$prefix
				),
				array( 'prefix' => $prefix )
			);
		}

		if ( 1 !== preg_match( '/^[A-Z0-9-]+$/', $value ) ) {
			return ValidationResult::invalid(
				'invalid_characters',
				__( 'The membership code may use letters, digits and hyphens only.', 'wccs-example' )
			);
		}

		return ValidationResult::valid();
	}
}
