<?php
/**
 * File permission tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Uploads;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;

/**
 * Covers which use of a file may do what, per destination.
 *
 * The distinction that matters: `actions()` answers for the *area* — "may this area do this
 * with this value at all?" — while `actions_for_binding()` answers for the *use*, which is
 * what a surface that draws one row needs. A field used twice in the same area with
 * different permissions is the case both have to get right.
 */
final class FilePermissionsTest extends TestCase {

	/**
	 * Boots the registries, because whether a type stores a file is the registry's answer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Registries::boot();
	}

	/**
	 * A file field with the given uses.
	 *
	 * @param array<int, array<string, mixed>> $bindings Bindings.
	 * @return FieldDefinition
	 */
	private function file( array $bindings ): FieldDefinition {
		return FieldDefinition::from_array(
			array(
				'id'           => 'licenca',
				'origin'       => 'custom',
				'type'         => 'file',
				'label'        => 'Licença',
				'section'      => 'billing',
				'position'     => 10,
				'enabled'      => true,
				'storage'      => array(
					'scope'       => 'order',
					'sensitivity' => 'sensitive',
				),
				'destinations' => DefinitionVocabulary::default_destinations(),
				'bindings'     => $bindings,
			)
		);
	}

	/**
	 * One use.
	 *
	 * @param string             $destination Destination.
	 * @param array<int, string> $permissions Permissions.
	 * @param bool               $visible     Whether it is shown.
	 * @return array<string, mixed>
	 */
	private function binding( string $destination, array $permissions, bool $visible = true ): array {
		return array(
			'field_id'     => 'licenca',
			'container_id' => 'documentos',
			'destination'  => $destination,
			'visible'      => $visible,
			'permissions'  => $permissions,
		);
	}

	/**
	 * A stored link still answers, through the binding derived from it.
	 *
	 * @return void
	 */
	public function test_a_stored_link_answers(): void {
		$field = FieldDefinition::from_array(
			array(
				'id'           => 'licenca',
				'origin'       => 'custom',
				'type'         => 'file',
				'label'        => 'Licença',
				'section'      => 'billing',
				'position'     => 10,
				'enabled'      => true,
				'destinations' => array(
					'admin_order' => array(
						'enabled' => true,
						'actions' => array( 'show_metadata', 'view', 'download', 'approve' ),
					),
				),
			)
		);

		self::assertSame(
			array( 'show_metadata', 'view', 'download', 'approve' ),
			FilePermissions::actions( $field, 'admin_order' )
		);
		self::assertTrue( FilePermissions::allows( $field, 'admin_order', 'approve' ) );
		self::assertFalse( FilePermissions::allows( $field, 'admin_order', 'resubmit' ) );
	}

	/**
	 * The area answers the union of its uses.
	 *
	 * A stored link could declare one list of actions for a whole area, so a second use
	 * of the same field had nowhere to say what it could do. With one use per row, the
	 * area's answer is the sum of them — and a surface drawing one row asks that row.
	 *
	 * @return void
	 */
	public function test_the_area_answers_the_union_of_its_uses(): void {
		$field = $this->file(
			array(
				$this->binding( 'admin_order', array( 'show_metadata', 'view' ) ),
				$this->binding( 'admin_order', array( 'download' ) ),
				$this->binding( 'admin_order', array( 'approve' ), false ),
			)
		);

		self::assertSame(
			array( 'show_metadata', 'view', 'download' ),
			FilePermissions::actions( $field, 'admin_order' ),
			'both visible uses contribute, and the hidden one contributes nothing'
		);
		self::assertTrue(
			FilePermissions::allows( $field, 'admin_order', 'download' ),
			'the area allows it because one of its uses does'
		);

		$first = $field->bindings_for( 'admin_order' )[0];

		self::assertFalse(
			FilePermissions::allows_binding( $first, 'admin_order', 'download' ),
			'but the first use does not: the decision belongs to the use'
		);
	}

	/**
	 * Each use answers for itself, inside what its destination may do.
	 *
	 * @return void
	 */
	public function test_each_use_answers_for_itself(): void {
		$field = $this->file(
			array(
				$this->binding( 'admin_order', array( 'show_metadata', 'view', 'download', 'approve' ) ),
				$this->binding( 'customer_order', array( 'show_metadata', 'view', 'download', 'approve' ) ),
			)
		);

		$staff    = $field->bindings_for( 'admin_order' )[0];
		$customer = $field->bindings_for( 'customer_order' )[0];

		self::assertTrue( FilePermissions::allows_binding( $staff, 'admin_order', 'approve' ) );
		self::assertFalse(
			FilePermissions::allows_binding( $customer, 'customer_order', 'approve' ),
			'a destination does not perform what it does not offer, whatever the use declares'
		);
		self::assertTrue( FilePermissions::allows_binding( $customer, 'customer_order', 'download' ) );
	}

	/**
	 * A use that declares nothing gets the safe defaults of its destination.
	 *
	 * @return void
	 */
	public function test_an_empty_use_gets_the_defaults(): void {
		$field   = $this->file( array( $this->binding( 'customer_order', array() ) ) );
		$binding = $field->bindings_for( 'customer_order' )[0];

		self::assertSame(
			array( 'show_metadata', 'view', 'download' ),
			FilePermissions::actions_for_binding( $binding, 'customer_order' )
		);
	}

	/**
	 * A new customer-account document is writable and downloadable by default.
	 *
	 * @return void
	 */
	public function test_customer_account_defaults_include_replace_and_download(): void {
		$field   = $this->file( array( $this->binding( 'customer_account', array() ) ) );
		$binding = $field->bindings_for( 'customer_account' )[0];

		self::assertTrue( FilePermissions::allows_binding( $binding, 'customer_account', 'download' ) );
		self::assertTrue( FilePermissions::allows_binding( $binding, 'customer_account', 'resubmit' ) );
		self::assertFalse( FilePermissions::allows_binding( $binding, 'admin_order', 'resubmit' ) );
	}

	/**
	 * A use that is not visible allows nothing.
	 *
	 * @return void
	 */
	public function test_an_invisible_use_allows_nothing(): void {
		$field = $this->file(
			array(
				$this->binding( 'admin_order', array( 'show_metadata', 'view', 'download' ), false ),
			)
		);

		$binding = $field->bindings_for( 'admin_order' )[0];

		self::assertSame( array(), FilePermissions::actions_for_binding( $binding, 'admin_order' ) );
		self::assertFalse( FilePermissions::allows_binding( $binding, 'admin_order', 'show_metadata' ) );
	}

	/**
	 * Only a type that stores a file has file permissions.
	 *
	 * @return void
	 */
	public function test_a_type_without_a_file_has_no_permissions(): void {
		self::assertTrue( FilePermissions::is_file( $this->file( array( $this->binding( 'admin_order', array( 'view' ) ) ) ) ) );

		$text = FieldDefinition::from_array(
			array(
				'id'       => 'cpf',
				'origin'   => 'custom',
				'type'     => 'text',
				'label'    => 'CPF',
				'section'  => 'billing',
				'position' => 10,
				'enabled'  => true,
			)
		);

		self::assertFalse( FilePermissions::is_file( $text ) );
	}

	/**
	 * A binding is its own answer, without a definition to look through.
	 *
	 * @return void
	 */
	public function test_a_binding_answers_on_its_own(): void {
		$binding = FieldBinding::from_array( $this->binding( 'admin_email', array( 'view' ) ) );

		self::assertTrue( FilePermissions::allows_binding( $binding, 'admin_email', 'view' ) );
		self::assertFalse( FilePermissions::allows_binding( $binding, 'admin_email', 'download' ) );
	}
}
