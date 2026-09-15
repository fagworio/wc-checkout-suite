<?php
/**
 * Blocks adapter tests.
 *
 * The adapter's job is to say what the Blocks checkout can be asked to render. The
 * interesting half is again the refusal: a type with no native counterpart, a
 * location that cannot be resolved and a storage scope the platform does not
 * perform all have to come back with a reason, because each of them would otherwise
 * be a field that silently does something other than what the merchant configured.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Blocks;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Blocks\BlocksAdapter;

/**
 * Translation of the published document into native Blocks fields.
 */
final class BlocksAdapterTest extends TestCase {

	/**
	 * The adapter.
	 *
	 * @var BlocksAdapter
	 */
	private BlocksAdapter $adapter;

	/**
	 * Sets up the adapter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adapter = new BlocksAdapter();
	}

	/**
	 * A definition the adapter can register.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function definition( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'wccs_company',
				'integration_id' => 'wc-checkoutsuite/wccs_company',
				'type'           => 'text',
				'label'          => 'Company',
				'section'        => 'billing',
				'enabled'        => true,
				'required'       => false,
				'settings'       => array(),
				'conditions'     => array(),
				'storage'        => array(
					'scope'       => 'order',
					'sensitivity' => 'personal',
				),
			),
			$overrides
		);
	}

	/**
	 * A published section.
	 *
	 * @param string $id       Identifier.
	 * @param string $location Location.
	 * @return array<string, mixed>
	 */
	private function section( string $id, string $location ): array {
		return array(
			'id'       => $id,
			'title'    => $id,
			'location' => $location,
			'position' => 10,
		);
	}

	/**
	 * The three native types are translated, and they are the ones the platform has.
	 *
	 * @return void
	 */
	public function test_the_native_types_are_the_ones_the_platform_accepts(): void {
		self::assertSame( array( 'text', 'select', 'checkbox' ), BlocksAdapter::NATIVE_TYPES );

		self::assertSame( 'text', BlocksAdapter::native_type( 'text' ) );
		self::assertSame( 'select', BlocksAdapter::native_type( 'select' ) );
		self::assertSame( 'checkbox', BlocksAdapter::native_type( 'checkbox' ) );
	}

