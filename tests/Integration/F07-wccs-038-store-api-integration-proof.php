<?php
/**
 * WCCS-038 proof harness — the Store API integration.
 *
 * Task:   WCCS-038 "Integrar Store API e validação"
 * Phase:  F07
 * Accept: "Erro final bloqueia pagamento; payload tipado; retry não duplica gravação."
 *
 * The three clauses are proven against the platform rather than against a fixture of
 * what the platform is believed to do:
 *
 * 1. **Typed payload** — the namespace is registered on the real Store API checkout
 *    endpoint, and the schema it publishes carries a type per field, so the route
 *    validates the shape before this plugin's code runs.
 * 2. **An error blocks payment** — the validation throws the same exception the
 *    checkout route throws, which is what makes the Checkout Block refuse to
 *    continue; the field that failed travels with it.
 * 3. **A retry does not duplicate** — the same submission is applied twice and the
 *    order is read back: one meta row, one payload, byte for byte the same.
 *
 * The full POST /wc/store/v1/checkout round trip is not executed here, and the note
 * at the end says why: this store has no product, so no cart can be built. What is
 * exercised is the code the route calls, with the objects the route passes.
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
				'revision'       => 7,
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

/**
 * A Store API request stand-in carrying an extensions map.
 *
 * The reader only needs `get_param`, so this is what the route would pass rather
 * than a hand-built WP_REST_Request that could differ from it.
 *
 * @param array<string, mixed> $extensions Extensions.
 * @return object
 */
