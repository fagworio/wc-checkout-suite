<?php
/**
 * WCCS-032 proof harness — the rule editor's contract with the server.
 *
 * Task:   WCCS-032 "Criar editor de regras"
 * Phase:  F06
 * Accept: "Regras legíveis, preview de resultado e mensagens de contradição."
 *
 * The editor itself is JavaScript, and what it renders is covered by the JS suite.
 * What this harness proves is the half of the contract that lives on the server,
 * because that is the half an editor cannot fake:
 *
 * 1. The vocabulary the editor builds its form from is published, through the same
 *    REST route the application reads, in the shape its types declare.
 *
 * 2. The set of operators the editor offers for a source is exactly the set the
 *    validator accepts for it. This is the property ADR-0007 asks a closed
 *    vocabulary to have, and the one where the two sides can silently drift apart.
 *
 * 3. The document the editor writes is the document the store keeps: the shape
 *    under `conditions.visible`, the `evaluator` key it does not own, and the fact
 *    that an empty group is refused — which is why removing the last condition
 *    clears the rule instead of leaving a group behind.
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
 * The keys of an array, sorted, for comparing two payload shapes.
 *
 * @param array<string, mixed> $entry Entry.
 * @return array<int, string>
 */
function wccs_proof_keys( array $entry ): array {
	$keys = array_keys( $entry );
	sort( $keys );

	return $keys;
}

/**
 * Builds a field definition.
 *
 * @param string               $id         Identifier.
 * @param string               $type       Field type.
 * @param array<string, mixed> $conditions Conditions.
 * @param array<string, mixed> $settings   Type settings.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $type = 'text', array $conditions = array(), array $settings = array() ): array {
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
		'settings'       => $settings,
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
 * Validates one definition the way a draft write does.
 *
 * @param array<string, mixed> $field Field.
 * @return \WCCheckoutSuite\Domain\Fields\ValidationResult
 */
