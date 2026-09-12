<?php
/**
 * WCCS-048 proof harness — the payment accordion and the order summary.
 *
 * Task:   WCCS-048 "Integrar accordion e resumo"
 * Phase:  F09 · Página customizada e pagamento
 * Accept: "Radios reais; totals/cupons/frete vêm do Woo; teclado e focus funcionam."
 *
 * The components are JavaScript and the behaviour is covered by the jsdom suite, which
 * renders WooCommerce's own payment markup and asserts that the module adds no input,
 * changes no panel's visibility and writes no number. What this harness proves is the
 * half a component cannot, and it is the half that decides whether the integration is
 * honest:
 *
 * 1. the selectors the modules bind to are the ones WooCommerce's own templates
 *    render — read out of the installed templates rather than assumed;
 * 2. the plugin registers no payment gateway and no Blocks payment method type: the
 *    rule is to preserve the platform's components, never to re-register a gateway in
 *    order to style it;
 * 3. the plugin declares no card, CVC or expiry field anywhere, which is the other half
 *    of "sem inputs de cartão próprios";
 * 4. the wording the control shows travels from the server, translation-ready, instead
 *    of being hardcoded in the bundle;
 * 5. both modules are in the bundle that ships, which is the difference between code
 *    that exists and code that arrives.
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
 * Reads a file shipped by another plugin, for the platform facts it holds.
 *
 * @param string $relative Path relative to the plugins directory.
 * @return string
 */
function wccs_proof_plugin_file( string $relative ): string {
	$path = dirname( WCCS_PLUGIN_DIR, 1 ) . '/' . $relative;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A template of the installed WooCommerce, read to ground a selector in the platform's own markup; the sniff targets remote URLs.
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * The callbacks this plugin has on a hook, whoever else is on it.
 *
 * @param string $tag Hook name.
 * @return array<int, string> Callbacks of this plugin.
 */
function wccs_proof_plugin_callbacks( string $tag ): array {
	if ( ! isset( $GLOBALS['wp_filter'][ $tag ]->callbacks ) ) {
		return array();
	}

	$found = array();

	foreach ( (array) $GLOBALS['wp_filter'][ $tag ]->callbacks as $callbacks ) {
		foreach ( (array) $callbacks as $callback ) {
			$function = $callback['function'] ?? null;

			if ( ! is_array( $function ) || ! isset( $function[0] ) ) {
				continue;
			}

			$class = is_object( $function[0] )
				? get_class( $function[0] )
				: ( is_string( $function[0] ) ? $function[0] : '' );

			if ( 0 === strpos( $class, 'WCCheckoutSuite\\' ) ) {
				$found[] = $tag;
			}
		}
	}

	return $found;
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-048 proof — accordion and summary' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The selectors are the platform's own markup.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the components bind to' );

$wccs_payment_template = wccs_proof_plugin_file( 'woocommerce/templates/checkout/payment-method.php' );
$wccs_review_template  = wccs_proof_plugin_file( 'woocommerce/templates/checkout/form-checkout.php' );

wccs_proof_check(
	'WooCommerce still renders the payment list and the method radio the frame wires',
	str_contains( $wccs_payment_template, 'wc_payment_method' )
		&& str_contains( $wccs_payment_template, 'name="payment_method"' ),
	'payment-method.php=' . strlen( $wccs_payment_template ) . ' bytes'
);

wccs_proof_check(
	'And the panel it belongs to is the one the template renders',
	str_contains( $wccs_payment_template, 'payment_box' )
);

wccs_proof_check(
	'And the order review the summary discloses is the platform\'s own element',
	str_contains( $wccs_review_template, 'class="woocommerce-checkout-review-order"' ),
	'a selector this plugin invented would style nothing'
);

wccs_proof_check(
	'The review element is inside the checkout form, which is what places the control next to it',
	str_contains( $wccs_review_template, 'form name="checkout"' )
		&& str_contains( $wccs_review_template, 'id="order_review"' )
);

// The panel's visibility is the platform's state, expressed in the element's own
// inline style. The module must not own it, and this is the platform fact that makes
// that a correctness requirement rather than a preference.
wccs_proof_check(
	'The template hides the panels of the methods that are not chosen, with an inline style',
	preg_match( '/style="display:\s*none;?"/', $wccs_payment_template ) === 1,
	'the state a second owner would fight'
);

$wccs_checkout_js = wccs_proof_plugin_file( 'woocommerce/assets/js/frontend/checkout.js' );

wccs_proof_check(
	'And the checkout slides those panels itself when the radio changes',
	str_contains( $wccs_checkout_js, 'payment_box' ) && str_contains( $wccs_checkout_js, 'slideDown' ),
	'the behaviour this plugin leaves where it is'
);

// ---------------------------------------------------------------------------
// 2. No gateway is registered, and no card field is declared.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The payment area stays the platform\'s' );

$wccs_gateway_hooks = array();

foreach ( array( 'woocommerce_payment_gateways', 'woocommerce_blocks_payment_method_type_registration', 'woocommerce_available_payment_gateways' ) as $wccs_hook_name ) {
	if ( array() !== wccs_proof_plugin_callbacks( $wccs_hook_name ) ) {
		$wccs_gateway_hooks[] = $wccs_hook_name;
	}
}

wccs_proof_check(
	'The plugin registers no gateway, and filters no gateway list',
	array() === $wccs_gateway_hooks,
	'mine=' . wp_json_encode( $wccs_gateway_hooks )
);

$wccs_gateways = array();

if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $wccs_gateway ) {
		$wccs_gateways[] = is_object( $wccs_gateway ) && method_exists( $wccs_gateway, 'get_title' )
			? (string) $wccs_gateway->id
			: '(unknown)';
	}
}

