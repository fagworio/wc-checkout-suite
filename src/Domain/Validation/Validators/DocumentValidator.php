<?php
/**
 * Named validators for the Brazilian documents.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation\Validators;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Validation\CheckDigits;

/**
 * Checks one Brazilian document against the value it carries.
 *
 * Every message here is about the **number**. None of them says the customer is
 * who they claim to be, that the registration exists, or that the document was
 * verified — because a matching check digit proves none of that. ADR-0003 makes
 * this a criterion rather than a courtesy: the interface must not suggest that
 * arithmetic is identification, and the message is where a suggestion would be
 * made in the first place. A unit test asserts the absence of that vocabulary
 * rather than trusting whoever writes the next message to remember.
 *
 * An absent value is never a failure. Requiredness belongs to the definition, and
 * a format rule that also fired on an empty field would make a field impossible to
 * leave alone without contradicting its own configuration.
 *
 * The value arrives canonical: punctuation removed and letters uppercased by the
 * named normalizer, which ADR-0003 makes the only point allowed to transform a
 * document. Nothing here cleans a value — it refuses one that is not canonical,
 * which is the honest answer for a field whose contract says what it stores.
 *
 * @see \ROADMAP.md sections 9 and 10
 * @see \docs/adr/ADR-0003-cnpj-alphanumeric.md
 */
final class DocumentValidator {

	/**
	 * Version of the validation contract.
	 */
	public const CONTRACT_VERSION = '1.0';

	/**
	 * CPF: eleven digits and two check digits.
	 */
	public const MODE_CPF = 'cpf';

	/**
	 * CNPJ: twelve alphanumeric positions and two numeric check digits.
	 */
	public const MODE_CNPJ = 'cnpj';

	/**
	 * Postcode: eight digits, with no check digit to compute.
	 */
	public const MODE_POSTCODE = 'postcode';

	/**
	 * Brazilian landline: ten digits including the area code.
	 */
	public const MODE_LANDLINE = 'landline';

	/**
	 * Brazilian mobile: eleven digits, the third of which is a nine.
	 */
	public const MODE_MOBILE = 'mobile';

	/**
	 * Constructor.
	 *
	 * @param string $key  Stable validator key, e.g. `br.cnpj`.
	 * @param string $mode One of the mode constants.
	 */
	public function __construct(
		private string $key,
		private string $mode
	) {
	}

	/**
	 * Stable validator key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Version of the contract this validator implements.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return self::CONTRACT_VERSION;
	}

	/**
	 * Validates one value.
	 *
	 * @param mixed        $value   Canonical value.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function __invoke( mixed $value, FieldContext $context ): ValidationResult {
		unset( $context );

		if ( null === $value || '' === $value ) {
			return ValidationResult::valid();
		}

		if ( ! is_string( $value ) ) {
			return $this->refuse( 'invalid_type' );
		}

		return $this->check( $value );
	}

	/**
	 * Applies the mode's rule.
	 *
	 * @param string $value Canonical value.
	 * @return ValidationResult
	 */
	private function check( string $value ): ValidationResult {
		switch ( $this->mode ) {
			case self::MODE_CPF:
				return CheckDigits::cpf( $value )
					? ValidationResult::valid()
					: $this->refuse( 'invalid_cpf' );

			case self::MODE_CNPJ:
				return CheckDigits::cnpj( $value )
					? ValidationResult::valid()
					: $this->refuse( 'invalid_cnpj' );

			case self::MODE_POSTCODE:
				return CheckDigits::is_digits( $value, 8 )
					? ValidationResult::valid()
					: $this->refuse( 'invalid_postcode' );

			case self::MODE_LANDLINE:
				return $this->is_phone( $value, 10 )
					? ValidationResult::valid()
					: $this->refuse( 'invalid_phone' );

			case self::MODE_MOBILE:
				// A Brazilian mobile is eleven digits and the first after the area
				// code is a nine. Checking it is what tells a mobile from a
				// landline that grew a digit.
				return $this->is_phone( $value, 11 ) && '9' === $value[2]
					? ValidationResult::valid()
					: $this->refuse( 'invalid_phone' );
		}

		return ValidationResult::valid();
	}

	/**
	 * Whether a value is a telephone number of this length.
	 *
	 * The area code is checked for shape and not against the official list. That
	 * list changes, and a hard-coded copy of it would start refusing real
	 * customers on the day a code is added — which is a worse failure than
	 * accepting a well-formed number from a code that does not exist.
	 *
	 * @param string $value  Value.
	 * @param int    $length Expected number of digits.
	 * @return bool
	 */
	private function is_phone( string $value, int $length ): bool {
		if ( ! CheckDigits::is_digits( $value, $length ) ) {
			return false;
		}

		$area = (int) substr( $value, 0, 2 );

		return $area >= 11 && $area <= 99;
	}

	/**
	 * A refusal, with the message the customer reads.
	 *
	 * @param string $code Stable machine code.
	 * @return ValidationResult
	 */
	private function refuse( string $code ): ValidationResult {
		$messages = array(
			'invalid_type'     => __( 'This field expects a number written as text.', 'wc-checkoutsuite' ),
			'invalid_cpf'      => __( 'This CPF is not a valid number. Check the digits you entered.', 'wc-checkoutsuite' ),
			'invalid_cnpj'     => __( 'This CNPJ is not a valid number. Check the digits you entered.', 'wc-checkoutsuite' ),
			'invalid_postcode' => __( 'Enter a postcode with eight digits.', 'wc-checkoutsuite' ),
			'invalid_phone'    => __( 'Enter a phone number with its area code.', 'wc-checkoutsuite' ),
		);

		return ValidationResult::invalid( $code, $messages[ $code ] ?? $code );
	}
}
