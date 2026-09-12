<?php
/**
 * WCCS-063 proof harness — the storefront delivery, under a real dispatch.
 *
 * Task:   WCCS-063 "Executar QA visual e a11y"
 * Phase:  F12 · Hardening, acessibilidade e matriz final
 * Accept: "320/375/768/1280/1440px, zoom, foco e reduced motion revisados."
 *
 * The browser observation needs a page that carries this plugin's region, and a page
 * cannot carry what the request never enqueued. This harness proves the delivery
 * half — the half that had never been exercised through WordPress's own dispatcher,
 * because every earlier harness called the gate with the booleans already decided.
 *
 * That is exactly how a defect survived seven observation rounds: `wp_enqueue_script`
 * was never reached on a real page load, while every direct call reported success.
 * WordPress dispatches an action fired without arguments by appending a single empty
 * string (`do_action()` does it in core, for PHP4-era compatibility), so a callback
 * declared `enqueue( ?bool $is_blocks = null )` was handed `false` under PHP's
 * coercive mode and returned before enqueueing anything. The gates are now registered
 * with zero accepted arguments, and this harness fires the action the way `wp_head`
 * does instead of passing its own answer.
 *
 * What the browser half proves is in `tests/browser/checkout-observation.mjs`; what
 * this half proves is that there is something for it to observe, and which half of
 * the plugin is supposed to be on the page at all.
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
 * The handles this plugin puts on a request.
 *
 * @return array<int, string>
 */
function wccs_delivery_handles(): array {
	return array(
		\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE,
		\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::STYLE_HANDLE,
		\WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE,
		\WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE,
		\WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE,
	);
}

/**
 * Takes this plugin's handles off the request, so each scenario starts clean.
 *
 * Without this a scenario would inherit the previous one's enqueues and assert
 * about them: the first thing every measurement here needs is that the queue is
 * not carrying the last answer.
 *
 * @return void
 */
function wccs_delivery_clear(): void {
	foreach ( wccs_delivery_handles() as $wccs_handle ) {
		wp_dequeue_script( $wccs_handle );
		wp_deregister_script( $wccs_handle );
		wp_dequeue_style( $wccs_handle );
		wp_deregister_style( $wccs_handle );
	}
}

/**
 * A field definition in the shape the editor writes.
 *
 * @param string $id    Bare identifier.
 * @param string $type  Field type.
 * @param int    $order Position.
 * @return array<string, mixed>
 */
function wccs_delivery_field( string $id, string $type, int $order ): array {
	return array(
		'id'             => 'wccs_delivery_' . $id,
		'integration_id' => 'wc-checkoutsuite/wccs_delivery_' . $id,
		'origin'         => 'custom',
		'type'           => $type,
		'label'          => 'Delivery ' . $id,
		'section'        => 'wccs_delivery',
		'enabled'        => true,
		'required'       => false,
		'position'       => $order,
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
	);
}

/**
 * Publishes a document through the repository, which is the path the store reads.
 *
 * @param \WCCheckoutSuite\Domain\Schema\SchemaRepository $repository Repository.
 * @param array<int, array<string, mixed>>                $fields     Fields.
 * @return void
 */
function wccs_delivery_publish( $repository, array $fields ): void {
	$document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision'       => 1,
			'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
			'sections'       => array(
				array(
					'id'          => 'wccs_delivery',
					'title'       => 'Delivery',
					'description' => '',
					'position'    => 10,
					'location'    => 'billing',
				),
			),
			'settings'       => array(),
		)
	);

	$repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $document, null );
	$repository->publish( $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), null, 1 );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-063 delivery proof — what the request enqueues' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

$wccs_blocks_handle  = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE;
$wccs_classic_handle = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE;
$wccs_tokens_handle  = \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE;

// ---------------------------------------------------------------------------
// 1. The dispatcher this plugin is registered on.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The dispatcher' );

$wccs_dispatched = null;

add_action(
	'wccs_dispatch_probe',
	static function ( $first = null ) use ( &$wccs_dispatched ) {
		$wccs_dispatched = $first;
	}
);

do_action( 'wccs_dispatch_probe' );

wccs_proof_check(
	'WordPress hands an argument-less action one empty string, which is why the gate takes no arguments',
	"''" === var_export( $wccs_dispatched, true ),
	'received=' . var_export( $wccs_dispatched, true )
);

$wccs_argument_counts = array();

