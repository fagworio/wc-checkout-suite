<?php
/**
 * Contrast mathematics tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Support\Contrast;

/**
 * Covers the WCAG maths the design tokens are verified against.
 *
 * If this maths is wrong, every contrast assertion elsewhere is worthless, so
 * it is pinned against the values WCAG defines outright.
 */
final class ContrastTest extends TestCase {

	/**
	 * Black on white is the maximum ratio WCAG defines.
	 *
	 * @return void
	 */
	public function test_black_on_white_is_the_maximum_ratio(): void {
		self::assertSame( 21.0, Contrast::ratio( '#000000', '#ffffff' ) );
	}

	/**
	 * A colour against itself has no contrast.
	 *
	 * @return void
	 */
	public function test_a_colour_against_itself_has_no_contrast(): void {
		self::assertSame( 1.0, Contrast::ratio( '#6d28d9', '#6d28d9' ) );
	}

	/**
	 * The ratio is symmetric.
	 *
	 * @return void
	 */
	public function test_ratio_is_symmetric(): void {
		self::assertSame(
			Contrast::ratio( '#202334', '#f6f7fb' ),
			Contrast::ratio( '#f6f7fb', '#202334' )
		);
	}

	/**
	 * A known project pair matches the value measured during design review.
	 *
	 * @return void
	 */
	public function test_known_project_pair_matches_the_measured_value(): void {
		self::assertSame( 7.1, Contrast::ratio( '#6d28d9', '#ffffff' ) );
		self::assertSame( 5.43, Contrast::ratio( '#626a7b', '#ffffff' ) );
	}

	/**
	 * Relative luminance of the extremes is exact.
	 *
	 * @return void
	 */
	public function test_relative_luminance_of_the_extremes(): void {
		self::assertSame( 0.0, Contrast::relative_luminance( '#000000' ) );
		self::assertSame( 1.0, Contrast::relative_luminance( '#ffffff' ) );
	}

	/**
	 * Only opaque six-digit hex is accepted.
	 *
	 * @return void
	 */
	public function test_only_opaque_six_digit_hex_is_accepted(): void {
		self::assertTrue( Contrast::is_hex( '#6d28d9' ) );
		self::assertTrue( Contrast::is_hex( '#FFFFFF' ) );

		self::assertFalse( Contrast::is_hex( '#fff' ), 'short hex has no unambiguous expansion here' );
		self::assertFalse( Contrast::is_hex( '#6d28d9ff' ), 'a translucent colour has no single ratio' );
		self::assertFalse( Contrast::is_hex( 'rgb(109,40,217)' ) );
		self::assertFalse( Contrast::is_hex( 'rebeccapurple' ) );
	}

	/**
	 * An invalid colour is refused instead of silently producing a number.
	 *
	 * @return void
	 */
	public function test_invalid_colour_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Contrast::relative_luminance( 'not-a-colour' );
	}

	/**
	 * The threshold helper agrees with the ratio.
	 *
	 * @return void
	 */
	public function test_threshold_helper_agrees_with_the_ratio(): void {
		self::assertTrue( Contrast::passes( '#202334', '#ffffff', 4.5 ) );
		self::assertFalse( Contrast::passes( '#e5e7ef', '#ffffff', 3.0 ) );
	}
}
