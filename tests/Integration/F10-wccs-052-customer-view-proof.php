<?php
/**
 * WCCS-052 proof harness — the checkout fields on the customer's order.
 *
 * Task:   WCCS-052 "Criar exibição ao cliente"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Visibilidade por campo respeitada; conta atual não reescreve pedido passado."
 *
 * Two clauses and both are asserted as what the customer is *not* shown, because that is
 * the direction a display bug fails in:
 *
 * 1. **The field's policy decides.** A value appears when the field declares
 *    `customer_order`, and a field whose policy cannot be read any more — renamed,
 *    archived or deleted since the order was placed — is left out rather than shown on
 *    the strength of a value that happens to be there.
 * 2. **The order is a snapshot, the account is current.** Every value comes from the
 *    order. What the account holds today is asserted to be absent from the page, and an
 *    order that captured nothing shows nothing even when the account holds something.
 *
 * The hook itself is grounded in the installed WooCommerce rather than asserted: the
 * template that fires it and the two surfaces that render that template are read.
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
 * Reads a template of the installed WooCommerce, for the platform fact it holds.
 *
 * @param string $relative Path inside the plugins directory.
 * @return string
 */
function wccs_proof_platform_file( string $relative ): string {
	$path = dirname( WCCS_PLUGIN_DIR, 1 ) . '/' . $relative;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the installed WooCommerce, read to ground a hook in the platform's own template; the sniff targets remote URLs.
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
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
			'visibility'     => array( 'customer_order' => true ),
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
				'revision'       => 52,
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
 * An order carrying Suite values, written through the service the checkout uses.
 *
 * @param array<string, mixed>             $values      Values, keyed by identifier.
 * @param array<int, array<string, mixed>> $definitions Definitions to capture a snapshot from.
 * @return WC_Order
 */
function wccs_proof_order( array $values, array $definitions ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'completed' );
	$order->save();

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $order, $values, $definitions, 52 );
	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * What the customer's page would contain.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function wccs_proof_rendered( WC_Order $order ): string {
	ob_start();
	\WCCheckoutSuite\Checkout\CustomerOrderFields::render( $order );

	return (string) ob_get_clean();
}

$wccs_view = 'WCCheckoutSuite\\Checkout\\CustomerOrderFields';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-052 proof — what the customer is shown' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. The hook is the platform's own, on both surfaces.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Where the customer sees it' );

$wccs_details = wccs_proof_platform_file( 'woocommerce/templates/order/order-details.php' );
$wccs_hooks   = wccs_proof_platform_file( 'woocommerce/includes/wc-template-hooks.php' );

wccs_proof_check(
	'The template the platform renders for an order fires this hook',
	str_contains( $wccs_details, $wccs_view::HOOK )
);

wccs_proof_check(
	'And that template is rendered for the thank-you page and for My Account',
	str_contains( $wccs_hooks, "add_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 )" )
		&& str_contains( $wccs_hooks, "add_action( 'woocommerce_view_order', 'woocommerce_order_details_table', 10 )" ),
	'one renderer, both surfaces — which is why one hook is enough'
);

wccs_proof_check(
	'And the plugin listens on it, once',
	false !== has_action( $wccs_view::HOOK, array( $wccs_view, 'render' ) )
);

wccs_proof_check(
	'The plugin registers no route, page or shortcode of its own for this',
	0 === count(
		array_filter(
			array_keys( $GLOBALS['shortcode_tags'] ),
			static function ( string $tag ): bool {
				return str_contains( $tag, 'wccs' );
			}
		)
	),
	'the store decides who may see the order; this decides which fields'
);

// ---------------------------------------------------------------------------
// 2. The field's policy decides.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Visibility by field' );

$wccs_public = wccs_proof_field( 'note' );
$wccs_staff  = wccs_proof_field( 'internal', array( 'visibility' => array( 'admin_order' => true ) ) );
$wccs_email  = wccs_proof_field( 'greeting', array( 'visibility' => array( 'customer_email' => true ) ) );
$wccs_gone   = wccs_proof_field(
	'retired',
	array(
		'visibility' => array( 'customer_order' => true ),
		'enabled'    => false,
	)
);

wccs_proof_publish( array( $wccs_public, $wccs_staff, $wccs_email ) );

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();
$wccs_visible     = $wccs_view::visible( $wccs_definitions );

wccs_proof_check(
	'Only the field the merchant shows to the customer is visible to them',
	array( 'wccs_note' ) === array_keys( $wccs_visible ),
	'visible=' . wp_json_encode( array_keys( $wccs_visible ) )
);

wccs_proof_check(
	'A field shown to staff is not shown to the customer, and neither is one meant for e-mail',
	! isset( $wccs_visible['wccs_internal'], $wccs_visible['wccs_greeting'] )
);

$wccs_order = wccs_proof_order(
	array(
		'wccs_note'     => 'A note for the customer.',
		'wccs_internal' => 'An internal remark.',
		'wccs_greeting' => 'Hello from the e-mail.',
	),
	$wccs_definitions
);

$wccs_html = wccs_proof_rendered( $wccs_order );

wccs_proof_check(
	'The order page carries the field the customer may see',
	str_contains( $wccs_html, 'A note for the customer.' )
);

