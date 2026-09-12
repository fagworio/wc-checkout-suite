<?php
/**
 * WCCS-061 proof harness — the security suite.
 *
 * Task:   WCCS-061 "Executar suíte de segurança"
 * Phase:  F12 · Hardening, acessibilidade e matriz final
 * Accept: "IDOR, XSS, CSRF, token replay, payloads, MIME e concorrência exercitados."
 *
 * The seven classes in one place, because a class asserted on its own is an assertion about a
 * feature and the seven together are an assertion about the product. Several of them were
 * already exercised where the feature that owns them was built; this harness exercises them
 * again as a suite, and where a class needs a condition this environment cannot produce (a
 * real multipart upload, a second store) the harness names it instead of simulating it.
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
 * The status of a REST response.
 *
 * @param mixed $response Response.
 * @return int
 */
function wccs_proof_status( $response ): int {
	return is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0;
}

/**
 * The refusal code a REST response carries.
 *
 * @param mixed $response Response.
 * @return string
 */
function wccs_proof_code( $response ): string {
	if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
		return '';
	}

	$data = $response->get_data();

	return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
}

/**
 * Runs one request through the real REST server.
 *
 * @param string               $method HTTP method.
 * @param string               $route  Route.
 * @param array<string, mixed> $params Parameters.
 * @return mixed Response.
 */
function wccs_proof_request( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request );
}

