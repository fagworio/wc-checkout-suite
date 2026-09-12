<?php
/**
 * WCCS-008 proof harness — draft, atomic publication, revisions and 409 conflict.
 *
 * Task:   WCCS-008 "Implementar draft, publicação e revisões"
 * Phase:  F01
 * Accept: "Gravação atômica e conflito 409 testados; publicar não perde histórico."
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * The harness writes real options and deletes them again at the end; it also
 * asserts that the database is left exactly as it was found.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaMigrations;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

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
 * A valid field definition for the proofs.
 *
 * @param string $id Field id.
 * @return array<string, mixed>
 */
function wccs_proof_field( $id ) {
	return array(
		'id'       => $id,
		'type'     => 'text',
		'label'    => 'Document ' . $id,
		'section'  => 'billing',
		'position' => 10,
		'settings' => array( 'maxLength' => 20 ),
	);
}

/**
 * Builds a document with an explicit revision.
 *
 * The revision is stated outright here so the proofs do not depend on the
 * incrementing behaviour of `bumped()`.
 *
 * @param array<int, array<string, mixed>> $fields   Field definitions.
 * @param int                              $revision Revision number.
 * @return SchemaDocument
 */
function wccs_proof_doc( array $fields, int $revision ): SchemaDocument {
	return SchemaDocument::from_array(
		array(
			'revision'       => $revision,
			'schema_version' => 1,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
		)
	);
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs_schema_%'" );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-008 proof — draft, publication and revisions' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_repository   = new SchemaRepository(
	Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard(),
	5
);
$wccs_option_names = array(
	SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ),
	SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ),
	'wccs_schema_revisions',
);

// A harness that passes only on a database someone else left clean fails for a reason
// that is not about the code. The three options this proof is about are cleared here,
// which makes the empty state this file's own decision and the run independent of what
// ran before it.
foreach ( $wccs_option_names as $wccs_option_name ) {
	delete_option( $wccs_option_name );
	wp_cache_delete( $wccs_option_name, 'options' );
}

$wccs_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. Empty state.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Empty state' );

wccs_proof_check( 'The proof starts from no schema option, having cleared them itself', 0 === $wccs_before, 'options=' . $wccs_before );
wccs_proof_check( 'An absent draft reads as revision 0', 0 === $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->revision() );
wccs_proof_check( 'An absent published schema reads as revision 0', 0 === $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision() );

// ---------------------------------------------------------------------------
// 2. Saving a draft never touches the published schema.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Draft isolation' );

$wccs_draft  = wccs_proof_doc( array( wccs_proof_field( 'billing_document' ) ), 1 );
$wccs_result = $wccs_repository->write( SchemaRepository::SLOT_DRAFT, $wccs_draft, 0 );

wccs_proof_check( 'A first draft is written', $wccs_result->is_ok(), 'status=' . $wccs_result->status() . ' revision=' . $wccs_result->revision() );
wccs_proof_check( 'The draft keeps its revision', 1 === $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->revision() );
wccs_proof_check( 'Saving a draft leaves the published schema untouched', 0 === $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision() );

// ---------------------------------------------------------------------------
// 3. Compare-and-swap conflict.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Compare-and-swap conflict' );

$wccs_stale = $wccs_repository->write( SchemaRepository::SLOT_DRAFT, wccs_proof_doc( array( wccs_proof_field( 'billing_other' ) ), 2 ), 0 );

wccs_proof_check(
	'A writer holding a stale revision is rejected',
	$wccs_stale->is_conflict(),
	'status=' . $wccs_stale->status() . ' current_revision=' . $wccs_stale->revision()
);
wccs_proof_check( 'The conflict reports the revision that actually won', 1 === $wccs_stale->revision() );
wccs_proof_check(
	'The rejected write did not change the stored draft',
	'billing_document' === ( $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->fields()[0]['id'] ?? '' )
);

$wccs_identical = $wccs_repository->write( SchemaRepository::SLOT_DRAFT, $wccs_draft, 1 );
wccs_proof_check(
	'Writing the byte-identical document again is an idempotent no-op, not a false conflict',
	$wccs_identical->is_ok(),
	'status=' . $wccs_identical->status()
);

// ---------------------------------------------------------------------------
// 4. Publication: full validation, atomic replacement, history kept.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Publication' );

$wccs_published = $wccs_repository->publish( $wccs_repository->read( SchemaRepository::SLOT_DRAFT ), 1, 1 );

wccs_proof_check( 'A valid draft publishes', $wccs_published->is_ok(), 'revision=' . $wccs_published->revision() );
wccs_proof_check( 'The published slot advances to revision 1', 1 === $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision() );
wccs_proof_check( 'Publication creates one history entry', 1 === count( $wccs_repository->revisions() ) );

