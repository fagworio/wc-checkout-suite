<?php
/**
 * WCCS-018 proof harness — sections and ordering.
 *
 * Task:   WCCS-018 "Criar seções e ordenação"
 * Phase:  F03
 * Accept: "Ordem por seção salva; mover por teclado e botões preserva foco."
 *
 * The acceptance has two halves that live in different places, so this harness
 * proves the one PHP can reach and states plainly where the other is proven:
 *
 * 1. **"Ordem por seção salva"** is a claim about storage. It is demonstrated by
 *    sending one order, reading the draft back, sending the opposite order and
 *    reading it back again — through the real route, because a pure function
 *    proved in a unit test says nothing about whether the order survives.
 * 2. **"mover por teclado e botões preserva foco"** is a claim about the DOM, and
 *    is proven by tests/js/components/SortableList.test.js. This harness only
 *    confirms that the component reached the shipped bundle.
 *
 * It also closes the two gaps the task set out to close: sections were stored
 * without ever being validated, and a field's section was never checked against
 * anything, so a field could belong to a section that did not exist.
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
 * Builds a section definition.
 *
 * @param string $id       Identifier.
 * @param string $title    Title.
 * @param string $location Logical location.
 * @param int    $position Position.
 * @return array<string, mixed>
 */
function wccs_proof_section( string $id, string $title, string $location = 'billing', int $position = 10 ): array {
	return array(
		'id'          => $id,
		'title'       => $title,
		'description' => '',
		'position'    => $position,
		'location'    => $location,
	);
}

/**
 * Builds a field definition.
 *
 * @param string $id      Identifier.
 * @param string $section Section.
 * @param int    $position Position.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $section, int $position ): array {
	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Label ' . $id,
		'section'        => $section,
		'enabled'        => true,
		'required'       => false,
		'position'       => $position,
	);
}

/**
 * Builds a document payload.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Sections.
 * @param int                              $revision Revision.
 * @return array<string, mixed>
 */
function wccs_proof_doc( array $fields, array $sections, int $revision ): array {
	return array(
		'revision'       => $revision,
		'schema_version' => 1,
		'fields'         => $fields,
		'sections'       => $sections,
		'settings'       => array(),
	);
}

/**
 * Sends a document through the draft route.
 *
 * @param array<string, mixed> $document Document.
 * @param int                  $expected Expected revision.
 * @return WP_REST_Response
 */
function wccs_proof_save( array $document, int $expected ): WP_REST_Response {
	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( array( 'schema' => $document, 'expected_revision' => $expected ) ) );

	return rest_do_request( $request );
}

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
wccs_proof_out( 'WCCS-018 proof — sections and ordering' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_registries = \WCCheckoutSuite\Domain\Registries::instance();
$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	$wccs_registries->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

// ---------------------------------------------------------------------------
// 1. The locations the admin is told about.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Section locations' );

$wccs_catalogue = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES )
)->get_data();

$wccs_locations = (array) ( $wccs_catalogue['sectionLocations'] ?? array() );
$wccs_expected  = \WCCheckoutSuite\Domain\Sections\SectionLocations::all();

wccs_proof_check(
	'The published locations are the ones the validator reads',
	$wccs_locations === $wccs_expected,
	implode( ', ', array_column( $wccs_locations, 'value' ) )
);

// ROADMAP.md section 4 names exactly these five as domain concepts. A sixth
// would be an invention; a missing one would make an adopted WooCommerce field
// impossible to place.
wccs_proof_check(
	'The five domain concepts of section 4 are the ones offered',
	array( 'billing', 'shipping', 'contact', 'account', 'order' ) === array_column( $wccs_locations, 'value' ),
	count( $wccs_locations ) . ' locations'
);

// ---------------------------------------------------------------------------
// 2. Sections are validated, which they never were.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Section validation' );

$wccs_base_fields = array(
	wccs_proof_field( 'billing_document', 'dados_extras', 10 ),
	wccs_proof_field( 'billing_ie', 'dados_extras', 20 ),
);

$wccs_first = wccs_proof_save(
	wccs_proof_doc(
		$wccs_base_fields,
		array( wccs_proof_section( 'dados_extras', 'Dados extras', 'billing', 10 ) ),
		0
	),
	0
);

