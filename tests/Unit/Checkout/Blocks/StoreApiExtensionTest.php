<?php
/**
 * Store API extension tests.
 *
 * The typed payload is the point of this class: a namespace whose properties have
 * the type of the value the component produces, so the route refuses a request of
 * the wrong shape before any of this plugin's code runs. A schema that says "string"
 * for every field would be a decoration.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Blocks;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Blocks\StoreApiExtension;

/**
 * The type mapping and the payload reading.
 */
final class StoreApiExtensionTest extends TestCase {

	/**
	 * The type declared for a field is the type of the value it produces.
	 *
	 * @return void
	 */
	public function test_the_declared_type_is_the_one_the_component_produces(): void {
		self::assertSame( 'string', StoreApiExtension::json_type( 'text' ) );
		self::assertSame( 'string', StoreApiExtension::json_type( 'textarea' ) );
		self::assertSame( 'string', StoreApiExtension::json_type( 'radio' ) );
		self::assertSame( 'string', StoreApiExtension::json_type( 'date' ) );
		self::assertSame( 'string', StoreApiExtension::json_type( 'time' ) );
		self::assertSame( 'string', StoreApiExtension::json_type( 'datetime' ) );
		self::assertSame( 'array', StoreApiExtension::json_type( 'multiselect' ) );
		self::assertSame( 'array', StoreApiExtension::json_type( 'checkbox-group' ) );
		self::assertSame( 'boolean', StoreApiExtension::json_type( 'checkbox' ) );
	}

	/**
	 * A request that did not send the namespace yields nothing, not everything.
	 *
	 * @return void
	 */
	public function test_a_request_without_the_namespace_submits_nothing(): void {
		self::assertSame( array(), StoreApiExtension::submitted( null ) );
		self::assertSame( array(), StoreApiExtension::submitted( 'not-a-request' ) );
		self::assertSame( array(), StoreApiExtension::submitted( $this->request( array() ) ) );
		self::assertSame(
			array(),
			StoreApiExtension::submitted( $this->request( array( 'other-plugin' => array( 'x' ) ) ) )
		);
	}

	/**
	 * The namespace's own payload is what comes back.
	 *
	 * @return void
	 */
	public function test_the_namespace_payload_is_read(): void {
		$submitted = StoreApiExtension::submitted(
			$this->request(
				array(
					StoreApiExtension::NAMESPACE_KEY => array( 'wccs_note' => 'hello' ),
				)
			)
		);

		self::assertSame( array( 'wccs_note' => 'hello' ), $submitted );
	}

	/**
	 * A payload that is not a map is ignored rather than guessed at.
	 *
	 * @return void
	 */
	public function test_a_payload_that_is_not_a_map_is_ignored(): void {
		self::assertSame(
			array(),
			StoreApiExtension::submitted(
				$this->request( array( StoreApiExtension::NAMESPACE_KEY => 'not-a-map' ) )
			)
		);
	}

	/**
	 * A request with a `get_param` method, which is all the reader needs.
	 *
	 * @param array<string, mixed> $extensions Extensions map.
	 * @return object Request stand-in.
	 */
	private function request( array $extensions ): object {
		return new class( $extensions ) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $extensions Extensions.
			 */
			public function __construct( private array $extensions ) {
			}

			/**
			 * Reads one parameter, the way the Store API request does.
			 *
			 * @param string $key Parameter.
			 * @return mixed
			 */
			public function get_param( string $key ): mixed {
				return 'extensions' === $key ? $this->extensions : null;
			}
		};
	}
}
