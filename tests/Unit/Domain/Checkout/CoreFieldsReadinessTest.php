<?php
/**
 * Core field inventory readiness tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Checkout\CoreFields;

require_once __DIR__ . '/../../Stubs/WooCommerceLoadOrderStubs.php';

/**
 * Proves the inventory can never take the site down.
 *
 * These tests exist because of a real failure. The first version of the inventory
 * was read while the plugin booted, on `plugins_loaded`. That is before
 * WooCommerce has built its countries object, and
 * `WC_Checkout::get_checkout_fields()` dereferences it unconditionally, so the
 * read was a fatal error that took the whole site down — the front end, the admin
 * and WP-CLI alike.
 *
 * `function_exists( 'WC' )` is true from the moment WooCommerce's plugin file is
 * included, which is exactly why it is not a sufficient readiness check. The stub
 * therefore counts every attempt to reach the checkout object: the assertion is
 * not merely that no exception escapes, but that the object is never touched at
 * all before WooCommerce says it is ready.
 */
final class CoreFieldsReadinessTest extends TestCase {

	/**
	 * Resets the WooCommerce stub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		\WCCS_CoreFields_Stub::reset();
	}

	/**
	 * Before WooCommerce finishes starting, the inventory stands down.
	 *
	 * @return void
	 */
	public function test_stands_down_before_woocommerce_finishes_starting(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 0;

		$core   = new CoreFields();
		$result = $core->catalogue();

		$this->assertFalse( $core->available() );
		$this->assertFalse( $result['available'] );
		$this->assertStringContainsString( 'has not finished starting', $result['reason'] );
		$this->assertSame( array(), $result['fields'] );
		$this->assertSame(
			0,
			\WCCS_CoreFields_Stub::$checkout_calls,
			'The checkout object must not be touched before WooCommerce is ready.'
		);
	}

	/**
	 * Asking whether the inventory is available cannot touch the object either.
	 *
	 * @return void
	 */
	public function test_availability_check_does_not_touch_the_checkout_object_early(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 0;

		( new CoreFields() )->available();

		$this->assertSame( 0, \WCCS_CoreFields_Stub::$checkout_calls );
	}

	/**
	 * A reader that throws is reported, not propagated.
	 *
	 * The `woocommerce_checkout_fields` filter runs third-party callbacks, so this
	 * is the realistic failure: something else in the store is broken and the
	 * admin still has to be able to open.
	 *
	 * @return void
	 */
	public function test_a_throwing_reader_is_reported_not_propagated(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 1;
		\WCCS_CoreFields_Stub::$throw_on_read          = true;

		$core   = new CoreFields();
		$result = $core->catalogue();

		$this->assertFalse( $core->available() );
		$this->assertFalse( $result['available'] );
		$this->assertStringContainsString( 'Reading the WooCommerce checkout fields failed', $result['reason'] );
		$this->assertStringContainsString( 'deliberate failure', $result['reason'] );
	}

	/**
	 * When WooCommerce is ready, the inventory is read and reported.
	 *
	 * @return void
	 */
	public function test_reads_the_inventory_once_woocommerce_is_ready(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 1;
		\WCCS_CoreFields_Stub::$fields                 = array(
			'billing' => array(
				'billing_first_name' => array(
					'label'    => 'First name',
					'required' => true,
					'priority' => 10,
					'class'    => array( 'form-row-first' ),
				),
			),
		);

		$core   = new CoreFields();
		$result = $core->catalogue();

		$this->assertTrue( $result['available'] );
		$this->assertSame( '', $result['reason'] );
		$this->assertCount( 1, $result['fields'] );
		$this->assertSame( 'billing_first_name', $result['fields'][0]['id'] );
		$this->assertSame( 'billing', $result['fields'][0]['section'] );
		$this->assertTrue( $result['fields'][0]['required'] );
		$this->assertTrue( $result['fields'][0]['protected'] );
		$this->assertSame(
			array(
				'desktop' => 6,
				'tablet'  => 6,
				'mobile'  => 12,
			),
			$result['fields'][0]['layout'],
			'form-row-first must be translated to a half-width desktop column.'
		);
	}

	/**
	 * The filter is applied once, not once per question.
	 *
	 * @return void
	 */
	public function test_the_reader_runs_once_per_inventory(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 1;

		$core = new CoreFields();
		$core->catalogue();
		$core->catalogue();
		$core->available();
		$core->ids();

		$this->assertSame( 1, \WCCS_CoreFields_Stub::$checkout_calls );
	}

	/**
	 * An unavailable inventory is distinguishable from an empty store.
	 *
	 * @return void
	 */
	public function test_unavailable_is_distinguishable_from_empty(): void {
		\WCCS_CoreFields_Stub::$woocommerce_init_fired = 1;
		\WCCS_CoreFields_Stub::$fields                 = array();

		$result = ( new CoreFields() )->catalogue();

		$this->assertTrue( $result['available'], 'An empty store is still a successful read.' );
		$this->assertSame( array(), $result['fields'] );
	}
}
