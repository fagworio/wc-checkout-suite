<?php
/**
 * Brazilian document check digits.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

/**
 * The arithmetic behind the Brazilian documents, and nothing else.
 *
 * Pure and free of WordPress, so the same rules can be read here and reproduced
 * in the checkout bundle. What is validated here is the *document*: whether the
 * check digits match the number that was typed. It says nothing about the person
 * or the company, whether the registration exists, or whether it belongs to
 * whoever typed it — ADR-0003 requires that a passing check digit never be
 * presented as verification of anything but the number.
 *
 * The values are the canonical ones. Normalization is `br.cnpj`'s job and this
 * class does not repeat it: a formatter that also cleaned the value would be the
 * second place allowed to transform a document, and ADR-0003 allows exactly one.
 * A value that arrives with punctuation is therefore **refused**, which is the
 * correct answer for a field whose contract says the stored value is unformatted.
 *
 * @see \ROADMAP.md section 9
 * @see \docs/adr/ADR-0003-cnpj-alphanumeric.md
 */
final class CheckDigits {

	/**
	 * Length of a CPF.
	 */
	public const CPF_LENGTH = 11;

	/**
	 * Length of a CNPJ.
	 */
	public const CNPJ_LENGTH = 14;

	/**
	 * Characters that make up a CNPJ, letters first.
	 *
	 * Uppercase only: the canonical form is uppercase, and a lowercase letter
	 * reaching here means the value was never normalized.
	 */
	private const CNPJ_CHARACTERS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Weights of the first CPF check digit, left to right.
	 *
	 * @var array<int, int>
	 */
	private const CPF_FIRST = array( 10, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Weights of the second CPF check digit.
	 *
	 * @var array<int, int>
	 */
	private const CPF_SECOND = array( 11, 10, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Weights of the first CNPJ check digit.
	 *
	 * Counted from the right in steps of two to nine, which left to right is this.
	 *
	 * @var array<int, int>
	 */
	private const CNPJ_FIRST = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Weights of the second CNPJ check digit.
	 *
	 * @var array<int, int>
	 */
	private const CNPJ_SECOND = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Whether a canonical CPF is arithmetically sound.
	 *
	 * @param string $value Canonical value.
	 * @return bool
	 */
	public static function cpf( string $value ): bool {
		if ( ! self::is_digits( $value, self::CPF_LENGTH ) ) {
			return false;
		}

		// A document made of one repeated digit satisfies the arithmetic and is
		// not a document anybody was issued. Every implementation refuses it and
		// so does this one, because accepting it turns a well-known test sequence
		// into a passing value.
		if ( 1 === count( array_unique( str_split( $value ) ) ) ) {
			return false;
		}

		return self::digit( substr( $value, 0, 9 ), self::CPF_FIRST ) === (int) $value[9]
			&& self::digit( substr( $value, 0, 10 ), self::CPF_SECOND ) === (int) $value[10];
	}

	/**
	 * Whether a canonical CNPJ is arithmetically sound.
	 *
	 * One implementation for both accepted formats, because they are one
	 * document: a digit's value is the digit itself and a letter's value is its
	 * position in the alphabet shifted by 17, which is what subtracting 48 from
	 * the ASCII code gives for both. The twelve leading positions accept either;
	 * the two check digits are always numeric.
	 *
	 * @param string $value Canonical value.
	 * @return bool
	 */
	public static function cnpj( string $value ): bool {
		if ( self::CNPJ_LENGTH !== strlen( $value ) ) {
			return false;
		}

		for ( $index = 0; $index < 12; $index++ ) {
			if ( false === strpos( self::CNPJ_CHARACTERS, $value[ $index ] ) ) {
				return false;
			}
		}

		for ( $index = 12; $index < 14; $index++ ) {
			if ( 1 !== preg_match( '/[0-9]/', $value[ $index ] ) ) {
				return false;
			}
		}

		return self::digit( substr( $value, 0, 12 ), self::CNPJ_FIRST ) === (int) $value[12]
			&& self::digit( substr( $value, 0, 13 ), self::CNPJ_SECOND ) === (int) $value[13];
	}

	/**
	 * Whether a value is exactly this many digits.
	 *
	 * @param string $value  Value.
	 * @param int    $length Expected length.
	 * @return bool
	 */
	public static function is_digits( string $value, int $length ): bool {
		return strlen( $value ) === $length && 1 === preg_match( '/^[0-9]+$/', $value );
	}

	/**
	 * One check digit.
	 *
	 * @param string          $value   Characters it is computed from.
	 * @param array<int, int> $weights Weights, left to right.
	 * @return int
	 */
	private static function digit( string $value, array $weights ): int {
		$sum = 0;

		foreach ( $weights as $index => $weight ) {
			$sum += self::character_value( $value[ $index ] ) * $weight;
		}

		$remainder = $sum % 11;

		return $remainder < 2 ? 0 : 11 - $remainder;
	}

	/**
	 * The value a character contributes.
	 *
	 * This is the official table: a digit is worth its face value and `A` starts
	 * at 17, which is exactly `ord( $character ) - 48` for every character the
	 * document accepts.
	 *
	 * @param string $character Character.
	 * @return int
	 */
	private static function character_value( string $character ): int {
		return ord( $character ) - 48;
	}
}
