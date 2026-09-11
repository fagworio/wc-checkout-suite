<?php
/**
 * WCCS-023 proof harness — order field values.
 *
 * Task:   WCCS-023 "Implementar OrderFieldsService"
 * Phase:  F04
 * Accept: "Valores tipados, zeros e false preservados; autoridade única por campo."
 *
 * ADR-0001 asks for this task specifically: typed values preserving `0`, `false`
 * and an empty array, in HPOS on and off. Both backends are exercised here.
 *
 * HPOS is authoritative on this store. The legacy backend is reached by
 * overriding WooCommerce's own `woocommerce_order_data_store` filter at a
 * priority above the controller that sets it — a plugin-local, reversible
 * override, removed in the same run. It selects the data store the HPOS-off
 * configuration uses; it does not change the store's authority, which is a site
 * setting outside the plugin root and is not touched.
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
 * Compares a value by type as well as by content.
 *
 * `assertSame` in spirit: `0 == '0'` is exactly the failure this task is about,
 * so the check has to be strict.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual   Observed value.
 * @return bool
 */
function wccs_proof_same( $expected, $actual ): bool {
	return gettype( $expected ) === gettype( $actual ) && $expected === $actual;
}

/**
 * Renders a value for a diagnostic line.
 *
 * @param mixed $value Value.
 * @return string
 */
function wccs_proof_show( $value ): string {
	return gettype( $value ) . '(' . wp_json_encode( $value ) . ')';
}

/**
 * Builds a field definition payload.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_def( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
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
 * Writes a document straight into a slot, bypassing the routes.
 *
 * @param string            $slot     Slot name.
 * @param array<int, mixed> $fields   Fields.
 * @param array<int, mixed> $sections Sections.
 * @param int               $revision Revision number.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields, array $sections = array(), int $revision = 7 ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => $revision,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => $sections,
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * Whether a specific object method is hooked on a tag at a priority.
 *
 * @param string $tag      Hook name.
 * @param int    $priority Priority.
 * @param string $class    Expected class name.
 * @param string $method   Expected method name.
 * @return bool
 */
function wccs_proof_hooked( string $tag, int $priority, string $class, string $method ): bool {
	if ( ! isset( $GLOBALS['wp_filter'][ $tag ] ) ) {
		return false;
	}

	$hook = $GLOBALS['wp_filter'][ $tag ];

	if ( ! isset( $hook->callbacks[ $priority ] ) ) {
		return false;
	}

	foreach ( $hook->callbacks[ $priority ] as $callback ) {
		$function = $callback['function'] ?? null;

		if (
			is_array( $function )
			&& isset( $function[0], $function[1] )
			&& $function[0] instanceof $class
			&& $method === $function[1]
		) {
			return true;
		}
	}

	return false;
}

/**
 * Every hook this plugin registers on the storefront half.
 *
 * @return array<int, string>
 */
function wccs_proof_plugin_hooks(): array {
	$found = array();

	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! isset( $hook->callbacks ) ) {
			continue;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) ) {
					continue;
				}

				if ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) {
					continue;
				}

				$class = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

				if ( 0 !== strpos( $class, 'WCCheckoutSuite\\Checkout\\Classic\\' ) ) {
					continue;
				}

				$found[] = $tag . '@' . $priority . ':' . $function[1];
			}
		}
	}

	sort( $found );

	return $found;
}

/**
 * Rows in a table for one order id.
 *
 * @param string $table Table name without prefix.
 * @param string $column Column holding the order id.
 * @param int    $id     Order id.
 * @return int
 */
function wccs_proof_rows( string $table, string $column, int $id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the store under test wrote where it claims to.
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", $id )
	);
}

/**
 * Rows in a meta table for one order id and one meta key.
 *
 * @param string $table Table name without prefix.
 * @param string $column Column holding the order id.
 * @param int    $id     Order id.
 * @param string $key    Meta key.
 * @return int
 */
function wccs_proof_meta_rows( string $table, string $column, int $id, string $key ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the store under test wrote where it claims to.
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d AND meta_key = %s", $id, $key )
	);
}

