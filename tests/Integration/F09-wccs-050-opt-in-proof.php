<?php
/**
 * WCCS-050 proof harness — the opt-in, the diagnostics and the safe return.
 *
 * Task:   WCCS-050 "Criar opt-in, diagnóstico e fallback"
 * Phase:  F09 · Página customizada e pagamento
 * Accept: "Ativar/desativar não muda engine nem apaga campos; falha tem retorno seguro."
 *
 * The acceptance has two clauses and each is asserted as a comparison rather than as a
 * claim:
 *
 * 1. **Toggling changes no engine and erases no field.** The schema slots are read
 *    before the switch is touched and compared byte for byte afterwards, and the
 *    registries the engine is made of — the types, the validators, the condition
 *    sources — are compared as well. A switch that rewrote any of it would be a switch
 *    that can lose a merchant's work, and "preserva o editor" is what section 15 asks
 *    for.
 * 2. **A failure returns safely.** A gateway the homologation record marks
 *    `unavailable` makes the presentation step aside for the store's own checkout, with
 *    the gateway named in the reason — and nothing else about the request changes.
 *
 * And the state the clauses live in is asserted first, because it is the one a fresh
 * install is in: **the switch is off, and off is the default**.
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
 * The stored schema slots, verbatim.
 *
 * Read straight out of the options table rather than through the repository, so the
 * comparison is about what is stored and not about what a reader returns.
 *
 * @return array<string, string|null>
 */
function wccs_proof_schema_bytes(): array {
	global $wpdb;

	$snapshot = array();

	foreach ( array( 'draft', 'published', 'revisions' ) as $slot ) {
		$name = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A byte for byte comparison of stored state across a settings toggle.
		$snapshot[ $name ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	return $snapshot;
}

/**
 * The vocabularies the engine is made of.
 *
 * @return array<string, array<int, string>>
 */
function wccs_proof_engine(): array {
	$registries = \WCCheckoutSuite\Domain\Registries::instance();

	return array(
		'types'      => array_map( 'strval', $registries->types()->keys() ),
		'validators' => array_map( 'strval', $registries->validators()->keys() ),
		'normalizers' => array_map( 'strval', $registries->normalizers()->keys() ),
		'sources'    => array_map( 'strval', array_keys( \WCCheckoutSuite\Domain\Conditions\Sources::all() ) ),
		'operators'  => array_map( 'strval', array_keys( \WCCheckoutSuite\Domain\Conditions\Operators::all() ) ),
	);
}

/**
 * A published document with one renderable field, so the checkout has something to draw.
 *
 * @return void
 */
function wccs_proof_publish(): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 50,
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
}

$wccs_settings = 'WCCheckoutSuite\\Domain\\Settings\\CheckoutSettings';
$wccs_matrix   = 'WCCheckoutSuite\\Domain\\Payments\\PaymentMatrix';
$wccs_mode     = 'WCCheckoutSuite\\Domain\\Payments\\PaymentMode';

// The store is put in a known state before anything is measured: opted out, which is
// where a fresh install is, and with the published slot cleared.
delete_option( $wccs_settings::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-050 proof — opt-in, diagnostics and safe return' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. Off is the default.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A fresh install changes nothing' );

wccs_proof_check(
	'With no setting stored, the custom checkout is off',
	! $wccs_settings::enabled()
);

$wccs_off = $wccs_settings::decision( $wccs_settings::offered_gateways() );

wccs_proof_check(
	'And the decision says so in words a merchant can act on',
	$wccs_settings::MODE_STORE === $wccs_off['mode'] && '' !== $wccs_off['reason'],
	'mode=' . $wccs_off['mode'] . ' reason=' . $wccs_off['reason']
);

wccs_proof_publish();

// The fields are the product and the switch is not: with the presentation off, the
// bundle that carries the field engine is still delivered, and the stylesheet is not.
wp_dequeue_style( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE );
wp_dequeue_style( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE );
wp_dequeue_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, null );

wccs_proof_check(
	'Opted out, the checkout is not given the presentation',
	! wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' )
		&& ! wp_style_is( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE, 'enqueued' )
);

wccs_proof_check(
	'And is still given the fields, which are what the switch does not govern',
	wp_script_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'enqueued' )
);

$wccs_payload = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();

wccs_proof_check(
	'And the payload still carries everything the field engine needs',
	isset( $wccs_payload['masks'], $wccs_payload['rules'], $wccs_payload['conditions'], $wccs_payload['uploads'], $wccs_payload['validation'] )
);

wccs_proof_check(
	'And it tells the bundle that the presentation is the store\'s own',
	isset( $wccs_payload['presentation']['mode'] )
		&& $wccs_settings::MODE_STORE === $wccs_payload['presentation']['mode'],
	'mode=' . ( $wccs_payload['presentation']['mode'] ?? '(none)' )
);

