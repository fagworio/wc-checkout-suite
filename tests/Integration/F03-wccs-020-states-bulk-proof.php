<?php
/**
 * WCCS-020 proof harness — states, bulk actions and local undo.
 *
 * Task:   WCCS-020 "Criar estados e ações em lote"
 * Phase:  F03 (última tarefa da fase)
 * Accept: "Vazio, erro, rede, conflito e permissão tratados; desfazer local e revisão separados."
 *
 * The acceptance splits cleanly between the two sides, and this harness is
 * explicit about which half it owns:
 *
 * 1. **Empty, unsupported version, corrupt, permission and conflict** are server
 *    states, and are proven here through the real routes.
 * 2. **Network failure, local undo and bulk actions** are client behaviour, and
 *    are proven by the JavaScript suite. What this harness adds is that a bulk
 *    change is accepted as an ordinary draft write, and that the components
 *    reached the shipped bundle.
 *
 * The rule that ties them together is "falha não descarta trabalho": every
 * rejection below is followed by a check that nothing was written.
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
 * @param string $id Identifier.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id ): array {
	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Label ' . $id,
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
	);
}

/**
 * Repository under test.
 *
 * @return \WCCheckoutSuite\Domain\Schema\SchemaRepository
 */
function wccs_proof_repository(): \WCCheckoutSuite\Domain\Schema\SchemaRepository {
	return new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);
}

/**
 * Raw field identifiers stored in a slot.
 *
 * @param string $slot Slot name.
 * @return array<int, string>
 */
function wccs_proof_slot_ids( string $slot ): array {
	$fields = wccs_proof_repository()->read( $slot )->fields();

	return array_values( array_map( static fn( array $f ): string => (string) ( $f['id'] ?? '' ), $fields ) );
}

/**
 * Sends a draft write.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param int                              $expected Expected revision.
 * @return WP_REST_Response
 */
function wccs_proof_save( array $fields, int $expected ): WP_REST_Response {
	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		(string) wp_json_encode(
			array(
				'schema'            => array(
					'revision'       => $expected + 1,
					'schema_version' => 1,
					'fields'         => $fields,
					'sections'       => array(),
					'settings'       => array(),
				),
				'expected_revision' => $expected,
			)
		)
	);

	return rest_do_request( $request );
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
$wccs_draft_option   = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-020 proof — states, bulk actions and local undo' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. Empty.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Empty' );

$wccs_repository = wccs_proof_repository();

wccs_proof_check(
	'A slot with nothing in it reports itself as absent, not as broken',
	'absent' === $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['state'],
	(string) wp_json_encode( $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) )
);

wccs_proof_check(
	'An absent draft reads as revision 0 with no fields',
	0 === $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision()
		&& array() === wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )
);

$wccs_report = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )
)->get_data();

wccs_proof_check(
	'The report says the draft slot is empty',
	'absent' === ( $wccs_report['storage']['draft']['state'] ?? '' ),
	(string) wp_json_encode( $wccs_report['storage']['draft'] ?? array() )
);

// ---------------------------------------------------------------------------
// 2. Versão não suportada, which used to look exactly like empty.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Unsupported version' );

$wccs_empty = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->to_array();

$wccs_newer = $wccs_empty;
$wccs_newer['revision']       = 6;
$wccs_newer['schema_version'] = \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION + 1;
$wccs_newer['fields']         = array( wccs_proof_field( 'billing_x' ) );

update_option( $wccs_draft_option, (string) wp_json_encode( $wccs_newer ), false );

$wccs_status = $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

wccs_proof_check(
	'A document from a newer build is reported as an unsupported version',
	'unsupported_version' === ( $wccs_status['state'] ?? '' )
		&& \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION + 1 === ( $wccs_status['stored_version'] ?? null ),
	(string) wp_json_encode( $wccs_status )
);

// This is the honesty point. read() answers an unreadable document with an empty
// one, which every other caller wants and which the merchant must not be shown.
wccs_proof_check(
	'Reading it still answers with an empty document, so the two have to be told apart',
	0 === $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision()
);

$wccs_report = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )
)->get_data();

wccs_proof_check(
	'The report carries the version so the screen can stop saying "no fields yet"',
	'unsupported_version' === ( $wccs_report['storage']['draft']['state'] ?? '' ),
	'stored_version=' . ( $wccs_report['storage']['draft']['stored_version'] ?? '?' )
);

