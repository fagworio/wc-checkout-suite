<?php
/**
 * Requirement evaluation tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Support\Requirements;

/**
 * Covers the requirement guard that keeps activation safe without WooCommerce.
 */
final class RequirementsTest extends TestCase {

	/**
	 * Requirements used by the tests.
	 *
	 * @var array<string, string>
	 */
	private array $required = array(
		'php'         => '8.2',
		'wordpress'   => '7.1',
		'woocommerce' => '11.1',
	);

	/**
	 * A component that is not installed is reported as missing, never as a fatal.
	 *
	 * @return void
	 */
	public function test_absent_component_is_reported_as_missing(): void {
		$unmet = Requirements::evaluate(
			array(
				'php'         => '8.2.1',
				'wordpress'   => '7.1',
				'woocommerce' => '',
			),
			$this->required
		);

		self::assertCount( 1, $unmet );
		self::assertSame( 'woocommerce', $unmet[0]['key'] );
		self::assertSame( 'missing', $unmet[0]['reason'] );
		self::assertSame( '', $unmet[0]['observed'] );
	}

	/**
	 * A component below the floor is reported as outdated with both versions.
	 *
	 * @return void
	 */
	public function test_older_version_is_reported_as_outdated(): void {
		$unmet = Requirements::evaluate(
			array(
				'php'         => '8.2.1',
				'wordpress'   => '7.1',
				'woocommerce' => '9.0.0',
			),
			$this->required
		);

		self::assertCount( 1, $unmet );
		self::assertSame( 'outdated', $unmet[0]['reason'] );
		self::assertSame( '9.0.0', $unmet[0]['observed'] );
		self::assertSame( '11.1', $unmet[0]['required'] );
	}

	/**
	 * A version exactly at the floor satisfies the requirement.
	 *
	 * @return void
	 */
	public function test_version_at_the_floor_is_accepted(): void {
		$unmet = Requirements::evaluate(
			array(
				'php'         => '8.2',
				'wordpress'   => '7.1',
				'woocommerce' => '11.1',
			),
			$this->required
		);

		self::assertSame( array(), $unmet );
	}

	/**
	 * Every unmet requirement is reported, not only the first one.
	 *
	 * @return void
	 */
	public function test_every_unmet_requirement_is_reported(): void {
		$unmet = Requirements::evaluate(
			array(
				'php'         => '7.4',
				'wordpress'   => '6.0',
				'woocommerce' => '',
			),
			$this->required
		);

		self::assertCount( 3, $unmet );
		self::assertSame(
			array( 'php', 'wordpress', 'woocommerce' ),
			array_column( $unmet, 'key' )
		);
	}

	/**
	 * The described sentence names the component and the required version.
	 *
	 * @return void
	 */
	public function test_describe_names_the_component_and_the_version(): void {
		$unmet = Requirements::evaluate( array( 'woocommerce' => '9.0.0' ), array( 'woocommerce' => '11.1' ) );

		$sentence = Requirements::describe( $unmet[0] );

		self::assertStringContainsString( 'WooCommerce', $sentence );
		self::assertStringContainsString( '11.1', $sentence );
		self::assertStringContainsString( '9.0.0', $sentence );
	}
}
