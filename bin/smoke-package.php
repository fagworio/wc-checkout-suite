<?php
/**
 * Runs the extracted package with nothing but PHP.
 *
 * This is the "instalação sem ferramentas de build" claim, checked the only way it can be
 * checked without a second WordPress: the package is extracted somewhere, and a plain PHP
 * process with no Composer autoloader and no WordPress is asked to load the plugin's main
 * file and to resolve every class it ships.
 *
 * The handful of WordPress functions the main file calls while it is being included are
 * shimmed here, and only those: `plugin_dir_path`, `plugin_dir_url`, `plugin_basename`,
 * the two hook registrars, the loader the text domain uses and `load_plugin_textdomain`.
 * Nothing else in the file runs at include time — the plugin boots on `plugins_loaded` —
 * so a class that resolves here resolves on a store, and a class that does not is a
 * package that would fatal the first time something asked for it.
 *
 * Usage:
 *   php bin/smoke-package.php /path/to/extracted/wc-checkout-suite
 *
 * Exit code 0 means the package loaded and every class resolved.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

$wccs_arguments = array_slice( $argv, 1 );

if ( array() === $wccs_arguments ) {
	fwrite( STDERR, "Usage: php bin/smoke-package.php <extracted-package-dir>\n" );
	exit( 2 );
}

$wccs_package = rtrim( $wccs_arguments[0], '/' );
$wccs_main    = $wccs_package . '/wc-checkoutsuite.php';

if ( ! is_readable( $wccs_main ) ) {
	fwrite( STDERR, "No main file in {$wccs_package}.\n" );
	exit( 2 );
}

$wccs_failures = array();

/*
 * The package must not carry a dependency resolver, because the store has none. Checked
 * before anything is loaded: a package that silently worked here because a leftover
 * vendor/ was inside it would be a package that fails on the store.
 */
foreach ( array( 'vendor', 'node_modules' ) as $wccs_forbidden ) {
	if ( is_dir( $wccs_package . '/' . $wccs_forbidden ) ) {
		$wccs_failures[] = "The package carries {$wccs_forbidden}/, so it was not built for a store without tooling.";
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $wccs_package . '/' );
}

/**
 * Stand-in for WordPress's plugin_dir_path().
 *
 * @param string $file File.
 * @return string
 */
function plugin_dir_path( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	return rtrim( dirname( (string) $file ), '/' ) . '/';
}

/**
 * Stand-in for WordPress's plugin_dir_url().
 *
 * @param string $file File.
 * @return string
 */
function plugin_dir_url( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	return 'http://example.invalid/plugins/' . basename( dirname( (string) $file ) ) . '/';
}

/**
 * Stand-in for WordPress's plugin_basename().
 *
 * @param string $file File.
 * @return string
 */
function plugin_basename( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

/**
 * Stand-in for add_action().
 *
 * @param string   $hook     Hook.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Accepted args.
 * @return void
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	unset( $hook, $callback, $priority, $args );
}

/**
 * Stand-in for add_filter().
 *
 * @param string   $hook     Hook.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Accepted args.
 * @return void
 */
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	unset( $hook, $callback, $priority, $args );
}

/**
 * Stand-in for register_activation_hook().
 *
 * @param string   $file     File.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	unset( $file, $callback );
}

/**
 * Stand-in for register_deactivation_hook().
 *
 * @param string   $file     File.
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	unset( $file, $callback );
}

/**
 * Stand-in for load_plugin_textdomain().
 *
 * @param string       $domain Domain.
 * @param bool         $abs    Absolute.
 * @param string|false $path   Path.
 * @return bool
 */
function load_plugin_textdomain( $domain, $abs = false, $path = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shimming WordPress on purpose.
	unset( $domain, $abs, $path );

	return true;
}

require $wccs_main;

if ( ! defined( 'WCCS_VERSION' ) ) {
	$wccs_failures[] = 'The package main file did not define WCCS_VERSION, so it did not load.';
}

$wccs_prefix = 'WCCheckoutSuite\\';

$wccs_classes = array();

$wccs_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $wccs_package . '/src', FilesystemIterator::SKIP_DOTS )
);

foreach ( $wccs_iterator as $wccs_item ) {
	if ( ! $wccs_item->isFile() || 'php' !== strtolower( $wccs_item->getExtension() ) ) {
		continue;
	}

	$wccs_relative = ltrim( str_replace( $wccs_package . '/src', '', $wccs_item->getPathname() ), '/' );
	$wccs_class    = $wccs_prefix . str_replace( '/', '\\', (string) preg_replace( '#\.php$#', '', $wccs_relative ) );

	$wccs_classes[] = $wccs_class;
}

sort( $wccs_classes );

$wccs_unresolved = array();

foreach ( $wccs_classes as $wccs_class ) {
	// class_exists() is what triggers the plugin's own autoloader; there is no Composer
	// autoloader in this process, and no vendor/ in the package to provide one.
	if ( ! class_exists( $wccs_class ) && ! interface_exists( $wccs_class ) && ! trait_exists( $wccs_class ) ) {
		$wccs_unresolved[] = $wccs_class;
	}
}

if ( array() !== $wccs_unresolved ) {
	$wccs_failures[] = 'Classes the package ships but cannot resolve: ' . implode( ', ', array_slice( $wccs_unresolved, 0, 5 ) )
		. ( count( $wccs_unresolved ) > 5 ? ' (and ' . ( count( $wccs_unresolved ) - 5 ) . ' more)' : '' );
}

echo "Package smoke: {$wccs_package}\n";
echo '  Version: ' . ( defined( 'WCCS_VERSION' ) ? WCCS_VERSION : '(none)' ) . "\n";
echo '  Classes resolved through the plugin\'s own autoloader: ' . ( count( $wccs_classes ) - count( $wccs_unresolved ) ) . '/' . count( $wccs_classes ) . "\n";
echo '  PHP: ' . PHP_VERSION . " (no Composer autoloader, no WordPress)\n";

if ( array() === $wccs_failures ) {
	echo "  PASS  The package loads and every class resolves with nothing but PHP.\n";
	exit( 0 );
}

foreach ( $wccs_failures as $wccs_failure ) {
	echo "  FAIL  {$wccs_failure}\n";
}

exit( 1 );