// A readable document is reported as readable, so the state is not always "bad".
delete_option( $wccs_draft_option );
wccs_proof_save( array( wccs_proof_field( 'billing_a' ) ), 0 );

wccs_proof_check(
	'A readable document is reported as readable',
	'readable' === wccs_proof_repository()->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['state'],
	(string) wp_json_encode( wccs_proof_repository()->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) )
);

// ---------------------------------------------------------------------------
// 3. Corrupt.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Corrupt storage' );

update_option( $wccs_draft_option, 'not json at all', false );

wccs_proof_check(
	'Unreadable bytes are reported as corrupt rather than as empty',
	'corrupt' === wccs_proof_repository()->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )['state'],
	(string) json_encode( wccs_proof_repository()->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) )
);

wccs_proof_check(
	'A corrupt document still reads as an empty one instead of failing',
	0 === wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision()
);

delete_option( $wccs_draft_option );

// ---------------------------------------------------------------------------
// 4. Permission.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Permission' );

wp_set_current_user( 0 );

$wccs_anonymous = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )
);

wccs_proof_check(
	'A request with no user is refused',
	401 === $wccs_anonymous->get_status(),
	'status=' . $wccs_anonymous->get_status()
);

$wccs_revision_before = wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

// The capability is denied with a filter rather than by editing a role: this is
// exactly how WordPress answers the question, and it leaves nothing behind.
$wccs_deny = static function ( array $allcaps ): array {
	$allcaps['manage_woocommerce'] = false;

	return $allcaps;
};

wp_set_current_user( 1 );
add_filter( 'user_has_cap', $wccs_deny );

$wccs_forbidden = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF )
);

$wccs_controller = new \WCCheckoutSuite\Http\Admin\SchemaController( wccs_proof_repository() );
$wccs_callback   = $wccs_controller->can_manage();

remove_filter( 'user_has_cap', $wccs_deny );

wccs_proof_check(
	'An authenticated user without the capability is refused with 403',
	403 === $wccs_forbidden->get_status(),
	'status=' . $wccs_forbidden->get_status()
);

wccs_proof_check(
	'The permission callback explains the refusal instead of answering false',
	is_wp_error( $wccs_callback ) && 'wccs_forbidden' === $wccs_callback->get_error_code(),
	is_wp_error( $wccs_callback ) ? $wccs_callback->get_error_code() : 'not an error'
);

wccs_proof_check(
	'With the capability back, the same request is allowed again',
	200 === rest_do_request( new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF ) )->get_status()
);

// ---------------------------------------------------------------------------
// 5. Conflict.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Conflict' );

// The state is established here rather than inherited. Section 3 removed the
// draft, so starting from whatever is left would be testing the leftovers — the
// mistake this harness has already made once in another shape.
$wccs_seed = wccs_proof_save( array( wccs_proof_field( 'billing_a' ) ), 0 );

wccs_proof_check(
	'A draft to conflict over is stored',
	200 === $wccs_seed->get_status(),
	'status=' . $wccs_seed->get_status()
);

$wccs_seen = wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

// Someone else saves first.
wccs_proof_save( array( wccs_proof_field( 'billing_a' ), wccs_proof_field( 'billing_other' ) ), $wccs_seen );

$wccs_winner = wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();
$wccs_before = wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

// The merchant's request was built against the revision they read, which has
// since moved.
$wccs_conflict = wccs_proof_save( array( wccs_proof_field( 'billing_a' ), wccs_proof_field( 'billing_mine' ) ), $wccs_seen );

wccs_proof_check(
	'A write against a revision that has moved is refused with 409',
	409 === $wccs_conflict->get_status(),
	'status=' . $wccs_conflict->get_status() . ' ' . (string) wp_json_encode( $wccs_conflict->get_data() )
);

wccs_proof_check(
	'The refusal names the revision that won',
	$wccs_winner === (int) ( $wccs_conflict->get_data()['data']['current_revision'] ?? 0 ),
	'current_revision=' . ( $wccs_conflict->get_data()['data']['current_revision'] ?? '?' )
);

