<?php
/**
 * WCAG contrast mathematics.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Support;

/**
 * Computes WCAG 2.x contrast ratios between two opaque colours.
 *
 * Kept deliberately pure and dependency free so the design tokens can be
 * verified without WordPress, and so the same maths can be reused by an admin
 * diagnostic later.
 *
 * @see https://www.w3.org/TR/WCAG22/#contrast-minimum
 */
final class Contrast {

	/**
	 * Whether a string is a six-digit hexadecimal colour.
	 *
	 * Alpha is rejected on purpose: a translucent colour has no single contrast
	 * ratio, and accepting one would make the measurement meaningless.
	 *
	 * @param string $value Candidate colour.
	 * @return bool
	 */
	public static function is_hex( string $value ): bool {
		return 1 === preg_match( '/^#[0-9a-f]{6}$/i', $value );
	}

	/**
	 * Relative luminance of a colour, as defined by WCAG.
	 *
	 * @param string $hex Six-digit hexadecimal colour.
	 * @return float Luminance between 0 and 1.
	 * @throws \InvalidArgumentException When the colour is not a valid hex value.
	 */
	public static function relative_luminance( string $hex ): float {
		if ( ! self::is_hex( $hex ) ) {
			throw new \InvalidArgumentException(
				sprintf( '"%s" is not a six-digit hexadecimal colour.', esc_html( $hex ) )
			);
		}

		$value = ltrim( $hex, '#' );

		$channels = array(
			hexdec( substr( $value, 0, 2 ) ) / 255,
			hexdec( substr( $value, 2, 2 ) ) / 255,
			hexdec( substr( $value, 4, 2 ) ) / 255,
		);

		$linear = array_map( array( self::class, 'linearize' ), $channels );

		return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
	}

	/**
	 * Contrast ratio between two colours.
	 *
	 * The result is symmetric and ranges from 1.0 (identical) to 21.0
	 * (black against white).
	 *
	 * @param string $first  Six-digit hexadecimal colour.
	 * @param string $second Six-digit hexadecimal colour.
	 * @return float Ratio, rounded to two decimals.
	 */
	public static function ratio( string $first, string $second ): float {
		$luminance_a = self::relative_luminance( $first );
		$luminance_b = self::relative_luminance( $second );

		$lighter = max( $luminance_a, $luminance_b );
		$darker  = min( $luminance_a, $luminance_b );

		return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 2 );
	}

	/**
	 * Whether a pair satisfies a threshold.
	 *
	 * @param string $foreground Foreground colour.
	 * @param string $background Background colour.
	 * @param float  $minimum    Required ratio.
	 * @return bool
	 */
	public static function passes( string $foreground, string $background, float $minimum ): bool {
		return self::ratio( $foreground, $background ) >= $minimum;
	}

	/**
	 * Applies the WCAG transfer function to one colour channel.
	 *
	 * @param float $channel Channel between 0 and 1.
	 * @return float
	 */
	private static function linearize( float $channel ): float {
		if ( $channel <= 0.03928 ) {
			return $channel / 12.92;
		}

		return ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
	}
}
