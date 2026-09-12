<?php
/**
 * WCCS-016 proof harness — the field picker, the CRUD and core protection.
 *
 * Task:   WCCS-016 "Criar Field Picker e CRUD"
 * Phase:  F03
 * Accept: "Criar/editar/duplicar/arquivar; campos core protegidos; busca por categorias."
 *
 * The client-side operations are pure functions proven by the JavaScript suite.
 * What this harness covers is what that suite cannot reach:
 *
 * 1. The picker's content comes from the registry, so a third-party type appears
 *    without anyone editing a list.
 * 2. The core protection is enforced at the persistence choke point, through the
 *    real REST route. A guard proven only by a unit test on a class would say
 *    nothing about whether the route actually consults it.
 * 3. A WooCommerce-owned field can still be customised, which is the whole point
 *    of the feature. Protection that also froze presentation would be a bug that
 *    a "refuses everything" test suite would happily call correct.
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
 * Reads a plugin file, returning an empty string when it is missing.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function wccs_proof_read( $relative ) {
	$path = WCCS_PLUGIN_DIR . $relative;

	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * Builds a definition array.
 *
 * @param string               $id      Field id.
 * @param string               $origin  `core` or `custom`.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $origin = 'custom', array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'core' === $origin ? $id : 'wc-checkoutsuite/' . $id,
			'origin'         => $origin,
			'type'           => 'text',
			'label'          => 'Label for ' . $id,
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
		'revision'      => $revision,
		'schema_version' => 1,
		'fields'        => $fields,
		'sections'      => array(),
		'settings'      => array(),
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

$wccs_root = rtrim( (string) WCCS_PLUGIN_DIR, '/' );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-016 proof — field picker, CRUD and core protection' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_options_before = wccs_proof_option_count();

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The picker is driven by the registry.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Field type catalogue' );

$wccs_types_response = rest_do_request( new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES ) );
$wccs_catalogue     = $wccs_types_response->get_data();

wccs_proof_check(
	'The field types route answers with 200',
	200 === $wccs_types_response->get_status(),
	'status=' . $wccs_types_response->get_status()
);

$wccs_registered = \WCCheckoutSuite\Domain\Registries::instance()->types()->keys();
$wccs_offered    = array_keys( (array) ( $wccs_catalogue['types'] ?? array() ) );

sort( $wccs_registered );
sort( $wccs_offered );

wccs_proof_check(
	'Every registered type is offered, and nothing else is',
	$wccs_registered === $wccs_offered,
	sprintf( '%d registered, %d offered', count( $wccs_registered ), count( $wccs_offered ) )
);

$wccs_categories = (array) ( $wccs_catalogue['categories'] ?? array() );

wccs_proof_check(
	'More than one category is offered, so the picker has something to search by',
	count( $wccs_categories ) >= 5,
	count( $wccs_categories ) . ' categories'
);

// A category is only useful if it is a partition: every type in exactly one.
$wccs_seen = array();

foreach ( $wccs_categories as $wccs_category ) {
	foreach ( (array) ( $wccs_category['types'] ?? array() ) as $wccs_entry ) {
		$wccs_seen[] = (string) ( $wccs_entry['key'] ?? '' );
	}
}

sort( $wccs_seen );

wccs_proof_check(
	'Categories partition the types: each appears exactly once',
	$wccs_seen === $wccs_offered,
	sprintf( '%d grouped, %d offered', count( $wccs_seen ), count( $wccs_offered ) )
);

$wccs_blank_categories = array();

foreach ( $wccs_categories as $wccs_category ) {
	if ( '' === (string) ( $wccs_category['label'] ?? '' ) ) {
		$wccs_blank_categories[] = (string) ( $wccs_category['key'] ?? '?' );
	}
}

// A third-party category gets a readable label derived from its key, so an
// extension cannot produce a group with no heading.
wccs_proof_check(
	'Every category has a label a person can read',
	array() === $wccs_blank_categories,
	$wccs_blank_categories ? 'blank: ' . implode( ', ', $wccs_blank_categories ) : 'all labelled'
);

$wccs_presets = (array) ( $wccs_catalogue['presets'] ?? array() );

wccs_proof_check(
	'Ready-made presets are offered to the picker',
	count( $wccs_presets ) >= 10,
	count( $wccs_presets ) . ' presets'
);

$wccs_preset_types = \WCCheckoutSuite\Domain\Registries::instance()->types();
$wccs_bad_presets  = array();

foreach ( $wccs_presets as $wccs_preset ) {
	$wccs_type_key = (string) ( $wccs_preset['type'] ?? '' );

	if ( ! $wccs_preset_types->has( $wccs_type_key ) ) {
		$wccs_bad_presets[] = (string) ( $wccs_preset['key'] ?? '?' ) . '->' . $wccs_type_key;
	}
}

// A preset built on a type that is not registered would produce a definition the
// validator rejects, so offering it would be offering a broken field.
wccs_proof_check(
	'Every offered preset is built on a registered type',
	array() === $wccs_bad_presets,
	$wccs_bad_presets ? implode( ', ', $wccs_bad_presets ) : 'all resolvable'
);

wccs_proof_note(
	'Categories',
	implode(
		', ',
		array_map(
			static function ( array $category ): string {
				return $category['label'] . '(' . count( (array) $category['types'] ) . ')';
			},
			$wccs_categories
		)
	)
);

// ---------------------------------------------------------------------------
// 2. The WooCommerce inventory is real.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. WooCommerce core field inventory' );

$wccs_core_response = rest_do_request( new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_CORE_FIELDS ) );
$wccs_inventory     = $wccs_core_response->get_data();

wccs_proof_check(
	'The core fields route answers with 200',
	200 === $wccs_core_response->get_status(),
	'status=' . $wccs_core_response->get_status()
);

wccs_proof_check(
	'The inventory is available on this store',
	true === ( $wccs_inventory['available'] ?? false ),
	'available=' . var_export( $wccs_inventory['available'] ?? null, true ) . ' reason=' . ( $wccs_inventory['reason'] ?? '' )
);

$wccs_core_fields = (array) ( $wccs_inventory['fields'] ?? array() );

wccs_proof_check(
	'It lists the fields WooCommerce actually has',
	count( $wccs_core_fields ) >= 10,
	count( $wccs_core_fields ) . ' fields across ' . count( (array) ( $wccs_inventory['sections'] ?? array() ) ) . ' sections'
);

$wccs_untyped     = array();
$wccs_unprotected = array();

foreach ( $wccs_core_fields as $wccs_field ) {
	if ( '' === (string) ( $wccs_field['type'] ?? '' ) ) {
		$wccs_untyped[] = (string) ( $wccs_field['id'] ?? '?' );
	}

	if ( true !== ( $wccs_field['protected'] ?? false ) ) {
		$wccs_unprotected[] = (string) ( $wccs_field['id'] ?? '?' );
	}
}

// Every core field is reported with a Suite type, otherwise the picker could not
// adopt it, and as protected, otherwise the admin would offer to delete it.
wccs_proof_check(
	'Every core field maps to a Suite type',
	array() === $wccs_untyped,
	$wccs_untyped ? implode( ', ', $wccs_untyped ) : 'all mapped'
);

wccs_proof_check(
	'Every core field is reported as protected',
	array() === $wccs_unprotected,
	$wccs_unprotected ? implode( ', ', $wccs_unprotected ) : 'all protected'
);

$wccs_uncatalogued = array();

foreach ( $wccs_core_fields as $wccs_field ) {
	if ( ! $wccs_preset_types->has( (string) $wccs_field['type'] ) ) {
		$wccs_uncatalogued[] = (string) $wccs_field['id'] . '->' . (string) $wccs_field['type'];
	}
}

wccs_proof_check(
	'Every mapped type is one the registry knows',
	array() === $wccs_uncatalogued,
	$wccs_uncatalogued ? implode( ', ', $wccs_uncatalogued ) : 'all known'
);

// ---------------------------------------------------------------------------
// 3. Protection is enforced through the real route.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Core protection through the draft route' );

$wccs_route = '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT;

/**
 * Sends a draft document through the real route.
 *
 * @param array<string, mixed> $document Document payload.
 * @param int                  $expected Expected revision.
 * @return WP_REST_Response
 */
