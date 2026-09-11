<?php
/**
 * Brazilian validator tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Validation;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Validation\BrazilianDocuments;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;

/**
 * Covers the validators against the shared fixture set.
 *
 * The vectors are in `resources/fixtures/br-documents.json`, the same file the
 * JavaScript suite reads. "The same fixtures pass and fail on both sides" is the
 * acceptance, and it is only true if there is one file: two sets of vectors would
 * agree on the day they were written and disagree afterwards.
 *
 * What a passing check digit means is asserted here too. It means the number is
 * well formed. It does not mean the person exists, that the registration is
 * active, or that the document belongs to whoever typed it, and ADR-0003 makes
 * the interface responsible for not suggesting otherwise — so the messages are
 * checked for that vocabulary rather than left to whoever writes the next one.
 */
final class BrazilianValidatorsTest extends TestCase {

	/**
	 * Validator registry under test.
	 *
	 * @var ValidatorRegistry
	 */
	private ValidatorRegistry $validators;

	/**
	 * The shared fixtures.
	 *
	 * @var array<string, mixed>
	 */
	private array $fixtures;

	/**
	 * Builds the registry and reads the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->validators = new ValidatorRegistry();

		BrazilianDocuments::register_validators( $this->validators );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixture shipped with the plugin; the sniff targets remote URLs.
		$raw = file_get_contents( dirname( __DIR__, 4 ) . '/resources/fixtures/br-documents.json' );

		self::assertIsString( $raw );

		$decoded = json_decode( $raw, true );

		self::assertIsArray( $decoded );

		$this->fixtures = $decoded;
	}

	/**
	 * Runs one value through a named validator.
	 *
	 * Named `validate` rather than `run`: `TestCase::run()` is public and a
	 * private method of the same name is a fatal error, which is a good reminder
	 * that the base class is not a namespace to borrow from.
	 *
	 * @param string $key   Validator key.
	 * @param mixed  $value Value.
	 * @return ValidationResult
	 */
	private function validate( string $key, mixed $value ): ValidationResult {
		return $this->validators->run( $key, $value, new FieldContext( array(), 'classic' ) );
	}

	/**
	 * The validator keys the fixtures describe.
	 *
	 * @return array<int, string>
	 */
	private function keys(): array {
		return array_values(
			array_filter(
				array_keys( (array) $this->fixtures['validity'] ),
				static function ( string $key ): bool {
					return 'br.rg' !== $key;
				}
			)
		);
	}

	/**
	 * Every fixture key is a registered validator.
	 *
	 * @return void
	 */
	public function test_every_document_with_fixtures_has_a_validator(): void {
		foreach ( $this->keys() as $key ) {
			self::assertTrue( $this->validators->has( $key ), $key . ' is registered' );
		}
	}

	/**
	 * RG has no validator, and the registry says so.
	 *
	 * @return void
	 */
	public function test_rg_has_no_validator(): void {
		self::assertFalse( $this->validators->has( 'br.rg' ) );
		self::assertArrayHasKey( 'note', $this->fixtures['validity']['br.rg'] );
	}

	/**
	 * Every valid fixture is accepted.
	 *
	 * @return void
	 */
	public function test_every_valid_fixture_is_accepted(): void {
		foreach ( $this->keys() as $key ) {
			foreach ( $this->fixtures['validity'][ $key ]['valid'] as $case ) {
				self::assertTrue(
					$this->validate( $key, $case['value'] )->is_valid(),
					$key . ' accepts ' . $case['value'] . ': ' . $case['why']
				);
			}
		}
	}

	/**
	 * Every invalid fixture is refused.
	 *
	 * @return void
	 */
	public function test_every_invalid_fixture_is_refused(): void {
		foreach ( $this->keys() as $key ) {
			foreach ( $this->fixtures['validity'][ $key ]['invalid'] as $case ) {
				$result = $this->validate( $key, $case['value'] );

				self::assertFalse(
					$result->is_valid(),
					$key . ' refuses ' . $case['value'] . ': ' . $case['why']
				);
				self::assertNotSame( array(), $result->error_codes(), $key . ' names a code for ' . $case['value'] );
			}
		}
	}

	/**
	 * The two worked alphanumeric examples are the anchors.
	 *
	 * One is the step by step published alongside the rule and the other is a
	 * published test number. An implementation that reproduces both has the rule
	 * and not a coincidence.
	 *
	 * @return void
	 */
	public function test_the_published_alphanumeric_examples_pass(): void {
		self::assertTrue( $this->validate( 'br.cnpj', '12ABC34501DE35' )->is_valid() );
		self::assertTrue( $this->validate( 'br.cnpj', 'UKPVME1E8HI996' )->is_valid() );
	}

	/**
	 * The well-known numeric vectors pass.
	 *
	 * @return void
	 */
	public function test_the_numeric_vectors_pass(): void {
		self::assertTrue( $this->validate( 'br.cpf', '52998224725' )->is_valid() );
		self::assertTrue( $this->validate( 'br.cpf', '11144477735' )->is_valid() );
		self::assertTrue( $this->validate( 'br.cnpj', '11222333000181' )->is_valid() );
	}

	/**
	 * A formatted value is refused rather than cleaned.
	 *
	 * The normalizer is the only place allowed to transform a document, so a value
	 * that still carries punctuation means the field was configured without it.
	 * Cleaning it here would make this the second such place, and would accept a
	 * value the field's own contract says it does not store.
	 *
	 * @return void
	 */
	public function test_a_formatted_value_is_refused_rather_than_cleaned(): void {
		self::assertFalse( $this->validate( 'br.cpf', '529.982.247-25' )->is_valid() );
		self::assertFalse( $this->validate( 'br.cnpj', '11.222.333/0001-81' )->is_valid() );
	}

