<?php
/**
 * File upload field type.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * File upload type.
 *
 * The stored value is a list of opaque upload tokens, never file bytes and never
 * a public URL. Whether a token may be read is decided by the download policy,
 * not by the field.
 *
 * @see \ROADMAP.md section 12 and ADR-0002
 */
final class FileFieldType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key      Type key.
	 * @param string $label    Translatable label.
	 * @param bool   $multiple Whether more than one file may be attached.
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
		return array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'maxFiles'          => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 20,
				'required' => true,
			),
			'maxBytes'          => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'allowedExtensions' => array(
				'type'     => 'array',
				'required' => true,
				'minItems' => 1,
				'maxItems' => 40,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 20,
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
			// What this type stores is a file, which is what the per-destination
			// permissions need to know: showing a name, opening it, taking a copy and
			// replacing it are decisions about a file, not about a string.
			'file'        => true,
			'multiple'    => true,
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
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$tokens = array();

		foreach ( $value as $token ) {
			if ( is_string( $token ) && '' !== trim( $token ) ) {
				$tokens[] = trim( $token );
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only the shape and the count are validated here. Content, MIME, size and
	 * ownership belong to the upload subsystem, which is the only place that can
	 * inspect the file itself.
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) || array() === $value ) {
			return ValidationResult::valid();
		}

		if ( ! is_array( $value ) ) {
			return ValidationResult::invalid(
				'invalid_type',
				__( 'This field expects a list of uploaded files.', 'wc-checkoutsuite' )
			);
		}

		foreach ( $value as $token ) {
			if ( ! is_string( $token ) || '' === $token ) {
				return ValidationResult::invalid(
					'invalid_token',
					__( 'An uploaded file reference is not valid.', 'wc-checkoutsuite' )
				);
			}
		}

		$max_files = $context->setting( 'maxFiles' );

		if ( is_int( $max_files ) && count( $value ) > $max_files ) {
			return ValidationResult::invalid(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files */
					__( 'Please attach at most %d files.', 'wc-checkoutsuite' ),
					$max_files
				),
				array( 'maxFiles' => $max_files )
			);
		}

		if ( ! $this->multiple && count( $value ) > 1 ) {
			return ValidationResult::invalid(
				'single_file_only',
				__( 'Please attach a single file.', 'wc-checkoutsuite' )
			);
		}

		return ValidationResult::valid();
	}
}
