<?php
/**
 * Absent-value semantics tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\AbstractFieldType;

/**
 * Covers the distinction the planning requires between absence, zero, false and
 * an empty list. Using `empty()` here would silently collapse all four.
 */
final class AbsentValueTest extends TestCase {

	/**
	 * Only null and the empty string count as absent.
	 *
	 * @return void
	 */
	public function test_only_null_and_empty_string_are_absent(): void {
		self::assertTrue( AbstractFieldType::is_absent( null ) );
		self::assertTrue( AbstractFieldType::is_absent( '' ) );

		self::assertFalse( AbstractFieldType::is_absent( 0 ), '0 is a real value' );
		self::assertFalse( AbstractFieldType::is_absent( '0' ), '"0" is a real value' );
		self::assertFalse( AbstractFieldType::is_absent( false ), 'false is a real value' );
		self::assertFalse( AbstractFieldType::is_absent( array() ), 'an empty list is a value, not absence' );
		self::assertFalse( AbstractFieldType::is_absent( ' ' ), 'whitespace is a value the normalizer handles' );
	}
}
