<?php
/**
 * WCCS-039 proof harness — the capability matrix before publishing.
 *
 * Task:   WCCS-039 "Implementar matriz no admin"
 * Phase:  F07 · Checkout Blocks nativo e tipos próprios
 * Accept: "Limites de core, largura, seção e tipo visíveis antes de publicar."
 *
 * The unit suite proves each family in isolation. What this harness proves is that
 * the matrix reaches the surface the merchant actually presses: the same route that
 * answers "what would publishing change" now also answers "what does each checkout
 * do with each field", from the real draft, against the store's real core field
 * inventory.
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
 * @param string               $type  Type.
 * @param array<string, mixed> $extra Extra keys.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $type, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => $type,
			'label'          => $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 6,
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
 * Writes a document into the draft slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_draft( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ),
		(string) wp_json_encode(
			array(
				'revision'       => 9,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(
					array(
						'id'       => 'billing',
						'title'    => 'Billing',
						'location' => 'billing',
						'position' => 10,
					),
					array(
						'id'       => 'notes',
						'title'    => 'Notes',
						'location' => 'order',
						'position' => 20,
					),
				),
				'settings'       => array(),
			)
		),
		false
	);
}

$wccs_draft_option = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

delete_option( $wccs_draft_option );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-039 proof — the capability matrix' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The matrix over a real draft.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The four families, over the draft' );

$wccs_core = ( new \WCCheckoutSuite\Domain\Checkout\CoreFields() )->catalogue();
$wccs_core_id = '';

if ( $wccs_core['available'] ) {
	// The inventory is a flat list of described fields: the identifier is a key of
	// each entry, not of the list.
	$wccs_core_id = isset( $wccs_core['fields'][0]['id'] ) ? (string) $wccs_core['fields'][0]['id'] : '';
}

wccs_proof_draft(
	array(
		wccs_proof_field( 'note', 'textarea', array( 'section' => 'notes' ) ),
		wccs_proof_field( 'birth_date', 'date', array( 'layout' => array( 'desktop' => 5, 'tablet' => 6, 'mobile' => 12 ) ) ),
		wccs_proof_field( 'attachment', 'file' ),
		wccs_proof_field( 'lost', 'text', array( 'section' => 'nowhere' ) ),
	)
);

if ( '' !== $wccs_core_id ) {
	$wccs_fields   = array();
	$wccs_document = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
	$wccs_stored   = json_decode( (string) get_option( $wccs_document ), true );

	if ( is_array( $wccs_stored ) ) {
		$wccs_stored['fields'][] = wccs_proof_field( 'core_override', 'text', array( 'origin' => 'core', 'id' => $wccs_core_id ) );
		$wccs_fields             = $wccs_stored['fields'];
	}

	wccs_proof_draft( $wccs_fields );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$wccs_response = rest_do_request(
	new WP_REST_Request(
		'GET',
		'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF
	)
);

$wccs_payload = $wccs_response->get_data();
$wccs_matrix  = is_array( $wccs_payload ) && isset( $wccs_payload['capabilities'] ) && is_array( $wccs_payload['capabilities'] )
	? $wccs_payload['capabilities']
	: array();

wccs_proof_check(
	'The publish report answers with the capability matrix',
	200 === $wccs_response->get_status() && array() !== $wccs_matrix,
	'status=' . $wccs_response->get_status() . ' fields=' . count( $wccs_matrix )
);

$wccs_by_field = array();

foreach ( $wccs_matrix as $wccs_entry ) {
	$wccs_by_field[ (string) $wccs_entry['field'] ] = $wccs_entry;
}

$wccs_families = array();

foreach ( $wccs_matrix as $wccs_entry ) {
	foreach ( $wccs_entry['limits'] as $wccs_limit ) {
		$wccs_families[ (string) $wccs_limit['family'] ] = true;
	}
}

ksort( $wccs_families );

wccs_proof_check(
	'The four families the acceptance names are all answered',
	array( 'core', 'section', 'type', 'width' ) === array_keys( $wccs_families ),
	'families=' . wp_json_encode( array_keys( $wccs_families ) )
);

wccs_proof_check(
	'Every field of the draft appears, so nothing is silently left out',
	isset( $wccs_by_field['wccs_note'], $wccs_by_field['wccs_birth_date'], $wccs_by_field['wccs_attachment'], $wccs_by_field['wccs_lost'] ),
	'fields=' . wp_json_encode( array_keys( $wccs_by_field ) )
);

/**
 * One limit, by family and adapter.
 *
 * @param array<string, mixed> $entry   Matrix entry.
 * @param string               $family  Family.
 * @param string               $adapter Adapter.
 * @return array<string, mixed>|null
 */