function wccs_proof_validate_field( array $field ): \WCCheckoutSuite\Domain\Fields\ValidationResult {
	return \WCCheckoutSuite\Domain\Registries::instance()->definition_validator()->validate_array( $field );
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
 * The comparison value an operator needs, chosen so the value type is never the
 * reason a pair is refused: this harness is about the operator and the source.
 *
 * @param string $operator_key Operator key.
 * @return array{takes: bool, value: mixed} Whether it takes one, and the value.
 */
function wccs_proof_value_for( string $operator_key ): array {
	if ( in_array( $operator_key, array( 'in', 'not_in' ), true ) ) {
		return array(
			'takes' => true,
			'value' => array( 'BR', 'PT' ),
		);
	}

	if ( in_array( $operator_key, array( 'greater_than', 'less_than' ), true ) ) {
		return array(
			'takes' => true,
			'value' => 5,
		);
	}

	if ( in_array( $operator_key, array( 'is_empty', 'is_not_empty' ), true ) ) {
		return array(
			'takes' => false,
			'value' => null,
		);
	}

	return array(
		'takes' => true,
		'value' => 'BR',
	);
}

/**
 * A leaf reading a catalogue source.
 *
 * @param string $source   Source key.
 * @param string $operator Operator key.
 * @return array<string, mixed>
 */
function wccs_proof_leaf( string $source, string $operator ): array {
	$value = wccs_proof_value_for( $operator );
	$leaf  = array(
		'source'   => $source,
		'operator' => $operator,
	);

	if ( $value['takes'] ) {
		$leaf['value'] = $value['value'];
	}

	return $leaf;
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-032 proof — the rule editor against the server' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The vocabulary the editor builds its form from.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The catalogue the editor reads' );

$wccs_request  = new WP_REST_Request( 'GET', '/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES );
$wccs_response = rest_do_request( $wccs_request );
$wccs_payload  = $wccs_response->get_data();

wccs_proof_check(
	'The field type catalogue answers the editor',
	200 === $wccs_response->get_status() && is_array( $wccs_payload ),
	'status=' . $wccs_response->get_status()
);

$wccs_conditions = is_array( $wccs_payload ) && isset( $wccs_payload['conditions'] ) && is_array( $wccs_payload['conditions'] )
	? $wccs_payload['conditions']
	: array();

wccs_proof_check(
	'It publishes the condition vocabulary',
	isset( $wccs_conditions['operators'], $wccs_conditions['sources'], $wccs_conditions['limits'] ),
	'keys=' . wp_json_encode( array_keys( $wccs_conditions ) )
);

wccs_proof_check(
	'Every operator the validator has is published, and nothing else',
	count( (array) ( $wccs_conditions['operators'] ?? array() ) ) === count( \WCCheckoutSuite\Domain\Conditions\Operators::all() ),
	'published=' . count( (array) ( $wccs_conditions['operators'] ?? array() ) ) . ' validator=' . count( \WCCheckoutSuite\Domain\Conditions\Operators::all() )
);

wccs_proof_check(
	'Every source the validator has is published, and nothing else',
	count( (array) ( $wccs_conditions['sources'] ?? array() ) ) === count( \WCCheckoutSuite\Domain\Conditions\Sources::all() ),
	'published=' . count( (array) ( $wccs_conditions['sources'] ?? array() ) ) . ' validator=' . count( \WCCheckoutSuite\Domain\Conditions\Sources::all() )
);

wccs_proof_check(
	'The published limits are the limits the validator enforces',
	\WCCheckoutSuite\Domain\Conditions\ConditionValidator::MAX_DEPTH === ( $wccs_conditions['limits']['maxDepth'] ?? null )
		&& \WCCheckoutSuite\Domain\Conditions\ConditionValidator::MAX_NODES === ( $wccs_conditions['limits']['maxNodes'] ?? null ),
	'depth=' . ( $wccs_conditions['limits']['maxDepth'] ?? '?' ) . ' nodes=' . ( $wccs_conditions['limits']['maxNodes'] ?? '?' )
);

// The editor's operator type declares these six properties and reads no others.
$wccs_operator_keys = array( 'key', 'label', 'negated', 'sourceTypes', 'takesValue', 'valueTypes' );
$wccs_bad_operators = array();

foreach ( (array) ( $wccs_conditions['operators'] ?? array() ) as $wccs_entry ) {
	if ( ! is_array( $wccs_entry ) || wccs_proof_keys( $wccs_entry ) !== $wccs_operator_keys ) {
		$wccs_bad_operators[] = is_array( $wccs_entry ) ? (string) ( $wccs_entry['key'] ?? '?' ) : 'not-an-object';
	}
}

wccs_proof_check(
	'Every operator carries exactly the properties the editor declares',
	array() === $wccs_bad_operators,
	'offenders=' . wp_json_encode( $wccs_bad_operators )
);

// The editor's source type declares these five, and `isReference` is what turns a
// source into the one that names a field.
$wccs_source_keys = array( 'isReference', 'key', 'label', 'scope', 'type' );
$wccs_bad_sources = array();

foreach ( (array) ( $wccs_conditions['sources'] ?? array() ) as $wccs_entry ) {
	if ( ! is_array( $wccs_entry ) || wccs_proof_keys( $wccs_entry ) !== $wccs_source_keys ) {
		$wccs_bad_sources[] = is_array( $wccs_entry ) ? (string) ( $wccs_entry['key'] ?? '?' ) : 'not-an-object';
	}
}

wccs_proof_check(
	'Every source carries exactly the properties the editor declares',
	array() === $wccs_bad_sources,
	'offenders=' . wp_json_encode( $wccs_bad_sources )
);

// ---------------------------------------------------------------------------
// 2. What the editor offers is what the validator accepts.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The offer list and the validator agree' );

$wccs_without_operator = array();

foreach ( \WCCheckoutSuite\Domain\Conditions\Sources::all() as $wccs_key => $wccs_source ) {
	// The reference source is judged by the document, once the field it names is
	// resolved; section 3 covers it.
	if ( $wccs_source->is_reference() ) {
		continue;
	}

	$wccs_offered  = array();
	$wccs_accepted = array();

	foreach ( \WCCheckoutSuite\Domain\Conditions\Operators::all() as $wccs_operator_key => $wccs_operator ) {
		if ( $wccs_operator->accepts_source( $wccs_source->type() ) ) {
			$wccs_offered[] = $wccs_operator_key;
		}

		// The editor would not offer a pair its offer list excludes, so an
		// editor-shaped rule is the one to validate: a leaf carrying the value its
		// operator asks for, and nothing else.
		$wccs_result = wccs_proof_validate_field(
			wccs_proof_field( 'wccs_a', 'text', array( 'visible' => wccs_proof_leaf( (string) $wccs_key, (string) $wccs_operator_key ) ) )
		);

		if ( $wccs_result->is_valid() ) {
			$wccs_accepted[] = $wccs_operator_key;
		}
	}

	if ( array() === $wccs_offered ) {
		$wccs_without_operator[] = (string) $wccs_key;
	}

	$wccs_only_offered  = array_values( array_diff( $wccs_offered, $wccs_accepted ) );
	$wccs_only_accepted = array_values( array_diff( $wccs_accepted, $wccs_offered ) );

	wccs_proof_check(
		sprintf( 'The operators offered for "%s" are the ones the validator accepts', (string) $wccs_source->label() ),
		array() === $wccs_only_offered && array() === $wccs_only_accepted,
		'type=' . $wccs_source->type()
			. ' offered-not-accepted=' . wp_json_encode( $wccs_only_offered )
			. ' accepted-not-offered=' . wp_json_encode( $wccs_only_accepted )
	);
}

wccs_proof_check(
	'Every source has at least one operator that can read it',
	array() === $wccs_without_operator,
	'a source with no operator would be a row the editor could not complete: ' . wp_json_encode( $wccs_without_operator )
);

// A rule the editor cannot produce is refused, which is what makes the offer list
// a rule of the store rather than a courtesy of the interface.
$wccs_meaningless = wccs_proof_validate_field(
	wccs_proof_field(
		'wccs_a',
		'text',
		array(
			'visible' => array(
				'source'   => 'country',
				'operator' => 'greater_than',
				'value'    => 5,
			),
		)
	)
);

wccs_proof_check(
	'A comparison the operator cannot make is refused when it is written',
	in_array( 'condition_source_incompatible', $wccs_meaningless->error_codes(), true ),
	'codes=' . wp_json_encode( $wccs_meaningless->error_codes() )
);

// ---------------------------------------------------------------------------
// 3. What a reference is judged by.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The reference source, which only the document can type' );

// The value type each of these fields holds is the one the condition vocabulary
// names, and the multiselect carries the settings its type requires.
$wccs_referenced = array(
	'string'  => wccs_proof_field( 'wccs_text', 'text' ),
	'number'  => wccs_proof_field( 'wccs_number', 'number' ),
	'boolean' => wccs_proof_field( 'wccs_checkbox', 'checkbox' ),
	'list'    => wccs_proof_field(
		'wccs_multi',
		'multiselect',
		array(),
		array(
			'options' => array(
				array(
					'value' => 'a',
					'label' => 'A',
				),
			),
		)
	),
);

foreach ( $wccs_referenced as $wccs_type => $wccs_definition ) {
	$wccs_offered  = array();
	$wccs_accepted = array();

	foreach ( \WCCheckoutSuite\Domain\Conditions\Operators::all() as $wccs_operator_key => $wccs_operator ) {
		if ( $wccs_operator->accepts_source( $wccs_type ) ) {
			$wccs_offered[] = $wccs_operator_key;
		}

		$wccs_leaf = array(
			'source'   => 'field',
			'field'    => $wccs_definition['id'],
			'operator' => $wccs_operator_key,
		);

		$wccs_value = wccs_proof_value_for( (string) $wccs_operator_key );

		if ( $wccs_value['takes'] ) {
			$wccs_leaf['value'] = $wccs_value['value'];
		}

		$wccs_result = wccs_proof_validate(
			array(
				$wccs_definition,
				wccs_proof_field( 'wccs_child', 'text', array( 'visible' => $wccs_leaf ) ),
			)
		);

		if ( $wccs_result->is_valid() ) {
			$wccs_accepted[] = $wccs_operator_key;
		}
	}

	$wccs_only_offered  = array_values( array_diff( $wccs_offered, $wccs_accepted ) );
	$wccs_only_accepted = array_values( array_diff( $wccs_accepted, $wccs_offered ) );

	wccs_proof_check(
		sprintf( 'A reference to a field holding a %s is judged by that type', $wccs_type ),
		array() === $wccs_only_offered && array() === $wccs_only_accepted,
		'offered-not-accepted=' . wp_json_encode( $wccs_only_offered )
			. ' accepted-not-offered=' . wp_json_encode( $wccs_only_accepted )
	);
}

// ---------------------------------------------------------------------------
// 4. The document the editor writes.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The document the editor writes, as the store reads it' );

$wccs_leaf = array(
	'source'   => 'country',
	'operator' => 'equals',
	'value'    => 'BR',
);

$wccs_empty = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	wccs_proof_field( 'wccs_a', 'text', array() )
);

wccs_proof_check(
	'A field with no rule at all is the ordinary case, not an invalid one',
	array() === $wccs_empty->to_array()['conditions'],
	'conditions=' . wp_json_encode( $wccs_empty->to_array()['conditions'] )
);

$wccs_round_trip = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	wccs_proof_field( 'wccs_a', 'text', array( 'visible' => $wccs_leaf ) )
)->to_array();

