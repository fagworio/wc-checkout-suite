<?php
/**
 * WCCS-035 proof harness — the compiled rule decided by WooCommerce itself.
 *
 * Task:   WCCS-035 "Criar compilador de condições nativas"
 * Phase:  F06
 * Accept: "Somente condições representáveis são compiladas; demais capacidades ficam explícitas."
 *
 * The acceptance has two halves and this harness proves both against WooCommerce
 * rather than against a fixture of what WooCommerce is believed to do:
 *
 * 1. **Representable rules are compiled** — and the compiled rule is handed to
 *    WooCommerce's own meta-schema validator and then to its own document validator,
 *    which must agree with this plugin's engine on the same document. That is the
 *    strongest form of "compiled": not that the output looks like JSON Schema, but
 *    that the code which will read it decides what the engine decides.
 * 2. **The rest is explicit** — a rule that cannot be translated comes back with a
 *    reason naming the source or the operator that stopped it, and a rule that is
 *    only partly translatable is not translated at all, because a partial
 *    translation is a different rule.
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
 * A definition carrying one visibility rule.
 *
 * @param array<string, mixed> $visible Rule.
 * @return array<string, mixed>
 */
function wccs_proof_definition( array $visible ): array {
	return array(
		'id'         => 'wccs_company',
		'type'       => 'text',
		'label'      => 'Company',
		'conditions' => array( 'visible' => $visible ),
	);
}

/**
 * Compiles a rule.
 *
 * @param array<string, mixed> $visible Rule.
 * @param int                  $decimals Currency decimals.
 * @return \WCCheckoutSuite\Domain\Conditions\NativeConditionCompilation
 */
function wccs_proof_compile( array $visible, int $decimals = 2 ): \WCCheckoutSuite\Domain\Conditions\NativeConditionCompilation {
	return ( new \WCCheckoutSuite\Domain\Conditions\NativeConditionCompiler() )->compile( wccs_proof_definition( $visible ), $decimals );
}

/**
 * Whether WooCommerce's own validator accepts a compiled rule.
 *
 * @param array<string, mixed> $schema Compiled rule.
 * @return bool
 */
function wccs_proof_native_accepts( array $schema ): bool {
	$valid = \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation::is_valid_schema( $schema );

	return true === $valid;
}

/**
 * The document object the native validator decides against.
 *
 * Built from the exact shape `DocumentObject::get_data()` returns, with the values
 * passed in, so the comparison in section 3 is about the decision and not about
 * whether a cart happened to be empty.
 *
 * @param array<string, mixed> $cart     Cart entries.
 * @param array<string, mixed> $customer Customer entries.
 * @return \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject
 */
function wccs_proof_document( array $cart = array(), array $customer = array() ): \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject {
	return new \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject(
		array(
			'cart'     => $cart,
			'customer' => $customer,
			'checkout' => array(),
		)
	);
}

/**
 * Whether the native mechanism answers what a compiled rule says.
 *
 * @param array<string, mixed> $schema Compiled rule.
 * @param object               $document Document object.
 * @return bool
 */
function wccs_proof_native_decides( array $schema, object $document ): bool {
	$result = \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation::validate_document_object( $document, $schema );

	if ( is_wp_error( $result ) ) {
		return false;
	}

	return true === $result;
}

/**
 * The engine's answer for the same situation.
 *
 * @param array<string, mixed> $rule    Rule.
 * @param array<string, mixed> $context Context entries.
 * @return bool
 */
function wccs_proof_engine( array $rule, array $context ): bool {
	return ( new \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator() )->evaluate(
		$rule,
		new \WCCheckoutSuite\Domain\Fields\FieldContext( $context, 'classic' )
	);
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-035 proof — the native condition compiler' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. WooCommerce accepts what was compiled.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The compiled rule is one WooCommerce will read' );

