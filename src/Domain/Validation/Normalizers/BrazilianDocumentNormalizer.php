<?php
/**
 * Normalizer for the Brazilian documents.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation\Normalizers;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Validation\NormalizerInterface;

/**
 * Removes the punctuation a Brazilian document is written with, and nothing else.
 *
 * This is deliberately not `preg_replace( '/\D/', '', $value )`, which is what a
 * document normalizer usually looks like and what ADR-0003 forbids on this path.
 * Dropping every non-digit is the silent corruption the ADR was written about: an
 * alphanumeric CNPJ becomes a different number, and a CPF typed with a letter
 * becomes a *valid* CPF that nobody typed. The rule from section 9 is the one
 * implemented here — remove only recognised punctuation, and let an illegal
 * character reach the validator so it can be refused rather than quietly
 * discarded.
 *
 * The recognised set is the punctuation these documents' visual formats use:
 *
 * ```
 * CPF   000.000.000-00
 * CNPJ  00.000.000/0000-00
 * CEP   00000-000
 * Phone (00) 00000-0000
 * ```
 *
 * The second mode uppercases. It exists for CNPJ, where the letters are part of
 * the document and ADR-0003 fixes the stored form as uppercase; a lowercase
 * document and an uppercase one are the same document, and normalizing is what
 * makes them compare equal.
 *
 * @see \ROADMAP.md sections 5 and 9
 * @see \docs/adr/ADR-0003-cnpj-alphanumeric.md
 */
final class BrazilianDocumentNormalizer implements NormalizerInterface {

	/**
	 * Version of the normalization contract.
	 */
	public const CONTRACT_VERSION = '1.0';

	/**
	 * Mode that strips recognised punctuation only.
	 */
	public const MODE_PUNCTUATION = 'punctuation';

	/**
	 * Mode that strips recognised punctuation and uppercases.
	 */
	public const MODE_PUNCTUATION_UPPERCASE = 'punctuation_uppercase';

	/**
	 * Punctuation the Brazilian document formats are written with.
	 *
	 * Declared as a list rather than as a character class inside the pattern so
	 * that adding one is a visible edit with a reason, instead of a character
	 * quietly joining a bracket expression.
	 *
	 * @var array<int, string>
	 */
	private const RECOGNISED = array( '.', '/', '-', '(', ')', '+', ' ', "\t", "\n", "\r" );

	/**
	 * Constructor.
	 *
	 * @param string $key  Stable normalizer key, e.g. `br.cnpj`.
	 * @param string $mode One of the mode constants.
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
		return self::CONTRACT_VERSION;
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
	 * @return mixed Canonical value, always a string when given one.
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		unset( $context );

		if ( ! is_string( $value ) ) {
			// A document is a string. Anything else is not this normalizer's to
			// convert, and converting it is what would truncate a CNPJ.
			return $value;
		}

		$stripped = $this->strip( $value );

		if ( self::MODE_PUNCTUATION_UPPERCASE === $this->mode ) {
			$stripped = mb_strtoupper( $stripped );
		}

		return $stripped;
	}

	/**
	 * Removes the recognised punctuation from a value.
	 *
	 * `preg_replace` returns null when the subject is not valid UTF-8. Keeping the
	 * original value in that case leaves the illegal input intact for the
	 * validator, which is the same answer this class gives to any other character
	 * it does not recognise.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function strip( string $value ): string {
		$pattern = '/[' . preg_quote( implode( '', self::RECOGNISED ), '/' ) . ']/u';

		return preg_replace( $pattern, '', $value ) ?? $value;
	}
}