function wccs_proof_request( array $extensions ): object {
	return new class( $extensions ) {
		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $extensions Extensions.
		 */
		public function __construct( private array $extensions ) {
		}

		/**
		 * Reads one parameter.
		 *
		 * @param string $key Parameter.
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {
			return 'extensions' === $key ? $this->extensions : null;
		}
	};
}

// The state this harness works in is established rather than inherited: a previous
// run that died before its cleanup would otherwise make the environment assertion
// fail for a reason that has nothing to do with this run's work.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-038 proof — the Store API integration' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

wccs_proof_publish(
	array(
		wccs_proof_field(
			'note',
			'textarea',
			array(
				'required' => true,
			)
		),
		wccs_proof_field( 'person_type', 'radio', array( 'settings' => array( 'options' => array( array( 'value' => 'pj', 'label' => 'Company' ) ) ) ) ),
		wccs_proof_field( 'tags', 'multiselect', array( 'settings' => array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) ) ),
		wccs_proof_field( 'plain', 'text' ),
	)
);

// ---------------------------------------------------------------------------
// 1. The typed payload.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The payload the Store API validates' );

$wccs_schema = \WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::schema();

wccs_proof_check(
	'Every controlled field is declared in the namespace',
	isset( $wccs_schema['wccs_note'], $wccs_schema['wccs_person_type'], $wccs_schema['wccs_tags'] ),
	'declared=' . wp_json_encode( array_keys( $wccs_schema ) )
);

wccs_proof_check(
	'A native field is not declared, because the platform carries it',
	! isset( $wccs_schema['wccs_plain'] )
);

wccs_proof_check(
	'The types are the ones the components produce, not the ones the customer sees',
	'string' === ( $wccs_schema['wccs_note']['type'] ?? '' )
		&& 'string' === ( $wccs_schema['wccs_person_type']['type'] ?? '' )
		&& 'array' === ( $wccs_schema['wccs_tags']['type'] ?? '' ),
	'types=' . wp_json_encode( array_map( static fn( array $entry ): string => (string) $entry['type'], $wccs_schema ) )
);

wccs_proof_check(
	'A list declares what its items are',
	'string' === ( $wccs_schema['wccs_tags']['items']['type'] ?? '' )
);

wccs_proof_check(
	'And every property is readable and writable, which is what a payload has to be',
	array() === array_filter(
		$wccs_schema,
		static function ( array $property ): bool {
			return ! in_array( 'view', (array) $property['context'], true ) || ! in_array( 'edit', (array) $property['context'], true );
		}
	)
);

// The namespace is registered on the real endpoint.
$wccs_registered = false;

if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
	\WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::extend();

	// The same object the checkout schema merges when it builds the route's
	// contract, read through the container rather than constructed here.
	$wccs_extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class );
	$wccs_data   = $wccs_extend->get_endpoint_data( \WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::ENDPOINT, array( null ) );

	$wccs_registered = is_object( $wccs_data ) && property_exists( $wccs_data, \WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::NAMESPACE_KEY );
}

wccs_proof_check(
	'The namespace is registered on the checkout endpoint of the real Store API',
	$wccs_registered
);

// ---------------------------------------------------------------------------
// 2. An error blocks the order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What stops the payment' );

$wccs_order = wc_create_order();

wccs_proof_check(
	'An order to attach the values to',
	$wccs_order instanceof WC_Order,
	'order=' . ( $wccs_order instanceof WC_Order ? $wccs_order->get_id() : '(none)' )
);

$wccs_refusal = null;

try {
	\WCCheckoutSuite\Checkout\Blocks\BlocksValidation::apply(
		$wccs_order,
		wccs_proof_request(
			array(
				\WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::NAMESPACE_KEY => array(
					'wccs_note' => '',
				),
			)
		)
	);
} catch ( \Throwable $wccs_error ) {
	$wccs_refusal = $wccs_error;
}

wccs_proof_check(
	'A required field left empty throws the exception the route turns into a refusal',
	$wccs_refusal instanceof \Automattic\WooCommerce\StoreApi\Exceptions\RouteException,
	'class=' . ( null === $wccs_refusal ? '(nothing thrown)' : get_class( $wccs_refusal ) )
);

wccs_proof_check(
	'With a code that names this plugin and the rule that failed',
	null !== $wccs_refusal
		&& str_starts_with( $wccs_refusal->getErrorCode(), 'wc-checkoutsuite_' ),
	'code=' . ( null === $wccs_refusal ? '(none)' : $wccs_refusal->getErrorCode() )
);

wccs_proof_check(
	'And with the field that produced it, so the message can be rendered against it',
	null !== $wccs_refusal
		&& 'wccs_note' === ( $wccs_refusal->getAdditionalData()['field'] ?? '' ),
	'data=' . wp_json_encode( null === $wccs_refusal ? array() : $wccs_refusal->getAdditionalData() )
);

wccs_proof_check(
	'And nothing was stored on the order, because the order is not allowed to exist',
	! $wccs_order->meta_exists( \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS )
		|| '' === (string) $wccs_order->get_meta( \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS )
);

// ---------------------------------------------------------------------------
// 3. A retry stores the same thing, not the same thing twice.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A retry does not duplicate' );

$wccs_payload = array(
	'wccs_note'      => 'Please call before delivery.',
	'wccs_person_type' => 'pj',
	'wccs_tags'      => array( 'a' ),
);

$wccs_request = wccs_proof_request(
	array( \WCCheckoutSuite\Checkout\Blocks\StoreApiExtension::NAMESPACE_KEY => $wccs_payload )
);

\WCCheckoutSuite\Checkout\Blocks\BlocksValidation::apply( $wccs_order, $wccs_request );

// The route saves the order after the hook returns, which is what makes the meta
// reach the table; doing it here is completing the step the route would take next,
// not adding one of this harness's own.
$wccs_order->save();

$wccs_first = (string) $wccs_order->get_meta( \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS );

$wccs_rows = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
		$wccs_order->get_id(),
		\WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS
	)
);

\WCCheckoutSuite\Checkout\Blocks\BlocksValidation::apply( $wccs_order, $wccs_request );

$wccs_order->save();

$wccs_second = (string) $wccs_order->get_meta( \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS );

$wccs_rows_after = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
		$wccs_order->get_id(),
		\WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS
	)
);

wccs_proof_check(
	'The first submission stores the values',
	'' !== $wccs_first && str_contains( $wccs_first, 'Please call before delivery.' ),
	'meta rows=' . $wccs_rows
);

wccs_proof_check(
	'A second identical submission stores the same payload, byte for byte',
	$wccs_first === $wccs_second
);

wccs_proof_check(
	'And does not add a second row',
	1 === $wccs_rows_after && $wccs_rows === $wccs_rows_after,
	'before=' . $wccs_rows . ' after=' . $wccs_rows_after
);

wccs_proof_check(
	'And what reads back is what was submitted',
	'Please call before delivery.' === ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( $wccs_order )->all()['wccs_note'] ?? null,
	'values=' . wp_json_encode( array_keys( ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( $wccs_order )->all() ) )
);

$wccs_order->delete( true );

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
	'What is deliberately not exercised here',
	'The full POST /wc/store/v1/checkout round trip. This store has no product, so no cart can be built and no order can be placed through the route; what this harness exercises is the code the route calls, with the order and the request it passes. The round trip belongs to WCCS-040 and to the browser work in F10.'
);

wccs_proof_note(
	'Why the refusal is an exception',
	'Throwing from woocommerce_store_api_checkout_update_order_from_request is documented to prevent checkout: the Checkout Block renders in a warning state instead of placing the order. That is the only way an extension can stop a payment from this side, and it is why validation and persistence share the hook — the order is either accepted with its values or not accepted at all.'
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
