<?php
/**
 * Brazilian document and preset contract tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Validation;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\BrazilianPresets;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\PresetRegistry;
use WCCheckoutSuite\Domain\Validation\BrazilianDocuments;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;

/**
 * Covers the contracts the Brazilian presets declare.
 *
 * The vectors come from `resources/fixtures/br-documents.json`, which the
 * JavaScript suite reads as well: the two sides have to agree on what a document
 * becomes, and a fixture each side keeps separately is a fixture that diverges.
 *
 * Validity is not tested here. Check digits are WCCS-028's contract, and the
 * fixtures say so — several of the documents below have arbitrary digits on
 * purpose, and reading them as validity vectors would be a mistake this class
 * makes impossible to hide.
 */
final class BrazilianDocumentsTest extends TestCase {

	/**
	 * Normalizer registry under test.
	 *
	 * @var NormalizerRegistry
	 */
	private NormalizerRegistry $normalizers;

	/**
	 * Mask registry under test.
	 *
	 * @var MaskRegistry
	 */
	private MaskRegistry $masks;

	/**
	 * Preset registry under test.
	 *
	 * @var PresetRegistry
	 */
	private PresetRegistry $presets;

	/**
	 * The shared fixtures.
	 *
	 * @var array<string, mixed>
	 */
	private array $fixtures;

	/**
	 * Builds the registries and reads the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->normalizers = new NormalizerRegistry();
		$this->masks       = new MaskRegistry();
		$this->presets     = new PresetRegistry();

		BrazilianDocuments::register_normalizers( $this->normalizers );
		BrazilianDocuments::register_masks( $this->masks );
		BrazilianPresets::register_presets( $this->presets );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixture shipped with the plugin; the sniff targets remote URLs.
		$raw = file_get_contents( dirname( __DIR__, 4 ) . '/resources/fixtures/br-documents.json' );

		self::assertIsString( $raw, 'the shared fixture file is readable' );

		$decoded = json_decode( $raw, true );

		self::assertIsArray( $decoded, 'the shared fixture file is valid JSON' );

		$this->fixtures = $decoded;
	}

	/**
	 * Normalizes one value with a named normalizer.
	 *
	 * @param string $key   Normalizer key.
	 * @param string $value Value.
	 * @return mixed
	 */
	private function normalize( string $key, string $value ): mixed {
		return $this->normalizers->run( $key, $value, new FieldContext( array(), 'classic' ) );
	}

	/**
	 * The fixture cases of one document.
	 *
	 * @param string $key Normalizer key.
	 * @return array<int, array<string, string>>
	 */
	private function cases( string $key ): array {
		$cases = $this->fixtures['normalization'][ $key ] ?? array();

		self::assertIsArray( $cases, $key . ' has fixtures' );
		self::assertNotSame( array(), $cases, $key . ' has at least one fixture' );

		return $cases;
	}

	/**
	 * Every fixture normalizes to exactly what it says it does.
	 *
	 * @return void
	 */
	public function test_every_fixture_normalizes_to_its_canonical_value(): void {
		foreach ( array_keys( $this->fixtures['normalization'] ) as $key ) {
			foreach ( $this->cases( (string) $key ) as $case ) {
				self::assertSame(
					$case['canonical'],
					$this->normalize( (string) $key, $case['input'] ),
					$key . ': ' . $case['why']
				);
			}
		}
	}

	/**
	 * A canonical value is a string, always.
	 *
	 * ADR-0003 rule 7: CPF, CNPJ and CEP are strings end to end. A document that
	 * comes back as an integer has already lost its leading zeros, and a document
	 * that comes back as a float has lost everything.
	 *
	 * @return void
	 */
	public function test_a_canonical_value_is_always_a_string(): void {
		foreach ( array_keys( $this->fixtures['normalization'] ) as $key ) {
			foreach ( $this->cases( (string) $key ) as $case ) {
				self::assertIsString(
					$this->normalize( (string) $key, $case['input'] ),
					$key . ' returns a string for ' . $case['input']
				);
			}
		}
	}

	/**
	 * An alphanumeric CNPJ keeps its letters.
	 *
	 * This is the phase gate and the reason ADR-0003 exists. A normalizer written
	 * as `preg_replace( '/\D/', '', $value )` passes the numeric case and turns
	 * this one into a different, plausible-looking number.
	 *
	 * @return void
	 */
	public function test_an_alphanumeric_cnpj_is_never_reduced_to_digits(): void {
		$canonical = $this->normalize( 'br.cnpj', '12.ABC.345/01DE-35' );

		self::assertSame( '12ABC34501DE35', $canonical );
		self::assertStringContainsString( 'ABC', $canonical );
		self::assertStringContainsString( 'DE', $canonical );
		self::assertSame( 14, strlen( (string) $canonical ) );
	}