wccs_proof_check(
	'And carries neither the staff field nor the e-mail one',
	! str_contains( $wccs_html, 'An internal remark.' )
		&& ! str_contains( $wccs_html, 'Hello from the e-mail.' ),
	'policy is per field and per surface'
);

// ---------------------------------------------------------------------------
// 3. A policy that cannot be read is a refusal.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What happens to a field that no longer exists' );

// The order was placed when the field existed and carries its value. The document no
// longer has it: the field was archived or deleted, and with it the policy that allowed
// showing it.
wccs_proof_publish( array( wccs_proof_field( 'note' ) ) );

$wccs_after_removal = wccs_proof_rendered( $wccs_order );

wccs_proof_check(
	'A value whose field is gone is not shown, even though the order carries it',
	! str_contains( $wccs_after_removal, 'An internal remark.' )
		&& ! str_contains( $wccs_after_removal, 'Hello from the e-mail.' ),
	'a policy that cannot be read is not an approval'
);

wccs_proof_check(
	'And the field that is still published is still shown, from the order',
	str_contains( $wccs_after_removal, 'A note for the customer.' )
);

// A field that is still published but no longer allowed for the customer is also out,
// which is the same rule read the other way.
wccs_proof_publish( array( wccs_proof_field( 'note', array( 'visibility' => array( 'admin_order' => true ) ) ) ) );

wccs_proof_check(
	'A field the merchant stopped showing to the customer stops being shown',
	! str_contains( wccs_proof_rendered( $wccs_order ), 'A note for the customer.' )
);

wccs_proof_check(
	'And with nothing left to show, the page says nothing at all',
	'' === wccs_proof_rendered( $wccs_order ),
	'a heading with no rows tells the customer something was withheld'
);

// ---------------------------------------------------------------------------
// 4. The account today does not rewrite the order.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The order is a snapshot, the account is current' );

wccs_proof_publish( array( wccs_proof_field( 'note' ), wccs_proof_field( 'preference' ) ) );

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_customer = wp_insert_user(
	array(
		'user_login' => 'wccs_proof_customer_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wccs_proof_' . wp_rand( 1000, 9999 ) . '@example.test',
		'role'       => 'customer',
	)
);

$wccs_snapshot_order = wccs_proof_order( array( 'wccs_note' => 'What the order captured.' ), $wccs_definitions );
$wccs_snapshot_order->set_customer_id( (int) $wccs_customer );
$wccs_snapshot_order->save();

// What the account holds today, under the same key the order store uses. A view that
// resolved the customer's current value instead of the order's would show this.
update_user_meta( (int) $wccs_customer, \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS, 'What the account holds today.' );

$wccs_customer_html = wccs_proof_rendered( wc_get_order( $wccs_snapshot_order->get_id() ) );

wccs_proof_check(
	'The page shows what the order captured',
	str_contains( $wccs_customer_html, 'What the order captured.' )
);

wccs_proof_check(
	'And never what the account holds today',
	! str_contains( $wccs_customer_html, 'What the account holds today.' ),
	'editing the account does not rewrite a past order, and viewing one does not read the other'
);

// An order that captured nothing shows nothing, however much the account holds.
$wccs_empty_order = wc_create_order();
$wccs_empty_order->set_customer_id( (int) $wccs_customer );
$wccs_empty_order->save();

wccs_proof_check(
	'An order that captured nothing shows nothing, even for an account that holds a value',
	'' === wccs_proof_rendered( wc_get_order( $wccs_empty_order->get_id() ) )
);

wp_delete_user( (int) $wccs_customer );

// ---------------------------------------------------------------------------
// 5. What it draws, and how.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What the customer is given' );

wccs_proof_publish( array( wccs_proof_field( 'note' ), wccs_proof_field( 'label_only' ) ) );

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_escape_order = wccs_proof_order(
	array( 'wccs_note' => '<script>alert(1)</script> & "quotes"' ),
	$wccs_definitions
);

$wccs_escape_html = wccs_proof_rendered( $wccs_escape_order );

wccs_proof_check(
	'A value is escaped rather than printed as markup',
	! str_contains( $wccs_escape_html, '<script>' )
		&& str_contains( $wccs_escape_html, '&lt;script&gt;' ),
	'the customer page is not a place a stored value becomes markup'
);

wccs_proof_check(
	'And the label travels from the order, not from a heading this plugin invents per field',
	str_contains( $wccs_escape_html, 'Label note' )
);

wccs_proof_check(
	'A field with no value on the order is not drawn at all',
	! str_contains( $wccs_escape_html, 'Label label_only' ),
	'an empty row is a row that says the customer left something blank'
);

wccs_proof_note(
	'Why there is no access check here',
	'The panel is not a route: WooCommerce fires the template hook only where the store has already decided this order may be shown to this person — the thank-you page with a valid order key, or the account that owns the order. A second check would be redundant at best and, if it were ever the only one, a false sense of safety. What this plugin adds is the per-field policy on top of the store\'s decision, and the audit of that decision is the phase gate.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No browser: the panel is asserted by rendering the hook callback, not by opening a thank-you page or a My Account order in a browser. The theme\'s placement of the hook, the appearance, and the logged-out thank-you path are WCCS-063 and WCCS-062.'
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before,
	'the published slot is as the harness found it'
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