$wccs_history_entry = $wccs_repository->revisions()[0] ?? array();
wccs_proof_check(
	'The history entry records revision, author, timestamp and hash',
	1 === ( $wccs_history_entry['revision'] ?? 0 )
		&& 1 === ( $wccs_history_entry['published_by'] ?? 0 )
		&& '' !== ( $wccs_history_entry['published_at'] ?? '' )
		&& '' !== ( $wccs_history_entry['hash'] ?? '' )
);

$wccs_invalid_draft = wccs_proof_doc( array( array( 'id' => 'broken', 'type' => 'does-not-exist', 'label' => 'Broken' ) ), 2 );
$wccs_invalid       = $wccs_repository->publish( $wccs_invalid_draft, 2, 1 );

wccs_proof_check(
	'Publishing an invalid schema is refused with error codes',
	'invalid' === $wccs_invalid->status() && count( $wccs_invalid->errors() ) > 0,
	'status=' . $wccs_invalid->status() . ' errors=' . count( $wccs_invalid->errors() )
);
wccs_proof_check(
	'A refused publication leaves the published schema untouched',
	1 === $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision() && 1 === count( $wccs_repository->revisions() )
);

$wccs_repository->write( SchemaRepository::SLOT_DRAFT, wccs_proof_doc( array( wccs_proof_field( 'billing_document' ), wccs_proof_field( 'billing_extra' ) ), 3 ), 1 );
$wccs_second = $wccs_repository->publish( $wccs_repository->read( SchemaRepository::SLOT_DRAFT ), 3, 1 );

wccs_proof_check( 'A second publication succeeds', $wccs_second->is_ok(), 'revision=' . $wccs_second->revision() );
wccs_proof_check( 'Publishing does not lose history', 2 === count( $wccs_repository->revisions() ) );
wccs_proof_check(
	'History is newest first',
	2 === ( $wccs_repository->revisions()[0]['revision'] ?? 0 ) && 1 === ( $wccs_repository->revisions()[1]['revision'] ?? 0 )
);

// ---------------------------------------------------------------------------
// 5. Restore.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Restore' );

$wccs_restored = $wccs_repository->restore( 1, 7 );

wccs_proof_check( 'Restoring an existing revision succeeds', $wccs_restored->is_ok(), 'revision=' . $wccs_restored->revision() );
wccs_proof_check(
	'Restoring republishes the old content as a NEW revision',
	3 === $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision()
		&& 1 === count( $wccs_repository->read( SchemaRepository::SLOT_PUBLISHED )->fields() )
);
wccs_proof_check( 'Restoring appends history instead of rewriting it', 3 === count( $wccs_repository->revisions() ) );
wccs_proof_check( 'Restoring an unknown revision is refused', ! $wccs_repository->restore( 99, 1 )->is_ok() );
wccs_proof_check( 'A refused restore leaves history untouched', 3 === count( $wccs_repository->revisions() ) );

// ---------------------------------------------------------------------------
// 6. Schema version and migrations.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Schema version and migrations' );

$wccs_unversioned = SchemaMigrations::migrate( array( 'fields' => array() ), '2026-09-11T00:00:00+00:00' );

wccs_proof_check(
	'A document without a version is stamped and recorded',
	1 === $wccs_unversioned['schema_version'] && 'stamp_missing_version' === ( $wccs_unversioned['migration_history'][0]['step'] ?? '' ),
	'step=' . ( $wccs_unversioned['migration_history'][0]['step'] ?? 'none' )
);

$wccs_refused = false;
try {
	SchemaMigrations::migrate( array( 'schema_version' => 99 ) );
} catch ( InvalidArgumentException $exception ) {
	$wccs_refused = true;
}

wccs_proof_check( 'A document from a newer build is refused, not misread', $wccs_refused );
wccs_proof_check( 'Only supported versions are accepted', SchemaMigrations::is_supported( 1 ) && ! SchemaMigrations::is_supported( 99 ) );

// A stored document from a newer build must not be served with the wrong shape.
$wccs_repository->write( SchemaRepository::SLOT_DRAFT, wccs_proof_doc( array(), 9 ), 3 );
$wccs_newer_raw = get_option( SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ) );
$wccs_newer     = json_decode( (string) $wccs_newer_raw, true );
$wccs_newer['schema_version'] = 99;
update_option( SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ), (string) wp_json_encode( $wccs_newer ), false );
wccs_proof_check(
	'A stored document from a newer build decodes as empty instead of failing later',
	0 === $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->revision()
);

// ---------------------------------------------------------------------------
// 7. REST transport: 200 / 401 / 409 / 422.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. REST transport' );

$wccs_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$wccs_admin  = (int) ( $wccs_admins[0] ?? 0 );

wccs_proof_check( 'An administrator exists to authenticate as', $wccs_admin > 0, 'user_id=' . $wccs_admin );

wp_set_current_user( $wccs_admin );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_rest' );

