<?php
/**
 * Field definition tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Covers the definition accessors that more than one layer reads.
 *
 * `options()` is the one that matters: the adapter that renders a select, the
 * checker that decides whether a submitted key is allowed and the snapshot that
 * remembers what a key meant all ask the same question. Three readers of one
 * setting is three chances to disagree, so the shape is pinned here and the
 * three callers share it.
 */
final class FieldDefinitionTest extends TestCase {

	/**
	 * Builds a definition.
	 *
	 * @param array<string, mixed> $changes Values to override.
	 * @return FieldDefinition
	 */
	private function definition( array $changes = array() ): FieldDefinition {
		return FieldDefinition::from_array(
			array_merge(
				array(
					'id'    => 'wccs_size',
					'type'  => 'select',
					'label' => 'Size',
				),
				$changes
			)
		);
	}

	/**
	 * Declared options become a value-to-label map.
	 *
	 * @return void
	 */
	public function test_declared_options_become_a_map(): void {
		$definition = $this->definition(
			array(
				'settings' => array(
					'options' => array(
						array(
							'value' => 's',
							'label' => 'Small',
						),
						array(
							'value' => 'm',
							'label' => 'Medium',
						),
					),
				),
			)
		);

		$this->assertSame(
			array(
				's' => 'Small',
				'm' => 'Medium',
			),
			$definition->options()
		);
	}

	/**
	 * A bare string is its own label, the way the choice type already reads it.
	 *
	 * @return void
	 */
	public function test_a_bare_string_option_is_its_own_label(): void {
		$definition = $this->definition( array( 'settings' => array( 'options' => array( 's', 'm' ) ) ) );

		$this->assertSame(
			array(
				's' => 's',
				'm' => 'm',
			),
			$definition->options()
		);
	}

	/**
	 * A label that is missing falls back to the value.
	 *
	 * @return void
	 */
	public function test_an_option_without_a_label_falls_back_to_its_value(): void {
		$definition = $this->definition( array( 'settings' => array( 'options' => array( array( 'value' => 's' ) ) ) ) );

		$this->assertSame( array( 's' => 's' ), $definition->options() );
	}

	/**
	 * A type that declares no options has none.
	 *
	 * @return void
	 */
	public function test_a_type_without_options_has_none(): void {
		$this->assertSame(
			array(),
			$this->definition(
				array(
					'type'     => 'text',
					'settings' => array(),
				)
			)->options()
		);
	}

	/**
	 * Settings that are not a list of options are ignored, not guessed at.
	 *
	 * @return void
	 */
	public function test_settings_that_are_not_options_are_ignored(): void {
		$this->assertSame( array(), $this->definition( array( 'settings' => array( 'options' => 's,m' ) ) )->options() );
		$this->assertSame( array(), $this->definition( array( 'settings' => array( 'options' => array( 42, null ) ) ) )->options() );
	}

	/**
	 * An omitted origin is `custom`.
	 *
	 * The model is the only place that knows this, which is why the origin is
	 * read through it rather than from the raw array.
	 *
	 * @return void
	 */
	public function test_an_omitted_origin_is_custom(): void {
		$this->assertSame( 'custom', $this->definition()->origin() );
		$this->assertSame( 'core', $this->definition( array( 'origin' => 'core' ) )->origin() );
	}

	/**
	 * A document written before bindings is read as bindings, one per enabled destination.
	 *
	 * @return void
	 */
	public function test_a_stored_destination_map_becomes_bindings(): void {
		$definition = $this->definition(
			array(
				'destinations' => array(
					'admin_order'      => array(
						'enabled'  => true,
						'section'  => 'documentos',
						'title'    => 'Documento fiscal',
						'position' => 20,
						'mode'     => 'view',
						'actions'  => array( 'show_metadata', 'view' ),
					),
					'customer_account' => array( 'enabled' => false ),
					'public_api'       => array( 'enabled' => false ),
				),
			)
		);

		$bindings = $definition->bindings();

		self::assertCount( 1, $bindings, 'a destination that is off is not a use of the field' );
		self::assertSame( 'admin_order', $bindings[0]->destination() );
		self::assertSame( 'documentos', $bindings[0]->container_id() );
		self::assertSame( 'Documento fiscal', $bindings[0]->label_override() );
		self::assertFalse( $bindings[0]->is_editable() );
		self::assertTrue( $definition->shows_in( 'admin_order' ) );
		self::assertFalse( $definition->shows_in( 'customer_account' ) );
		self::assertFalse( $definition->is_canonical() );
	}