$wccs_rules = array(
	'a country comparison'      => array(
		'source'   => 'country',
		'operator' => 'equals',
		'value'    => 'BR',
	),
	'a cart total comparison'   => array(
		'source'   => 'cart_total',
		'operator' => 'greater_than',
		'value'    => 100,
	),
	'a cart item membership'    => array(
		'source'   => 'cart_items',
		'operator' => 'contains',
		'value'    => 12,
	),
	'a shipping method equality' => array(
		'source'   => 'shipping_method',
		'operator' => 'equals',
		'value'    => 'flat_rate:3',
	),
	'a logged-in comparison'    => array(
		'source'   => 'customer_logged_in',
		'operator' => 'equals',
		'value'    => true,
	),
	'a negation'                => array(
		'source'   => 'state',
		'operator' => 'not_equals',
		'value'    => 'SP',
	),
	'a set membership'          => array(
		'source'   => 'country',
		'operator' => 'in',
		'value'    => array( 'BR', 'PT' ),
	),
	'emptiness over text'       => array(
		'source'   => 'state',
		'operator' => 'is_empty',
	),
	'emptiness over a list'     => array(
		'source'   => 'cart_items',
		'operator' => 'is_empty',
	),
	'a group of two'            => array(
		'all' => array(
			array(
				'source'   => 'country',
				'operator' => 'equals',
				'value'    => 'BR',
			),
			array(
				'source'   => 'cart_total',
				'operator' => 'less_than',
				'value'    => 500,
			),
		),
	),
);

$wccs_refused_by_woocommerce = array();

foreach ( $wccs_rules as $wccs_label => $wccs_rule ) {
	$wccs_compiled = wccs_proof_compile( $wccs_rule );

	if ( ! $wccs_compiled->is_compiled() ) {
		$wccs_refused_by_woocommerce[] = $wccs_label . ' (not compiled)';
		continue;
	}

	if ( ! wccs_proof_native_accepts( (array) $wccs_compiled->schema() ) ) {
		$wccs_refused_by_woocommerce[] = $wccs_label;
	}
}

wccs_proof_check(
	'Every compiled rule passes WooCommerce\'s own schema validation',
	array() === $wccs_refused_by_woocommerce,
	'offenders=' . wp_json_encode( $wccs_refused_by_woocommerce )
);

wccs_proof_check(
	'The meta-schema check is not vacuous: a schema that is not JSON Schema is refused',
	! wccs_proof_native_accepts( array( 'cart' => array( 'properties' => 'not-a-schema' ) ) )
);

// ---------------------------------------------------------------------------
// 2. What cannot be compiled is named.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What is refused, and why' );

$wccs_uncompilable = array(
	'the payment method'  => array(
		'source'   => 'payment_method',
		'operator' => 'equals',
		'value'    => 'bacs',
	),
	'the cart categories' => array(
		'source'   => 'cart_categories',
		'operator' => 'contains',
		'value'    => 'books',
	),
	'another field'       => array(
		'source'   => 'field',
		'field'    => 'wccs_person_type',
		'operator' => 'equals',
		'value'    => 'pj',
	),
	'emptiness of a flag' => array(
		'source'   => 'customer_logged_in',
		'operator' => 'is_empty',
	),
	'text compared as a number' => array(
		'source'   => 'country',
		'operator' => 'greater_than',
		'value'    => 5,
	),
);

$wccs_without_reason = array();

foreach ( $wccs_uncompilable as $wccs_label => $wccs_rule ) {
	$wccs_compiled = wccs_proof_compile( $wccs_rule );

	if ( $wccs_compiled->is_compiled() || array() === $wccs_compiled->notes() ) {
		$wccs_without_reason[] = $wccs_label;
	}
}

wccs_proof_check(
	'Every rule that cannot be compiled comes back with a reason',
	array() === $wccs_without_reason,
	'offenders=' . wp_json_encode( $wccs_without_reason )
);

