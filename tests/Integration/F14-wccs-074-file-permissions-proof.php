<?php
/**
 * WCCS-074 proof harness — what each destination may do with a file.
 *
 * Task:   WCCS-074 "Permissões de arquivo por destino"
 * Phase:  F14
 * Accept: "Matriz mostrar/ver/baixar/aprovar/reenviar independente por destino; cliente
 *          vê apenas o próprio pedido; nada vira público."
 *
 * The matrix is data in `FilePermissions`, and this harness drives it through the
 * classes that render and through the one route that serves bytes, because a rule
 * that only the model believes is not a rule:
 *
 * 1. **The matrix itself.** What a link declares is intersected with what its
 *    destination may perform, so no stored document — including one written by hand —
 *    can widen a destination beyond its own list, and an enabled link that declares
 *    nothing grants reading and nothing else.
 * 2. **The surfaces that list the document.** A file's name is metadata: a
 *    destination that may not show the name does not list the document, in the order
 *    panel and in the e-mail alike.
 * 3. **The door that serves bytes.** A surface that may not download refuses the
 *    file, the surface that may serves it as a private attachment, and a destination
 *    is not inherited by being named: a customer may not claim the staff surface.
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
 * Validates one definition and returns its codes.
 *
 * @param array<string, mixed> $data Definition.
 * @return array{valid: bool, codes: array<int, string>}
 */
function wccs_proof_validate( array $data ): array {
	$validator = \WCCheckoutSuite\Domain\Registries::instance()->definition_validator();
	$full      = array_merge(
		array(
			'id'     => 'wccs_authorisation',
			'origin' => 'custom',
			'type'   => 'text',
			'label'  => 'Autorização',
		),
		$data
	);

	$result = $validator->validate_array( $full );

	return array(
		'valid' => $result->is_valid(),
		'codes' => $result->error_codes(),
	);
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-074 proof — what each destination may do with a file' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_before = wccs_proof_option_count();

/**
 * One file field, linked to the given destinations.
 *
 * @param array<string, mixed> $destinations Destination links.
 * @return array<string, mixed>
 */
function wccs_proof_file_field( array $destinations ): array {
	return array(
		'id'             => 'wccs_document',
		'integration_id' => 'wc-checkoutsuite/wccs_document',
		'origin'         => 'custom',
		'type'           => 'file',
		'label'          => 'Documento',
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'settings'       => array(
			'maxFiles'          => 1,
			'allowedExtensions' => array( 'pdf', 'txt' ),
		),
		'destinations'   => $destinations,
	);
}

/**
 * Builds a definition from the raw array.
 *
 * @param array<string, mixed> $raw Raw field.
 * @return \WCCheckoutSuite\Domain\Fields\FieldDefinition
 */
function wccs_proof_definition( array $raw ): \WCCheckoutSuite\Domain\Fields\FieldDefinition {
	return \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $raw );
}

/**
 * Publishes one field through the real route, so the surfaces read it as they do.
 *
 * @param array<string, mixed> $field Field.
 * @return int Status.
 */
function wccs_proof_publish( array $field ): int {
	$current = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		(string) wp_json_encode(
			array(
				'schema'            => array(
					'revision' => 0,
					'fields'   => array( $field ),
					'sections' => array(),
					'settings' => array(),
				),
				'expected_revision' => $current->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
			)
		)
	);

	$response = rest_do_request( $request );

	if ( 200 !== $response->get_status() ) {
		return (int) $response->get_status();
	}

	$publish = $current->publish(
		$current->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ),
		null,
		1
	);

	return $publish->is_ok() ? 200 : 500;
}

// ---------------------------------------------------------------------------
// 1. The matrix, as the model answers it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What each destination may do with a file' );

$wccs_only_view = wccs_proof_definition(
	wccs_proof_file_field(
		array(
			'customer_order' => array(
				'enabled' => true,
				'actions' => array( 'show_metadata', 'view' ),
			),
		)
	)
);

wccs_proof_check(
	'A file type is a file as far as permissions are concerned',
	\WCCheckoutSuite\Domain\Uploads\FilePermissions::is_file( $wccs_only_view ),
	'type=' . $wccs_only_view->type()
);

$wccs_declared = wccs_proof_validate(
	wccs_proof_file_field(
		array(
			'admin_order' => array(
				'enabled' => true,
				'actions' => array( 'show_metadata', 'view', 'download', 'approve' ),
			),
		)
	)
);

wccs_proof_check(
	'Every action the matrix offers is one the validator takes for that destination',
	$wccs_declared['valid'],
	'codes=' . implode( ',', $wccs_declared['codes'] )
);

wccs_proof_check(
	'A destination that allows opening does not allow downloading',
	\WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_only_view, 'customer_order', 'view' )
		&& ! \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_only_view, 'customer_order', 'download' ),
	'actions=' . implode( ',', \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_only_view, 'customer_order' ) )
);