	/**
	 * Lowercase letters are the same document written differently.
	 *
	 * @return void
	 */
	public function test_cnpj_letters_are_stored_in_uppercase(): void {
		self::assertSame(
			$this->normalize( 'br.cnpj', '12.ABC.345/01DE-35' ),
			$this->normalize( 'br.cnpj', '12.abc.345/01de-35' )
		);
	}

	/**
	 * Leading zeros survive on every document that can have them.
	 *
	 * @return void
	 */
	public function test_leading_zeros_are_preserved(): void {
		self::assertSame( '01234567890', $this->normalize( 'br.cpf', '012.345.678-90' ) );
		self::assertSame( '00111222333344', $this->normalize( 'br.cnpj', '00.111.222/3333-44' ) );
		self::assertSame( '01310100', $this->normalize( 'br.cep', '01310-100' ) );
	}

	/**
	 * An unrecognised character is kept, never cleaned away.
	 *
	 * Section 9 and ADR-0003 rule 6: removing enough of a wrong value to make it
	 * look right is how a wrong document gets stored. The character is left for
	 * the validator to refuse.
	 *
	 * @return void
	 */
	public function test_an_illegal_character_is_not_discarded(): void {
		self::assertSame( '1234567890A', $this->normalize( 'br.cpf', '123.456.789-0A' ) );
		self::assertSame( '12ABC34501DE3!', $this->normalize( 'br.cnpj', '12.ABC.345/01DE-3!' ) );
		self::assertSame( '1199999888A', $this->normalize( 'br.phone', '(11) 99999-888A' ) );
	}

	/**
	 * A value that is not a string is left alone.
	 *
	 * Converting it is what would turn a document into a number, and a document
	 * that arrived as one has already lost its zeros somewhere else.
	 *
	 * @return void
	 */
	public function test_a_non_string_value_is_not_converted(): void {
		self::assertSame( 12345678909, $this->normalizers->run( 'br.cpf', 12345678909, new FieldContext() ) );
		self::assertNull( $this->normalizers->run( 'br.cpf', null, new FieldContext() ) );
	}

	/**
	 * The mask of a document writes exactly as many characters as the document has.
	 *
	 * The mask is an aid for typing and the length is a property of the value; a
	 * mask that offers a character too few silently refuses a valid document at
	 * the last keystroke, and one that offers a character too many accepts an
	 * unfinished value as complete.
	 *
	 * @return void
	 */
	public function test_every_mask_offers_exactly_the_documents_length(): void {
		foreach ( $this->fixtures['masks'] as $key => $expected ) {
			$mask = $this->masks->mask( (string) $key );

			self::assertNotNull( $mask, 'the mask ' . $key . ' is registered' );

			$definition = $mask->definition();

			self::assertIsString( $definition, 'the mask ' . $key . ' is a pattern' );

			$tokens = substr_count( $definition, '0' ) + substr_count( $definition, '*' );

			self::assertSame(
				$expected['canonicalLength'],
				$tokens,
				'the mask ' . $key . ' offers one position per character of the stored value'
			);
		}
	}

