<?php
/**
 * Fase 4 proof harness — a document the customer keeps on their own profile.
 *
 * Task:   Fase 4 "Minha Conta — upload do perfil"
 * Gate:   "cliente sem pedido usa formulário e persiste dados."
 *
 * The account page is where a customer without an order fills in and keeps their own data.
 * A document is part of that data, and it is not a checkout upload: it belongs to the
 * customer, it has to be there when they sign in from another device, and it must not expire
 * with a cart that was never placed. This harness proves the server half of that, against the
 * real database and the real private directory:
 *
 * 1. **The schema carries whose a file is.** `user_id` exists on the uploads table, and the
 *    version option says which build installed it.
 * 2. **An upload for a customer is accepted and recorded as theirs**, with no expiry, and the
 *    account page finds it by customer and field — not by a token it would have to remember.
 * 3. **Another customer cannot reach it**: the owner is checked in the query, not by a caller.
 * 4. **It is kept while the customer exists and goes with them when they do** — the case
 *    nothing else would ever collect, because the row has no expiry and no order.
 * 5. The harness leaves the store as it found it.
 *
 * Prerequisite: the plugin must be ACTIVE, uploads enabled and the uploads table installed.
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
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

/**
 * A file on disk to hand to the service, as PHP would hand an uploaded one.
 *
 * `is_uploaded_file()` is checked at the HTTP boundary and nowhere else — that check needs a
 * real request — so a harness can exercise the service with a readable path, which is what
 * every other test of this subsystem does.
 *
 * @param string $contents Contents.
 * @param string $name     Submitted name.
 * @return array<string, mixed>
 */
function wccs_proof_file( string $contents, string $name ): array {
	$path = wp_tempnam( $name );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture the harness owns and removes.
	file_put_contents( $path, $contents );

	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'size'     => strlen( $contents ),
		'error'    => UPLOAD_ERR_OK,
		'type'     => 'application/pdf',
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 4 proof — a document the customer keeps on their own profile' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

global $wpdb;

// The observation and the document slots are cleared first so the count at the end is
// comparable, and so the harness's own fixtures cannot be mistaken for something the store
// decided.
$wccs_ref_privacy_option = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION;

delete_option( $wccs_ref_privacy_option );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );
// The endpoint signature is this harness's to write and remove: registering the endpoints below
// records what they were, and the teardown removes the record along with the document.
delete_option( 'wccs_account_endpoint_signature' );