function wccs_proof_save( array $document, int $expected ): WP_REST_Response {
	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( array( 'schema' => $document, 'expected_revision' => $expected ) ) );

	return rest_do_request( $request );
}

// Adopt a core field and a custom one, then confirm the store took them.
$wccs_first = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field( 'billing_first_name', 'core', array( 'required' => true ) ),
			wccs_proof_field( 'billing_document' ),
		),
		0
	),
	0
);

wccs_proof_check(
	'Adopting a WooCommerce field is accepted',
	200 === $wccs_first->get_status(),
	'status=' . $wccs_first->get_status() . ' ' . (string) wp_json_encode( $wccs_first->get_data() )
);

$wccs_stored = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_revision = $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

wccs_proof_check(
	'The draft now holds both fields',
	2 === count( $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields() ),
	'revision=' . $wccs_revision
);

/**
 * Extracts the machine codes of an error response.
 *
 * @param WP_REST_Response $response Response.
 * @return array<int, string>
 */
function wccs_proof_codes( WP_REST_Response $response ): array {
	$data   = $response->get_data();
	$codes  = array();
	$errors = is_array( $data ) && isset( $data['data']['errors'] ) ? (array) $data['data']['errors'] : array();

	foreach ( $errors as $error ) {
		if ( is_array( $error ) && isset( $error['code'] ) ) {
			$codes[] = (string) $error['code'];
		}
	}

	return $codes;
}

