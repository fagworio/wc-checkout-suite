<?php
/**
 * The document, read the way one area reads it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Orders;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\AreaProjection;
use WCCheckoutSuite\Domain\Orders\OrderFieldEntry;

/**
 * Section, title and order, read from the link and not from the field.
 *
 * Section 14's sentence has four parts, and this is the last three: a linked field
 * appears "na seção, com o título e na ordem configurados". They are the link's, so
 * the same field can be third on the order screen and first in the customer's e-mail,
 * and the projection is where that is decided for every surface at once.
 */
final class AreaProjectionTest extends TestCase {

	/**
	 * One entry.
	 *
	 * @param string $id    Identifier.
	 * @param string $label Label recorded with the value.
	 * @return OrderFieldEntry
	 */
	private function entry( string $id, string $label ): OrderFieldEntry {
		return new OrderFieldEntry( $id, $label, 'text', 'valor', array(), OrderFieldEntry::SOURCE_SCHEMA, true );
	}

	/**
	 * One definition.
	 *
	 * @param string               $id           Identifier.
	 * @param string               $section      The field's own section.
	 * @param int                  $position     The field's own position.
	 * @param array<string, mixed> $destinations Destination links.
	 * @return array<string, mixed>
	 */
	private function definition( string $id, string $section, int $position, array $destinations ): array {
		return array(
			'id'           => $id,
			'origin'       => 'custom',
			'type'         => 'text',
			'label'        => 'Rótulo ' . $id,
			'section'      => $section,
			'position'     => $position,
			'enabled'      => true,
			'destinations' => $destinations,
		);
	}

	/**
	 * Two sections, in the order the document declares them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sections(): array {
		return array(
			array(
				'id'       => 'depois',
				'title'    => 'Depois',
				'position' => 20,
				'location' => 'order',
				'areas'    => array( 'admin_order' ),
			),
			array(
				'id'       => 'antes',
				'title'    => 'Antes',
				'position' => 10,
				'location' => 'order',
				'areas'    => array( 'admin_order' ),
			),
		);
	}

	/**
	 * The link decides the three things, and the document order decides nothing.
	 *
	 * @return void
	 */
	public function test_the_link_decides_section_title_and_order(): void {
		$definitions = array(
			$this->definition(
				'primeiro',
				'billing',
				99,
				array(
					'admin_order' => array(
						'enabled'  => true,
						'section'  => 'antes',
						'title'    => 'Primeiro',
						'position' => 10,
					),
				)
			),
			$this->definition(
				'segundo',
				'billing',
				1,
				array(
					'admin_order' => array(
						'enabled'  => true,
						'section'  => 'depois',
						'title'    => 'Segundo',
						'position' => 10,
					),
				)
			),
		);

		// The entries come in the order the values were stored, which is neither the
		// section order nor the configured one.
		$groups = AreaProjection::group(
			array( $this->entry( 'segundo', 'Rótulo segundo' ), $this->entry( 'primeiro', 'Rótulo primeiro' ) ),
			$definitions,
			$this->sections(),
			'admin_order'
		);

		self::assertCount( 2, $groups );

		// Section order is the document's position, not the order of the entries.
		self::assertSame( 'antes', $groups[0]['id'] );
		self::assertSame( 'Antes', $groups[0]['title'] );
		self::assertSame( 'Primeiro', $groups[0]['fields'][0]['title'] );
		self::assertSame( 'segundo', $groups[1]['fields'][0]['entry']->id() );
		self::assertSame( 'depois', $groups[1]['id'] );
		self::assertSame( 'Segundo', $groups[1]['fields'][0]['title'] );
	}