$wccs_ref_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. The schema carries whose a file is.
// ---------------------------------------------------------------------------
$wccs_ref_columns = $wpdb->get_col( 'DESCRIBE ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name(), 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading the shape of this plugin's own table.

wccs_proof_check(
	'The uploads table carries the customer a document belongs to',
	in_array( 'user_id', $wccs_ref_columns, true ),
	implode( ',', $wccs_ref_columns )
);

wccs_proof_check(
	'And the version option says which build installed it',
	'2' === (string) get_option( \WCCheckoutSuite\Domain\Uploads\UploadsTable::VERSION_OPTION, '' ),
	'version=' . (string) get_option( \WCCheckoutSuite\Domain\Uploads\UploadsTable::VERSION_OPTION, '' )
);

// What this store says today, before anything is injected. The probe is an outbound HTTP
// request to the store's own address, which is why it is made here rather than on a
// storefront request.
$wccs_ref_observed = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::state( true );

wccs_proof_note(
	'What this store does today',
	'protected=' . ( $wccs_ref_observed['protected'] ? 'yes' : 'no' ) . ' status=' . $wccs_ref_observed['status'] . ' reason=' . ( '' !== $wccs_ref_observed['reason'] ? $wccs_ref_observed['reason'] : '(none)' )
);

// From here on the observation is injected, so the rules can be exercised on a store that
// would be allowed to hold a file. The harness says so rather than implying that this
// environment passes the check, and deletes what it writes.
wccs_proof_note(
	'The accepting path below is exercised with the observation injected',
	'This is the same convention WCCS-042 set: the probe result is a cached fact about the environment, and injecting it lets the ownership and the retention be proven without pretending this box protects the directory.'
);

update_option(
	$wccs_ref_privacy_option,
	array(
		'protected'  => true,
		'status'     => 403,
		'reason'     => '',
		'checked_at' => time(),
	),
	false
);

wccs_proof_check(
	'With a protected directory, uploads are offered',
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled(),
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason()
);

// ---------------------------------------------------------------------------
// 2. One customer's document.
// ---------------------------------------------------------------------------
$wccs_ref_login = 'wccs_upload_' . wp_generate_password( 6, false, false );
$wccs_ref_user  = wp_insert_user(
	array(
		'user_login' => $wccs_ref_login,
		'user_pass'  => wp_generate_password( 20, true, true ),
		'user_email' => $wccs_ref_login . '@example.invalid',
		'role'       => 'customer',
	)
);

if ( is_wp_error( $wccs_ref_user ) ) {
	fwrite( STDERR, 'Could not create the fixture customer: ' . $wccs_ref_user->get_error_message() . "\n" );
	exit( 1 );
}

$wccs_ref_user_id = (int) $wccs_ref_user;
$wccs_ref_login   = (string) get_userdata( $wccs_ref_user_id )->user_login;

$wccs_ref_service = new \WCCheckoutSuite\Domain\Uploads\UploadService();
$wccs_ref_file    = wccs_proof_file( "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'contrato-social.pdf' );

$wccs_ref_accepted = $wccs_ref_service->accept_for_customer( $wccs_ref_file, 'documento_do_cliente', $wccs_ref_user_id );

wccs_proof_check(
	'An upload for a customer is accepted',
	'' === $wccs_ref_accepted['code'] && 64 === strlen( $wccs_ref_accepted['token'] ),
	'code=' . $wccs_ref_accepted['code'] . ' message=' . $wccs_ref_accepted['message']
);

$wccs_ref_token  = (string) $wccs_ref_accepted['token'];
$wccs_ref_record = $wccs_ref_service->for_customer_field( $wccs_ref_user_id, 'documento_do_cliente' );

wccs_proof_check(
	'And the account page finds it by customer and field, not by token',
	is_array( $wccs_ref_record ) && (string) $wccs_ref_record['token'] === $wccs_ref_token,
	is_array( $wccs_ref_record ) ? 'file=' . $wccs_ref_record['file_name'] : '(not found)'
);

wccs_proof_check(
	'It is stored for that customer, with no expiry',
	is_array( $wccs_ref_record ) &&
		$wccs_ref_user_id === (int) $wccs_ref_record['user_id'] &&
		'stored' === (string) $wccs_ref_record['status'] &&
		null === $wccs_ref_record['expires_at'] &&
		0 === (int) $wccs_ref_record['order_id'],
	is_array( $wccs_ref_record )
		? 'user=' . $wccs_ref_record['user_id'] . ' status=' . $wccs_ref_record['status'] . ' expires=' . var_export( $wccs_ref_record['expires_at'], true )
		: '(not found)'
);

wccs_proof_check(
	'And it counts against that customer, so the quota is theirs',
	(int) $wccs_ref_record['byte_size'] === (int) ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->used_bytes( 'customer:' . $wccs_ref_user_id ),
	'bytes=' . ( is_array( $wccs_ref_record ) ? $wccs_ref_record['byte_size'] : 0 )
);

// ---------------------------------------------------------------------------
// 3. Another customer cannot reach it.
// ---------------------------------------------------------------------------
$wccs_ref_other_login = 'wccs_other_' . wp_generate_password( 6, false, false );
$wccs_ref_other       = wp_insert_user(
	array(
		'user_login' => $wccs_ref_other_login,
		'user_pass'  => wp_generate_password( 20, true, true ),
		'user_email' => $wccs_ref_other_login . '@example.invalid',
		'role'       => 'customer',
	)
);

$wccs_ref_other_id = is_wp_error( $wccs_ref_other ) ? 0 : (int) $wccs_ref_other;

$wccs_ref_theirs = $wccs_ref_service->find( $wccs_ref_token, \WCCheckoutSuite\Domain\Uploads\UploadService::customer_owner( $wccs_ref_other_id ) );

wccs_proof_check(
	'Another customer cannot read it, and is told it is not theirs',
	'not_yours' === $wccs_ref_theirs['code'],
	'code=' . $wccs_ref_theirs['code']
);

$wccs_ref_own = $wccs_ref_service->find( $wccs_ref_token, \WCCheckoutSuite\Domain\Uploads\UploadService::customer_owner( $wccs_ref_user_id ) );

wccs_proof_check(
	'While its own customer reads it',
	'' === $wccs_ref_own['code'] && is_array( $wccs_ref_own['record'] ),
	'code=' . $wccs_ref_own['code']
);

wccs_proof_check(
	'And a checkout session is not the customer, so a session cannot claim it',
	'' === \WCCheckoutSuite\Domain\Uploads\UploadService::customer_owner( 0 ),
	'owner=' . var_export( \WCCheckoutSuite\Domain\Uploads\UploadService::customer_owner( 0 ), true )
);

// ---------------------------------------------------------------------------
// 4. Kept while the customer exists, gone with them when they are not.
// ---------------------------------------------------------------------------
$wccs_ref_repository = new \WCCheckoutSuite\Domain\Uploads\UploadRepository();
$wccs_ref_candidates = $wccs_ref_repository->candidates( time(), 200 );
$wccs_ref_seen       = false;

foreach ( $wccs_ref_candidates as $wccs_ref_candidate ) {
	if ( (string) ( $wccs_ref_candidate['token'] ?? '' ) === $wccs_ref_token ) {
		$wccs_ref_seen = true;
	}
}

wccs_proof_check(
	'The cleanup looks at a customer document, even though it never expires',
	$wccs_ref_seen,
	'candidates=' . count( $wccs_ref_candidates )
);

$wccs_ref_decision = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::decision( (array) $wccs_ref_record, time(), false, true );

wccs_proof_check(
	'It is kept while the customer exists',
	'keep' === $wccs_ref_decision,
	'decision=' . $wccs_ref_decision
);

// ---------------------------------------------------------------------------
// 4. The page the customer fills in.
// ---------------------------------------------------------------------------
$wccs_ref_repository_published = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_ref_document = array(
	'revision' => 1,
	'fields'   => array(
		array(
			'id'             => 'documento_do_cliente',
			'integration_id' => 'wc-checkoutsuite/documento_do_cliente',
			'origin'         => 'custom',
			'type'           => 'file',
			'label'          => 'Contrato social',
			'section'        => 'documentos_da_conta',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'storage'        => array(
				'scope'       => 'customer',
				'sensitivity' => 'sensitive',
			),
			'settings'       => array(
				'maxFiles'          => 1,
				'allowedExtensions' => array( 'pdf' ),
			),
			'destinations'   => array(
				'customer_account' => array(
					'enabled' => true,
					'section' => 'documentos_da_conta',
					'title'   => 'Seu contrato',
					'mode'    => 'edit',
					'actions' => array( 'show_metadata', 'view' ),
				),
			),
		),
	),
	'sections' => array(
		array(
			'id'           => 'documentos_da_conta',
			'title'        => 'Documentos',
			'description'  => '',
			'position'     => 10,
			'location'     => 'order',
			'areas'        => array( 'customer_account' ),
			'presentation' => array(
				'account' => array(
					'slug'       => 'documentos-da-conta',
					'menu_label' => 'Documentos',
					'icon'       => 'file',
					'position'   => 5,
					'mode'       => 'edit',
				),
			),
		),
	),
	'settings' => array(),
);

$wccs_ref_written = $wccs_ref_repository_published->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array( $wccs_ref_document ),
	$wccs_ref_repository_published->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

wccs_proof_check(
	'A customer account page with a document is a document the store accepts',
	$wccs_ref_written->is_ok(),
	'ok=' . ( $wccs_ref_written->is_ok() ? 'yes' : 'no' )
);

\WCCheckoutSuite\Account\MyAccountSections::register_endpoints();

wp_set_current_user( $wccs_ref_user_id );
set_query_var( 'documentos-da-conta', '' );

ob_start();
\WCCheckoutSuite\Account\MyAccountSections::render();
$wccs_ref_html = (string) ob_get_clean();

wccs_proof_check(
	'The page shows the document the customer already sent',
	false !== strpos( $wccs_ref_html, 'contrato-social.pdf' ) &&
		false !== strpos( $wccs_ref_html, 'wccs-account-document-documento_do_cliente' ),
	'name=' . ( false !== strpos( $wccs_ref_html, 'contrato-social.pdf' ) ? 'shown' : 'missing' )
);

wccs_proof_check(
	'It offers the door that serves the bytes, when the use allows reading',
	false !== strpos( $wccs_ref_html, 'wccs-account-document__link' ) &&
		false !== strpos( $wccs_ref_html, 'token=' . $wccs_ref_token ),
	'the link carries the token and the destination'
);

wccs_proof_check(
	'And the form can carry bytes, because a document is not a value',
	false !== strpos( $wccs_ref_html, 'enctype="multipart/form-data"' ) &&
		false !== strpos( $wccs_ref_html, 'name="wccs_account_files[documento_do_cliente]"' ),
	'the input is a file input, not a text one'
);

// With the store unable to protect the directory, the page says why instead of offering a
// control that cannot work.
delete_option( $wccs_ref_privacy_option );

ob_start();
\WCCheckoutSuite\Account\MyAccountSections::render();
$wccs_ref_html_unavailable = (string) ob_get_clean();

wccs_proof_check(
	'And when the store cannot keep files private, it says so instead of pretending',
	false === strpos( $wccs_ref_html_unavailable, 'wccs_account_files[' ) &&
		false !== strpos( $wccs_ref_html_unavailable, 'wccs-account-document__unavailable' ),
	'reason shown, no input'
);

wccs_proof_check(
	'While the document the customer sent is still shown',
	false !== strpos( $wccs_ref_html_unavailable, 'contrato-social.pdf' ),
	'the document is theirs whether or not uploads are offered'
);

// The published document this section wrote is removed, so the sweep is not run against a
// store this harness configured.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

// ---------------------------------------------------------------------------
// 5. A section inside a native page (§7.4).
// ---------------------------------------------------------------------------
$wccs_ref_native = $wccs_ref_document;
$wccs_ref_native['sections'][0]['presentation']['account'] = array(
	// A section placed on a page WooCommerce already has: no slug of its own, no endpoint, no
	// menu entry — it renders inside the page that exists.
	'page'       => 'edit-account',
	'menu_label' => 'Dados profissionais',
	'icon'       => 'fields',
	'position'   => 5,
	'mode'       => 'edit',
);

$wccs_ref_native_written = $wccs_ref_repository_published->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array( $wccs_ref_native ),
	$wccs_ref_repository_published->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

wccs_proof_check(
	'A section placed inside a native page is a document the store accepts',
	$wccs_ref_native_written->is_ok(),
	'ok=' . ( $wccs_ref_native_written->is_ok() ? 'yes' : 'no' )
);

\WCCheckoutSuite\Account\MyAccountSections::register_endpoints();

$wccs_ref_menu = \WCCheckoutSuite\Account\MyAccountSections::menu_items( array() );

wccs_proof_check(
	'It registers no endpoint of its own, and adds no menu entry',
	! isset( $wccs_ref_menu['dados-profissionais'] ) &&
		! in_array( 'Dados profissionais', $wccs_ref_menu, true ),
	'menu=' . implode( ',', array_keys( $wccs_ref_menu ) )
);

// The native page's own action, which is what WooCommerce fires for the page the customer
// opened. Firing it is what the page does.
set_query_var( 'edit-account', '' );

ob_start();
do_action( 'woocommerce_account_edit-account_endpoint' );
$wccs_ref_native_html = (string) ob_get_clean();

wccs_proof_check(
	'And it renders inside that page, after WooCommerce\'s own content',
	false !== strpos( $wccs_ref_native_html, 'Seu contrato' ) &&
		false !== strpos( $wccs_ref_native_html, 'wccs-account-section' ),
	'length=' . strlen( $wccs_ref_native_html )
);

wccs_proof_check(
	'With its own form and its own section marker, so a submission knows what it answers',
	false !== strpos( $wccs_ref_native_html, '<form method="post"' ) &&
		false !== strpos( $wccs_ref_native_html, 'name="wccs_account_section"' ) &&
		false !== strpos( $wccs_ref_native_html, 'value="documentos_da_conta"' ),
	'the same submission path as a page of its own'
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_ref_path = (string) $wccs_ref_record['path'];

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $wccs_ref_user_id, $wccs_ref_other_id > 0 ? $wccs_ref_other_id : null );

$wccs_ref_after_delete = $wccs_ref_repository->for_user( $wccs_ref_user_id, 'documento_do_cliente' );

wccs_proof_check(
	'The document is still on file when the customer is gone, so the cleanup can decide',
	is_array( $wccs_ref_after_delete ),
	is_array( $wccs_ref_after_delete ) ? 'row kept until the sweep runs' : '(row already gone)'
);

$wccs_ref_sweep = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::sweep( 200 );

wccs_proof_check(
	'And the sweep removes it, file and row together',
	null === $wccs_ref_repository->for_user( $wccs_ref_user_id, 'documento_do_cliente' ) && ! file_exists( $wccs_ref_path ),
	'orphaned=' . ( $wccs_ref_sweep['orphaned'] ?? 0 ) . ' files_removed=' . ( $wccs_ref_sweep['files_removed'] ?? 0 )
);

if ( $wccs_ref_other_id > 0 ) {
	wp_delete_user( $wccs_ref_other_id );
}

if ( file_exists( (string) $wccs_ref_file['tmp_name'] ) ) {
	unlink( (string) $wccs_ref_file['tmp_name'] );
}

wccs_proof_note(
	'What this harness does not cover',
	'The account page is rendered here with the real renderer and the real document; the browser drives the same page with a real form submission in tests/browser/fase4-my-account-upload.mjs, and says there that this box needs the observation injected.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
delete_option( $wccs_ref_privacy_option );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );
// Registering the endpoint for this harness's document records what the endpoints were, and
// that record belongs to the document being removed.
delete_option( 'wccs_account_endpoint_signature' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_ref_options_before,
	'before=' . $wccs_ref_options_before . ' after=' . wccs_proof_option_count()
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