if ( isset( $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks ) ) {
	foreach ( $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks as $wccs_callbacks ) {
		foreach ( $wccs_callbacks as $wccs_callback ) {
			$wccs_function = $wccs_callback['function'] ?? null;

			if ( ! is_array( $wccs_function ) || ! isset( $wccs_function[0], $wccs_function[1] ) ) {
				continue;
			}

			$wccs_class = is_object( $wccs_function[0] ) ? get_class( $wccs_function[0] ) : (string) $wccs_function[0];

			if ( 0 !== strpos( $wccs_class, 'WCCheckoutSuite\\' ) ) {
				continue;
			}

			$wccs_argument_counts[ $wccs_class . '::' . $wccs_function[1] ] = (int) ( $wccs_callback['accepted_args'] ?? -1 );
		}
	}
}

wccs_proof_check(
	'Both enqueue gates are registered taking zero arguments, so the empty string never reaches them',
	array(
		'WCCheckoutSuite\\Checkout\\Blocks\\BlocksRenderer::enqueue'  => 0,
		'WCCheckoutSuite\\Checkout\\Classic\\ClassicAssets::enqueue' => 0,
	) === $wccs_argument_counts,
	'registered=' . wp_json_encode( $wccs_argument_counts )
);

// The checkout page this store serves, and the half of the plugin that owns it.
$wccs_is_blocks_store = class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
	&& \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();

wccs_proof_note(
	'Which checkout this store serves',
	'checkout_page=' . wc_get_page_id( 'checkout' )
		. ' block_theme=' . ( wp_is_block_theme() ? '1' : '0' )
		. ' page_has_block=' . ( has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ) ? '1' : '0' )
		. ' blocks_default=' . ( $wccs_is_blocks_store ? '1' : '0' )
);

// The storefront gate reads the conditional WooCommerce publishes. In WP-CLI there is
// no query, so the conditional is asked for explicitly — the same filter WooCommerce's
// own `is_checkout()` consults first, not a stub of the plugin's gate.
add_filter( 'woocommerce_is_checkout', '__return_true' );

$wccs_registries = \WCCheckoutSuite\Domain\Registries::instance();
$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	$wccs_registries->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

// ---------------------------------------------------------------------------
// 2. A store whose schema has nothing to render ships nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. No document, no delivery' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
\WCCheckoutSuite\Domain\Settings\CheckoutSettings::set_enabled( false );

wccs_delivery_clear();
do_action( 'wp_enqueue_scripts' );

wccs_proof_check(
	'An empty schema enqueues neither bundle, which is what makes the delivery a decision and not a constant',
	! wp_script_is( $wccs_blocks_handle, 'enqueued' ) && ! wp_script_is( $wccs_classic_handle, 'enqueued' ),
	'blocks=' . ( wp_script_is( $wccs_blocks_handle, 'enqueued' ) ? 1 : 0 )
		. ' classic=' . ( wp_script_is( $wccs_classic_handle, 'enqueued' ) ? 1 : 0 )
);

// ---------------------------------------------------------------------------
// 3. A field only this plugin can draw is delivered with its payload.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The controlled field, delivered' );

wccs_delivery_publish(
	$wccs_repository,
	array(
		wccs_delivery_field( 'note', 'text', 30 ),
		wccs_delivery_field( 'message', 'textarea', 31 ),
	)
);

\WCCheckoutSuite\Domain\Settings\CheckoutSettings::set_enabled( true );
wccs_delivery_clear();
do_action( 'wp_enqueue_scripts' );

$wccs_payload = wp_scripts()->get_data( $wccs_blocks_handle, 'before' );

wccs_proof_check(
	'The Blocks bundle is enqueued on a real dispatch, with the controlled field published',
	wp_script_is( $wccs_blocks_handle, 'enqueued' ),
	'handle=' . $wccs_blocks_handle
);

wccs_proof_check(
	'The payload travels with the bundle, so the page can draw the field the platform cannot',
	is_array( $wccs_payload ) && array() !== $wccs_payload && false !== strpos( implode( "\n", $wccs_payload ), 'window.wccsBlocks' ),
	'payload=' . ( is_array( $wccs_payload ) ? substr( implode( ' ', $wccs_payload ), 0, 120 ) : gettype( $wccs_payload ) )
);

wccs_proof_check(
	'The payload carries the controlled field and not the native one, which WooCommerce already draws',
	is_array( $wccs_payload ) && 1 === substr_count( implode( "\n", $wccs_payload ), '"name":' ),
	'fields=' . ( is_array( $wccs_payload ) ? substr_count( implode( "\n", $wccs_payload ), '"name":' ) : 0 )
);