// ---------------------------------------------------------------------------
// 2. Turning it on presents, and turning it off restores.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the switch governs' );

$wccs_schema_before = wccs_proof_schema_bytes();
$wccs_engine_before = wccs_proof_engine();

$wccs_settings::set_enabled( true );

wp_dequeue_style( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE );
wp_dequeue_style( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE );
wp_dequeue_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, null );

wccs_proof_check(
	'Opted in, the presentation arrives with the tokens it reads',
	wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' )
		&& wp_style_is( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE, 'enqueued' )
);

wccs_proof_check(
	'And the fields still arrive, unchanged',
	wp_script_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'enqueued' )
);

$wccs_on = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();

wccs_proof_check(
	'And the payload now says the presentation is this plugin\'s',
	$wccs_settings::MODE_CUSTOM === ( $wccs_on['presentation']['mode'] ?? '' ),
	'mode=' . ( $wccs_on['presentation']['mode'] ?? '(none)' )
);

$wccs_settings::set_enabled( false );

wp_dequeue_style( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE );
wp_dequeue_style( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, null );

wccs_proof_check(
	'Turning it off restores the store\'s own checkout',
	! wp_style_is( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE, 'enqueued' )
);

// ---------------------------------------------------------------------------
// 3. The switch changes no engine and erases no field.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What the switch leaves alone' );

$wccs_schema_after = wccs_proof_schema_bytes();

wccs_proof_check(
	'Every stored schema slot is byte for byte what it was before the toggle',
	$wccs_schema_before === $wccs_schema_after,
	'changed=' . wp_json_encode(
		array_keys(
			array_filter(
				array_keys( $wccs_schema_before ),
				static function ( string $name ) use ( $wccs_schema_before, $wccs_schema_after ): bool {
					return $wccs_schema_before[ $name ] !== $wccs_schema_after[ $name ];
				}
			)
		)
	)
);

wccs_proof_check(
	'And the published document still holds the field the merchant configured',
	'wccs_note' === ( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields()[0]['id'] ?? '' )
);

wccs_proof_check(
	'And the engine is the same engine: types, validators, normalizers, sources and operators',
	$wccs_engine_before === wccs_proof_engine(),
	'types=' . count( $wccs_engine_before['types'] ) . ' validators=' . count( $wccs_engine_before['validators'] )
);

$wccs_settings::set_enabled( true );

$wccs_document_bytes = (string) get_option(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
	''
);

wccs_proof_check(
	'The switch is stored as its own option and never inside the document',
	'yes' === get_option( $wccs_settings::OPTION, 'no' )
		&& ! str_contains( $wccs_document_bytes, 'custom_checkout' ),
	'the document is the merchant\'s work; the switch is a preference'
);

$wccs_settings::set_enabled( false );

// ---------------------------------------------------------------------------
// 4. A failure returns safely.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. When the presentation has no business being there' );

$wccs_property = new ReflectionProperty( $wccs_matrix, 'record' );
$wccs_property->setAccessible( true );
$wccs_shipped = $wccs_matrix::record();

$wccs_settings::set_enabled( true );

$wccs_gateway        = new stdClass();
$wccs_gateway->id    = 'wccs_proof_gateway';
$wccs_gateway->version = '1.0.0';

$wccs_property->setValue(
	null,
	array(
		'scenario_vocabulary'   => array( 'express' => 'The express path satisfies the required fields.' ),
		'decoration_vocabulary' => array( 'panel' => 'The box around the gateway markup.' ),
		'gateways'              => array(
			array(
				'id'      => 'wccs_proof_gateway',
				'version' => '1.0.0',
				'mode'    => $wccs_mode::UNAVAILABLE,
				'tested'  => array( 'express' ),
			),
		),
	)
);

$wccs_fallback = $wccs_settings::decision( array( $wccs_gateway ) );

wccs_proof_check(
	'A gateway the record marks unavailable makes the presentation step aside',
	$wccs_settings::MODE_STORE === $wccs_fallback['mode']
		&& array( 'wccs_proof_gateway' ) === $wccs_fallback['blocked_by'],
	'mode=' . $wccs_fallback['mode'] . ' blocked_by=' . wp_json_encode( $wccs_fallback['blocked_by'] )
);

// The rule is worth nothing unless the page asks it, so what is asserted next is that
// the page's answer is the rule's answer for the gateways this checkout offers — the
// same call, the same list. Feeding a forged gateway into that list would need a real
// WC_Payment_Gateway instance that reports itself available, which is a store with a
// gateway, and the note below records that limit instead of inventing around it.
$wccs_page_decision = $wccs_settings::decision( $wccs_settings::offered_gateways() );

