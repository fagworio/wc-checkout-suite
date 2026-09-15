<?php
/**
 * Container definition tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Sections;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Sections\ContainerDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/**
 * Covers the container: its own destination, the presentation of that place, and the two
 * historical names it still answers to while the readers migrate.
 */
final class ContainerDefinitionTest extends TestCase {

	/**
	 * A container written by the final model.
	 *
	 * @return array<string, mixed>
	 */
	private function container(): array {
		return array(
			'id'            => 'documentacao',
			'name'          => 'Documentação',
			'destination'   => 'checkout',
			'position'      => 20,
			'enabled'       => true,
			'show_title'    => false,
			'display_title' => 'Documentação da compra',
			'description'   => 'Envie a licença.',
			'icon'          => 'file',
			'target'        => 'order',
			'settings'      => array( 'columns' => 2 ),
			'presentation'  => array( 'show_title' => false ),
		);
	}

	/**
	 * The canonical keys are read and written.
	 *
	 * @return void
	 */
	public function test_the_final_keys_are_read(): void {
		$container = ContainerDefinition::from_array( $this->container() );

		self::assertSame( 'documentacao', $container->id() );
		self::assertSame( 'Documentação', $container->name() );
		self::assertSame( 'checkout', $container->destination() );
		self::assertSame( array( 'checkout' ), $container->destinations() );
		self::assertSame( 20, $container->position() );
		self::assertTrue( $container->is_enabled() );
		self::assertFalse( $container->show_title() );
		self::assertSame( 'Documentação da compra', $container->display_title() );
		self::assertSame( 'file', $container->icon() );
		self::assertSame( 'order', $container->target() );
		self::assertSame( array( 'columns' => 2 ), $container->settings() );
		self::assertTrue( $container->is_offered_in( 'checkout' ) );
		self::assertFalse( $container->is_offered_in( 'customer_account' ) );
	}

	/**
	 * A container written before the split is read as one, without losing where it lived.
	 *
	 * @return void
	 */
	public function test_a_stored_section_is_read_as_a_container(): void {
		$container = ContainerDefinition::from_array(
			array(
				'id'           => 'preferencias_do_perfil',
				'title'        => 'Preferências',
				'description'  => '',
				'position'     => 10,
				'location'     => 'account',
				'areas'        => array( 'customer_account', 'admin_customer_profile' ),
				'presentation' => array(
					'show_title' => true,
					'account'    => array( 'slug' => 'preferencias' ),
				),
			)
		);

		self::assertSame( 'Preferências', $container->name() );
		self::assertSame( 'Preferências', $container->title(), 'the historical name still answers' );
		self::assertSame( 'customer_account', $container->destination(), 'the first area is where it is edited' );
		self::assertSame( array( 'customer_account', 'admin_customer_profile' ), $container->destinations() );
		self::assertTrue( $container->is_offered_in( 'admin_customer_profile' ) );
		self::assertTrue( $container->show_title() );
		self::assertSame( 'preferencias', $container->account()['slug'] );
		self::assertSame( 'account', $container->location(), 'the location the adapter inserts by is kept' );
		self::assertSame( 'account', $container->target(), 'and it is the insertion point of a checkout section' );
	}

	/**
	 * The title is shown unless the container says otherwise, as it always was.
	 *
	 * @return void
	 */
	public function test_the_title_defaults_to_shown(): void {
		$silent = ContainerDefinition::from_array(
			array(
				'id'   => 'a',
				'name' => 'A',
			)
		);

		self::assertTrue( $silent->show_title() );
		self::assertTrue( $silent->shows_title(), 'the historical reader answers the same' );
		self::assertSame( 'A', $silent->display_title() );
	}

	/**
	 * A container offered nowhere is readable as such: the validator refuses it.
	 *
	 * @return void
	 */
	public function test_an_empty_area_list_means_offered_nowhere(): void {
		$nowhere = ContainerDefinition::from_array(
			array(
				'id'    => 'orfa',
				'name'  => 'Órfã',
				'areas' => array(),
			)
		);

		self::assertSame( array(), $nowhere->destinations() );
		self::assertFalse( $nowhere->is_offered_in( 'checkout' ) );
	}

	/**
	 * A container naming several destinations splits without losing anything.
	 *
	 * @return void
	 */
	public function test_a_container_can_be_offered_in_one_destination(): void {
		$container = ContainerDefinition::from_array(
			array(
				'id'    => 'dados',
				'name'  => 'Dados',
				'areas' => array( 'customer_account', 'admin_customer_profile' ),
			)
		);

		$customer = $container->for_destination( 'customer_account' );
		$staff    = $container->for_destination( 'admin_customer_profile' );

		self::assertSame( 'dados', $customer->id(), 'the first keeps the identifier it always had' );
		self::assertSame( 'dados__admin_customer_profile', $staff->id() );
		self::assertSame( array( 'admin_customer_profile' ), $staff->destinations() );
		self::assertSame( 'admin_customer_profile', $staff->destination() );
		self::assertSame( 'Dados', $staff->name() );
	}

	/**
	 * The array written back carries the canonical keys and the projection the readers use.
	 *
	 * @return void
	 */
	public function test_the_array_carries_both_shapes(): void {
		$array = ContainerDefinition::from_array( $this->container() )->to_array();

		self::assertSame( 'Documentação', $array['name'] );
		self::assertSame( 'Documentação', $array['title'], 'derived, never independent' );
		self::assertSame( 'checkout', $array['destination'] );
		self::assertSame( array( 'checkout' ), $array['areas'] );
		self::assertSame( false, $array['show_title'] );
		self::assertSame( 'order', $array['target'] );
		self::assertSame( 'order', $array['location'] );
		self::assertSame( 'Documentação da compra', $array['display_title'] );
	}

	/**
	 * The historical class is the same behaviour under the name the readers use.
	 *
	 * @return void
	 */
	public function test_the_historical_name_is_the_same_container(): void {
		$section = SectionDefinition::from_array( $this->container() );

		self::assertInstanceOf( ContainerDefinition::class, $section );
		self::assertInstanceOf( SectionDefinition::class, $section );
		self::assertSame( 'Documentação', $section->title() );
		self::assertSame( 'checkout', $section->destination() );
	}
}
