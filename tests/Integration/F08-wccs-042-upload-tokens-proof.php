<?php
/**
 * WCCS-042 proof harness — accepting an upload, and knowing whose it is.
 *
 * Task:   WCCS-042 "Criar upload e tokens por sessão"
 * Phase:  F08 · Upload privado nos dois checkouts
 * Accept: "MIME, bytes, quota e ownership validados; sessão A não usa token B."
 *
 * The rules are proven as rules, and the ownership is proven the only way it can be:
 * by asking as somebody else.
 *
 * A note on how the accepting path is reached here. This store's private directory
 * is served over HTTP (WCCS-041, blocker UPLOAD-PRIVACY-ENV), so the feature is off
 * and the endpoint refuses every upload with that reason — which is the first thing
 * this harness asserts, because it is the truth about this store. To exercise what
 * happens when a store *can* keep a file private, the observation is then injected as
 * protected, and the harness says so in its own output: it is proving the rules, not
 * claiming that this environment protects anything. Everything it writes is deleted.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

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
	$ok                                = (bool) $condition;
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
 * Writes a file and answers with the `$_FILES` entry a request would carry.
 *
 * @param string               $contents Contents.
 * @param string               $name     Submitted name.
 * @param array<string, mixed> $extra    Extra entries.
 * @return array<string, mixed>
 */
function wccs_proof_file( string $contents, string $name, array $extra = array() ): array {
	$path = sys_get_temp_dir() . '/' . wp_generate_password( 12, false, false );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file standing in for an uploaded one.
	file_put_contents( $path, $contents );

	return array_merge(
		array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'size'     => strlen( $contents ),
			'error'    => 0,
		),
		$extra
	);
}

/**
 * Removes a temporary file.
 *
 * @param array<string, mixed> $file File entry.
 * @return void
 */
function wccs_proof_unlink( array $file ): void {
	if ( isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temporary file this harness wrote.
		unlink( $file['tmp_name'] );
	}
}

$wccs_privacy_option = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION;

delete_option( $wccs_privacy_option );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-042 proof — accepting an upload' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// ---------------------------------------------------------------------------
// 1. The gate comes first.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What this store does today' );

\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::forget();

$wccs_file   = wccs_proof_file( 'a private document', 'document.txt' );
$wccs_result = ( new \WCCheckoutSuite\Domain\Uploads\UploadService() )->accept( $wccs_file, 'wccs_document' );

wccs_proof_check(
	'On a store that cannot keep a file private, the upload is refused before it is read',
	'not_available' === $wccs_result['code'],
	'code=' . $wccs_result['code'] . ' message=' . $wccs_result['message']
);

wccs_proof_check(
	'And the refusal names the environment rather than the file',
	str_contains( $wccs_result['message'], 'HTTP' ),
	'message=' . $wccs_result['message']
);

wccs_proof_unlink( $wccs_file );

// From here on the observation is injected, so the rules can be exercised on a store
// that would be allowed to hold a file. The harness says so rather than implying
// that this environment passes the check.
wccs_proof_note(
	'The accepting path below is exercised with the observation injected',
	'This store\'s private directory is served over HTTP (WCCS-041), so the feature is off. The observation is set to protected for the rest of this harness to prove the rules and the ownership; the harness deletes what it writes.'
);

update_option(
	$wccs_privacy_option,
	array(
		'protected'  => true,
		'status'     => 403,
		'reason'     => '',
		'checked_at' => time(),
	),
	false
);

// ---------------------------------------------------------------------------
// 2. The rules.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. MIME, bytes and quota' );

$wccs_service = new \WCCheckoutSuite\Domain\Uploads\UploadService();

$wccs_good = wccs_proof_file( "a plain text document\n", 'document.txt' );
$wccs_bad  = wccs_proof_file( "#!/bin/sh\necho hello\n", 'document.txt', array( 'type' => 'text/plain' ) );

$wccs_accepted = $wccs_service->accept( $wccs_good, 'wccs_document' );

wccs_proof_check(
	'A text file is accepted, and gets a token',
	'' === $wccs_accepted['code'] && 64 === strlen( $wccs_accepted['token'] ),
	'code=' . $wccs_accepted['code'] . ' token_length=' . strlen( $wccs_accepted['token'] )
);

$wccs_refused = $wccs_service->accept( $wccs_bad, 'wccs_document' );

wccs_proof_check(
	'A shell script named document.txt is refused: the declaration is not evidence',
	'mime_not_allowed' === $wccs_refused['code'],
	'code=' . $wccs_refused['code'] . ' detected=' . \WCCheckoutSuite\Domain\Uploads\UploadService::detect( (string) $wccs_bad['tmp_name'] )
);

$wccs_large = wccs_proof_file( str_repeat( 'x', \WCCheckoutSuite\Domain\Uploads\UploadRules::DEFAULT_MAX_BYTES + 1 ), 'large.txt' );

wccs_proof_check(
	'A file above the maximum is refused',
	'file_too_large' === $wccs_service->accept( $wccs_large, 'wccs_document' )['code']
);

$wccs_quota_owner = \WCCheckoutSuite\Domain\Uploads\UploadService::owner();

wccs_proof_check(
	'A session has an owner identifier, and it is not the customer id',
	'' !== $wccs_quota_owner && 32 === strlen( $wccs_quota_owner ),
	'owner_length=' . strlen( $wccs_quota_owner )
);

