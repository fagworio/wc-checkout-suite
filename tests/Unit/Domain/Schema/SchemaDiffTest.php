<?php
/**
 * Schema diff tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Schema;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Schema\SchemaDiff;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;

/**
 * Covers what the publication says it will change.
 *
 * The two claims worth defending are that a reordering is reported once rather
 * than once per field below it — moving the first of twenty fields renumbers
 * nineteen positions and is still one decision — and that a document is never
 * reported as different from itself.
 */
final class SchemaDiffTest extends TestCase {

	/**
	 * Builds a document.
	 *
	 * @param array<int, array<string, mixed>> $fields   Fields.
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @param int                              $revision Revision.
	 * @return SchemaDocument
	 */
	private function document( array $fields, array $sections = array(), int $revision = 1 ): SchemaDocument {
		return SchemaDocument::from_array(
			array(
				'revision' => $revision,
				'fields'   => $fields,
				'sections' => $sections,
				'settings' => array(),
			)
		);
	}

	/**
	 * Builds a field.
	 *
	 * @param string               $id       Identifier.
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private function field( string $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => $id,
				'type'     => 'text',
				'label'    => 'Label ' . $id,
				'section'  => 'billing',
				'position' => 10,
			),
			$overrides
		);
	}

	/**
	 * A document is not different from itself.
	 *
	 * @return void
	 */
	public function test_a_document_is_not_different_from_itself(): void {
		$document = $this->document( array( $this->field( 'a' ), $this->field( 'b', array( 'position' => 20 ) ) ) );

		$diff = SchemaDiff::between( $document, $document );

		self::assertTrue( $diff['empty'] );
		self::assertSame( 0, $diff['total_changes'] );
	}

	/**
	 * Key order inside a map is not a change.
	 *
	 * @return void
	 */
	public function test_key_order_is_not_a_change(): void {
		$before = $this->document(
			array(
				$this->field(
					'a',
					array(
						'settings' => array(
							'maxLength'   => 5,
							'placeholder' => 'x',
						),
					)
				),
			)
		);
		$after  = $this->document(
			array(
				$this->field(
					'a',
					array(
						'settings' => array(
							'placeholder' => 'x',
							'maxLength'   => 5,
						),
					)
				),
			)
		);

		self::assertTrue( SchemaDiff::between( $before, $after )['empty'] );
	}

	/**
	 * An added field is reported as an addition.
	 *
	 * @return void
	 */
	public function test_an_added_field_is_reported(): void {
		$diff = SchemaDiff::between(
			$this->document( array( $this->field( 'a' ) ) ),
			$this->document( array( $this->field( 'a' ), $this->field( 'b' ) ), array(), 2 )
		);

		self::assertSame( array( 'b' ), array_column( $diff['fields']['added'], 'id' ) );
		self::assertSame( array(), $diff['fields']['removed'] );
	}

	/**
	 * A removed field is reported as a removal.
	 *
	 * @return void
	 */
	public function test_a_removed_field_is_reported(): void {
		$diff = SchemaDiff::between(
			$this->document( array( $this->field( 'a' ), $this->field( 'b' ) ) ),
			$this->document( array( $this->field( 'a' ) ), array(), 2 )
		);

		self::assertSame( array( 'b' ), array_column( $diff['fields']['removed'], 'id' ) );
	}

	/**
	 * A changed value is reported with both sides.
	 *
	 * @return void
	 */
	public function test_a_changed_value_is_reported_with_both_sides(): void {
		$diff = SchemaDiff::between(
			$this->document( array( $this->field( 'a', array( 'label' => 'CPF' ) ) ) ),
			$this->document( array( $this->field( 'a', array( 'label' => 'Documento' ) ) ), array(), 2 )
		);

		$changed = $diff['fields']['changed'][0];

		self::assertSame( 'a', $changed['id'] );
		self::assertSame( 'label', $changed['differences'][0]['key'] );
		self::assertSame( 'CPF', $changed['differences'][0]['from'] );
		self::assertSame( 'Documento', $changed['differences'][0]['to'] );
	}

	/**
	 * A position change on its own is not a field change.
	 *
	 * @return void
	 */
	public function test_a_position_change_is_not_a_field_change(): void {
		$diff = SchemaDiff::between(
			$this->document( array( $this->field( 'a', array( 'position' => 10 ) ) ) ),
			$this->document( array( $this->field( 'a', array( 'position' => 40 ) ) ), array(), 2 )
		);

		self::assertSame( array(), $diff['fields']['changed'] );
	}

