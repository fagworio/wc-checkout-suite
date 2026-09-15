<?php
/**
 * Condition vocabulary and tree tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Conditions;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Conditions\ConditionTree;
use WCCheckoutSuite\Domain\Conditions\ConditionValidator;
use WCCheckoutSuite\Domain\Conditions\Operator;
use WCCheckoutSuite\Domain\Conditions\Operators;
use WCCheckoutSuite\Domain\Conditions\Sources;

/**
 * Covers the shapes a rule may have, and the two ways one is refused.
 *
 * The acceptance is "AND/OR and typed operators; cycles and invalid references
 * rejected", and the two halves are checked in different places for a reason worth
 * keeping: a rule on its own can be judged against the vocabularies, and a rule
 * that names a field can only be judged with the rest of the document in hand.
 */
final class ConditionVocabularyTest extends TestCase {

	/**
	 * Builds a field definition with conditions.
	 *
	 * @param string               $id         Identifier.
	 * @param array<string, mixed> $conditions Conditions.
	 * @param string               $type       Field type.
	 * @return array<string, mixed>
	 */
	private function field( string $id, array $conditions, string $type = 'text' ): array {
		return array(
			'id'         => $id,
			'type'       => $type,
			'label'      => $id,
			'origin'     => 'custom',
			'conditions' => $conditions,
		);
	}