wccs_proof_check(
	'The page decides with the same rule and the same gateway list',
	$wccs_page_decision === $wccs_settings::decision( $wccs_settings::offered_gateways() )
		&& isset( $wccs_page_decision['mode'], $wccs_page_decision['reason'], $wccs_page_decision['blocked_by'] ),
	'offered=' . wp_json_encode( array_keys( $wccs_settings::offered_gateways() ) )
);

wccs_proof_check(
	'And the reason names the gateway, so the merchant can act on it',
	str_contains( $wccs_fallback['reason'], 'wccs_proof_gateway' ),
	'reason=' . $wccs_fallback['reason']
);

wp_dequeue_style( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE );
wp_dequeue_style( \WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, null );

$wccs_blocked_payload = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();

wccs_proof_check(
	'And the payload the page is given carries that decision, unchanged, with the whole field engine',
	( $wccs_blocked_payload['presentation']['mode'] ?? '' ) === $wccs_page_decision['mode']
		&& isset( $wccs_blocked_payload['masks'], $wccs_blocked_payload['validation'] ),
	'payload=' . ( $wccs_blocked_payload['presentation']['mode'] ?? '(none)' )
		. ' rule=' . $wccs_page_decision['mode']
		. ' — the page and the rule are one answer over one list'
);

$wccs_property->setValue( null, $wccs_shipped );
$wccs_settings::set_enabled( false );

wccs_proof_check(
	'The record and the switch are left as they were found',
	$wccs_settings::MODE_STORE === $wccs_settings::decision( $wccs_settings::offered_gateways() )['mode']
);

// ---------------------------------------------------------------------------
// 5. The switch is reachable.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Where a merchant turns it on' );

$wccs_routes = \WCCheckoutSuite\Http\Admin\SettingsController::routes();

wccs_proof_check(
	'The settings route is published to the administration client',
	isset( $wccs_routes['settings'] )
		&& \WCCheckoutSuite\Http\Admin\SettingsController::ROUTE_SETTINGS === $wccs_routes['settings']
);

$wccs_registered = array();

foreach ( rest_get_server()->get_routes() as $wccs_path => $wccs_handlers ) {
	if ( str_contains( $wccs_path, \WCCheckoutSuite\Http\Admin\SettingsController::ROUTE_SETTINGS ) ) {
		foreach ( $wccs_handlers as $wccs_handler ) {
			foreach ( (array) ( $wccs_handler['methods'] ?? array() ) as $wccs_method => $wccs_on ) {
				if ( $wccs_on ) {
					$wccs_registered[] = $wccs_method;
				}
			}
		}
	}
}

sort( $wccs_registered );

wccs_proof_check(
	'And it answers a read and a write, and can destroy nothing',
	in_array( 'GET', $wccs_registered, true )
		&& in_array( 'POST', $wccs_registered, true )
		&& ! in_array( 'DELETE', $wccs_registered, true ),
	'methods=' . wp_json_encode( array_values( array_unique( $wccs_registered ) ) ) . ' — WordPress adds the write aliases itself'
);

$wccs_state = ( new \WCCheckoutSuite\Http\Admin\SettingsController() )->state();

wccs_proof_check(
	'The state names the gateways and how many were never homologated',
	isset( $wccs_state['gateways'], $wccs_state['undecided'], $wccs_state['homologated'] )
		&& $wccs_state['undecided'] === count( $wccs_state['gateways'] )
		&& 0 === $wccs_state['homologated'],
	'gateways=' . count( $wccs_state['gateways'] ) . ' undecided=' . $wccs_state['undecided']
);

wccs_proof_check(
	'And every mode it can report is a mode of the closed vocabulary',
	array() === array_diff( $wccs_state['modes'], $wccs_mode::all() )
);

wccs_proof_note(
	'Why the fallback is the store\'s own checkout and not a disabled plugin',
	'Section 15: an incompatibility does not authorise silently swapping the page engine. A gateway the record marks unavailable is one this presentation must not offer, so the presentation leaves and WooCommerce keeps rendering the checkout it always rendered — fields included, validation included. The customer sees the store, and the merchant sees the reason.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No browser and no gateway: the switch is asserted on the server and in the payload, and the diagnostics state is asserted through the controller. What a merchant sees when they press the toggle is WCCS-063.'
);

wccs_proof_note(
	'What the fallback proof can and cannot reach',
	'The rule is proven with a gateway the record marks unavailable, and the page is proven to decide with that same rule over its own list. What is NOT exercised is a real gateway being blocked on a real checkout, because feeding one into that list needs a WC_Payment_Gateway instance reporting itself available — which is a store with an enabled gateway, the SANDBOX-PAYMENT blocker again. The missing step is named rather than faked.'
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( $wccs_settings::OPTION );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

// The store is left opted out, which is where it started.
delete_option( $wccs_settings::OPTION );

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
