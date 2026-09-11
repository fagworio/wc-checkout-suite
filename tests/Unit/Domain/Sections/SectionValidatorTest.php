<?php
/**
 * Section validation tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Sections;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;
use WCCheckoutSuite\Domain\Sections\SectionValidator;

/**
 * Covers the two gaps this task closed.
 *
 * Sections were stored without ever being validated, so a document could carry a
 * section with no title or two sections sharing an identifier. And a field's
 * section was never checked against anything, so it could point at a section that
 * does not exist — which on a checkout means a field that is configured, saved,
 * and rendered nowhere.
 */
final class SectionValidatorTest extends TestCase {

	/**
	 * Builds a valid section.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private function section( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 'dados_extras',
				'title'       => 'Dados extras',
				'description' => '',
				'position'    => 10,
				'location'    => 'billing',
			),
			$overrides
		);
	}

	/**
	 * A well-formed section is accepted.
	 *
	 * @return void
	 */
	public function test_a_valid_section_is_accepted(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section() ) );

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A section needs an identifier.
	 *
	 * @return void
	 */
	public function test_a_section_requires_an_id(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'id' => '' ) ) ) );

		self::assertContains( 'section_missing_id', $result->error_codes() );
	}

	/**
	 * An identifier the stored fields could not point at is refused.
	 *
	 * @return void
	 */
	public function test_a_section_id_must_be_machine_readable(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array( $this->section( array( 'id' => 'Dados Extras' ) ) )
		);

		self::assertContains( 'section_invalid_id', $result->error_codes() );
	}

	/**
	 * A section needs a title.
	 *
	 * @return void
	 */
	public function test_a_section_requires_a_title(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'title' => '' ) ) ) );

		self::assertContains( 'section_missing_title', $result->error_codes() );
	}

	/**
	 * A negative position is refused.
	 *
	 * @return void
	 */
	public function test_a_negative_position_is_refused(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'position' => -1 ) ) ) );

		self::assertContains( 'section_invalid_position', $result->error_codes() );
	}

	/**
	 * Only the five domain concepts are locations.
	 *
	 * @return void
	 */
	public function test_a_location_must_be_a_domain_concept(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'location' => 'sidebar' ) ) ) );

		self::assertContains( 'section_unknown_location', $result->error_codes() );
	}

	/**
	 * Two sections cannot share an identifier.
	 *
	 * @return void
	 */
	public function test_duplicate_section_ids_are_refused(): void {
		$result = SectionValidator::validate_sections(
			array(
				$this->section(),
				$this->section( array( 'title' => 'Outra' ) ),
			)
		);

		self::assertContains( 'duplicate_section_id', $result->error_codes() );
	}

	/**
	 * A field may belong to a declared section.
	 *
	 * @return void
	 */
	public function test_a_field_in_a_declared_section_is_accepted(): void {
		$result = SectionValidator::validate_references(
			array( $this->section() ),
			array(
				array(
					'id'      => 'billing_document',
					'section' => 'dados_extras',
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A field may belong to one of the five domain concepts without the document
	 * declaring a section for it, which is what adopting a WooCommerce field does.
	 *
	 * @return void
	 */
	public function test_a_field_in_a_domain_location_needs_no_declaration(): void {
		foreach ( array( 'billing', 'shipping', 'contact', 'account', 'order' ) as $location ) {
			$result = SectionValidator::validate_references(
				array(),
				array(
					array(
						'id'      => 'billing_document',
						'section' => $location,
					),
				)
			);

			self::assertTrue( $result->is_valid(), $location . ': ' . implode( ', ', $result->error_codes() ) );
		}
	}

	/**
	 * A field pointing at a section nobody declared is refused.
	 *
	 * @return void
	 */
	public function test_a_field_in_an_undeclared_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => 'minha_secao',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * An empty section is refused rather than assumed.
	 *
	 * @return void
	 */
	public function test_a_field_without_a_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => '',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * A field with no `section` key means "order", the same answer the definition
	 * model gives.
	 *
	 * This is not a hypothetical: the check originally read the raw key, so a
	 * document that omitted it was valid to FieldDefinition and invalid to the
	 * reference check at the same time. A stored document written by an older
	 * build depends on the two agreeing.
	 *
	 * @return void
	 */
	public function test_an_omitted_section_means_the_default_one(): void {
		$result = SectionValidator::validate_references(
			array(),
			array( array( 'id' => 'billing_document' ) )
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An explicit empty string is not the same as an omitted key: it names no
	 * section at all, and is refused.
	 *
	 * @return void
	 */
	public function test_an_explicitly_empty_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => '',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * An entry that is not an array is reported rather than skipped.
	 *
	 * @return void
	 */
	public function test_a_non_array_entry_is_reported(): void {
		$result = SectionValidator::validate_sections( array( 'not a section' ) );

		self::assertContains( 'invalid_section_entry', $result->error_codes() );
	}
}