/**
 * Rows in wp_posts for one post id.
 *
 * @param int $id Post id.
 * @return int
 */
function wccs_proof_posts( int $id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the store under test wrote where it claims to.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id ) );
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
 * Test subclass that exposes WooCommerce's protected validator and builds a form
 * from the document that is stored at the time it is asked.
 */
class WCCS_Proof_Checkout extends WC_Checkout {

	/**
	 * Exposes the protected validator.
	 *
	 * @param array    $data   Posted values.
	 * @param WP_Error $errors Error collection.
	 * @return void
	 */
	public function wccs_validate_posted_data( array &$data, WP_Error &$errors ): void {
		$this->validate_posted_data( $data, $errors );
	}
}

$wccs_options_before = wccs_proof_option_count();
$wccs_order_ids      = array();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-023 proof — order field values' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_draft_slot     = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT;
$wccs_published_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;
$wccs_service        = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );

// ---------------------------------------------------------------------------
// 1. The writer is on the hook WooCommerce fires before it saves the order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The writer is wired to order creation' );

wccs_proof_check(
	'The values are written on the frame WooCommerce saves the order in',
	wccs_proof_hooked(
		'woocommerce_checkout_create_order',
		20,
		'WCCheckoutSuite\\Checkout\\Classic\\ClassicOrderFields',
		'persist'
	),
	'woocommerce_checkout_create_order fires before $order->save()'
);

$wccs_hooks = wccs_proof_plugin_hooks();

wccs_proof_check(
	'The storefront half grew by exactly one hook in this task',
	array(
		'woocommerce_after_checkout_validation@20:collect_errors',
		'woocommerce_checkout_create_order@20:persist',
		'woocommerce_checkout_fields@20:filter_fields',
		'woocommerce_checkout_posted_data@20:normalize_posted_data',
	) === $wccs_hooks,
	'found ' . wp_json_encode( $wccs_hooks )
);

wccs_proof_check(
	'The storage keys are the ones ADR-0001 and section 13 name',
	'_wccs_fields' === \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS
		&& '_wccs_schema_revision' === \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_REVISION,
	\WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS . ', ' . \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_REVISION
);

// ---------------------------------------------------------------------------
// 2. Typed values, HPOS authoritative.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Typed values (HPOS)' );

$wccs_defs = array(
	wccs_proof_def( 'wccs_cpf' ),
	wccs_proof_def( 'wccs_consent', array( 'type' => 'checkbox' ) ),
	wccs_proof_def( 'wccs_qty', array( 'type' => 'number' ) ),
	wccs_proof_def( 'wccs_weight', array( 'type' => 'number' ) ),
	wccs_proof_def( 'wccs_notes' ),
	wccs_proof_def( 'wccs_tags' ),
	wccs_proof_def( 'wccs_first_name', array( 'origin' => 'core' ) ),
);

$wccs_typed = array(
	'wccs_cpf'        => '12345678909',
	'wccs_consent'    => false,
	'wccs_qty'        => 0,
	'wccs_weight'     => 0.0,
	'wccs_notes'      => '',
	'wccs_tags'       => array(),
	'wccs_first_name' => 'Ana',
);