	/**
	 * A value that is not canonical is refused here, and accepted in the browser.
	 *
	 * The two sides sit in different places and the fixture says so in one list
	 * rather than leaving the difference to be discovered. The server validates
	 * what it stores, and the named normalizer is the only thing allowed to
	 * transform a document, so a formatted or lowercase value is refused rather
	 * than cleaned. The browser validates what is in the field, so it removes the
	 * punctuation and uppercases before checking.
	 *
	 * @return void
	 */
	public function test_a_value_that_is_not_canonical_is_refused(): void {
		self::assertSame( 'server', $this->fixtures['not_canonical']['refused_by'] );
		self::assertSame( 'browser', $this->fixtures['not_canonical']['accepted_by'] );

		foreach ( $this->fixtures['not_canonical']['cases'] as $case ) {
			self::assertFalse(
				$this->validate( $case['validator'], $case['value'] )->is_valid(),
				$case['validator'] . ' refuses the non-canonical ' . $case['value'] . ': ' . $case['why']
			);
		}
	}

	/**
	 * A CNPJ whose check digits are letters is refused.
	 *
	 * The twelve leading positions are alphanumeric and the two check digits are
	 * always numeric. That is the answer to the question ADR-0003 left open, and
	 * it is asserted rather than described.
	 *
	 * @return void
	 */
	public function test_the_check_digits_are_always_numeric(): void {
		self::assertFalse( $this->validate( 'br.cnpj', '12ABC34501DE3A' )->is_valid() );
		self::assertTrue( $this->validate( 'br.cnpj', '12ABC34501DE35' )->is_valid() );
	}

	/**
	 * An absent value is never a format error.
	 *
	 * @return void
	 */
	public function test_an_absent_value_is_valid(): void {
		foreach ( $this->keys() as $key ) {
			self::assertTrue( $this->validate( $key, '' )->is_valid(), $key . ' on an empty value' );
			self::assertTrue( $this->validate( $key, null )->is_valid(), $key . ' on a null value' );
		}
	}

	/**
	 * A value that is not text is refused.
	 *
	 * @return void
	 */
	public function test_a_value_that_is_not_text_is_refused(): void {
		self::assertFalse( $this->validate( 'br.cpf', 52998224725 )->is_valid() );
		self::assertFalse( $this->validate( 'br.cnpj', array( '12ABC34501DE35' ) )->is_valid() );
	}

	/**
	 * Every refusal names the document and claims nothing about the person.
	 *
	 * This is the acceptance clause about the check digits not being presented as
	 * identity validation, and it is the one place a suggestion could be made. The
	 * vocabulary is checked rather than trusted, so a message added later has to
	 * face the rule.
	 *
	 * @return void
	 */
	public function test_no_message_claims_to_have_verified_anybody(): void {
		$claims = array( 'identity', 'identidade', 'titular', 'owner', 'ownership', 'verified', 'verificad', 'confirms', 'confirma', 'authentic', 'autêntic', 'is yours' );

		foreach ( $this->keys() as $key ) {
			$case   = $this->fixtures['validity'][ $key ]['invalid'][0];
			$result = $this->validate( $key, $case['value'] );

			self::assertSame( 1, count( $result->errors() ), $key . ' reports one message' );

			$message = strtolower( $result->errors()[0]['message'] );

			self::assertNotSame( '', trim( $message ), $key . ' says something' );

			foreach ( $claims as $claim ) {
				self::assertStringNotContainsString(
					$claim,
					$message,
					$key . ' must not claim to have verified anybody: "' . $message . '"'
				);
			}
		}

		self::assertStringContainsString( 'cpf', strtolower( $this->validate( 'br.cpf', '1234567890' )->errors()[0]['message'] ) );
		self::assertStringContainsString( 'cnpj', strtolower( $this->validate( 'br.cnpj', '1122233300018' )->errors()[0]['message'] ) );
	}

	/**
	 * The presets and the registry agree.
	 *
	 * A preset naming a validator that is not registered would make every field
	 * built from it unsaveable, and the mistake would surface on the merchant's
	 * screen rather than here.
	 *
	 * @return void
	 */
	public function test_every_preset_names_a_registered_validator(): void {
		$presets = new \WCCheckoutSuite\Domain\Fields\PresetRegistry();

		\WCCheckoutSuite\Domain\Fields\BrazilianPresets::register_presets( $presets );

		foreach ( $presets->presets() as $key => $preset ) {
			foreach ( $preset->defaults()['validators'] ?? array() as $reference ) {
				self::assertTrue(
					$this->validators->has( (string) $reference['key'] ),
					'the preset ' . (string) $key . ' names the validator ' . (string) $reference['key']
				);
			}
		}
	}

	/**
	 * A document preset declares exactly one validator, and it is its own.
	 *
	 * @return void
	 */
	public function test_a_document_preset_declares_its_own_validator(): void {
		$presets = new \WCCheckoutSuite\Domain\Fields\PresetRegistry();

		\WCCheckoutSuite\Domain\Fields\BrazilianPresets::register_presets( $presets );

		foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' ) as $key ) {
			$references = $presets->preset( $key )->defaults()['validators'];

			self::assertSame( array( array( 'key' => $key ) ), $references, $key . ' declares its own validator' );
		}
	}

	/**
	 * RG declares no validator, because none exists.
	 *
	 * @return void
	 */
	public function test_rg_declares_no_validator(): void {
		$presets = new \WCCheckoutSuite\Domain\Fields\PresetRegistry();

		\WCCheckoutSuite\Domain\Fields\BrazilianPresets::register_presets( $presets );

		self::assertArrayNotHasKey( 'validators', $presets->preset( 'br.rg' )->defaults() );
	}
}
