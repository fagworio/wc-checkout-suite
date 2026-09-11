<?php
/**
 * Schema validation tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\SchemaValidator;

/**
 * Covers the settings validator, including the mass-assignment guard.
 */
final class SchemaValidatorTest extends TestCase {

	/**
	 * A representative settings schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema = array(
		'maxLength' => array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 200,
		),
		'label'     => array(
			'type'      => 'string',
			'maxLength' => 10,
		),
		'mode'      => array(
			'type' => 'string',
			'enum' => array( 'a', 'b' ),
		),
		'options'   => array(
			'type'     => 'array',
			'required' => true,
			'maxItems' => 2,
		),
	);

	/**
	 * A conforming value map is accepted.
	 *
	 * @return void
	 */
	public function test_conforming_values_are_accepted(): void {
		$result = SchemaValidator::validate(
			array(
				'maxLength' => 20,
				'label'     => 'Document',
				'mode'      => 'a',
				'options'   => array( 'x' ),
			),
			$this->schema
		);

		self::assertTrue( $result->is_valid(), implode( ',', $result->error_codes() ) );
	}

	/**
	 * An undeclared setting is refused: this is the mass-assignment guard.
	 *
	 * @return void
	 */
	public function test_undeclared_setting_is_refused(): void {
		$result = SchemaValidator::validate(
			array(
				'nonsense' => 1,
				'options'  => array(),
			),
			$this->schema
		);

		self::assertContains( 'unknown_setting', $result->error_codes() );
	}

	/**
	 * A setting with the wrong type is refused.
	 *
	 * @return void
	 */
	public function test_wrong_type_is_refused(): void {
		$result = SchemaValidator::validate(
			array(
				'maxLength' => 'twenty',
				'options'   => array(),
			),
			$this->schema
		);

		self::assertContains( 'invalid_setting_type', $result->error_codes() );
	}

	/**
	 * A value outside the declared enum is refused.
	 *
	 * @return void
	 */
	public function test_value_outside_enum_is_refused(): void {
		$result = SchemaValidator::validate(
			array(
				'mode'    => 'z',
				'options' => array(),
			),
			$this->schema
		);

		self::assertContains( 'invalid_setting_value', $result->error_codes() );
	}

	/**
	 * Numeric bounds are enforced on both sides.
	 *
	 * @return void
	 */
	public function test_numeric_bounds_are_enforced(): void {
		$too_low  = SchemaValidator::validate(
			array(
				'maxLength' => 0,
				'options'   => array(),
			),
			$this->schema
		);
		$too_high = SchemaValidator::validate(
			array(
				'maxLength' => 500,
				'options'   => array(),
			),
			$this->schema
		);

		self::assertContains( 'setting_below_minimum', $too_low->error_codes() );
		self::assertContains( 'setting_above_maximum', $too_high->error_codes() );
	}

	/**
	 * String length bounds are enforced in both directions.
	 *
	 * @return void
	 */
	public function test_string_length_bounds_are_enforced(): void {
		$result = SchemaValidator::validate(
			array(
				'label'   => 'a-very-long-label',
				'options' => array(),
			),
			$this->schema
		);

		self::assertContains( 'setting_too_long', $result->error_codes() );
	}

	/**
	 * A required setting that is absent is refused.
	 *
	 * @return void
	 */
	public function test_missing_required_setting_is_refused(): void {
		$result = SchemaValidator::validate( array( 'maxLength' => 10 ), $this->schema );

		self::assertContains( 'missing_required_setting', $result->error_codes() );
	}

	/**
	 * Too many items in a list setting is refused.
	 *
	 * @return void
	 */
	public function test_too_many_items_is_refused(): void {
		$result = SchemaValidator::validate( array( 'options' => array( 'a', 'b', 'c' ) ), $this->schema );

		self::assertContains( 'setting_too_many_items', $result->error_codes() );
	}

	/**
	 * The declared type check distinguishes lists from maps.
	 *
	 * @return void
	 */
	public function test_array_and_object_types_are_distinguished(): void {
		self::assertTrue( SchemaValidator::matches_type( array( 'a', 'b' ), 'array' ) );
		self::assertFalse( SchemaValidator::matches_type( array( 'k' => 'v' ), 'array' ) );
		self::assertTrue( SchemaValidator::matches_type( array( 'k' => 'v' ), 'object' ) );
		self::assertFalse( SchemaValidator::matches_type( array( 'a', 'b' ), 'object' ) );
	}
}
