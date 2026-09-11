<?php
/**
 * WCCS-037 proof harness — the controlled fields the Blocks checkout needs.
 *
 * Task:   WCCS-037 "Implementar campos controlados próprios"
 * Phase:  F07
 * Accept: "Textarea, radio, multiselect, time/datetime e presets mascarados nos slots permitidos."
 *
 * The components themselves are JavaScript and are covered by the jsdom suite, which
 * renders each one and operates it. What this harness proves is the half a component
 * cannot: that the server publishes exactly the fields that need a component — no
 * native one, no restricted one — with everything a component needs to draw them,
 * and that the classification over the whole v1 type list has no gap.
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
 * @param string               $id      Identifier.
 * @param string               $type    Type.
 * @param array<string, mixed> $extra   Extra keys.
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
 * Writes a document into the published slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 6,
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
				),
				'settings'       => array(),
			)
		),
		false
	);
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-037 proof — the controlled fields' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The classification over the whole v1 type list.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Every type is rendered by someone, or restricted on purpose' );

$wccs_registered = \WCCheckoutSuite\Domain\Registries::instance()->types()->keys();

$wccs_unclassified = array();
$wccs_reasons      = array();

foreach ( $wccs_registered as $wccs_type ) {
	$wccs_mode = \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::mode( (string) $wccs_type );

	if ( ! in_array( $wccs_mode, array( 'native', 'controlled', 'restricted' ), true ) ) {
		$wccs_unclassified[] = (string) $wccs_type;
	}

	$wccs_reasons[ (string) $wccs_type ] = $wccs_mode;
}

wccs_proof_check(
	'Every registered field type has a decided mode',
	array() === $wccs_unclassified,
	'registered=' . count( $wccs_registered ) . ' offenders=' . wp_json_encode( $wccs_unclassified )
);

wccs_proof_check(
	'The phase gate is met where it can be: no non-file type is left undecided',
	array() === array_filter(
		$wccs_reasons,
		static function ( string $mode ): bool {
			return '' === $mode;
		}
	),
	'modes=' . wp_json_encode( array_count_values( $wccs_reasons ) )
);

// ---------------------------------------------------------------------------
// 2. What is published for the components.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the components are given' );

$wccs_masks = \WCCheckoutSuite\Domain\Registries::instance()->masks()->all();
$wccs_key   = (string) array_key_first( $wccs_masks );

wccs_proof_publish(
	array(
		wccs_proof_field( 'note', 'textarea', array( 'description' => 'Anything we should know.' ) ),
		wccs_proof_field(
			'person_type',
			'radio',
			array(
				'settings' => array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => 'Person',
						),
						array(
							'value' => 'pj',
							'label' => 'Company',
						),
					),
				),
			)
		),
		wccs_proof_field( 'birth_date', 'date', array( 'required' => true ) ),
		wccs_proof_field(
			'document',
			'text',
			array(
				'mask' => array(
					'key'     => $wccs_key,
					'version' => (int) $wccs_masks[ $wccs_key ]->version(),
				),
			)
		),
		wccs_proof_field( 'plain', 'text' ),
		wccs_proof_field( 'attachment', 'file' ),
		wccs_proof_field( 'choice', 'multiselect' ),
	)
);

$wccs_payload = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::fields();
$wccs_by_name = array();

foreach ( $wccs_payload['fields'] as $wccs_entry ) {
	$wccs_by_name[ (string) $wccs_entry['name'] ] = $wccs_entry;
}

wccs_proof_check(
	'A textarea, a radio, a date and a masked text are published',
	isset( $wccs_by_name['wccs_note'], $wccs_by_name['wccs_person_type'], $wccs_by_name['wccs_birth_date'], $wccs_by_name['wccs_document'] ),
	'published=' . wp_json_encode( array_keys( $wccs_by_name ) )
);

wccs_proof_check(
	'A native text field is not published for rendering',
	! isset( $wccs_by_name['wccs_plain'] ),
	'the native adapter registers it, and drawing it twice would duplicate the field'
);

wccs_proof_check(
	'A restricted type is not published for rendering',
	! isset( $wccs_by_name['wccs_attachment'] )
);

wccs_proof_check(
	'The radio carries its options, in the order the merchant wrote them',
	isset( $wccs_by_name['wccs_person_type']['options'] )
		&& array( 'pf', 'pj' ) === array_column( $wccs_by_name['wccs_person_type']['options'], 'value' ),
	'options=' . wp_json_encode( array_column( $wccs_by_name['wccs_person_type']['options'] ?? array(), 'value' ) )
);

wccs_proof_check(
	'The masked field carries the mask definition itself',
	isset( $wccs_by_name['wccs_document']['mask']['definition'] )
		&& $wccs_key === ( $wccs_by_name['wccs_document']['mask']['key'] ?? '' ),
	'mask=' . wp_json_encode( $wccs_by_name['wccs_document']['mask']['key'] ?? '' )
);

wccs_proof_check(
	'The required flag and the description travel with the field',
	true === $wccs_by_name['wccs_birth_date']['required']
		&& 'Anything we should know.' === ( $wccs_by_name['wccs_note']['description'] ?? '' )
);

wccs_proof_check(
	'A mutliselect with no options is reported rather than published empty',
	! isset( $wccs_by_name['wccs_choice'] )
		&& 'no_options' === ( $wccs_payload['report'][0]['code'] ?? '' ),
	'report=' . wp_json_encode( array_column( $wccs_payload['report'], 'code' ) )
);

// ---------------------------------------------------------------------------
// 3. The bundle, and the payload it reads.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The bundle and its payload' );

$wccs_manifest = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::asset_manifest();

wccs_proof_check(
	'The Blocks bundle was built',
	is_readable( WCCS_PLUGIN_DIR . 'build/blocks/index.js' )
);

wccs_proof_check(
	'And its manifest declares the runtime it needs, without bundling a copy',
	in_array( 'wp-element', $wccs_manifest['dependencies'], true )
		&& in_array( 'wp-i18n', $wccs_manifest['dependencies'], true ),
	'dependencies=' . wp_json_encode( $wccs_manifest['dependencies'] )
);

$wccs_external = array_values(
	array_filter(
		$wccs_manifest['dependencies'],
		static function ( string $handle ): bool {
			return 0 !== strpos( $handle, 'wp-' ) && 0 !== strpos( $handle, 'react-' );
		}
	)
);

wccs_proof_check(
	'Everything the bundle needs beyond WordPress is inside it, as section 9 requires',
	array() === $wccs_external,
	'declared but not provided by WordPress: ' . wp_json_encode( $wccs_external )
);

wp_dequeue_script( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::enqueue( true, $wccs_payload );

wccs_proof_check(
	'The bundle is enqueued when the checkout is a Blocks one and there is something to draw',
	wp_script_is( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE, 'enqueued' )
);

$wccs_inline = wp_scripts()->get_data( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE, 'before' );

wccs_proof_check(
	'The payload is written before the bundle, so it is there when the bundle runs',
	is_array( $wccs_inline )
		&& array() !== $wccs_inline
		&& false !== strpos( implode( '', $wccs_inline ), 'window.wccsBlocks' ),
	'inline=' . count( (array) $wccs_inline ) . ' block(s)'
);

wp_dequeue_script( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::enqueue( false, $wccs_payload );

wccs_proof_check(
	'And not enqueued on a request that is not the Blocks checkout',
	! wp_script_is( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE, 'enqueued' )
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Where the components are proven',
	'The components are JavaScript. tests/js/blocks/fields.test.js renders each one in jsdom and operates it: the control its type calls for, the value it reports, the required and invalid states it announces, the mask that formats what is typed, and the keyboard the pattern asks for. This harness proves the other half — that the server publishes exactly the fields that need one.'
);

wccs_proof_note(
	'What is deliberately not claimed',
	'The registration of these components into the live Blocks checkout belongs to the integration task: a field that is drawn but whose value has nowhere to go is worse than a field that is not drawn. The seam is in resources/blocks/index.js, and it reports no_blocks_checkout_api rather than pretending.'
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