$wccs_order = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_order ) ) {
	wccs_proof_check( 'Order created for the HPOS section', false, $wccs_order->get_error_message() );
} else {
	$wccs_hpos_id            = $wccs_order->get_id();
	$wccs_order_ids[]        = $wccs_hpos_id;
	$wccs_stored             = $wccs_service->write( $wccs_order, $wccs_typed, $wccs_defs, 7 );
	$wccs_order->save();

	wccs_proof_check(
		'The writer reports what it stored',
		array( 'wccs_cpf', 'wccs_consent', 'wccs_qty', 'wccs_weight', 'wccs_notes', 'wccs_tags' ) === $wccs_stored->ids(),
		'stored=' . wp_json_encode( $wccs_stored->ids() )
	);

	$wccs_fresh = wc_get_order( $wccs_hpos_id );

	wccs_proof_check(
		'Order re-read through wc_get_order()',
		$wccs_fresh instanceof WC_Order,
		'class=' . ( is_object( $wccs_fresh ) ? get_class( $wccs_fresh ) : 'none' )
	);

	wccs_proof_check(
		'The order is stored in the HPOS store',
		false !== strpos( $wccs_fresh->get_data_store()->get_current_class_name(), 'OrdersTableDataStore' ),
		$wccs_fresh->get_data_store()->get_current_class_name()
	);

	$wccs_read = $wccs_service->read( $wccs_fresh );

	foreach ( $wccs_typed as $wccs_field => $wccs_value ) {
		if ( 'wccs_first_name' === $wccs_field ) {
			continue;
		}

		$wccs_got = $wccs_read->get( $wccs_field, '__absent__' );

		wccs_proof_check(
			'Value and type survive the store: ' . $wccs_field,
			wccs_proof_same( $wccs_value, $wccs_got ),
			'expected ' . wccs_proof_show( $wccs_value ) . ' got ' . wccs_proof_show( $wccs_got )
		);
	}

	wccs_proof_check(
		'An empty string is stored, not dropped as absent',
		$wccs_read->has( 'wccs_notes' ),
		"has('wccs_notes')=" . var_export( $wccs_read->has( 'wccs_notes' ), true )
	);

	wccs_proof_check(
		'An empty list is stored, not dropped as empty',
		$wccs_read->has( 'wccs_tags' ),
		"has('wccs_tags')=" . var_export( $wccs_read->has( 'wccs_tags' ), true )
	);

	wccs_proof_check(
		'A field WooCommerce owns was not stored by the Suite',
		! $wccs_read->has( 'wccs_first_name' ),
		'origin=core, so the value stays where WooCommerce put it'
	);

	$wccs_state = $wccs_service->read_status( $wccs_fresh );

	wccs_proof_check(
		'The order reports itself readable',
		'readable' === $wccs_state['state'],
		'state=' . $wccs_state['state']
	);

	wccs_proof_check(
		'The schema revision is stored with the values',
		7 === $wccs_state['revision'],
		'revision=' . var_export( $wccs_state['revision'], true )
	);

	$wccs_payload = json_decode( (string) $wccs_fresh->get_meta( '_wccs_fields', true, 'edit' ), true );

	wccs_proof_check(
		'The payload carries its own format version',
		1 === ( $wccs_payload['format'] ?? null ),
		'format=' . var_export( $wccs_payload['format'] ?? null, true )
	);

	wccs_proof_check(
		'The value is in the HPOS meta table',
		1 === wccs_proof_meta_rows( 'wc_orders_meta', 'order_id', $wccs_hpos_id, '_wccs_fields' )
			&& 1 === wccs_proof_meta_rows( 'wc_orders_meta', 'order_id', $wccs_hpos_id, '_wccs_schema_revision' ),
		'rows=' . wccs_proof_meta_rows( 'wc_orders_meta', 'order_id', $wccs_hpos_id, '_wccs_fields' )
	);

	wccs_proof_check(
		'Nothing was written to the legacy post meta store',
		0 === wccs_proof_rows( 'postmeta', 'post_id', $wccs_hpos_id ),
		'rows=' . wccs_proof_rows( 'postmeta', 'post_id', $wccs_hpos_id )
	);

	$wccs_forbidden = array();

	foreach ( $wccs_fresh->get_meta_data() as $wccs_meta ) {
		$wccs_key = (string) $wccs_meta->key;

		if ( 0 === strpos( $wccs_key, '_wc_' ) ) {
			$wccs_forbidden[] = $wccs_key;
		}
	}

	wccs_proof_check(
		'The Suite wrote no meta in the WooCommerce namespace',
		array() === $wccs_forbidden,
		'the core additional fields live under _wc_other/ and are not copied'
	);
}

// ---------------------------------------------------------------------------
// 3. Typed values, legacy backend.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Typed values (legacy backend)' );

$wccs_force_legacy = static function () {
	return 'WC_Order_Data_Store_CPT';
};

add_filter( 'woocommerce_order_data_store', $wccs_force_legacy, 1000 );

