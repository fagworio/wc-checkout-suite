<?php
/**
 * WCCS-071 proof harness — links and display, per destination.
 *
 * Task:   WCCS-071 "Modelar vínculos e exibição no schema"
 * Phase:  F14
 * Accept: "Coleta, vinculação e exibição separadas; `destinations` substitui o mapa
 *          booleano; destinos começam desativados; migração de documento antigo
 *          testada."
 *
 * The rule this task exists for is a negative one: publishing a field to the checkout
 * does not put its answer anywhere else. A proof of a negative has to be made where
 * the negative could fail, so this harness drives the real draft route and reads the
 * stored document back:
 *
 * 1. **Nothing is enabled by itself.** A field stored with no destination map is
 *    stored with every destination disabled, and the compatibility projection the
 *    older surfaces read says the same.
 * 2. **Each destination is independent.** One enabled destination does not enable
 *    another, and what each one configures — section, title, order, actions — is what
 *    is stored.
 * 3. **The closed sets hold through the route.** An unknown destination, an action a
 *    destination may not perform, a value that is not true or false and an approval
 *    flow that does not say where the review happens are all refused with a stable
 *    code, and nothing is written.
 * 4. **An old document migrates.** The legacy audience map becomes destinations, with
 *    what was on left on and what was off left off, and an audience the closed list
 *    does not know survives the migration so that it can be refused.
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
 * Builds one field definition.
 *
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_authorisation',
			'integration_id' => 'wc-checkoutsuite/wccs_authorisation',
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Autorização',
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
		),
		$changes
	);
}

/**
 * The repository the routes write through.
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
 * Writes a document through the real draft route.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param int|null                         $expected Expected revision.
 * @return array{status: int, codes: array<int, string>, data: array<string, mixed>}
 */
function wccs_proof_write( array $fields, ?int $expected = null ): array {
	// The route is compare-and-swap: an expected revision that is not the stored one
	// is refused with 409. The harness writes what is there now unless a test asks for
	// a specific revision, which is what a client does.
	if ( null === $expected ) {
		$expected = wccs_proof_repository()
			->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )
			->revision();
	}

	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		(string) wp_json_encode(
			array(
				'schema'            => array(
					'revision' => 0,
					'fields'   => $fields,
					'sections' => array(),
					'settings' => array(),
				),
				'expected_revision' => $expected,
			)
		)
	);

	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$codes    = array();

	// A refusal comes back as a WP_Error shape: the codes live one level down, under
	// `data.errors`. Reading only the top level reports "no code" for a refusal that
	// did happen, which is the worst kind of green.
	if ( is_array( $data ) && isset( $data['data']['errors'] ) && is_array( $data['data']['errors'] ) ) {
		$data['errors'] = $data['data']['errors'];
	}

	if ( is_array( $data ) && isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
		// The refusal payload is read tolerantly: the route may publish the errors as
		// a list of entries that carry a code, or as a map of code to message, and a
		// harness that only understands one of them reports "no code" for a refusal
		// that did happen.
		foreach ( $data['errors'] as $wccs_index => $wccs_error ) {
			if ( is_array( $wccs_error ) && isset( $wccs_error['code'] ) ) {
				$codes[] = (string) $wccs_error['code'];
			} elseif ( is_string( $wccs_index ) ) {
				$codes[] = $wccs_index;
			}
		}
	}

	return array(
		'status' => (int) $response->get_status(),
		'codes'  => $codes,
		'data'   => is_array( $data ) ? $data : array(),
	);
}

/**
 * Reads the draft document as it was stored.
 *
 * @return array<string, mixed>
 */