	/**
	 * The same field can be used twice in the same destination — the reason bindings exist.
	 *
	 * @return void
	 */
	public function test_a_canonical_document_can_hold_two_bindings_in_one_destination(): void {
		$definition = $this->definition(
			array(
				'bindings' => array(
					array(
						'field_id'       => 'wccs_size',
						'container_id'   => 'medidas_cliente',
						'destination'    => 'customer_account',
						'label_override' => 'Tamanho atual',
						'position'       => 10,
						'visible'        => true,
						'editable'       => true,
					),
					array(
						'field_id'       => 'wccs_size',
						'container_id'   => 'medidas_pedido',
						'destination'    => 'customer_order',
						'label_override' => 'Tamanho do pedido',
						'position'       => 20,
						'visible'        => true,
						'editable'       => false,
						'permissions'    => array( 'view' ),
					),
				),
			)
		);

		self::assertTrue( $definition->is_canonical() );
		self::assertCount( 2, $definition->bindings() );

		$customer = $definition->bindings_for( 'customer_account' );
		$order    = $definition->bindings_for( 'customer_order' );

		self::assertCount( 1, $customer );
		self::assertCount( 1, $order );
		self::assertSame( 'medidas_cliente', $customer[0]->container_id() );
		self::assertSame( 'Tamanho atual', $customer[0]->title_for( 'Size' ) );
		self::assertTrue( $customer[0]->is_editable() );
		self::assertSame( 'Tamanho do pedido', $order[0]->title_for( 'Size' ) );
		self::assertFalse( $order[0]->is_editable() );
		self::assertSame( array( 'view' ), $order[0]->permissions() );
		self::assertTrue( $definition->shows_in( 'customer_account' ) );
		self::assertTrue( $definition->shows_in( 'customer_order' ) );
	}

	/**
	 * A canonical document writes its bindings and the map derived from them.
	 *
	 * The two shapes cannot disagree because only one of them is stored: the map the
	 * surfaces read is projected from the bindings on the way out.
	 *
	 * @return void
	 */
	public function test_a_canonical_document_writes_both_shapes_consistently(): void {
		$definition = $this->definition(
			array(
				'bindings' => array(
					array(
						'field_id'       => 'wccs_size',
						'container_id'   => 'medidas',
						'destination'    => 'customer_account',
						'label_override' => 'Tamanho atual',
						'position'       => 30,
						'visible'        => true,
						'editable'       => true,
						'permissions'    => array( 'show_metadata', 'view' ),
					),
				),
			)
		);

		$array = $definition->to_array();

		self::assertCount( 1, $array['bindings'] );
		self::assertSame( 'wccs_size@customer_account/medidas', $array['bindings'][0]['id'] );
		self::assertSame(
			array(
				'enabled'  => true,
				'section'  => 'medidas',
				'title'    => 'Tamanho atual',
				'position' => 30,
				'mode'     => 'edit',
				'actions'  => array( 'show_metadata', 'view' ),
			),
			$array['destinations']['customer_account']
		);
	}

	/**
	 * A document written before bindings keeps the map it has, with no bindings beside it.
	 *
	 * A canonical list written next to a map that the editor still edits would be two
	 * sources of truth for the same decision.
	 *
	 * @return void
	 */
	public function test_a_stored_map_is_written_back_unchanged(): void {
		$definition = $this->definition(
			array(
				'destinations' => array(
					'checkout' => array(
						'enabled' => true,
						'section' => 'billing',
						'mode'    => 'edit',
					),
				),
			)
		);

		$array = $definition->to_array();

		self::assertArrayNotHasKey(
			'bindings',
			$array,
			'an empty list would say "this field is used nowhere", which is not what it means'
		);
		self::assertSame( 'billing', $array['destinations']['checkout']['section'] );
		self::assertFalse( $definition->is_canonical() );
	}
}
