<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * The unit suite runs without WordPress and without WooCommerce. Classes under
 * `src/Domain`, `src/Support` and the value objects are deliberately free of
 * WordPress loading order, so they can be exercised at full speed. The few
 * WordPress functions they call for translation and JSON encoding are shimmed
 * here; behaviour that depends on real WordPress (e-mail validation, HTTP,
 * order CRUD, options) is covered by the integration proofs under
 * `tests/Integration`, which run against a live installation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

$wccs_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $wccs_autoloader ) ) {
	fwrite( STDERR, "Run `composer install` before the unit suite.\n" );
	exit( 1 );
}

require $wccs_autoloader;

/*
 * -----------------------------------------------------------------------------
 * Minimal WordPress shims.
 *
 * Only functions the domain actually calls are provided. A shim that silently
 * diverges from WordPress would make the suite lie, so each one documents what
 * it does and does nothing more.
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation shim: returns the source string unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping shim mirroring the real behaviour for plain strings.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * URL sanitising shim covering the schemes WordPress allows by default.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		return false === filter_var( $url, FILTER_VALIDATE_URL ) ? '' : $url;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * E-mail shim.
	 *
	 * Deliberately stricter than a bare filter by rejecting obviously invalid
	 * shapes, without pretending to reproduce WordPress exactly.
	 *
	 * @param string $email Address.
	 * @return string|false
	 */
	function is_email( $email ) {
		$email = trim( (string) $email );

		if ( '' === $email || strlen( $email ) > 320 || ! str_contains( $email, '@' ) ) {
			return false;
		}

		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encoding shim.
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @param int   $depth Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_json_file_decode' ) ) {
	/**
	 * JSON file decoding shim mirroring the WordPress helper.
	 *
	 * @param string               $filename    Absolute path.
	 * @param array<string, mixed> $options     Options.
	 * @return mixed Decoded value, or null when the file is missing or invalid.
	 */
	function wp_json_file_decode( $filename, $options = array() ) {
		if ( ! is_readable( $filename ) ) {
			return null;
		}

		$associative = ! empty( $options['associative'] );

		return json_decode( (string) file_get_contents( $filename ), $associative );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action shim: the unit suite does not dispatch hooks.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ) {
		unset( $hook_name, $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter shim: returns the value unchanged.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value.
	 * @param mixed  ...$args   Arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		unset( $hook_name, $args );

		return $value;
	}
}
