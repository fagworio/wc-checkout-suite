<?php
/**
 * The operator vocabulary.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * Every operator a rule may use, and nothing else.
 *
 * A closed set, for the reason ADR-0007 gives about the definition vocabularies:
 * the set the editor offers and the set the validator accepts have to be the same
 * set, and the way to guarantee that is for both to read it from here. A rule
 * naming an operator this catalogue does not have is refused rather than ignored,
 * because a condition that silently never matches is worse than one that cannot be
 * saved.
 *
 * Section 11 names five families: equality, containment, comparison, emptiness
 * and set membership. Each one is here with its negation, and each declares what
 * it can read and what it can be compared against.
 *
 * @see \ROADMAP.md section 11
 * @see \docs/adr/ADR-0007-closed-vocabularies.md
 */
final class Operators {

	/**
	 * Every operator, keyed by its stable key.
	 *
	 * @return array<string, Operator>
	 */
	public static function all(): array {
		$operators = array(
			new Operator(
				'equals',
				__( 'is equal to', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN ),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN )
			),
			new Operator(
				'not_equals',
				__( 'is not equal to', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN ),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN ),
				true
			),
			new Operator(
				'contains',
				__( 'contains', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_STRING ),
				array( Operator::TYPE_STRING, Operator::TYPE_LIST )
			),
			new Operator(
				'not_contains',
				__( 'does not contain', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_STRING ),
				array( Operator::TYPE_STRING, Operator::TYPE_LIST ),
				true
			),
			new Operator(
				'greater_than',
				__( 'is greater than', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_NUMBER ),
				array( Operator::TYPE_NUMBER )
			),
			new Operator(
				'less_than',
				__( 'is less than', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_NUMBER ),
				array( Operator::TYPE_NUMBER )
			),
			new Operator(
				'is_empty',
				__( 'is empty', 'wc-checkoutsuite' ),
				false,
				array(),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN, Operator::TYPE_LIST )
			),
			new Operator(
				'is_not_empty',
				__( 'is not empty', 'wc-checkoutsuite' ),
				false,
				array(),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER, Operator::TYPE_BOOLEAN, Operator::TYPE_LIST ),
				true
			),
			new Operator(
				'in',
				__( 'is one of', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_LIST ),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER ),
				false
			),
			new Operator(
				'not_in',
				__( 'is not one of', 'wc-checkoutsuite' ),
				true,
				array( Operator::TYPE_LIST ),
				array( Operator::TYPE_STRING, Operator::TYPE_NUMBER ),
				true
			),
		);

		$catalogue = array();

		foreach ( $operators as $operator ) {
			$catalogue[ $operator->key() ] = $operator;
		}

		return $catalogue;
	}

	/**
	 * One operator, or null.
	 *
	 * @param string $key Operator key.
	 * @return Operator|null
	 */
	public static function get( string $key ): ?Operator {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether an operator exists.
	 *
	 * @param string $key Operator key.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return null !== self::get( $key );
	}

	/**
	 * The vocabulary as the editor reads it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_array(): array {
		return array_values(
			array_map(
				static function ( Operator $operator ): array {
					return $operator->to_array();
				},
				self::all()
			)
		);
	}
}
