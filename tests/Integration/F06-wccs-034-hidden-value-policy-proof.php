<?php
/**
 * WCCS-034 proof harness — the hidden-value policy in a real submission.
 *
 * Task:   WCCS-034 "Implementar discard/preserve"
 * Phase:  F06
 * Accept: "Valor residual oculto é descartado por padrão; required segue estado no servidor."
 *
 * What this harness proves is the pair of statements in that acceptance, through
 * the real submission pipeline and with the engine as the default:
 *
 * 1. Discard is the default and it is complete: a field hidden by a rule stores
 *    nothing, raises no required error, and cannot refuse the order.
 * 2. Requiredness follows what the *server* computed, so a browser that hides a
 *    field — or a request that claims a country it is not in — cannot turn a
 *    required field off.
 * 3. Preserve is the explicit alternative: it keeps a value the store accepts, and
 *    it does not keep one the store would refuse, because there is no field on the
 *    form to repair it from.
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
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
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
 * A rule that shows the field only for a person type.
 *
 * @param string $person_type Expected value of the person type field.
 * @return array<string, mixed>
 */
function wccs_proof_only_for( string $person_type ): array {
	return array(
		'visible' => array(
			'source'   => 'field',
			'field'    => 'wccs_person_type',
			'operator' => 'equals',
			'value'    => $person_type,
		),
	);
}

/**
 * A rule that depends on the customer's country.
 *
 * @return array<string, mixed>
 */
function wccs_proof_only_in_brazil(): array {
	return array(
		'visible' => array(
			'source'   => 'country',
			'operator' => 'equals',
			'value'    => 'BR',
		),
	);
}

/**
 * Runs a submission through the adapter half.
 *
 * @param array<string, mixed>             $posted      Posted values.
 * @param array<int, array<string, mixed>> $definitions Definitions.
 * @return array{values: array<string, mixed>, results: array<string, \WCCheckoutSuite\Domain\Validation\ProcessedValue>}
 */
function wccs_proof_submit( array $posted, array $definitions ): array {
	$submission = new \WCCheckoutSuite\Checkout\Classic\ClassicSubmission(
		\WCCheckoutSuite\Domain\Registries::instance()->value_processor()
	);

	return $submission->normalize( $posted, $definitions );
}

/**
 * Error codes reported for one field.
 *
 * @param array{values: array<string, mixed>, results: array<string, \WCCheckoutSuite\Domain\Validation\ProcessedValue>} $outcome Outcome.
 * @param string                                                                                                           $id      Field id.
 * @return array<int, string>
 */
function wccs_proof_codes( array $outcome, string $id ): array {
	$processed = $outcome['results'][ $id ] ?? null;

	return null === $processed ? array() : $processed->result()->error_codes();
}