	/**
	 * Within one section the link's position decides, whatever order the entries came in.
	 *
	 * @return void
	 */
	public function test_the_configured_order_wins_inside_a_section(): void {
		$link = static fn( int $position ): array => array(
			'admin_order' => array(
				'enabled'  => true,
				'section'  => 'antes',
				'position' => $position,
			),
		);

		$definitions = array(
			$this->definition( 'terceiro', 'billing', 1, $link( 30 ) ),
			$this->definition( 'primeiro', 'billing', 2, $link( 10 ) ),
			$this->definition( 'segundo', 'billing', 3, $link( 20 ) ),
		);

		$groups = AreaProjection::group(
			array(
				$this->entry( 'primeiro', 'Um' ),
				$this->entry( 'segundo', 'Dois' ),
				$this->entry( 'terceiro', 'Três' ),
			),
			$definitions,
			$this->sections(),
			'admin_order'
		);

		self::assertCount( 1, $groups );
		self::assertSame(
			array( 'primeiro', 'segundo', 'terceiro' ),
			array_map( static fn( array $field ): string => $field['entry']->id(), $groups[0]['fields'] )
		);
	}

	/**
	 * A link that only turns the destination on keeps behaving as it did: the field's
	 * own section, label and position are used, and no section title is invented.
	 *
	 * @return void
	 */
	public function test_a_link_without_a_section_uses_the_fields_own(): void {
		$definitions = array(
			$this->definition( 'simples', 'billing', 7, array( 'admin_order' => array( 'enabled' => true ) ) ),
		);

		$groups = AreaProjection::group(
			array( $this->entry( 'simples', 'O rótulo gravado' ) ),
			$definitions,
			$this->sections(),
			'admin_order'
		);

		self::assertCount( 1, $groups );
		self::assertSame( 'billing', $groups[0]['id'] );
		self::assertSame( '', $groups[0]['title'] );
		self::assertSame( 'O rótulo gravado', $groups[0]['fields'][0]['title'] );
		self::assertSame( 7, $groups[0]['fields'][0]['position'] );
	}

	/**
	 * Nothing this area was not given, and nothing it was not linked to.
	 *
	 * @return void
	 */
	public function test_only_linked_entries_of_this_area_are_grouped(): void {
		$definitions = array(
			$this->definition( 'outro_destino', 'billing', 1, array( 'customer_order' => array( 'enabled' => true ) ) ),
			$this->definition( 'desligado', 'billing', 1, array( 'admin_order' => array( 'enabled' => false ) ) ),
			$this->definition(
				'este',
				'billing',
				1,
				array(
					'admin_order' => array(
						'enabled' => true,
						'section' => 'antes',
					),
				)
			),
		);

		$groups = AreaProjection::group(
			array(
				$this->entry( 'outro_destino', 'Outro' ),
				$this->entry( 'desligado', 'Desligado' ),
				$this->entry( 'este', 'Este' ),
				$this->entry( 'sem_definicao', 'Sem definição' ),
			),
			$definitions,
			$this->sections(),
			'admin_order'
		);

		self::assertCount( 1, $groups );
		self::assertSame(
			array( 'este' ),
			array_map( static fn( array $field ): string => $field['entry']->id(), $groups[0]['fields'] )
		);
	}

	/**
	 * A field that is not in the document has no link to read, and is dropped.
	 *
	 * @return void
	 */
	public function test_a_definition_that_no_longer_exists_is_dropped(): void {
		$groups = AreaProjection::group(
			array( $this->entry( 'antigo', 'Antigo' ) ),
			array(),
			$this->sections(),
			'admin_order'
		);

		self::assertSame( array(), $groups );
	}

	/**
	 * The projection reads definitions, not a field object, so a document that is still
	 * an array is enough.
	 *
	 * @return void
	 */
	public function test_a_definition_is_read_through_the_model(): void {
		$definition = FieldDefinition::from_array(
			$this->definition(
				'com_titulo',
				'billing',
				1,
				array(
					'admin_order' => array(
						'enabled' => true,
						'section' => 'antes',
						'title'   => 'Do documento',
					),
				)
			)
		);

		$groups = AreaProjection::group(
			array( $this->entry( 'com_titulo', 'Do pedido' ) ),
			array( $definition->to_array() ),
			$this->sections(),
			'admin_order'
		);

		self::assertSame( 'Do documento', $groups[0]['fields'][0]['title'] );
	}
}