try {
	$wccs_legacy = wc_create_order( array( 'status' => 'pending' ) );

	if ( is_wp_error( $wccs_legacy ) ) {
		wccs_proof_check( 'Order created for the legacy section', false, $wccs_legacy->get_error_message() );
	} else {
		$wccs_legacy_id   = $wccs_legacy->get_id();
		$wccs_order_ids[] = $wccs_legacy_id;

		wccs_proof_check(
			'The order is stored in the legacy store',
			'WC_Order_Data_Store_CPT' === $wccs_legacy->get_data_store()->get_current_class_name(),
			$wccs_legacy->get_data_store()->get_current_class_name()
		);

		$wccs_service->write( $wccs_legacy, $wccs_typed, $wccs_defs, 7 );
		$wccs_legacy->save();

		$wccs_legacy_fresh = wc_get_order( $wccs_legacy_id );

		wccs_proof_check(
			'The legacy row is a real order post',
			'shop_order' === get_post_type( $wccs_legacy_id ),
			'post_type=' . var_export( get_post_type( $wccs_legacy_id ), true )
		);

		wccs_proof_check(
			'The value is in the legacy post meta store',
			1 === wccs_proof_meta_rows( 'postmeta', 'post_id', $wccs_legacy_id, '_wccs_fields' )
				&& true === $wccs_legacy_fresh->meta_exists( '_wccs_fields' ),
			'rows=' . wccs_proof_meta_rows( 'postmeta', 'post_id', $wccs_legacy_id, '_wccs_fields' )
		);

		$wccs_legacy_read = $wccs_service->read( $wccs_legacy_fresh );

		foreach ( $wccs_typed as $wccs_field => $wccs_value ) {
			if ( 'wccs_first_name' === $wccs_field ) {
				continue;
			}

			$wccs_got = $wccs_legacy_read->get( $wccs_field, '__absent__' );

			wccs_proof_check(
				'Same value and type on the legacy store: ' . $wccs_field,
				wccs_proof_same( $wccs_value, $wccs_got ),
				'expected ' . wccs_proof_show( $wccs_value ) . ' got ' . wccs_proof_show( $wccs_got )
			);
		}

		wccs_proof_check(
			'The legacy backend reports the same state',
			'readable' === $wccs_service->read_status( $wccs_legacy_fresh )['state']
				&& 7 === $wccs_service->read_status( $wccs_legacy_fresh )['revision']
		);

		$wccs_legacy_fresh->delete( true );

		wccs_proof_check(
			'Cleanup: legacy order removed from wp_posts and wp_postmeta',
			0 === wccs_proof_posts( $wccs_legacy_id ) && 0 === wccs_proof_rows( 'postmeta', 'post_id', $wccs_legacy_id ),
			'posts=' . wccs_proof_posts( $wccs_legacy_id ) . ' meta=' . wccs_proof_rows( 'postmeta', 'post_id', $wccs_legacy_id )
		);
	}
} catch ( Throwable $wccs_error ) {
	wccs_proof_check( 'Legacy section completed', false, get_class( $wccs_error ) . ': ' . $wccs_error->getMessage() );
}

remove_filter( 'woocommerce_order_data_store', $wccs_force_legacy, 1000 );

wccs_proof_check(
	'The legacy override was removed, so the store is HPOS again',
	false !== strpos( WC_Data_Store::load( 'order' )->get_current_class_name(), 'OrdersTableDataStore' ),
	WC_Data_Store::load( 'order' )->get_current_class_name()
);

// ---------------------------------------------------------------------------
// 4. Authority: a field that is not the Suite's is never stored.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. One authority per field' );