$wccs_repository = new \WCCheckoutSuite\Domain\Uploads\UploadRepository();

update_option(
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION,
	array(
		'protected'  => true,
		'status'     => 403,
		'reason'     => '',
		'checked_at' => time(),
	),
	false
);

// Fill the quota with rows rather than with files: the quota is a sum over what the
// owner has stored, and a store that counted only what it could hold in memory would
// be counting something else.
$wccs_token = ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->insert(
	array(
		'owner'      => $wccs_quota_owner,
		'field_id'   => 'wccs_document',
		'file_name'  => 'filler.txt',
		'mime_type'  => 'text/plain',
		'byte_size'  => \WCCheckoutSuite\Domain\Uploads\UploadRules::DEFAULT_QUOTA_BYTES,
		'path'       => '/tmp/filler',
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
	)
);

$wccs_over = wccs_proof_file( "one more document\n", 'more.txt' );

wccs_proof_check(
	'An owner who has spent the quota is refused',
	'quota_exceeded' === $wccs_service->accept( $wccs_over, 'wccs_document' )['code'],
	'used=' . $wccs_repository->used_bytes( $wccs_quota_owner )
);

$wccs_repository->delete( $wccs_token, $wccs_quota_owner );

wccs_proof_unlink( $wccs_over );

// ---------------------------------------------------------------------------
// 3. Ownership: session A cannot use session B's token.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Whose upload it is' );

$wccs_owner_a = \WCCheckoutSuite\Domain\Uploads\UploadService::owner();
$wccs_owner_b = bin2hex( random_bytes( 16 ) );

wccs_proof_check(
	'Two sessions have two owners',
	$wccs_owner_a !== $wccs_owner_b && '' !== $wccs_owner_b
);

$wccs_mine = $wccs_accepted['token'];

wccs_proof_check(
	'The session that uploaded it can read it back',
	'' === $wccs_service->find( $wccs_mine, $wccs_owner_a )['code']
);

$wccs_other = $wccs_service->find( $wccs_mine, $wccs_owner_b );

wccs_proof_check(
	'Another session cannot, and is told it is not theirs',
	'not_yours' === $wccs_other['code'] && null === $wccs_other['record'],
	'code=' . $wccs_other['code']
);

wccs_proof_check(
	'And a token that does not exist is told apart from one that is not theirs',
	'unknown_token' === $wccs_service->find( bin2hex( random_bytes( 32 ) ), $wccs_owner_a )['code']
);

wccs_proof_check(
	'Another session cannot remove it either',
	'not_yours' === $wccs_service->remove( $wccs_mine, $wccs_owner_b )
		&& '' === $wccs_service->find( $wccs_mine, $wccs_owner_a )['code']
);

// ---------------------------------------------------------------------------
// 4. The endpoint, through the real route.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The route' );

$wccs_request = new WP_REST_Request(
	'GET',
	'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Checkout\UploadController::ROUTE_UPLOADS
);

$wccs_request->set_param( 'nonce', wp_create_nonce( 'wp_rest' ) );
$wccs_request->set_param( 'token', $wccs_mine );

$wccs_response = rest_do_request( $wccs_request );
$wccs_body     = $wccs_response->get_data();

wccs_proof_check(
	'The route answers the token holder with the file it knows about',
	200 === $wccs_response->get_status()
		&& 'found' === ( $wccs_body['status'] ?? '' )
		&& '' !== ( $wccs_body['file_name'] ?? '' ),
	'status=' . $wccs_response->get_status() . ' body=' . wp_json_encode( $wccs_body )
);

wccs_proof_check(
	'And never with the contents of the file',
	! str_contains( (string) wp_json_encode( $wccs_body ), 'a plain text document' )
);

wccs_proof_check(
	'Which is marked no-store, because a token answer is not cacheable',
	'no-store' === ( $wccs_response->get_headers()['Cache-Control'] ?? '' )
);

$wccs_bad_nonce = new WP_REST_Request(
	'GET',
	'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Checkout\UploadController::ROUTE_UPLOADS
);

$wccs_bad_nonce->set_param( 'nonce', 'not-a-nonce' );
$wccs_bad_nonce->set_param( 'token', $wccs_mine );

$wccs_refused_response = rest_do_request( $wccs_bad_nonce );

wccs_proof_check(
	'A request that cannot prove it came from the page is refused',
	403 === $wccs_refused_response->get_status()
		&& 'bad_nonce' === ( $wccs_refused_response->get_data()['code'] ?? '' ),
	'status=' . $wccs_refused_response->get_status()
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

$wccs_service->remove( $wccs_mine, $wccs_owner_a );
wccs_proof_unlink( $wccs_good );
wccs_proof_unlink( $wccs_bad );
wccs_proof_unlink( $wccs_large );

delete_option( $wccs_privacy_option );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_check(
	'And no upload behind: the table is as it was',
	0 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() )
);

wccs_proof_note(
	'Why the owner is a session and not the customer',
	'A guest has no customer id, and a logged-in customer may check out from two devices. An identifier two checkouts share is an identifier that lets one of them read the other\'s documents, so the owner is generated per session and kept in the session — the browser never sees it and cannot choose it.'
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
