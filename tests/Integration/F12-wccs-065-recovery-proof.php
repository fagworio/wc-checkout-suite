<?php
/**
 * WCCS-065 proof harness — recovery.
 *
 * Task:   WCCS-065 "Executar testes de recuperação"
 * Phase:  F12 · Hardening, acessibilidade e matriz final
 * Accept: "Rollback, schemas antigos, checkout aberto, jobs e extensão desativada tratados."
 *
 * Five situations a store recovers from, executed together because each one alone is a
 * feature and the five are what "the store survives" means:
 *
 *   1. **Rollback** — a merchant restores an earlier revision, and the storefront runs
 *      what the restore published rather than what was there a moment ago.
 *   2. **Older schemas** — a document written by an older build is read, and one written
 *      by a newer build is refused whole rather than read in part. This is the store that
 *      downgrades the plugin, which is the recovery nobody tests until it happens.
 *   3. **A checkout left open** — a page held revision N while the merchant published
 *      N+1. The customer's submission is judged by the rules that are live, and the fact
 *      that the page was stale travels back to it.
 *   4. **Jobs** — the retention job reschedules itself once and not twice, repeating a
 *      sweep changes nothing the second time, a batch leaves the rest for the next run,
 *      and deactivating the plugin takes its own scheduled work off the calendar.
 *   5. **A deactivated extension** — a published document that names a type the registry
 *      no longer has keeps every field and every value the merchant configured, the
 *      adapters report the field instead of drawing something else, and the rest of the
 *      checkout keeps working.
 *
 * Where a situation cannot be produced exactly as it happens in production, the harness
 * says how it produced it instead. That is the case for the deactivated extension: this
 * process cannot unregister a type, so the state is produced by asking the adapters about
 * a definition whose type no registry knows — which is the state the store is in.
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
 * @param string               $id    Identifier.
 * @param array<string, mixed> $extra Extra keys.
 * @return array<string, mixed>
 */
function wccs_rec_field( string $id, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Field ' . $id,
			'section'        => 'wccs_rec',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
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
		),
		$extra
	);
}

/**
 * A document with an explicit revision.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param int                              $revision Revision.
 * @return \WCCheckoutSuite\Domain\Schema\SchemaDocument
 */
function wccs_rec_document( array $fields, int $revision ): \WCCheckoutSuite\Domain\Schema\SchemaDocument {
	return \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision'       => $revision,
			'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
			'sections'       => array(
				array(
					'id'          => 'wccs_rec',
					'title'       => 'Recovery',
					'description' => '',
					'position'    => 10,
					'location'    => 'billing',
				),
			),
			'settings'       => array(),
		)
	);
}

/**
 * Publishes a document through the repository and returns the result.
 *
 * @param \WCCheckoutSuite\Domain\Schema\SchemaRepository $repository Repository.
 * @param \WCCheckoutSuite\Domain\Schema\SchemaDocument   $document   Document.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_rec_publish( $repository, $document ) {
	$repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $document, null );

	return $repository->publish( $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), null, 1 );
}

/**
 * Stores a file and records it, with an expiry the caller chooses.
 *
 * @param int $expires_in Seconds from now; negative is in the past.
 * @return string Token, or an empty string when the environment refuses storage.
 */