wccs_proof_check(
	'A declared section with fields in it is accepted',
	200 === $wccs_first->get_status(),
	'status=' . $wccs_first->get_status() . ' ' . (string) wp_json_encode( $wccs_first->get_data() )
);

$wccs_revision = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

/**
 * Sends a document and returns the response, using the current revision.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Sections.
 * @return WP_REST_Response
 */
function wccs_proof_attempt( array $fields, array $sections ): WP_REST_Response {
	$current = \WCCheckoutSuite\Domain\Registries::instance();

	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		$current->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	$revision = $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision();

	return wccs_proof_save( wccs_proof_doc( $fields, $sections, $revision + 1 ), $revision );
}

$wccs_orphan = wccs_proof_attempt(
	array( wccs_proof_field( 'billing_document', 'secao_inexistente', 10 ) ),
	array()
);

wccs_proof_check(
	'A field in a section the document does not declare is refused',
	in_array( 'unknown_section', wccs_proof_codes( $wccs_orphan ), true ),
	implode( ', ', wccs_proof_codes( $wccs_orphan ) )
);

$wccs_domain_location = wccs_proof_attempt(
	array( wccs_proof_field( 'billing_document', 'shipping', 10 ) ),
	array()
);

wccs_proof_check(
	'A field in one of the five domain locations is accepted without a declaration',
	200 === $wccs_domain_location->get_status(),
	'status=' . $wccs_domain_location->get_status()
);

$wccs_duplicate = wccs_proof_attempt(
	array(),
	array(
		wccs_proof_section( 'extra', 'Extra', 'billing', 10 ),
		wccs_proof_section( 'extra', 'Outra', 'order', 20 ),
	)
);

wccs_proof_check(
	'Two sections sharing an identifier are refused',
	in_array( 'duplicate_section_id', wccs_proof_codes( $wccs_duplicate ), true ),
	implode( ', ', wccs_proof_codes( $wccs_duplicate ) )
);

$wccs_untitled = wccs_proof_attempt(
	array(),
	array(
		array(
			'id'       => 'sem_titulo',
			'location' => 'billing',
			'position' => 10,
		),
	)
);

wccs_proof_check(
	'A section without a title is refused',
	in_array( 'section_missing_title', wccs_proof_codes( $wccs_untitled ), true ),
	implode( ', ', wccs_proof_codes( $wccs_untitled ) )
);

$wccs_bad_location = wccs_proof_attempt(
	array(),
	array( wccs_proof_section( 'lateral', 'Lateral', 'sidebar', 10 ) )
);

wccs_proof_check(
	'A section in an unknown location is refused',
	in_array( 'section_unknown_location', wccs_proof_codes( $wccs_bad_location ), true ),
	implode( ', ', wccs_proof_codes( $wccs_bad_location ) )
);

$wccs_bad_id = wccs_proof_attempt(
	array(),
	array( wccs_proof_section( 'Dados Extras', 'Dados Extras', 'billing', 10 ) )
);

wccs_proof_check(
	'A section identifier the server would not accept is refused',
	in_array( 'section_invalid_id', wccs_proof_codes( $wccs_bad_id ), true ),
	implode( ', ', wccs_proof_codes( $wccs_bad_id ) )
);

// ---------------------------------------------------------------------------
// 3. The order is saved.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Order survives the round trip' );

/**
 * Reads the identifiers of a section, in stored position order.
 *
 * @param string $section Section identifier.
 * @return array<int, string>
 */
function wccs_proof_order( string $section ): array {
	$registries = \WCCheckoutSuite\Domain\Registries::instance();

	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		$registries->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	$fields = $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields();

	usort(
		$fields,
		static function ( array $a, array $b ): int {
			return ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) );
		}
	);

	$order = array();

	foreach ( $fields as $field ) {
		if ( ( $field['section'] ?? '' ) === $section ) {
			$order[] = (string) $field['id'];
		}
	}

	return $order;
}

// The order has to be established immediately before it is read. The attempts
// above include accepted writes, and an accepted write replaces the draft — so
// asserting here without re-sending would be reading whatever the last accepted
// attempt happened to leave behind, which is exactly the mistake this line
// corrects.
$wccs_ordered = wccs_proof_attempt(
	array(
		wccs_proof_field( 'billing_document', 'dados_extras', 10 ),
		wccs_proof_field( 'billing_ie', 'dados_extras', 20 ),
	),
	array( wccs_proof_section( 'dados_extras', 'Dados extras', 'billing', 10 ) )
);

