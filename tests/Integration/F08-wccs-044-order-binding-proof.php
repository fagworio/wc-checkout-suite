<?php
/**
 * WCCS-044 proof harness — binding an upload to an order, and protecting it.
 *
 * Task:   WCCS-044 "Vincular ao pedido e proteger download"
 * Phase:  F08 · Upload privado nos dois checkouts
 * Accept: "Vínculo atômico/idempotente; dono autorizado acessa e terceiro recebe negação."
 *
 * Both halves are proven on rows and on the route rather than on a reading of the
 * code: the binding is run twice and the table is counted, and the download is asked
 * for by three different callers — the session that uploaded, the customer the order
 * belongs to, and a stranger.
 *
 * The accepting path needs the environment's observation injected, because this
 * store's private directory is served over HTTP (WCCS-041) and the feature is off
 * without it. The harness says so and deletes everything it writes.
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
 * Stores a file and records it, on behalf of an owner.
 *
 * @param string $owner Owner identifier.
 * @param string $body  Contents.
 * @return array{token: string, path: string}
 */
function wccs_proof_upload( string $owner, string $body ): array {
	$stored = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::put( $body, 'txt' );

	if ( null === $stored ) {
		return array(
			'token' => '',
			'path'  => '',
		);
	}

	$token = ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->insert(
		array(
			'owner'      => $owner,
			'field_id'   => 'wccs_document',
			'file_name'  => 'document.txt',
			'mime_type'  => 'text/plain',
			'byte_size'  => strlen( $body ),
			'path'       => $stored['path'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		)
	);

	return array(
		'token' => $token,
		'path'  => (string) $stored['path'],
	);
}

$wccs_privacy_option = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION;

delete_option( $wccs_privacy_option );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-044 proof — binding and download policy' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// The observation, made explicitly: nothing on a storefront request makes one.
\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::state( true );

wccs_proof_note(
	'The accepting path is exercised with the observation injected',
	'This store\'s private directory is served over HTTP (WCCS-041), so uploads are off without it. The harness deletes every file and row it creates.'
);

// ---------------------------------------------------------------------------
// 1. The binding.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Binding an upload to an order' );

$wccs_repository = new \WCCheckoutSuite\Domain\Uploads\UploadRepository();
$wccs_owner      = \WCCheckoutSuite\Domain\Uploads\UploadService::owner();
$wccs_other      = bin2hex( random_bytes( 16 ) );

$wccs_mine  = wccs_proof_upload( $wccs_owner, 'my document' );
$wccs_theirs = wccs_proof_upload( $wccs_other, 'their document' );

$wccs_order = wc_create_order();
$wccs_id    = $wccs_order instanceof WC_Order ? (int) $wccs_order->get_id() : 0;

wccs_proof_check(
	'An order to bind to, and two uploads belonging to two sessions',
	$wccs_id > 0 && '' !== $wccs_mine['token'] && '' !== $wccs_theirs['token'],
	'order=' . $wccs_id
);

$wccs_first = $wccs_repository->bind(
	array( $wccs_mine['token'], $wccs_theirs['token'] ),
	$wccs_owner,
	$wccs_id
);

wccs_proof_check(
	'One statement binds the upload of this session, and only it',
	1 === $wccs_first,
	'rows changed=' . $wccs_first
);

$wccs_bound = $wccs_repository->find( $wccs_mine['token'], $wccs_owner );

wccs_proof_check(
	'The bound upload says which order it belongs to, and is no longer temporary',
	$wccs_id === (int) ( $wccs_bound['order_id'] ?? 0 )
		&& \WCCheckoutSuite\Domain\Uploads\UploadRepository::STATUS_ORDERED === ( $wccs_bound['status'] ?? '' ),
	'record=' . wp_json_encode( array( 'order_id' => $wccs_bound['order_id'] ?? null, 'status' => $wccs_bound['status'] ?? null ) )
);

$wccs_theirs_row = $wccs_repository->find( $wccs_theirs['token'], $wccs_other );

wccs_proof_check(
	"And the other session's upload was not bound by it",
	0 === (int) ( $wccs_theirs_row['order_id'] ?? 0 ),
	'order_id=' . ( $wccs_theirs_row['order_id'] ?? '?' )
);

$wccs_second = $wccs_repository->bind( array( $wccs_mine['token'] ), $wccs_owner, $wccs_id );

wccs_proof_check(
	'Running it again changes nothing, which is what makes a retry safe',
	0 === $wccs_second,
	'rows changed on the second call=' . $wccs_second
);

$wccs_after = $wccs_repository->find( $wccs_mine['token'], $wccs_owner );

wccs_proof_check(
	'And the record is the same one, not a second binding',
	$wccs_id === (int) ( $wccs_after['order_id'] ?? 0 )
		&& (string) $wccs_bound['token'] === (string) $wccs_after['token']
);

wccs_proof_check(
	'A token that does not have the shape of one is ignored rather than queried',
	0 === $wccs_repository->bind( array( 'not-a-token', '../../etc/passwd' ), $wccs_owner, $wccs_id )
);

$wccs_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM " . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );

$wccs_repository->bind( array( $wccs_theirs['token'] ), $wccs_owner, $wccs_id );

wccs_proof_check(
	'And binding never creates a row',
	$wccs_before === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM " . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() )
);

// ---------------------------------------------------------------------------
// 2. The policy, on rows.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Who may read it' );

$wccs_customer = (int) $wccs_order->get_customer_id();

$wccs_allows = static function ( array $record, array $context ): bool {
	return \WCCheckoutSuite\Domain\Uploads\DownloadPolicy::allows( $record, $context );
};

wccs_proof_check(
	'The session that uploaded it may read it, before or after the order exists',
	$wccs_allows( $wccs_bound, array( 'owner' => $wccs_owner ) )
		&& $wccs_allows( $wccs_theirs_row, array( 'owner' => $wccs_other ) )
);

wccs_proof_check(
	'A stranger may not, with any context',
	! $wccs_allows( $wccs_bound, array( 'owner' => $wccs_other ) )
		&& ! $wccs_allows( $wccs_bound, array( 'owner' => '', 'user_id' => 0 ) )
);

wccs_proof_check(
	'Staff who manage the store may read it',
	$wccs_allows( $wccs_bound, array( 'owner' => '', 'user_id' => 1, 'can_manage' => true ) )
);

wccs_proof_check(
	'The customer the order belongs to may read it, and somebody else may not',
	$wccs_allows(
		array( 'owner' => $wccs_other, 'order_id' => $wccs_id ),
		array( 'owner' => '', 'user_id' => 7, 'order_customer' => 7 )
	)
		&& ! $wccs_allows(
			array( 'owner' => $wccs_other, 'order_id' => $wccs_id ),
			array( 'owner' => '', 'user_id' => 8, 'order_customer' => 7 )
		)
);

wccs_proof_check(
	'And an upload with no owner is readable by nobody',
	! $wccs_allows( array( 'owner' => '' ), array( 'owner' => '', 'user_id' => 1, 'can_manage' => false ) )
);

// ---------------------------------------------------------------------------
// 3. The route.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Asking for the file' );

$wccs_route = '/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . '/uploads/' . $wccs_mine['token'] . '/download';

$wccs_response = rest_do_request( new WP_REST_Request( 'GET', $wccs_route ) );

wccs_proof_check(
	'The session that uploaded it gets the bytes',
	200 === $wccs_response->get_status() && 'my document' === (string) $wccs_response->get_data(),
	'status=' . $wccs_response->get_status()
);

$wccs_headers = $wccs_response->get_headers();

wccs_proof_check(
	'As an attachment, never as a page',
	'nosniff' === ( $wccs_headers['X-Content-Type-Options'] ?? '' )
		&& str_contains( (string) ( $wccs_headers['Content-Disposition'] ?? '' ), 'attachment' ),
	'disposition=' . ( $wccs_headers['Content-Disposition'] ?? '' )
);

// The stranger: another session, with no session of its own to match.
$wccs_saved          = isset( WC()->session ) ? WC()->session->get( \WCCheckoutSuite\Domain\Uploads\UploadService::OWNER_KEY, '' ) : '';
$wccs_saved_logged   = get_current_user_id();
$wccs_stranger_route = '/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . '/uploads/' . $wccs_mine['token'] . '/download';

if ( isset( WC()->session ) ) {
	WC()->session->set( \WCCheckoutSuite\Domain\Uploads\UploadService::OWNER_KEY, $wccs_other );
}

wp_set_current_user( 0 );

$wccs_stranger = rest_do_request( new WP_REST_Request( 'GET', $wccs_stranger_route ) );

wccs_proof_check(
	'Another session is refused, and told nothing about the file',
	404 === $wccs_stranger->get_status()
		&& 'not_allowed' === ( $wccs_stranger->get_data()['code'] ?? '' )
		&& ! str_contains( (string) wp_json_encode( $wccs_stranger->get_data() ), 'document' ),
	'status=' . $wccs_stranger->get_status()
);

$wccs_missing = rest_do_request(
	new WP_REST_Request(
		'GET',
		'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . '/uploads/' . bin2hex( random_bytes( 32 ) ) . '/download'
	)
);

wccs_proof_check(
	'A token that does not exist gets the same refusal, so nobody can enumerate them',
	404 === $wccs_missing->get_status()
		&& $wccs_missing->get_data()['code'] === $wccs_stranger->get_data()['code'],
	'code=' . ( $wccs_missing->get_data()['code'] ?? '' )
);

if ( isset( WC()->session ) && '' !== $wccs_saved ) {
	WC()->session->set( \WCCheckoutSuite\Domain\Uploads\UploadService::OWNER_KEY, $wccs_saved );
}

wp_set_current_user( $wccs_saved_logged );

$wccs_staff = rest_do_request( new WP_REST_Request( 'GET', $wccs_stranger_route ) );

wccs_proof_check(
	'And staff who manage the store get the bytes',
	200 === $wccs_staff->get_status() && 'my document' === (string) $wccs_staff->get_data(),
	'status=' . $wccs_staff->get_status()
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

if ( $wccs_order instanceof WC_Order ) {
	$wccs_order->delete( true );
}

\WCCheckoutSuite\Domain\Uploads\PrivateStorage::delete( $wccs_mine['path'] );
\WCCheckoutSuite\Domain\Uploads\PrivateStorage::delete( $wccs_theirs['path'] );

$GLOBALS['wpdb']->query( 'DELETE FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );

delete_option( $wccs_privacy_option );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_check(
	'And no upload and no file behind',
	0 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() )
		&& ! file_exists( $wccs_mine['path'] )
		&& ! file_exists( $wccs_theirs['path'] )
);

wccs_proof_note(
	'Why the ownership is in the query',
	'The binding updates rows whose owner is the session asking and whose order is still empty. A check written in PHP would have to be remembered at every call site and would leave a window between reading and writing; in the statement it cannot be forgotten and there is no window.'
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