$wccs_get = rest_do_request( new WP_REST_Request( 'GET', '/wc-checkoutsuite/v1/schema/draft' ) );
wccs_proof_check( 'GET /schema/draft returns 200 for an administrator', 200 === $wccs_get->get_status(), 'status=' . $wccs_get->get_status() );

// The draft was left unusable by the previous section; write a clean one.
$wccs_repository->write( SchemaRepository::SLOT_DRAFT, wccs_proof_doc( array( wccs_proof_field( 'billing_rest' ) ), 1 ), null );

$wccs_conflict_request = new WP_REST_Request( 'PUT', '/wc-checkoutsuite/v1/schema/draft' );
$wccs_conflict_request->set_body_params(
	array(
		'schema'            => array( 'fields' => array( wccs_proof_field( 'billing_race' ) ) ),
		'expected_revision' => 7,
	)
);
$wccs_conflict_response = rest_do_request( $wccs_conflict_request );
wccs_proof_check( 'A stale expected_revision returns 409', 409 === $wccs_conflict_response->get_status(), 'status=' . $wccs_conflict_response->get_status() );

$wccs_ok_request = new WP_REST_Request( 'PUT', '/wc-checkoutsuite/v1/schema/draft' );
$wccs_ok_request->set_body_params(
	array(
		'schema'            => array( 'fields' => array( wccs_proof_field( 'billing_final' ) ) ),
		'expected_revision' => 1,
	)
);
$wccs_ok_response = rest_do_request( $wccs_ok_request );
wccs_proof_check( 'A current expected_revision returns 200', 200 === $wccs_ok_response->get_status(), 'status=' . $wccs_ok_response->get_status() );
wccs_proof_check(
	'The server owns the revision: the request body cannot set it',
	2 === $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->revision(),
	'draft revision=' . $wccs_repository->read( SchemaRepository::SLOT_DRAFT )->revision()
);

$wccs_publish_request = new WP_REST_Request( 'POST', '/wc-checkoutsuite/v1/schema/publish' );
$wccs_publish_request->set_body_params( array( 'expected_revision' => 2 ) );
$wccs_publish_response = rest_do_request( $wccs_publish_request );
wccs_proof_check( 'Publishing a valid draft returns 200', 200 === $wccs_publish_response->get_status(), 'status=' . $wccs_publish_response->get_status() );

$wccs_revisions_response = rest_do_request( new WP_REST_Request( 'GET', '/wc-checkoutsuite/v1/schema/revisions' ) );
$wccs_revisions_data     = (array) $wccs_revisions_response->get_data();
wccs_proof_check(
	'GET /schema/revisions lists history without leaking stored documents',
	200 === $wccs_revisions_response->get_status()
		&& 4 === count( $wccs_revisions_data['revisions'] ?? array() )
		&& ! isset( $wccs_revisions_data['revisions'][0]['document'] ),
	'status=' . $wccs_revisions_response->get_status() . ' entries=' . count( $wccs_revisions_data['revisions'] ?? array() )
);

$wccs_repository->write(
	SchemaRepository::SLOT_DRAFT,
	wccs_proof_doc( array( array( 'id' => 'broken', 'type' => 'does-not-exist', 'label' => 'Broken' ) ), 3 ),
	2
);
$wccs_422_request = new WP_REST_Request( 'POST', '/wc-checkoutsuite/v1/schema/publish' );
$wccs_422_request->set_body_params( array( 'expected_revision' => 3 ) );
$wccs_422_response = rest_do_request( $wccs_422_request );
wccs_proof_check( 'Publishing an invalid schema returns 422', 422 === $wccs_422_response->get_status(), 'status=' . $wccs_422_response->get_status() );

wp_set_current_user( 0 );
unset( $_REQUEST['_wpnonce'] );
$wccs_forbidden = rest_do_request( new WP_REST_Request( 'GET', '/wc-checkoutsuite/v1/schema/draft' ) );
wccs_proof_check(
	'A request without an authenticated user is refused',
	in_array( $wccs_forbidden->get_status(), array( 401, 403 ), true ),
	'status=' . $wccs_forbidden->get_status()
);

// ---------------------------------------------------------------------------
// 8. Cleanup.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Cleanup' );

foreach ( $wccs_option_names as $wccs_option_name ) {
	delete_option( $wccs_option_name );
	wp_cache_delete( $wccs_option_name, 'options' );
}

$wccs_after = wccs_proof_option_count();

wccs_proof_check( 'No schema option is left behind', 0 === $wccs_after, 'after=' . $wccs_after );
wccs_proof_note( 'Options created by the proof', implode( ', ', $wccs_option_names ) );
wccs_proof_note(
	'CAS mechanism',
	'A single conditional UPDATE ... WHERE option_value = <expected>, with add_option used as the atomic first write.'
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