/**
 * Writes a document straight into the published slot.
 *
 * The bootstrap payload is built from the published document, so this is how a
 * harness asks the real question about what a page receives.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 5,
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

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-034 proof — the hidden-value policy' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// ---------------------------------------------------------------------------
// 1. Discard is the default.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Discard, because nothing said otherwise' );

wccs_proof_check(
	'The vocabulary default is discard',
	'discard' === \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::DEFAULT_HIDDEN_VALUE_POLICY
);

wccs_proof_check(
	'Both policies are offered, and discard comes first',
	array( 'discard', 'preserve' ) === \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::hidden_value_policy_values(),
	'values=' . wp_json_encode( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::hidden_value_policy_values() )
);

$wccs_defaulted = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( wccs_proof_field( 'defaulted' ) )->to_array();

wccs_proof_check(
	'A definition that never mentions the policy is a discard one',
	'discard' === $wccs_defaulted['hidden_value_policy'],
	'stored=' . wp_json_encode( $wccs_defaulted['hidden_value_policy'] )
);

$wccs_definitions = array(
	wccs_proof_field( 'person_type' ),
	wccs_proof_field(
		'company',
		wccs_proof_only_for( 'pj' ),
		array(
			'required' => true,
		)
	),
);

$wccs_hidden = wccs_proof_submit(
	array(
		'wccs_person_type' => 'pf',
		'wccs_company'     => 'ACME',
	),
	$wccs_definitions
);

wccs_proof_check(
	'A field a rule hides stores nothing',
	true === ( $wccs_hidden['results']['wccs_company'] ?? null )?->is_discarded()
		&& '' === $wccs_hidden['values']['wccs_company'],
	'carried=' . wp_json_encode( $wccs_hidden['values']['wccs_company'] )
);

wccs_proof_check(
	'And raises no required error, even though it is a required field',
	array() === wccs_proof_codes( $wccs_hidden, 'wccs_company' ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_hidden, 'wccs_company' ) )
);

wccs_proof_check(
	'And writes nothing to the order',
	false === ( $wccs_hidden['results']['wccs_company'] ?? null )?->is_storable()
);

// ---------------------------------------------------------------------------
// 2. Requiredness follows the server's own answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Required follows the state the server computed' );

$wccs_visible = wccs_proof_submit(
	array(
		'wccs_person_type' => 'pj',
		'wccs_company'     => '',
	),
	$wccs_definitions
);

wccs_proof_check(
	'A required field the server considers visible refuses an empty submission',
	in_array( 'required', wccs_proof_codes( $wccs_visible, 'wccs_company' ), true ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_visible, 'wccs_company' ) )
);

// The forge: the browser hid the field and left it empty, and the request carries a
// country of its own. Neither is context, so neither can decide the question.
$wccs_forged = wccs_proof_submit(
	array(
		'wccs_person_type' => 'pj',
		'wccs_company'     => '',
		'country'          => 'PT',
		'customer_logged_in' => false,
	),
	$wccs_definitions
);

wccs_proof_check(
	'A forged request cannot make a visible required field optional',
	in_array( 'required', wccs_proof_codes( $wccs_forged, 'wccs_company' ), true ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_forged, 'wccs_company' ) )
);

$wccs_country_field = wccs_proof_field(
	'brazil_only',
	wccs_proof_only_in_brazil(),
	array(
		'required' => true,
	)
);

$wccs_customer   = WC()->customer;
$wccs_country    = (string) $wccs_customer->get_billing_country();
$wccs_state      = (string) $wccs_customer->get_billing_state();

$wccs_customer->set_billing_country( 'PT' );
$wccs_customer->set_billing_state( 'LI' );

$wccs_abroad = wccs_proof_submit(
	array(
		'wccs_brazil_only' => '',
		'country'          => 'BR',
	),
	array( $wccs_country_field )
);

wccs_proof_check(
	'A posted country is not the country the rule reads',
	false === ( $wccs_abroad['results']['wccs_brazil_only'] ?? null )?->is_visible()
		&& array() === wccs_proof_codes( $wccs_abroad, 'wccs_brazil_only' ),
	'the rule reads WooCommerce\'s customer, and it says PT'
);

$wccs_customer->set_billing_country( 'BR' );
$wccs_customer->set_billing_state( 'MG' );

$wccs_home = wccs_proof_submit(
	array(
		'wccs_brazil_only' => '',
	),
	array( $wccs_country_field )
);

wccs_proof_check(
	'And the same field is required once the server agrees it is visible',
	in_array( 'required', wccs_proof_codes( $wccs_home, 'wccs_brazil_only' ), true ),
	'codes=' . json_encode( wccs_proof_codes( $wccs_home, 'wccs_brazil_only' ) )
);

$wccs_customer->set_billing_country( $wccs_country );
$wccs_customer->set_billing_state( $wccs_state );

// ---------------------------------------------------------------------------
// 3. Preserve, only when it is asked for.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Preserve, which nobody gets by accident' );

$wccs_preserved = wccs_proof_submit(
	array(
		'wccs_person_type' => 'pf',
		'wccs_company'     => 'ACME',
	),
	array(
		wccs_proof_field( 'person_type' ),
		wccs_proof_field(
			'company',
			wccs_proof_only_for( 'pj' ),
			array(
				'hidden_value_policy' => 'preserve',
			)
		),
	)
);

wccs_proof_check(
	'A preserved value survives the field being hidden',
	false === ( $wccs_preserved['results']['wccs_company'] ?? null )?->is_discarded()
		&& 'ACME' === $wccs_preserved['values']['wccs_company'],
	'carried=' . wp_json_encode( $wccs_preserved['values']['wccs_company'] )
);

wccs_proof_check(
	'And the field still reports itself as hidden',
	false === ( $wccs_preserved['results']['wccs_company'] ?? null )?->is_visible()
);

$wccs_refused = wccs_proof_submit(
	array(
		'wccs_person_type' => 'pf',
		'wccs_company'     => 'ACME',
	),
	array(
		wccs_proof_field( 'person_type' ),
		wccs_proof_field(
			'company',
			wccs_proof_only_for( 'pj' ),
			array(
				'hidden_value_policy' => 'preserve',
				'settings'            => array( 'maxLength' => 3 ),
			)
		),
	)
);

wccs_proof_check(
	'A value the store would refuse is not preserved',
	true === ( $wccs_refused['results']['wccs_company'] ?? null )?->is_discarded()
		&& '' === $wccs_refused['values']['wccs_company'],
	'carried=' . wp_json_encode( $wccs_refused['values']['wccs_company'] )
);

wccs_proof_check(
	'And it does not block the order with an error nobody can repair',
	array() === wccs_proof_codes( $wccs_refused, 'wccs_company' ),
	'codes=' . wp_json_encode( wccs_proof_codes( $wccs_refused, 'wccs_company' ) )
);

// ---------------------------------------------------------------------------
// 4. What the page is allowed to decide.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What the browser is told' );

$wccs_document = array(
	wccs_proof_field( 'person_type' ),
	wccs_proof_field( 'brazil_only', wccs_proof_only_in_brazil(), array( 'required' => true ) ),
	wccs_proof_field( 'company', wccs_proof_only_for( 'pj' ) ),
	wccs_proof_field(
		'premium',
		array(
			'visible' => array(
				'source'   => 'cart_total',
				'operator' => 'greater_than',
				'value'    => 100,
			),
		)
	),
);

wccs_proof_publish( $wccs_document );

$wccs_bootstrap = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();
$wccs_published = $wccs_bootstrap['conditions'] ?? array();

wccs_proof_check(
	'A rule the page can answer is published to it, with its policy',
	isset( $wccs_published['wccs_company'], $wccs_published['wccs_brazil_only'] )
		&& 'discard' === ( $wccs_published['wccs_company']['policy'] ?? null ),
	'published=' . wp_json_encode( array_keys( $wccs_published ) )
);

wccs_proof_check(
	'A rule the page cannot answer stays on the server',
	! isset( $wccs_published['wccs_premium'] ),
	'that rule reads the cart total, which only the server holds'
);

wccs_proof_check(
	'A field with no rule is not published at all',
	! isset( $wccs_published['wccs_person_type'] )
);

wccs_proof_publish( array() );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why the engine had to become the default',
	'The browser half evaluates the same tree. If the server kept answering "always", a field the browser hid would be refused by the server as a missing required one — an order blocked by a field nobody can see. The two halves have to agree on the same document, and the document does not name an engine.'
);

wccs_proof_note(
	'What preserve costs',
	'Section 11 attaches a privacy policy to it, and the reason is in the name: the value survives a question the customer believed they had answered. A value the store would refuse is dropped rather than kept, because a field that is not on the form is a field the customer cannot repair.'
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
