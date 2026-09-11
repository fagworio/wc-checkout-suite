<?php
/**
 * WCCS-033 proof harness — the condition engine in the running plugin.
 *
 * Task:   WCCS-033 "Implementar evaluators PHP/JS"
 * Phase:  F06
 * Accept: "Paridade em vazio, zero, false, arrays, contexto e negações."
 *
 * Parity itself is proven by the shared fixtures, read by the PHP suite and by the
 * JavaScript suite from `resources/fixtures/conditions.json`: one authored
 * expectation per case, two implementations held to it. What a fixture cannot show
 * is whether the engine is reachable from a real store, fed with a context the
 * server trusts, and given the values the submission actually carried. That is what
 * this harness proves:
 *
 * 1. The engine is registered, and registering it did not change what a store does
 *    by default.
 * 2. Every operator and every source the catalogue publishes is *understood* —
 *    each one answers about a rule, instead of falling back to "visible".
 * 3. The trusted context comes from WooCommerce's own objects, and every source the
 *    vocabulary has is answered for.
 * 4. A document that names the engine decides visibility through the real pipeline.
 * 5. The values a rule may read are the ones the store accepted, not the ones that
 *    were posted.
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
 * Builds a field definition.
 *
 * @param string               $id         Identifier.
 * @param array<string, mixed> $conditions Conditions.
 * @param array<string, mixed> $extra      Extra definition keys.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $conditions = array(), array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
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
			'conditions'     => $conditions,
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array(
				'admin_order' => true,
			),
		),
		$extra
	);
}

/**
 * The engine.
 *
 * @return \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator
 */
function wccs_proof_engine(): \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator {
	return new \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator();
}

/**
 * Evaluates a rule through the engine.
 *
 * @param array<string, mixed> $rules   Rule.
 * @param array<string, mixed> $entries Context entries.
 * @return bool
 */
function wccs_proof_evaluate( array $rules, array $entries ): bool {
	return wccs_proof_engine()->evaluate( $rules, new \WCCheckoutSuite\Domain\Fields\FieldContext( $entries, 'classic' ) );
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-033 proof — the condition engine' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// ---------------------------------------------------------------------------
// 1. Registered, and still not the default.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The engine the registry holds' );

$wccs_registry = \WCCheckoutSuite\Domain\Registries::instance()->conditions();
$wccs_engine   = $wccs_registry->evaluator( \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator::KEY );

wccs_proof_check(
	'The engine is registered under the key a document names',
	$wccs_engine instanceof \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator,
	'key=' . \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator::KEY
);

wccs_proof_check(
	'It declares the contract version the registry accepts',
	$wccs_engine instanceof \WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorInterface
		&& '1.0' === $wccs_engine->contract_version()
);

// WCCS-034 made the engine the default, which is what this line asserts today. The
// assertion is deliberately about the current state and not about the state this
// task left behind: a proof that pins a superseded default is a proof that fails
// for the right reason and gets edited for the wrong one.
wccs_proof_check(
	'The engine is what answers by default, and the permissive one is still reachable',
	'rules' === $wccs_registry->active()->key()
		&& 'always' === $wccs_registry->evaluator( 'always' )?->key(),
	'active=' . $wccs_registry->active()->key()
);

$wccs_permissive = $wccs_registry->evaluator( 'always' );

wccs_proof_check(
	'The permissive evaluator still hides nothing, for a document that pins it',
	$wccs_permissive instanceof \WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorInterface
		&& true === $wccs_permissive->evaluate(
		array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'BR',
		),
		new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'country' => 'PT' ), 'classic' )
	)
);

// ---------------------------------------------------------------------------
// 2. Every published operator and source is understood.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Nothing the catalogue publishes falls back to "visible"' );