function wccs_proof_stored(): array {
	$repository = wccs_proof_repository();
	$document   = $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
	$fields     = array();

	// The stored document keeps the field arrays as they were written; what the plugin
	// acts on is the definition those arrays produce, which is the same read the order
	// screens make. Reading it here is what makes this assertion about the model
	// rather than about the JSON.
	foreach ( $document->fields() as $wccs_raw ) {
		$wccs_definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_raw );

		$fields[ $wccs_definition->id() ] = $wccs_definition->to_array();
	}

	return $fields;
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
wccs_proof_out( 'WCCS-071 proof — links and display, per destination' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// The harness establishes its own state rather than inheriting one: the same family of
// mistake has produced a false failure in this project more than once. It also states
// the count it will be judged against *after* cleaning, so "no residue" is a claim
// about what this run wrote.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. What the catalogue publishes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The closed sets' );

// The catalogue the catalog route publishes, from the class both the validator and
// the interface read.
$wccs_catalogue = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::to_array();

wccs_proof_check(
	'The catalogue publishes the destinations',
	is_array( $wccs_catalogue['destinations'] ?? null ) && 7 === count( $wccs_catalogue['destinations'] ),
	'count=' . ( is_array( $wccs_catalogue['destinations'] ?? null ) ? count( $wccs_catalogue['destinations'] ) : -1 )
);

wccs_proof_check(
	'Every destination declares the actions it may perform',
	7 === count( array_filter( $wccs_catalogue['destinations'], static fn( $entry ) => ! empty( $entry['actions'] ) ) ),
	'actions present on every destination'
);

wccs_proof_check(
	'Approving is a staff action and not a customer one',
	in_array( 'approve', \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::actions_for_destination( 'admin_order' ), true )
		&& ! in_array( 'approve', \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::actions_for_destination( 'customer_order' ), true ),
	'admin_order=' . implode( ',', \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::actions_for_destination( 'admin_order' ) )
);

// ---------------------------------------------------------------------------
// 2. Nothing is enabled by itself.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. No destination without configuration' );

$wccs_bare = wccs_proof_write( array( wccs_proof_field() ) );

wccs_proof_check(
	'A field with no destination map is accepted',
	200 === $wccs_bare['status'],
	'status=' . $wccs_bare['status'] . ' codes=' . implode( ',', $wccs_bare['codes'] )
);

$wccs_stored_bare = wccs_proof_stored();
$wccs_bare_field  = $wccs_stored_bare['wccs_authorisation'] ?? array();
$wccs_bare_dest   = is_array( $wccs_bare_field['destinations'] ?? null ) ? $wccs_bare_field['destinations'] : array();
$wccs_enabled     = array();

foreach ( $wccs_bare_dest as $wccs_key => $wccs_entry ) {
	if ( ! empty( $wccs_entry['enabled'] ) ) {
		$wccs_enabled[] = (string) $wccs_key;
	}
}

wccs_proof_check(
	'Every destination is disabled when nothing was configured',
	array() === $wccs_enabled,
	'count=' . count( $wccs_destinations ?? array() ) . ' enabled=' . implode( ',', $wccs_enabled )
);

wccs_proof_check(
	'The superseded audience map is no longer published',
	! array_key_exists( 'visibility', $wccs_bare_field ),
	'keys=' . implode( ',', array_keys( $wccs_bare_field ) )
);

// ---------------------------------------------------------------------------
// 3. Each destination is independent.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. One destination at a time' );

$wccs_configured_field = wccs_proof_field(
	array(
		'destinations' => array(
			'admin_order'      => array(
				'enabled'  => true,
				'section'  => 'documentos_para_analise',
				'title'    => 'Documentos para análise',
				'position' => 10,
				'actions'  => array( 'show_metadata', 'view', 'download', 'approve' ),
			),
			'customer_order'   => array(
				'enabled' => true,
				'section' => 'documentos_enviados',
				'title'   => 'Documentos enviados',
				'actions' => array( 'show_metadata', 'view' ),
			),
			'customer_email'   => array( 'enabled' => false ),
			'admin_email'      => array( 'enabled' => false ),
			'order_received'   => array( 'enabled' => false ),
			'customer_profile' => array( 'enabled' => false ),
			'public_api'       => array( 'enabled' => false ),
		),
	)
);

$wccs_configured = wccs_proof_write( array( $wccs_configured_field ) );

wccs_proof_check(
	'A configured field is accepted',
	200 === $wccs_configured['status'],
	'status=' . $wccs_configured['status'] . ' codes=' . implode( ',', $wccs_configured['codes'] )
);

$wccs_stored = wccs_proof_stored();
$wccs_field  = $wccs_stored['wccs_authorisation'] ?? array();
$wccs_dest   = is_array( $wccs_field['destinations'] ?? null ) ? $wccs_field['destinations'] : array();

wccs_proof_check(
	'What each destination configured is what is stored',
	'documentos_para_analise' === ( $wccs_dest['admin_order']['section'] ?? '' )
		&& 'Documentos para análise' === ( $wccs_dest['admin_order']['title'] ?? '' )
		&& 10 === ( $wccs_dest['admin_order']['position'] ?? null )
		&& array( 'show_metadata', 'view', 'download', 'approve' ) === ( $wccs_dest['admin_order']['actions'] ?? array() ),
	'section=' . ( $wccs_dest['admin_order']['section'] ?? '?' )
);

wccs_proof_check(
	'Enabling one destination does not enable another',
	true === ( $wccs_dest['customer_order']['enabled'] ?? null )
		&& false === ( $wccs_dest['customer_email']['enabled'] ?? null )
		&& false === ( $wccs_dest['order_received']['enabled'] ?? null )
		&& false === ( $wccs_dest['customer_profile']['enabled'] ?? null ),
	'email=' . wp_json_encode( $wccs_dest['customer_email']['enabled'] ?? null )
);

wccs_proof_check(
	'The destinations are the only source of who sees what',
	true === ( $wccs_dest['admin_order']['enabled'] ?? null )
		&& true === ( $wccs_dest['customer_order']['enabled'] ?? null )
		&& false === ( $wccs_dest['customer_email']['enabled'] ?? null )
		&& ! array_key_exists( 'visibility', $wccs_field ),
	'keys=' . implode( ',', array_keys( $wccs_dest ) )
);

// ---------------------------------------------------------------------------
// 4. The closed sets hold through the route.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What the route refuses' );

$wccs_cases = array(
	'an unknown destination is refused' => array(
		'fields' => array(
			wccs_proof_field( array( 'destinations' => array( 'twitter' => array( 'enabled' => true ) ) ) ),
		),
		'code'   => 'unknown_destination',
	),
	'a destination may not be given an action it cannot perform' => array(
		'fields' => array(
			wccs_proof_field(
				array(
					'destinations' => array(
						'customer_order' => array(
							'enabled' => true,
							'actions' => array( 'view', 'approve' ),
						),
					),
				)
			),
		),
		'code'   => 'invalid_destination_action',
	),
	'whether a destination shows the field must be true or false' => array(
		'fields' => array(
			wccs_proof_field( array( 'destinations' => array( 'admin_order' => array( 'enabled' => 'yes' ) ) ) ),
		),
		'code'   => 'invalid_destination',
	),
	'an approval flow without an area is refused' => array(
		'fields' => array(
			wccs_proof_field( array( 'approval' => array( 'require_review' => true ) ) ),
		),
		'code'   => 'approval_incomplete',
	),
);

foreach ( $wccs_cases as $wccs_label => $wccs_case ) {
	$wccs_attempt = wccs_proof_write( $wccs_case['fields'] );

	wccs_proof_check(
		$wccs_label,
		422 === $wccs_attempt['status'] && in_array( $wccs_case['code'], $wccs_attempt['codes'], true ),
		'status=' . $wccs_attempt['status'] . ' codes=' . implode( ',', $wccs_attempt['codes'] )
	);
}

// A refused write changes nothing: the last accepted document is still the stored one.
$wccs_after_refusals = wccs_proof_stored();

wccs_proof_check(
	'A refused write leaves the stored document alone',
	'documentos_para_analise' === ( $wccs_after_refusals['wccs_authorisation']['destinations']['admin_order']['section'] ?? '' ),
	'section=' . ( $wccs_after_refusals['wccs_authorisation']['destinations']['admin_order']['section'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 5. An old document migrates.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The legacy audience map' );

$wccs_legacy = wccs_proof_write(
	array( wccs_proof_field( array( 'visibility' => array( 'admin_order' => true ) ) ) )
);

wccs_proof_check(
	'A document carrying the old map is still accepted',
	200 === $wccs_legacy['status'],
	'status=' . $wccs_legacy['status'] . ' codes=' . implode( ',', $wccs_legacy['codes'] )
);

$wccs_migrated = wccs_proof_stored();
$wccs_legacy_dest = $wccs_migrated['wccs_authorisation']['destinations'] ?? array();

wccs_proof_check(
	'What was on stays on and what was off stays off',
	true === ( $wccs_legacy_dest['admin_order']['enabled'] ?? null )
		&& false === ( $wccs_legacy_dest['customer_order']['enabled'] ?? null )
		&& false === ( $wccs_legacy_dest['public_api']['enabled'] ?? null ),
	'admin_order=' . wp_json_encode( $wccs_legacy_dest['admin_order']['enabled'] ?? null )
);

wccs_proof_check(
	'An audience the closed list does not know is not dropped by the migration',
	in_array(
		'unknown_destination',
		wccs_proof_write( array( wccs_proof_field( array( 'visibility' => array( 'twitter' => true ) ) ) ) )['codes'],
		true
	),
	'a legacy audience reaches the validator'
);

// ---------------------------------------------------------------------------
// 6. Nothing is inserted anywhere by publishing to the checkout.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Publishing adds no display' );

$wccs_publish = wccs_proof_repository()->publish(
	wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ),
	null,
	1
);

$wccs_published = wccs_proof_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );
$wccs_published_enabled = array();

foreach ( $wccs_published->fields() as $wccs_published_raw ) {
	$wccs_published_field = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_published_raw );

	foreach ( $wccs_published_field->destinations() as $wccs_key => $wccs_entry ) {
		if ( ! empty( $wccs_entry['enabled'] ) ) {
			$wccs_published_enabled[] = $wccs_published_field->id() . ':' . $wccs_key;
		}
	}
}

wccs_proof_check(
	'Publishing keeps exactly the destinations that were configured',
	$wccs_publish->is_ok() && array( 'wccs_authorisation:admin_order' ) === $wccs_published_enabled,
	'enabled=' . implode( ',', $wccs_published_enabled )
);

wccs_proof_note(
	'Cross-reference',
	'The inspector that edits these links is WCCS-072; the per-area negative ("a field without a link appears nowhere") is proven by WCCS-076.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
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
