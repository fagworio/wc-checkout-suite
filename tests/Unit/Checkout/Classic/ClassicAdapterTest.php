<?php
/**
 * Classic adapter tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Classic;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;

/**
 * Covers the translation from a stored schema to the classic field array.
 *
 * The claim in the acceptance is "campos core mantêm contratos", and the way it
 * is kept is by the adapter never assigning them: it cannot break a contract it
 * does not write. Most of what follows checks exactly that, by asserting that
 * what WooCommerce and other plugins put in the array is still there afterwards.
 */
final class ClassicAdapterTest extends TestCase {

	/**
	 * Adapter under test.
	 *
	 * @var ClassicAdapter
	 */
	private ClassicAdapter $adapter;

	/**
	 * Sets up the adapter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adapter = new ClassicAdapter();
	}

	/**
	 * A WooCommerce field, as another plugin might have shaped it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function woo_fields(): array {
		return array(
			'billing'  => array(
				'billing_first_name' => array(
					'type'         => 'text',
					'label'        => 'First name',
					'required'     => true,
					'class'        => array( 'form-row-first' ),
					'validate'     => array( 'required' ),
					'sanitize'     => array( 'trim' ),
					'autocomplete' => 'given-name',
					'priority'     => 10,
				),
			),
			'shipping' => array(
				'shipping_first_name' => array(
					'type'     => 'text',
					'label'    => 'First name',
					'required' => false,
					'priority' => 10,
				),
			),
			'order'    => array(
				'order_comments' => array(
					'type'     => 'textarea',
					'label'    => 'Notes',
					'required' => false,
					'priority' => 10,
				),
			),
		);
	}

	/**
	 * Builds a definition.
	 *
	 * @param string               $id      Identifier.
	 * @param array<string, mixed> $changes Values to override.
	 * @return array<string, mixed>
	 */
	private function definition( string $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'       => $id,
				'origin'   => 'custom',
				'type'     => 'text',
				'label'    => 'Label ' . $id,
				'section'  => 'billing',
				'enabled'  => true,
				'required' => false,
				'position' => 30,
				'layout'   => array(
					'desktop' => 12,
					'tablet'  => 12,
					'mobile'  => 12,
				),
			),
			$changes
		);
	}

	/**
	 * A custom field is added with the type the schema declares.
	 *
	 * @return void
	 */
	public function test_a_custom_text_field_is_added(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'billing_document', array( 'required' => true ) ) )
		);

		self::assertArrayHasKey( 'billing_document', $fields['billing'] );
		self::assertSame( 'text', $fields['billing']['billing_document']['type'] );
		self::assertSame( 'Label billing_document', $fields['billing']['billing_document']['label'] );
		self::assertTrue( $fields['billing']['billing_document']['required'] );
		self::assertSame( 30, $fields['billing']['billing_document']['priority'] );
	}

	/**
	 * The settings the type declares reach the field.
	 *
	 * @return void
	 */
	public function test_declared_settings_are_carried(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition(
					'billing_document',
					array(
						'settings' => array(
							'placeholder' => '000.000.000-00',
							'maxLength'   => 14,
						),
					)
				),
			)
		);

		$field = $fields['billing']['billing_document'];

		self::assertSame( '000.000.000-00', $field['placeholder'] );
		self::assertSame( 14, $field['custom_attributes']['maxlength'] );
	}

	/**
	 * A field the Suite owns is marked as its own.
	 *
	 * The client half has to tell the Suite's fields apart from every other field
	 * on the form, and the identifier a merchant chose is not a pattern anything
	 * could match on. The marker is how the server says which fields are its own.
	 *
	 * @return void
	 */
	public function test_a_custom_field_is_marked_as_the_suites_own(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				array(
					'id'      => 'wccs_cpf',
					'type'    => 'text',
					'section' => 'billing',
				),
			)
		);

		self::assertSame( 'wccs_cpf', $fields['billing']['wccs_cpf']['custom_attributes']['data-wccs-field'] );
	}

	/**
	 * The marker and the declared attributes live side by side.
	 *
	 * @return void
	 */
	public function test_the_marker_does_not_displace_a_declared_attribute(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				array(
					'id'       => 'wccs_cpf',
					'type'     => 'text',
					'section'  => 'billing',
					'settings' => array( 'maxLength' => 11 ),
				),
			)
		);

		self::assertSame(
			array(
				'data-wccs-field' => 'wccs_cpf',
				'maxlength'       => 11,
			),
			$fields['billing']['wccs_cpf']['custom_attributes']
		);
	}

	/**
	 * A field WooCommerce owns is not marked.
	 *
	 * WooCommerce re-renders and repopulates its own fields from the session.
	 * Marking one would invite the client half to restore a value the platform is
	 * already responsible for, and would put a key on a core field that the
	 * adapter's own rule says it does not touch.
	 *
	 * @return void
	 */
	public function test_a_field_woocommerce_owns_is_not_marked(): void {
		$woo = array(
			'billing' => array(
				'billing_first_name' => array(
					'type'              => 'text',
					'label'             => 'First name',
					'required'          => true,
					'custom_attributes' => array( 'autocomplete' => 'given-name' ),
				),
			),
		);

		$fields = $this->adapter->apply(
			$woo,
			array(
				array(
					'id'      => 'billing_first_name',
					'type'    => 'text',
					'origin'  => 'core',
					'label'   => 'Primeiro nome',
					'section' => 'billing',
				),
			)
		);

		self::assertSame( 'Primeiro nome', $fields['billing']['billing_first_name']['label'] );
		self::assertSame(
			array( 'autocomplete' => 'given-name' ),
			$fields['billing']['billing_first_name']['custom_attributes'],
			'the attributes WooCommerce set are untouched'
		);
	}

	/**
	 * Options become the value to label map the checkout expects.
	 *
	 * @return void
	 */
	public function test_options_become_a_map(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition(
					'person_type',
					array(
						'type'     => 'select',
						'settings' => array(
							'options' => array(
								array(
									'value' => 'pf',
									'label' => 'Individual',
								),
								array(
									'value' => 'pj',
									'label' => 'Company',
								),
							),
						),
					)
				),
			)
		);

		self::assertSame(
			array(
				'pf' => 'Individual',
				'pj' => 'Company',
			),
			$fields['billing']['person_type']['options']
		);
	}

	/**
	 * A type the classic checkout cannot render is skipped, and said so.
	 *
	 * Rendering a file upload as a text input would accept something the store
	 * cannot keep. The report is the honest alternative.
	 *
	 * @return void
	 */
	public function test_an_unrenderable_type_is_skipped_and_reported(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'billing_avatar', array( 'type' => 'file' ) ) )
		);

		self::assertArrayNotHasKey( 'billing_avatar', $fields['billing'] );

		$report = $this->adapter->report();

		self::assertCount( 1, $report );
		self::assertSame( 'billing_avatar', $report[0]['field'] );
		self::assertSame( 'skipped', $report[0]['level'] );
		self::assertStringContainsString( 'no rendering', $report[0]['reason'] );
	}

	/**
	 * A type rendered as something simpler is reported as reduced.
	 *
	 * @return void
	 */
	public function test_a_degraded_type_is_rendered_and_reported(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'billing_birth', array( 'type' => 'date' ) ) )
		);

		self::assertSame( 'text', $fields['billing']['billing_birth']['type'] );

		$report = $this->adapter->report();

		self::assertSame( 'degraded', $report[0]['level'] );
		self::assertStringContainsString( 'text input', $report[0]['reason'] );
	}

	/**
	 * Every registered type is either renderable or explicitly not.
	 *
	 * @return void
	 */
	public function test_renderability_is_a_decision_per_type(): void {
		self::assertTrue( ClassicAdapter::can_render( 'text' ) );
		self::assertTrue( ClassicAdapter::can_render( 'select' ) );
		self::assertTrue( ClassicAdapter::can_render( 'date' ) );
		self::assertFalse( ClassicAdapter::can_render( 'file' ) );
		self::assertFalse( ClassicAdapter::can_render( 'heading' ) );
		self::assertFalse( ClassicAdapter::can_render( 'html' ) );
	}

	/**
	 * An archived field is not rendered at all.
	 *
	 * @return void
	 */
	public function test_a_disabled_field_is_not_rendered(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'billing_old', array( 'enabled' => false ) ) )
		);

		self::assertArrayNotHasKey( 'billing_old', $fields['billing'] );
		self::assertSame( array(), $this->adapter->report() );
	}

	/**
	 * A core override changes presentation and nothing else.
	 *
	 * @return void
	 */
	public function test_a_core_override_preserves_the_contract(): void {
		$before = $this->woo_fields();
		$fields = $this->adapter->apply(
			$before,
			array(
				$this->definition(
					'billing_first_name',
					array(
						'origin'   => 'core',
						'label'    => 'Nome',
						'section'  => 'billing',
						'position' => 5,
						'layout'   => array( 'desktop' => 6 ),
						'settings' => array( 'placeholder' => 'Como no documento' ),
					)
				),
			)
		);

		$after = $fields['billing']['billing_first_name'];

		self::assertSame( 'Nome', $after['label'] );
		self::assertSame( 'Como no documento', $after['placeholder'] );
		self::assertSame( 5, $after['priority'] );

		// The contract, byte for byte, from what another plugin had put there.
		self::assertSame( 'text', $after['type'] );
		self::assertTrue( $after['required'] );
		self::assertSame( array( 'required' ), $after['validate'] );
		self::assertSame( array( 'trim' ), $after['sanitize'] );
		self::assertSame( 'given-name', $after['autocomplete'] );

		// And the array that was passed in is untouched.
		self::assertSame( 'First name', $before['billing']['billing_first_name']['label'] );
	}

	/**
	 * An override whose WooCommerce field is gone changes nothing.
	 *
	 * @return void
	 */
	public function test_an_override_without_its_field_is_ignored(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition(
					'billing_gone',
					array(
						'origin'  => 'core',
						'section' => 'billing',
					)
				),
			)
		);

		self::assertArrayNotHasKey( 'billing_gone', $fields['billing'] );
	}

	/**
	 * Widths the WooCommerce grid understands use its classes.
	 *
	 * @return void
	 */
	public function test_widths_use_the_woocommerce_grid(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition( 'a', array( 'layout' => array( 'desktop' => 12 ) ) ),
				$this->definition( 'b', array( 'layout' => array( 'desktop' => 6 ) ) ),
				$this->definition( 'c', array( 'layout' => array( 'desktop' => 6 ) ) ),
				$this->definition( 'd', array( 'layout' => array( 'desktop' => 4 ) ) ),
			)
		);

		self::assertSame( array( 'form-row-wide' ), $fields['billing']['a']['class'] );
		self::assertSame( array( 'form-row-first' ), $fields['billing']['b']['class'] );
		self::assertSame( array( 'form-row-last' ), $fields['billing']['c']['class'] );
		self::assertSame( array( 'form-row-wide', 'wccs-col-4' ), $fields['billing']['d']['class'] );
	}

	/**
	 * Halves alternate per section, not across the page.
	 *
	 * @return void
	 */
	public function test_halves_alternate_within_each_section(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition(
					'a',
					array(
						'section' => 'billing',
						'layout'  => array( 'desktop' => 6 ),
					)
				),
				$this->definition(
					'b',
					array(
						'section' => 'shipping',
						'layout'  => array( 'desktop' => 6 ),
					)
				),
			)
		);

		self::assertSame( array( 'form-row-first' ), $fields['billing']['a']['class'] );
		self::assertSame( array( 'form-row-first' ), $fields['shipping']['b']['class'] );
	}

	/**
	 * Sections land where the classic checkout has room for them.
	 *
	 * @return void
	 */
	public function test_sections_map_to_woocommerce_sections(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition( 'a', array( 'section' => 'shipping' ) ),
				$this->definition( 'b', array( 'section' => 'account' ) ),
				$this->definition( 'c', array( 'section' => 'order' ) ),
				$this->definition( 'd', array( 'section' => 'contact' ) ),
			)
		);

		self::assertArrayHasKey( 'a', $fields['shipping'] );
		self::assertArrayHasKey( 'b', $fields['account'] );
		self::assertArrayHasKey( 'c', $fields['order'] );

		// The classic checkout has no contact step: what reaches the customer
		// lives with the billing address.
		self::assertArrayHasKey( 'd', $fields['billing'] );
	}

	/**
	 * A declared section sends its fields to its own location.
	 *
	 * @return void
	 */
	public function test_a_declared_section_uses_its_location(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'a', array( 'section' => 'dados_extras' ) ) ),
			array(
				array(
					'id'       => 'dados_extras',
					'title'    => 'Extra',
					'location' => 'shipping',
				),
			)
		);

		self::assertArrayHasKey( 'a', $fields['shipping'] );
	}

	/**
	 * Fields are applied in the order the merchant arranged.
	 *
	 * @return void
	 */
	public function test_halves_alternate_in_position_order(): void {
		$fields = $this->adapter->apply(
			$this->woo_fields(),
			array(
				$this->definition(
					'later',
					array(
						'position' => 50,
						'layout'   => array( 'desktop' => 6 ),
					)
				),
				$this->definition(
					'earlier',
					array(
						'position' => 20,
						'layout'   => array( 'desktop' => 6 ),
					)
				),
			)
		);

		self::assertSame( array( 'form-row-first' ), $fields['billing']['earlier']['class'] );
		self::assertSame( array( 'form-row-last' ), $fields['billing']['later']['class'] );
	}

	/**
	 * The report is reset for each translation.
	 *
	 * @return void
	 */
	public function test_the_report_does_not_accumulate(): void {
		$this->adapter->apply(
			$this->woo_fields(),
			array( $this->definition( 'a', array( 'type' => 'file' ) ) )
		);

		self::assertCount( 1, $this->adapter->report() );

		$this->adapter->apply( $this->woo_fields(), array( $this->definition( 'b' ) ) );

		self::assertSame( array(), $this->adapter->report() );
	}

	/**
	 * An empty schema leaves the checkout exactly as WooCommerce built it.
	 *
	 * @return void
	 */
	public function test_an_empty_schema_changes_nothing(): void {
		$before = $this->woo_fields();

		self::assertSame( $before, $this->adapter->apply( $before, array() ) );
	}
}
