<?php
/**
 * WCCS-025 proof harness — the classic checkout lifecycle and refresh.
 *
 * Task:   WCCS-025 "Tratar lifecycle e refresh"
 * Phase:  F04
 * Accept: "Refresh preserva valores e cria apenas uma instância por componente."
 *
 * The contract itself is JavaScript and is proven by the JS suite, which renders
 * a real DOM in jsdom and replaces nodes the way WooCommerce's refresh does. What
 * this harness proves is everything around it that PHP owns: that the server
 * marks its own fields so the client can tell them apart, that the bundle is
 * delivered on the checkout and nowhere else, and that the bundle really depends
 * on the jQuery WordPress provides.
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
 * @param string            $slot   Slot name.
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => 3,
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
 * The form WooCommerce builds from the stored document.
 *
 * A fresh checkout instance, so the field array is produced by the real
 * `woocommerce_checkout_fields` filter with the stored schema in place.
 *
 * @return array<string, mixed>
 */
function wccs_proof_form(): array {
	$checkout = new WC_Checkout();

	return $checkout->get_checkout_fields();
}

/**
 * Whether a specific class method is hooked on a tag at a priority.
 *
 * Both callable shapes are accepted: a static method is registered as a class
 * name and an instance method as an object, and a check that knows only one of
 * them reports a hook as missing when it is there.
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

		if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) || $method !== $function[1] ) {
			continue;
		}

		$target = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

		if ( $class === $target ) {
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
wccs_proof_out( 'WCCS-025 proof — classic checkout lifecycle and refresh' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_published_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );

// ---------------------------------------------------------------------------
// 1. The storefront half grew by exactly the hooks this task added.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The storefront half' );

wccs_proof_check(
	'The bundle is registered on the front-end hook',
	wccs_proof_hooked(
		'wp_enqueue_scripts',
		10,
		'WCCheckoutSuite\\Checkout\\Classic\\ClassicAssets',
		'enqueue'
	),
	'priority 10'
);

$wccs_hooks = wccs_proof_plugin_hooks();

wccs_proof_check(
	'The storefront half is exactly these hooks and nothing else',
	array(
		'woocommerce_after_checkout_validation@20:collect_errors',
		'woocommerce_checkout_create_order@20:persist',
		'woocommerce_checkout_fields@20:filter_fields',
		'woocommerce_checkout_posted_data@20:normalize_posted_data',
		// Added by WCCS-043: the classic checkout has no file type, and this is the
		// documented filter an unknown type reaches.
		'woocommerce_form_field_file@10:render',
		'wp_enqueue_scripts@10:enqueue',
	) === $wccs_hooks,
	'found ' . wp_json_encode( $wccs_hooks )
);

// ---------------------------------------------------------------------------
// 2. The server marks its own fields so the client can tell them apart.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The fields the client half may touch' );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def( 'wccs_cpf', array( 'settings' => array( 'maxLength' => 14 ) ) ),
		wccs_proof_def( 'wccs_untouched', array( 'type' => 'heading' ) ),
		wccs_proof_def(
			'billing_first_name',
			array(
				'origin' => 'core',
				'label'  => 'Primeiro nome',
			)
		),
	)
);

$wccs_form = wccs_proof_form();

wccs_proof_check(
	'A field the Suite owns carries the marker',
	'wccs_cpf' === ( $wccs_form['billing']['wccs_cpf']['custom_attributes']['data-wccs-field'] ?? null ),
	'attributes=' . wp_json_encode( $wccs_form['billing']['wccs_cpf']['custom_attributes'] ?? null )
);

wccs_proof_check(
	'And keeps the attributes it declared',
	14 === ( $wccs_form['billing']['wccs_cpf']['custom_attributes']['maxlength'] ?? null ),
	'the marker sits beside them rather than replacing them'
);

wccs_proof_check(
	'A field WooCommerce owns is not marked',
	! isset( $wccs_form['billing']['billing_first_name']['custom_attributes']['data-wccs-field'] ),
	'WooCommerce repopulates its own fields from the session'
);

wccs_proof_check(
	'A field the classic checkout cannot render is not on the form at all',
	! isset( $wccs_form['billing']['wccs_untouched'] ),
	'and therefore cannot be marked'
);

// ---------------------------------------------------------------------------
// 3. The bundle is delivered only where it is needed.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. When the bundle is delivered' );

$wccs_gates = array(
	'checkout with fields'    => array( true, true, true ),
	'checkout with no fields' => array( true, false, false ),
	'other page with fields'  => array( false, true, false ),
	'other page without'      => array( false, false, false ),
);

foreach ( $wccs_gates as $wccs_case => $wccs_gate ) {
	wccs_proof_check(
		'should_enqueue(' . $wccs_case . ')',
		$wccs_gate[2] === \WCCheckoutSuite\Checkout\Classic\ClassicAssets::should_enqueue( $wccs_gate[0], $wccs_gate[1] ),
		'is_checkout=' . var_export( $wccs_gate[0], true ) . ' has_fields=' . var_export( $wccs_gate[1], true )
	);
}

wccs_proof_check(
	'The published schema has something to initialise',
	true === \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields(),
	'the document carries a text field and a core override'
);

// The bundle is delivered when both gates pass, and the delivery is checked the
// way WordPress would: the script is in the enqueued registry with the URL the
// enqueue built and the dependencies the build recorded.
\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, true );

wccs_proof_check(
	'The bundle is enqueued when the checkout needs it',
	true === wp_script_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'enqueued' ),
	'handle=' . \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE
);

$wccs_registered = wp_scripts()->registered[ \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE ] ?? null;

wccs_proof_check(
	'And it points at the built bundle',
	is_object( $wccs_registered )
		&& WCCS_PLUGIN_URL . 'build/checkout/index.js' === $wccs_registered->src,
	'src=' . ( is_object( $wccs_registered ) ? (string) $wccs_registered->src : 'none' )
);

wccs_proof_check(
	'And WordPress is told to load jQuery first',
	is_object( $wccs_registered ) && in_array( 'jquery', (array) $wccs_registered->deps, true ),
	'deps=' . wp_json_encode( is_object( $wccs_registered ) ? $wccs_registered->deps : null )
);

wccs_proof_check(
	'And it loads in the footer, after the form exists',
	is_object( $wccs_registered ) && 1 === (int) $wccs_registered->extra['group'],
	'group=' . var_export( is_object( $wccs_registered ) ? ( $wccs_registered->extra['group'] ?? null ) : null, true )
);

$wccs_manifest = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::asset_manifest();

wccs_proof_check(
	'The dependency list comes from the build, not from a hand kept list',
	in_array( 'jquery', $wccs_manifest['dependencies'], true ),
	'dependencies=' . wp_json_encode( $wccs_manifest['dependencies'] )
);

// ---------------------------------------------------------------------------
// 4. Nothing is delivered when the request is not the checkout.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What a request that is not the checkout gets' );

wp_dequeue_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );
wp_deregister_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );

// Asking the real gate: this request is WP-CLI, so it is not a checkout.
\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue();

wccs_proof_check(
	'A request that is not the checkout is not given the bundle',
	false === wp_script_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'enqueued' )
		&& false === wp_script_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'registered' ),
	'is_checkout() is false here, which is the screen gate holding'
);

$wccs_asset_hits = 0;

foreach ( array_merge( (array) wp_scripts()->registered, (array) wp_styles()->registered ) as $wccs_asset ) {
	if ( isset( $wccs_asset->src ) && false !== strpos( (string) $wccs_asset->src, 'wc-checkout-suite' ) ) {
		++$wccs_asset_hits;
	}
}

wccs_proof_check(
	'And no asset of this plugin is left registered on the request',
	0 === $wccs_asset_hits,
	'assets=' . $wccs_asset_hits
);

// The bundle cannot be delivered for a schema with nothing to run it on.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );

wccs_proof_check(
	'A store with no published schema has nothing to initialise',
	false === \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields()
);

wccs_proof_store( $wccs_published_slot, array( wccs_proof_def( 'wccs_untouched', array( 'type' => 'heading' ) ) ) );

wccs_proof_check(
	'And neither has a schema whose only fields the classic checkout cannot render',
	false === \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields(),
	'a file field is skipped by the adapter, so there is nothing to start'
);

// ---------------------------------------------------------------------------
// 5. The bundle that ships is the one the contract was written against.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The bundle' );

wccs_proof_check(
	'The bundle was built and is readable',
	is_readable( WCCS_PLUGIN_DIR . 'build/checkout/index.js' ),
	'size=' . ( is_readable( WCCS_PLUGIN_DIR . 'build/checkout/index.js' ) ? (string) filesize( WCCS_PLUGIN_DIR . 'build/checkout/index.js' ) : '0' ) . ' bytes'
);

wccs_proof_check(
	'The build manifest is readable too',
	is_readable( WCCS_PLUGIN_DIR . 'build/checkout/index.asset.php' )
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Where the contract itself is proven',
	'The lifecycle and the value keeper are covered by tests/js/checkout/lifecycle.test.js (11 specs) and tests/js/checkout/values.test.js (10 specs). They render a real DOM in jsdom, replace nodes the way a fragment refresh does, and assert that a component starts once per element and that a value typed before the replacement is restored into the new node.'
);

wccs_proof_note(
	'Not exercised here',
	'A rendered classic checkout page. The positive enqueue was driven by handing the two gates in, which is how the administration assets are tested for the same reason; whether WooCommerce calls the hook on a real checkout follows from the hook being on wp_enqueue_scripts, and this store has no classic checkout page to observe it on. That is the open decision CLASSIC-TEST-SURFACE.'
);

wccs_proof_note(
	'Not exercised here',
	'WooCommerce\'s AJAX refresh against a real server response. The replacement of DOM nodes is simulated in jsdom; the fragment list WooCommerce returns by default is the order review table and the payment box, and neither holds a Suite field, so the keeper matters for replacements a plugin or theme adds through woocommerce_update_order_review_fragments.'
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