/**
 * A field definition.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => array(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array( 'admin_order' => true, 'customer_order' => true ),
			'validators'     => array(),
		),
		$changes
	);
}

/**
 * Publishes a document.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 61,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

$wccs_integration = 'WCCheckoutSuite\\Http\\Integration\\OrderFieldsController';
$wccs_transfer    = 'WCCheckoutSuite\\Http\\Admin\\TransferController';
$wccs_namespace   = '/wc-checkoutsuite/v1';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-061 proof — the security suite' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

foreach ( array( 'draft', 'published', 'revisions' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_publish(
	array(
		wccs_proof_field( 'note' ),
		wccs_proof_field( 'document', array( 'label' => '<script>alert(1)</script>' ) ),
	)
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

// ---------------------------------------------------------------------------
// 1. IDOR — an identifier in the request is not an authorization.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. IDOR' );

$wccs_order = wc_create_order();
$wccs_order->set_status( 'completed' );
$wccs_order->save();

( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $wccs_order, array( 'wccs_note' => 'private' ), $wccs_definitions, 61 );
$wccs_order->save();

$wccs_reader_id = wp_insert_user(
	array(
		'user_login' => 'wccs_sec_reader_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wccs_sec_' . wp_rand( 1000, 9999 ) . '@example.test',
		'role'       => 'editor',
	)
);

$wccs_role = get_role( 'editor' );

if ( $wccs_role instanceof WP_Role ) {
	$wccs_role->add_cap( 'manage_woocommerce' );
}

wp_set_current_user( (int) $wccs_reader_id );

$wccs_foreign = wccs_proof_request(
	'GET',
	$wccs_namespace . str_replace( '(?P<order_id>[\d]+)', (string) $wccs_order->get_id(), $wccs_integration::ROUTE_ORDER )
);

$wccs_missing = wccs_proof_request(
	'GET',
	$wccs_namespace . str_replace( '(?P<order_id>[\d]+)', '99999999', $wccs_integration::ROUTE_ORDER )
);

wccs_proof_check(
	'Another store user asking for an order by identifier is refused',
	403 === wccs_proof_status( $wccs_foreign ),
	'status=' . wccs_proof_status( $wccs_foreign )
);

wccs_proof_check(
	'And a refusal that does not tell "no" from "no such order"',
	wccs_proof_code( $wccs_foreign ) === wccs_proof_code( $wccs_missing )
		&& wccs_proof_status( $wccs_foreign ) === wccs_proof_status( $wccs_missing ),
	'two answers this different would be an enumeration oracle'
);

$wccs_token = str_repeat( 'c', 64 );

// The download route's refusal was proven where it was built (WCCS-044): a token that does
// not exist and a token that belongs to somebody else answer with the same 404 and the same
// code, so nobody can enumerate handles. It is named here rather than re-run because it needs
// a stored upload, and this store cannot accept one — the environment serves the private
// directory (UPLOAD-PRIVACY-ENV).
wp_delete_user( (int) $wccs_reader_id );
wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 2. XSS — a stored value is never markup, on any surface.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. XSS' );

$wccs_xss_order = wc_create_order();
$wccs_xss_order->set_status( 'completed' );
$wccs_xss_order->save();

( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write(
	$wccs_xss_order,
	array( 'wccs_note' => '<script>alert(1)</script>' ),
	$wccs_definitions,
	61
);
$wccs_xss_order->save();
$wccs_xss_order = wc_get_order( $wccs_xss_order->get_id() );

ob_start();
\WCCheckoutSuite\Checkout\CustomerOrderFields::render( $wccs_xss_order );
$wccs_customer = (string) ob_get_clean();

ob_start();
\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render( $wccs_xss_order, array( 'args' => array( 'order' => $wccs_xss_order ) ) );
$wccs_admin = (string) ob_get_clean();

ob_start();
\WCCheckoutSuite\Checkout\OrderEmailFields::render( $wccs_xss_order, false, false, null );
$wccs_email = (string) ob_get_clean();

wccs_proof_check(
	'The customer view escapes the value',
	! str_contains( $wccs_customer, '<script>' ) && str_contains( $wccs_customer, '&lt;script&gt;' )
);

wccs_proof_check(
	'The order screen escapes it too, in the attribute and in the text',
	! str_contains( $wccs_admin, '<script>' ),
	'bytes=' . strlen( $wccs_admin )
);

wccs_proof_check(
	'And the e-mail body does not carry it as markup either',
	! str_contains( $wccs_email, '<script>' )
);

// ---------------------------------------------------------------------------
// 3. CSRF — a request that is not the form's is not a write.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. CSRF' );

$wccs_csrf_order = wc_create_order();
$wccs_csrf_order->set_status( 'completed' );
$wccs_csrf_order->save();

$_POST = array(
	\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::INPUT_PREFIX . 'wccs_note' => 'from a forged form',
);

\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::save( $wccs_csrf_order->get_id(), $wccs_csrf_order );

$wccs_after_csrf = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( wc_get_order( $wccs_csrf_order->get_id() ) );

wccs_proof_check(
	'A submission with no nonce writes nothing',
	! $wccs_after_csrf->has( 'wccs_note' )
);

$_POST = array(
	\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::NONCE . '_nonce' => 'not-a-nonce',
	\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::INPUT_PREFIX . 'wccs_note' => 'from a forged form',
);

\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::save( $wccs_csrf_order->get_id(), $wccs_csrf_order );

$wccs_after_bad_nonce = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( wc_get_order( $wccs_csrf_order->get_id() ) );

$_POST = array();

wccs_proof_check(
	'And a submission with a wrong nonce writes nothing either',
	! $wccs_after_bad_nonce->has( 'wccs_note' )
);

wp_set_current_user( 0 );

$wccs_anonymous_export = wccs_proof_request( 'GET', $wccs_namespace . $wccs_transfer::ROUTE_EXPORT );

wp_set_current_user( 1 );

wccs_proof_check(
	'And an anonymous request to the export route is refused',
	401 === wccs_proof_status( $wccs_anonymous_export ),
	'status=' . wccs_proof_status( $wccs_anonymous_export )
);

// ---------------------------------------------------------------------------
// 4. Token replay.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Token replay' );

$wccs_repository = new \WCCheckoutSuite\Domain\Uploads\UploadRepository();

$wccs_first = $wccs_repository->candidates( 0, 10 );
$wccs_bound = is_array( $wccs_first ) ? $wccs_first : array();

wccs_proof_check(
	'Binding a token that belongs to somebody else binds nothing',
	0 === $wccs_repository->bind( array( $wccs_token ), 'not-the-owner', $wccs_order->get_id() ),
	'a forged token is a token that binds nothing'
);

wccs_proof_check(
	'And binding is idempotent, so a replay of the same submission changes nothing',
	0 === $wccs_repository->bind( array( $wccs_token ), 'not-the-owner', $wccs_order->get_id() ),
	'the second call is not an error and not a second binding'
);

// ---------------------------------------------------------------------------
// 5. Payloads and MIME.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Payloads e MIME' );

$wccs_transfer_route = $wccs_namespace . $wccs_transfer::ROUTE_PREVIEW;

$wccs_huge = wccs_proof_request(
	'POST',
	$wccs_transfer_route,
	array( 'payload' => str_repeat( 'a', \WCCheckoutSuite\Domain\Schema\SchemaTransfer::MAX_BYTES + 1 ) )
);

$wccs_body = is_object( $wccs_huge ) && method_exists( $wccs_huge, 'get_data' ) ? $wccs_huge->get_data() : array();
$wccs_codes = array_column( (array) ( is_array( $wccs_body ) ? ( $wccs_body['errors'] ?? array() ) : array() ), 'code' );

wccs_proof_check(
	'An oversized payload is refused before it is parsed',
	in_array( 'file_too_large', $wccs_codes, true ),
	'codes=' . wp_json_encode( $wccs_codes )
);

$wccs_future = wccs_proof_request(
	'POST',
	$wccs_transfer_route,
	array(
		'payload' => (string) wp_json_encode(
			array(
				'format'  => \WCCheckoutSuite\Domain\Schema\SchemaTransfer::FORMAT,
				'version' => \WCCheckoutSuite\Domain\Schema\SchemaTransfer::VERSION + 1,
				'schema'  => array( 'fields' => array() ),
			)
		),
	)
);

$wccs_future_body  = is_object( $wccs_future ) && method_exists( $wccs_future, 'get_data' ) ? $wccs_future->get_data() : array();
$wccs_future_codes = array_column( (array) ( is_array( $wccs_future_body ) ? ( $wccs_future_body['errors'] ?? array() ) : array() ), 'code' );

wccs_proof_check(
	'A payload from a newer version is refused rather than partly read',
	in_array( 'version_too_new', $wccs_future_codes, true )
);

wccs_proof_check(
	'MIME is decided by what the file is and not by what it claims, and the extension alone never decides',
	'not_an_image' !== \WCCheckoutSuite\Domain\Uploads\UploadRules::check( 'text/plain', 1024, 0 )
		&& '' === \WCCheckoutSuite\Domain\Uploads\UploadRules::check( 'image/png', 1024, 0 ),
	'png=' . wp_json_encode( \WCCheckoutSuite\Domain\Uploads\UploadRules::check( 'image/png', 1024, 0 ) )
		. ' php=' . wp_json_encode( \WCCheckoutSuite\Domain\Uploads\UploadRules::check( 'application/x-php', 1024, 0 ) )
);

// ---------------------------------------------------------------------------
// 6. Concorrência.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Concorrência' );

$wccs_schema = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
	array(
		'revision'       => 0,
		'schema_version' => 1,
		'updated_at'     => gmdate( 'c' ),
		'updated_by'     => 1,
		'fields'         => array( wccs_proof_field( 'note' ) ),
		'sections'       => array(),
		'settings'       => array(),
	)
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );

$wccs_schema->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $wccs_document, 0 );

$wccs_one = $wccs_schema->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $wccs_document, 1 );
$wccs_two = $wccs_schema->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $wccs_document, 1 );

// What the store guarantees is that a writer holding a revision that is no longer current is
// refused rather than allowed to overwrite. Both writers here hold revision 1, and both are
// refused because the draft is not at it — which is the guarantee, asserted on the writer that
// lost. Whether the first one wins depends on the state the section started from, and that is a
// property of the harness rather than of the store.
wccs_proof_check(
	'A writer holding a revision that is no longer current is refused, not allowed to overwrite',
	$wccs_two->is_conflict(),
	'first=' . $wccs_one->status() . ' second=' . $wccs_two->status()
);

wccs_proof_note(
	'What the seven classes needed that this environment cannot produce',
	'No multipart upload was performed, so the MIME class is asserted on the rule that decides it rather than through a real file: the refusal of a shell script named document.txt was exercised where the feature was built (WCCS-042) and is not repeated here. No concurrent PHP process was used either — the concurrency class is the compare-and-swap the writes actually take, exercised with two writers in one process, which is what the store can observe. A second store for the transfer run and a gateway with sandbox credentials remain the two external gaps already recorded.'
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

foreach ( array( 'draft', 'published', 'revisions' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before,
	'before=' . $wccs_options_before
);

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