$wccs_removed = wccs_proof_save(
	wccs_proof_doc( array( wccs_proof_field( 'billing_document' ) ), $wccs_revision + 1 ),
	$wccs_revision
);

wccs_proof_check(
	'Removing a WooCommerce field is rejected with 422',
	422 === $wccs_removed->get_status(),
	'status=' . $wccs_removed->get_status()
);

wccs_proof_check(
	'The rejection names the rule that was broken',
	in_array( 'core_field_removed', wccs_proof_codes( $wccs_removed ), true ),
	implode( ', ', wccs_proof_codes( $wccs_removed ) )
);

wccs_proof_check(
	'Nothing was written by the rejected request',
	2 === count( $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields() ),
	'fields=' . count( $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields() )
);

$wccs_archived = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field( 'billing_first_name', 'core', array( 'required' => true, 'enabled' => false ) ),
			wccs_proof_field( 'billing_document' ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'Archiving a WooCommerce field is rejected',
	in_array( 'core_field_disabled', wccs_proof_codes( $wccs_archived ), true ),
	implode( ', ', wccs_proof_codes( $wccs_archived ) )
);

$wccs_retyped = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field( 'billing_first_name', 'core', array( 'required' => true, 'type' => 'hidden' ) ),
			wccs_proof_field( 'billing_document' ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'Changing what a WooCommerce field stores is rejected',
	in_array( 'core_field_type_changed', wccs_proof_codes( $wccs_retyped ), true ),
	implode( ', ', wccs_proof_codes( $wccs_retyped ) )
);

$wccs_relaxed = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field( 'billing_first_name', 'core', array( 'required' => false ) ),
			wccs_proof_field( 'billing_document' ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'Relaxing a requirement WooCommerce imposes is rejected',
	in_array( 'core_field_requirement_relaxed', wccs_proof_codes( $wccs_relaxed ), true ),
	implode( ', ', wccs_proof_codes( $wccs_relaxed ) )
);

// The obvious bypass: relabel the field as custom and then do as you like.
$wccs_laundered = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field( 'billing_first_name', 'custom', array( 'enabled' => false ) ),
			wccs_proof_field( 'billing_document' ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'Re-declaring a WooCommerce field as custom is rejected',
	in_array( 'core_field_origin_changed', wccs_proof_codes( $wccs_laundered ), true ),
	implode( ', ', wccs_proof_codes( $wccs_laundered ) )
);

// ---------------------------------------------------------------------------
// 4. Customisation is still possible, which is the point of the feature.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. A WooCommerce field can still be customised' );

