<?php
/**
 * WCCS-072 proof harness — the links the inspector edits, and the surfaces that obey them.
 *
 * Task:   WCCS-072 "Criar a aba Vínculos e exibição no inspetor"
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
wccs_proof_out( 'WCCS-072 proof — the links the inspector edits' );
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

$wccs_vocabulary = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::to_array();

// ---------------------------------------------------------------------------
// 1. What the tab is given to draw, and what the server takes back.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. No drift between the tab and the validator' );

$wccs_rejected = array();

foreach ( $wccs_vocabulary['destinations'] as $wccs_destination ) {
	$wccs_outcome = wccs_proof_validate(
		array(
			'destinations' => array(
				$wccs_destination['value'] => array( 'enabled' => true ),
			),
		)
	);

	if ( ! $wccs_outcome['valid'] ) {
		$wccs_rejected[] = 'destination:' . $wccs_destination['value'];
	}

	// Every action the tab offers for this destination is one the server accepts for
	// it — a control that offers what the server refuses is a control that lies.
	foreach ( $wccs_destination['actions'] as $wccs_action ) {
		$wccs_outcome = wccs_proof_validate(
			array(
				'destinations' => array(
					$wccs_destination['value'] => array(
						'enabled' => true,
						'actions' => array( $wccs_action ),
					),
				),
			)
		);

		if ( ! $wccs_outcome['valid'] ) {
			$wccs_rejected[] = 'action:' . $wccs_destination['value'] . ':' . $wccs_action;
		}
	}
}

wccs_proof_check(
	'Every destination and action the tab offers is accepted',
	array() === $wccs_rejected,
	$wccs_rejected ? implode( ', ', $wccs_rejected ) : 'no drift'
);

wccs_proof_check(
	'Every destination publishes the actions it may perform',
	count( array_filter( $wccs_vocabulary['destinations'], static fn( $entry ) => ! empty( $entry['actions'] ) ) ) === count( $wccs_vocabulary['destinations'] ),
	'count=' . count( $wccs_vocabulary['destinations'] )
);

wccs_proof_check(
	'An action a destination may not perform is refused',
	in_array(
		'invalid_destination_action',
		wccs_proof_validate(
			array(
				'destinations' => array(
					'customer_order' => array(
						'enabled' => true,
						'actions' => array( 'approve' ),
					),
				),
			)
		)['codes'],
		true
	),
	'approving is a staff action'
);

// ---------------------------------------------------------------------------
// 2. The shape the tab writes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the tab writes' );

$wccs_request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
$wccs_request->set_header( 'Content-Type', 'application/json' );
$wccs_request->set_body(
	(string) wp_json_encode(
		array(
			'schema'            => array(
				'revision' => 0,
				'fields'   => array(
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
						'destinations'   => array(
							'admin_order'    => array(
								'enabled'  => true,
								'section'  => 'documentos_para_analise',
								'title'    => 'Documentos para análise',
								'position' => 10,
								'actions'  => array( 'show_metadata', 'view', 'download', 'approve' ),
							),
							'customer_order' => array( 'enabled' => false ),
						),
						'approval'       => array(
							'require_review'   => true,
							'area'             => 'admin_order',
							'section'          => 'documentos_para_analise',
							'status'           => 'Pendente de aprovação',
							'allow_correction' => true,
							'allow_resubmit'   => true,
							'show_status'      => true,
						),
					),
				),
				'sections' => array(),
				'settings' => array(),
			),
			'expected_revision' => 0,
		)
	)
);

$wccs_response = rest_do_request( $wccs_request );

wccs_proof_check(
	'The definition the tab writes is accepted',
	200 === $wccs_response->get_status(),
	'status=' . $wccs_response->get_status() . ' ' . (string) wp_json_encode( $wccs_response->get_data() )
);

$wccs_stored = array();

foreach ( $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields() as $wccs_raw ) {
	$wccs_definition              = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_raw );
	$wccs_stored[ $wccs_definition->id() ] = $wccs_definition;
}

$wccs_field = $wccs_stored['wccs_authorisation'] ?? null;

wccs_proof_check(
	'Section, title, order and actions survive the round trip',
	null !== $wccs_field
		&& 'documentos_para_analise' === ( $wccs_field->destinations()['admin_order']['section'] ?? '' )
		&& 'Documentos para análise' === ( $wccs_field->destinations()['admin_order']['title'] ?? '' )
		&& 10 === ( $wccs_field->destinations()['admin_order']['position'] ?? null )
		&& array( 'show_metadata', 'view', 'download', 'approve' ) === ( $wccs_field->destinations()['admin_order']['actions'] ?? array() ),
	'section=' . ( $wccs_field?->destinations()['admin_order']['section'] ?? '?' )
);

wccs_proof_check(
	'The approval flow survives too, and stays a separate configuration',
	null !== $wccs_field
		&& true === ( $wccs_field->approval()['require_review'] ?? null )
		&& 'admin_order' === ( $wccs_field->approval()['area'] ?? '' ),
	'approval=' . wp_json_encode( $wccs_field?->approval() )
);

// ---------------------------------------------------------------------------
// 3. The negative: nothing is shown without a link.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A field linked to nothing is shown by nobody' );

$wccs_unlinked = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	array(
		'id'     => 'wccs_unlinked',
		'origin' => 'custom',
		'type'   => 'text',
		'label'  => 'Sem vínculo',
	)
);

$wccs_shown = array();

foreach ( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::destination_values() as $wccs_key ) {
	if ( $wccs_unlinked->shows_in( $wccs_key ) ) {
		$wccs_shown[] = $wccs_key;
	}
}

wccs_proof_check(
	'A field with no link is enabled in no destination',
	array() === $wccs_shown,
	'enabled=' . implode( ',', $wccs_shown )
);

// Through the classes that render, not through the model alone: these are the three
// surfaces that would show it if a link were assumed instead of read.
$wccs_definitions = array(
	'wccs_unlinked'         => $wccs_unlinked->to_array(),
	'wccs_authorisation'    => $wccs_field?->to_array() ?? array(),
);

$wccs_customer = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( $wccs_definitions );
$wccs_email    = \WCCheckoutSuite\Checkout\OrderEmailFields::visible( $wccs_definitions, false );
$wccs_staff    = \WCCheckoutSuite\Checkout\OrderEmailFields::visible( $wccs_definitions, true );

wccs_proof_check(
	'The customer page shows the linked field and not the unlinked one',
	! array_key_exists( 'wccs_unlinked', $wccs_customer )
		&& array_key_exists( 'wccs_authorisation', $wccs_customer ) === $wccs_field?->shows_in( 'customer_order' ),
	'visible=' . implode( ',', array_keys( $wccs_customer ) )
);

wccs_proof_check(
	'The customer e-mail shows the unlinked field to nobody',
	! array_key_exists( 'wccs_unlinked', $wccs_email ),
	'visible=' . implode( ',', array_keys( $wccs_email ) )
);

wccs_proof_check(
	'The store e-mail shows the unlinked field to nobody',
	! array_key_exists( 'wccs_unlinked', $wccs_staff ),
	'visible=' . implode( ',', array_keys( $wccs_staff ) )
);

// ---------------------------------------------------------------------------
// 4. A document written before the model still reads.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The map the inspector used to write' );

$wccs_legacy = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	array(
		'id'         => 'wccs_legacy',
		'origin'     => 'custom',
		'type'       => 'text',
		'label'      => 'Antigo',
		'visibility' => array(
			'admin_order'    => true,
			'customer_order' => true,
			'public_api'     => false,
		),
	)
);

wccs_proof_check(
	'A stored audience map still reads as links',
	$wccs_legacy->shows_in( 'admin_order' )
		&& $wccs_legacy->shows_in( 'customer_order' )
		&& ! $wccs_legacy->shows_in( 'public_api' )
		&& ! $wccs_legacy->shows_in( 'admin_email' ),
	'links=' . wp_json_encode( $wccs_legacy->destinations() )
);

wccs_proof_check(
	'The superseded map is not published any more',
	! array_key_exists( 'visibility', $wccs_legacy->to_array() ),
	'keys=' . implode( ',', array_keys( $wccs_legacy->to_array() ) )
);

wccs_proof_note(
	'Cross-reference',
	'The tab itself is pinned by tests/js/views/FieldProperties.test.js (12 specs, four of them for this tab).'
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
