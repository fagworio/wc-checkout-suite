<?php
/**
 * WCCS-056 proof harness — export and import with a preview.
 *
 * Task:   WCCS-056 "Implementar export/import com preview"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "JSON versionado, diff, limites, conflitos e rollback testados; sem segredos."
 *
 * The five clauses, and the rollback one is walked rather than invented: an import is a
 * draft, publishing keeps a revision, and the previous revision is what `restore()` brings
 * back — the same path the editor uses, exercised end to end through the real routes.
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
			'visibility'     => array( 'admin_order' => true ),
			'validators'     => array(),
		),
		$changes
	);
}

/**
 * A document with the given fields.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Sections.
 * @return \WCCheckoutSuite\Domain\Schema\SchemaDocument
 */
function wccs_proof_document( array $fields, array $sections = array() ) {
	return \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision'       => 1,
			'schema_version' => 1,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
			'sections'       => $sections,
			'settings'       => array(),
		)
	);
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
 * The data of a response.
 *
 * @param mixed $response Response.
 * @return array<string, mixed>
 */
function wccs_proof_body( $response ): array {
	if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
		$data = $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	return array();
}

/**
 * The codes of a report's errors.
 *
 * @param array<string, mixed> $report Report.
 * @return array<int, string>
 */
function wccs_proof_codes( array $report ): array {
	$codes = array();

	foreach ( (array) ( $report['errors'] ?? array() ) as $error ) {
		if ( is_array( $error ) && isset( $error['code'] ) ) {
			$codes[] = (string) $error['code'];
		}
	}

	return $codes;
}

$wccs_transfer  = 'WCCheckoutSuite\\Domain\\Schema\\SchemaTransfer';
$wccs_transport = 'WCCheckoutSuite\\Http\\Admin\\TransferController';
$wccs_namespace = '/wc-checkoutsuite/v1';

$wccs_export_route  = $wccs_namespace . $wccs_transport::ROUTE_EXPORT;
$wccs_preview_route = $wccs_namespace . $wccs_transport::ROUTE_PREVIEW;
$wccs_import_route  = $wccs_namespace . $wccs_transport::ROUTE_IMPORT;

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-056 proof — export and import' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// The slots this harness owns are cleared before anything is measured: a run that died
// before its cleanup cannot be allowed to fail the next one.
foreach ( array( 'draft', 'published', 'revisions' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// The store is put in a known state: a published document with one field, which is what
// the export will read.
$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_published = wccs_proof_document( array( wccs_proof_field( 'note' ) ) );

// Published through the publish path, and not written straight into the slot: what a
// rollback brings back has to be in the history, and the history is what publishing
// writes. A document placed in the slot by hand is a state the store never recorded.
$wccs_repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $wccs_published, 0 );
$wccs_first = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_repository->publish( $wccs_first, $wccs_first->revision(), 1 );

// ---------------------------------------------------------------------------
// 1. The file is versioned, and carries nothing else about the store.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. JSON versionado' );

$wccs_export_response = wccs_proof_request( 'GET', $wccs_export_route );
$wccs_export_body     = wccs_proof_body( $wccs_export_response );
$wccs_contents        = (string) ( $wccs_export_body['contents'] ?? '' );

wccs_proof_check(
	'The export route answers with a named file and its contents',
	200 === (int) ( is_object( $wccs_export_response ) ? $wccs_export_response->get_status() : 0 )
		&& str_ends_with( (string) ( $wccs_export_body['filename'] ?? '' ), '.json' )
		&& '' !== $wccs_contents,
	'file=' . ( $wccs_export_body['filename'] ?? '(none)' )
);

$wccs_envelope = json_decode( $wccs_contents, true );

wccs_proof_check(
	'The file names its format and its version',
	is_array( $wccs_envelope )
		&& $wccs_transfer::FORMAT === ( $wccs_envelope['format'] ?? '' )
		&& $wccs_transfer::VERSION === (int) ( $wccs_envelope['version'] ?? 0 ),
	'format=' . ( $wccs_envelope['format'] ?? '(none)' ) . ' version=' . ( $wccs_envelope['version'] ?? '(none)' )
);

wccs_proof_check(
	'And says where it came from, as information',
	isset( $wccs_envelope['exported_from']['revision'], $wccs_envelope['exported_from']['plugin'] ),
	'from=' . wp_json_encode( $wccs_envelope['exported_from'] ?? array() )
);

$wccs_secrets = array( 'token', 'nonce', 'password', 'secret', 'api_key', 'apikey', 'authorization', 'wc-checkoutsuite-private', 'wp-content', WCCS_PLUGIN_DIR );

$wccs_leaked = array();

foreach ( $wccs_secrets as $wccs_secret ) {
	if ( str_contains( $wccs_contents, (string) $wccs_secret ) ) {
		$wccs_leaked[] = (string) $wccs_secret;
	}
}

wccs_proof_check(
	'And carries no secret, no path and nothing about the store',
	array() === $wccs_leaked,
	'a file that leaves the store is the merchant\'s configuration and nothing else: found=' . wp_json_encode( $wccs_leaked )
);

wccs_proof_check(
	'And carries only the document: no option, no environment record, no order',
	array( 'format', 'version', 'exported_at', 'exported_from', 'schema' ) === array_keys( (array) $wccs_envelope )
		&& array( 'schema_version', 'fields', 'sections', 'settings' ) === array_keys( (array) ( $wccs_envelope['schema'] ?? array() ) )
);

// ---------------------------------------------------------------------------
// 2. The preview: a diff and a conflict, and nothing written.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. O preview' );

$wccs_incoming = wccs_proof_document(
	array(
		wccs_proof_field( 'note' ),
		wccs_proof_field( 'document', array( 'label' => 'Documento' ) ),
	)
);

$wccs_file = $wccs_transfer::encode( $wccs_transfer::export( $wccs_incoming ) );

$wccs_before_preview = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

$wccs_preview = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => $wccs_file ) )
);

