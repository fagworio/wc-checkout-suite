<?php
/**
 * WCCS-047 proof harness — the Blocks checkout, presented.
 *
 * Task:   WCCS-047 "Implementar apresentação Blocks"
 * Phase:  F09 · Página customizada e pagamento
 * Accept: "Layout nas regiões suportadas; não clona campos nem componentes de pagamento."
 *
 * The stylesheet's own properties are covered by the unit suite, which reads it as a
 * stylesheet and asserts that every selector stays inside the region this plugin
 * renders. What this harness proves is what no unit test can see:
 *
 * 1. the region is given the presentation, with the tokens it reads, and only there;
 * 2. **no field is drawn twice** — walked over every type the plugin registers, the
 *    set the native adapter registers and the set this renderer publishes are
 *    disjoint and together cover what the platform can be asked to draw;
 * 3. **no payment component is touched** — the plugin registers nothing on the Blocks
 *    payment extension points, declares no payment field type, and the presentation
 *    that ships names no payment class.
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
		$extra
	);
}

/**
 * A published section.
 *
 * @param string $id       Identifier.
 * @param string $location Location.
 * @return array<string, mixed>
 */
function wccs_proof_section( string $id, string $location ): array {
	return array(
		'id'       => $id,
		'title'    => ucfirst( $id ),
		'location' => $location,
		'position' => 10,
	);
}

/**
 * Writes a document straight into the published slot, bypassing the routes.
 *
 * @param array<int, mixed> $fields   Fields.
 * @param array<int, mixed> $sections Sections.
 * @return void
 */
function wccs_proof_publish( array $fields, array $sections ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 47,
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
 * The Blocks checkout fields service, when the platform has loaded it.
 *
 * Read out of the container rather than from a global function: the service is what
 * holds the additional fields this plugin registers, and asking the container is the
 * only way to read back what the platform accepted.
 *
 * @return object|null
 */
function wccs_proof_registry() {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
		return null;
	}

	try {
		$service = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
	} catch ( \Throwable $error ) {
		return null;
	}

	return is_object( $service ) ? $service : null;
}

/**
 * The additional fields the platform is currently holding, by identifier.
 *
 * @param object|null $registry Checkout fields service.
 * @return array<int, string>
 */
function wccs_proof_registered_ids( $registry ): array {
	if ( null === $registry || ! method_exists( $registry, 'get_additional_fields' ) ) {
		return array();
	}

	return array_map( 'strval', array_keys( (array) $registry->get_additional_fields() ) );
}

