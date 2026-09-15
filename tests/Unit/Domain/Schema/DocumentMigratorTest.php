<?php
/**
 * Document migration tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Schema;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Schema\DocumentMigrator;

/**
 * Covers the layer that reads a stored document in the final shape: what it converts, what
 * it refuses to guess, and the promise that running it twice changes nothing.
 */
final class DocumentMigratorTest extends TestCase {

	/**
	 * A document as every version before the final model wrote it.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return array(
			'revision' => 3,
			'fields'   => array(
				array(
					'id'             => 'preferencia_perfil',
					'integration_id' => 'wc-checkoutsuite/preferencia_perfil',
					'origin'         => 'custom',
					'type'           => 'text',
					'label'          => 'Preferência',
					'section'        => 'dados_fiscais',
					'enabled'        => true,
					'required'       => false,
					'position'       => 10,
					'storage'        => array( 'scope' => 'customer' ),
					'destinations'   => array(
						'customer_account' => array(
							'enabled'  => true,
							'section'  => 'preferencias_do_perfil',
							'title'    => 'Como prefere ser contactado',
							'position' => 10,
							'mode'     => 'edit',
						),
						'admin_customer'   => array(
							'enabled'  => true,
							'section'  => 'preferencias_do_perfil',
							'title'    => 'Preferência (equipa)',
							'position' => 10,
							'mode'     => 'view',
							'actions'  => array( 'show_metadata', 'view' ),
						),
						'public_api'       => array( 'enabled' => false ),
					),
				),
			),
			'sections' => array(
				array(
					'id'           => 'preferencias_do_perfil',
					'title'        => 'Preferências',
					'description'  => 'Dados da conta.',
					'position'     => 10,
					'location'     => 'account',
					'areas'        => array( 'customer_account', 'admin_customer' ),
					'presentation' => array(
						'show_title' => true,
						'account'    => array(
							'slug'       => 'preferencias',
							'menu_label' => 'Preferências',
							'icon'       => 'user',
							'mode'       => 'edit',
						),
					),
				),
			),
			'settings' => array(),
		);
	}

	/**
	 * A container offered in two destinations becomes one per destination.
	 *
	 * @return void
	 */
	public function test_a_container_offered_twice_is_split(): void {
		$result     = DocumentMigrator::migrate( $this->stored() );
		$containers = $result['document']['sections'];

		self::assertTrue( $result['changed'] );
		self::assertContains( 'container_split', $result['migrations'] );
		self::assertContains( 'destination_renamed', $result['migrations'] );
		self::assertCount( 2, $containers );

		$customer = $containers[0];
		$staff    = $containers[1];

		self::assertSame( 'preferencias_do_perfil', $customer['id'], 'the first keeps the identifier it had' );
		self::assertSame( 'customer_account', $customer['destination'] );
		self::assertSame(
			'preferencias_do_perfil__admin_customer_profile',
			$staff['id']
		);
		self::assertSame( 'admin_customer_profile', $staff['destination'] );
		self::assertSame( 'Preferências', $staff['name'] );
		self::assertTrue( $staff['show_title'] );
		self::assertSame( 'Preferências', $staff['display_title'] );
		self::assertSame( 'user', $staff['icon'] );
		self::assertSame( 'account', $staff['target'] );
		self::assertSame( array( 'admin_customer_profile' ), $staff['areas'] );
	}

	/**
	 * Each destination link becomes a binding, and each points at its own variant.
	 *
	 * @return void
	 */
	public function test_links_become_bindings_with_their_container(): void {
		$result = DocumentMigrator::migrate( $this->stored() );
		$field  = $result['document']['fields'][0];

		self::assertCount( 2, $field['bindings'] );

		$customer = $field['bindings'][0];
		$staff    = $field['bindings'][1];

		self::assertSame( 'customer_account', $customer['destination'] );
		self::assertSame( 'preferencias_do_perfil', $customer['container_id'] );
		self::assertSame( 'edit', $field['destinations']['customer_account']['mode'] );
		self::assertTrue( $customer['editable'] );

		self::assertSame( 'admin_customer_profile', $staff['destination'] );
		self::assertSame(
			'preferencias_do_perfil__admin_customer_profile',
			$staff['container_id'],
			'the binding follows the variant of its own destination'
		);
		self::assertSame( 'view', $field['destinations']['admin_customer_profile']['mode'] );
		self::assertFalse( $staff['editable'] );
		self::assertSame( array( 'show_metadata', 'view' ), $staff['permissions'] );
		self::assertSame( 'Preferência (equipa)', $staff['label_override'] );
		self::assertFalse( $field['destinations']['public_api']['enabled'] );
	}

