<?php
/**
 * WCCS-019 proof harness — draft, publication and the diff.
 *
 * Task:   WCCS-019 "Implementar draft e PublishDiff"
 * Phase:  F03
 * Accept: "Salvar draft não afeta a loja; publicação mostra diferenças e incompatibilidades."
 *
 * Both halves are claims about the server, so both are proven through the real
 * routes rather than through the domain classes the controller calls:
 *
 * 1. **"Salvar draft não afeta a loja"** — a document is published, a different
 *    draft is saved on top of it, and the *published* document is read back and
 *    compared. A draft write that reached the published slot would pass every
 *    unit test of the repository and would change a live checkout.
 * 2. **"diferenças e incompatibilidades"** — the diff route is read before and
 *    after publishing, and the three parts it returns are checked to be three
 *    different things: what changed, whether the schema is valid, and whether a
 *    checkout would honour it.
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
 * Builds a field definition.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
		),
		$changes
	);
}

/**
 * Builds a document payload.
 *
 * @param array<int, array<string, mixed>> $fields Fields.
 * @param int                              $revision Revision.
 * @return array<string, mixed>
 */
function wccs_proof_doc( array $fields, int $revision ): array {
	return array(
		'revision'       => $revision,
		'schema_version' => 1,
		'fields'         => $fields,
		'sections'       => array(),
		'settings'       => array(),
	);
}

/**
 * Sends a request and returns the response.
 *
 * @param string               $method HTTP method.
 * @param string               $path   Route path.
 * @param array<string, mixed> $body   Body parameters.
 * @return WP_REST_Response
 */
function wccs_proof_request( string $method, string $path, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/' . WCCS_REST_NAMESPACE . $path );

	if ( array() !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}

	return rest_do_request( $request );
}

/**
 * Saves a draft, using the revision the server currently holds.
 *
 * @param array<int, array<string, mixed>> $fields Fields.
 * @return WP_REST_Response
 */
function wccs_proof_save( array $fields ): WP_REST_Response {
	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	$revision = $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

	return wccs_proof_request(
		'PUT',
		\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT,
		array(
			'schema'            => wccs_proof_doc( $fields, $revision + 1 ),
			'expected_revision' => $revision,
		)
	);
}

/**
 * Reads a slot directly, so the harness can see what is actually stored.
 *
 * @param string $slot Slot name.
 * @return array<string, mixed>
 */
function wccs_proof_slot( string $slot ): array {
	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	return $repository->read( $slot )->to_array();
}

/**
 * Raw field list of a slot.
 *
 * @param string $slot Slot name.
 * @return array<int, array<string, mixed>>
 */
function wccs_proof_slot_fields( string $slot ): array {
	return (array) ( wccs_proof_slot( $slot )['fields'] ?? array() );
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

$wccs_options_before = wccs_proof_option_count();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-019 proof — draft, publication and the diff' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_routes = \WCCheckoutSuite\Http\Admin\SchemaController::routes();

// ---------------------------------------------------------------------------
// 1. The report is published to the page.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Route and bootstrap' );

wccs_proof_check(
	'The diff route is one of the schema routes',
	isset( $wccs_routes['diff'] ),
	implode( ', ', array_keys( $wccs_routes ) )
);

$wccs_bootstrap = \WCCheckoutSuite\Admin\Assets::bootstrap_data();
$wccs_published_routes = (array) ( $wccs_bootstrap['rest']['routes'] ?? array() );

wccs_proof_check(
	'The browser is given the route it needs to read the report',
	( $wccs_routes['diff'] ?? '' ) === ( $wccs_published_routes['diff'] ?? '' ),
	'diff=' . ( $wccs_published_routes['diff'] ?? 'missing' )
);

// ---------------------------------------------------------------------------
// 2. Saving a draft does not affect the store.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Saving a draft leaves the store alone' );

$wccs_first_save = wccs_proof_save( array( wccs_proof_field( 'billing_document', array( 'label' => 'CPF' ) ) ) );

wccs_proof_check(
	'The first draft is accepted',
	200 === $wccs_first_save->get_status(),
	'status=' . $wccs_first_save->get_status()
);

$wccs_publish = wccs_proof_request(
	'POST',
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_PUBLISH,
	array( 'expected_revision' => 1 )
);

wccs_proof_check(
	'It can be published',
	200 === $wccs_publish->get_status(),
	'status=' . $wccs_publish->get_status() . ' ' . (string) wp_json_encode( $wccs_publish->get_data() )
);

$wccs_published_before = wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

$wccs_changed = wccs_proof_save(
	array(
		wccs_proof_field( 'billing_document', array( 'label' => 'Documento' ) ),
		wccs_proof_field( 'billing_extra', array( 'position' => 20 ) ),
	)
);

wccs_proof_check(
	'A draft that changes two things is accepted',
	200 === $wccs_changed->get_status(),
	'status=' . $wccs_changed->get_status()
);

$wccs_published_after = wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

// This is the acceptance sentence, checked on the stored documents rather than on
// what the interface shows.
wccs_proof_check(
	'The published document is byte for byte what it was before the draft changed',
	$wccs_published_before === $wccs_published_after,
	'published revision=' . ( $wccs_published_after['revision'] ?? '?' )
		. ' fields=' . count( (array) ( $wccs_published_after['fields'] ?? array() ) )
);

wccs_proof_check(
	'The draft holds the change the store does not',
	2 === count( (array) ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['fields'] ?? array() ) ),
	'fields=' . count( (array) ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['fields'] ?? array() ) )
);