/**
 * The callbacks this plugin has on a hook, whoever else is on it.
 *
 * A hook the platform uses for its own registration is not empty, so asking whether it
 * is empty answers a question about WooCommerce. What this plugin promised is that it
 * is not among the callbacks, which is what this reads.
 *
 * @param string $tag Hook name.
 * @return array<int, string>
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

$wccs_assets_dir = 'WCCheckoutSuite\\Checkout\\Blocks\\BlocksRenderer';
$wccs_adapter    = 'WCCheckoutSuite\\Checkout\\Blocks\\BlocksAdapter';
$wccs_present    = 'WCCheckoutSuite\\Checkout\\Presentation';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-047 proof — the Blocks presentation' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// WCCS-050: the custom presentation is opt-in and off by default, and these proofs
// exercise the positive path. They ask for it, and restore the store to off at the end,
// so a run leaves the environment as it found it. The switch itself — that turning it
// on and off changes no engine and erases no field — is what WCCS-050's own proof is
// about.
\WCCheckoutSuite\Domain\Settings\CheckoutSettings::set_enabled( true );

// The published slot is cleared before anything is measured, so a run that died before
// its cleanup cannot make the next one fail — the correction WCCS-008's proof needed.
// The empty state is this file's decision, not the environment's.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
wp_cache_delete( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ), 'options' );

$wccs_options_before = wccs_proof_option_count();

wccs_proof_publish(
	array(
		wccs_proof_field( 'note', 'textarea', array( 'description' => 'Anything we should know.' ) ),
		wccs_proof_field(
			'person_type',
			'radio',
			array(
				'label'   => 'Tipo de pessoa',
				'section' => 'billing',
				'settings' => array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => 'Pessoa física',
						),
						array(
							'value' => 'pj',
							'label' => 'Pessoa jurídica',
						),
					),
				),
			)
		),
	),
	array(
		wccs_proof_section( 'contact', 'contact' ),
		wccs_proof_section( 'billing', 'billing' ),
		wccs_proof_section( 'order', 'order' ),
	)
);

$wccs_payload = $wccs_assets_dir::fields();

// ---------------------------------------------------------------------------
// 1. What the regions are given.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the Blocks checkout receives' );

wp_dequeue_style( $wccs_assets_dir::STYLE_HANDLE );
wp_dequeue_style( $wccs_present::TOKENS_HANDLE );
wp_dequeue_script( $wccs_assets_dir::SCRIPT_HANDLE );

$wccs_assets_dir::enqueue( true, $wccs_payload );

wccs_proof_check(
	'The presentation is enqueued on a Blocks checkout with fields',
	wp_style_is( $wccs_assets_dir::STYLE_HANDLE, 'enqueued' )
);

$wccs_registered = wp_styles()->registered[ $wccs_assets_dir::STYLE_HANDLE ] ?? null;
$wccs_deps       = null === $wccs_registered ? array() : (array) $wccs_registered->deps;

wccs_proof_check(
	'The tokens are enqueued with it, declared as its dependency',
	wp_style_is( $wccs_present::TOKENS_HANDLE, 'enqueued' ) && in_array( $wccs_present::TOKENS_HANDLE, $wccs_deps, true ),
	'deps=' . wp_json_encode( $wccs_deps )
);

wccs_proof_check(
	'And it is the file this plugin ships',
	null !== $wccs_registered
		&& str_contains( (string) $wccs_registered->src, $wccs_assets_dir::STYLE_FILE ),
	'src=' . ( null === $wccs_registered ? '(not registered)' : (string) $wccs_registered->src )
);

$wccs_classic = 'WCCheckoutSuite\\Checkout\\Classic\\ClassicAssets';

wccs_proof_check(
	'And it is a different file from the classic presentation, because the markup is different',
	$wccs_assets_dir::STYLE_FILE !== $wccs_classic::STYLE_FILE
		&& file_exists( WCCS_PLUGIN_DIR . $wccs_assets_dir::STYLE_FILE ),
	'blocks=' . $wccs_assets_dir::STYLE_FILE . ' classic=' . $wccs_classic::STYLE_FILE
);

wccs_proof_check(
	'The bundle is enqueued with it, so the region is both drawn and arranged',
	wp_script_is( $wccs_assets_dir::SCRIPT_HANDLE, 'enqueued' )
);

// ---------------------------------------------------------------------------
// 2. What every other request is not given.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What only a Blocks checkout receives' );

wp_dequeue_style( $wccs_assets_dir::STYLE_HANDLE );
wp_dequeue_style( $wccs_present::TOKENS_HANDLE );
wp_dequeue_script( $wccs_assets_dir::SCRIPT_HANDLE );

$wccs_assets_dir::enqueue( false, $wccs_payload );

wccs_proof_check(
	'A request that is not a Blocks checkout is given neither the presentation nor the tokens',
	! wp_style_is( $wccs_assets_dir::STYLE_HANDLE, 'enqueued' )
		&& ! wp_style_is( $wccs_present::TOKENS_HANDLE, 'enqueued' )
);

wp_dequeue_style( $wccs_assets_dir::STYLE_HANDLE );
wp_dequeue_style( $wccs_present::TOKENS_HANDLE );

$wccs_assets_dir::enqueue( true, array( 'fields' => array(), 'report' => array() ) );

wccs_proof_check(
	'A Blocks checkout with nothing to render is given neither',
	! wp_style_is( $wccs_assets_dir::STYLE_HANDLE, 'enqueued' )
		&& ! wp_style_is( $wccs_present::TOKENS_HANDLE, 'enqueued' ),
	'a checkout this plugin contributes nothing to pays for nothing'
);

// The two presentations are mutually exclusive: the classic file is written under
// `.wccs-checkout` and the Blocks file under the region class, and a request that
// loaded both would be styling a form that is not there.
wp_dequeue_style( $wccs_present::TOKENS_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( false, null );

wccs_proof_check(
	'The classic presentation is not delivered on a Blocks checkout',
	! wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' )
);

// ---------------------------------------------------------------------------
// 3. No field is drawn twice.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A field belongs to exactly one half' );

$wccs_types = \WCCheckoutSuite\Domain\Registries::instance()->types()->keys();

$wccs_fields = array();
$wccs_modes  = array();

foreach ( $wccs_types as $wccs_type ) {
	$wccs_type = (string) $wccs_type;

	// One field per registered type, in the section whose location the platform
	// knows, with options for the types that need them: what is being asked is
	// which half claims each type, not whether a particular field is well formed.
	$wccs_fields[] = wccs_proof_field(
		't_' . preg_replace( '/[^a-z0-9]+/', '_', $wccs_type ),
		$wccs_type,
		array(
			'settings' => array(
				'options' => array(
					array(
						'value' => 'one',
						'label' => 'One',
					),
					array(
						'value' => 'two',
						'label' => 'Two',
					),
				),
			),
		)
	);

	$wccs_modes[ $wccs_type ] = $wccs_adapter::mode( $wccs_type );
}

wccs_proof_publish(
	$wccs_fields,
	array(
		wccs_proof_section( 'contact', 'contact' ),
		wccs_proof_section( 'billing', 'billing' ),
		wccs_proof_section( 'order', 'order' ),
	)
);

$wccs_registry = wccs_proof_registry();

$wccs_native = array();
$wccs_drawn  = array();

// What the platform holds before this harness registers anything, so the question is
// about what this document produced and not about whatever load order left there.
$wccs_before_registration = wccs_proof_registered_ids( $wccs_registry );

if ( null !== $wccs_registry ) {
	\WCCheckoutSuite\Checkout\Blocks\BlocksCheckout::apply();
}

$wccs_native = array_values( array_diff( wccs_proof_registered_ids( $wccs_registry ), $wccs_before_registration ) );

foreach ( $wccs_assets_dir::fields()['fields'] as $wccs_entry ) {
	$wccs_drawn[] = (string) $wccs_entry['id'];
}

$wccs_both = array_values( array_intersect( $wccs_native, $wccs_drawn ) );

wccs_proof_check(
	'The platform is reachable and both halves answered',
	array() !== $wccs_native && array() !== $wccs_drawn,
	'native=' . count( $wccs_native ) . ' drawn=' . count( $wccs_drawn )
);

wccs_proof_check(
	'No field is claimed by both halves — which is what cloning a field would look like',
	array() === $wccs_both,
	'claimed twice=' . wp_json_encode( $wccs_both )
);

$wccs_native_types  = array();
$wccs_control_types = array();
$wccs_wrong_half    = array();

foreach ( $wccs_modes as $wccs_type => $wccs_mode ) {
	$wccs_id = 'wc-checkoutsuite/wccs_t_' . preg_replace( '/[^a-z0-9]+/', '_', $wccs_type );

	if ( 'native' === $wccs_mode ) {
		$wccs_native_types[] = $wccs_type;

		if ( ! in_array( $wccs_id, $wccs_native, true ) ) {
			$wccs_wrong_half[] = $wccs_type . ' (native, not registered)';
		}
	} elseif ( 'controlled' === $wccs_mode ) {
		$wccs_control_types[] = $wccs_type;

		if ( ! in_array( $wccs_id, $wccs_drawn, true ) ) {
			$wccs_wrong_half[] = $wccs_type . ' (controlled, not published)';
		}
	} elseif ( in_array( $wccs_id, $wccs_native, true ) || in_array( $wccs_id, $wccs_drawn, true ) ) {
		// A restricted type has no renderer anywhere, so neither half may claim it:
		// drawing one would be collecting a value the store cannot keep.
		$wccs_wrong_half[] = $wccs_type . ' (restricted, claimed anyway)';
	}
}

wccs_proof_check(
	'Every type is claimed by the half its mode names, over the whole registered list',
	array() === $wccs_wrong_half,
	sprintf(
		'%d types: %d native, %d controlled, %d restricted; wrong=',
		count( $wccs_modes ),
		count( $wccs_native_types ),
		count( $wccs_control_types ),
		count( $wccs_modes ) - count( $wccs_native_types ) - count( $wccs_control_types )
	) . wp_json_encode( $wccs_wrong_half )
);

wccs_proof_check(
	'And the native half drew only the types the platform can draw',
	array() === array_diff( $wccs_native, array_map(
		static function ( $wccs_type ) {
			return 'wc-checkoutsuite/wccs_t_' . preg_replace( '/[^a-z0-9]+/', '_', (string) $wccs_type );
		},
		$wccs_native_types
	) ),
	'extra=' . wp_json_encode( array_values( array_diff( $wccs_native, array_map(
		static function ( $wccs_type ) {
			return 'wc-checkoutsuite/wccs_t_' . preg_replace( '/[^a-z0-9]+/', '_', (string) $wccs_type );
		},
		$wccs_native_types
	) ) ) )
);

// ---------------------------------------------------------------------------
// 4. No payment component is touched.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The payment area stays WooCommerce\'s' );

$wccs_payment_hooks  = array();
$wccs_payment_others = array();

// Asked as "who is on this hook, and which of them is this plugin", not as "is the
// hook empty": WooCommerce registers its own payment methods on
// `woocommerce_blocks_payment_method_type_registration`, so an empty hook is not the
// property being claimed. Counting the world instead of the subject is the same
// mistake WCCS-025's asset assertion made, and the count printed is what tells the two
// apart — the hook is not empty, and nothing on it is ours.
foreach ( array( 'woocommerce_blocks_payment_method_type_registration', 'woocommerce_blocks_checkout_block_registration' ) as $wccs_hook_name ) {
	if ( array() !== wccs_proof_plugin_callbacks( $wccs_hook_name ) ) {
		$wccs_payment_hooks[] = $wccs_hook_name;
	}

	$wccs_total = 0;

	foreach ( (array) ( $GLOBALS['wp_filter'][ $wccs_hook_name ]->callbacks ?? array() ) as $wccs_callbacks ) {
		$wccs_total += count( (array) $wccs_callbacks );
	}

	$wccs_payment_others[ $wccs_hook_name ] = $wccs_total;
}

wccs_proof_check(
	'The plugin registers nothing on the Blocks payment extension points',
	array() === $wccs_payment_hooks,
	'mine=' . wp_json_encode( $wccs_payment_hooks ) . ' callbacks=' . wp_json_encode( $wccs_payment_others )
);

$wccs_payment_types = array();

foreach ( array_keys( $wccs_modes ) as $wccs_type ) {
	if ( preg_match( '/card|ccv|cvc|credit|payment|gateway/i', (string) $wccs_type ) ) {
		$wccs_payment_types[] = (string) $wccs_type;
	}
}

wccs_proof_check(
	'And the field type registry declares no card or payment type',
	array() === $wccs_payment_types,
	'found=' . wp_json_encode( $wccs_payment_types )
);

$wccs_source_hits = array();

foreach ( array_keys( $wccs_modes ) as $wccs_type ) {
	$wccs_reason = $wccs_adapter::reason( (string) $wccs_type );

	if ( '' !== $wccs_reason && preg_match( '/payment|card|cvc/i', $wccs_reason ) ) {
		$wccs_source_hits[] = (string) $wccs_type;
	}
}

wccs_proof_check(
	'Nor does any restriction reason promise a payment renderer',
	array() === $wccs_source_hits,
	'found=' . wp_json_encode( $wccs_source_hits )
);

$wccs_css = (string) file_get_contents( WCCS_PLUGIN_DIR . $wccs_assets_dir::STYLE_FILE );
$wccs_css = (string) preg_replace( '#/\*.*?\*/#s', '', $wccs_css );

wccs_proof_check(
	'The presentation that ships names no payment class and no card field',
	! preg_match( '/payment|card|cvc|credit/i', $wccs_css ),
	'bytes=' . strlen( $wccs_css )
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why the region and not the page',
	'The Blocks checkout is WooCommerce\'s page: the field blocks it draws, the order summary and the payment area are its markup, and a plugin that restyled them would be styling something it was not promised. What this plugin is given is a region inside a location, and the presentation is scoped to the wrapper its own components render.'
);

wccs_proof_note(
	'Why a container query and not a media query',
	'The same checkout places these fields in a wide column and in a narrow order sidebar, so the question "how much room do I have" is answered by the region. A viewport query would tighten the spacing on a wide desktop that gave the field a narrow region and leave it roomy on a phone whose region is full width — the opposite of both answers.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'This store has no Blocks checkout page, so the positive path is exercised by handing the gate its two booleans, and no field has been seen inside a live region — the placement depends on where the platform lets a third-party field be inserted, which belongs to the browser review in WCCS-063. What is proven is the delivery, the partition of every type, and the absence of any payment coupling.'
);

// The store is left opted out, which is where it started.
delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

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
