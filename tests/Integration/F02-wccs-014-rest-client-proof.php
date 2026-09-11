<?php
/**
 * WCCS-014 proof harness — the REST bootstrap handed to the browser.
 *
 * Task:   WCCS-014 "Implementar client REST"
 * Phase:  F02
 * Accept: "Nonce, erro 403/409/422, retry seguro e estado não salvo tratados."
 *
 * The client's behaviour — nonce header, classification of 403/409/422, when a
 * request is repeated and when it is not, and the unsaved work guard — is proven
 * by the JavaScript suite under tests/js/api. This harness proves the other half
 * of the contract: that the server hands the browser a usable, correctly scoped
 * bootstrap, and that the route map it publishes matches the routes that are
 * really registered.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Admin\AdminMenu;
use WCCheckoutSuite\Admin\Assets;
use WCCheckoutSuite\Http\Admin\CatalogController;
use WCCheckoutSuite\Http\Admin\SchemaController;

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

/**
 * Reads a plugin file, returning an empty string when it is missing.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function wccs_proof_read( $relative ) {
	$path = WCCS_PLUGIN_DIR . $relative;

	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$wccs_root = rtrim( (string) WCCS_PLUGIN_DIR, '/' );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-014 proof — REST bootstrap for the admin client' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$wccs_admin  = (int) ( $wccs_admins[0] ?? 0 );
wp_set_current_user( $wccs_admin );

$wccs_data = Assets::bootstrap_data();

// ---------------------------------------------------------------------------
// 1. The bootstrap carries what the client needs.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Bootstrap payload' );

wccs_proof_check( 'The bootstrap exposes a REST section', isset( $wccs_data['rest'] ) && is_array( $wccs_data['rest'] ) );

$wccs_rest = $wccs_data['rest'] ?? array();

foreach ( array( 'root', 'namespace', 'nonce', 'routes' ) as $wccs_key ) {
	wccs_proof_check( "The REST section carries \"{$wccs_key}\"", ! empty( $wccs_rest[ $wccs_key ] ) );
}

wccs_proof_check(
	'The namespace matches the one the controller registers under',
	WCCS_REST_NAMESPACE === $wccs_rest['namespace'],
	'namespace=' . ( $wccs_rest['namespace'] ?? 'missing' )
);

wccs_proof_check(
	'The root is the site REST root, not a hardcoded path',
	false !== strpos( (string) $wccs_rest['root'], rest_url() ),
	'root=' . ( $wccs_rest['root'] ?? 'missing' )
);

wccs_proof_check(
	'The previous bootstrap keys are untouched',
	isset( $wccs_data['version'], $wccs_data['mountId'], $wccs_data['sections'] )
		&& AdminMenu::MOUNT_ID === $wccs_data['mountId']
);

// ---------------------------------------------------------------------------
// 2. The nonce is a real REST nonce for this user.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Nonce' );

$wccs_nonce = (string) ( $wccs_rest['nonce'] ?? '' );

wccs_proof_check(
	'The nonce is valid for wp_rest',
	$wccs_admin === wp_verify_nonce( $wccs_nonce, 'wp_rest' ),
	'user=' . $wccs_admin
);

wccs_proof_check(
	'A nonce from another action is rejected',
	false === wp_verify_nonce( wp_create_nonce( 'something-else' ), 'wp_rest' )
);

wccs_proof_note(
	'Nonce placement',
	'the payload is printed before the bundle; asserted in the WCCS-012 proof'
);

// ---------------------------------------------------------------------------
// 3. The published route map matches the registered routes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Route map' );

$wccs_published = (array) ( $wccs_rest['routes'] ?? array() );

// Every route the page receives has to come from a controller's own map. F03
// added a second controller for the picker catalogue, so the expected map is the
// union of both; what the assertion protects is unchanged, and still the thing
// that matters: the client is never handed a path a controller did not declare.
$wccs_expected = SchemaController::routes() + CatalogController::routes();

wccs_proof_check(
	'The bootstrap publishes the controller route maps verbatim',
	$wccs_published === $wccs_expected,
	implode( ', ', array_keys( $wccs_published ) )
);

$wccs_server = rest_get_server();
$wccs_routes = array_keys( $wccs_server->get_routes() );

$wccs_unregistered = array();

foreach ( $wccs_expected as $wccs_path ) {
	$wccs_full = '/' . WCCS_REST_NAMESPACE . $wccs_path;

	if ( ! in_array( $wccs_full, $wccs_routes, true ) ) {
		$wccs_unregistered[] = $wccs_full;
	}
}

wccs_proof_check(
	'Every published route is actually registered',
	array() === $wccs_unregistered,
	$wccs_unregistered ? 'unregistered: ' . implode( ', ', $wccs_unregistered ) : count( $wccs_expected ) . ' routes'
);

wccs_proof_note( 'Routes', implode( ', ', array_values( $wccs_expected ) ) );

// ---------------------------------------------------------------------------
// 4. The client refuses to act without authorisation before the payload matters.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Transport contract' );

$wccs_request = new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . SchemaController::ROUTE_DRAFT );
$wccs_request->set_header( 'X-WP-Nonce', $wccs_nonce );

$wccs_response = rest_do_request( $wccs_request );

wccs_proof_check(
	'A request carrying the bootstrap nonce is accepted',
	200 === $wccs_response->get_status(),
	'status=' . $wccs_response->get_status()
);

wp_set_current_user( 0 );

$wccs_anonymous = rest_do_request( new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . SchemaController::ROUTE_DRAFT ) );

wccs_proof_check(
	'Without a user the same route is refused, which is what the client surfaces as a 403',
	in_array( $wccs_anonymous->get_status(), array( 401, 403 ), true ),
	'status=' . $wccs_anonymous->get_status()
);

wp_set_current_user( $wccs_admin );

// ---------------------------------------------------------------------------
// 5. The client and its behaviour suite are wired into the project.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Client surface' );

$wccs_barrel = wccs_proof_read( 'resources/admin/app/api/index.js' );

wccs_proof_check(
	'The API barrel exports the client and its error types',
	false !== strpos( $wccs_barrel, 'createClient' )
		&& false !== strpos( $wccs_barrel, 'ApiError' )
		&& false !== strpos( $wccs_barrel, 'StaleResponseError' )
		&& false !== strpos( $wccs_barrel, 'useUnsavedChanges' )
);

$wccs_specs = array();

$wccs_directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wccs_root . '/tests/js' ) );

foreach ( $wccs_directory as $wccs_entry ) {
	if ( $wccs_entry->isFile() && str_ends_with( $wccs_entry->getFilename(), '.test.js' ) ) {
		$wccs_specs[] = $wccs_entry->getFilename();
	}
}

wccs_proof_check(
	'The client and the unsaved work guard have behaviour specs',
	in_array( 'client.test.js', $wccs_specs, true )
		&& in_array( 'useUnsavedChanges.test.js', $wccs_specs, true ),
	implode( ', ', $wccs_specs )
);

wccs_proof_note(
	'The client is not in the bundle yet',
	sprintf(
		'the shell does not import it, so webpack leaves it out: %d bytes. It enters the bundle when the editor that consumes it lands in F03.',
		strlen( wccs_proof_read( 'build/admin/index.js' ) )
	)
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