	/**
	 * A masked native text field goes through the controlled path exactly once.
	 *
	 * @return void
	 */
	public function test_a_masked_text_field_is_not_registered_as_native(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'mask' => array(
							'key'     => 'br.cpf',
							'version' => 1,
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( array(), $translated['registrations'] );
		self::assertSame( 'needs_controlled_component', $translated['report'][0]['code'] );
	}

	/**
	 * A date field is refused, and the reason names what will deliver it.
	 *
	 * ROADMAP.md section 8 lists date among the natively supported types. The
	 * installed WooCommerce does not have it, and the adapter says so instead of
	 * registering a text field where the merchant configured a date.
	 *
	 * @return void
	 */
	public function test_a_date_field_is_refused_with_the_task_that_delivers_it(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'type' => 'date' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( array(), $translated['registrations'] );
		self::assertCount( 1, $translated['report'] );
		self::assertSame( 'needs_controlled_component', $translated['report'][0]['code'] );
		self::assertStringContainsString( 'WCCS-037', $translated['report'][0]['reason'] );
	}

	/**
	 * A type with no counterpart at all is refused as such.
	 *
	 * @return void
	 */
	public function test_a_type_with_no_native_counterpart_is_refused(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'type' => 'file' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( 'no_native_type', $translated['report'][0]['code'] );
	}

	/**
	 * The four section locations land where the Blocks checkout keeps them.
	 *
	 * @return void
	 */
	public function test_sections_are_resolved_to_native_locations(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'id'             => 'wccs_a',
						'integration_id' => 'wc-checkoutsuite/wccs_a',
						'section'        => 'billing',
					)
				),
				$this->definition(
					array(
						'id'             => 'wccs_b',
						'integration_id' => 'wc-checkoutsuite/wccs_b',
						'section'        => 'shipping',
					)
				),
				$this->definition(
					array(
						'id'             => 'wccs_c',
						'integration_id' => 'wc-checkoutsuite/wccs_c',
						'section'        => 'contact',
					)
				),
				$this->definition(
					array(
						'id'             => 'wccs_d',
						'integration_id' => 'wc-checkoutsuite/wccs_d',
						'section'        => 'order',
					)
				),
			),
			array(
				$this->section( 'billing', 'billing' ),
				$this->section( 'shipping', 'shipping' ),
				$this->section( 'contact', 'contact' ),
				$this->section( 'order', 'order' ),
			)
		);

		self::assertSame(
			array( 'address', 'address', 'contact', 'order' ),
			array_column( $translated['registrations'], 'location' )
		);
	}

	/**
	 * The platform is told not to print the field on its own.
	 *
	 * WooCommerce renders an additional field it knows about on the order confirmation and
	 * on the order details in the account unless the registration says otherwise, and that
	 * display answers to none of the destinations the merchant configured: the field would
	 * appear on both pages even when it was linked to one of them, or to none. Which area
	 * shows a value is the links tab's decision, and the Suite's projections are what carry
	 * it out.
	 *
	 * @return void
	 */
	public function test_the_platform_is_told_not_to_print_the_field_itself(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'section' => 'billing' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertCount( 1, $translated['registrations'] );
		self::assertFalse(
			$translated['registrations'][0]['show_in_order_confirmation'],
			'the registration forbids the platform its own display'
		);
	}

	/**
	 * A field whose section is not published is refused, not defaulted.
	 *
	 * @return void
	 */
	public function test_a_field_in_an_unknown_section_is_refused_rather_than_defaulted(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'section' => 'nope' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( array(), $translated['registrations'] );
		self::assertSame( 'unknown_location', $translated['report'][0]['code'] );
	}

	/**
	 * Storage the platform does not perform is refused.
	 *
	 * @return void
	 */
	public function test_storage_the_platform_does_not_perform_is_refused(): void {
		$none = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'storage' => array(
							'scope'       => 'none',
							'sensitivity' => 'personal',
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		$customer = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'storage' => array(
							'scope'       => 'customer',
							'sensitivity' => 'personal',
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( 'storage_not_native', $none['report'][0]['code'] );
		self::assertSame( 'storage_not_native', $customer['report'][0]['code'] );
		self::assertTrue( BlocksAdapter::storage_is_representable( $this->definition() ) );
		self::assertSame( array( 'order' ), BlocksAdapter::native_storage_scopes() );
	}

	/**
	 * A select carries its options, and one without any is refused.
	 *
	 * @return void
	 */
	public function test_a_select_carries_its_options(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'type'     => 'select',
						'settings' => array(
							'options' => array(
								array(
									'value' => 'pj',
									'label' => 'Company',
								),
								array(
									'value' => 'pf',
									'label' => 'Person',
								),
							),
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		$empty = $this->adapter->apply(
			array( $this->definition( array( 'type' => 'select' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame(
			array(
				array(
					'value' => 'pj',
					'label' => 'Company',
				),
				array(
					'value' => 'pf',
					'label' => 'Person',
				),
			),
			$translated['registrations'][0]['options']
		);
		self::assertSame( 'select_without_options', $empty['report'][0]['code'] );
	}

	/**
	 * A disabled field is not registered, and is not reported either.
	 *
	 * @return void
	 */
	public function test_a_disabled_field_is_not_registered_and_not_reported(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'enabled' => false ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( array(), $translated['registrations'] );
		self::assertSame( array(), $translated['report'] );
	}

	/**
	 * A required field with a compilable rule becomes conditionally required and conditional.
	 *
	 * This is where the compiler from WCCS-035 stops being a library: the same rule
	 * becomes the native `required` and the native `hidden`, which together mean
	 * "required exactly when it is shown".
	 *
	 * @return void
	 */
	public function test_a_compilable_rule_becomes_the_native_required_and_hidden(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'required'   => true,
						'conditions' => array(
							'visible' => array(
								'source'   => 'country',
								'operator' => 'equals',
								'value'    => 'BR',
							),
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		$rule = array(
			'customer' => array(
				'properties' => array(
					'billing_address' => array(
						'properties' => array(
							'country' => array( 'const' => 'BR' ),
						),
					),
				),
			),
		);

		self::assertSame( $rule, $translated['registrations'][0]['required'] );
		self::assertSame( array( 'not' => $rule ), $translated['registrations'][0]['hidden'] );
		self::assertSame( array(), $translated['report'] );
	}

	/**
	 * A visibility rule does not make an optional field required.
	 *
	 * @return void
	 */
	public function test_a_visible_optional_field_stays_optional(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'required'   => false,
						'conditions' => array(
							'visible' => array(
								'source'   => 'country',
								'operator' => 'equals',
								'value'    => 'BR',
							),
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertFalse( $translated['registrations'][0]['required'] );
		self::assertArrayHasKey( 'hidden', $translated['registrations'][0] );
	}

	/**
	 * A rule that cannot be compiled does not stop the field from being registered.
	 *
	 * ADR-0008: an incompatibility warns and never blocks. The field is registered
	 * without the condition, the report says why, and the server keeps deciding.
	 *
	 * @return void
	 */
	public function test_an_uncompilable_rule_is_reported_and_the_field_still_registers(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'conditions' => array(
							'visible' => array(
								'source'   => 'cart_categories',
								'operator' => 'contains',
								'value'    => 'books',
							),
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertCount( 1, $translated['registrations'] );
		self::assertArrayNotHasKey( 'hidden', $translated['registrations'][0] );
		self::assertSame( 'condition_not_native', $translated['report'][0]['code'] );
		self::assertStringContainsString( 'cart_categories', $translated['report'][0]['reason'] );
	}

	/**
	 * The identifier the platform sees is the integration one.
	 *
	 * @return void
	 */
	public function test_the_registration_uses_the_integration_identifier(): void {
		$translated = $this->adapter->apply(
			array( $this->definition() ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( 'wc-checkoutsuite/wccs_company', $translated['registrations'][0]['id'] );
	}

	/**
	 * A field WooCommerce owns is never registered as an additional field.
	 *
	 * §6.7: editing a native field has to modify the real field, never create a silent copy.
	 * Handing `billing_first_name` to the additional-fields API would ask the platform to draw
	 * a second field with the name of the real one, so the adapter refuses it and says which
	 * properties the Blocks checkout keeps as its own.
	 *
	 * @return void
	 */
	public function test_a_native_field_is_not_registered_as_an_additional_one(): void {
		$translated = $this->adapter->apply(
			array(
				$this->definition(
					array(
						'id'             => 'billing_first_name',
						'integration_id' => 'billing_first_name',
						'origin'         => 'core',
						'label'          => 'Nome',
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( array(), $translated['registrations'] );
		self::assertSame( 'core_field_not_registered', $translated['report'][0]['code'] );
		self::assertStringContainsString( 'billing_first_name', $translated['report'][0]['reason'] );
	}

	/**
	 * A field this plugin owns is still registered, so the rule is about the origin.
	 *
	 * @return void
	 */
	public function test_a_custom_field_is_registered_as_before(): void {
		$translated = $this->adapter->apply(
			array( $this->definition( array( 'origin' => 'custom' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertCount( 1, $translated['registrations'] );
		self::assertSame( array(), $translated['report'] );
	}
}