// "Falha não descarta trabalho" has a server half: a refused write writes nothing.
wccs_proof_check(
	'The refused write changed nothing at all',
	$wccs_before === wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ),
	'before=' . implode( ', ', $wccs_before ) . ' after=' . implode( ', ', wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) )
);

wccs_proof_check(
	"The writer who lost did not get their field in, and the winner's is still there",
	! in_array( 'billing_mine', wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), true )
		&& in_array( 'billing_other', wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), true ),
	implode( ', ', wccs_proof_slot_ids( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) )
);

wccs_proof_check(
	'The merchant can still save by acknowledging the new revision',
	200 === wccs_proof_save(
		array( wccs_proof_field( 'billing_a' ), wccs_proof_field( 'billing_other' ), wccs_proof_field( 'billing_mine' ) ),
		$wccs_winner
	)->get_status()
);

// ---------------------------------------------------------------------------
// 6. Bulk changes are ordinary draft writes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Bulk' );

$wccs_bulk_revision = wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

$wccs_archived = wccs_proof_field( 'billing_a' );
$wccs_archived['enabled'] = false;

$wccs_moved = wccs_proof_field( 'billing_other' );
$wccs_moved['section'] = 'shipping';

$wccs_bulk = wccs_proof_save(
	array( $wccs_archived, $wccs_moved, wccs_proof_field( 'billing_mine' ) ),
	$wccs_bulk_revision
);

wccs_proof_check(
	'One write can archive, move and keep fields at once',
	200 === $wccs_bulk->get_status(),
	'status=' . $wccs_bulk->get_status()
);

$wccs_stored = array();

foreach ( wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields() as $wccs_field ) {
	$wccs_stored[ (string) $wccs_field['id'] ] = $wccs_field;
}

wccs_proof_check(
	'The archived field is stored disabled',
	false === ( $wccs_stored['billing_a']['enabled'] ?? true ),
	'x'
);

wccs_proof_check(
	'The moved field is stored in its new section',
	'shipping' === ( $wccs_stored['billing_other']['section'] ?? '' ),
	(string) ( $wccs_stored['billing_other']['section'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 7. The states reached the bundle.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Build output' );

$wccs_built_js = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.js' );

wccs_proof_check(
	'Local undo reached the shipped bundle, worded apart from the revision history',
	false !== strpos( $wccs_built_js, 'Undo edit' )
		&& false !== strpos( $wccs_built_js, 'Redo edit' )
		&& false !== strpos( $wccs_built_js, 'Publication history' ),
	'undo and history both present'
);

wccs_proof_check(
	'The bulk confirmation reached the bundle',
	false !== strpos( $wccs_built_js, 'selected field(s) will change' )
		&& false !== strpos( $wccs_built_js, 'Left alone because WooCommerce owns them' ),
	'impact wording present'
);

wccs_proof_check(
	'The unsupported-version state reached the bundle',
	false !== strpos( $wccs_built_js, 'written by a newer version' ),
	'state wording present'
);

wccs_proof_check(
	'The missing-extension state reached the bundle',
	false !== strpos( $wccs_built_js, 'no longer available' ),
	'state wording present'
);

// ---------------------------------------------------------------------------
// 7b. A tab has a linkable address.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7b. Section in the address bar' );

// The behaviour — which section a given query opens, and that the address is
// rewritten as the merchant moves — is proven by the JavaScript suite, which has
// a document. What can be checked here is that the code reached the browser at
// all: without it a link would open the first tab regardless of what it named.
wccs_proof_check(
	'The address is read and rewritten in the shipped bundle',
	false !== strpos( $wccs_built_js, 'URLSearchParams' )
		&& false !== strpos( $wccs_built_js, 'replaceState' ),
	'the screen can parse and rewrite the query'
);

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

delete_option( $wccs_draft_option );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Cross-reference',
	'The five classified states are proven by tests/js/schema/failureState.test.js (22 specs).'
);
wccs_proof_note(
	'Cross-reference',
	'Local undo is proven by tests/js/schema/useDocumentHistory.test.js (14 specs), bulk operations by fieldOperations.test.js and the confirmation by BulkActions.test.js (13 specs).'
);
wccs_proof_note(
	'Not simulated here',
	'A network failure cannot be produced from inside the server; it is proven in the client suite by the transport branch of the classifier.'
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