	/**
	 * The numeric documents' fixtures all have the length their mask offers.
	 *
	 * Held for CPF, CNPJ and CEP, where the written form is a fixed shape. Not
	 * held for phone, and deliberately: a telephone number with a country code is
	 * longer, which is why section 9 says the Brazilian mask is not imposed on an
	 * international number.
	 *
	 * @return void
	 */
	public function test_a_fixed_shape_document_has_one_length(): void {
		foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep' ) as $key ) {
			$expected = $this->fixtures['masks'][ $key ]['canonicalLength'];

			foreach ( $this->cases( $key ) as $case ) {
				self::assertSame(
					$expected,
					strlen( $case['canonical'] ),
					$key . ': ' . $case['canonical']
				);
			}
		}
	}

	/**
	 * A telephone number with a country code is not truncated.
	 *
	 * The Brazilian presets declare a Brazilian mask and a Brazilian length, and a
	 * number from outside Brazil does not fit either. What must not happen is the
	 * country code being cut away to make it fit: the value survives whole, and the
	 * merchant who needs international numbers uses a plain telephone field.
	 *
	 * @return void
	 */
	public function test_a_country_code_is_not_cut_away(): void {
		self::assertSame( '5511999998888', $this->normalize( 'br.phone', '+55 (11) 99999-8888' ) );
	}

	/**
	 * Every document preset references a mask and a normalizer that exist.
	 *
	 * A preset is what the merchant clicks. A mask key that is not registered
	 * would make every field built from it fail validation at save, and the
	 * mistake would surface at the merchant's screen rather than here.
	 *
	 * @return void
	 */
	public function test_every_preset_references_something_that_exists(): void {
		$documents = array( 'br.cpf', 'br.cnpj', 'br.cep' );

		foreach ( $documents as $key ) {
			$preset = $this->presets->preset( $key );

			self::assertNotNull( $preset, 'the preset ' . $key . ' is registered' );

			$defaults = $preset->defaults();

			self::assertSame( $key, $defaults['normalizer'], $key . ' names its normalizer' );
			self::assertTrue( $this->normalizers->has( (string) $defaults['normalizer'] ), $key . ' normalized by a registered normalizer' );
			self::assertSame( $key, $defaults['mask']['key'], $key . ' names its mask' );
			self::assertTrue( $this->masks->has( (string) $defaults['mask']['key'] ), $key . ' masked by a registered mask' );
		}
	}

	/**
	 * The phone presets share a normalizer and use masks of their own.
	 *
	 * @return void
	 */
	public function test_the_phone_presets_are_two_masks_over_one_policy(): void {
		$landline = $this->presets->preset( 'br.phone.landline' );
		$mobile   = $this->presets->preset( 'br.phone.mobile' );

		self::assertNotNull( $landline );
		self::assertNotNull( $mobile );

		self::assertSame( 'br.phone', $landline->defaults()['normalizer'] );
		self::assertSame( 'br.phone', $mobile->defaults()['normalizer'] );
		self::assertSame( 'br.phone.landline', $landline->defaults()['mask']['key'] );
		self::assertSame( 'br.phone.mobile', $mobile->defaults()['mask']['key'] );
	}

	/**
	 * A preset declares a validator only where one exists.
	 *
	 * This test said the opposite while the check digits were a promise: no preset
	 * declared a validator, because a key that is not registered makes every field
	 * built from the preset unsaveable. WCCS-028 registered them and the document
	 * presets now name them, so the assertion had to change — and the part of it
	 * that still matters is the other half, which is that nothing else claims one.
	 * A preset naming a key that exists but does nothing would be a promise the
	 * code does not keep.
	 *
	 * @return void
	 */
	public function test_only_a_document_preset_declares_a_validator(): void {
		$documents = array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' );

		foreach ( $this->presets->presets() as $key => $preset ) {
			if ( in_array( (string) $key, $documents, true ) ) {
				self::assertArrayHasKey(
					'validators',
					$preset->defaults(),
					'the preset ' . (string) $key . ' declares the validator that checks it'
				);

				continue;
			}

			self::assertArrayNotHasKey(
				'validators',
				$preset->defaults(),
				'the preset ' . (string) $key . ' declares no validator, because none exists for it'
			);
		}
	}

	/**
	 * RG claims nothing about its shape.
	 *
	 * Its format depends on the issuing state and on the document type, and
	 * inventing a national one was rejected in ADR-0003.
	 *
	 * @return void
	 */
	public function test_rg_claims_no_shape(): void {
		$preset = $this->presets->preset( 'br.rg' );

		self::assertNotNull( $preset );
		self::assertArrayNotHasKey( 'mask', $preset->defaults() );
		self::assertArrayNotHasKey( 'normalizer', $preset->defaults() );
		self::assertSame( 'text', $preset->type() );
	}

	/**
	 * No document preset is built on a numeric type.
	 *
	 * @return void
	 */
	public function test_no_document_preset_is_numeric(): void {
		foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep' ) as $key ) {
			$preset = $this->presets->preset( $key );

			self::assertNotSame( 'number', $preset->type(), $key . ' is not a number field' );
		}
	}

	/**
	 * The presets the planning lists are the presets that ship.
	 *
	 * @return void
	 */
	public function test_the_planned_presets_are_the_registered_ones(): void {
		$expected = array(
			'br.cpf',
			'br.cnpj',
			'br.rg',
			'br.cep',
			'br.phone.landline',
			'br.phone.mobile',
			'br.person-type',
			'br.company-name',
			'br.trade-name',
			'br.address.number',
			'br.address.neighborhood',
			'br.address.complement',
			'br.state-registration',
			'br.municipal-registration',
			'br.birthdate',
		);

		$registered = array_keys( $this->presets->presets() );

		sort( $expected );
		sort( $registered );

		self::assertSame( $expected, $registered );
	}

	/**
	 * The optional presets ship disabled.
	 *
	 * @return void
	 */
	public function test_the_optional_presets_ship_disabled(): void {
		foreach ( array( 'br.state-registration', 'br.municipal-registration', 'br.birthdate' ) as $key ) {
			$preset = $this->presets->preset( $key );

			self::assertNotNull( $preset, $key . ' is registered' );
			self::assertFalse( $preset->is_enabled(), $key . ' ships disabled' );
		}

		foreach ( array( 'br.cpf', 'br.cnpj', 'br.rg', 'br.cep' ) as $key ) {
			self::assertTrue( $this->presets->preset( $key )->is_enabled(), $key . ' ships enabled' );
		}
	}

	/**
	 * The person type preset offers PF and PJ as options.
	 *
	 * @return void
	 */
	public function test_the_person_type_preset_offers_both_choices(): void {
		$options = $this->presets->preset( 'br.person-type' )->settings()['options'] ?? array();

		self::assertSame( array( 'pf', 'pj' ), array_column( $options, 'value' ) );
	}
}
