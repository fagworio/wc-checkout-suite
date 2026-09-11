<?php
/**
 * Generic normalizer.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation\Normalizers;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Validation\NormalizerInterface;

/**
 * Normalizer primitives shared by the field presets.
 *
 * These are infrastructure, not business rules: the Brazilian document rules
 * (CPF, CNPJ with letters and leading zeros, CEP) are composed from these in
 * WCCS-026. Keeping the primitives here means the preset layer never has to
 * reinvent string handling.
 *
 * A normalizer removes only recognised formatting. It never "cleans" a value
 * until it looks valid, because that turns a wrong document into an accepted
 * one.
 *
 * @see \ROADMAP.md section 9
 */
final class GenericNormalizer implements NormalizerInterface {

	/**
	 * Constructor.
	 *
	 * @param string $key  Stable key.
	 * @param string $mode One of `trim`, `digits`, `uppercase`, `lowercase`, `single_spaces`.
	 */
	public function __construct(
		private string $key,
		private string $mode
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * Normalization mode.
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
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

		switch ( $this->mode ) {
			case 'trim':
				return trim( $value );

			case 'digits':
				return preg_replace( '/\D/', '', $value ) ?? '';

			case 'uppercase':
				return mb_strtoupper( trim( $value ) );

			case 'lowercase':
				return mb_strtolower( trim( $value ) );

			case 'single_spaces':
				return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		}

		return $value;
	}
}
