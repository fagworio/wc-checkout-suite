<?php
/**
 * Constant declarations for static analysis only.
 *
 * PHPStan analyses `src/` on its own, so the constants the plugin bootstrap
 * defines are invisible to it. This file declares them instead of loading the
 * real bootstrap, which would run `register_activation_hook()` and other
 * WordPress functions that do not exist during analysis.
 *
 * The values are irrelevant to type checking; only the names matter. That makes
 * this file self-policing: renaming a constant in wc-checkoutsuite.php without
 * renaming it here produces an "unknown constant" error rather than silence.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

define( 'WCCS_VERSION', '0.1.0' );
define( 'WCCS_PLUGIN_FILE', __DIR__ . '/../wc-checkoutsuite.php' );
define( 'WCCS_PLUGIN_DIR', __DIR__ . '/../' );
define( 'WCCS_PLUGIN_URL', 'https://example.test/wp-content/plugins/wc-checkout-suite/' );
define( 'WCCS_PLUGIN_BASENAME', 'wc-checkout-suite/wc-checkoutsuite.php' );

define( 'WCCS_TEXT_DOMAIN', 'wc-checkoutsuite' );
define( 'WCCS_REST_NAMESPACE', 'wc-checkoutsuite/v1' );
define( 'WCCS_FIELD_ID_NAMESPACE', 'wc-checkoutsuite' );

define( 'WCCS_META_FIELDS', '_wccs_fields' );
define( 'WCCS_META_SCHEMA_REVISION', '_wccs_schema_revision' );
define( 'WCCS_OPTION_REQUIREMENTS', '_wccs_requirements_unmet' );
define( 'WCCS_UPLOADS_TABLE', 'wccs_uploads' );

define( 'WCCS_MIN_PHP', '8.2' );
define( 'WCCS_MIN_WP', '7.1' );
define( 'WCCS_MIN_WC', '11.1' );