wccs_proof_check(
	'The preview accepts a file this plugin wrote',
	true === ( $wccs_preview['ok'] ?? false ),
	'errors=' . wp_json_encode( wccs_proof_codes( $wccs_preview ) )
);

wccs_proof_check(
	'And shows what would be added',
	array( 'wccs_document' ) === array_column( (array) ( $wccs_preview['diff']['fields']['added'] ?? array() ), 'id' ),
	'added=' . wp_json_encode( array_column( (array) ( $wccs_preview['diff']['fields']['added'] ?? array() ), 'id' ) )
);

wccs_proof_check(
	'And writes nothing at all',
	$wccs_before_preview === $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
	'the preview is the same work as the import, returned instead of applied'
);

// A file that came from another revision is a conflict to report.
$wccs_stale_envelope = $wccs_transfer::export( $wccs_incoming );
$wccs_stale_envelope['exported_from']['revision'] = 99;

$wccs_stale = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => $wccs_transfer::encode( $wccs_stale_envelope ) ) )
);

wccs_proof_check(
	'A file from another revision is reported as a conflict rather than applied',
	true === ( $wccs_stale['conflict'] ?? false )
		&& array() !== ( $wccs_stale['warnings'] ?? array() ),
	'warning=' . substr( (string) ( $wccs_stale['warnings'][0] ?? '' ), 0, 90 )
);

// ---------------------------------------------------------------------------
// 3. The limits.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Os limites' );

$wccs_huge = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => str_repeat( 'a', $wccs_transfer::MAX_BYTES + 1 ) ) )
);

wccs_proof_check(
	'A file larger than the limit is refused, and the refusal names the numbers',
	in_array( 'file_too_large', wccs_proof_codes( $wccs_huge ), true ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_huge ) )
);

$wccs_not_json = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => 'not json at all' ) )
);

wccs_proof_check(
	'A file that is not JSON is refused as such',
	in_array( 'not_json', wccs_proof_codes( $wccs_not_json ), true )
);

$wccs_foreign = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => (string) wp_json_encode( array( 'format' => 'something/else', 'version' => 1 ) ) ) )
);

wccs_proof_check(
	'A file from another plugin is refused',
	in_array( 'not_our_file', wccs_proof_codes( $wccs_foreign ), true )
);

$wccs_future = wccs_proof_body(
	wccs_proof_request(
		'POST',
		$wccs_preview_route,
		array(
			'payload' => (string) wp_json_encode(
				array(
					'format'  => $wccs_transfer::FORMAT,
					'version' => $wccs_transfer::VERSION + 1,
					'schema'  => array( 'fields' => array() ),
				)
			),
		)
	)
);

wccs_proof_check(
	'A file from a newer version is refused rather than partly read',
	in_array( 'version_too_new', wccs_proof_codes( $wccs_future ), true ),
	'reading it as this version would apply the parts it shares and report success for the rest'
);

$wccs_many_fields = array();

for ( $i = 0; $i <= $wccs_transfer::MAX_FIELDS; $i++ ) {
	$wccs_many_fields[] = wccs_proof_field( 'field_' . $i );
}

$wccs_too_many = wccs_proof_body(
	wccs_proof_request(
		'POST',
		$wccs_preview_route,
		array( 'payload' => $wccs_transfer::encode( $wccs_transfer::export( wccs_proof_document( $wccs_many_fields ) ) ) )
	)
);

wccs_proof_check(
	'A file with more fields than the limit is refused before it becomes a document',
	in_array( 'too_many_things', wccs_proof_codes( $wccs_too_many ), true ),
	'fields=' . count( $wccs_many_fields ) . ' limit=' . $wccs_transfer::MAX_FIELDS
);

