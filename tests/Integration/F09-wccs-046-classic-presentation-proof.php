<?php
/**
 * WCCS-046 proof harness — the classic presentation, delivered.
 *
 * Task:   WCCS-046 "Implementar apresentação Classic"
 * Phase:  F09 · Página customizada e pagamento
 * Accept: "Layout responsivo do anexo com gap correto; hooks preservados."
 *
 * What the stylesheet *says* is proven by the unit suite, which reads it as a
 * stylesheet and asserts the grid, the gap and the scope. What this harness proves
 * is the delivery half, which no unit test can see: that the checkout page is given
 * the presentation together with the tokens it reads, that every other page of the
 * store is given neither, and that nothing this plugin ships takes the form away
 * from whoever renders it. The last one is the half of the acceptance criterion the
 * word "hooks preservados" is about, and it is asserted against the same shared
 * list four other harnesses already hold the storefront to.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

require_once __DIR__ . '/support/storefront-hooks.php';

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
 * The WooCommerce actions a form is drawn from, collected over the whole plugin.
 *
 * These are the actions a template override uses to draw a different checkout, so
 * being on one of them is how a plugin takes the form over. Scanned over every
 * `WCCheckoutSuite\` class rather than one namespace on purpose: a presentation that
 * reached the form through a hook would do it from whichever class wrote the hook.
 *
 * @return array<int, string>
 */