wccs_proof_check(
	'The order that was sent is the order that is stored',
	200 === $wccs_ordered->get_status()
		&& array( 'billing_document', 'billing_ie' ) === wccs_proof_order( 'dados_extras' ),
	'status=' . $wccs_ordered->get_status() . ' order=' . implode( ', ', wccs_proof_order( 'dados_extras' ) )
);

// Now the opposite order, the way moving the second field up would send it.
$wccs_swapped = wccs_proof_attempt(
	array(
		wccs_proof_field( 'billing_ie', 'dados_extras', 10 ),
		wccs_proof_field( 'billing_document', 'dados_extras', 20 ),
	),
	array( wccs_proof_section( 'dados_extras', 'Dados extras', 'billing', 10 ) )
);

wccs_proof_check(
	'Swapping two fields is accepted',
	200 === $wccs_swapped->get_status(),
	'status=' . $wccs_swapped->get_status()
);

wccs_proof_check(
	'The new order replaced the old one',
	array( 'billing_ie', 'billing_document' ) === wccs_proof_order( 'dados_extras' ),
	implode( ', ', wccs_proof_order( 'dados_extras' ) )
);

// ---------------------------------------------------------------------------
// 4. Sections and their fields are stored together.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Stored shape' );

$wccs_stored = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_section = $wccs_stored->sections()[0] ?? array();

wccs_proof_check(
	'The section is stored with its own keys',
	'dados_extras' === ( $wccs_section['id'] ?? '' )
		&& 'Dados extras' === ( $wccs_section['title'] ?? '' )
		&& 'billing' === ( $wccs_section['location'] ?? '' )
		&& 10 === ( $wccs_section['position'] ?? null ),
	(string) wp_json_encode( $wccs_section )
);

$wccs_moved = wccs_proof_attempt(
	array(
		wccs_proof_field( 'billing_ie', 'dados_extras', 10 ),
		wccs_proof_field( 'billing_document', 'shipping', 10 ),
	),
	array( wccs_proof_section( 'dados_extras', 'Dados extras', 'billing', 10 ) )
);

wccs_proof_check(
	'A field can move to a domain location without declaring a section for it',
	200 === $wccs_moved->get_status()
		&& array( 'billing_ie' ) === wccs_proof_order( 'dados_extras' )
		&& array( 'billing_document' ) === wccs_proof_order( 'shipping' ),
	'status=' . $wccs_moved->get_status()
);

// ---------------------------------------------------------------------------
// 5. Removing a section still holding fields is refused.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Removing a section' );

$wccs_removed_early = wccs_proof_attempt(
	array( wccs_proof_field( 'billing_ie', 'dados_extras', 10 ) ),
	array()
);

wccs_proof_check(
	'Removing a section that still holds fields is refused',
	in_array( 'unknown_section', wccs_proof_codes( $wccs_removed_early ), true ),
	implode( ', ', wccs_proof_codes( $wccs_removed_early ) )
);

$wccs_removed_clean = wccs_proof_attempt( array(), array() );

wccs_proof_check(
	'Removing an empty section is accepted',
	200 === $wccs_removed_clean->get_status(),
	'status=' . $wccs_removed_clean->get_status()
);

// ---------------------------------------------------------------------------
// 6. The reorderable list reached the bundle.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Build output' );

$wccs_built_js  = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.js' );
$wccs_built_css = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.css' );

wccs_proof_check(
	'The reorderable list reached the shipped bundle',
	false !== strpos( $wccs_built_js, 'wccs-sortable__item' )
		&& false !== strpos( $wccs_built_css, 'wccs-sortable__' ),
	'list markup and styles present'
);

wccs_proof_check(
	'The controls name the item they move',
	false !== strpos( $wccs_built_js, 'Move %s up' )
		&& false !== strpos( $wccs_built_js, 'moved to position' ),
	'labels and announcement present'
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
	'Button, keyboard and focus behaviour is proven by tests/js/components/SortableList.test.js (19 specs).'
);
wccs_proof_note(
	'Cross-reference',
	'The ordering operations are proven by tests/js/schema/fieldOperations.test.js (51 specs).'
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