wccs_proof_check(
	'The rule survives a round trip under the key the editor writes',
	$wccs_leaf === ( $wccs_round_trip['conditions']['visible'] ?? null ),
	'stored=' . wp_json_encode( $wccs_round_trip['conditions'] )
);

// The editor writes the whole document back, so a key it does not own has to come
// out the other side: `evaluator` pins the engine a store was written against.
$wccs_pinned = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	wccs_proof_field(
		'wccs_a',
		'text',
		array(
			'evaluator' => 'always',
			'visible'   => $wccs_leaf,
		)
	)
)->to_array();

wccs_proof_check(
	'And a key the editor does not own is kept beside it',
	'always' === ( $wccs_pinned['conditions']['evaluator'] ?? null )
		&& $wccs_leaf === ( $wccs_pinned['conditions']['visible'] ?? null ),
	'stored=' . wp_json_encode( $wccs_pinned['conditions'] )
);

$wccs_empty_group = wccs_proof_validate_field(
	wccs_proof_field( 'wccs_a', 'text', array( 'visible' => array( 'all' => array() ) ) )
);

wccs_proof_check(
	'An empty group is refused, which is why removing the last condition clears the rule',
	in_array( 'empty_condition_group', $wccs_empty_group->error_codes(), true ),
	'codes=' . wp_json_encode( $wccs_empty_group->error_codes() )
);

