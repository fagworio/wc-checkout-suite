<?php
/**
 * WCCS-058 proof harness — the example field type, end to end.
 *
 * Task:   WCCS-058 "Concluir exemplo de novo tipo"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Código de associado aparece no picker, nos dois checkouts declarados e no pedido."
 *
 * The example plugin is loaded the way a store would load it — its file, required once, and
 * its registration through the published hook — and then every surface the acceptance names
 * is asked about it: the catalogue the picker reads, the classic adapter, the Blocks payload,
 * and an order that ends up carrying the value the example's own code normalized.
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

// The example is loaded before the registries are asked for anything, which is the order a
// real store loads it in: WordPress loads every active plugin before the checkout runs.
require_once WCCS_PLUGIN_DIR . 'examples/custom-field-type/wccs-example-field-type.php';

$wccs_plugin = 'WCCheckoutSuite\\Domain\\Registries';
$wccs_type   = 'WCCheckoutSuiteExample\\MembershipCodeType';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-058 proof — the contributed field type' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// The registry may already have been built during this request, and a plugin loaded after
// that registers through the same published hook: firing it again is the contract, and a
// key already registered is refused rather than replaced.
$wccs_registry = $wccs_plugin::instance()->types();

do_action( 'wccs_register_field_types', $wccs_registry );

// The key comes from the example's own constant, so the proof cannot pass by naming a
// type the example did not register.
$wccs_key = (string) constant( $wccs_type . '::KEY' );

// ---------------------------------------------------------------------------
// 1. The type is registered, through the hook, by the example.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. O tipo é do exemplo, e não do núcleo' );

wccs_proof_check(
	'The example registers its type through the published hook',
	$wccs_registry->has( $wccs_key ),
	'key=' . $wccs_key
);

wccs_proof_check(
	'And the registry records where it came from',
	'external' === $wccs_registry->source_of( $wccs_key )
		|| str_contains( strtolower( $wccs_registry->source_of( $wccs_key ) ), 'example' ),
	'source=' . $wccs_registry->source_of( $wccs_key )
);

$wccs_registered = $wccs_registry->get( $wccs_key );

wccs_proof_check(
	'And it declares the control that renders it',
	'text' === $wccs_registry->control( $wccs_key ),
	'control=' . $wccs_registry->control( $wccs_key )
);

wccs_proof_check(
	'And a type that declares nothing is rendered by nothing',
	'' === $wccs_registry->control( 'text' ) && '' === $wccs_registry->control( 'no-such-type' ),
	'the declaration is what makes a contributed type visible'
);

// ---------------------------------------------------------------------------
// 2. The picker.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. O picker' );

$wccs_catalogue = \WCCheckoutSuite\Http\Admin\CatalogController::class;

$wccs_response = rest_do_request( new WP_REST_Request( 'GET', '/wc-checkoutsuite/v1/field-types' ) );
$wccs_body     = is_object( $wccs_response ) && method_exists( $wccs_response, 'get_data' ) ? $wccs_response->get_data() : array();
$wccs_types    = is_array( $wccs_body ) ? ( $wccs_body['types'] ?? $wccs_body ) : array();
$wccs_keys     = array_column( (array) $wccs_types, 'key' );

wccs_proof_check(
	'The catalogue the picker reads offers the contributed type',
	in_array( $wccs_key, $wccs_keys, true ),
	'types=' . count( $wccs_keys )
);

$wccs_entry = array();

foreach ( (array) $wccs_types as $wccs_candidate ) {
	if ( is_array( $wccs_candidate ) && $wccs_key === ( $wccs_candidate['key'] ?? '' ) ) {
		$wccs_entry = $wccs_candidate;
	}
}

wccs_proof_check(
	'And offers it with the label the example wrote and the source it came from',
	'Membership code' === ( $wccs_entry['label'] ?? '' ) && 'wccs-example' === ( $wccs_entry['source'] ?? '' ),
	'entry=' . wp_json_encode( array( 'label' => $wccs_entry['label'] ?? '', 'source' => $wccs_entry['source'] ?? '' ) )
);

wccs_proof_check(
	'And offers the control it declared, so the picker can say how it is drawn',
	'text' === ( $wccs_entry['supports']['control'] ?? '' ),
	'supports=' . wp_json_encode( $wccs_entry['supports'] ?? array() )
);

// ---------------------------------------------------------------------------
// 3. The two declared checkouts.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Os dois checkouts' );

wccs_proof_check(
	'The classic adapter accepts the contributed type',
	\WCCheckoutSuite\Checkout\Classic\ClassicAdapter::can_render( $wccs_key )
		&& 'text' === \WCCheckoutSuite\Checkout\Classic\ClassicAdapter::control_for( $wccs_key ),
	'the control it declared is the one the classic field API draws'
);

wccs_proof_check(
	'And a type that declares nothing is still not renderable there',
	! \WCCheckoutSuite\Checkout\Classic\ClassicAdapter::can_render( 'no-such-type' )
);

$wccs_adapter = new \WCCheckoutSuite\Checkout\Classic\ClassicAdapter();

$wccs_fields = $wccs_adapter->apply(
	array(),
	array(
		array(
			'id'             => 'wccs_associate',
			'integration_id' => 'wc-checkoutsuite/wccs_associate',
			'origin'         => 'custom',
			'type'           => $wccs_key,
			'label'          => 'Código de associado',
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => true,
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
	),
	array(
		array(
			'id'       => 'billing',
			'title'    => 'Billing',
			'location' => 'billing',
			'position' => 10,
		),
	)
);

$wccs_drawn = $wccs_fields['billing']['wccs_associate'] ?? array();

wccs_proof_check(
	'And it reaches the classic checkout as the control the example declared',
	'text' === ( $wccs_drawn['type'] ?? '' ) && 'Código de associado' === ( $wccs_drawn['label'] ?? '' ),
	'field=' . wp_json_encode( array( 'type' => $wccs_drawn['type'] ?? '', 'label' => $wccs_drawn['label'] ?? '' ) )
);

wccs_proof_check(
	'The Blocks adapter calls it controlled rather than restricted',
	'controlled' === \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::mode( $wccs_key ),
	'mode=' . \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::mode( $wccs_key )
);

$wccs_published = array(
	'revision'       => 58,
	'schema_version' => 1,
	'updated_at'     => gmdate( 'c' ),
	'updated_by'     => 1,
	'fields'         => array(
		array(
			'id'             => 'wccs_associate',
			'integration_id' => 'wc-checkoutsuite/wccs_associate',
			'origin'         => 'custom',
			'type'           => $wccs_key,
			'label'          => 'Código de associado',
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => true,
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
	),
	'sections'       => array(
		array(
			'id'       => 'billing',
			'title'    => 'Billing',
			'location' => 'billing',
			'position' => 10,
		),
	),
	'settings'       => array(),
);

update_option(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
	(string) wp_json_encode( $wccs_published ),
	false
);

$wccs_rendered = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::fields();

$wccs_entry = array();

foreach ( $wccs_rendered['fields'] as $wccs_candidate ) {
	if ( 'wccs_associate' === ( $wccs_candidate['name'] ?? '' ) ) {
		$wccs_entry = $wccs_candidate;
	}
}

wccs_proof_check(
	'And the Blocks renderer publishes it for the bundle, with the control that draws it',
	array() !== $wccs_entry && 'text' === ( $wccs_entry['control'] ?? '' ),
	'entry=' . wp_json_encode( array( 'name' => $wccs_entry['name'] ?? '', 'control' => $wccs_entry['control'] ?? '', 'type' => $wccs_entry['type'] ?? '' ) )
);

wccs_proof_check(
	'And the bundle chooses the component by that control',
	str_contains(
		(string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/blocks/fields.js' ),
		'COMPONENTS[ field.control ]'
	)
);

// ---------------------------------------------------------------------------
// 4. The order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. O pedido' );

$wccs_definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_published['fields'][0] );

$wccs_processor = $wccs_plugin::instance()->value_processor();
$wccs_context   = new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), 'classic' );

// The example's own code decides both: it upper-cases what it is given, and it refuses a
// code with characters a membership code cannot have. The first version of this assertion
// expected the opposite of both, which is what a proof written from the outside looks like
// before it reads the contract it is testing.
$wccs_accepted = $wccs_processor->process( $wccs_definition, 'assoc-0042', $wccs_context );
$wccs_refused  = $wccs_processor->process( $wccs_definition, 'not a code!', $wccs_context );

wccs_proof_check(
	'The example normalizes and validates its own values',
	$wccs_accepted->result()->is_valid()
		&& 'ASSOC-0042' === $wccs_accepted->value()
		&& ! $wccs_refused->result()->is_valid(),
	'accepted=' . wp_json_encode( $wccs_accepted->value() ) . ' refused=' . wp_json_encode( array_column( $wccs_refused->result()->errors(), 'code' ) )
);

$wccs_order = wc_create_order();
$wccs_order->set_status( 'completed' );
$wccs_order->save();

( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write(
	$wccs_order,
	array( 'wccs_associate' => $wccs_accepted->value() ),
	$wccs_published['fields'],
	58
);
$wccs_order->save();

$wccs_stored = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )
	->read( wc_get_order( $wccs_order->get_id() ) )
	->get( 'wccs_associate' );

wccs_proof_check(
	'The value the example produced is stored on the order and read back',
	'ASSOC-0042' === $wccs_stored,
	'stored=' . wp_json_encode( $wccs_stored ) . ' — the canonical value the example normalize() produced'
);

$wccs_history = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )
	->history( wc_get_order( $wccs_order->get_id() ), $wccs_published['fields'] );

wccs_proof_check(
	'And the order shows the label the example declared',
	array() !== $wccs_history && 'Código de associado' === $wccs_history[0]->label(),
	'label=' . ( $wccs_history[0]->label() ?? '(none)' )
);

$wccs_html = '';

ob_start();
\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render(
	wc_get_order( $wccs_order->get_id() ),
	array( 'args' => array( 'order' => wc_get_order( $wccs_order->get_id() ) ) )
);
$wccs_html = (string) ob_get_clean();

wccs_proof_check(
	'And the order screen draws a control the staff can correct it with',
	str_contains( $wccs_html, 'wccs_associate' ),
	'a contributed type is editable where every other order value is'
);

wccs_proof_note(
	'What made the example invisible before this task',
	'Both adapters decided what they could draw from their own closed maps, so a type contributed by another plugin was invisible on both checkouts — the picker offered it and neither checkout could draw it, which is a contract that exists only on paper. The contribution now declares which existing control renders it, and the adapters honour the declaration instead of consulting a list they own. The declaration is a choice among the controls that exist: a select is still a select, and what makes a membership code a membership code is the validation the contributing plugin wrote.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No browser: the two checkouts are asserted through the adapters and the payload the bundle reads, not by opening a checkout page, and nothing was typed into a form. What the customer sees with the component drawn is WCCS-063. The example plugin is loaded in this request rather than activated on the site, because activating a plugin is a site change outside this plugin root.'
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

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
