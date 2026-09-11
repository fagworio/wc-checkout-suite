<?php
/**
 * Order field value tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Orders;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Orders\OrderFieldValues;

/**
 * Covers the typed value set and the authority rule that fills it.
 *
 * The acceptance says "valores tipados, zeros e false preservados". Every test
 * below that looks pedantic is one of the four ways that promise is normally lost:
 * casting to string turns `false` into `''` and `0` into `'0'`, an `empty()` check
 * discards both plus `[]`, and a truthiness test cannot tell `false` from absent.
 * `assertSame` is used throughout for exactly that reason — `assertEquals` would
 * pass on `0 == '0'` and prove nothing.
 */
final class OrderFieldValuesTest extends TestCase {

	/**
	 * Builds one raw definition.
	 *
	 * @param string               $id      Identifier.
	 * @param array<string, mixed> $changes Values to override.
	 * @return array<string, mixed>
	 */
	private function definition( string $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'     => $id,
				'type'   => 'text',
				'label'  => 'Label ' . $id,
				'origin' => 'custom',
			),
			$changes
		);
	}

	/**
	 * Every value a field can hold survives the round trip with its type intact.
	 *
	 * @return void
	 */
	public function test_every_representable_value_keeps_its_type(): void {
		$values = array(
			'wccs_text'     => 'abc',
			'wccs_zero_str' => '0',
			'wccs_empty'    => '',
			'wccs_zero'     => 0,
			'wccs_float'    => 0.0,
			'wccs_decimal'  => 1.5,
			'wccs_false'    => false,
			'wccs_true'     => true,
			'wccs_null'     => null,
			'wccs_emptyarr' => array(),
			'wccs_list'     => array( 'a', 'b' ),
			'wccs_nums'     => array( 0, 1 ),
		);

		$set   = OrderFieldValues::from_array( $values );
		$again = OrderFieldValues::from_array( $set->all() );

		foreach ( $values as $id => $value ) {
			$this->assertTrue( $again->has( $id ), $id . ' is present' );
			$this->assertSame( $value, $again->get( $id ), $id . ' keeps its value and type' );
		}
	}

	/**
	 * Presence is not truthiness.
	 *
	 * @return void
	 */
	public function test_false_and_zero_are_present_values(): void {
		$set = OrderFieldValues::from_array(
			array(
				'wccs_false' => false,
				'wccs_zero'  => 0,
				'wccs_empty' => '',
				'wccs_list'  => array(),
			)
		);

		$this->assertTrue( $set->has( 'wccs_false' ) );
		$this->assertTrue( $set->has( 'wccs_zero' ) );
		$this->assertTrue( $set->has( 'wccs_empty' ) );
		$this->assertTrue( $set->has( 'wccs_list' ) );
		$this->assertSame( false, $set->get( 'wccs_false' ) );
		$this->assertSame( 0, $set->get( 'wccs_zero' ) );
	}

	/**
	 * A set of only falsy values is not empty.
	 *
	 * @return void
	 */
	public function test_a_set_of_falsy_values_is_not_empty(): void {
		$set = OrderFieldValues::from_array(
			array(
				'wccs_false' => false,
				'wccs_zero'  => 0,
			)
		);

		$this->assertFalse( $set->is_empty() );
		$this->assertSame( array( 'wccs_false', 'wccs_zero' ), $set->ids() );
	}

	/**
	 * Absent is not the same as present and null.
	 *
	 * @return void
	 */
	public function test_absent_differs_from_a_null_value(): void {
		$set = OrderFieldValues::from_array( array( 'wccs_null' => null ) );

		$this->assertTrue( $set->has( 'wccs_null' ) );
		$this->assertFalse( $set->has( 'wccs_missing' ) );
		$this->assertNull( $set->get( 'wccs_null', 'fallback' ) );
		$this->assertSame( 'fallback', $set->get( 'wccs_missing', 'fallback' ) );
	}

	/**
	 * A value a field cannot hold is left out rather than converted.
	 *
	 * A type that returns an object has broken the value contract; storing
	 * `"Object"` or an empty string instead would be a different answer.
	 *
	 * @return void
	 */
	public function test_a_value_a_field_cannot_hold_is_not_stored(): void {
		$set = OrderFieldValues::from_array(
			array(
				'wccs_ok'      => 'fine',
				'wccs_object'  => new \stdClass(),
				'wccs_nested'  => array( 'ok', new \stdClass() ),
				''             => 'no identifier',
				'wccs_closure' => static function (): void {},
			)
		);

		$this->assertSame( array( 'wccs_ok' ), $set->ids() );
	}

	/**
	 * Only the fields the Suite owns are kept.
	 *
	 * @return void
	 */
	public function test_only_custom_fields_are_kept(): void {
		$set = OrderFieldValues::from_definitions(
			array(
				'wccs_mine'          => 'kept',
				'billing_first_name' => 'not ours',
				'wccs_disabled'      => 'not rendered',
				'wccs_unknown'       => 'not in the schema',
			),
			array(
				$this->definition( 'wccs_mine' ),
				$this->definition( 'billing_first_name', array( 'origin' => 'core' ) ),
				$this->definition( 'wccs_disabled', array( 'enabled' => false ) ),
			)
		);

		$this->assertSame( array( 'wccs_mine' ), $set->ids() );
	}

	/**
	 * A field WooCommerce owns is never copied into the Suite's storage.
	 *
	 * This is the authority rule in ADR-0001. The value exists in the input and
	 * the definition exists in the document; the only thing that keeps it out is
	 * the origin.
	 *
	 * @return void
	 */
	public function test_a_core_field_is_never_kept_even_when_its_value_exists(): void {
		$set = OrderFieldValues::from_definitions(
			array( 'billing_phone' => '+5531999999999' ),
			array( $this->definition( 'billing_phone', array( 'origin' => 'core' ) ) )
		);

		$this->assertTrue( $set->is_empty() );
		$this->assertFalse( $set->has( 'billing_phone' ) );
	}

	/**
	 * A definition that is not an array does not break the walk.
	 *
	 * @return void
	 */
	public function test_a_malformed_definition_is_skipped(): void {
		$set = OrderFieldValues::from_definitions(
			array( 'wccs_mine' => 'kept' ),
			array( 'not a definition', $this->definition( 'wccs_mine' ) )
		);

		$this->assertSame( array( 'wccs_mine' ), $set->ids() );
	}

	/**
	 * An empty input gives an empty set, and it says so.
	 *
	 * @return void
	 */
	public function test_nothing_in_gives_nothing_out(): void {
		$this->assertTrue( OrderFieldValues::none()->is_empty() );
		$this->assertSame( array(), OrderFieldValues::none()->all() );

		$this->assertTrue(
			OrderFieldValues::from_definitions( array(), array( $this->definition( 'wccs_mine' ) ) )->is_empty()
		);

		$this->assertTrue(
			OrderFieldValues::from_definitions( array( 'wccs_mine' => 'x' ), array() )->is_empty()
		);
	}

	/**
	 * Falsy values survive being rebuilt from what a definition allows.
	 *
	 * @return void
	 */
	public function test_falsy_values_survive_the_authority_filter(): void {
		$set = OrderFieldValues::from_definitions(
			array(
				'wccs_false' => false,
				'wccs_zero'  => 0,
			),
			array(
				$this->definition( 'wccs_false' ),
				$this->definition( 'wccs_zero' ),
			)
		);

		$this->assertSame( false, $set->get( 'wccs_false' ) );
		$this->assertSame( 0, $set->get( 'wccs_zero' ) );
		$this->assertFalse( $set->is_empty() );
	}
}