// Each entry is a rule that must NOT match. An operator the engine does not know
// answers "matches", so a false answer is the proof that it was understood — and a
// rule that matches by accident is the failure this section exists to catch.
$wccs_unmatched = array(
	'equals'       => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'BR',
		),
		'context' => array( 'country' => 'PT' ),
	),
	'not_equals'   => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'not_equals',
			'value'    => 'BR',
		),
		'context' => array( 'country' => 'BR' ),
	),
	'contains'     => array(
		'rule'    => array(
			'source'   => 'cart_items',
			'operator' => 'contains',
			'value'    => '999999',
		),
		'context' => array( 'cart_items' => array( '1' ) ),
	),
	'not_contains' => array(
		'rule'    => array(
			'source'   => 'cart_items',
			'operator' => 'not_contains',
			'value'    => '1',
		),
		'context' => array( 'cart_items' => array( '1' ) ),
	),
	'greater_than' => array(
		'rule'    => array(
			'source'   => 'cart_total',
			'operator' => 'greater_than',
			'value'    => 100,
		),
		'context' => array( 'cart_total' => 50 ),
	),
	'less_than'    => array(
		'rule'    => array(
			'source'   => 'cart_total',
			'operator' => 'less_than',
			'value'    => 100,
		),
		'context' => array( 'cart_total' => 150 ),
	),
	'is_empty'     => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'is_empty',
		),
		'context' => array( 'country' => 'BR' ),
	),
	'is_not_empty' => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'is_not_empty',
		),
		'context' => array( 'country' => '' ),
	),
	'in'           => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'in',
			'value'    => array( 'BR' ),
		),
		'context' => array( 'country' => 'PT' ),
	),
	'not_in'       => array(
		'rule'    => array(
			'source'   => 'country',
			'operator' => 'not_in',
			'value'    => array( 'BR' ),
		),
		'context' => array( 'country' => 'BR' ),
	),
);

$wccs_misunderstood = array();

foreach ( \WCCheckoutSuite\Domain\Conditions\Operators::all() as $wccs_key => $wccs_operator ) {
	if ( ! isset( $wccs_unmatched[ $wccs_key ] ) ) {
		$wccs_misunderstood[] = $wccs_key . ' (no case)';
		continue;
	}

	if ( true === wccs_proof_evaluate( $wccs_unmatched[ $wccs_key ]['rule'], $wccs_unmatched[ $wccs_key ]['context'] ) ) {
		$wccs_misunderstood[] = $wccs_key;
	}
}

wccs_proof_check(
	'Every published operator decides a rule that must not match',
	array() === $wccs_misunderstood,
	'operators that answered "visible" to a rule that does not match: ' . wp_json_encode( $wccs_misunderstood )
);

$wccs_unread_sources = array();

foreach ( \WCCheckoutSuite\Domain\Conditions\Sources::all() as $wccs_key => $wccs_source ) {
	// A source holding a value is not empty, and an unreadable source answers
	// "visible" to `is_empty` as well — so a false answer proves it was read.
	$wccs_entries = $wccs_source->is_reference()
		? array( 'fields' => array( 'wccs_a' => 'a value' ) )
		: array( $wccs_key => 'a value' );

	$wccs_rule = array(
		'source'   => $wccs_key,
		'operator' => 'is_empty',
	);

	if ( $wccs_source->is_reference() ) {
		$wccs_rule['field'] = 'wccs_a';
	}

	if ( true === wccs_proof_evaluate( $wccs_rule, $wccs_entries ) ) {
		$wccs_unread_sources[] = $wccs_key;
	}
}

wccs_proof_check(
	'Every published source is read instead of answered by default',
	array() === $wccs_unread_sources,
	'sources answered "visible" while holding a value: ' . wp_json_encode( $wccs_unread_sources )
);

// ---------------------------------------------------------------------------
// 3. The trusted context.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The context the server builds' );

$wccs_context = new \WCCheckoutSuite\Checkout\Classic\ClassicConditionContext();

wccs_proof_check(
	'Every source the vocabulary has is answered for, except the one only a submission can answer',
	array() === \WCCheckoutSuite\Checkout\Classic\ClassicConditionContext::missing(),
	'missing=' . wp_json_encode( \WCCheckoutSuite\Checkout\Classic\ClassicConditionContext::missing() )
);

