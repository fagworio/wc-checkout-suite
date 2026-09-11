<?php
/**
 * WCCS-010 proof harness — the quality gates are wired to real files, and the
 * documented example extension actually works.
 *
 * Task:   WCCS-010 "Ativar CI desde o início"
 * Phase:  F01
 * Accept: "PHPCS, análise estática, lint, typecheck e testes mínimos obrigatórios no merge."
 *
 * The gates themselves are executed by `composer check` and by the npm scripts;
 * this harness covers the part a green run cannot prove: that every
 * configuration file points at something that exists, so the gate cannot rot
 * into a silent no-op.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Registries;

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

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

$wccs_root = defined( 'WCCS_PLUGIN_DIR' ) ? rtrim( (string) WCCS_PLUGIN_DIR, '/' ) : '';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-010 proof — CI wiring and the example extension' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. Every gate has a configuration file.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Gate configuration' );

$wccs_configs = array(
	'Coding standards'   => 'phpcs.xml.dist',
	'Static analysis'    => 'phpstan.neon.dist',
	'Unit tests'         => 'phpunit.xml.dist',
	'Type check'         => 'tsconfig.json',
	'CI workflow'        => '.github/workflows/ci.yml',
);

foreach ( $wccs_configs as $wccs_gate => $wccs_config ) {
	wccs_proof_check( "{$wccs_gate} is configured", is_readable( $wccs_root . '/' . $wccs_config ), $wccs_config );
}

// ---------------------------------------------------------------------------
// 2. The configuration points at files that exist.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Configuration points at real files' );

$wccs_phpcs = simplexml_load_file( $wccs_root . '/phpcs.xml.dist' );

wccs_proof_check( 'The coding standard ruleset parses', false !== $wccs_phpcs );

if ( false !== $wccs_phpcs ) {
	$wccs_missing_paths = array();

	foreach ( $wccs_phpcs->file as $wccs_file ) {
		$wccs_path = (string) $wccs_file;

		if ( ! file_exists( $wccs_root . '/' . rtrim( $wccs_path, '/' ) ) ) {
			$wccs_missing_paths[] = $wccs_path;
		}
	}

	wccs_proof_check(
		'Every path the ruleset checks exists',
		array() === $wccs_missing_paths,
		$wccs_missing_paths ? 'missing: ' . implode( ', ', $wccs_missing_paths ) : 'all present'
	);
}

$wccs_stub_files = array(
	'vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
	'vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php',
	'vendor/php-stubs/woocommerce-stubs/woocommerce-packages-stubs.php',
	'vendor/szepeviktor/phpstan-wordpress/extension.neon',
);

foreach ( $wccs_stub_files as $wccs_stub ) {
	wccs_proof_check( 'Static analysis dependency present', is_readable( $wccs_root . '/' . $wccs_stub ), $wccs_stub );
}

wccs_proof_check(
	'The unit suite bootstrap exists',
	is_readable( $wccs_root . '/tests/bootstrap-unit.php' )
);

$wccs_recursive = array();

$wccs_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wccs_root . '/tests/Unit' ) );

foreach ( $wccs_iterator as $wccs_entry ) {
	if ( $wccs_entry->isFile() && str_ends_with( $wccs_entry->getFilename(), 'Test.php' ) ) {
		$wccs_recursive[] = $wccs_entry->getPathname();
	}
}

wccs_proof_check(
	'The unit suite has test classes to run',
	count( $wccs_recursive ) >= 5,
	count( $wccs_recursive ) . ' test classes'
);

wccs_proof_note( 'Unit test classes', implode( ', ', array_map( 'basename', $wccs_recursive ) ) );

// ---------------------------------------------------------------------------
// 3. The documented example extension works from the outside.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Example extension' );

$wccs_example = $wccs_root . '/examples/custom-field-type';

wccs_proof_check( 'The example plugin entry file exists', is_readable( $wccs_example . '/wccs-example-field-type.php' ) );
wccs_proof_check( 'The example type class exists', is_readable( $wccs_example . '/src/MembershipCodeType.php' ) );

require_once $wccs_example . '/src/MembershipCodeType.php';

$wccs_types = Registries::instance()->types();

// plugins_loaded already passed inside wp eval-file, so the documented hook is
// fired again to exercise the external path. The registry rejects duplicates,
// so core content cannot be replaced.
add_action( 'wccs_register_field_types', array( 'WCCheckoutSuiteExample\\MembershipCodeType', 'register_field_types' ) );
do_action( 'wccs_register_field_types', $wccs_types );

$wccs_example_type = $wccs_types->type( 'example.membership-code' );

wccs_proof_check(
	'The example registers a type through the public hook',
	null !== $wccs_example_type,
	'key=example.membership-code'
);
wccs_proof_check(
	'The example origin is recorded',
	'wccs-example' === $wccs_types->source_of( 'example.membership-code' ),
	'source=' . $wccs_types->source_of( 'example.membership-code' )
);

$wccs_context = new FieldContext( array(), 'classic', array( 'prefix' => 'WCCS-' ) );

wccs_proof_check(
	'The example normalizes with the core contract',
	'WCCS-42' === $wccs_example_type->normalize( ' wccs-42 ', $wccs_context )
);
wccs_proof_check(
	'The example validates server side using its own settings',
	in_array( 'missing_prefix', $wccs_example_type->validate( 'XX-1', $wccs_context )->error_codes(), true )
);
wccs_proof_check(
	'The example accepts a well formed value',
	$wccs_example_type->validate( 'WCCS-42', $wccs_context )->is_valid()
);
wccs_proof_check(
	'A definition using the example type validates without touching the core',
	Registries::instance()->definition_validator()->validate_array(
		array(
			'id'       => 'membership_code',
			'type'     => 'example.membership-code',
			'label'    => 'Membership code',
			'settings' => array( 'prefix' => 'WCCS-' ),
		)
	)->is_valid()
);
wccs_proof_check(
	'The example claims no renderer, so the capability query stays honest',
	! Registries::instance()->renderers()->supports( 'example.membership-code', 'classic' )
);

// ---------------------------------------------------------------------------
// 4. Gates that must stay green.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Gates' );

$wccs_composer = is_readable( $wccs_root . '/composer.json' ) ? json_decode( (string) file_get_contents( $wccs_root . '/composer.json' ), true ) : null;
$wccs_package  = is_readable( $wccs_root . '/package.json' ) ? json_decode( (string) file_get_contents( $wccs_root . '/package.json' ), true ) : null;

wccs_proof_check(
	'The PHP gates are exposed as composer scripts',
	is_array( $wccs_composer )
		&& isset( $wccs_composer['scripts']['lint'], $wccs_composer['scripts']['analyse'], $wccs_composer['scripts']['test'], $wccs_composer['scripts']['check'] )
);
wccs_proof_check(
	'The JavaScript gates are exposed as npm scripts',
	is_array( $wccs_package )
		&& isset( $wccs_package['scripts']['build'], $wccs_package['scripts']['lint:js'], $wccs_package['scripts']['check-types'] )
);
wccs_proof_note(
	'The gates are executed by the CI workflow',
	'jobs: php-quality, php-unit, frontend'
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
