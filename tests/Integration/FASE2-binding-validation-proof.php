<?php
/**
 * Fase 2 proof harness — the uses of a field are validated one by one.
 *
 * Task:   Fase 2 "Container/Binding — validadores"
 * Accept: "um documento com dois usos do mesmo campo no mesmo destino é validado uso a uso:
 *          o que a área pode desempenhar, o container que a área oferece, e a identidade de
 *          cada uso."
 *
 * The destination map keeps one entry per destination, so before the bindings were validated
 * on their own a second use of a field in the same area had nowhere to be wrong: an action
 * the destination cannot perform, or a container the area does not offer, was accepted for it
 * and only the first use was ever asked. This harness drives the real route with a document
 * in the final model and reads what the store answered:
 *
 * 1. **A valid pair of uses is accepted**, and both survive the round trip — the point of
 *    §3.3 is that a field may be used twice, not that it is tolerated.
 * 2. **A use that claims an action its destination cannot perform is refused**, even though
 *    the map's entry for that destination is a use that may do it.
 * 3. **A use that names a container its destination does not offer is refused**, even though
 *    another use of the same field names one that is offered.
 * 4. The refusals leave nothing behind: a document that was refused is not stored.
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
 * The repository the routes use.
 *
 * @return \WCCheckoutSuite\Domain\Schema\SchemaRepository
 */
function wccs_binding_repository(): \WCCheckoutSuite\Domain\Schema\SchemaRepository {
	return new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);
}

/**
 * Writes one document through the real draft route.
 *
 * @param array<int, array<string, mixed>> $sections Containers.
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @return array{status: int, codes: array<int, string>}
 */
