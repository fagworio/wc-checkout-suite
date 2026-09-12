<?php
/**
 * WCCS-054 proof harness — the integration API.
 *
 * Task:   WCCS-054 "Criar API pública de integração"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Contrato autenticado; nenhum dado pessoal aparece em Store API pública."
 *
 * The first clause is exercised through the real REST server, with real requests and real
 * users, because an authorization that is only asserted as a return value is a return
 * value nobody has tested. The second is exercised against the platform's own public
 * route: an order that carries Suite values is requested through the Store API and the
 * response is asserted not to contain them.
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
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
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
			'validators'     => array(),
		),
		$changes
	);
}

/**
 * Publishes a document.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 54,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * An order carrying Suite values.
 *
 * @param array<string, mixed>             $values      Values.
 * @param array<int, array<string, mixed>> $definitions Definitions.
 * @return WC_Order
 */
function wccs_proof_order( array $values, array $definitions ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'completed' );
	$order->save();

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $order, $values, $definitions, 54 );
	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * Runs one request through the real REST server as the current user.
 *
 * @param string               $method HTTP method.
 * @param string               $route  Route.
 * @param array<string, mixed> $params Parameters.
 * @return WP_REST_Response|WP_Error
 */
function wccs_proof_request( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request );
}

/**
 * The status of whatever the REST server answered.
 *
 * `rest_do_request()` turns a permission refusal into a response that carries it, so the
 * assertions read the status and the code rather than an exception: the refusal is part of
 * the contract, not a failure of the call.
 *
 * @param mixed $response Response.
 * @return int
 */
function wccs_proof_status( $response ): int {
	return is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0;
}

/**
 * The error code a refused response carries.
 *
 * @param mixed $response Response.
 * @return string
 */
function wccs_proof_code( $response ): string {
	if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
		return '';
	}

	$data = $response->get_data();

	return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
}

/**
 * The body of a response, as an array.
 *
 * @param mixed $response Response.
 * @return array<string, mixed>
 */
function wccs_proof_body( $response ): array {
	if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
		$data = $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	return array();
}

$wccs_route  = 'WCCheckoutSuite\\Http\\Integration\\OrderFieldsController';
$wccs_orders = '/wc-checkoutsuite/v1' . $wccs_route::ROUTE_ORDERS;
$wccs_order  = '/wc-checkoutsuite/v1' . str_replace( '(?P<order_id>[\d]+)', '__ID__', $wccs_route::ROUTE_ORDER );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-054 proof — the integration API' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. The contract.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The contract' );

$wccs_routes = rest_get_server()->get_routes();

wccs_proof_check(
	'The routes are registered in the plugin namespace',
	isset( $wccs_routes[ $wccs_orders ] )
		&& array() !== array_filter(
			array_keys( $wccs_routes ),
			static function ( string $path ): bool {
				return str_contains( $path, '/integration/orders' );
			}
		)
);

wccs_proof_check(
	'And never on the public Store API namespace',
	array() === array_filter(
		array_keys( $wccs_routes ),
		static function ( string $path ): bool {
			return str_contains( $path, '/wc/store/' ) && str_contains( $path, 'integration' );
		}
	),
	'this plugin adds no route to the surface anonymous callers reach'
);

$wccs_collection_args = array();

foreach ( $wccs_routes[ $wccs_orders ] as $wccs_handler ) {
	if ( isset( $wccs_handler['args'] ) && is_array( $wccs_handler['args'] ) ) {
		$wccs_collection_args = $wccs_handler['args'];
	}
}

wccs_proof_check(
	'The collection declares a typed, documented schema for every parameter',
	array( 'page', 'per_page', 'status' ) === array_keys( $wccs_collection_args )
		&& 'integer' === ( $wccs_collection_args['page']['type'] ?? '' )
		&& 'integer' === ( $wccs_collection_args['per_page']['type'] ?? '' )
		&& isset( $wccs_collection_args['per_page']['maximum'] ),
	'args=' . wp_json_encode( array_keys( $wccs_collection_args ) )
);

wccs_proof_check(
	'And the page size is bounded, so one call cannot ask for the store',
	$wccs_route::MAX_PER_PAGE >= $wccs_collection_args['per_page']['maximum']
);

// ---------------------------------------------------------------------------
// 2. Authenticated, and authorized per order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Who may read' );

wp_set_current_user( 0 );

$wccs_anonymous = wccs_proof_request( 'GET', $wccs_orders );

wccs_proof_check(
	'An anonymous caller is refused, and refused by the route rather than by the callback',
	401 === wccs_proof_status( $wccs_anonymous ) && 'wccs_unauthenticated' === wccs_proof_code( $wccs_anonymous ),
	'status=' . wccs_proof_status( $wccs_anonymous ) . ' code=' . wccs_proof_code( $wccs_anonymous )
);

$wccs_customer_id = wp_insert_user(
	array(
		'user_login' => 'wccs_proof_reader_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wccs_proof_reader_' . wp_rand( 1000, 9999 ) . '@example.test',
		'role'       => 'customer',
	)
);

wp_set_current_user( (int) $wccs_customer_id );