	/**
	 * A leaf that reads another field.
	 *
	 * @param string $field    Field identifier.
	 * @param string $operator Operator key.
	 * @param mixed  $value    Comparison value.
	 * @return array<string, mixed>
	 */
	private function reads( string $field, string $operator = 'equals', mixed $value = 'x' ): array {
		return array(
			'source'   => 'field',
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * The five families of section 11 are all present, with their negations.
	 *
	 * @return void
	 */
	public function test_the_operator_vocabulary_covers_section_11(): void {
		$expected = array(
			'equals',
			'not_equals',
			'contains',
			'not_contains',
			'greater_than',
			'less_than',
			'is_empty',
			'is_not_empty',
			'in',
			'not_in',
		);

		$keys = array_keys( Operators::all() );

		sort( $expected );
		sort( $keys );

		self::assertSame( $expected, $keys );
	}

	/**
	 * Every negation says which operator it negates.
	 *
	 * @return void
	 */
	public function test_negations_are_declared(): void {
		foreach ( array( 'not_equals', 'not_contains', 'is_not_empty', 'not_in' ) as $key ) {
			self::assertTrue( Operators::get( $key )->is_negated(), $key . ' is a negation' );
		}

		foreach ( array( 'equals', 'contains', 'is_empty', 'in' ) as $key ) {
			self::assertFalse( Operators::get( $key )->is_negated(), $key . ' is not a negation' );
		}
	}

	/**
	 * An operator that asks a question takes no value.
	 *
	 * @return void
	 */
	public function test_emptiness_takes_no_value(): void {
		self::assertFalse( Operators::get( 'is_empty' )->takes_value() );
		self::assertFalse( Operators::get( 'is_not_empty' )->takes_value() );
		self::assertTrue( Operators::get( 'equals' )->takes_value() );
	}

	/**
	 * The sources §6.8 lists are all present, and nothing else is.
	 *
	 * Section 6.8 names them as the sources a conditional rule may read — product, category, tag,
	 * virtual/downloadable, quantity, subtotal, country, state, shipping method, payment method,
	 * user, role and another field — and the vocabulary is closed for the reason ADR-0007 gives:
	 * the set the editor offers and the set the validator accepts have to be the same set.
	 *
	 * @return void
	 */
	public function test_the_source_vocabulary_covers_section_6_8(): void {
		$expected = array(
			'field',
			'country',
			'state',
			'shipping_method',
			'payment_method',
			'customer_logged_in',
			'cart_items',
			'cart_categories',
			'cart_tags',
			'cart_total',
			'cart_virtual',
			'cart_downloadable',
			'cart_quantity',
			'cart_subtotal',
			'user_role',
		);

		$keys = array_keys( Sources::all() );

		sort( $expected );
		sort( $keys );

		self::assertSame( $expected, $keys );
	}

	/**
	 * The vocabulary the shared fixtures cover is the one this store publishes.
	 *
	 * Parity is only a claim about the sources both engines were held to. The fixture file carries
	 * its own copy of the vocabulary so it can be read without a running store; this is where the
	 * copy is held to the catalogue, so a source added here without a case — or a case for a source
	 * this store does not have — fails instead of quietly narrowing what "paridade" means.
	 *
	 * @return void
	 */
	public function test_the_fixture_vocabulary_is_the_published_one(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixture shipped with the plugin; the sniff targets remote URLs.
		$raw  = file_get_contents( dirname( __DIR__, 4 ) . '/resources/fixtures/conditions.json' );
		$data = false === $raw ? array() : json_decode( $raw, true );

		$sources   = array_keys( Sources::all() );
		$operators = array_keys( Operators::all() );

		$fixture_sources   = (array) ( $data['vocabulary']['sources'] ?? array() );
		$fixture_operators = (array) ( $data['vocabulary']['operators'] ?? array() );

		sort( $sources );
		sort( $fixture_sources );
		sort( $operators );
		sort( $fixture_operators );

		self::assertSame( $sources, $fixture_sources, 'sources the fixtures cover' );
		self::assertSame( $operators, $fixture_operators, 'operators the fixtures cover' );
	}

	/**
	 * A source says whether the browser can answer for it.
	 *
	 * @return void
	 */
	public function test_the_scope_of_each_source_is_declared(): void {
		self::assertTrue( Sources::get( 'cart_total' )->is_server_only() );
		self::assertTrue( Sources::get( 'cart_items' )->is_server_only() );
		self::assertTrue( Sources::get( 'customer_logged_in' )->is_server_only() );

		self::assertFalse( Sources::get( 'field' )->is_server_only() );
		self::assertFalse( Sources::get( 'country' )->is_server_only() );

		self::assertSame( Operator::TYPE_NUMBER, Sources::get( 'cart_total' )->type() );
		self::assertSame( Operator::TYPE_LIST, Sources::get( 'cart_items' )->type() );
		self::assertSame( Operator::TYPE_BOOLEAN, Sources::get( 'customer_logged_in' )->type() );

		// The rest of §6.8's list, and the reading each one carries. Every one of them is answered
		// by the server with the context it holds, so none of them is a source the page decides.
		self::assertSame( Operator::TYPE_LIST, Sources::get( 'cart_tags' )->type() );
		self::assertSame( Operator::TYPE_BOOLEAN, Sources::get( 'cart_virtual' )->type() );
		self::assertSame( Operator::TYPE_BOOLEAN, Sources::get( 'cart_downloadable' )->type() );
		self::assertSame( Operator::TYPE_NUMBER, Sources::get( 'cart_quantity' )->type() );
		self::assertSame( Operator::TYPE_NUMBER, Sources::get( 'cart_subtotal' )->type() );
		self::assertSame( Operator::TYPE_LIST, Sources::get( 'user_role' )->type() );

		foreach ( array( 'cart_tags', 'cart_virtual', 'cart_downloadable', 'cart_quantity', 'cart_subtotal', 'user_role' ) as $server_source ) {
			self::assertTrue( Sources::get( $server_source )->is_server_only(), $server_source );
		}
	}

	/**
	 * One place decides what a node is.
	 *
	 * @return void
	 */
	public function test_the_shape_of_a_node_is_decided_in_one_place(): void {
		self::assertSame( ConditionTree::SHAPE_GROUP, ConditionTree::shape( array( 'all' => array() ) ) );
		self::assertSame( ConditionTree::SHAPE_GROUP, ConditionTree::shape( array( 'any' => array() ) ) );
		self::assertSame(
			ConditionTree::SHAPE_LEAF,
			ConditionTree::shape(
				array(
					'source'   => 'country',
					'operator' => 'equals',
				)
			)
		);
		self::assertSame( ConditionTree::SHAPE_INVALID, ConditionTree::shape( 'a string' ) );
		self::assertSame( ConditionTree::SHAPE_INVALID, ConditionTree::shape( array( 'source' => 'country' ) ) );
		self::assertSame( ConditionTree::SHAPE_INVALID, ConditionTree::shape( array( 'nope' => array() ) ) );
		self::assertSame( ConditionTree::SHAPE_INVALID, ConditionTree::shape( array( 'all' => 'not a list' ) ) );
	}

	/**
	 * A tree parses, reports its references and knows its own size.
	 *
	 * @return void
	 */
	public function test_a_tree_parses_and_reports_what_it_reads(): void {
		$tree = ConditionTree::parse(
			array(
				'all' => array(
					$this->reads( 'person_type', 'equals', 'pj' ),
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
			)
		);

		self::assertNotNull( $tree );
		self::assertTrue( $tree->is_group() );
		self::assertSame( ConditionTree::GROUP_ALL, $tree->group() );
		self::assertSame( array( 'person_type' ), $tree->references() );
		self::assertSame( 5, $tree->size() );
		self::assertSame( 3, $tree->depth() );
	}

	/**
	 * A tree that cannot be parsed does not throw.
	 *
	 * It is read out of an option, and a store whose tree cannot be parsed should
	 * evaluate as "no condition" rather than take the checkout down.
	 *
	 * @return void
	 */
	public function test_an_unparseable_tree_answers_null(): void {
		self::assertNull( ConditionTree::parse( 'not a tree' ) );
		self::assertNull( ConditionTree::parse( array( 'all' => array( 'not a node' ) ) ) );
	}

	/**
	 * An unknown operator is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_operator_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array( 'visible' => $this->reads( 'wccs_b', 'is_approximately' ) )
		);

		self::assertContains( 'unknown_condition_operator', $result->error_codes() );
	}

	/**
	 * An unknown source is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_source_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array(
				'visible' => array(
					'source'   => 'weather',
					'operator' => 'equals',
					'value'    => 'rain',
				),
			)
		);

		self::assertContains( 'unknown_condition_source', $result->error_codes() );
	}

	/**
	 * An operator that cannot compare against the value is refused.
	 *
	 * "greater than" against text is not a rule that evaluates to false; it is a
	 * rule that does not mean anything.
	 *
	 * @return void
	 */
	public function test_an_incompatible_value_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array(
				'visible' => array(
					'source'   => 'country',
					'operator' => 'greater_than',
					'value'    => 'BR',
				),
			)
		);

		self::assertContains( 'condition_value_incompatible', $result->error_codes() );
	}

	/**
	 * An operator that cannot read the source it is pointed at is refused.
	 *
	 * The value type alone does not settle this: a number is a value "greater
	 * than" accepts, so "Country is greater than 5" passed every other check while
	 * comparing a country with a number. The source's own type is what decides, and
	 * the operator declares which ones it can read.
	 *
	 * @return void
	 */
	public function test_an_operator_reading_an_incompatible_source_is_refused(): void {
		$cases = array(
			'string source and a numeric comparison' => array( 'country', 'greater_than', 5 ),
			'list source and a numeric comparison'   => array( 'cart_items', 'greater_than', 5 ),
			'boolean source and containment'         => array( 'customer_logged_in', 'contains', 'x' ),
			'number source and containment'          => array( 'cart_total', 'contains', 'x' ),
		);

		foreach ( $cases as $label => $case ) {
			[ $source, $operator, $value ] = $case;

			$result = ConditionValidator::validate_rules(
				'wccs_a',
				array(
					'visible' => array(
						'source'   => $source,
						'operator' => $operator,
						'value'    => $value,
					),
				)
			);

			self::assertContains(
				'condition_source_incompatible',
				$result->error_codes(),
				$label
			);
		}
	}

	/**
	 * The operator set the editor offers and the one the validator accepts agree.
	 *
	 * This is the property ADR-0007 asks a closed vocabulary to have, and the one
	 * that makes the rule pass through both sides: the editor offers what the
	 * operator lists for the source's type, and the validator accepts exactly that.
	 * A pair judged acceptable by one and refused by the other is the disagreement
	 * this test exists to catch, so it walks every source and every operator rather
	 * than a sample of them.
	 *
	 * @return void
	 */
	public function test_the_editor_offer_and_the_validator_agree_on_every_pair(): void {
		$values = array(
			'in'           => array( 'BR', 'PT' ),
			'not_in'       => array( 'BR', 'PT' ),
			'greater_than' => 5,
			'less_than'    => 5,
		);

		$disagreements = array();

		foreach ( Sources::all() as $source_key => $source ) {
			if ( $source->is_reference() ) {
				continue;
			}

			foreach ( Operators::all() as $operator_key => $operator ) {
				$leaf = array(
					'source'   => $source_key,
					'operator' => $operator_key,
				);

				if ( $operator->takes_value() ) {
					$leaf['value'] = $values[ $operator_key ] ?? 'BR';
				}

				$accepted = ConditionValidator::validate_rules(
					'wccs_a',
					array( 'visible' => $leaf )
				)->is_valid();

				// What the editor offers is decided by the operator's own list of
				// readable types, which is the same list the validator reads.
				$offered = $operator->accepts_source( $source->type() );

				if ( $accepted !== $offered ) {
					$disagreements[] = $source_key . ' + ' . $operator_key;
				}
			}
		}

		self::assertSame( array(), $disagreements );
	}

	/**
	 * A comparison with nothing to compare against is refused.
	 *
	 * @return void
	 */
	public function test_a_missing_value_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array(
				'visible' => array(
					'source'   => 'country',
					'operator' => 'equals',
				),
			)
		);