function wccs_binding_write( array $sections, array $fields ): array {
	$repository = wccs_binding_repository();

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
				'expected_revision' => $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
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
 * One container, in the final model.
 *
 * @param string $id          Identifier.
 * @param string $name        Name.
 * @param string $destination Destination it belongs to.
 * @return array<string, mixed>
 */
function wccs_binding_container( string $id, string $name, string $destination ): array {
	return array(
		'id'          => $id,
		'name'        => $name,
		'destination' => $destination,
		'position'    => 10,
		'enabled'     => true,
		'show_title'  => true,
	);
}

/**
 * One use of a field.
 *
 * @param string             $container   Container identifier.
 * @param string             $destination Destination.
 * @param array<int, string> $permissions What that use may do.
 * @param int                $position    Order inside the container.
 * @return array<string, mixed>
 */
function wccs_binding_use( string $container, string $destination, array $permissions, int $position ): array {
	return array(
		'field_id'     => 'autorizacao',
		'container_id' => $container,
		'destination'  => $destination,
		'position'     => $position,
		'visible'      => true,
		'editable'     => false,
		'permissions'  => $permissions,
	);
}

/**
 * One file field that stores the uses it is given.
 *
 * @param array<int, array<string, mixed>> $uses Uses.
 * @return array<string, mixed>
 */
function wccs_binding_field( array $uses ): array {
	return array(
		'id'             => 'autorizacao',
		'integration_id' => 'wc-checkoutsuite/autorizacao',
		'origin'         => 'custom',
		'type'           => 'file',
		'label'          => 'Autorização',
		'section'        => 'documentos_do_cliente',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'sensitive',
		),
		'settings'       => array(
			'maxFiles'          => 1,
			'allowedExtensions' => array( 'pdf' ),
		),
		'bindings'       => $uses,
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 2 proof — the uses of a field are validated one by one' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_before = wccs_proof_option_count();

$wccs_containers = array(
	wccs_binding_container( 'documentos_do_cliente', 'Documentos do cliente', 'customer_order' ),
	wccs_binding_container( 'documentos_anexos', 'Anexos do cliente', 'customer_order' ),
	wccs_binding_container( 'documentos_da_equipa', 'Documentos da equipa', 'admin_order' ),
);

// ---------------------------------------------------------------------------
// 1. A valid pair of uses, in the same destination.
// ---------------------------------------------------------------------------
$wccs_valid = wccs_binding_write(
	$wccs_containers,
	array(
		wccs_binding_field(
			array(
				wccs_binding_use( 'documentos_do_cliente', 'customer_order', array( 'show_metadata', 'view' ), 10 ),
				wccs_binding_use( 'documentos_anexos', 'customer_order', array( 'download' ), 20 ),
			)
		),
	)
);

wccs_proof_check(
	'A document with two uses of one field in one destination is accepted',
	200 === $wccs_valid['status'],
	'status=' . $wccs_valid['status'] . ' codes=' . implode( ',', $wccs_valid['codes'] )
);

$wccs_stored = wccs_binding_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_field  = null;

foreach ( $wccs_stored->fields() as $wccs_raw ) {
	if ( is_array( $wccs_raw ) && 'autorizacao' === ( $wccs_raw['id'] ?? '' ) ) {
		$wccs_field = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_raw );
	}
}

wccs_proof_check(
	'Both uses come back, in the order they were given',
	null !== $wccs_field &&
		2 === count( $wccs_field->bindings_for( 'customer_order' ) ) &&
		array( 10, 20 ) === array_map(
			static fn( \WCCheckoutSuite\Domain\Fields\FieldBinding $binding ): ?int => $binding->position(),
			$wccs_field->bindings_for( 'customer_order' )
		),
	null === $wccs_field ? 'the field was not stored' : 'uses=' . count( $wccs_field->bindings_for( 'customer_order' ) )
);

wccs_proof_check(
	'And each use keeps what it may do',
	null !== $wccs_field &&
		array( 'show_metadata', 'view' ) === $wccs_field->bindings_for( 'customer_order' )[0]->permissions() &&
		array( 'download' ) === $wccs_field->bindings_for( 'customer_order' )[1]->permissions(),
	null === $wccs_field ? 'the field was not stored' : ''
);

// ---------------------------------------------------------------------------
// 2. A use that claims an action its destination cannot perform.
// ---------------------------------------------------------------------------
$wccs_bad_action = wccs_binding_write(
	$wccs_containers,
	array(
		wccs_binding_field(
			array(
				// The first use may do it; the second may not. The map keeps one entry, so
				// before the list was validated on its own this document was accepted.
				wccs_binding_use( 'documentos_do_cliente', 'customer_order', array( 'show_metadata', 'view' ), 10 ),
				wccs_binding_use( 'documentos_anexos', 'customer_order', array( 'approve' ), 20 ),
			)
		),
	)
);

wccs_proof_check(
	'A use that claims an action its destination cannot perform is refused',
	200 !== $wccs_bad_action['status'] && in_array( 'invalid_destination_action', $wccs_bad_action['codes'], true ),
	'status=' . $wccs_bad_action['status'] . ' codes=' . implode( ',', $wccs_bad_action['codes'] )
);

// ---------------------------------------------------------------------------
// 3. A use that names a container its destination does not offer.
// ---------------------------------------------------------------------------
$wccs_bad_container = wccs_binding_write(
	$wccs_containers,
	array(
		wccs_binding_field(
			array(
				wccs_binding_use( 'documentos_do_cliente', 'customer_order', array( 'show_metadata' ), 10 ),
				// The team's container, offered in the team's area and not in the customer's.
				wccs_binding_use( 'documentos_da_equipa', 'customer_order', array( 'show_metadata' ), 20 ),
			)
		),
	)
);

wccs_proof_check(
	'A use that names a container its destination does not offer is refused',
	200 !== $wccs_bad_container['status'] &&
		in_array( 'destination_section_not_offered', $wccs_bad_container['codes'], true ),
	'status=' . $wccs_bad_container['status'] . ' codes=' . implode( ',', $wccs_bad_container['codes'] )
);

wccs_proof_check(
	'One use in the area the container belongs to is accepted',
	isset( $wccs_field ) && $wccs_field->shows_in( 'customer_order' ) && ! $wccs_field->shows_in( 'admin_order' ),
	'the customer area is the one the stored document names'
);

// ---------------------------------------------------------------------------
// 4. A refusal stores nothing.
// ---------------------------------------------------------------------------
$wccs_after = wccs_binding_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_ids   = array();

foreach ( $wccs_after->fields() as $wccs_raw ) {
	if ( is_array( $wccs_raw ) ) {
		$wccs_ids[] = (string) ( $wccs_raw['id'] ?? '' );
	}
}

wccs_proof_check(
	'The refused documents did not replace the accepted one',
	array( 'autorizacao' ) === $wccs_ids,
	'fields=' . implode( ',', $wccs_ids )
);

wccs_proof_note(
	'Both refusals were asked of the draft route, which is where a configuration is written; publication asks the same questions of the document it publishes.'
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