wp_set_current_user( 0 );
$wccs_logged_out = ( new \WCCheckoutSuite\Checkout\Classic\ClassicConditionContext() )->context();
wp_set_current_user( 1 );
$wccs_logged_in = ( new \WCCheckoutSuite\Checkout\Classic\ClassicConditionContext() )->context();

wccs_proof_check(
	'Whether the customer is logged in comes from the request, not from the form',
	false === $wccs_logged_out->get( 'customer_logged_in' ) && true === $wccs_logged_in->get( 'customer_logged_in' ),
	'guest=' . wp_json_encode( $wccs_logged_out->get( 'customer_logged_in' ) )
		. ' member=' . wp_json_encode( $wccs_logged_in->get( 'customer_logged_in' ) )
);

$wccs_customer = WC()->customer;
$wccs_country  = (string) $wccs_customer->get_billing_country();
$wccs_state    = (string) $wccs_customer->get_billing_state();

$wccs_customer->set_billing_country( 'PT' );
$wccs_customer->set_billing_state( 'LI' );

$wccs_portugal = $wccs_context->context();

wccs_proof_check(
	'The country comes from the customer the request is for',
	'PT' === $wccs_portugal->get( 'country' ) && 'LI' === $wccs_portugal->get( 'state' ),
	'country=' . $wccs_portugal->get( 'country' ) . ' state=' . $wccs_portugal->get( 'state' )
);

wccs_proof_check(
	'And a rule about the country decides with it',
	true === wccs_proof_engine()->evaluate(
		array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'PT',
		),
		$wccs_portugal
	)
);

$wccs_customer->set_billing_country( $wccs_country );
$wccs_customer->set_billing_state( $wccs_state );

$wccs_empty_cart = $wccs_context->context();

wccs_proof_check(
	'An empty cart is an empty list and a total of zero, not an absent total',
	array() === $wccs_empty_cart->get( 'cart_items' )
		&& array() === $wccs_empty_cart->get( 'cart_categories' )
		&& 0.0 === $wccs_empty_cart->get( 'cart_total' ),
	'items=' . wp_json_encode( $wccs_empty_cart->get( 'cart_items' ) )
		. ' total=' . wp_json_encode( $wccs_empty_cart->get( 'cart_total' ) )
);

wccs_proof_check(
	'The entries the browser owns are read from the session, not from the request',
	'string' === gettype( $wccs_empty_cart->get( 'shipping_method' ) )
		&& 'string' === gettype( $wccs_empty_cart->get( 'payment_method' ) ),
	'shipping=' . wp_json_encode( $wccs_empty_cart->get( 'shipping_method' ) )
		. ' payment=' . wp_json_encode( $wccs_empty_cart->get( 'payment_method' ) )
);

// ---------------------------------------------------------------------------
// 4. A document that names the engine decides through the pipeline.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The engine reached through the value pipeline' );

$wccs_processor = \WCCheckoutSuite\Domain\Registries::instance()->value_processor();

$wccs_hidden_rule = array(
	'evaluator' => \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator::KEY,
	'visible'   => array(
		'source'   => 'country',
		'operator' => 'equals',
		'value'    => 'BR',
	),
);

$wccs_hidden = $wccs_processor->process(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
		wccs_proof_field( 'wccs_document', $wccs_hidden_rule )
	),
	'123',
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'country' => 'PT' ), 'classic' )
);

wccs_proof_check(
	'A document naming the engine hides the field when the rule does not match',
	false === $wccs_hidden->is_visible() && true === $wccs_hidden->is_discarded(),
	'visible=' . wp_json_encode( $wccs_hidden->is_visible() ) . ' discarded=' . wp_json_encode( $wccs_hidden->is_discarded() )
);

$wccs_shown = $wccs_processor->process(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
		wccs_proof_field( 'wccs_document', $wccs_hidden_rule )
	),
	'123',
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'country' => 'BR' ), 'classic' )
);