$wccs_customised = wccs_proof_save(
	wccs_proof_doc(
		array(
			wccs_proof_field(
				'billing_first_name',
				'core',
				array(
					'required'  => true,
					'label'     => 'Nome',
					'section'   => 'shipping',
					'position'  => 1,
					'layout'    => array(
						'desktop' => 6,
						'tablet'  => 6,
						'mobile'  => 12,
					),
					'settings'  => array( 'placeholder' => 'Como no documento' ),
				)
			),
			wccs_proof_field( 'billing_document', 'custom', array( 'type' => 'select' ) ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'Renaming, moving and resizing a WooCommerce field is accepted',
	200 === $wccs_customised->get_status(),
	'status=' . $wccs_customised->get_status() . ' ' . (string) wp_json_encode( $wccs_customised->get_data() )
);

$wccs_after = $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_saved = array();

foreach ( $wccs_after->fields() as $wccs_field ) {
	$wccs_saved[ (string) $wccs_field['id'] ] = $wccs_field;
}

wccs_proof_check(
	'The core field kept its identity and its stored type',
	'billing_first_name' === ( $wccs_saved['billing_first_name']['integration_id'] ?? '' )
		&& 'text' === ( $wccs_saved['billing_first_name']['type'] ?? '' ),
	'integration_id=' . ( $wccs_saved['billing_first_name']['integration_id'] ?? '?' )
);

wccs_proof_check(
	'The core field took the new label and layout',
	'Nome' === ( $wccs_saved['billing_first_name']['label'] ?? '' )
		&& 6 === ( $wccs_saved['billing_first_name']['layout']['desktop'] ?? 0 ),
	'label=' . ( $wccs_saved['billing_first_name']['label'] ?? '?' )
);

// A custom field may be retyped, archived and removed freely. Protection that
// also froze custom fields would make the product useless.
$wccs_revision = $wccs_after->revision();

$wccs_custom_change = wccs_proof_save(
	wccs_proof_doc(
		array(
			$wccs_saved['billing_first_name'],
			wccs_proof_field( 'billing_document', 'custom', array( 'type' => 'select', 'enabled' => false ) ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'A custom field can still be retyped and archived',
	200 === $wccs_custom_change->get_status(),
	'status=' . $wccs_custom_change->get_status() . ' ' . (string) wp_json_encode( $wccs_custom_change->get_data() )
);

// ---------------------------------------------------------------------------
// 5. A forged core id is refused.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. A WooCommerce id cannot be claimed as custom' );

$wccs_revision = $wccs_stored->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

$wccs_forged = wccs_proof_save(
	wccs_proof_doc(
		array(
			$wccs_saved['billing_first_name'],
			wccs_proof_field( 'billing_document', 'custom', array( 'enabled' => false ) ),
			// `billing_email` is a real WooCommerce field that was never adopted.
			// Declaring it custom would let a later request disable it without
			// tripping the guard, which only protects what is stored as core.
			wccs_proof_field( 'billing_email', 'custom', array( 'type' => 'hidden' ) ),
		),
		$wccs_revision + 1
	),
	$wccs_revision
);

wccs_proof_check(
	'A WooCommerce id declared as a custom field is refused at the draft write',
	422 === $wccs_forged->get_status()
		&& in_array( 'core_field_origin_required', wccs_proof_codes( $wccs_forged ), true ),
	'status=' . $wccs_forged->get_status() . ' codes=' . implode( ', ', wccs_proof_codes( $wccs_forged ) )
);

// ---------------------------------------------------------------------------
// 6. The picker reads are published to the page.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Bootstrap wiring' );

$wccs_bootstrap = \WCCheckoutSuite\Admin\Assets::bootstrap_data();
$wccs_routes    = (array) ( $wccs_bootstrap['rest']['routes'] ?? array() );

wccs_proof_check(
	'The page receives the catalogue routes alongside the schema routes',
	isset( $wccs_routes['fieldTypes'], $wccs_routes['coreFields'], $wccs_routes['draft'] ),
	implode( ', ', array_keys( $wccs_routes ) )
);

wccs_proof_check(
	'The published paths are the ones the controllers register',
	\WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES === ( $wccs_routes['fieldTypes'] ?? '' )
		&& \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_CORE_FIELDS === ( $wccs_routes['coreFields'] ?? '' ),
	'field-types=' . ( $wccs_routes['fieldTypes'] ?? '?' )
);

$wccs_server = rest_get_server();
$wccs_known  = array_keys( $wccs_server->get_routes() );
$wccs_missing = array();

foreach ( $wccs_routes as $wccs_path ) {
	if ( ! in_array( '/' . WCCS_REST_NAMESPACE . $wccs_path, $wccs_known, true ) ) {
		$wccs_missing[] = (string) $wccs_path;
	}
}

wccs_proof_check(
	'Every published path is actually registered',
	array() === $wccs_missing,
	$wccs_missing ? 'unregistered: ' . implode( ', ', $wccs_missing ) : 'all registered'
);

$wccs_bundle = wccs_proof_read( 'build/admin/index.js' );

// The class names are the design's, taken from roadmap/fields.html: the picker draws
// `.picker-card` in a `.picker-grid` and the editor draws `.field-row`. Asserting the
// names this project used before the port would assert a bundle that no longer exists.
wccs_proof_check(
	'The field manager reached the shipped bundle',
	false !== strpos( $wccs_bundle, 'picker-card' )
		&& false !== strpos( $wccs_bundle, 'field-row' ),
	'picker and manager markup present'
);

wccs_proof_check(
	'The protection wording is in the bundle, not only in the source',
	false !== strpos( $wccs_bundle, 'shipping, tax and payment read it' ),
	'explanation shipped'
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

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
	'Identifier generation, duplication and purity are proven by tests/js/schema/fieldOperations.test.js (31 specs).'
);
wccs_proof_note(
	'Cross-reference',
	'Search by category is proven by tests/js/components/FieldPicker.test.js (21 specs).'
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