$wccs_authority_order = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_authority_order ) ) {
	wccs_proof_check( 'Order created for the authority section', false, $wccs_authority_order->get_error_message() );
} else {
	$wccs_authority_id   = $wccs_authority_order->get_id();
	$wccs_order_ids[]    = $wccs_authority_id;
	$wccs_only_core      = $wccs_service->write(
		$wccs_authority_order,
		array( 'billing_first_name' => 'Ana' ),
		$wccs_defs,
		7
	);
	$wccs_authority_order->save();

	wccs_proof_check(
		'A value whose definition is a core override stores nothing at all',
		$wccs_only_core->is_empty() && ! $wccs_authority_order->meta_exists( '_wccs_fields' ),
		'stored=' . wp_json_encode( $wccs_only_core->ids() )
	);

	$wccs_mixed = $wccs_service->write(
		$wccs_authority_order,
		array(
			'wccs_cpf'           => '12345678909',
			'billing_first_name' => 'Ana',
		),
		$wccs_defs,
		7
	);
	$wccs_authority_order->save();

	wccs_proof_check(
		'One call with one Suite field and one core field stores only the Suite field',
		array( 'wccs_cpf' ) === $wccs_mixed->ids(),
		'stored=' . wp_json_encode( $wccs_mixed->ids() )
	);

	wccs_proof_check(
		'The core field is nowhere in the stored payload',
		false === strpos( (string) $wccs_authority_order->get_meta( '_wccs_fields', true, 'edit' ), 'billing_first_name' )
	);

	wccs_proof_check(
		'A definition the published schema does not contain is not stored',
		$wccs_service->write(
			$wccs_authority_order,
			array( 'wccs_not_in_schema' => 'x' ),
			$wccs_defs,
			7
		)->is_empty()
	);
}

// ---------------------------------------------------------------------------
// 5. A payload this build cannot read says so.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The storage slot reports its own state' );

$wccs_state_order = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_state_order ) ) {
	wccs_proof_check( 'Order created for the state section', false, $wccs_state_order->get_error_message() );
} else {
	$wccs_state_id    = $wccs_state_order->get_id();
	$wccs_order_ids[] = $wccs_state_id;

	wccs_proof_check(
		'An order with no Suite values reports absent',
		'absent' === $wccs_service->read_status( $wccs_state_order )['state'],
		'state=' . $wccs_service->read_status( $wccs_state_order )['state']
	);

	wccs_proof_check(
		'And it reads as empty rather than as an error',
		$wccs_service->read( $wccs_state_order )->is_empty()
	);

	$wccs_state_order->update_meta_data( '_wccs_fields', '{ "format": 2, "values": { "wccs_cpf": "x" } }' );
	$wccs_state_order->save();

	$wccs_unsupported = $wccs_service->read_status( $wccs_state_order );

	wccs_proof_check(
		'A payload from a newer build is reported, not read as empty',
		'unsupported_format' === $wccs_unsupported['state'] && 2 === $wccs_unsupported['format'],
		'state=' . $wccs_unsupported['state'] . ' format=' . var_export( $wccs_unsupported['format'], true )
	);

	wccs_proof_check(
		'And it does not leak into the values',
		$wccs_service->read( $wccs_state_order )->is_empty()
	);

	$wccs_state_order->update_meta_data( '_wccs_fields', 'not json at all' );
	$wccs_state_order->save();

	wccs_proof_check(
		'A corrupt payload is reported as corrupt',
		'corrupt' === $wccs_service->read_status( $wccs_state_order )['state'],
		'state=' . $wccs_service->read_status( $wccs_state_order )['state']
	);
}

// ---------------------------------------------------------------------------
// 6. The checkout writes what it validated.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. From the submitted form to the order' );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def( 'wccs_cpf', array( 'normalizer' => 'digits' ) ),
		wccs_proof_def( 'wccs_consent', array( 'type' => 'checkbox' ) ),
		wccs_proof_def( 'wccs_optin', array( 'type' => 'checkbox' ) ),
		wccs_proof_def( 'wccs_qty', array( 'type' => 'number' ) ),
		wccs_proof_def( 'wccs_notes' ),
		wccs_proof_def( 'billing_first_name', array( 'origin' => 'core', 'label' => 'Primeiro nome' ) ),
	),
	array(),
	7
);

$wccs_checkout = new WCCS_Proof_Checkout();
$wccs_form     = $wccs_checkout->get_checkout_fields();

wccs_proof_check(
	'The Suite fields are on the checkout form',
	isset( $wccs_form['billing']['wccs_cpf'], $wccs_form['billing']['wccs_consent'] )
);

