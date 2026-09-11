<?php
/**
 * WCCS-003 proof harness — Checkout Blocks additional fields, custom controlled
 * field via the Store API extensions namespace, server-side error, and
 * persistence to the order. No core DOM manipulation is performed or required.
 *
 * Task:   WCCS-003 "Provar fluxo Blocks"
 * Phase:  F00
 * Accept: "Text, campo customizado controlado e erro server-side chegam ao pedido
 *          sem manipulação do DOM core."
 *
 * This is an F00 feasibility proof, NOT the F07 Blocks adapter.
 * It creates exactly one order, verifies it, and deletes it again.
 *
 * Run from the Devilbox host:
 *
 *   docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
 *     /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval-file \
 *     /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite/tests/Integration/F00-wccs-003-blocks-proof.php
 *
 * Exit code 0 = every assertion passed.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;

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

$wccs_ns           = 'wc-checkoutsuite';
$wccs_text_id      = $wccs_ns . '/test-document';
$wccs_native_types = array();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-003 proof — Checkout Blocks / Store API' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. Blocks / Store API availability.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Blocks API availability' );

wccs_proof_check(
	'Blocks bootstrap ran (woocommerce_blocks_loaded)',
	did_action( 'woocommerce_blocks_loaded' ) > 0,
	'did_action=' . did_action( 'woocommerce_blocks_loaded' )
);

wccs_proof_check(
	'Public registration function exists',
	function_exists( 'woocommerce_register_additional_checkout_field' )
);

wccs_proof_check(
	'Store API endpoint-data helper exists',
	function_exists( 'woocommerce_store_api_register_endpoint_data' )
);

$wccs_registry = null;

try {
	$wccs_registry = Package::container()->get( CheckoutFields::class );
	wccs_proof_check( 'CheckoutFields registry resolved from the Blocks container', is_object( $wccs_registry ), get_class( $wccs_registry ) );
} catch ( Throwable $e ) {
	wccs_proof_check( 'CheckoutFields registry resolved from the Blocks container', false, $e->getMessage() );
}

// ---------------------------------------------------------------------------
// 2. Native contract: supported locations and types, probed by observation.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Native additional-field contract' );

$wccs_wrong = array();
add_action(
	'doing_it_wrong_run',
	static function ( $function_name, $message ) use ( &$wccs_wrong ) {
		$wccs_wrong[] = (string) $message;
	},
	10,
	2
);

// A deliberately unsupported type, to extract the supported list from WooCommerce itself.
woocommerce_register_additional_checkout_field(
	array(
		'id'       => $wccs_ns . '/probe-date',
		'label'    => 'Probe date',
		'location' => 'contact',
		'type'     => 'date',
	)
);

$wccs_types_message = '';
foreach ( $wccs_wrong as $wccs_message ) {
	if ( false !== strpos( $wccs_message, 'supported types are' ) ) {
		$wccs_types_message = $wccs_message;
	}
}

if ( preg_match( '/supported types are:\s*(.+?)\./i', $wccs_types_message, $wccs_m ) ) {
	$wccs_native_types = array_map( 'trim', explode( ',', $wccs_m[1] ) );
}

wccs_proof_check(
	'Native types reported by WooCommerce 11.1.0',
	! empty( $wccs_native_types ),
	implode( ', ', $wccs_native_types )
);

wccs_proof_check(
	'"date" is NOT a natively supported additional-field type in this version',
	! in_array( 'date', $wccs_native_types, true ) && ! empty( $wccs_types_message ),
	'"date" rejected; roadmap section 8 listed it as available'
);

wccs_proof_note(
	'Roadmap correction required',
	'ROADMAP.md section 8 and WCCS-036 assume text/select/checkbox/date. The installed WooCommerce 11.1.0 supports only: ' . implode( ', ', $wccs_native_types ) . '. A "date" additional field therefore requires a custom controlled component (F07/WCCS-037).'
);

// Register one native text field per documented location to prove they are accepted.
$wccs_locations = array( 'contact', 'address', 'order' );

foreach ( $wccs_locations as $wccs_location ) {
	woocommerce_register_additional_checkout_field(
		array(
			'id'       => $wccs_ns . '/loc-' . $wccs_location,
			'label'    => 'Location probe ' . $wccs_location,
			'location' => $wccs_location,
			'type'     => 'text',
		)
	);
}

$wccs_registered = $wccs_registry ? $wccs_registry->get_additional_fields() : array();

foreach ( $wccs_locations as $wccs_location ) {
	$wccs_key = $wccs_ns . '/loc-' . $wccs_location;
	wccs_proof_check(
		"Location '{$wccs_location}' accepts a native text field",
		isset( $wccs_registered[ $wccs_key ] ),
		'id=' . $wccs_key
	);
}

wccs_proof_check(
	'Unsupported-type field was NOT registered',
	! isset( $wccs_registered[ $wccs_ns . '/probe-date' ] )
);

// ---------------------------------------------------------------------------
// 3. Server-side validation — the field's own validator and the location hook.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Server-side validation' );

woocommerce_register_additional_checkout_field(
	array(
		'id'                => $wccs_text_id,
		'label'             => 'Documento de teste (WCCS-003)',
		'location'          => 'contact',
		'type'              => 'text',
		'required'          => true,
		'sanitize_callback' => static function ( $value ) {
			return preg_replace( '/\D/', '', (string) $value );
		},
		'validate_callback' => static function ( $value, $field ) {
			$digits = preg_replace( '/\D/', '', (string) $value );

			if ( '' === $digits ) {
				return true;
			}

			if ( ! preg_match( '/^\d{11}$/', (string) $digits ) ) {
				return new WP_Error(
					'wccs_invalid_test_document',
					sprintf( 'O campo %s não é válido.', $field['label'] )
				);
			}

			return true;
		},
	)
);

$wccs_registered = $wccs_registry->get_additional_fields();
$wccs_field      = $wccs_registered[ $wccs_text_id ] ?? array();

wccs_proof_check(
	'Native text field registered with type/location',
	! empty( $wccs_field )
		&& 'text' === ( $wccs_field['type'] ?? '' )
		&& 'contact' === ( $wccs_field['location'] ?? '' ),
	'id=' . $wccs_text_id
);

wccs_proof_check(
	'Sanitize callback strips punctuation',
	'12345678901' === $wccs_registry->sanitize_field( $wccs_text_id, '123.456.789-01' ),
	'sanitize_field("123.456.789-01")'
);

$wccs_errors_invalid = $wccs_registry->validate_field( $wccs_field, '123' );
$wccs_codes_invalid  = is_wp_error( $wccs_errors_invalid ) ? $wccs_errors_invalid->get_error_codes() : array();

wccs_proof_check(
	'validate_field() returns a server-side WP_Error for an invalid value',
	in_array( 'wccs_invalid_test_document', $wccs_codes_invalid, true ),
	'codes=' . ( $wccs_codes_invalid ? implode( ',', $wccs_codes_invalid ) : '(none)' )
);

$wccs_errors_valid = $wccs_registry->validate_field( $wccs_field, '12345678901' );
$wccs_codes_valid  = is_wp_error( $wccs_errors_valid ) ? $wccs_errors_valid->get_error_codes() : array();

wccs_proof_check(
	'validate_field() is clean for a valid value',
	! in_array( 'wccs_invalid_test_document', $wccs_codes_valid, true ),
	'codes=' . ( $wccs_codes_valid ? implode( ',', $wccs_codes_valid ) : '(none)' )
);

// Location-level validation hook, as used by the Store API checkout route.
add_action(
	'woocommerce_blocks_validate_location_contact_fields',
	static function ( $errors, $fields, $group ) use ( $wccs_text_id ) {
		if ( isset( $fields[ $wccs_text_id ] ) && 'blocked' === $fields[ $wccs_text_id ] ) {
			$errors->add( 'wccs_location_blocked', 'Bloqueado pela validação de localização.' );
		}
	},
	10,
	3
);

$wccs_location_errors = $wccs_registry->validate_fields_for_location(
	array( $wccs_text_id => 'blocked' ),
	'contact',
	'other'
);
$wccs_location_codes = is_wp_error( $wccs_location_errors ) ? $wccs_location_errors->get_error_codes() : array();

wccs_proof_check(
	'Location-level validation hook produces a server-side error',
	in_array( 'wccs_location_blocked', $wccs_location_codes, true ),
	'codes=' . ( $wccs_location_codes ? implode( ',', $wccs_location_codes ) : '(none)' )
);

wccs_proof_check(
	'Store API CheckoutSchema owns the additional-fields validate callback',
	method_exists( 'Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema', 'validate_additional_fields' )
);

// ---------------------------------------------------------------------------
// 4. Custom controlled field through the Store API extensions namespace.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Custom field via Store API extensions namespace' );

try {
	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => 'checkout',
			'namespace'       => $wccs_ns,
			'schema_callback' => static function () {
				return array(
					'controlled_document' => array(
						'description' => 'Controlled document value supplied by the Suite component.',
						'type'        => 'string',
						'readonly'    => true,
					),
				);
			},
			'data_callback'   => static function () {
				return array( 'controlled_document' => '12345678901' );
			},
			'schema_type'     => ARRAY_A,
		)
	);

	$wccs_extend = StoreApi::container()->get( ExtendSchema::class );

	$wccs_schema = (array) $wccs_extend->get_endpoint_schema( 'checkout' );
	wccs_proof_check(
		'Checkout endpoint schema exposes the Suite namespace',
		isset( $wccs_schema[ $wccs_ns ] ),
		'namespaces=' . implode( ',', array_keys( $wccs_schema ) )
	);

	// format_extensions_properties() returns an ARRAY (description/type/context/properties),
	// not an object, even though get_endpoint_schema() casts the outer map to an object.
	$wccs_entry = isset( $wccs_schema[ $wccs_ns ] ) ? (array) $wccs_schema[ $wccs_ns ] : array();
	$wccs_props = isset( $wccs_entry['properties'] ) ? (array) $wccs_entry['properties'] : array();

	wccs_proof_check(
		'Controlled field property is present in the checkout response schema',
		isset( $wccs_props['controlled_document'] ),
		'properties=' . implode( ',', array_keys( $wccs_props ) )
	);

	wccs_proof_check(
		'Schema entry is a nullable object as required by the Store API',
		isset( $wccs_entry['type'] ) && in_array( 'object', (array) $wccs_entry['type'], true ),
		'type=' . wp_json_encode( $wccs_entry['type'] ?? null )
	);

	$wccs_data = (array) $wccs_extend->get_endpoint_data( 'checkout' );
	wccs_proof_check(
		'Endpoint data callback returns the controlled value',
		isset( $wccs_data[ $wccs_ns ]['controlled_document'] )
			&& '12345678901' === $wccs_data[ $wccs_ns ]['controlled_document'],
		'data=' . wp_json_encode( $wccs_data[ $wccs_ns ] ?? null )
	);

	wccs_proof_note(
		'Custom controlled fields do not need core DOM access',
		'Registration goes through the official Store API extensions namespace; no MutationObserver and no core DOM rewriting.'
	);
} catch ( Throwable $e ) {
	wccs_proof_check( 'Store API extensions namespace registration', false, get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// 5. Value reaches the order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Value reaches the order' );

global $wpdb;

$wccs_meta_key = CheckoutFields::get_group_key( 'other' ) . $wccs_text_id;
wccs_proof_note( 'Meta key used for the persisted value', $wccs_meta_key );

try {
	$wccs_order = wc_create_order( array( 'status' => 'pending' ) );

	if ( is_wp_error( $wccs_order ) ) {
		wccs_proof_check( 'Order created', false, $wccs_order->get_error_message() );
	} else {
		$wccs_order_id = $wccs_order->get_id();
		wccs_proof_check( 'Order created', $wccs_order_id > 0, 'order_id=' . $wccs_order_id );

		$wccs_registry->persist_field_for_order( $wccs_text_id, '12345678901', $wccs_order, 'other', false );
		$wccs_order->save();

		$wccs_fresh = wc_get_order( $wccs_order_id );
		$wccs_read  = $wccs_fresh ? $wccs_fresh->get_meta( $wccs_meta_key ) : null;

		wccs_proof_check(
			'Field value round-trips on the order through the Blocks persistence path',
			'12345678901' === (string) $wccs_read,
			'meta_key=' . $wccs_meta_key . ' value=' . var_export( $wccs_read, true )
		);

		$wccs_hpos_meta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
				$wccs_order_id,
				$wccs_meta_key
			)
		);
		wccs_proof_check( 'Value stored in wp_wc_orders_meta (HPOS)', 1 === $wccs_hpos_meta, 'rows=' . $wccs_hpos_meta );

		$wccs_legacy_meta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$wccs_order_id,
				$wccs_meta_key
			)
		);
		wccs_proof_check( 'Value NOT written to the legacy wp_postmeta store', 0 === $wccs_legacy_meta, 'rows=' . $wccs_legacy_meta );

		// A custom controlled field is not a native additional-field type; it must still
		// reach the order through the same canonical persistence path.
		$wccs_custom_id  = $wccs_ns . '/controlled-document';
		$wccs_custom_key = CheckoutFields::get_group_key( 'other' ) . $wccs_custom_id;

		$wccs_registry->persist_field_for_order( $wccs_custom_id, '98765432109', $wccs_order, 'other', false );
		$wccs_order->save();

		$wccs_fresh = wc_get_order( $wccs_order_id );
		$wccs_read2 = $wccs_fresh ? $wccs_fresh->get_meta( $wccs_custom_key ) : null;

		wccs_proof_check(
			'Custom controlled field value reaches the order',
			'98765432109' === (string) $wccs_read2,
			'meta_key=' . $wccs_custom_key . ' value=' . var_export( $wccs_read2, true )
		);

		$wccs_custom_hpos = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
				$wccs_order_id,
				$wccs_custom_key
			)
		);
		wccs_proof_check( 'Custom controlled field stored in wp_wc_orders_meta (HPOS)', 1 === $wccs_custom_hpos, 'rows=' . $wccs_custom_hpos );

		// ---------------------------------------------------------------
		// 6. Cleanup.
		// ---------------------------------------------------------------
		wccs_proof_out( '' );
		wccs_proof_out( '6. Cleanup' );

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