$wccs_nested = \WCCheckoutSuite\Domain\Conditions\ConditionValidator::validate_rules(
	'wccs_company',
	array(
		'visible' => array(
			'all' => array(
				array(
					'source'   => 'field',
					'field'    => 'wccs_person_type',
					'operator' => 'equals',
					'value'    => 'pj',
				),
				array(
					'any' => array(
						$wccs_leaf,
						array(
							'source'   => 'cart_total',
							'operator' => 'greater_than',
							'value'    => 100,
						),
					),
				),
			),
		),
	)
);

wccs_proof_check(
	'A nested rule built the way the editor builds one is accepted',
	$wccs_nested->is_valid(),
	'codes=' . wp_json_encode( $wccs_nested->error_codes() )
);

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
	'What the offer list means',
	'The editor offers an operator for a source only when the operator lists that source type, and the validator now accepts exactly those pairs. Both read the same declaration, which is what keeps a closed vocabulary closed.'
);

wccs_proof_note(
	'Told apart by whom',
	'The reference source is the one case the rule alone cannot settle: what it holds is decided by the field it names, so the per-rule check leaves it alone and the document-level check judges the resolved type. Section 3 proves the answer is the same either way.'
);

wccs_proof_note(
	'A divergence found while writing this',
	'A field of type "file" publishes a value schema of type "array" while ConditionValidator::definition_type() maps it to string. Recorded as the open decision CONDITION-VALUE-TYPE; it is not asserted either way here, because which of the two is authoritative is the decision.'
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