$_POST = array(
	'woocommerce-process-checkout-nonce' => 'forged-by-the-harness',
	'payment_method'                     => 'bacs',
	'billing_first_name'                 => 'Ana',
	'billing_last_name'                  => 'Silva',
	'billing_country'                    => 'BR',
	'billing_email'                      => 'ana@example.test',
	'wccs_cpf'                           => '123.456.789-09',
	'wccs_optin'                         => '1',
	'wccs_qty'                           => '0',
	'wccs_notes'                         => '   ',
	// wccs_consent is deliberately not posted: the box was left unchecked.
);

$wccs_posted = $wccs_checkout->get_posted_data();
$wccs_errors = new WP_Error();

do_action( 'woocommerce_after_checkout_validation', $wccs_posted, $wccs_errors );

$wccs_error_codes = $wccs_errors->get_error_codes();

wccs_proof_check(
	'The submission produced no errors, as an accepted checkout would',
	array() === $wccs_error_codes,
	'codes=' . wp_json_encode( $wccs_error_codes )
);

$wccs_order = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_order ) ) {
	wccs_proof_check( 'Order created for the wiring section', false, $wccs_order->get_error_message() );
} else {
	$wccs_wired_id    = $wccs_order->get_id();
	$wccs_order_ids[] = $wccs_wired_id;

	// WooCommerce sets the native fields from the posted data in the same method
	// that fires the action below; doing it here reproduces that, so the assertion
	// at the end of this section is about the Suite not touching them.
	$wccs_order->set_billing_first_name( (string) ( $wccs_posted['billing_first_name'] ?? '' ) );

	do_action( 'woocommerce_checkout_create_order', $wccs_order, $wccs_posted );
	$wccs_order->save();

	$wccs_wired     = wc_get_order( $wccs_wired_id );
	$wccs_wired_set = $wccs_service->read( $wccs_wired );

	wccs_proof_check(
		'The normalized value is on the order',
		wccs_proof_same( '12345678909', $wccs_wired_set->get( 'wccs_cpf', '__absent__' ) ),
		'posted 123.456.789-09 -> ' . wccs_proof_show( $wccs_wired_set->get( 'wccs_cpf', '__absent__' ) )
	);

	wccs_proof_check(
		'An unchecked box is stored as false, not as an empty string',
		wccs_proof_same( false, $wccs_wired_set->get( 'wccs_consent', '__absent__' ) ),
		'got ' . wccs_proof_show( $wccs_wired_set->get( 'wccs_consent', '__absent__' ) )
	);

	wccs_proof_check(
		'A checked box is stored as true',
		wccs_proof_same( true, $wccs_wired_set->get( 'wccs_optin', '__absent__' ) ),
		'got ' . wccs_proof_show( $wccs_wired_set->get( 'wccs_optin', '__absent__' ) )
	);

	wccs_proof_check(
		'A posted zero is stored as the number zero',
		wccs_proof_same( 0, $wccs_wired_set->get( 'wccs_qty', '__absent__' ) ),
		'posted "0" -> ' . wccs_proof_show( $wccs_wired_set->get( 'wccs_qty', '__absent__' ) )
	);

	wccs_proof_check(
		'A field posted as whitespace is stored as an empty string',
		$wccs_wired_set->has( 'wccs_notes' ) && wccs_proof_same( '', $wccs_wired_set->get( 'wccs_notes' ) ),
		'got ' . wccs_proof_show( $wccs_wired_set->get( 'wccs_notes', '__absent__' ) )
	);

	wccs_proof_check(
		'The overridden core field was not stored by the Suite',
		! $wccs_wired_set->has( 'billing_first_name' ),
		'stored=' . wp_json_encode( $wccs_wired_set->ids() )
	);

	wccs_proof_check(
		'The order carries the revision the values were captured against',
		7 === $wccs_service->read_status( $wccs_wired )['revision'],
		'revision=' . var_export( $wccs_service->read_status( $wccs_wired )['revision'], true )
	);

	wccs_proof_check(
		'And WooCommerce still owns its own field on the same order',
		'Ana' === $wccs_wired->get_billing_first_name(),
		'billing_first_name=' . $wccs_wired->get_billing_first_name()
	);
}

// ---------------------------------------------------------------------------
// 7. Saving a draft changes nothing here either.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. A draft does not change what is stored' );