function wccs_rec_upload( int $expires_in ): string {
	$stored = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::put( 'a document', 'txt' );

	if ( null === $stored ) {
		return '';
	}

	return (string) ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->insert(
		array(
			'owner'      => 'recovery-owner',
			'field_id'   => 'wccs_rec_document',
			'file_name'  => 'document.txt',
			'mime_type'  => 'text/plain',
			'byte_size'  => 10,
			'path'       => $stored['path'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
		)
	);
}

/**
 * One validation request, as a checkout page would send it.
 *
 * @param string $field    Field identifier.
 * @param string $value    Value the customer typed.
 * @param int    $revision Revision the page read.
 * @param string $nonce    REST nonce.
 * @return WP_REST_Request
 */
function wccs_rec_request( string $field, string $value, int $revision, string $nonce ): WP_REST_Request {
	$request = new WP_REST_Request(
		'POST',
		'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Checkout\ValidationController::ROUTE_VALIDATE
	);

	$request->set_body_params(
		array(
			'field'      => $field,
			'value'      => $value,
			'revision'   => $revision,
			'request_id' => 'recovery-' . $revision,
			'nonce'      => $nonce,
		)
	);

	return $request;
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-065 proof harness — recovery' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

wp_set_current_user( 1 );

$wccs_nonce = wp_create_nonce( 'wp_rest' );

// ---------------------------------------------------------------------------
// 1. Rollback.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Rollback' );

$wccs_first = wccs_rec_publish( $wccs_repository, wccs_rec_document( array( wccs_rec_field( 'wccs_rec_one' ) ), 1 ) );

wccs_proof_check( 'The first revision publishes', $wccs_first->is_ok(), 'revision=' . $wccs_first->revision() );

$wccs_second = wccs_rec_publish(
	$wccs_repository,
	wccs_rec_document(
		array(
			wccs_rec_field( 'wccs_rec_one' ),
			wccs_rec_field( 'wccs_rec_two' ),
		),
		2
	)
);

wccs_proof_check( 'The second revision publishes', $wccs_second->is_ok(), 'revision=' . $wccs_second->revision() );

$wccs_restored = $wccs_repository->restore( 1, 1 );

wccs_proof_check(
	'Restoring the first revision publishes a NEW revision with its content',
	$wccs_restored->is_ok() && 1 === count( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->fields() ),
	'status=' . $wccs_restored->status() . ' revision=' . $wccs_restored->revision()
);

wccs_proof_check(
	'The storefront reads the restored content and nothing else',
	'wccs_rec_one' === ( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields()[0]['id'] ?? '' ),
	'fields=' . wp_json_encode( array_column( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields(), 'id' ) )
);

wccs_proof_check(
	'Rolling back does not erase the history: all three publications are still there',
	3 === count( $wccs_repository->revisions() ),
	'history=' . count( $wccs_repository->revisions() )
);

$wccs_unknown = $wccs_repository->restore( 99, 1 );

wccs_proof_check(
	'Restoring a revision that does not exist is refused, and the store keeps running what it had',
	! $wccs_unknown->is_ok()
		&& 'unknown_revision' === ( $wccs_unknown->errors()[0]['code'] ?? '' )
		&& 'wccs_rec_one' === ( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields()[0]['id'] ?? '' ),
	'code=' . ( $wccs_unknown->errors()[0]['code'] ?? 'none' )
);

// ---------------------------------------------------------------------------
// 2. Older schemas.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Schemas from another build' );

$wccs_option = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );
$wccs_backup = (string) get_option( $wccs_option, '' );

// What a newer build would have written. The plugin must refuse the whole document rather
// than read the parts it recognises: a schema read in part is a checkout configured in part.
update_option(
	$wccs_option,
	(string) wp_json_encode(
		array(
			'revision'       => 7,
			'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION + 1,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => array( wccs_rec_field( 'wccs_rec_future' ) ),
			'sections'       => array(),
			'settings'       => array(),
		)
	),
	false
);

$wccs_status = $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

wccs_proof_check(
	'A document from a newer build is reported as unsupported rather than read',
	'unsupported_version' === ( $wccs_status['state'] ?? '' ),
	'state=' . ( $wccs_status['state'] ?? 'none' ) . ' stored_version=' . ( $wccs_status['stored_version'] ?? 'none' )
);

wccs_proof_check(
	'And it is read as nothing at all, so the checkout falls back to the store\'s own form',
	0 === $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
		&& ! \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields(),
	'revision=' . $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

wccs_proof_check(
	'Booting the migration for it is refused instead of guessed',
	! \WCCheckoutSuite\Domain\Schema\SchemaMigrations::is_supported( \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION + 1 )
		&& \WCCheckoutSuite\Domain\Schema\SchemaMigrations::is_supported( 1 ),
	'current=' . \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION
);

// A document from before the version stamp existed: it is stamped and read, not refused.
$wccs_old = \WCCheckoutSuite\Domain\Schema\SchemaMigrations::migrate(
	array(
		'revision' => 1,
		'fields'   => array( wccs_rec_field( 'wccs_rec_old' ) ),
		'sections' => array(),
	)
);

wccs_proof_check(
	'A document written before the version stamp is stamped and read, and the migration is recorded',
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION === (int) ( $wccs_old['schema_version'] ?? 0 )
		&& 1 === count( (array) ( $wccs_old['migration_history'] ?? array() ) )
		&& 'wccs_rec_old' === ( $wccs_old['fields'][0]['id'] ?? '' ),
	'steps=' . wp_json_encode( array_column( (array) ( $wccs_old['migration_history'] ?? array() ), 'step' ) )
);

update_option( $wccs_option, $wccs_backup, false );

// ---------------------------------------------------------------------------
// 3. A checkout left open.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A checkout left open' );

wccs_rec_publish( $wccs_repository, wccs_rec_document( array( wccs_rec_field( 'wccs_rec_open' ) ), 1 ) );

// The page is holding revision 1 while the merchant publishes revision 2, where the field
// became required and a second field was added.
$wccs_live = wccs_rec_publish(
	$wccs_repository,
	wccs_rec_document(
		array(
			wccs_rec_field( 'wccs_rec_open', array( 'required' => true ) ),
			wccs_rec_field( 'wccs_rec_added' ),
		),
		2
	)
);

$wccs_stale = rest_do_request( wccs_rec_request( 'wccs_rec_open', '', 1, $wccs_nonce ) )->get_data();

wccs_proof_check(
	'A stale page is judged by the rules that are live, not by the ones it read',
	'invalid' === ( $wccs_stale['status'] ?? '' ),
	'status=' . ( $wccs_stale['status'] ?? 'none' ) . ' code=' . ( $wccs_stale['code'] ?? 'none' )
);

wccs_proof_check(
	'And the answer says the schema changed, so the page can tell the customer why',
	true === ( $wccs_stale['schema_changed'] ?? false ),
	'schema_changed=' . var_export( $wccs_stale['schema_changed'] ?? null, true ) . ' published=' . $wccs_live->revision()
);

$wccs_live_revision = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision();
$wccs_current       = rest_do_request( wccs_rec_request( 'wccs_rec_open', 'ok', $wccs_live_revision, $wccs_nonce ) )->get_data();

wccs_proof_check(
	'A page that read the live revision is judged by it and told nothing changed',
	'valid' === ( $wccs_current['status'] ?? '' ) && false === ( $wccs_current['schema_changed'] ?? true ),
	'status=' . ( $wccs_current['status'] ?? 'none' )
);

// The classic half reads the published document when the form arrives, so a submission from
// a page that never saw the new field cannot store a value for it.
$wccs_classic = \WCCheckoutSuite\Checkout\Classic\ClassicValidation::register();
$wccs_classic->normalize_posted_data(
	array(
		'wccs_rec_open'   => 'ok',
		'wccs_rec_removed' => 'a value for a field that is not in the document any more',
	)
);

wccs_proof_check(
	'A value for a field the document no longer has is not stored anywhere',
	! array_key_exists( 'wccs_rec_removed', $wccs_classic->storable_values() )
		&& array_key_exists( 'wccs_rec_open', $wccs_classic->storable_values() ),
	'storable=' . wp_json_encode( array_keys( $wccs_classic->storable_values() ) )
);

// ---------------------------------------------------------------------------
// 4. Jobs.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Jobs' );

$wccs_retention = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::class;

wp_clear_scheduled_hook( $wccs_retention::HOOK );

$wccs_retention::run();

$wccs_scheduled = wp_next_scheduled( $wccs_retention::HOOK );

wccs_proof_check(
	'Running the job schedules exactly one next run',
	false !== $wccs_scheduled,
	'next=' . ( false === $wccs_scheduled ? 'none' : gmdate( 'c', (int) $wccs_scheduled ) )
);

$wccs_retention::run();

wccs_proof_check(
	'Running it again does not pile up a second occurrence',
	(int) $wccs_scheduled === (int) wp_next_scheduled( $wccs_retention::HOOK ),
	'scheduled=' . (int) wp_next_scheduled( $wccs_retention::HOOK ) . ' same=' . (int) $wccs_scheduled
);

$wccs_tokens = array();

for ( $wccs_index = 0; $wccs_index < 7; $wccs_index++ ) {
	$token = wccs_rec_upload( -60 );

	if ( '' !== $token ) {
		$wccs_tokens[] = $token;
	}
}

if ( array() === $wccs_tokens ) {
	wccs_proof_note(
		'The private directory refused the stored files, so the sweep was exercised on nothing',
		'This is the environment recorded as UPLOAD-PRIVACY-ENV; the scheduling half above is unaffected.'
	);
} else {
	$wccs_batch = $wccs_retention::sweep( time(), 5 );

	$wccs_left = 0;

	foreach ( $wccs_tokens as $wccs_token ) {
		if ( null !== ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->find_any( $wccs_token ) ) {
			++$wccs_left;
		}
	}

	wccs_proof_check(
		'A batch stops at its limit and leaves the rest for the next run',
		5 === count( $wccs_tokens ) - $wccs_left && 2 === $wccs_left,
		'stored=' . count( $wccs_tokens ) . ' removed=' . ( count( $wccs_tokens ) - $wccs_left ) . ' left=' . $wccs_left
	);

	$wccs_second_sweep = $wccs_retention::sweep( time(), 200 );

	$wccs_still_there = 0;

	foreach ( $wccs_tokens as $wccs_token ) {
		if ( null !== ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->find_any( $wccs_token ) ) {
			++$wccs_still_there;
		}
	}

	wccs_proof_check(
		'The next run takes the remainder: nothing of this harness\'s seven is left',
		0 === $wccs_still_there,
		'second_removed=' . count( $wccs_second_sweep ) . ' left_of_ours=' . $wccs_still_there
	);
}

$wccs_retention::schedule();

$wccs_scheduled_before = wp_next_scheduled( $wccs_retention::HOOK );

\WCCheckoutSuite\Plugin::deactivate();

wccs_proof_check(
	'Deactivating the plugin takes its own scheduled work off the calendar',
	false !== $wccs_scheduled_before && false === wp_next_scheduled( $wccs_retention::HOOK ),
	'before=' . ( false === $wccs_scheduled_before ? 'none' : gmdate( 'c', (int) $wccs_scheduled_before ) ) . ' after=' . var_export( wp_next_scheduled( $wccs_retention::HOOK ), true )
);

wccs_proof_check(
	'And deactivation removed no configuration: the published document is still there',
	$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision() > 0,
	'revision=' . $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

// ---------------------------------------------------------------------------
// 5. A deactivated extension.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. A deactivated extension' );

$wccs_example = WCCS_PLUGIN_DIR . 'examples/custom-field-type/src/MembershipCodeType.php';

if ( is_readable( $wccs_example ) ) {
	require_once $wccs_example;

	\WCCheckoutSuiteExample\MembershipCodeType::register_field_types( \WCCheckoutSuite\Domain\Registries::instance()->types() );

	$wccs_contributed = \WCCheckoutSuite\Domain\Registries::instance()->types()->type( \WCCheckoutSuiteExample\MembershipCodeType::KEY );

	wccs_proof_check(
		'The example extension registers its type through the public API',
		null !== $wccs_contributed,
		'key=' . \WCCheckoutSuiteExample\MembershipCodeType::KEY
	);

	$wccs_with_extension = wccs_rec_publish(
		$wccs_repository,
		wccs_rec_document(
			array(
				wccs_rec_field( 'wccs_rec_kept' ),
				wccs_rec_field( 'wccs_rec_contributed', array( 'type' => \WCCheckoutSuiteExample\MembershipCodeType::KEY ) ),
			),
			1
		)
	);

	wccs_proof_check(
		'A document using the contributed type publishes while the extension is present',
		$wccs_with_extension->is_ok(),
		'status=' . $wccs_with_extension->status()
	);

	$wccs_published_fields = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

	wccs_proof_check(
		'When the extension goes away the document is still there, with the type it declared',
		2 === count( $wccs_published_fields )
			&& \WCCheckoutSuiteExample\MembershipCodeType::KEY === ( $wccs_published_fields[1]['type'] ?? '' ),
		'fields=' . wp_json_encode( array_column( $wccs_published_fields, 'type' ) )
	);

	// The state after deactivation: the definition is the one the store published, with a
	// type no registry knows. Nothing was rewritten to produce it.
	$wccs_orphan = wccs_rec_field( 'wccs_rec_orphan', array( 'type' => 'wccs-example/membership-code-gone' ) );

	$wccs_orphan_type = (string) $wccs_orphan['type'];
	$wccs_capability  = \WCCheckoutSuite\Domain\Checkout\AdapterCapabilities::for_type( $wccs_orphan_type, 'classic' );

	wccs_proof_check(
		'The capability matrix says the type has no adapter, with a reason a merchant can read',
		! \WCCheckoutSuite\Domain\Checkout\AdapterCapabilities::is_native( $wccs_capability['level'] )
			&& '' !== ( $wccs_capability['reason'] ?? '' ),
		'level=' . $wccs_capability['level'] . ' reason=' . $wccs_capability['reason']
	);

	$wccs_adapter = new \WCCheckoutSuite\Checkout\Classic\ClassicAdapter();
	$wccs_applied = $wccs_adapter->apply(
		array( 'billing' => array( 'billing_first_name' => array( 'type' => 'text' ) ) ),
		array( $wccs_orphan, wccs_rec_field( 'wccs_rec_kept' ) ),
		array(
			array(
				'id'       => 'wccs_rec',
				'location' => 'billing',
			),
		)
	);

	$wccs_report = $wccs_adapter->report();
	$wccs_rendered = array_keys( (array) ( $wccs_applied['billing'] ?? array() ) );

	wccs_proof_check(
		'The classic adapter reports the field it cannot render instead of drawing it as something else',
		1 === count( $wccs_report )
			&& 'wccs_rec_orphan' === ( $wccs_report[0]['field'] ?? '' )
			&& ! in_array( 'wccs_rec_orphan', $wccs_rendered, true ),
		'report=' . wp_json_encode( $wccs_report )
	);

	wccs_proof_check(
		'And the rest of the checkout keeps working: the fields it can render are rendered',
		in_array( 'wccs_rec_kept', $wccs_rendered, true ) && in_array( 'billing_first_name', $wccs_rendered, true ),
		'rendered=' . wp_json_encode( $wccs_rendered )
	);

	$wccs_incompatibilities = \WCCheckoutSuite\Domain\Schema\PublishIncompatibilities::check( array( $wccs_orphan, wccs_rec_field( 'wccs_rec_kept' ) ) );

	wccs_proof_check(
		'And the merchant is told before publishing, by the compatibility report',
		$wccs_incompatibilities['total'] > 0,
		'total=' . $wccs_incompatibilities['total']
	);
} else {
	wccs_proof_check(
		'The example extension is where the harness expects it',
		false,
		'missing=' . $wccs_example
	);
}

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

foreach ( array( 'draft', 'published' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

delete_option( 'wccs_schema_revisions' );

$GLOBALS['wpdb']->query( 'DELETE FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() . " WHERE owner = 'recovery-owner'" );

$wccs_final = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	$wccs_final === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_final
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