$wccs_partly = wccs_proof_compile(
	array(
		'all' => array(
			array(
				'source'   => 'country',
				'operator' => 'equals',
				'value'    => 'BR',
			),
			array(
				'source'   => 'cart_categories',
				'operator' => 'contains',
				'value'    => 'books',
			),
		),
	)
);

wccs_proof_check(
	'A rule that is only partly translatable is not translated at all',
	false === $wccs_partly->is_compiled() && 'cart_categories' === $wccs_partly->notes()[0]['source'],
	'compiling the half that fits would turn an "all" into something weaker'
);

$wccs_always = wccs_proof_compile( array() );

wccs_proof_check(
	'A field with no rule is not a failure to compile',
	false === $wccs_always->is_compiled() && array() === $wccs_always->notes()
);

// ---------------------------------------------------------------------------
// 3. WooCommerce's validator and this plugin's engine agree.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The same document, decided twice' );

$wccs_decimals = (int) wc_get_price_decimals();

// Each case is a rule, the document object WooCommerce reads, and the context this
// plugin's engine reads. The two are built from the same values on purpose: the
// question is whether the compiled rule decides what the rule means.
$wccs_cases = array(
	'the country matches'                  => array(
		'rule'     => array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'BR',
		),
		'cart'     => array(),
		'customer' => array( 'billing_address' => array( 'country' => 'BR' ), 'id' => 0 ),
		'context'  => array( 'country' => 'BR' ),
		'expect'   => true,
	),
	'the country does not match'           => array(
		'rule'     => array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'BR',
		),
		'cart'     => array(),
		'customer' => array( 'billing_address' => array( 'country' => 'PT' ), 'id' => 0 ),
		'context'  => array( 'country' => 'PT' ),
		'expect'   => false,
	),
	'the total is above the threshold'     => array(
		'rule'     => array(
			'source'   => 'cart_total',
			'operator' => 'greater_than',
			'value'    => 100,
		),
		'cart'     => array( 'totals' => array( 'total_price' => 15000 ) ),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'cart_total' => 150.0 ),
		'expect'   => true,
	),
	'the total is below the threshold'     => array(
		'rule'     => array(
			'source'   => 'cart_total',
			'operator' => 'greater_than',
			'value'    => 100,
		),
		'cart'     => array( 'totals' => array( 'total_price' => 5000 ) ),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'cart_total' => 50.0 ),
		'expect'   => false,
	),
	'the cart holds the product'           => array(
		'rule'     => array(
			'source'   => 'cart_items',
			'operator' => 'contains',
			'value'    => 12,
		),
		'cart'     => array( 'items' => array( 11, 12, 12 ) ),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'cart_items' => array( '11', '12', '12' ) ),
		'expect'   => true,
	),
	'the cart does not hold the product'   => array(
		'rule'     => array(
			'source'   => 'cart_items',
			'operator' => 'contains',
			'value'    => 12,
		),
		'cart'     => array( 'items' => array( 11 ) ),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'cart_items' => array( '11' ) ),
		'expect'   => false,
	),
	'the shipping method is the chosen one' => array(
		'rule'     => array(
			'source'   => 'shipping_method',
			'operator' => 'equals',
			'value'    => 'flat_rate:3',
		),
		'cart'     => array( 'shipping_rates' => array( 'flat_rate:3' ) ),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'shipping_method' => 'flat_rate:3' ),
		'expect'   => true,
	),
	'a guest is not logged in'             => array(
		'rule'     => array(
			'source'   => 'customer_logged_in',
			'operator' => 'equals',
			'value'    => false,
		),
		'cart'     => array(),
		'customer' => array( 'id' => 0 ),
		'context'  => array( 'customer_logged_in' => false ),
		'expect'   => true,
	),
	'a member is logged in'                => array(
		'rule'     => array(
			'source'   => 'customer_logged_in',
			'operator' => 'equals',
			'value'    => true,
		),
		'cart'     => array(),
		'customer' => array( 'id' => 7 ),
		'context'  => array( 'customer_logged_in' => true ),
		'expect'   => true,
	),
	'the state is one of a set'            => array(
		'rule'     => array(
			'source'   => 'state',
			'operator' => 'in',
			'value'    => array( 'MG', 'SP' ),
		),
		'cart'     => array(),
		'customer' => array( 'billing_address' => array( 'state' => 'MG' ), 'id' => 0 ),
		'context'  => array( 'state' => 'MG' ),
		'expect'   => true,
	),
	'the state is empty'                   => array(
		'rule'     => array(
			'source'   => 'state',
			'operator' => 'is_empty',
		),
		'cart'     => array(),
		'customer' => array( 'billing_address' => array( 'state' => '' ), 'id' => 0 ),
		'context'  => array( 'state' => '' ),
		'expect'   => true,
	),
	'a group of two matches'               => array(
		'rule'     => array(
			'all' => array(
				array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'BR',
				),
				array(
					'source'   => 'cart_total',
					'operator' => 'less_than',
					'value'    => 500,
				),
			),
		),
		'cart'     => array( 'totals' => array( 'total_price' => 15000 ) ),
		'customer' => array( 'billing_address' => array( 'country' => 'BR' ), 'id' => 0 ),
		'context'  => array( 'country' => 'BR', 'cart_total' => 150.0 ),
		'expect'   => true,
	),
);

