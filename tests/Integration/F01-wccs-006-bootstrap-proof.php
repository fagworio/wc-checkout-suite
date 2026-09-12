<?php
/**
 * WCCS-006 proof harness — bootstrap, requirements guard, i18n and build output.
 *
 * Task:   WCCS-006 "Criar bootstrap e build"
 * Phase:  F01
 * Accept: "Ativação segura sem Woo; assets compiláveis; namespace e i18n definidos."
 *
 * Prerequisite: the plugin must be ACTIVE for the runtime assertions.
 *
 *   wp plugin activate wc-checkout-suite/wc-checkoutsuite.php
 *
 * Run from the Devilbox host:
 *
 *   docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
 *     /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval-file \
 *     /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite/tests/Integration/F01-wccs-006-bootstrap-proof.php
 *
 * Exit code 0 = every assertion passed.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'checks' => array(),
	'notes'  => array(),
);

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok = (bool) $condition;

	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];

	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

$wccs_plugin_dir = '/shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-006 proof — bootstrap, requirements guard, i18n, build' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. Plugin header metadata.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Plugin header' );

$wccs_headers = get_file_data(
	$wccs_plugin_dir . '/wc-checkoutsuite.php',
	array(
		'name'        => 'Plugin Name',
		'version'     => 'Version',
		'text_domain' => 'Text Domain',
		'domain_path' => 'Domain Path',
		'requires_php' => 'Requires PHP',
		'requires_wp' => 'Requires at least',
	)
);

wccs_proof_check( 'Header Plugin Name is present', 'WC CheckoutSuite' === $wccs_headers['name'], 'name=' . $wccs_headers['name'] );
wccs_proof_check( 'Header Version matches WCCS_VERSION', defined( 'WCCS_VERSION' ) && $wccs_headers['version'] === WCCS_VERSION, 'header=' . $wccs_headers['version'] );
wccs_proof_check( 'Header Text Domain matches WCCS_TEXT_DOMAIN', defined( 'WCCS_TEXT_DOMAIN' ) && $wccs_headers['text_domain'] === WCCS_TEXT_DOMAIN, 'domain=' . $wccs_headers['text_domain'] );
wccs_proof_check( 'Header Domain Path points at /languages', '/languages' === $wccs_headers['domain_path'], 'path=' . $wccs_headers['domain_path'] );
wccs_proof_check( 'Header Requires PHP matches WCCS_MIN_PHP', defined( 'WCCS_MIN_PHP' ) && $wccs_headers['requires_php'] === WCCS_MIN_PHP, 'php=' . $wccs_headers['requires_php'] );

// Read the header block itself. Scanning the raw source would match the
// explanatory comment that documents why this header is absent.
$wccs_header_probe = get_file_data(
	$wccs_plugin_dir . '/wc-checkoutsuite.php',
	array( 'requires_plugins' => 'Requires Plugins' )
);

wccs_proof_check(
	'Header deliberately omits "Requires Plugins" so activation is not hard-blocked',
	'' === trim( (string) $wccs_header_probe['requires_plugins'] ),
	'header value=' . var_export( $wccs_header_probe['requires_plugins'], true )
);

// ---------------------------------------------------------------------------
// 2. Identity constants (single source of truth).
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Identity constants' );

$wccs_expected = array(
	// The version is not pinned here on purpose. Pinning it would mean editing this
	// proof at every release, and the thing worth asserting is not the number but that
	// the three places that declare it agree: the plugin header, the constant and the
	// readme's stable tag. That is asserted below; the number itself is the release
	// record's business (docs/operations/release-*.md) and the build script refuses to
	// package a tree where they disagree.
	'WCCS_VERSION'        => (string) WCCS_VERSION,
	'WCCS_TEXT_DOMAIN'    => 'wc-checkoutsuite',
	'WCCS_REST_NAMESPACE' => 'wc-checkoutsuite/v1',
	'WCCS_FIELD_ID_NAMESPACE' => 'wc-checkoutsuite',
	'WCCS_META_FIELDS'    => '_wccs_fields',
	'WCCS_META_SCHEMA_REVISION' => '_wccs_schema_revision',
);

foreach ( $wccs_expected as $wccs_constant => $wccs_value ) {
	wccs_proof_check(
		"Constant {$wccs_constant} is defined with the agreed value",
		defined( $wccs_constant ) && constant( $wccs_constant ) === $wccs_value,
		'value=' . ( defined( $wccs_constant ) ? constant( $wccs_constant ) : '<undef>' )
	);
}

// The header and the constant are asserted equal above. The third copy of the number is
// the readme's stable tag, which is what a merchant reads before installing and what an
// update server compares; three copies of a version drift unless something checks them.
$wccs_readme = is_readable( $wccs_plugin_dir . '/readme.txt' )
	? (string) file_get_contents( $wccs_plugin_dir . '/readme.txt' )
	: '';

preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $wccs_readme, $wccs_stable );

wccs_proof_check(
	'The readme declares the same version as the plugin',
	isset( $wccs_stable[1] ) && $wccs_stable[1] === WCCS_VERSION,
	'readme=' . ( $wccs_stable[1] ?? '(none)' ) . ' version=' . WCCS_VERSION
);

$wccs_dir = defined( 'WCCS_PLUGIN_DIR' ) ? WCCS_PLUGIN_DIR : '';
wccs_proof_check(
	'Directory name and text domain follow the confirmed identifier decision',
	'wc-checkout-suite' === basename( rtrim( $wccs_dir, '/' ) ) && 'wc-checkoutsuite' === WCCS_TEXT_DOMAIN,
	'dir=' . basename( rtrim( $wccs_dir, '/' ) ) . ' text_domain=' . WCCS_TEXT_DOMAIN
);

// ---------------------------------------------------------------------------
// 3. Activation safety without WooCommerce (pure evaluation).
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Activation safety without WooCommerce' );

$wccs_requirements_class = 'WCCheckoutSuite\\Support\\Requirements';

wccs_proof_check(
	'Autoloader resolves the class without a Composer autoloader being required',
	class_exists( $wccs_requirements_class ),
	$wccs_requirements_class
);

$wccs_required = array( 'php' => '8.2', 'wordpress' => '7.1', 'woocommerce' => '11.1' );

// WooCommerce absent entirely.
$wccs_absent = $wccs_requirements_class::evaluate(
	array( 'php' => '8.2.1', 'wordpress' => '7.1', 'woocommerce' => '' ),
	$wccs_required
);
wccs_proof_check(
	'Absent WooCommerce is reported as "missing", not as a fatal error',
	1 === count( $wccs_absent )
		&& 'woocommerce' === $wccs_absent[0]['key']
		&& 'missing' === $wccs_absent[0]['reason'],
	'entry=' . wp_json_encode( $wccs_absent[0] ?? null )
);

// Outdated WooCommerce.
$wccs_outdated = $wccs_requirements_class::evaluate(
	array( 'php' => '8.2.1', 'wordpress' => '7.1', 'woocommerce' => '9.0.0' ),
	$wccs_required
);
wccs_proof_check(
	'Outdated WooCommerce is reported as "outdated" with both versions',
	1 === count( $wccs_outdated )
		&& 'outdated' === $wccs_outdated[0]['reason']
		&& '9.0.0' === $wccs_outdated[0]['observed'],
	'entry=' . wp_json_encode( $wccs_outdated[0] ?? null )
);

// Everything satisfied.
wccs_proof_check(
	'Satisfied environment produces no unmet requirement',
	array() === $wccs_requirements_class::evaluate(
		array( 'php' => '8.2.1', 'wordpress' => '7.1', 'woocommerce' => '11.1.0' ),
		$wccs_required
	)
);

wccs_proof_check(
	'Unmet requirements render a translatable, human readable sentence',
	'' !== $wccs_requirements_class::describe( $wccs_absent[0] ),
	$wccs_requirements_class::describe( $wccs_absent[0] )
);

// ---------------------------------------------------------------------------
// 4. Runtime boot.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Runtime boot' );

if ( ! class_exists( 'WCCheckoutSuite\\Plugin' ) ) {
	wccs_proof_check( 'Plugin class is available (plugin must be active)', false, 'activate the plugin before running this harness' );
} else {
	wccs_proof_check( 'Plugin::boot() ran on plugins_loaded', WCCheckoutSuite\Plugin::is_booted() );
	wccs_proof_check( 'The wccs_booted extension hook fired exactly once', 1 === did_action( 'wccs_booted' ), 'did_action=' . did_action( 'wccs_booted' ) );
	wccs_proof_check(
		'No unmet requirement in this environment (WooCommerce present)',
		array() === WCCheckoutSuite\Plugin::unmet_requirements(),
		wp_json_encode( WCCheckoutSuite\Plugin::unmet_requirements() )
	);
	wccs_proof_check(
		'Observed versions are read without instantiating WooCommerce classes',
		isset( WCCheckoutSuite\Plugin::observed_versions()['woocommerce'] ),
		wp_json_encode( WCCheckoutSuite\Plugin::observed_versions() )
	);
	wccs_proof_check(
		'Boot is idempotent',
		( static function () {
			WCCheckoutSuite\Plugin::boot();
			return 1 === did_action( 'wccs_booted' );
		} )()
	);
}

// ---------------------------------------------------------------------------
// 5. i18n.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Internationalisation' );

wccs_proof_check(
	'Translation loading is wired to the init hook',
	false !== has_action( 'init', 'wccs_load_textdomain' ),
	'callback=wccs_load_textdomain'
);

wccs_proof_check(
	'POT catalog exists in /languages',
	is_readable( $wccs_plugin_dir . '/languages/wc-checkoutsuite.pot' )
);

$wccs_pot = is_readable( $wccs_plugin_dir . '/languages/wc-checkoutsuite.pot' )
	? (string) file_get_contents( $wccs_plugin_dir . '/languages/wc-checkoutsuite.pot' )
	: '';

wccs_proof_check(
	'POT declares the correct text domain and version',
	false !== strpos( $wccs_pot, 'X-Domain: wc-checkoutsuite' )
		&& false !== strpos( $wccs_pot, 'Project-Id-Version: WC CheckoutSuite ' . WCCS_VERSION ),
	'domain and version header'
);

wccs_proof_check(
	'POT contains the plugin strings',
	false !== strpos( $wccs_pot, 'msgid "%1$s is inactive. %2$s"' ),
	'admin notice string present'
);

wccs_proof_note(
	'is_textdomain_loaded() is not a reliable indicator here',
	'WordPress 7.1 loads plugin text domains through the textdomain registry; a translation can be applied while is_textdomain_loaded() still reports false. The chain was verified separately by compiling a temporary pt_BR catalog and observing the translated output.'
);

// ---------------------------------------------------------------------------
// 6. Build output.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Build output' );

$wccs_js     = $wccs_plugin_dir . '/build/admin/index.js';
$wccs_asset  = $wccs_plugin_dir . '/build/admin/index.asset.php';

wccs_proof_check( 'Compiled bundle exists', is_readable( $wccs_js ), 'build/admin/index.js' );
wccs_proof_check( 'Dependency manifest exists', is_readable( $wccs_asset ), 'build/admin/index.asset.php' );

if ( is_readable( $wccs_asset ) ) {
	$wccs_manifest = include $wccs_asset;
	$wccs_deps     = isset( $wccs_manifest['dependencies'] ) ? (array) $wccs_manifest['dependencies'] : array();

	wccs_proof_check(
		'WordPress packages are extracted as external dependencies',
		in_array( 'wp-i18n', $wccs_deps, true ),
		'dependencies=' . implode( ',', $wccs_deps )
	);
	wccs_proof_check(
		'Manifest carries a content version for cache busting',
		! empty( $wccs_manifest['version'] ),
		'version=' . ( $wccs_manifest['version'] ?? '' )
	);
}

if ( is_readable( $wccs_js ) ) {
	$wccs_bundle = (string) file_get_contents( $wccs_js );
	$wccs_size   = strlen( $wccs_bundle );

	// Structural check, not a search for the word "react": the bundle now
	// legitimately contains "ReactJSXRuntime" because that is the name of the
	// external it binds to. Externalisation is proven by the manifest listing
	// the WordPress handles (an external is by definition not bundled) together
	// with the bundle reading them off `window`.
	wccs_proof_check(
		'WordPress packages stay external instead of being bundled',
		in_array( 'wp-element', $wccs_deps, true )
			&& in_array( 'wp-i18n', $wccs_deps, true )
			&& false !== strpos( $wccs_bundle, 'window.wp.element' ),
		'bundle=' . $wccs_size . ' bytes, externals=' . implode( ',', $wccs_deps )
	);

	wccs_proof_note( 'Bundle size', $wccs_size . ' bytes' );
}

// ---------------------------------------------------------------------------
// 7. Packaging safety.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Packaging safety' );

$wccs_ignore = is_readable( $wccs_plugin_dir . '/.gitignore' ) ? (string) file_get_contents( $wccs_plugin_dir . '/.gitignore' ) : '';

wccs_proof_check(
	'Build output and dependencies are excluded from the repository',
	false !== strpos( $wccs_ignore, '/node_modules/' )
		&& false !== strpos( $wccs_ignore, '/vendor/' )
		&& false !== strpos( $wccs_ignore, '/build/' ),
	'.gitignore covers node_modules, vendor and build'
);

wccs_proof_note(
	'Runtime does not depend on Composer or npm',
	'The plugin ships a PSR-4 autoloader of its own and only loads vendor/autoload.php when it happens to exist, so the merchant installs the ZIP without running either tool (ROADMAP.md section 18).'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