// ---------------------------------------------------------------------------
// 3. The report shows the differences.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Differences' );

$wccs_report_response = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF );
$wccs_report          = $wccs_report_response->get_data();

wccs_proof_check(
	'The report route answers with 200',
	200 === $wccs_report_response->get_status(),
	'status=' . $wccs_report_response->get_status()
);

$wccs_diff = (array) ( $wccs_report['diff'] ?? array() );

wccs_proof_check(
	'It reports the change to the existing field',
	array( 'billing_document' ) === array_column( (array) ( $wccs_diff['fields']['changed'] ?? array() ), 'id' ),
	implode( ', ', array_column( (array) ( $wccs_diff['fields']['changed'] ?? array() ), 'id' ) )
);

wccs_proof_check(
	'It reports the new field as an addition',
	array( 'billing_extra' ) === array_column( (array) ( $wccs_diff['fields']['added'] ?? array() ), 'id' ),
	implode( ', ', array_column( (array) ( $wccs_diff['fields']['added'] ?? array() ), 'id' ) )
);

wccs_proof_check(
	'The change names both sides of the label',
	'label' === ( $wccs_diff['fields']['changed'][0]['differences'][0]['key'] ?? '' )
		&& 'CPF' === ( $wccs_diff['fields']['changed'][0]['differences'][0]['from'] ?? '' )
		&& 'Documento' === ( $wccs_diff['fields']['changed'][0]['differences'][0]['to'] ?? '' ),
	(string) wp_json_encode( $wccs_diff['fields']['changed'][0]['differences'][0] ?? array() )
);

wccs_proof_check(
	'The two revisions being compared are named',
	1 === ( $wccs_diff['published']['revision'] ?? null )
		&& 2 === ( $wccs_diff['draft']['revision'] ?? null ),
	'published=' . ( $wccs_diff['published']['revision'] ?? '?' ) . ' draft=' . ( $wccs_diff['draft']['revision'] ?? '?' )
);

wccs_proof_check(
	'The report is not empty',
	false === ( $wccs_diff['empty'] ?? true ),
	'total=' . ( $wccs_diff['total_changes'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 4. An invalid draft is reported as invalid without being published.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Validations' );

$wccs_broken = wccs_proof_save(
	array(
		wccs_proof_field( 'billing_document', array( 'label' => 'Documento' ) ),
		wccs_proof_field( 'billing_broken', array( 'type' => 'does-not-exist', 'position' => 20 ) ),
	)
);

wccs_proof_check(
	'A draft naming an unregistered type is still stored, because drafts hold work in progress',
	200 === $wccs_broken->get_status(),
	'status=' . $wccs_broken->get_status()
);

$wccs_invalid_report = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )->get_data();

wccs_proof_check(
	'The report says the draft is not valid',
	false === ( $wccs_invalid_report['validation']['valid'] ?? true ),
	'valid=' . var_export( $wccs_invalid_report['validation']['valid'] ?? null, true )
);

$wccs_codes = array_column( (array) ( $wccs_invalid_report['validation']['errors'] ?? array() ), 'code' );

wccs_proof_check(
	'It names the rule that was broken',
	in_array( 'unknown_type', $wccs_codes, true ),
	implode( ', ', $wccs_codes )
);

$wccs_refused = wccs_proof_request(
	'POST',
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_PUBLISH,
	array( 'expected_revision' => 3 )
);

wccs_proof_check(
	'Publishing it is refused with 422',
	422 === $wccs_refused->get_status(),
	'status=' . $wccs_refused->get_status()
);

wccs_proof_check(
	'The published document is still the good one',
	1 === ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )['revision'] ?? null ),
	'revision=' . ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )['revision'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 5. Incompatibilities are a third, different answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Incompatibilities' );

wccs_proof_save( array( wccs_proof_field( 'billing_birthdate', array( 'type' => 'date' ) ) ) );

$wccs_compat = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )->get_data();
$wccs_incompat = (array) ( $wccs_compat['incompatibilities'] ?? array() );

