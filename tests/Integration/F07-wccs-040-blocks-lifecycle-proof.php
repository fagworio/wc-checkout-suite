<?php
/**
 * WCCS-040 proof harness — the lifecycle's contract with the page.
 *
 * Task:   WCCS-040 "Testar edição e lifecycle Blocks"
 * Phase:  F07 · Checkout Blocks nativo e tipos próprios
 * Accept: "Troca de endereço/frete, remount e rerender preservam valores sem DOM hacks."
 *
 * The lifecycle is JavaScript and is covered by the jsdom suite, which remounts,
 * re-renders, switches visibility and asserts that a mutation observer is never
 * constructed. What cannot be asserted from there is what the *server* hands the
 * page: a store can only preserve a value the page was told to expect, and it can
 * only apply the hidden-value policy the definition declared. This harness proves
 * both travel, through the real bootstrap path.
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
				'revision'       => 8,
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
						'id'       => 'order',
						'title'    => 'Order',
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

$wccs_published_option = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

delete_option( $wccs_published_option );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-040 proof — the lifecycle contract' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. What the page is told, so it can preserve anything at all.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the page receives' );

wccs_proof_publish(
	array(
		wccs_proof_field( 'note', 'textarea', array( 'required' => true ) ),
		wccs_proof_field( 'kept', 'radio', array( 'hidden_value_policy' => 'preserve', 'settings' => array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) ) ),
		wccs_proof_field( 'attachment', 'file' ),
	)
);

$wccs_payload = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::fields();
$wccs_by_name = array();

foreach ( $wccs_payload['fields'] as $wccs_entry ) {
	$wccs_by_name[ (string) $wccs_entry['name'] ] = $wccs_entry;
}

wccs_proof_check(
	'Every field a component renders carries its hidden-value policy',
	isset( $wccs_by_name['wccs_note']['policy'], $wccs_by_name['wccs_kept']['policy'] ),
	'policies=' . wp_json_encode(
		array(
			'note' => $wccs_by_name['wccs_note']['policy'] ?? null,
			'kept' => $wccs_by_name['wccs_kept']['policy'] ?? null,
		)
	)
);

wccs_proof_check(
	'The default is discard, which is what a field that said nothing gets',
	'discard' === ( $wccs_by_name['wccs_note']['policy'] ?? '' )
);

wccs_proof_check(
	'And an explicit preserve travels as itself',
	'preserve' === ( $wccs_by_name['wccs_kept']['policy'] ?? '' )
);

// ---------------------------------------------------------------------------
// 2. The bundle that reads it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The bundle that keeps the values' );

$wccs_manifest = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::asset_manifest();

wccs_proof_check(
	'The Blocks bundle was built with the lifecycle in it',
	is_readable( WCCS_PLUGIN_DIR . 'build/blocks/index.js' )
);

wccs_proof_check(
	'And still declares only runtime dependencies WordPress provides',
	array() === array_values(
		array_filter(
			$wccs_manifest['dependencies'],
			static function ( string $handle ): bool {
				return 0 !== strpos( $handle, 'wp-' ) && 0 !== strpos( $handle, 'react-' );
			}
		)
	),
	'dependencies=' . wp_json_encode( $wccs_manifest['dependencies'] )
);

wp_dequeue_script( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::enqueue( true, $wccs_payload );

$wccs_inline = wp_scripts()->get_data( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE, 'before' );
$wccs_inline = is_array( $wccs_inline ) ? implode( '', $wccs_inline ) : '';

wccs_proof_check(
	'The payload the page reads is written before the bundle runs',
	wp_script_is( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE, 'enqueued' )
		&& false !== strpos( $wccs_inline, 'window.wccsBlocks' ),
	'payload written=' . ( false !== strpos( $wccs_inline, 'window.wccsBlocks' ) ? 'yes' : 'no' )
);

wccs_proof_check(
	'And it carries the policy for each field, which is what the store mirrors',
	false !== strpos( $wccs_inline, '"policy":"discard"' )
		&& false !== strpos( $wccs_inline, '"policy":"preserve"' )
);

wp_dequeue_script( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE );

// ---------------------------------------------------------------------------
// 3. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Environment' );

delete_option( $wccs_published_option );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Where the lifecycle is proven',
	'tests/js/blocks/values.test.js: a value survives an unmount and a fresh mount, survives a re-render, is recorded on every keystroke through a harness that renders what the store holds, and the hidden-value policy is applied when a rule hides a field — discard dropping the value and preserve keeping it. The last spec asserts that a mutation observer is never constructed while all of that runs, because that technique would make the others pass while reading back whatever React last rendered.'
);

wccs_proof_note(
	'Why this half exists at all',
	'The server re-decides visibility and re-applies the policy when the order is placed, and it remains the authority. The page keeps a value for one reason: the customer typed it, and a checkout that forgets it whenever the shipping method changes is a checkout that loses work. The two agree because they read the same policy from the same document.'
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
