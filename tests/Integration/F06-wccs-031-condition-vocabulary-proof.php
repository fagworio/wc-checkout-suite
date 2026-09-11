<?php
/**
 * WCCS-031 proof harness — the condition vocabulary in the running plugin.
 *
 * Task:   WCCS-031 "Criar AST e tipos de operadores"
 * Phase:  F06
 * Accept: "AND/OR e operadores tipados; ciclos e referências inválidas rejeitados."
 *
 * The vocabulary and the tree are covered by the unit suite. What this harness
 * proves is where they are enforced: the per-rule checks on every draft write, and
 * the two questions only the whole document can answer — a rule naming a field that
 * does not exist, and two fields that depend on each other — where the document is
 * validated and reported.
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
 * @param string               $type       Field type.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $conditions = array(), string $type = 'text' ): array {
	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => $type,
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
	);
}

/**
 * Validates a set of fields the way the document is validated.
 *
 * @param array<int, mixed> $fields Fields.
 * @return \WCCheckoutSuite\Domain\Fields\ValidationResult
 */
function wccs_proof_validate( array $fields ): \WCCheckoutSuite\Domain\Fields\ValidationResult {
	$registries = \WCCheckoutSuite\Domain\Registries::instance();

	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		$registries->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	return $repository->validate(
		\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
			array(
				'revision'       => 1,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		)
	);
}

/**
 * Validates one definition the way a draft write does.
 *
 * @param array<string, mixed> $field Field.
 * @return \WCCheckoutSuite\Domain\Fields\ValidationResult
 */
function wccs_proof_validate_field( array $field ): \WCCheckoutSuite\Domain\Fields\ValidationResult {
	return \WCCheckoutSuite\Domain\Registries::instance()->definition_validator()->validate_array( $field );
}

/**
 * A condition reading another field.
 *
 * @param string $field    Field identifier.
 * @param string $operator Operator key.
 * @param mixed  $value    Comparison value.
 * @return array<string, mixed>
 */