wccs_proof_check(
	'A valid draft can still have incompatibilities',
	true === ( $wccs_compat['validation']['valid'] ?? false )
		&& ( $wccs_incompat['total'] ?? 0 ) > 0,
	'valid=' . var_export( $wccs_compat['validation']['valid'] ?? null, true ) . ' total=' . ( $wccs_incompat['total'] ?? '?' )
);

wccs_proof_check(
	'The Block checkout is told which fields it cannot render on its own',
	( $wccs_incompat['adapters']['blocks'][0]['field'] ?? '' ) === 'billing_birthdate'
		&& 'component' === ( $wccs_incompat['adapters']['blocks'][0]['level'] ?? '' ),
	(string) wp_json_encode( $wccs_incompat['adapters']['blocks'][0] ?? array() )
);

// Verified in WCCS-003: WooCommerce 11.1.0 registers text, select and checkbox
// as additional-field types. A date field is therefore not native there.
wccs_proof_check(
	'The Block incompatibility names the verified reason',
	false !== strpos( (string) ( $wccs_incompat['adapters']['blocks'][0]['reason'] ?? '' ), 'text, select and checkbox' ),
	(string) ( $wccs_incompat['adapters']['blocks'][0]['reason'] ?? '' )
);

wccs_proof_check(
	'The Classic checkout has none, because it renders through the plugin hooks',
	array() === ( $wccs_incompat['adapters']['classic'] ?? array() ),
	count( (array) ( $wccs_incompat['adapters']['classic'] ?? array() ) ) . ' entries'
);

wccs_proof_check(
	'The adapters are named for the interface',
	2 === count( (array) ( $wccs_compat['adapters'] ?? array() ) ),
	implode( ', ', array_column( (array) ( $wccs_compat['adapters'] ?? array() ), 'value' ) )
);

// An override whose WooCommerce field is gone. The inventory is filtered to
// simulate a field another plugin removed, which is the drift ADR-0001 exists to
// detect.
wccs_proof_save(
	array(
		wccs_proof_field(
			'billing_first_name',
			array(
				'origin'         => 'core',
				'integration_id' => 'billing_first_name',
			)
		),
	)
);

$wccs_hide = static function ( array $inventory ): array {
	$inventory['fields'] = array_values(
		array_filter(
			(array) ( $inventory['fields'] ?? array() ),
			static function ( array $field ): bool {
				return 'billing_first_name' !== ( $field['id'] ?? '' );
			}
		)
	);

	return $inventory;
};

add_filter( 'wccs_core_fields_inventory', $wccs_hide );

$wccs_drift = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )->get_data();
$wccs_store_entries = (array) ( $wccs_drift['incompatibilities']['store'] ?? array() );

remove_filter( 'wccs_core_fields_inventory', $wccs_hide );

wccs_proof_check(
	'An override whose WooCommerce field is gone is reported',
	'store' === ( $wccs_store_entries[0]['kind'] ?? '' )
		&& 'billing_first_name' === ( $wccs_store_entries[0]['field'] ?? '' ),
	(string) wp_json_encode( $wccs_store_entries[0] ?? array() )
);

$wccs_clean_drift = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )->get_data();

wccs_proof_check(
	'With the field back, the drift is gone',
	array() === (array) ( $wccs_clean_drift['incompatibilities']['store'] ?? array() ),
	'entries=' . count( (array) ( $wccs_clean_drift['incompatibilities']['store'] ?? array() ) )
);

// ---------------------------------------------------------------------------
// 6. Publishing clears the differences and keeps the history.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Publishing' );

// The next draft is built from what is published, the way a client does: it
// sends the whole document back. Sending only the field it cares about would
// remove every core override, which the guard refuses — correctly.
$wccs_carried = wccs_proof_slot_fields( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

foreach ( $wccs_carried as $wccs_index => $wccs_field ) {
	if ( 'billing_document' === ( $wccs_field['id'] ?? '' ) ) {
		$wccs_carried[ $wccs_index ]['label'] = 'Documento';
	}
}

wccs_proof_save( $wccs_carried );

$wccs_draft_revision = (int) ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['revision'] ?? 0 );

$wccs_ok = wccs_proof_request(
	'POST',
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_PUBLISH,
	array( 'expected_revision' => $wccs_draft_revision )
);

wccs_proof_check(
	'Publishing a valid draft is accepted',
	200 === $wccs_ok->get_status(),
	'status=' . $wccs_ok->get_status()
);