$wccs_customer_request = wccs_proof_request( 'GET', $wccs_orders );

wccs_proof_check(
	'An authenticated customer with no store capability is refused too',
	403 === wccs_proof_status( $wccs_customer_request ) && 'wccs_forbidden' === wccs_proof_code( $wccs_customer_request ),
	'status=' . wccs_proof_status( $wccs_customer_request ) . ' — authentication is not authorization'
);

wp_set_current_user( 1 );

wccs_proof_publish(
	array(
		wccs_proof_field( 'public_note', array( 'visibility' => array( 'public_api' => true ) ) ),
		wccs_proof_field( 'private_note', array( 'visibility' => array( 'admin_order' => true ) ) ),
	)
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_carrier = wccs_proof_order(
	array(
		'wccs_public_note'  => 'Exposed to the integration.',
		'wccs_private_note' => 'Kept for the order screen.',
	),
	$wccs_definitions
);

$wccs_single = wccs_proof_request( 'GET', str_replace( '__ID__', (string) $wccs_carrier->get_id(), $wccs_order ) );
$wccs_body   = wccs_proof_body( $wccs_single );

wccs_proof_check(
	'The store may read an order it may edit, and gets the exposed field',
	200 === wccs_proof_status( $wccs_single )
		&& 'Exposed to the integration.' === ( $wccs_body['fields'][0]['value'] ?? '' ),
	'fields=' . wp_json_encode( array_column( $wccs_body['fields'] ?? array(), 'id' ) )
);

// The second authorization: a caller who may read orders and not this order.
$wccs_editor = wp_insert_user(
	array(
		'user_login' => 'wccs_proof_editor_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wccs_proof_editor_' . wp_rand( 1000, 9999 ) . '@example.test',
		'role'       => 'editor',
	)
);

$wccs_capability = get_role( 'editor' );
$wccs_orders_cap = 'edit_shop_orders';

if ( $wccs_capability instanceof WP_Role ) {
	$wccs_capability->add_cap( 'manage_woocommerce' );
}

wp_set_current_user( (int) $wccs_editor );

$wccs_foreign = wccs_proof_request( 'GET', str_replace( '__ID__', (string) $wccs_carrier->get_id(), $wccs_order ) );

wccs_proof_check(
	'A caller who may read orders but not this one is refused',
	403 === wccs_proof_status( $wccs_foreign ) && 'wccs_order_not_allowed' === wccs_proof_code( $wccs_foreign ),
	'status=' . wccs_proof_status( $wccs_foreign ) . ' — "may read orders" and "may read this customer\'s order" are different sentences'
);

$wccs_message = is_object( $wccs_foreign ) && method_exists( $wccs_foreign, 'get_data' ) && is_array( $wccs_foreign->get_data() )
	? (string) ( $wccs_foreign->get_data()['message'] ?? '' )
	: '';

wccs_proof_check(
	'And the refusal does not say whether the order exists',
	! str_contains( strtolower( $wccs_message ), 'exist' )
		&& ! str_contains( strtolower( $wccs_message ), 'found' ),
	'message=' . $wccs_message
);

$wccs_unknown = wccs_proof_request( 'GET', str_replace( '__ID__', '99999999', $wccs_order ) );

wccs_proof_check(
	'And an order that does not exist is refused the same way',
	wccs_proof_code( $wccs_unknown ) === wccs_proof_code( $wccs_foreign )
		&& wccs_proof_status( $wccs_unknown ) === wccs_proof_status( $wccs_foreign ),
	'code=' . wccs_proof_code( $wccs_unknown ) . ' — telling "no" from "no such thing" is an enumeration oracle'
);

wp_delete_user( (int) $wccs_editor );
wp_delete_user( (int) $wccs_customer_id );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 3. Permission per field, and never every order meta.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What travels' );

$wccs_ids = array_column( $wccs_body['fields'] ?? array(), 'id' );

wccs_proof_check(
	'Only the field the merchant exposed travels',
	in_array( 'wccs_public_note', $wccs_ids, true ) && ! in_array( 'wccs_private_note', $wccs_ids, true ),
	'ids=' . wp_json_encode( $wccs_ids )
);

wccs_proof_check(
	'The projection is an allow list and not a dump of the order',
	! str_contains( (string) wp_json_encode( $wccs_body ), 'Kept for the order screen.' )
		&& ! str_contains( (string) wp_json_encode( $wccs_body ), '_wccs_' ),
	'no meta key and no unexposed value appears in the response'
);

// Exposed, and with no value on this order: the field is absent from the response
// rather than present with a null, because a caller cannot tell "the customer left it
// blank" from "the field did not exist" if both arrive as null.
$wccs_without_value = wccs_proof_order( array( 'wccs_private_note' => 'Not the exposed one.' ), $wccs_definitions );

$wccs_without_value_body = wccs_proof_body(
	wccs_proof_request( 'GET', str_replace( '__ID__', (string) $wccs_without_value->get_id(), $wccs_order ) )
);

wccs_proof_check(
	'An exposed field the order does not carry is absent, not null',
	array() === ( $wccs_without_value_body['fields'] ?? array() )
		&& ! str_contains( (string) wp_json_encode( $wccs_without_value_body ), 'null' ),
	'body=' . wp_json_encode( $wccs_without_value_body )
);

$wccs_defaults = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_visibility( true );

wccs_proof_check(
	'The default is the one that exposes nothing',
	false === $wccs_defaults['public_api'],
	'a field is private until a merchant decides otherwise: ' . wp_json_encode( $wccs_defaults )
);

$wccs_unmarked = wccs_proof_order( array( 'wccs_private_note' => 'Only on the order screen.' ), $wccs_definitions );

$wccs_unmarked_body = wccs_proof_body(
	wccs_proof_request( 'GET', str_replace( '__ID__', (string) $wccs_unmarked->get_id(), $wccs_order ) )
);

wccs_proof_check(
	'An order whose only value is not exposed answers with an empty field list',
	array() === ( $wccs_unmarked_body['fields'] ?? array() )
);

// ---------------------------------------------------------------------------
// 4. Pagination.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Pagination' );

$wccs_page = wccs_proof_request( 'GET', $wccs_orders, array( 'per_page' => 2, 'page' => 1 ) );

wccs_proof_check(
	'The collection answers with a page, a total and the platform headers',
	200 === wccs_proof_status( $wccs_page )
		&& null !== $wccs_page->get_headers()['X-WP-Total'] ?? null,
	'total=' . wp_json_encode( $wccs_page->get_headers()['X-WP-Total'] ?? null )
);

wccs_proof_check(
	'And a page holds no more than it was asked for',
	count( wccs_proof_body( $wccs_page )['orders'] ?? array() ) <= 2
);

$wccs_over = wccs_proof_request( 'GET', $wccs_orders, array( 'per_page' => 100000 ) );

wccs_proof_check(
	'And a caller asking for the whole store is given the maximum instead',
	count( wccs_proof_body( $wccs_over )['orders'] ?? array() ) <= $wccs_route::MAX_PER_PAGE
);

// ---------------------------------------------------------------------------
// 5. Nothing personal on the public Store API.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The public surface' );

$wccs_store_routes = array_filter(
	array_keys( rest_get_server()->get_routes() ),
	static function ( string $path ): bool {
		return str_contains( $path, '/wc/store/v1/order' );
	}
);

wccs_proof_check(
	'The Store API has its own order route on this store, so the assertion has a subject',
	array() !== $wccs_store_routes,
	'routes=' . wp_json_encode( array_values( $wccs_store_routes ) )
);

$wccs_store_path = (string) str_replace( '(?P<id>[\d]+)', (string) $wccs_carrier->get_id(), (string) ( array_values( $wccs_store_routes )[0] ?? '' ) );
$wccs_store_path = (string) preg_replace( '#\(\?P<[a-z_]+>[^)]+\)#', (string) $wccs_carrier->get_id(), $wccs_store_path );

wp_set_current_user( 0 );

$wccs_store_request = wccs_proof_request(
	'GET',
	$wccs_store_path,
	array(
		'key' => $wccs_carrier->get_order_key(),
	)
);

$wccs_store_body = wp_json_encode( wccs_proof_body( $wccs_store_request ) );

wccs_proof_check(
	'The Store API answers, so the check is not vacuous',
	'' !== $wccs_store_body && 'null' !== $wccs_store_body,
	'bytes=' . strlen( (string) $wccs_store_body )
);

wccs_proof_check(
	'And carries no Suite field, no Suite meta key and none of the values',
	! str_contains( (string) $wccs_store_body, 'wccs_public_note' )
		&& ! str_contains( (string) $wccs_store_body, 'wccs_private_note' )
		&& ! str_contains( (string) $wccs_store_body, '_wccs_' )
		&& ! str_contains( (string) $wccs_store_body, 'Exposed to the integration.' )
		&& ! str_contains( (string) $wccs_store_body, 'Kept for the order screen.' ),
	'no personal data of this plugin travels on the surface anonymous callers reach'
);

wp_set_current_user( 1 );

wccs_proof_check(
	'And the plugin extends the Store API with a shape and not with a value',
	str_contains(
		(string) file_get_contents( WCCS_PLUGIN_DIR . 'src/Checkout/Blocks/StoreApiExtension.php' ),
		"'schema_callback'"
	) && ! str_contains(
		(string) file_get_contents( WCCS_PLUGIN_DIR . 'src/Checkout/Blocks/StoreApiExtension.php' ),
		'OrderFieldsService'
	),
	'the only extension is the type contract the Blocks checkout validates against'
);

wccs_proof_note(
	'Why the integration API is not on the Store API namespace',
	'The Store API is the surface an anonymous browser reaches during checkout. An integration that reads an order is not that: it is a caller that has authenticated and been authorized per order. Putting it in the same namespace would make the two indistinguishable in a log, in a firewall rule and in a reviewer\'s head, and the acceptance asks for exactly that distinction.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No external caller: the requests go through the real REST server in-process, so the authentication exercised is WordPress\'s authorization model and not a real application password over the network. Exercising a real integration client, and the matrix of roles against every endpoint, is the audit this phase closes with.'
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before
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
