<?php
/**
 * WCCS-002 proof harness — Classic field validation and HPOS persistence.
 *
 * Task:   WCCS-002 "Provar fluxo Classic e HPOS"
 * Phase:  F00
 * Accept: "Um campo de teste é validado e persistido com HPOS ligado e sincronização desligada."
 *
 * This is an F00 feasibility proof, NOT the F04 Classic adapter and NOT an integration test suite.
 * It creates exactly one order, verifies it, and deletes it again.
 *
 * Run from the Devilbox host:
 *
 *   docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
 *     /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval-file \
 *     /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite/tests/Integration/F00-wccs-002-classic-hpos-proof.php
 *
 * Exit code 0 = every assertion passed. Any failure = exit code 1.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'checks' => array(),
	'notes'  => array(),
);

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
	$ok = (bool) $condition;

	$GLOBALS['wccs_proof']['checks'][] = array(
		'label'  => $label,
		'ok'     => $ok,
		'detail' => $detail,
	);

	if ( $ok ) {
		++$GLOBALS['wccs_proof']['pass'];
	} else {
		++$GLOBALS['wccs_proof']['fail'];
	}

	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation (never fails the run).
 *
 * @param string $label Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array(
		'label'  => $label,
		'detail' => $detail,
	);
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

$wccs_test_field_key = 'billing_wccs_test_document';
$wccs_valid_value    = '12345678901';
$wccs_invalid_value  = '123';
$wccs_created_order  = null;

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-002 proof — Classic field validation + HPOS persistence' );
	wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. HPOS state — resolves open decision HPOS-SYNC-SEMANTICS by observation.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. HPOS state' );

try {
	$wccs_sync = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
	$wccs_ctrl = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

	wccs_proof_check(
		'HPOS: custom orders table is the authoritative store',
		true === $wccs_ctrl->custom_orders_table_usage_is_enabled(),
		'custom_orders_table_usage_is_enabled=' . var_export( $wccs_ctrl->custom_orders_table_usage_is_enabled(), true )
	);

	$wccs_sync_enabled = $wccs_sync->data_sync_is_enabled();
	wccs_proof_check(
		'HPOS: post/orders data synchronization is OFF',
		false === $wccs_sync_enabled,
		'data_sync_is_enabled=' . var_export( $wccs_sync_enabled, true )
			. ' | option=' . var_export( get_option( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, '<absent>' ), true )
	);

	wccs_proof_note(
		'HPOS: sync semantics resolved by observation',
		'data_sync_is_enabled() is `"yes" === get_option(...)` and the option is absent, so sync evaluates to false.'
	);
} catch ( Throwable $e ) {
	wccs_proof_check( 'HPOS: state could be read', false, $e->getMessage() );
}

// ---------------------------------------------------------------------------
// 2. Classic field registration through the official filter.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Classic field registration' );

add_filter(
	'woocommerce_checkout_fields',
	static function ( $fields ) use ( $wccs_test_field_key ) {
		$fields['billing'][ $wccs_test_field_key ] = array(
			'type'     => 'text',
			'label'    => 'Documento de teste (WCCS-002)',
			'required' => true,
			'class'    => array( 'form-row-wide' ),
			'priority' => 999,
		);
		return $fields;
	},
	10,
	1
);

// A fresh instance guarantees the field cache is empty, so the filter above is applied.
$wccs_checkout = new WC_Checkout();
$wccs_fields   = $wccs_checkout->get_checkout_fields( 'billing' );

wccs_proof_check(
	'Classic: test field registered via woocommerce_checkout_fields',
	isset( $wccs_fields[ $wccs_test_field_key ] ),
	'key=' . $wccs_test_field_key
);

wccs_proof_check(
	'Classic: registered field keeps its declared properties',
	isset( $wccs_fields[ $wccs_test_field_key ] )
		&& 'text' === $wccs_fields[ $wccs_test_field_key ]['type']
		&& true === $wccs_fields[ $wccs_test_field_key ]['required'],
	'type=' . ( $wccs_fields[ $wccs_test_field_key ]['type'] ?? '?' )
		. ' required=' . var_export( $wccs_fields[ $wccs_test_field_key ]['required'] ?? null, true )
);

// ---------------------------------------------------------------------------
// 3. Server-side validation.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Server-side validation' );

$wccs_validator_ran = false;

add_action(
	'woocommerce_after_checkout_validation',
	static function ( $data, $errors ) use ( $wccs_test_field_key, &$wccs_validator_ran ) {
		$wccs_validator_ran = true;

		if ( empty( $data[ $wccs_test_field_key ] ) ) {
			return;
		}

		$digits = preg_replace( '/\D/', '', (string) $data[ $wccs_test_field_key ] );

		if ( ! preg_match( '/^\d{11}$/', (string) $digits ) ) {
			$errors->add( $wccs_test_field_key . '_wccs_invalid', 'Documento de teste inválido.' );
		}
	},
	10,
	2
);

// 3a. Core contract: the field participates in WooCommerce's own validation loop.
$wccs_ref_posted = new ReflectionMethod( WC_Checkout::class, 'validate_posted_data' );
$wccs_ref_posted->setAccessible( true );

$wccs_data   = array( $wccs_test_field_key => '' );
$wccs_errors = new WP_Error();
$wccs_ref_posted->invokeArgs( $wccs_checkout, array( &$wccs_data, &$wccs_errors ) );
$wccs_codes = $wccs_errors->get_error_codes();

wccs_proof_check(
	'Classic: empty required field yields <key>_required from WooCommerce core',
	in_array( $wccs_test_field_key . '_required', $wccs_codes, true ),
	'codes=' . ( $wccs_codes ? implode( ',', $wccs_codes ) : '(none)' )
);

$wccs_data   = array( $wccs_test_field_key => $wccs_valid_value );
$wccs_errors = new WP_Error();
$wccs_ref_posted->invokeArgs( $wccs_checkout, array( &$wccs_data, &$wccs_errors ) );
$wccs_codes_valid = $wccs_errors->get_error_codes();

wccs_proof_check(
	'Classic: valid value raises no core error for the test field',
	! in_array( $wccs_test_field_key . '_required', $wccs_codes_valid, true ),
	'codes=' . ( $wccs_codes_valid ? implode( ',', $wccs_codes_valid ) : '(none)' )
);

// 3b. Suite validator inside the real validation phase (woocommerce_after_checkout_validation).
$wccs_phase_ran = false;

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

$wccs_ref_checkout = new ReflectionMethod( WC_Checkout::class, 'validate_checkout' );
$wccs_ref_checkout->setAccessible( true );

// phpcs:ignore WordPress.Security.NonceVerification.Missing -- proof harness, not a request handler.
$_POST = array( $wccs_test_field_key => $wccs_invalid_value );

$wccs_data   = array( $wccs_test_field_key => $wccs_invalid_value );
$wccs_errors = new WP_Error();

try {
	$wccs_ref_checkout->invokeArgs( $wccs_checkout, array( &$wccs_data, &$wccs_errors ) );
	$wccs_phase_ran = true;
} catch ( Throwable $e ) {
	wccs_proof_note(
		'Classic: full validate_checkout() phase could not run in this CLI context',
		$e->getMessage() . ' — falling back to a direct do_action of the same hook.'
	);
}

if ( ! $wccs_phase_ran ) {
	$wccs_data   = array( $wccs_test_field_key => $wccs_invalid_value );
	$wccs_errors = new WP_Error();
	do_action( 'woocommerce_after_checkout_validation', $wccs_data, $wccs_errors );
}

$wccs_codes_invalid = $wccs_errors->get_error_codes();

wccs_proof_check(
	'Classic: validation phase reached the Suite validator',
	$wccs_validator_ran,
	'phase=' . ( $wccs_phase_ran ? 'validate_checkout()' : 'direct do_action fallback' )
);

wccs_proof_check(
	'Classic: Suite validator blocked the invalid value server-side',
	in_array( $wccs_test_field_key . '_wccs_invalid', $wccs_codes_invalid, true ),
	'codes=' . ( $wccs_codes_invalid ? implode( ',', $wccs_codes_invalid ) : '(none)' )
);

if ( $wccs_phase_ran ) {
	$unrelated = array_values(
		array_diff(
			$wccs_codes_invalid,
			array( $wccs_test_field_key . '_wccs_invalid' )
		)
	);
	wccs_proof_note(
		'Classic: unrelated errors produced by the unconfigured store',
		$unrelated ? implode( ',', $unrelated ) : '(none)'
	);
}

// ---------------------------------------------------------------------------
// 4. Persistence through the order CRUD with HPOS authoritative.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Persistence (HPOS)' );

global $wpdb;

try {
	$wccs_order = wc_create_order( array( 'status' => 'pending' ) );

	if ( is_wp_error( $wccs_order ) ) {
		wccs_proof_check( 'Order created through wc_create_order()', false, $wccs_order->get_error_message() );
	} else {
		$wccs_created_order = $wccs_order;
		$wccs_order_id      = $wccs_order->get_id();

		wccs_proof_check( 'Order created through wc_create_order()', $wccs_order_id > 0, 'order_id=' . $wccs_order_id );

		$wccs_payload = array(
			$wccs_test_field_key => array(
				'value'          => $wccs_valid_value,
				'type'           => 'text',
				'preset'         => 'br.cpf',
				'schema_version' => 1,
			),
		);

		$wccs_order->update_meta_data( '_wccs_fields', wp_json_encode( $wccs_payload ) );
		$wccs_order->save();

		$wccs_fresh = wc_get_order( $wccs_order_id );

		wccs_proof_check( 'Order re-read through wc_get_order()', $wccs_fresh instanceof WC_Order, 'class=' . ( is_object( $wccs_fresh ) ? get_class( $wccs_fresh ) : 'none' ) );

		// WC_Data::get_data_store() returns the WC_Data_Store proxy; the concrete store is
		// revealed by get_current_class_name().
		$wccs_store_class = is_object( $wccs_fresh )
			? $wccs_fresh->get_data_store()->get_current_class_name()
			: '(none)';
		wccs_proof_check(
			'Order data store is the HPOS store',
			false !== strpos( $wccs_store_class, 'OrdersTableDataStore' ),
			$wccs_store_class
		);

		$wccs_read = json_decode( (string) $wccs_fresh->get_meta( '_wccs_fields' ), true );
		wccs_proof_check(
			'Meta round-trips through WC_Order CRUD unchanged',
			is_array( $wccs_read )
				&& isset( $wccs_read[ $wccs_test_field_key ]['value'] )
				&& $wccs_valid_value === $wccs_read[ $wccs_test_field_key ]['value'],
			'read=' . wp_json_encode( $wccs_read )
		);

		$wccs_wc_orders = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $wccs_order_id )
		);
		wccs_proof_check( 'Order row exists in wp_wc_orders', 1 === $wccs_wc_orders, 'rows=' . $wccs_wc_orders );

		$wccs_in_hpos_meta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
				$wccs_order_id,
				'_wccs_fields'
			)
		);
		wccs_proof_check( 'Value stored in wp_wc_orders_meta', 1 === $wccs_in_hpos_meta, 'rows=' . $wccs_in_hpos_meta );

		$wccs_in_postmeta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$wccs_order_id,
				'_wccs_fields'
			)
		);
		wccs_proof_check( 'Value NOT written to the legacy wp_postmeta store', 0 === $wccs_in_postmeta, 'rows=' . $wccs_in_postmeta );

		$wccs_placeholder = $wpdb->get_var(
			$wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $wccs_order_id )
		);
		wccs_proof_note(
			'Legacy post row for this order',
			null === $wccs_placeholder ? '(none)' : (string) $wccs_placeholder
		);

		// ---------------------------------------------------------------
		// 5. Cleanup.
		// ---------------------------------------------------------------
		wccs_proof_out( '' );
		wccs_proof_out( '5. Cleanup' );

		$wccs_fresh->delete( true );

		$wccs_after_orders = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $wccs_order_id )
		);
		$wccs_after_meta = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d", $wccs_order_id )
		);

		wccs_proof_check( 'Cleanup: order removed from wp_wc_orders', 0 === $wccs_after_orders, 'rows=' . $wccs_after_orders );
		wccs_proof_check( 'Cleanup: order meta removed from wp_wc_orders_meta', 0 === $wccs_after_meta, 'rows=' . $wccs_after_meta );
	}
} catch ( Throwable $e ) {
	wccs_proof_check( 'Persistence section completed', false, get_class( $e ) . ': ' . $e->getMessage() );
}

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