		self::assertContains( 'condition_value_missing', $result->error_codes() );
	}

	/**
	 * A value on a question that takes none is refused.
	 *
	 * @return void
	 */
	public function test_a_value_where_none_belongs_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array(
				'visible' => array(
					'source'   => 'country',
					'operator' => 'is_empty',
					'value'    => 'BR',
				),
			)
		);

		self::assertContains( 'condition_value_unexpected', $result->error_codes() );
	}

	/**
	 * A group with nothing in it is refused.
	 *
	 * An empty `all` matches everything and an empty `any` matches nothing, and
	 * either way the merchant wrote something that does not say what they meant.
	 *
	 * @return void
	 */
	public function test_an_empty_group_is_refused(): void {
		$result = ConditionValidator::validate_rules( 'wccs_a', array( 'visible' => array( 'all' => array() ) ) );

		self::assertContains( 'empty_condition_group', $result->error_codes() );
	}

	/**
	 * A field that depends on itself is refused at the rule.
	 *
	 * @return void
	 */
	public function test_a_self_reference_is_refused(): void {
		$result = ConditionValidator::validate_rules(
			'wccs_a',
			array( 'visible' => $this->reads( 'wccs_a' ) )
		);

		self::assertContains( 'condition_self_reference', $result->error_codes() );
	}

	/**
	 * A rule reading a field that does not exist is refused.
	 *
	 * @return void
	 */
	public function test_a_reference_to_a_missing_field_is_refused(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field( 'wccs_a', array( 'visible' => $this->reads( 'wccs_gone' ) ) ),
			)
		);

		self::assertContains( 'condition_field_unknown', $result->error_codes() );
	}

	/**
	 * Two fields that depend on each other are refused.
	 *
	 * The pair has no answer — not a wrong one, none — and an engine that picked
	 * one would show whichever field it happened to evaluate first.
	 *
	 * @return void
	 */
	public function test_a_two_field_cycle_is_refused(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field( 'wccs_a', array( 'visible' => $this->reads( 'wccs_b' ) ) ),
				$this->field( 'wccs_b', array( 'visible' => $this->reads( 'wccs_a' ) ) ),
			)
		);

		self::assertContains( 'condition_cycle', $result->error_codes() );
	}

	/**
	 * A longer cycle is refused too, including across a group.
	 *
	 * @return void
	 */
	public function test_a_cycle_through_a_group_is_refused(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field(
					'wccs_a',
					array(
						'visible' => array(
							'all' => array(
								$this->reads( 'wccs_b', 'equals', 'x' ),
								array(
									'source'   => 'country',
									'operator' => 'equals',
									'value'    => 'BR',
								),
							),
						),
					)
				),
				$this->field( 'wccs_b', array( 'visible' => $this->reads( 'wccs_c' ) ) ),
				$this->field( 'wccs_c', array( 'visible' => $this->reads( 'wccs_a' ) ) ),
			)
		);

		self::assertContains( 'condition_cycle', $result->error_codes() );
	}

	/**
	 * A chain that does not close is accepted.
	 *
	 * The check has to refuse cycles without refusing ordinary dependencies, and a
	 * cycle detector that refuses any dependency is a detector nobody can use.
	 *
	 * @return void
	 */
	public function test_a_dependency_chain_is_accepted(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field( 'wccs_a', array( 'visible' => $this->reads( 'wccs_b' ) ) ),
				$this->field( 'wccs_b', array( 'visible' => $this->reads( 'wccs_c' ) ) ),
				$this->field( 'wccs_c', array() ),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A text operator reading a number field is accepted.
	 *
	 * Numbers are written as text by the customer and compared as numbers by the
	 * arithmetic, so "equal to 100" on a cart total is a rule that means
	 * something.
	 *
	 * @return void
	 */
	public function test_an_operator_reading_a_compatible_field_is_accepted(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field( 'wccs_qty', array(), 'number' ),
				$this->field(
					'wccs_a',
					array(
						'visible' => array(
							'source'   => 'field',
							'field'    => 'wccs_qty',
							'operator' => 'greater_than',
							'value'    => 3,
						),
					)
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A comparison operator reading a checkbox is refused.
	 *
	 * This is the half of "typed operators" only the document can check, and the
	 * reason the reference and the rule travel together.
	 *
	 * @return void
	 */
	public function test_an_operator_reading_an_incompatible_field_is_refused(): void {
		$result = ConditionValidator::validate_document(
			array(
				$this->field( 'wccs_consent', array(), 'checkbox' ),
				$this->field(
					'wccs_a',
					array(
						'visible' => array(
							'source'   => 'field',
							'field'    => 'wccs_consent',
							'operator' => 'greater_than',
							'value'    => 3,
						),
					)
				),
			)
		);

		self::assertContains( 'condition_source_incompatible', $result->error_codes() );
	}

	/**
	 * A field with no conditions is the ordinary case.
	 *
	 * @return void
	 */
	public function test_a_field_without_conditions_is_accepted(): void {
		self::assertTrue( ConditionValidator::validate_rules( 'wccs_a', array() )->is_valid() );
		self::assertTrue(
			ConditionValidator::validate_rules( 'wccs_a', array( 'visible' => array() ) )->is_valid()
		);
		self::assertTrue(
			ConditionValidator::validate_document( array( $this->field( 'wccs_a', array() ) ) )->is_valid()
		);
	}
}