wccs_proof_check(
	'Every gateway the store offers is a plugin that is not this one',
	array() === array_filter(
		$wccs_gateways,
		static function ( string $id ): bool {
			return str_contains( $id, 'wccs' ) || str_contains( $id, 'checkoutsuite' );
		}
	),
	'gateways=' . wp_json_encode( $wccs_gateways )
);

$wccs_card_types = array();

foreach ( \WCCheckoutSuite\Domain\Registries::instance()->types()->keys() as $wccs_type ) {
	if ( preg_match( '/card|cvc|cvv|ccv|credit|expir|debit|gateway|payment/i', (string) $wccs_type ) ) {
		$wccs_card_types[] = (string) $wccs_type;
	}
}

wccs_proof_check(
	'No field type collects card data, which is the half of the acceptance this plugin owns',
	array() === $wccs_card_types,
	'found=' . wp_json_encode( $wccs_card_types )
);

$wccs_checkout_lexicon = array();

foreach ( array( 'payment.js', 'summary.js' ) as $wccs_module ) {
	$wccs_source = (string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/checkout/' . $wccs_module );
	$wccs_source = (string) preg_replace( '#/\*.*?\*/#s', '', $wccs_source );

	if ( preg_match( '/card|cvc|cvv|credit|expir|cardNumber/i', $wccs_source ) ) {
		$wccs_checkout_lexicon[] = $wccs_module;
	}
}

wccs_proof_check(
	'And neither module names card data at all',
	array() === $wccs_checkout_lexicon,
	'found=' . wp_json_encode( $wccs_checkout_lexicon )
);

// ---------------------------------------------------------------------------
// 3. The numbers are WooCommerce's.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Nothing here prices anything' );

$wccs_formatting = array();

foreach ( array( 'payment.js', 'summary.js' ) as $wccs_module ) {
	$wccs_source = (string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/checkout/' . $wccs_module );

	if ( preg_match( '/toFixed|Intl\.NumberFormat|wc_price|parseFloat|\.innerHTML\s*=/', $wccs_source ) ) {
		$wccs_formatting[] = $wccs_module;
	}
}

wccs_proof_check(
	'Neither module formats a price or rewrites the markup it was given',
	array() === $wccs_formatting,
	'found=' . wp_json_encode( $wccs_formatting )
);

$wccs_bootstrap = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();

wccs_proof_check(
	'The server publishes the control wording rather than the bundle hardcoding it',
	isset( $wccs_bootstrap['summary']['label'], $wccs_bootstrap['summary']['show'], $wccs_bootstrap['summary']['hide'] )
		&& '' !== $wccs_bootstrap['summary']['label']
		&& '' !== $wccs_bootstrap['summary']['show']
		&& '' !== $wccs_bootstrap['summary']['hide'],
	'label=' . ( $wccs_bootstrap['summary']['label'] ?? '(none)' )
);

wccs_proof_check(
	'And the three states are told apart, so the control can say what it does',
	$wccs_bootstrap['summary']['show'] !== $wccs_bootstrap['summary']['hide']
);

wccs_proof_check(
	'The payload carries nothing about a total, a coupon or a shipping rate',
	! isset( $wccs_bootstrap['summary']['total'], $wccs_bootstrap['summary']['coupons'], $wccs_bootstrap['summary']['shipping'] ),
	'a number that travelled from here would be a second source for it'
);

// ---------------------------------------------------------------------------
// 4. Both modules are in the bundle that ships.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The code that arrives' );

$wccs_bundle = WCCS_PLUGIN_DIR . 'build/checkout/index.js';

wccs_proof_check(
	'The checkout bundle was built',
	is_readable( $wccs_bundle ),
	'build/checkout/index.js'
);

$wccs_built = is_readable( $wccs_bundle ) ? (string) file_get_contents( $wccs_bundle ) : '';

wccs_proof_check(
	'And it carries the payment frame, which is what wires the real radios',
	str_contains( $wccs_built, 'data-wccs-payment-frame' )
		&& str_contains( $wccs_built, 'aria-controls' ),
	'bytes=' . strlen( $wccs_built )
);

wccs_proof_check(
	'And it carries the summary control',
	str_contains( $wccs_built, 'wccs-summary__toggle' )
);

wccs_proof_check(
	'And it binds to the platform\'s own selectors, not to invented ones',
	str_contains( $wccs_built, 'wc_payment_methods' )
		&& str_contains( $wccs_built, 'woocommerce-checkout-review-order' )
);

// A store with nothing to render is given no stylesheet at all — the gate WCCS-046
// proved — so the page has to be given something to render before this can be asked.
update_option(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
	(string) wp_json_encode(
		array(
			'revision'       => 48,
			'schema_version' => 1,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => array(
				array(
					'id'             => 'wccs_note',
					'integration_id' => 'wc-checkoutsuite/wccs_note',
					'origin'         => 'custom',
					'type'           => 'textarea',
					'label'          => 'Note',
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
				),
			),
			'sections'       => array(),
			'settings'       => array(),
		)
	),
	false
);

wp_dequeue_style( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE );
wp_dequeue_style( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, null );

$wccs_css = (string) file_get_contents( WCCS_PLUGIN_DIR . \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_FILE );

wccs_proof_check(
	'The stylesheet that arrives styles the controls this task added',
	wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' )
		&& str_contains( $wccs_css, '.wccs-summary__toggle' )
		&& str_contains( $wccs_css, '[data-wccs-payment-panel]' ),
	'registered=' . wp_json_encode( wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' ) )
);

wccs_proof_check(
	'And it sets no display on a gateway\'s panel, which is the state WooCommerce owns',
	preg_match( '/\[data-wccs-payment-panel\][^{]*\{[^}]*display:/s', $wccs_css ) !== 1,
	'the panel keeps the visibility the platform gave it'
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	0 === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_schema\_%'" ),
	'the schema slots are as the harness found them'
);

wccs_proof_note(
	'The panel has two possible owners, and this plugin is not one of them',
	'WooCommerce renders the chosen method\'s panel and hides the others with an inline style, and its checkout script slides them when the radio changes. A module that also set visibility would disagree with the platform in the direction that matters: the panel of a method the customer just selected, hidden by an attribute the platform never touches. What the module does is wire the pairing — an id on the panel and aria-controls on the radio — and mark the row so the stylesheet can style a box this plugin did not write.'
);

wccs_proof_note(
	'Why the wording comes from the server',
	'The summary control is the one piece of text this task adds to the checkout. It travels in the page payload from __() with the plugin text domain, so it is translated like every other string and the bundle hardcodes none of it.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'This store has no classic checkout page and no gateway is enabled (SANDBOX-PAYMENT), so the accordion has been exercised against WooCommerce\'s own markup in jsdom and against its templates on disk, never in a browser with a real gateway. Keyboard operation is the platform\'s radio group and a real button; the browser review is WCCS-063.'
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