wccs_proof_store(
	$wccs_draft_slot,
	array(
		wccs_proof_def( 'wccs_cpf', array( 'normalizer' => 'digits' ) ),
		wccs_proof_def( 'wccs_consent', array( 'type' => 'checkbox' ) ),
		wccs_proof_def( 'wccs_optin', array( 'type' => 'checkbox' ) ),
		wccs_proof_def( 'wccs_qty', array( 'type' => 'number', 'label' => 'Changed' ) ),
		wccs_proof_def( 'wccs_notes' ),
		wccs_proof_def( 'wccs_draft_only', array( 'type' => 'text' ) ),
		wccs_proof_def( 'billing_first_name', array( 'origin' => 'core', 'label' => 'Outro nome' ) ),
	),
	array(),
	9
);

$wccs_draft_checkout = new WCCS_Proof_Checkout();
$wccs_draft_posted   = $wccs_draft_checkout->get_posted_data();
$wccs_draft_order    = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_draft_order ) ) {
	wccs_proof_check( 'Order created for the draft section', false, $wccs_draft_order->get_error_message() );
} else {
	$wccs_draft_id    = $wccs_draft_order->get_id();
	$wccs_order_ids[] = $wccs_draft_id;

	do_action( 'woocommerce_checkout_create_order', $wccs_draft_order, $wccs_draft_posted );
	$wccs_draft_order->save();

	$wccs_draft_read = $wccs_service->read( wc_get_order( $wccs_draft_id ) );

	wccs_proof_check(
		'A field that exists only in the draft is not stored',
		! $wccs_draft_read->has( 'wccs_draft_only' ),
		'stored=' . wp_json_encode( $wccs_draft_read->ids() )
	);

	wccs_proof_check(
		'The revision recorded is the published one, not the draft revision',
		7 === $wccs_service->read_status( wc_get_order( $wccs_draft_id ) )['revision'],
		'draft revision 9 versus recorded ' . var_export( $wccs_service->read_status( wc_get_order( $wccs_draft_id ) )['revision'], true )
	);
}

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

$_POST = array();

foreach ( $wccs_order_ids as $wccs_cleanup_id ) {
	$wccs_cleanup_order = wc_get_order( $wccs_cleanup_id );

	if ( $wccs_cleanup_order instanceof WC_Order ) {
		$wccs_cleanup_order->delete( true );
	}
}

$wccs_residue = array();

foreach ( $wccs_order_ids as $wccs_cleanup_id ) {
	$wccs_residue[ $wccs_cleanup_id ] = array(
		'orders'   => wccs_proof_rows( 'wc_orders', 'id', $wccs_cleanup_id ),
		'hposmeta' => wccs_proof_rows( 'wc_orders_meta', 'order_id', $wccs_cleanup_id ),
		'postmeta' => wccs_proof_rows( 'postmeta', 'post_id', $wccs_cleanup_id ),
		'posts'    => wccs_proof_posts( $wccs_cleanup_id ),
	);
}

wccs_proof_check(
	'Every order this harness created was removed from every store',
	array() === array_filter(
		$wccs_residue,
		static function ( array $rows ): bool {
			return 0 !== array_sum( $rows );
		}
	),
	'orders=' . count( $wccs_order_ids ) . ' residue=' . wp_json_encode( $wccs_residue )
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'What the legacy section exercised',
	'It forced WooCommerce\'s own woocommerce_order_data_store filter to the CPT store, which is the data path the HPOS-off configuration uses for order meta. It did not change the store\'s authority: that is a site setting outside the plugin root, and switching it on a store with orders is a migration, not a test. The filter was removed in the same run and the assertion above confirms HPOS is authoritative again.'
);

wccs_proof_note(
	'Not exercised here',
	'A completed checkout. The order was created through wc_create_order() and the values were written by firing woocommerce_checkout_create_order, which is the hook WooCommerce itself fires; reaching it from a real POST needs a Classic checkout page, which is the open decision CLASSIC-TEST-SURFACE.'
);

wccs_proof_note(
	'Cross-reference',
	'The typed value set and the authority rule are covered by tests/Unit/Domain/Orders/OrderFieldValuesTest.php (10 specs).'
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
