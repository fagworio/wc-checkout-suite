<?php
/**
 * Plugin Name:       WC CheckoutSuite
 * Description:       Advanced checkout field management for WooCommerce: field types, Brazilian presets, masks, server-side validation, conditional logic, private uploads and order persistence for Classic Checkout, Checkout Blocks and HPOS.
 * Version:           1.0.0-rc.1
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * WC requires at least: 11.1
 * WC tested up to:   11.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-checkoutsuite
 * Domain Path:       /languages
 *
 * @package WCCheckoutSuite
 *
 * Notes on deliberately absent metadata:
 *
 * - Author, Author URI and Plugin URI are still omitted, and the licence is the one
 *   header that is not: a WordPress plugin has to be GPL-compatible to be installed at
 *   all, so GPL-2.0-or-later is the ecosystem's requirement rather than a commercial
 *   choice. The commercial side of WCCS-067 — who distributes it, under what price and
 *   with which update channel — is written down in
 *   docs/operations/licensing-and-distribution.md and stays a product decision that is
 *   not invented in a file header.
 *
 * - The `Requires Plugins` header is deliberately NOT used. It would hard-block
 *   activation without WooCommerce, but the acceptance for WCCS-006 requires
 *   activation to succeed safely without WooCommerce and explain the missing
 *   prerequisite through an admin notice instead. See ROADMAP.md section 20.
 *
 * Version floors above are PROVISIONAL. They mirror the only runtime that could
 * be verified locally (PHP 8.2.1, WordPress 7.1, WooCommerce 11.1.0) and are
 * ratified later by WCCS-010 (CI matrix) and WCCS-062 (functional matrix).
 */

defined( 'ABSPATH' ) || exit;

// Guard against double inclusion (e.g. a theme or another plugin requiring this file).
if ( defined( 'WCCS_VERSION' ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Identity constants. These are the single source of truth for the technical
 * identifiers confirmed in ADR/index: directory `wc-checkout-suite`, text domain
 * `wc-checkoutsuite`, REST namespace `wc-checkoutsuite/v1`.
 * Changing any of them must be a single edit here.
 * -----------------------------------------------------------------------------
 */
define( 'WCCS_VERSION', '1.0.0-rc.1' );
define( 'WCCS_PLUGIN_FILE', __FILE__ );
define( 'WCCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCCS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'WCCS_TEXT_DOMAIN', 'wc-checkoutsuite' );
define( 'WCCS_REST_NAMESPACE', 'wc-checkoutsuite/v1' );
define( 'WCCS_FIELD_ID_NAMESPACE', 'wc-checkoutsuite' );

// Storage keys. See ADR-0001 (storage authority per field origin).
define( 'WCCS_META_FIELDS', '_wccs_fields' );
define( 'WCCS_META_SCHEMA_REVISION', '_wccs_schema_revision' );
define( 'WCCS_OPTION_REQUIREMENTS', '_wccs_requirements_unmet' );
define( 'WCCS_UPLOADS_TABLE', 'wccs_uploads' );

// Provisional compatibility floor. See docs/compatibility.json.
define( 'WCCS_MIN_PHP', '8.2' );
define( 'WCCS_MIN_WP', '7.1' );
define( 'WCCS_MIN_WC', '11.1' );

/**
 * Shows a notice when the PHP runtime is too old to load the plugin.
 *
 * Kept as a plain function so that this file parses and behaves on runtimes
 * older than the supported floor.
 *
 * @return void
 */
function wccs_render_php_version_notice() {
	if ( ! function_exists( 'wp_admin_notice' ) ) {
		return;
	}

	wp_admin_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			esc_html__( 'WC CheckoutSuite requires PHP %1$s or higher. This server runs PHP %2$s, so the plugin is inactive.', 'wc-checkoutsuite' ),
			esc_html( WCCS_MIN_PHP ),
			esc_html( PHP_VERSION )
		),
		array(
			'type'        => 'error',
			'dismissible' => false,
		)
	);
}

if ( version_compare( PHP_VERSION, WCCS_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'wccs_render_php_version_notice' );
	return;
}

/**
 * Registers a PSR-4 autoloader for the plugin namespace.
 *
 * The released ZIP must work without the merchant running Composer
 * (ROADMAP.md section 18), so the runtime never depends on a generated
 * autoloader. When a Composer autoloader is present in development it is
 * loaded as well and takes precedence.
 *
 * @return void
 */
function wccs_register_autoloader() {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'WCCheckoutSuite\\';

			if ( 0 !== strncmp( $prefix, (string) $class_name, strlen( $prefix ) ) ) {
				return;
			}

			$relative = substr( (string) $class_name, strlen( $prefix ) );
			$path     = WCCS_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);

	$composer_autoloader = WCCS_PLUGIN_DIR . 'vendor/autoload.php';

	if ( is_readable( $composer_autoloader ) ) {
		require_once $composer_autoloader;
	}
}

wccs_register_autoloader();

/**
 * Loads the plugin translations.
 *
 * @return void
 */
function wccs_load_textdomain() {
	load_plugin_textdomain(
		WCCS_TEXT_DOMAIN,
		false,
		dirname( WCCS_PLUGIN_BASENAME ) . '/languages'
	);
}

add_action( 'init', 'wccs_load_textdomain' );

/*
 * Boot on `plugins_loaded` at priority 5, so modules can hook after the plugin
 * has decided whether its requirements are met.
 */
add_action( 'plugins_loaded', array( 'WCCheckoutSuite\\Plugin', 'boot' ), 5 );

/*
 * Activation must never block the site: when a requirement is missing the
 * plugin stays inactive and records why, so the admin notice can explain it.
 */
register_activation_hook( __FILE__, array( 'WCCheckoutSuite\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCCheckoutSuite\\Plugin', 'deactivate' ) );