wccs_proof_check(
	'And shows it, with the value kept, when the rule matches',
	true === $wccs_shown->is_visible() && '123' === $wccs_shown->value()
);

$wccs_default = $wccs_processor->process(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
		wccs_proof_field(
			'wccs_document',
			array(
				'visible' => array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'BR',
				),
			)
		)
	),
	'123',
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'country' => 'PT' ), 'classic' )
);

wccs_proof_check(
	'A document naming no engine is answered by whichever one is active',
	false === $wccs_default->is_visible(),
	'active=' . \WCCheckoutSuite\Domain\Registries::instance()->conditions()->active()->key()
);

$wccs_pinned = $wccs_processor->process(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
		wccs_proof_field(
			'wccs_document',
			array(
				'evaluator' => 'always',
				'visible'   => array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'BR',
				),
			)
		)
	),
	'123',
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'country' => 'PT' ), 'classic' )
);

wccs_proof_check(
	'And a document that pins an engine is answered by that engine',
	true === $wccs_pinned->is_visible(),
	'a store that pinned the permissive engine keeps working with it'
);

// ---------------------------------------------------------------------------
// 5. What a rule may read is what was accepted.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The values a rule reads' );

$wccs_submission = new \WCCheckoutSuite\Checkout\Classic\ClassicSubmission( $wccs_processor );

$wccs_definitions = array(
	wccs_proof_field(
		'wccs_person_type',
		array(),
		array(
			'validators' => array( array( 'key' => 'br.cpf' ) ),
			'normalizer' => 'br.cpf',
		)
	),
	wccs_proof_field(
		'wccs_company',
		array(
			'evaluator' => \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator::KEY,
			'visible'   => array(
				'source'   => 'field',
				'field'    => 'wccs_person_type',
				'operator' => 'equals',
				'value'    => '52998224725',
			),
		)
	),
);

$wccs_accepted = $wccs_submission->normalize(
	array(
		'wccs_person_type' => '529.982.247-25',
		'wccs_company'     => 'ACME',
	),
	$wccs_definitions
);

wccs_proof_check(
	'A rule reading another field sees the value the store accepted',
	true === ( $wccs_accepted['results']['wccs_company'] ?? null )?->is_visible(),
	'value seen=' . wp_json_encode( $wccs_accepted['results']['wccs_person_type']->value() )
);

$wccs_rejected = $wccs_submission->normalize(
	array(
		'wccs_person_type' => '111.111.111-11',
		'wccs_company'     => 'ACME',
	),
	$wccs_definitions
);

wccs_proof_check(
	'And does not see a rejected value, which is the value an attacker would forge',
	false === ( $wccs_rejected['results']['wccs_company'] ?? null )?->is_visible(),
	'codes=' . wp_json_encode( $wccs_rejected['results']['wccs_person_type']->result()->error_codes() )
);

wccs_proof_check(
	'And the field hidden by that rule stores nothing',
	true === ( $wccs_rejected['results']['wccs_company'] ?? null )?->is_discarded()
		&& '' === $wccs_rejected['values']['wccs_company']
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Where the parity is proven',
	'Not here. The shared fixtures in resources/fixtures/conditions.json are read by the PHP suite and by the JavaScript suite, and both are held to the same authored expectations. This harness proves the wiring: that the engine the fixtures describe is the one a real store reaches.'
);

wccs_proof_note(
	'When the default changed',
	'WCCS-033 registered the engine under its own key and left it out of the default, so the storefront could not change behaviour as a side effect of an engine existing. WCCS-034 made it the default, because the browser half evaluates the same tree and the two have to agree on the same document: a field the browser hides must not be refused by the server as a missing required one.'
);

wccs_proof_note(
	'What the client half is for',
	'The JavaScript engine is the same semantics for the sources the page owns. Wiring it into the live checkout is WCCS-034, where the hidden-value policy and the required-state question are decided; shipping it earlier would decide them here by accident.'
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
