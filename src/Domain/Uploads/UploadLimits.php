<?php
/**
 * Upload limits exposed to the administrator.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/** Describes the PHP and WordPress ceilings an upload field cannot exceed. */
final class UploadLimits {

	/**
	 * Returns the effective PHP/WordPress upload ceiling in bytes.
	 *
	 * @return array<string, int|bool>
	 */
	public static function to_array(): array {
		$upload    = self::ini_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post      = self::ini_bytes( (string) ini_get( 'post_max_size' ) );
		$wordpress = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		$limits    = array_filter( array( $upload, $post, $wordpress ), static fn( int $value ): bool => $value > 0 );

		return array(
			'effectiveBytes'      => empty( $limits ) ? 0 : min( $limits ),
			'uploadMaxBytes'      => $upload,
			'postMaxBytes'        => $post,
			'wordpressMaxBytes'   => $wordpress,
			'webServerMayBeLower' => true,
		);
	}

	/**
	 * Convert PHP shorthand notation into bytes.
	 *
	 * @param string $value PHP ini value.
	 * @return int
	 */
	private static function ini_bytes( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		$number = (float) $value;
		$unit   = strtolower( substr( $value, -1 ) );

		if ( 'g' === $unit ) {
			$number *= 1024;
		}

		if ( 'g' === $unit || 'm' === $unit ) {
			$number *= 1024;
		}

		if ( 'g' === $unit || 'm' === $unit || 'k' === $unit ) {
			$number *= 1024;
		}

		return max( 0, (int) $number );
	}
}