function wccs_proof_limit( array $entry, string $family, string $adapter ): ?array {
	foreach ( $entry['limits'] as $limit ) {
		if ( $family === $limit['family'] && $adapter === $limit['adapter'] ) {
			return $limit;
		}
	}

	return null;
}

$wccs_type_file    = wccs_proof_limit( $wccs_by_field['wccs_attachment'], 'type', 'blocks' );
$wccs_type_date    = wccs_proof_limit( $wccs_by_field['wccs_birth_date'], 'type', 'blocks' );
$wccs_width_blocks = wccs_proof_limit( $wccs_by_field['wccs_note'], 'width', 'blocks' );
$wccs_width_grid   = wccs_proof_limit( $wccs_by_field['wccs_birth_date'], 'width', 'all' );
$wccs_section_lost = wccs_proof_limit( $wccs_by_field['wccs_lost'], 'section', 'all' );
$wccs_section_core = wccs_proof_limit( $wccs_by_field['wccs_note'], 'section', 'classic' );

wccs_proof_check(
	'A type neither checkout renders is reported as unavailable, with its reason',
	null !== $wccs_type_file && \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::UNAVAILABLE === $wccs_type_file['level'],
	'level=' . ( null === $wccs_type_file ? '(missing)' : $wccs_type_file['level'] )
);

wccs_proof_check(
	'A type this plugin renders is limited, and the reason names the task that delivers it',
	null !== $wccs_type_date
		&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::LIMITED === $wccs_type_date['level']
		&& str_contains( (string) $wccs_type_date['reason'], 'WCCS-037' ),
	'reason=' . ( null === $wccs_type_date ? '(missing)' : $wccs_type_date['reason'] )
);

wccs_proof_check(
	'The width is provided by the classic checkout and limited in the Block one',
	null !== wccs_proof_limit( $wccs_by_field['wccs_note'], 'width', 'classic' )
		&& null !== $wccs_width_blocks
		&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::LIMITED === $wccs_width_blocks['level']
);

wccs_proof_check(
	'A width outside the grid is reported as not applied',
	null !== $wccs_width_grid
		&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::UNAVAILABLE === $wccs_width_grid['level']
		&& str_contains( (string) $wccs_width_grid['reason'], 'desktop' ),
	'reason=' . ( null === $wccs_width_grid ? '(missing)' : $wccs_width_grid['reason'] )
);

wccs_proof_check(
	'A field in a section that is not in the document has nowhere to go',
	null !== $wccs_section_lost
		&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::UNAVAILABLE === $wccs_section_lost['level']
);

wccs_proof_check(
	'And a field in a published section has a place in the classic checkout',
	null !== $wccs_section_core
		&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::PROVIDED === $wccs_section_core['level'],
	'reason=' . ( null === $wccs_section_core ? '(missing)' : $wccs_section_core['reason'] )
);

if ( '' !== $wccs_core_id ) {
	$wccs_core_limit = wccs_proof_limit( $wccs_by_field[ $wccs_core_id ] ?? array( 'limits' => array() ), 'core', 'all' );

	wccs_proof_check(
		'A WooCommerce field carries the core limit, and what can be done to it',
		null !== $wccs_core_limit
			&& \WCCheckoutSuite\Domain\Checkout\CapabilityResolver::LIMITED === $wccs_core_limit['level']
			&& str_contains( (string) $wccs_core_limit['reason'], 'cannot be removed' ),
		'field=' . $wccs_core_id
	);
} else {
	wccs_proof_check(
		'The core inventory is available on this store, so the core limit can be proven',
		false,
		'inventory=' . wp_json_encode( $wccs_core['reason'] ?? '' )
	);
}

wccs_proof_check(
	'The matrix is not the incompatibility report under another name',
	isset( $wccs_payload['incompatibilities'] )
		&& $wccs_payload['incompatibilities'] !== $wccs_matrix
);

// ---------------------------------------------------------------------------
// 2. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Environment' );

delete_option( $wccs_draft_option );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why the matrix is not the incompatibility report',
	'The report answers whether something would not be honoured, in the vocabulary of warnings. The matrix answers what each checkout does with each field, whether or not any of it is a problem: "four columns is honoured by the classic checkout and is not applied in the Block one" is a fact to decide with, not a defect to fix. Both travel in the same response, under separate keys, so the interface cannot merge them by accident.'
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