	/**
	 * A swap is one reordering, not two position changes.
	 *
	 * @return void
	 */
	public function test_a_swap_is_reported_as_one_reordering(): void {
		$before = $this->document(
			array(
				$this->field( 'a', array( 'position' => 10 ) ),
				$this->field( 'b', array( 'position' => 20 ) ),
				$this->field( 'c', array( 'position' => 30 ) ),
			)
		);

		$after = $this->document(
			array(
				$this->field( 'c', array( 'position' => 10 ) ),
				$this->field( 'a', array( 'position' => 20 ) ),
				$this->field( 'b', array( 'position' => 30 ) ),
			),
			array(),
			2
		);

		$diff = SchemaDiff::between( $before, $after );

		self::assertCount( 1, $diff['order'] );
		self::assertSame( 'billing', $diff['order'][0]['section'] );
		self::assertSame( array( 'a', 'b', 'c' ), $diff['order'][0]['from'] );
		self::assertSame( array( 'c', 'a', 'b' ), $diff['order'][0]['to'] );
		self::assertSame( 1, $diff['total_changes'] );
	}

	/**
	 * An addition does not also count as a reordering of the section.
	 *
	 * @return void
	 */
	public function test_an_addition_is_not_also_a_reordering(): void {
		$before = $this->document( array( $this->field( 'a', array( 'position' => 10 ) ) ) );
		$after  = $this->document(
			array(
				$this->field( 'a', array( 'position' => 10 ) ),
				$this->field( 'b', array( 'position' => 20 ) ),
			),
			array(),
			2
		);

		$diff = SchemaDiff::between( $before, $after );

		self::assertCount( 1, $diff['fields']['added'] );
		self::assertSame( array(), $diff['order'] );
		self::assertSame( 1, $diff['total_changes'] );
	}

	/**
	 * A section is reported when it is added, removed or changed.
	 *
	 * @return void
	 */
	public function test_sections_are_compared(): void {
		$section = array(
			'id'       => 'extra',
			'title'    => 'Extra',
			'position' => 10,
			'location' => 'billing',
		);

		$added = SchemaDiff::between(
			$this->document( array(), array() ),
			$this->document( array(), array( $section ), 2 )
		);

		self::assertSame( array( 'extra' ), array_column( $added['sections']['added'], 'id' ) );

		$removed = SchemaDiff::between(
			$this->document( array(), array( $section ) ),
			$this->document( array(), array(), 2 )
		);

		self::assertSame( array( 'extra' ), array_column( $removed['sections']['removed'], 'id' ) );

		$changed = SchemaDiff::between(
			$this->document( array(), array( $section ) ),
			$this->document( array(), array( array_merge( $section, array( 'title' => 'Outra' ) ) ), 2 )
		);

		self::assertSame(
			'title',
			$changed['sections']['changed'][0]['differences'][0]['key']
		);
	}

	/**
	 * A field that only moved section is reported, not silently ignored.
	 *
	 * @return void
	 */
	public function test_moving_a_field_between_sections_is_a_change(): void {
		$diff = SchemaDiff::between(
			$this->document( array( $this->field( 'a', array( 'section' => 'billing' ) ) ) ),
			$this->document( array( $this->field( 'a', array( 'section' => 'shipping' ) ) ), array(), 2 )
		);

		self::assertSame( 'section', $diff['fields']['changed'][0]['differences'][0]['key'] );
	}

	/**
	 * Document settings are compared too.
	 *
	 * @return void
	 */
	public function test_document_settings_are_compared(): void {
		$before = SchemaDocument::from_array(
			array(
				'revision' => 1,
				'fields'   => array(),
				'sections' => array(),
				'settings' => array( 'checkout' => 'classic' ),
			)
		);

		$after = SchemaDocument::from_array(
			array(
				'revision' => 2,
				'fields'   => array(),
				'sections' => array(),
				'settings' => array( 'checkout' => 'blocks' ),
			)
		);

		$diff = SchemaDiff::between( $before, $after );

		self::assertSame( 'checkout', $diff['settings'][0]['key'] );
		self::assertFalse( $diff['empty'] );
	}

	/**
	 * The revisions being compared are reported, so the panel can name them.
	 *
	 * @return void
	 */
	public function test_the_two_revisions_are_reported(): void {
		$diff = SchemaDiff::between(
			$this->document( array(), array(), 4 ),
			$this->document( array(), array(), 9 )
		);

		self::assertSame( 4, $diff['published']['revision'] );
		self::assertSame( 9, $diff['draft']['revision'] );
	}
}
