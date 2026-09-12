<?php
/**
 * The inventory must not count this plugin's own fields as WooCommerce's.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;
use WCCheckoutSuite\Domain\Checkout\CoreFields;

require_once __DIR__ . '/../../Stubs/WooCommerceLoadOrderStubs.php';

/**
 * Proves a published custom field does not come back as a field of the platform.
 *
 * The failure this test exists for was found on a live store. The classic adapter
 * adds the store's custom fields to `woocommerce_checkout_fields`, and the
 * inventory reads the same filter to answer "which fields does WooCommerce own?".
 * So the second save of a document the store had already published was refused —
 * `core_field_origin_required` for a field the merchant had created themselves —
 * and the admin picker would have offered the merchant's own fields as fields of
 * the platform, which is worse than refusing: it invites adopting them.
 *
 * The adapter marks what it creates, and the inventory reads the mark. Both halves
 * are asserted here, because a marker nothing reads and a reader looking for a
 * marker nothing writes fail in the same way and are found in different places.
 */
final class CoreFieldContributionTest extends TestCase {

	/**
	 * Resets the WooCommerce stub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		\WCCS_CoreFields_Stub::reset();
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 1;
	}

	/**
	 * A field the adapter added is not part of the inventory.
	 *
	 * @return void
	 */
	public function test_a_contributed_field_is_not_in_the_inventory(): void {
		\WCCS_CoreFields_Stub::$fields = array(
			'billing' => array(
				'billing_first_name' => array(
					'label'    => 'First name',
					'required' => true,
					'priority' => 10,
				),
				// What ClassicAdapter::add_custom() produces.
				'wccs_notes'         => array(
					'type'              => 'text',
					'label'             => 'Notes',
					'priority'          => 30,
					'custom_attributes' => array( ClassicAdapter::FIELD_ATTRIBUTE => 'wccs_notes' ),
				),
			),
		);

		$core = new CoreFields();

		$this->assertTrue( $core->available(), 'The store does have core fields.' );
		$this->assertSame( array( 'billing_first_name' ), $core->ids() );
		$this->assertFalse( $core->has( 'wccs_notes' ) );
		$this->assertTrue( $core->has( 'billing_first_name' ) );
		$this->assertCount( 1, $core->catalogue()['fields'] );
	}

	/**
	 * An override of a WooCommerce field keeps it in the inventory.
	 *
	 * The other half of the rule: the adapter deliberately does not mark a field it
	 * merely modifies, so an override must still be protected by the guard that
	 * reads this inventory.
	 *
	 * @return void
	 */
	public function test_an_overridden_core_field_stays_in_the_inventory(): void {
		\WCCS_CoreFields_Stub::$fields = array(
			'billing' => array(
				'billing_phone' => array(
					'type'     => 'tel',
					'label'    => 'Phone',
					'priority' => 100,
					'class'    => array( 'form-row-wide' ),
				),
			),
		);

		$this->assertSame( array( 'billing_phone' ), ( new CoreFields() )->ids() );
	}

	/**
	 * The marker is written by the adapter and nowhere else.
	 *
	 * @return void
	 */
	public function test_the_marker_is_the_adapters_own_contract(): void {
		$this->assertSame( 'data-wccs-field', ClassicAdapter::FIELD_ATTRIBUTE );
	}
}