function wccs_proof_form_hooks(): array {
	$actions = array(
		'woocommerce_before_checkout_form',
		'woocommerce_checkout_before_customer_details',
		'woocommerce_checkout_billing',
		'woocommerce_checkout_shipping',
		'woocommerce_checkout_after_customer_details',
		'woocommerce_checkout_before_order_review',
		'woocommerce_checkout_order_review',
		'woocommerce_after_checkout_form',
	);
	$found   = array();

	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! in_array( $tag, $actions, true ) || ! isset( $hook->callbacks ) ) {
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

				if ( 0 !== strpos( $class, 'WCCheckoutSuite\\' ) ) {
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
 * Every hook this plugin registers on the storefront half, as `tag@priority:method`.
 *
 * Scoped to the classic checkout namespace, which is what the shared expectation
 * describes; the administration and REST halves register their own hooks on the same
 * process and are not this list's business.
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
 * Writes a document straight into a slot, bypassing the routes.
 *
 * @param string            $slot   Slot name.
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => 46,
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
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

$wccs_options_before = wccs_proof_option_count();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-046 proof — the classic presentation' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;

wccs_proof_store( $wccs_slot, array( wccs_proof_def( 'wccs_note' ) ) );

$wccs_assets  = 'WCCheckoutSuite\\Checkout\\Classic\\ClassicAssets';
$wccs_style   = $wccs_assets::STYLE_HANDLE;
$wccs_tokens  = \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE;

// ---------------------------------------------------------------------------
// 1. What the checkout page is given.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the checkout page receives' );

wp_dequeue_style( $wccs_style );
wp_dequeue_style( $wccs_tokens );

$wccs_assets::enqueue( true, null );

wccs_proof_check(
	'The presentation is enqueued on the checkout',
	wp_style_is( $wccs_style, 'enqueued' )
);

$wccs_registered = wp_styles()->registered[ $wccs_style ] ?? null;
$wccs_deps       = null === $wccs_registered ? array() : (array) $wccs_registered->deps;

wccs_proof_check(
	'The tokens are enqueued with it, declared as its dependency',
	wp_style_is( $wccs_tokens, 'enqueued' ) && in_array( $wccs_tokens, $wccs_deps, true ),
	'deps=' . wp_json_encode( $wccs_deps )
);

wccs_proof_check(
	'And it is the file this plugin ships, not a copy of the theme',
	null !== $wccs_registered
		&& str_contains( (string) $wccs_registered->src, 'resources/checkout/presentation.css' ),
	'src=' . ( null === $wccs_registered ? '(not registered)' : (string) $wccs_registered->src )
);

wccs_proof_check(
	'And the tokens are the file it ships too, so a token change reaches the checkout',
	isset( wp_styles()->registered[ $wccs_tokens ] )
		&& str_contains( (string) wp_styles()->registered[ $wccs_tokens ]->src, 'resources/design-tokens/tokens.css' ),
	'src=' . ( isset( wp_styles()->registered[ $wccs_tokens ] ) ? (string) wp_styles()->registered[ $wccs_tokens ]->src : '(not registered)' )
);

// ---------------------------------------------------------------------------
// 2. The class the stylesheet is written under.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The scope the presentation is written under' );

wccs_proof_check(
	'The scope class is the literal every selector in the stylesheet uses',
	'wccs-checkout' === $wccs_assets::SCOPE_CLASS
		&& str_contains(
			(string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/checkout/presentation.css' ),
			'.' . $wccs_assets::SCOPE_CLASS
		),
	'scope=' . $wccs_assets::SCOPE_CLASS
);

wccs_proof_check(
	'It is added to the body by a filter, not by a template this plugin does not ship',
	false !== has_filter( 'body_class', array( $wccs_assets, 'body_class' ) )
);

// The same gate that delivers the stylesheet decides the class, so the class is on
// exactly the requests that were given the presentation. A checkout with the
// stylesheet and without the class is a checkout the presentation does not reach,
// which is the defect this section exists to catch.
$wccs_plain = array( 'home', 'woocommerce', 'woocommerce-checkout' );

wccs_proof_check(
	'A checkout with something to render is marked',
	in_array( $wccs_assets::SCOPE_CLASS, $wccs_assets::add_scope( $wccs_plain, true, true ), true ),
	'classes=' . wp_json_encode( $wccs_assets::add_scope( $wccs_plain, true, true ) )
);

wccs_proof_check(
	'And marked by the same gate that enqueues the stylesheet, in every combination',
	( in_array( $wccs_assets::SCOPE_CLASS, $wccs_assets::add_scope( $wccs_plain, true, true ), true ) )
		=== $wccs_assets::should_enqueue( true, true )
		&& ( in_array( $wccs_assets::SCOPE_CLASS, $wccs_assets::add_scope( $wccs_plain, true, false ), true ) )
		=== $wccs_assets::should_enqueue( true, false )
		&& ( in_array( $wccs_assets::SCOPE_CLASS, $wccs_assets::add_scope( $wccs_plain, false, true ), true ) )
		=== $wccs_assets::should_enqueue( false, true )
		&& ( in_array( $wccs_assets::SCOPE_CLASS, $wccs_assets::add_scope( $wccs_plain, false, false ), true ) )
		=== $wccs_assets::should_enqueue( false, false )
);

wccs_proof_check(
	'Another page of the store is left exactly as the theme left it',
	$wccs_plain === $wccs_assets::add_scope( $wccs_plain, false, true )
		&& $wccs_plain === $wccs_assets::add_scope( $wccs_plain, true, false )
);

$wccs_marked = $wccs_assets::add_scope( $wccs_plain, true, true );

wccs_proof_check(
	'Marking twice does not mark twice — the filter can run more than once',
	$wccs_marked === $wccs_assets::add_scope( $wccs_marked, true, true )
);

// ---------------------------------------------------------------------------
// 3. What every other request is not given.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What only the checkout receives' );

wp_dequeue_style( $wccs_style );
wp_dequeue_style( $wccs_tokens );

$wccs_assets::enqueue( false, null );

wccs_proof_check(
	'A request that is not the checkout is given neither stylesheet',
	! wp_style_is( $wccs_style, 'enqueued' ) && ! wp_style_is( $wccs_tokens, 'enqueued' )
);

wccs_proof_check(
	'And the gate is a decision over both conditions, not a side effect of the request',
	$wccs_assets::should_enqueue( true, true )
		&& ! $wccs_assets::should_enqueue( true, false )
		&& ! $wccs_assets::should_enqueue( false, true )
		&& ! $wccs_assets::should_enqueue( false, false )
);

// A store with nothing to render pays for nothing: the presentation styles a form
// the plugin contributes to, and on a checkout that contributes nothing there is
// nothing to present.
wccs_proof_store( $wccs_slot, array( wccs_proof_def( 'wccs_heading', array( 'type' => 'heading' ) ) ) );

wccs_proof_check(
	'A checkout with no renderable field is given neither stylesheet',
	! $wccs_assets::has_renderable_fields() && ! $wccs_assets::should_enqueue( true, $wccs_assets::has_renderable_fields() )
);

wccs_proof_store( $wccs_slot, array( wccs_proof_def( 'wccs_note' ) ) );

// ---------------------------------------------------------------------------
// 4. Hooks preserved.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Nothing of the form is replaced' );

$wccs_overrides = array();

foreach ( array( 'templates', 'woocommerce', 'templates/woocommerce', 'woocommerce/checkout' ) as $wccs_relative ) {
	if ( is_dir( WCCS_PLUGIN_DIR . $wccs_relative ) ) {
		$wccs_overrides[] = $wccs_relative;
	}
}

wccs_proof_check(
	'This plugin ships no directory WooCommerce reads templates from',
	array() === $wccs_overrides,
	'found=' . wp_json_encode( $wccs_overrides )
);

$wccs_loaders = array();

foreach ( array( 'woocommerce_locate_template', 'woocommerce_template_path' ) as $wccs_filter ) {
	if ( false !== has_filter( $wccs_filter ) ) {
		$wccs_loaders[] = $wccs_filter;
	}
}

wccs_proof_check(
	'And it points no template loader at itself',
	array() === $wccs_loaders,
	'found=' . wp_json_encode( $wccs_loaders )
);

$wccs_form_hooks = wccs_proof_form_hooks();

wccs_proof_check(
	'It puts nothing of its own on the actions a form is drawn from',
	array() === $wccs_form_hooks,
	'found=' . wp_json_encode( $wccs_form_hooks )
);

$wccs_hooks = wccs_proof_plugin_hooks();

wccs_proof_check(
	'The storefront half is exactly the hooks the shared list records, and this task added one',
	wccs_proof_expected_hooks() === $wccs_hooks,
	'found ' . wp_json_encode( $wccs_hooks )
);

wccs_proof_check(
	'And that one is the scope class, which is the only way the presentation touches the page',
	in_array( 'body_class@10:body_class', $wccs_hooks, true )
);

wccs_proof_check(
	'So the fields still reach the form the supported way — filtered into WooCommerce\'s own array',
	in_array( 'woocommerce_checkout_fields@20:filter_fields', $wccs_hooks, true )
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );

$wccs_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why the stylesheet is a file and not part of the bundle',
	'A layout that arrives with the JavaScript is a layout a customer does not get until the bundle has run — on a slow connection that is a checkout visible in a different arrangement from the one the merchant approved. It is served as its own file, with the tokens as a declared dependency so the variables it reads exist before it uses them.'
);

wccs_proof_note(
	'What the scope is for',
	'Every selector is qualified by `.wccs-checkout`, and that class is added by the same gate that delivers the file, so it is on exactly the requests that receive it. This is why the scope is the one hook this task added: the alternative was a class nothing emitted, which is a stylesheet that never applies.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'This store has no classic checkout page, so the positive path is exercised by handing the gate its two booleans — the same substitution WCCS-025 uses. The rendered arrangement of the form is not observed here; it needs a browser, and it is recorded as unobserved rather than claimed.'
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
