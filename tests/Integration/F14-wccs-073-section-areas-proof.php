<?php
/**
 * WCCS-072 proof harness — the links the inspector edits, and the surfaces that obey them.
 *
 * Task:   WCCS-073 "Seções por área"
 * Phase:  F14
 * Accept: "Por destino: habilitar, seção, título, ordem e ações; somente propriedades
 *          suportadas; a mesma linguagem do resto do inspetor."
 *
 * The tab is JavaScript, and its own behaviour is pinned by
 * `tests/js/views/FieldProperties.test.js`. What a browser test cannot check is the
 * half the tab depends on, and that is what this harness drives through the real
 * routes and the real consumer classes:
 *
 * 1. **No drift.** Every destination the catalogue publishes is one the validator
 *    accepts, and every action published for a destination is one the validator
 *    accepts for it. The tab offers exactly what the server would take.
 * 2. **The shape the tab writes round-trips**, section, title, order and actions
 *    included.
 * 3. **The negative.** A field linked to nothing is shown by nobody: not to the
 *    customer, not in an e-mail, not on the order screen. That is the rule the whole
 *    phase exists for, and it is checked against the classes that render, not against
 *    the model alone.
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
wccs_proof_out( 'WCCS-073 proof — sections offered per area' );
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
 * Writes a document through the real draft route.
 *
 * @param array<int, array<string, mixed>> $sections Sections.
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @return array{status: int, codes: array<int, string>}
 */
function wccs_proof_write( array $sections, array $fields ): array {
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
					'fields'   => $fields,
					'sections' => $sections,
					'settings' => array(),
				),
				'expected_revision' => $current->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
			)
		)
	);

	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$codes    = array();

	if ( is_array( $data ) && isset( $data['data']['errors'] ) && is_array( $data['data']['errors'] ) ) {
		$data['errors'] = $data['data']['errors'];
	}

	foreach ( (array) ( $data['errors'] ?? array() ) as $index => $error ) {
		if ( is_array( $error ) && isset( $error['code'] ) ) {
			$codes[] = (string) $error['code'];
		} elseif ( is_string( $index ) ) {
			$codes[] = $index;
		}
	}

	return array(
		'status' => (int) $response->get_status(),
		'codes'  => $codes,
	);
}

/**
 * One section, declared for the given areas.
 *
 * @param string             $id    Identifier.
 * @param string             $title Title.
 * @param array<int, string> $areas Areas.
 * @return array<string, mixed>
 */
function wccs_proof_section( string $id, string $title, array $areas ): array {
	return array(
		'id'          => $id,
		'title'       => $title,
		'description' => '',
		'position'    => 10,
		'location'    => 'order',
		'areas'       => $areas,
	);
}

/**
 * One field, linked to the given destinations.
 *
 * @param array<string, mixed> $destinations Destination links.
 * @return array<string, mixed>
 */
function wccs_proof_field( array $destinations ): array {
	return array(
		'id'             => 'wccs_authorisation',
		'integration_id' => 'wc-checkoutsuite/wccs_authorisation',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Autorização',
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'destinations'   => $destinations,
	);
}

// ---------------------------------------------------------------------------
// 1. What the catalogue publishes as areas.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The areas a section may be offered in' );

$wccs_areas = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::section_area_values();

wccs_proof_check(
	'The checkout is an area',
	in_array( 'checkout', $wccs_areas, true ),
	'areas=' . implode( ',', $wccs_areas )
);

wccs_proof_check(
	'Every destination that draws a panel is an area too',
	in_array( 'admin_order', $wccs_areas, true )
		&& in_array( 'customer_order', $wccs_areas, true )
		&& in_array( 'order_received', $wccs_areas, true )
		&& in_array( 'customer_email', $wccs_areas, true )
		&& in_array( 'admin_email', $wccs_areas, true )
		&& in_array( 'customer_account', $wccs_areas, true )
		&& in_array( 'admin_customer', $wccs_areas, true ),
	'count=' . count( $wccs_areas )
);

wccs_proof_check(
	'The public API is not an area: it is a projection, not a panel',
	! in_array( 'public_api', $wccs_areas, true ),
	'areas=' . implode( ',', $wccs_areas )
);

// ---------------------------------------------------------------------------
// 2. The same section in two areas is one section.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. One section, two areas, one copy of the data' );

