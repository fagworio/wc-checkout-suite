<?php
/**
 * Field binding tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldBinding;

/**
 * Covers the one use of a field: how it is read from a destination link, what it answers
 * about visibility and editing, and how it is written back in both shapes.
 */
final class FieldBindingTest extends TestCase {

	/**
	 * A link as a document written before bindings stored it.
	 *
	 * @return array<string, mixed>
	 */
	private function link(): array {
		return array(
			'enabled'  => true,
			'section'  => 'documentos',
			'title'    => 'Documento fiscal',
			'position' => 20,
			'mode'     => 'view',
			'actions'  => array( 'show_metadata', 'view', 'download' ),
		);
	}

	/**
	 * A link becomes a binding, and the two decisions it carried are kept apart.
	 *
	 * @return void
	 */
	public function test_a_link_is_read_as_a_binding(): void {
		$binding = FieldBinding::from_link( 'documento_fiscal', 'admin_order', $this->link() );

		self::assertSame( 'documento_fiscal', $binding->field_id() );
		self::assertSame( 'admin_order', $binding->destination() );
		self::assertSame( 'documentos', $binding->container_id() );
		self::assertTrue( $binding->is_visible() );
		self::assertFalse( $binding->is_editable(), 'view means shown and not editable' );
		self::assertSame( 'Documento fiscal', $binding->label_override() );
		self::assertSame( 20, $binding->position() );
		self::assertSame(
			array( 'show_metadata', 'view', 'download' ),
			$binding->permissions(),
			'the actions of a link are the permissions of the use'
		);
	}

	/**
	 * The edit mode is the editable flag; a disabled link is not visible.
	 *
	 * @return void
	 */
	public function test_mode_and_enabled_become_flags(): void {
		$editable = FieldBinding::from_link(
			'campo',
			'checkout',
			array(
				'enabled' => true,
				'mode'    => 'edit',
			)
		);

		self::assertTrue( $editable->is_visible() );
		self::assertTrue( $editable->is_editable() );

		$off = FieldBinding::from_link( 'campo', 'checkout', array( 'enabled' => false ) );

		self::assertFalse( $off->is_visible() );
	}

	/**
	 * The identifier of a use is derived, so migrating twice produces one document.
	 *
	 * @return void
	 */
	public function test_the_identifier_is_deterministic(): void {
		$first  = FieldBinding::from_link( 'campo', 'checkout', array( 'enabled' => true ) );
		$second = FieldBinding::from_link( 'campo', 'checkout', array( 'enabled' => true ) );
		$other  = FieldBinding::from_link( 'campo', 'admin_order', array( 'enabled' => true ) );

		self::assertSame( $first->id(), $second->id() );
		self::assertNotSame( $first->id(), $other->id() );
		self::assertSame( 'campo@checkout', $first->id() );
		self::assertSame(
			'campo@checkout/secao',
			FieldBinding::identifier_for( 'campo', 'checkout', 'secao' )
		);
	}

	/**
	 * A canonical binding fills the decisions it does not state.
	 *
	 * @return void
	 */
	public function test_a_stored_binding_defaults_to_visible_and_editable(): void {
		$binding = FieldBinding::from_array(
			array(
				'field_id'     => 'campo',
				'container_id' => 'secao',
				'destination'  => 'checkout',
			)
		);

		self::assertTrue( $binding->is_visible() );
		self::assertTrue( $binding->is_editable() );
		self::assertFalse( $binding->is_required_override() );
		self::assertSame( array(), $binding->permissions() );
		self::assertSame( 'campo@checkout/secao', $binding->id() );
	}

	/**
	 * The link the surfaces read is derived from the binding.
	 *
	 * @return void
	 */
	public function test_a_binding_projects_back_to_a_link(): void {
		$binding = FieldBinding::from_array(
			array(
				'id'             => 'campo@checkout/secao',
				'field_id'       => 'campo',
				'container_id'   => 'secao',
				'destination'    => 'checkout',
				'position'       => 30,
				'visible'        => true,
				'editable'       => false,
				'label_override' => 'Outro título',
				'permissions'    => array( 'view' ),
			)
		);

		self::assertSame(
			array(
				'enabled'  => true,
				'section'  => 'secao',
				'title'    => 'Outro título',
				'position' => 30,
				'mode'     => 'view',
				'actions'  => array( 'view' ),
			),
			$binding->to_link()
		);
	}

	/**
	 * A binding without a title shows the field's own label.
	 *
	 * @return void
	 */
	public function test_the_title_falls_back_to_the_label(): void {
		$named  = FieldBinding::from_link(
			'campo',
			'checkout',
			array(
				'enabled' => true,
				'title'   => 'Documento',
			)
		);
		$silent = FieldBinding::from_link( 'campo', 'checkout', array( 'enabled' => true ) );

		self::assertSame( 'Documento', $named->title_for( 'CPF' ) );
		self::assertSame( 'CPF', $silent->title_for( 'CPF' ) );
	}

	/**
	 * The canonical array carries every decision, with the documented keys.
	 *
	 * @return void
	 */
	public function test_the_canonical_array_has_the_final_keys(): void {
		$binding = FieldBinding::from_link( 'campo', 'checkout', $this->link() );
		$array   = $binding->to_array();

		self::assertSame(
			array(
				'id',
				'field_id',
				'container_id',
				'destination',
				'position',
				'visible',
				'editable',
				'required_override',
				'label_override',
				'description_override',
				'permissions',
				'conditions',
			),
			array_keys( $array )
		);
		self::assertSame( 'campo@checkout/documentos', $array['id'] );
	}
}