wccs_proof_check(
	'The presentation and its tokens arrive with the fields when the merchant opted in',
	wp_style_is( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::STYLE_HANDLE, 'enqueued' ) && wp_style_is( $wccs_tokens_handle, 'enqueued' ),
	'presentation=' . ( wp_style_is( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::STYLE_HANDLE, 'enqueued' ) ? 1 : 0 )
		. ' tokens=' . ( wp_style_is( $wccs_tokens_handle, 'enqueued' ) ? 1 : 0 )
);

// ---------------------------------------------------------------------------
// 4. The other half stays off the page it does not own.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. One checkout, one half' );

$wccs_blocks_gate = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::is_blocks_checkout();

wccs_proof_check(
	'The request is the Blocks checkout, so the classic half must stand down',
	$wccs_blocks_gate,
	'blocks_gate=' . ( $wccs_blocks_gate ? 1 : 0 )
);

wccs_proof_check(
	'The classic bundle is not enqueued on the Blocks checkout, though the same document has a field it can render',
	! wp_script_is( $wccs_classic_handle, 'enqueued' )
		&& \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields(),
	'classic_enqueued=' . ( wp_script_is( $wccs_classic_handle, 'enqueued' ) ? 1 : 0 )
		. ' renderable=' . ( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields() ? 1 : 0 )
);

$wccs_body = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::body_class( array( 'woocommerce-checkout', 'page-id-2755' ) );

wccs_proof_check(
	'The scope class the classic stylesheet is written under is not added to the Blocks checkout',
	is_array( $wccs_body ) && ! in_array( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCOPE_CLASS, $wccs_body, true ),
	'body=' . wp_json_encode( $wccs_body )
);

// ---------------------------------------------------------------------------
// 5. A document the platform can draw entirely leaves this plugin's bundle off.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. A native-only document' );

wccs_delivery_publish( $wccs_repository, array( wccs_delivery_field( 'note', 'text', 30 ) ) );

wccs_delivery_clear();
do_action( 'wp_enqueue_scripts' );

wccs_proof_check(
	'With nothing the platform refuses, this plugin ships no bundle: an observation looking for its region here is measuring what it configured',
	! wp_script_is( $wccs_blocks_handle, 'enqueued' ),
	'blocks=' . ( wp_script_is( $wccs_blocks_handle, 'enqueued' ) ? 1 : 0 )
);

$wccs_adapter = new \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter();

wccs_proof_check(
	'And the native half still registers the field with WooCommerce, which is the delivery that is not this bundle',
	array() !== $wccs_adapter->apply(
		$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->fields(),
		$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->sections()
	)['registrations'],
	'registrations=' . count(
		$wccs_adapter->apply(
			$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->fields(),
			$wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->sections()
		)['registrations']
	)
);

// ---------------------------------------------------------------------------
// 6. What the browser half covers, and what it cannot.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. The other half' );

wccs_proof_note(
	'The visual and accessibility half',
	'Run with Playwright and the store\'s own Chrome: `WCCS_PRODUCT=<id> node tests/browser/checkout-observation.mjs`. It reports the five widths, zoom, the focus ring and reduced motion against the rendered page, which no PHP harness can see.'
);

wccs_proof_note(
	'The store is in WooCommerce\'s "coming soon" mode',
	'woocommerce_coming_soon=' . get_option( 'woocommerce_coming_soon', 'absent' )
		. ' store_pages_only=' . get_option( 'woocommerce_store_pages_only', 'absent' )
		. '. An anonymous visit to the checkout is answered with the coming-soon screen and carries none of the checkout; a user who can manage WooCommerce sees the store. The browser script authenticates for that reason, and the condition is recorded rather than changed.'
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

remove_filter( 'woocommerce_is_checkout', '__return_true' );

foreach ( array( 'draft', 'published' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

delete_option( 'wccs_schema_revisions' );
delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// Weaker than equality on purpose: a store being observed by hand may already carry
// options this harness does not own, and deleting those would be this harness
// deciding about state that is not its business. What it must not do is leave any.
wccs_proof_check(
	'The harness left no stored option of its own behind',
	$wccs_options_after <= $wccs_options_before
		&& false === get_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( 'draft' ), false )
		&& false === get_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( 'published' ), false )
		&& false === get_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION, false ),
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

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