function wccs_proof_reads( string $field, string $operator = 'equals', mixed $value = 'pj' ): array {
	return array(
		'source'   => 'field',
		'field'    => $field,
		'operator' => $operator,
		'value'    => $value,
	);
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-031 proof — the condition vocabulary' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

// ---------------------------------------------------------------------------
// 1. A rule is judged on every write.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A rule that does not mean anything is refused when it is written' );

$wccs_bad_operator = wccs_proof_validate_field(
	wccs_proof_field( 'wccs_a', array( 'visible' => wccs_proof_reads( 'wccs_b', 'is_approximately' ) ) )
);

wccs_proof_check(
	'An operator that does not exist is refused',
	in_array( 'unknown_condition_operator', $wccs_bad_operator->error_codes(), true ),
	'codes=' . wp_json_encode( $wccs_bad_operator->error_codes() )
);

$wccs_bad_type = wccs_proof_validate_field(
	wccs_proof_field(
		'wccs_a',
		array(
			'visible' => array(
				'source'   => 'country',
				'operator' => 'greater_than',
				'value'    => 'BR',
			),
		)
	)
);

wccs_proof_check(
	'A comparison the operator cannot make is refused',
	in_array( 'condition_value_incompatible', $wccs_bad_type->error_codes(), true )
);

$wccs_bad_shape = wccs_proof_validate_field(
	wccs_proof_field( 'wccs_a', array( 'visible' => array( 'all' => array() ) ) )
);

wccs_proof_check(
	'A group with nothing in it is refused',
	in_array( 'empty_condition_group', $wccs_bad_shape->error_codes(), true )
);

$wccs_self = wccs_proof_validate_field(
	wccs_proof_field( 'wccs_a', array( 'visible' => wccs_proof_reads( 'wccs_a' ) ) )
);

wccs_proof_check(
	'A field that depends on itself is refused at the rule',
	in_array( 'condition_self_reference', $wccs_self->error_codes(), true )
);

// ---------------------------------------------------------------------------
// 2. The two questions only the document can answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What only the whole document can see' );

$wccs_missing = wccs_proof_validate(
	array( wccs_proof_field( 'wccs_a', array( 'visible' => wccs_proof_reads( 'wccs_gone' ) ) ) )
);

wccs_proof_check(
	'A rule naming a field that does not exist is refused',
	in_array( 'condition_field_unknown', $wccs_missing->error_codes(), true ),
	'codes=' . wp_json_encode( $wccs_missing->error_codes() )
);

$wccs_cycle = wccs_proof_validate(
	array(
		wccs_proof_field( 'wccs_a', array( 'visible' => wccs_proof_reads( 'wccs_b' ) ) ),
		wccs_proof_field( 'wccs_b', array( 'visible' => wccs_proof_reads( 'wccs_a' ) ) ),
	)
);

wccs_proof_check(
	'Two fields that depend on each other are refused',
	in_array( 'condition_cycle', $wccs_cycle->error_codes(), true ),
	'codes=' . wp_json_encode( $wccs_cycle->error_codes() )
);

$wccs_chain = wccs_proof_validate(
	array(
		wccs_proof_field( 'wccs_a', array( 'visible' => wccs_proof_reads( 'wccs_b' ) ) ),
		wccs_proof_field( 'wccs_b', array( 'visible' => wccs_proof_reads( 'wccs_c' ) ) ),
		wccs_proof_field( 'wccs_c', array() ),
	)
);

wccs_proof_check(
	'A dependency chain that does not close is accepted',
	$wccs_chain->is_valid(),
	'codes=' . wp_json_encode( $wccs_chain->error_codes() )
);

$wccs_typed = wccs_proof_validate(
	array(
		wccs_proof_field( 'wccs_consent', array(), 'checkbox' ),
		wccs_proof_field(
			'wccs_a',
			array(
				'visible' => wccs_proof_reads( 'wccs_consent', 'greater_than', 3 ),
			)
		),
	)
);

wccs_proof_check(
	'An operator that cannot read what the named field holds is refused',
	in_array( 'condition_source_incompatible', $wccs_typed->error_codes(), true ),
	'the half of typed operators that needs the referenced field'
);

// ---------------------------------------------------------------------------
// 3. AND/OR, with both answers.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Groups of groups' );

$wccs_tree = wccs_proof_field(
	'wccs_company',
	array(
		'visible' => array(
			'all' => array(
				wccs_proof_reads( 'wccs_person_type', 'equals', 'pj' ),
				array(
					'any' => array(
						array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
						array(
							'source'   => 'cart_total',
							'operator' => 'greater_than',
							'value'    => 100,
						),
					),
				),
			),
		),
	),
	'text'
);

$wccs_tree_result = wccs_proof_validate(
	array(
		wccs_proof_field( 'wccs_person_type', array() ),
		$wccs_tree,
	)
);

wccs_proof_check(
	'A nested AND/OR rule reading a field and two server sources is accepted',
	$wccs_tree_result->is_valid(),
	'codes=' . wp_json_encode( $wccs_tree_result->error_codes() )
);

wccs_proof_check(
	'And it is the tree the store will evaluate',
	null !== \WCCheckoutSuite\Domain\Conditions\ConditionTree::parse( $wccs_tree['conditions']['visible'] ),
	'references=' . wp_json_encode(
		\WCCheckoutSuite\Domain\Conditions\ConditionTree::parse( $wccs_tree['conditions']['visible'] )->references()
	)
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'When each check runs',
	'The per-rule checks run on every write, because the rule alone is enough to judge them. The reference and cycle checks run where the document is validated — the same place section references are checked — so they are reported in the publish panel and refuse publication, rather than refusing a draft that is still being written.'
);

wccs_proof_note(
	'Not exercised here',
	'Evaluation. Nothing decides anything yet: the engine that walks this tree is WCCS-033, and the evaluator the pipeline asks is still the permissive one. What is proven is the vocabulary and the refusals, which is what makes an evaluator worth writing.'
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