	/**
	 * Running it on its own output changes nothing: the migration is idempotent.
	 *
	 * @return void
	 */
	public function test_the_migration_is_idempotent(): void {
		$once  = DocumentMigrator::migrate( $this->stored() );
		$twice = DocumentMigrator::migrate( $once['document'] );

		self::assertFalse( $twice['changed'] );
		self::assertSame( array(), $twice['migrations'] );
		self::assertSame( $once['document'], $twice['document'] );
	}

	/**
	 * A document already in the final shape is returned untouched.
	 *
	 * @return void
	 */
	public function test_a_canonical_document_is_left_alone(): void {
		$container = \WCCheckoutSuite\Domain\Sections\ContainerDefinition::from_array(
			array(
				'id'          => 'billing',
				'name'        => 'Cobrança',
				'destination' => 'checkout',
				'position'    => 10,
				'enabled'     => true,
				'show_title'  => true,
				'target'      => 'billing',
				'location'    => 'billing',
			)
		);

		$binding = \WCCheckoutSuite\Domain\Fields\FieldBinding::from_array(
			array(
				'field_id'     => 'campo',
				'container_id' => 'billing',
				'destination'  => 'checkout',
				'visible'      => true,
				'editable'     => true,
			)
		);

		// A document the final model wrote: the migration must have nothing to say about it.
		$document = array(
			'revision' => 1,
			'fields'   => array(
				array(
					'id'           => 'campo',
					'type'         => 'text',
					'label'        => 'Campo',
					'destinations' => array( 'checkout' => $binding->to_link() ),
					'bindings'     => array( $binding->to_array() ),
				),
			),
			'sections' => array( $container->to_array() ),
			'settings' => array(),
		);

		$result = DocumentMigrator::migrate( $document );

		self::assertFalse( $result['changed'] );
		self::assertSame( $document, $result['document'] );
	}

	/**
	 * A document that stores its uses is read from the list, not rebuilt from the map.
	 *
	 * The map holds one entry per destination, so rebuilding the list from it would erase a
	 * second use of the same destination — the one thing the list exists to carry. The map
	 * ends up as the projection of the list, which is what every surface reads.
	 *
	 * @return void
	 */
	public function test_the_uses_are_read_from_the_list_and_not_rebuilt_from_the_map(): void {
		$document = array(
			'revision' => 1,
			'fields'   => array(
				array(
					'id'       => 'autorizacao',
					'type'     => 'file',
					'label'    => 'Autorização',
					'bindings' => array(
						array(
							'field_id'     => 'autorizacao',
							'container_id' => 'documentos',
							'destination'  => 'customer_order',
							'position'     => 10,
							'visible'      => true,
							'permissions'  => array( 'show_metadata', 'view' ),
						),
						array(
							'field_id'     => 'autorizacao',
							'container_id' => 'anexos',
							'destination'  => 'customer_order',
							'position'     => 20,
							'visible'      => true,
							'permissions'  => array( 'download' ),
						),
					),
				),
			),
			'sections' => array(),
			'settings' => array(),
		);

		$result   = DocumentMigrator::migrate( $document );
		$migrated = $result['document']['fields'][0];

		self::assertCount( 2, $migrated['bindings'], 'both uses are still there' );
		self::assertSame(
			array( 'documentos', 'anexos' ),
			array_column( $migrated['bindings'], 'container_id' )
		);
		self::assertArrayHasKey(
			'customer_order',
			$migrated['destinations'],
			'the map is the projection of the list, so the surface still reads something'
		);
		self::assertSame(
			'download',
			$migrated['destinations']['customer_order']['actions'][0],
			'the map carries what it can: the last use of that destination'
		);

		$again = DocumentMigrator::migrate( $result['document'] );

		self::assertFalse( $again['changed'], 'and it is still idempotent' );
		self::assertSame( $result['document'], $again['document'] );
	}