$wccs_after_publish = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )->get_data();

wccs_proof_check(
	'The report becomes empty once the draft is published',
	true === ( $wccs_after_publish['diff']['empty'] ?? false ),
	'total=' . ( $wccs_after_publish['diff']['total_changes'] ?? '?' )
);

$wccs_history = wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_REVISIONS )->get_data();
$wccs_entries = (array) ( $wccs_history['revisions'] ?? array() );

wccs_proof_check(
	'The publication is recorded in the history',
	count( $wccs_entries ) >= 2,
	count( $wccs_entries ) . ' revisions'
);

wccs_proof_check(
	'The history does not leak the stored documents',
	! isset( $wccs_entries[0]['document'] ),
	implode( ', ', array_keys( (array) ( $wccs_entries[0] ?? array() ) ) )
);

// ---------------------------------------------------------------------------
// 7. Restoring publishes again rather than rewriting history.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Restore' );

// One more publication first. Restoring a revision from before a WooCommerce
// field was adopted is refused by the core guard — correctly, and for a reason
// that has nothing to do with restoring. What this section is about is that a
// restore publishes again instead of erasing, so it needs a previous revision
// that carries the same core overrides.
$wccs_final = wccs_proof_slot_fields( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

foreach ( $wccs_final as $wccs_index => $wccs_field ) {
	if ( 'billing_document' === ( $wccs_field['id'] ?? '' ) ) {
		$wccs_final[ $wccs_index ]['label'] = 'Documento final';
	}
}

wccs_proof_save( $wccs_final );

wccs_proof_request(
	'POST',
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_PUBLISH,
	array( 'expected_revision' => (int) ( wccs_proof_slot( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['revision'] ?? 0 ) )
);

$wccs_entries        = (array) ( wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_REVISIONS )->get_data()['revisions'] ?? array() );
$wccs_before_restore = count( $wccs_entries );
$wccs_previous       = (int) ( $wccs_entries[1]['revision'] ?? $wccs_entries[0]['revision'] ?? 0 );

$wccs_restored = wccs_proof_request(
	'POST',
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_RESTORE,
	array( 'revision' => $wccs_previous )
);

wccs_proof_check(
	'An earlier revision can be published again',
	200 === $wccs_restored->get_status(),
	'status=' . $wccs_restored->get_status() . ' ' . (string) wp_json_encode( $wccs_restored->get_data() )
);

$wccs_after_restore = (array) ( wccs_proof_request( 'GET', \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_REVISIONS )->get_data()['revisions'] ?? array() );

wccs_proof_check(
	'The history grew instead of losing an entry',
	count( $wccs_after_restore ) > $wccs_before_restore,
	$wccs_before_restore . ' -> ' . count( $wccs_after_restore )
);

wccs_proof_check(
	'The revision that was restored is still in the history, untouched',
	in_array( $wccs_previous, array_map( static fn( array $entry ): int => (int) $entry['revision'], $wccs_after_restore ), true ),
	'revision ' . $wccs_previous
);

// ---------------------------------------------------------------------------
// 8. What the shipped screen offers, and what it no longer offers.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Build output' );

$wccs_built_js  = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.js' );
$wccs_built_css = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.css' );

// The save and the publication are one action for the merchant
// (`roadmap/ESPECIFICACAO-SECOES-WCCS.md` §16). The REST contract this harness proves
// above is unchanged — the draft still exists, and publishing is still what writes the
// published slot — so what is checked here is the interface: one button that does both,
// and no vocabulary of a two-step flow for the merchant to read.
wccs_proof_check(
	'The single save action reached the shipped bundle',
	false !== strpos( $wccs_built_js, 'Salvar alterações' )
		&& false !== strpos( $wccs_built_js, 'Alterações salvas com sucesso.' ),
	'save wording present'
);

wccs_proof_check(
	'And the draft-and-publication vocabulary did not',
	false === strpos( $wccs_built_js, 'Revisar publicação' )
		&& false === strpos( $wccs_built_js, 'Publicar alterações' )
		&& false === strpos( $wccs_built_js, 'do not block publication' )
		&& false === strpos( $wccs_built_js, 'Corrija antes de publicar' ),
	'no review step is offered'
);

wccs_proof_check(
	'The history reached the bundle, because a revision can still be restored',
	false !== strpos( $wccs_built_js, 'history-row' )
		&& false !== strpos( $wccs_built_css, 'history-row' ),
	'history markup and styles present'
);

// ---------------------------------------------------------------------------
// 9. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '9. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Cross-reference',
	'The three parts of the panel are proven by tests/js/components/PublishPanel.test.js (19 specs) and the history by RevisionsList.test.js (12 specs).'
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