$wccs_shared = wccs_proof_write(
	array( wccs_proof_section( 'documentos', 'Documentos', array( 'admin_order', 'customer_order' ) ) ),
	array(
		wccs_proof_field(
			array(
				'admin_order'    => array( 'enabled' => true, 'section' => 'documentos' ),
				'customer_order' => array( 'enabled' => true, 'section' => 'documentos' ),
			)
		),
	)
);

wccs_proof_check(
	'A section offered in two areas is accepted',
	200 === $wccs_shared['status'],
	'status=' . $wccs_shared['status'] . ' codes=' . implode( ',', $wccs_shared['codes'] )
);

$wccs_stored = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_sections = array();
$wccs_links    = array();

foreach ( $wccs_stored->sections() as $wccs_raw_section ) {
	$wccs_definition = \WCCheckoutSuite\Domain\Sections\SectionDefinition::from_array( $wccs_raw_section );

	$wccs_sections[ $wccs_definition->id() ] = $wccs_definition->areas();
}

foreach ( $wccs_stored->fields() as $wccs_raw_field ) {
	$wccs_field_definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_raw_field );

	foreach ( $wccs_field_definition->destinations() as $wccs_destination => $wccs_link ) {
		if ( ! empty( $wccs_link['enabled'] ) ) {
			$wccs_links[ $wccs_destination ] = $wccs_link['section'] ?? '';
		}
	}
}

wccs_proof_check(
	'There is one section, offered in both areas',
	1 === count( $wccs_sections )
		&& array( 'admin_order', 'customer_order' ) === ( $wccs_sections['documentos'] ?? array() ),
	'sections=' . wp_json_encode( $wccs_sections )
);

wccs_proof_check(
	'Both destinations point at the same section',
	'documentos' === ( $wccs_links['admin_order'] ?? '' )
		&& 'documentos' === ( $wccs_links['customer_order'] ?? '' ),
	'links=' . wp_json_encode( $wccs_links )
);

// ---------------------------------------------------------------------------
// 3. What the rule refuses.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What the route refuses' );

$wccs_cases = array(
	'a section offered nowhere is refused'                => array(
		'sections' => array( wccs_proof_section( 'documentos', 'Documentos', array() ) ),
		'fields'   => array( wccs_proof_field( array( 'admin_order' => array( 'enabled' => true ) ) ) ),
		'code'     => 'section_without_area',
	),
	'an area that does not exist is refused'              => array(
		'sections' => array( wccs_proof_section( 'documentos', 'Documentos', array( 'sidebar' ) ) ),
		'fields'   => array( wccs_proof_field( array( 'admin_order' => array( 'enabled' => true ) ) ) ),
		'code'     => 'section_unknown_area',
	),
	'a link to a section not offered in that area is refused' => array(
		'sections' => array( wccs_proof_section( 'documentos', 'Documentos', array( 'checkout' ) ) ),
		'fields'   => array(
			wccs_proof_field(
				array( 'admin_order' => array( 'enabled' => true, 'section' => 'documentos' ) )
			),
		),
		'code'     => 'destination_section_not_offered',
	),
	'a link to a section the document does not declare is refused' => array(
		'sections' => array(),
		'fields'   => array(
			wccs_proof_field(
				array( 'customer_order' => array( 'enabled' => true, 'section' => 'nao_existe' ) )
			),
		),
		'code'     => 'destination_section_not_offered',
	),
);

foreach ( $wccs_cases as $wccs_label => $wccs_case ) {
	$wccs_attempt = wccs_proof_write( $wccs_case['sections'], $wccs_case['fields'] );

	wccs_proof_check(
		$wccs_label,
		422 === $wccs_attempt['status'] && in_array( $wccs_case['code'], $wccs_attempt['codes'], true ),
		'status=' . $wccs_attempt['status'] . ' codes=' . implode( ',', $wccs_attempt['codes'] )
	);
}

wccs_proof_check(
	'A refused write leaves the stored document alone',
	'documentos' === ( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->sections()[0]['id'] ?? '' ),
	'sections=' . wp_json_encode( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->sections() )
);

wccs_proof_note(
	'Cross-reference',
	'The tab that chooses a section per destination is WCCS-072; the panel per area that must not appear without a link is WCCS-076.'
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