	/**
	 * An edit written into the map reaches the use it describes.
	 *
	 * The editor writes the map today. For a destination with a single use the two shapes
	 * describe the same thing, so the map is the newer view and the use follows it; for a
	 * destination with two uses the map cannot describe them both, and the list decides.
	 *
	 * @return void
	 */
	public function test_an_edited_map_reaches_its_use(): void {
		$use = static function ( string $destination, string $container, string $title ): array {
			return array(
				'field_id'       => 'documento',
				'container_id'   => $container,
				'destination'    => $destination,
				'visible'        => true,
				'label_override' => $title,
			);
		};

		$document = array(
			'revision' => 1,
			'fields'   => array(
				array(
					'id'           => 'documento',
					'type'         => 'text',
					'label'        => 'Documento',
					// The list still says what was written before the edit; the map carries
					// what the merchant typed afterwards.
					'bindings'     => array(
						$use( 'admin_order', 'equipa', 'Antes' ),
						$use( 'customer_order', 'cliente', 'Cliente' ),
						$use( 'customer_order', 'anexos', 'Anexos' ),
					),
					'destinations' => array(
						'admin_order'    => array(
							'enabled' => true,
							'section' => 'equipa',
							'title'   => 'Depois',
							'mode'    => 'edit',
						),
						'customer_order' => array(
							'enabled' => true,
							'section' => 'cliente',
							'title'   => 'Não vale: este destino tem dois usos',
							'mode'    => 'edit',
						),
					),
				),
			),
			'sections' => array(),
			'settings' => array(),
		);

		$result   = DocumentMigrator::migrate( $document );
		$migrated = $result['document']['fields'][0];

		self::assertSame(
			array( 'Depois', 'Cliente', 'Anexos' ),
			array_column( $migrated['bindings'], 'label_override' ),
			'the single-use destination follows the map; the two-use one keeps its list'
		);
		self::assertSame( 'Depois', $migrated['destinations']['admin_order']['title'] );
		self::assertSame(
			'Anexos',
			$migrated['destinations']['customer_order']['title'],
			'the map is the projection of the list where the list is the authority: the last use'
		);
	}

	/**
	 * A destination no use justifies is not left saying it is on.
	 *
	 * The list is the authority, so a document whose map claims a destination the list does
	 * not use is promising a panel that nothing inserts. A link that is *off* is kept: that
	 * is configuration the merchant typed, and it is inert.
	 *
	 * @return void
	 */
	public function test_a_destination_no_use_justifies_is_dropped(): void {
		$document = array(
			'revision' => 1,
			'fields'   => array(
				array(
					'id'           => 'documento',
					'type'         => 'text',
					'label'        => 'Documento',
					'bindings'     => array(
						array(
							'field_id'     => 'documento',
							'container_id' => 'cliente',
							'destination'  => 'customer_order',
							'visible'      => true,
						),
					),
					'destinations' => array(
						// Nothing uses it, and the map still says "on".
						'admin_order'    => array(
							'enabled' => true,
							'section' => 'equipa',
						),
						// Off, and it stays: inert configuration is still configuration.
						'customer_email' => array( 'enabled' => false ),
					),
				),
			),
			'sections' => array(),
			'settings' => array(),
		);

		$migrated = DocumentMigrator::migrate( $document )['document']['fields'][0];

		self::assertArrayNotHasKey(
			'admin_order',
			$migrated['destinations'],
			'the promise goes with the use that justified it'
		);
		self::assertArrayHasKey( 'customer_order', $migrated['destinations'] );
		self::assertArrayHasKey(
			'customer_email',
			$migrated['destinations'],
			'a link that is off is kept'
		);
	}

	/**
	 * A destination that meant two places is not guessed: it is left for the validator.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_destination_is_not_converted(): void {
		$document = $this->stored();
		$document['fields'][0]['destinations']['customer_profile'] = array(
			'enabled' => true,
			'section' => 'preferencias_do_perfil',
			'mode'    => 'edit',
		);

		$result = DocumentMigrator::migrate( $document );

		self::assertArrayHasKey(
			'customer_profile',
			$result['document']['fields'][0]['destinations'],
			'the ambiguous key survives so the validator can refuse it by name'
		);
	}

	/**
	 * The identifiers, the values and the settings are never touched.
	 *
	 * @return void
	 */
	public function test_nothing_but_configuration_changes(): void {
		$before = $this->stored();
		$after  = DocumentMigrator::migrate( $before )['document'];

		self::assertSame( $before['revision'], $after['revision'] );
		self::assertSame( 'preferencia_perfil', $after['fields'][0]['id'] );
		self::assertSame( 'wc-checkoutsuite/preferencia_perfil', $after['fields'][0]['integration_id'] );
		self::assertSame( array( 'scope' => 'customer' ), $after['fields'][0]['storage'] );
		self::assertSame( array(), $after['settings'] );
	}
}