$wccs_default = wccs_proof_definition(
	wccs_proof_file_field( array( 'customer_order' => array( 'enabled' => true ) ) )
);

wccs_proof_check(
	'A link that declares no actions grants reading and nothing else',
	\WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_default, 'customer_order', 'show_metadata' )
		&& \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_default, 'customer_order', 'download' )
		&& ! \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_default, 'customer_order', 'approve' )
		&& ! \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_default, 'customer_order', 'resubmit' ),
	'actions=' . implode( ',', \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_default, 'customer_order' ) )
);

$wccs_handwritten = wccs_proof_definition(
	wccs_proof_file_field(
		array(
			'customer_order' => array(
				'enabled' => true,
				'actions' => array( 'show_metadata', 'download', 'approve', 'resubmit' ),
			),
		)
	)
);

wccs_proof_check(
	'A stored document cannot give a customer surface the right to approve',
	! \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_handwritten, 'customer_order', 'approve' )
		&& \WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_handwritten, 'customer_order', 'resubmit' ),
	'actions=' . implode( ',', \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_handwritten, 'customer_order' ) )
);

$wccs_staff = wccs_proof_definition(
	wccs_proof_file_field(
		array(
			'admin_order' => array(
				'enabled' => true,
				'actions' => array( 'show_metadata', 'view', 'download', 'approve' ),
			),
		)
	)
);

wccs_proof_check(
	'Approving is allowed where the destination may do it',
	\WCCheckoutSuite\Domain\Uploads\FilePermissions::allows( $wccs_staff, 'admin_order', 'approve' ),
	'actions=' . implode( ',', \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_staff, 'admin_order' ) )
);

wccs_proof_check(
	'A destination that is not linked allows nothing',
	array() === \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_staff, 'customer_email' ),
	'actions=' . implode( ',', \WCCheckoutSuite\Domain\Uploads\FilePermissions::actions( $wccs_staff, 'customer_email' ) )
);

// ---------------------------------------------------------------------------
// 2. The surfaces that list the field obey the same matrix.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Who lists the document' );

$wccs_hidden_metadata = wccs_proof_file_field(
	array(
		'customer_order' => array(
			'enabled' => true,
			'actions' => array( 'view', 'download' ),
		),
		'customer_email' => array(
			'enabled' => true,
			'actions' => array( 'download' ),
		),
	)
);

$wccs_shown = wccs_proof_file_field(
	array(
		'customer_order' => array(
			'enabled' => true,
			'actions' => array( 'show_metadata', 'view', 'download' ),
		),
		'customer_email' => array(
			'enabled' => true,
			'actions' => array( 'show_metadata', 'download' ),
		),
	)
);

$wccs_without = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( array( $wccs_hidden_metadata ) );
$wccs_with    = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( array( $wccs_shown ) );

wccs_proof_check(
	'A destination that may not show the name does not list the document',
	! array_key_exists( 'wccs_document', $wccs_without )
		&& array_key_exists( 'wccs_document', $wccs_with ),
	'without=' . implode( ',', array_keys( $wccs_without ) ) . ' with=' . implode( ',', array_keys( $wccs_with ) )
);

$wccs_email_without = \WCCheckoutSuite\Checkout\OrderEmailFields::visible( array( $wccs_hidden_metadata ), false );
$wccs_email_with    = \WCCheckoutSuite\Checkout\OrderEmailFields::visible( array( $wccs_shown ), false );

wccs_proof_check(
	'The e-mail obeys it too, for its own destination',
	! array_key_exists( 'wccs_document', $wccs_email_without )
		&& array_key_exists( 'wccs_document', $wccs_email_with ),
	'without=' . implode( ',', array_keys( $wccs_email_without ) ) . ' with=' . implode( ',', array_keys( $wccs_email_with ) )
);

// ---------------------------------------------------------------------------
// 3. The door that serves bytes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The bytes follow the same decision' );

// The matrix says a link that allows opening does not allow downloading. This is the
// same sentence with a request behind it: the store publishes a document that offers
// the name and the reader, and withholds the copy.
$wccs_read_only = wccs_proof_file_field(
	array(
		'customer_order' => array(
			'enabled' => true,
			'actions' => array( 'show_metadata', 'view' ),
		),
	)
);

$wccs_published = wccs_proof_publish( $wccs_read_only );

wccs_proof_check(
	'The store published what it configured',
	200 === $wccs_published,
	'status=' . $wccs_published
);