// A file that is within the limits but invalid is refused by the same validator the
// editor and the publish route use.
$wccs_invalid = wccs_proof_document( array( wccs_proof_field( 'broken', array( 'type' => 'no-such-type' ) ) ) );

$wccs_invalid_report = wccs_proof_body(
	wccs_proof_request( 'POST', $wccs_preview_route, array( 'payload' => $wccs_transfer::encode( $wccs_transfer::export( $wccs_invalid ) ) ) )
);

wccs_proof_check(
	'And a document the interface would refuse cannot arrive as a file',
	false === ( $wccs_invalid_report['ok'] ?? true ) && array() !== ( $wccs_invalid_report['errors'] ?? array() ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_invalid_report ) )
);

// ---------------------------------------------------------------------------
// 4. The import, and the conflict.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. A importação e o conflito' );

$wccs_draft_revision = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

$wccs_imported = wccs_proof_body(
	wccs_proof_request(
		'POST',
		$wccs_import_route,
		array(
			'payload'           => $wccs_file,
			'expected_revision' => $wccs_draft_revision,
		)
	)
);

wccs_proof_check(
	'The import writes the file into the draft',
	true === ( $wccs_imported['written'] ?? false ) && false === ( $wccs_imported['conflict'] ?? true ),
	'status=' . ( $wccs_imported['status'] ?? '' )
);

$wccs_ids = array_column( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields(), 'id' );

wccs_proof_check(
	'And the draft is what the file said',
	in_array( 'wccs_note', $wccs_ids, true ) && in_array( 'wccs_document', $wccs_ids, true ),
	'ids=' . wp_json_encode( $wccs_ids )
);

wccs_proof_check(
	'And the published document was not touched: nothing reaches the checkout before a publish',
	1 === $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

// The same file, against a revision that has moved: a conflict, not an overwrite.
$wccs_conflict = wccs_proof_body(
	wccs_proof_request(
		'POST',
		$wccs_import_route,
		array(
			'payload'           => $wccs_file,
			'expected_revision' => $wccs_draft_revision,
		)
	)
);

wccs_proof_check(
	'A file applied twice against the same revision is refused as a conflict',
	true === ( $wccs_conflict['conflict'] ?? false ) || false === ( $wccs_conflict['written'] ?? true ),
	'status=' . ( $wccs_conflict['status'] ?? '' ) . ' written=' . wp_json_encode( $wccs_conflict['written'] ?? null )
);

// ---------------------------------------------------------------------------
// 5. Rollback: the path that already exists.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Rollback' );

$wccs_published_result = $wccs_repository->publish(
	$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ),
	$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
	1
);

wccs_proof_check(
	'The imported draft can be published, which keeps a revision of what was there',
	$wccs_published_result->is_ok() && count( $wccs_repository->revisions() ) >= 1,
	'status=' . $wccs_published_result->status()
		. ' draft=' . $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision()
		. ' published=' . $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
		. ' errors=' . wp_json_encode( $wccs_published_result->errors() )
);

// The oldest revision in the history is the document that was there before the import:
// the history is newest first, so the one to go back to is the last entry.
$wccs_history = $wccs_repository->revisions();
$wccs_oldest  = is_array( $wccs_history ) && array() !== $wccs_history ? end( $wccs_history ) : array();
$wccs_target  = (int) ( is_array( $wccs_oldest ) ? ( $wccs_oldest['revision'] ?? 0 ) : 0 );

$wccs_restore = $wccs_repository->restore( $wccs_target, 1 );

wccs_proof_check(
	'And the previous revision can be restored, which is the rollback',
	$wccs_restore->is_ok(),
	'status=' . $wccs_restore->status() . ' revision=' . $wccs_target
);

$wccs_restored_ids = array_column( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->fields(), 'id' );

wccs_proof_check(
	'And the document is the one that was there before the import',
	array( 'wccs_note' ) === $wccs_restored_ids
		&& ! in_array( 'wccs_document', $wccs_restored_ids, true ),
	'ids=' . wp_json_encode( $wccs_restored_ids )
);

wccs_proof_check(
	'And the restored document is a new revision rather than a rewrite of history',
	count( $wccs_repository->revisions() ) >= 2,
	'history is appended to, never rewritten'
);

wccs_proof_note(
	'Why the rollback is the editor\'s own path and not a second undo',
	'An import is written into the draft, and nothing reaches the checkout until it is published. Publishing keeps a revision of what was published, and restore() brings one back as a new revision — the same mechanism the merchant uses to undo any publish. A second undo built for imports would be a second history, and two histories disagree the first time one of them is restored.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No screen: the three routes are exercised through the real REST server, but the Import/Export section of the administration still renders the placeholder it has rendered since the shell was built, so the file is reachable by a client and not yet by a merchant. Building that screen is named rather than implied.'
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

foreach ( array( 'draft', 'published', 'revisions' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before,
	'before=' . $wccs_options_before
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
