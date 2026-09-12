<?php
/**
 * The capability matrix must say what the adapters do.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;
use WCCheckoutSuite\Domain\Checkout\AdapterCapabilities;

/**
 * Proves the matrix and the adapter answer the same question the same way.
 *
 * The failure this test exists for was found by the recovery run of WCCS-065. A document
 * named a type whose extension had been deactivated. The classic adapter skipped the
 * field and said why — and the matrix told the merchant the field would be rendered
 * through the plugin's own hooks, because the classic branch of the matrix answered
 * "native" for every type without asking the adapter. Two answers to one question, and
 * the merchant-facing one was the wrong one.
 *
 * The rule the pair of assertions below pins is that the matrix is a report about the
 * adapter, not a promise about the platform: where the adapter has a rendering, the
 * matrix says native; where it does not, the matrix says unsupported and gives a reason.
 */
final class AdapterCapabilitiesTest extends TestCase {

	/**
	 * Every type this plugin knows how to draw on the classic checkout is native there.
	 *
	 * @return void
	 */
	public function test_a_renderable_type_is_native_on_the_classic_checkout(): void {
		foreach ( array( 'text', 'select', 'checkbox', 'textarea', 'radio', 'email', 'tel' ) as $type ) {
			$capability = AdapterCapabilities::for_type( $type, AdapterCapabilities::CLASSIC );

			$this->assertSame(
				AdapterCapabilities::NATIVE,
				$capability['level'],
				$type . ' has a rendering in the classic adapter.'
			);
		}
	}

	/**
	 * A type the adapter refuses is not reported as something the checkout will draw.
	 *
	 * @return void
	 */
	public function test_a_type_the_adapter_cannot_render_is_unsupported(): void {
		$type = 'extension-gone/membership-code';

		$this->assertFalse(
			ClassicAdapter::can_render( $type ),
			'The premise of this test is that the adapter has no rendering for that type.'
		);

		$capability = AdapterCapabilities::for_type( $type, AdapterCapabilities::CLASSIC );

		$this->assertSame( AdapterCapabilities::UNSUPPORTED, $capability['level'] );
		$this->assertStringContainsString( $type, $capability['reason'] );
		$this->assertNotSame( '', $capability['reason'] );
	}

	/**
	 * The matrix and the adapter agree for every type the registry publishes.
	 *
	 * The general form of the rule, asserted over the whole vocabulary rather than over
	 * the two examples above: a matrix that agrees on the examples and disagrees on the
	 * rest is the same defect with a smaller surface.
	 *
	 * @return void
	 */
	public function test_the_matrix_and_the_adapter_agree_for_every_registered_type(): void {
		$types = array_keys( \WCCheckoutSuite\Domain\Registries::boot()->types()->types() );

		$this->assertNotSame( array(), $types, 'The registry publishes at least the core types.' );

		foreach ( $types as $type ) {
			$capability = AdapterCapabilities::for_type( (string) $type, AdapterCapabilities::CLASSIC );
			$renders    = ClassicAdapter::can_render( (string) $type );

			$this->assertSame(
				$renders,
				AdapterCapabilities::is_native( $capability['level'] ),
				'The matrix and the classic adapter disagree about "' . $type . '".'
			);
		}
	}
}