$wccs_body  = 'documento privado';
$wccs_store = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::put( $wccs_body, 'txt' );
$wccs_owner = \WCCheckoutSuite\Domain\Uploads\UploadService::owner();
$wccs_token = null === $wccs_store
	? ''
	: ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->insert(
		array(
			'owner'      => $wccs_owner,
			'field_id'   => 'wccs_document',
			'file_name'  => 'documento.txt',
			'mime_type'  => 'text/plain',
			'byte_size'  => strlen( $wccs_body ),
			'path'       => $wccs_store['path'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		)
	);

wccs_proof_check(
	'An upload was bound to a session',
	'' !== $wccs_token,
	'token=' . ( '' === $wccs_token ? '(none)' : substr( $wccs_token, 0, 8 ) . '…' )
);

/**
 * Asks the download route for one token from one surface.
 *
 * @param string $token       Token.
 * @param string $destination Destination key.
 * @return \WP_REST_Response Response.
 */
function wccs_proof_download( string $token, string $destination ): \WP_REST_Response {
	$request = new WP_REST_Request(
		'GET',
		'/' . WCCS_REST_NAMESPACE . str_replace( '(?P<token>[a-f0-9]{64})', $token, \WCCheckoutSuite\Http\Checkout\DownloadController::ROUTE_DOWNLOAD )
	);
	$request->set_param( 'destination', $destination );

	return rest_do_request( $request );
}

/**
 * The refusal code of a response, or an empty string.
 *
 * @param \WP_REST_Response $response Response.
 * @return string
 */
function wccs_proof_refusal( \WP_REST_Response $response ): string {
	$data = $response->get_data();

	return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
}

$wccs_refused = wccs_proof_download( $wccs_token, 'customer_order' );

wccs_proof_check(
	'A surface that may not download does not serve the file',
	404 === $wccs_refused->get_status() && 'not_allowed' === wccs_proof_refusal( $wccs_refused ),
	'status=' . $wccs_refused->get_status() . ' code=' . wccs_proof_refusal( $wccs_refused )
);

$wccs_published = wccs_proof_publish( $wccs_shown );

$wccs_served  = wccs_proof_download( $wccs_token, 'customer_order' );
$wccs_headers = $wccs_served->get_headers();

wccs_proof_check(
	'The same file is served once the destination allows downloading',
	200 === $wccs_published && 200 === $wccs_served->get_status() && $wccs_body === $wccs_served->get_data(),
	'status=' . $wccs_served->get_status()
);

wccs_proof_check(
	'And it is served as a private attachment, never as a public address',
	'nosniff' === ( $wccs_headers['X-Content-Type-Options'] ?? '' )
		&& str_contains( (string) ( $wccs_headers['Cache-Control'] ?? '' ), 'private' )
		&& str_contains( (string) ( $wccs_headers['Content-Disposition'] ?? '' ), 'attachment' )
		&& ! str_contains( (string) ( $wccs_headers['Content-Disposition'] ?? '' ), 'wp-content' ),
	'disposition=' . ( $wccs_headers['Content-Disposition'] ?? '' ) . ' cache=' . ( $wccs_headers['Cache-Control'] ?? '' )
);

$wccs_unlinked = wccs_proof_download( $wccs_token, 'admin_email' );

wccs_proof_check(
	'A destination the field is not linked to does not serve it either',
	404 === $wccs_unlinked->get_status() && 'not_allowed' === wccs_proof_refusal( $wccs_unlinked ),
	'status=' . $wccs_unlinked->get_status()
);

// The destination is a string the client sends, so it cannot be the authority on its
// own. The same request, from somebody who manages nothing, may not claim the staff
// surface of a document that offers a download there.
$wccs_staff_only = wccs_proof_file_field(
	array(
		'admin_order'    => array(
			'enabled' => true,
			'actions' => array( 'show_metadata', 'download' ),
		),
		'customer_order' => array(
			'enabled' => true,
			'actions' => array( 'show_metadata', 'download' ),
		),
	)
);

wccs_proof_publish( $wccs_staff_only );

$wccs_as_customer = wccs_proof_download( $wccs_token, 'customer_order' );
wp_set_current_user( 0 );
$wccs_claiming = wccs_proof_download( $wccs_token, 'admin_order' );
$wccs_own      = wccs_proof_download( $wccs_token, 'customer_order' );
wp_set_current_user( 1 );

wccs_proof_check(
	'A customer may not claim the staff surface to inherit what it allows',
	200 === $wccs_as_customer->get_status()
		&& 404 === $wccs_claiming->get_status()
		&& 'not_allowed' === wccs_proof_refusal( $wccs_claiming ),
	'staff=' . $wccs_as_customer->get_status() . ' claimed=' . $wccs_claiming->get_status()
);

wccs_proof_check(
	'And the surface that is theirs keeps serving the same bytes',
	200 === $wccs_own->get_status() && $wccs_body === $wccs_own->get_data(),
	'status=' . $wccs_own->get_status()
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
if ( '' !== $wccs_token ) {
	$wccs_record = ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->find_any( $wccs_token );

	if ( null !== $wccs_record ) {
		\WCCheckoutSuite\Domain\Uploads\PrivateStorage::delete( (string) ( $wccs_record['path'] ?? '' ) );
	}
}

$GLOBALS['wpdb']->query( 'DELETE FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
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