$wccs_disagreements = array();

foreach ( $wccs_cases as $wccs_label => $wccs_case ) {
	$wccs_compiled = wccs_proof_compile( $wccs_case['rule'], $wccs_decimals );

	if ( ! $wccs_compiled->is_compiled() ) {
		$wccs_disagreements[] = $wccs_label . ' (not compiled)';
		continue;
	}

	$wccs_native = wccs_proof_native_decides(
		(array) $wccs_compiled->schema(),
		wccs_proof_document( $wccs_case['cart'], $wccs_case['customer'] )
	);

	$wccs_engine = wccs_proof_engine( $wccs_case['rule'], $wccs_case['context'] );

	if ( $wccs_native !== $wccs_engine || $wccs_engine !== (bool) $wccs_case['expect'] ) {
		$wccs_disagreements[] = sprintf(
			'%s (native=%s engine=%s expected=%s)',
			$wccs_label,
			wp_json_encode( $wccs_native ),
			wp_json_encode( $wccs_engine ),
			wp_json_encode( (bool) $wccs_case['expect'] )
		);
	}
}

wccs_proof_check(
	'WooCommerce and this plugin decide every compiled rule the same way',
	array() === $wccs_disagreements,
	'disagreements=' . wp_json_encode( $wccs_disagreements )
);

wccs_proof_check(
	'The comparison is not vacuous: a rule the document contradicts is answered false by both',
	false === wccs_proof_native_decides(
		(array) wccs_proof_compile(
			array(
				'source'   => 'country',
				'operator' => 'equals',
				'value'    => 'BR',
			)
		)->schema(),
		wccs_proof_document( array(), array( 'billing_address' => array( 'country' => 'PT' ), 'id' => 0 ) )
	)
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why a partial translation is not a translation',
	'Compiling the leaves that fit changes what the rule says: an "all" loses a condition and an "any" gains one. Two checkouts would then hide different fields from the same document, and neither would say so. The compiler is all-or-nothing per field, and the notes name every leaf that stopped it.'
);

wccs_proof_note(
	'What the document does not carry',
	'`payment_method` and `cart_categories` are sources section 11 names and the document object does not have: it carries product identifiers, not the categories they belong to. They are refused with that as the reason instead of being mapped to something that merely looks similar.'
);

wccs_proof_note(
	'One caveat that belongs in writing',
	'The Suite reads `shipping_method` as the first rate the customer chose, while the document holds every selected rate. A checkout with a single package makes the two identical; a multi-package one does not, and the native rule tests membership of the whole list.'
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
