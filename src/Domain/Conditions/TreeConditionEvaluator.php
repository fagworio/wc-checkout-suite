<?php
/**
 * Condition evaluator.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Evaluates the declarative condition tree.
 *
 * This is the engine the pipeline asks. It walks the tree `ConditionTree` owns and
 * answers one question: does this rule match under this context? A rule that
 * matches means the field is visible, which is the meaning `ValueProcessor` gives
 * the answer.
 *
 * Four decisions make the answers what they are, and each one exists because the
 * alternative would be a rule that means something different in PHP and in
 * JavaScript — the parity this task is about:
 *
 * 1. **Emptiness is not falsiness.** `0`, `'0'` and `false` are values; `''`,
 *    `null` and an empty list are not. A cart total of zero is a total, and a
 *    consent box the customer cleared is an answer. PHP and JavaScript agree on
 *    this because both read the same list here and neither uses its own truthiness
 *    test.
 *
 * 2. **Comparison is decided by the values, not by the language.** Two numbers
 *    compare as numbers, two lists element by element, and anything else as text —
 *    with a boolean rendered as `1` or `0` on both sides, because `(string) true`
 *    is `'1'` in PHP and `String(true)` is `'true'` in JavaScript, and that
 *    difference alone would be enough to make one store behave differently from
 *    another.
 *
 * 3. **A number is only a number when it is written as one.** Both sides use the
 *    same pattern, because PHP's `is_numeric()` and JavaScript's `Number()` do not
 *    agree: `is_numeric( '0x1A' )` is false while `Number( '0x1A' )` is 26.
 *
 * 4. **The engine never hides a field because it failed to understand a rule.** An
 *    unreadable tree, an empty group, a source or operator the vocabulary does not
 *    have all answer "matches" — the same direction as the permissive evaluator
 *    that answered before this one existed. A malformed tree cannot reach here
 *    through the normal path, since the validator refuses one on every write, so
 *    this is about corruption; and on corruption the dangerous answer is the one
 *    that removes a field from the checkout and asks the customer to work out why.
 *
 * @see \ROADMAP.md section 11
 */
final class TreeConditionEvaluator implements ConditionEvaluatorInterface {

	/**
	 * Stable key of this evaluator.
	 */
	public const KEY = 'rules';

	/**
	 * Version of the contract this evaluator implements.
	 */
	public const CONTRACT_VERSION = '1.0';

	/**
	 * Pattern a value has to match to count as a number.
	 *
	 * Deliberately narrower than either language's own conversion: hexadecimal,
	 * infinite and NaN spellings convert differently in PHP and JavaScript, and a
	 * comparison that depends on which language is asking is not a comparison.
	 */
	private const NUMBER_PATTERN = '/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return self::CONTRACT_VERSION;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rules   Declarative rule, e.g. `['all' => [...]]`.
	 * @param FieldContext         $context Trusted server-side context.
	 * @return bool True when the rules match.
	 */
	public function evaluate( array $rules, FieldContext $context ): bool {
		$tree = ConditionTree::parse( $rules );

		if ( null === $tree ) {
			return true;
		}

		return $this->decide( $tree, $context );
	}

	/**
	 * Decides a node.
	 *
	 * @param ConditionTree $node    Node.
	 * @param FieldContext  $context Trusted context.
	 * @return bool
	 */
	private function decide( ConditionTree $node, FieldContext $context ): bool {
		if ( ! $node->is_group() ) {
			return $this->decide_leaf( $node->leaf(), $context );
		}

		$children = $node->children();

		// A group with nothing in it says nothing, and a rule that says nothing is
		// not read as "everything matches": see the class docblock.
		if ( array() === $children ) {
			return true;
		}

		if ( ConditionTree::GROUP_ALL === $node->group() ) {
			foreach ( $children as $child ) {
				if ( ! $this->decide( $child, $context ) ) {
					return false;
				}
			}

			return true;
		}

		foreach ( $children as $child ) {
			if ( $this->decide( $child, $context ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decides one comparison.
	 *
	 * @param array<string, mixed> $leaf    Leaf entries.
	 * @param FieldContext         $context Trusted context.
	 * @return bool
	 */
	private function decide_leaf( array $leaf, FieldContext $context ): bool {
		$source   = Sources::get( (string) ( $leaf[ ConditionTree::KEY_SOURCE ] ?? '' ) );
		$operator = Operators::get( (string) ( $leaf[ ConditionTree::KEY_OPERATOR ] ?? '' ) );

		if ( null === $source || null === $operator ) {
			return true;
		}

		$actual = $this->read( $source, $leaf, $context );

		return self::compare( $actual, $operator->key(), $leaf[ ConditionTree::KEY_VALUE ] ?? null );
	}

	/**
	 * Reads what a source holds in this context.
	 *
	 * A reference reads the map the context carries under `fields`, because the
	 * value of another field is not a context entry: it is whatever was submitted
	 * for that field, collected by the caller that knows the submission.
	 *
	 * @param Source               $source  Source.
	 * @param array<string, mixed> $leaf    Leaf entries.
	 * @param FieldContext         $context Trusted context.
	 * @return mixed
	 */
	private function read( Source $source, array $leaf, FieldContext $context ): mixed {
		if ( ! $source->is_reference() ) {
			return $context->get( $source->key() );
		}

		$fields = $context->get( 'fields', array() );

		if ( ! is_array( $fields ) ) {
			return null;
		}

		return $fields[ (string) ( $leaf[ ConditionTree::KEY_FIELD ] ?? '' ) ] ?? null;
	}

	/**
	 * Applies one operator.
	 *
	 * @param mixed  $actual   Value read from the context.
	 * @param string $operator Operator key.
	 * @param mixed  $expected Comparison value.
	 * @return bool
	 */
	private static function compare( mixed $actual, string $operator, mixed $expected ): bool {
		switch ( $operator ) {
			case 'is_empty':
				return self::is_empty( $actual );
			case 'is_not_empty':
				return ! self::is_empty( $actual );
			case 'equals':
				return self::same( $actual, $expected );
			case 'not_equals':
				return ! self::same( $actual, $expected );
			case 'contains':
				return self::contains( $actual, $expected );
			case 'not_contains':
				return ! self::contains( $actual, $expected );
			case 'greater_than':
				return self::beyond( $actual, $expected, true );
			case 'less_than':
				return self::beyond( $actual, $expected, false );
			case 'in':
				return self::member( $actual, $expected );
			case 'not_in':
				return ! self::member( $actual, $expected );
		}

		// An operator nothing here knows cannot be read, and an unreadable
		// comparison does not hide a field.
		return true;
	}

	/**
	 * Whether a value counts as absent.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty( mixed $value ): bool {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return is_array( $value ) && array() === $value;
	}

	/**
	 * Whether two values are the same one.
	 *
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @return bool
	 */
	private static function same( mixed $left, mixed $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			return self::as_list( $left ) === self::as_list( $right );
		}

		$left_number  = self::as_number( $left );
		$right_number = self::as_number( $right );

		if ( null !== $left_number && null !== $right_number ) {
			return $left_number === $right_number;
		}

		return self::as_text( $left ) === self::as_text( $right );
	}

	/**
	 * Whether a value is greater than, or less than, another.
	 *
	 * A comparison against something that is not written as a number is not a
	 * comparison: it answers false rather than falling back to text order, because
	 * "greater than" over text has no agreed meaning and would order the same two
	 * strings differently in two languages.
	 *
	 * @param mixed $actual   Value read from the context.
	 * @param mixed $expected Comparison value.
	 * @param bool  $greater  True for greater than, false for less than.
	 * @return bool
	 */
	private static function beyond( mixed $actual, mixed $expected, bool $greater ): bool {
		$left  = self::as_number( $actual );
		$right = self::as_number( $expected );

		if ( null === $left || null === $right ) {
			return false;
		}

		return $greater ? $left > $right : $left < $right;
	}

	/**
	 * Whether a value contains another.
	 *
	 * A list is searched by membership and text is searched by substring: the same
	 * operator over the two shapes a value can have, and the shape is the value's,
	 * not the operator's.
	 *
	 * @param mixed $actual   Value read from the context.
	 * @param mixed $expected What to look for.
	 * @return bool
	 */
	private static function contains( mixed $actual, mixed $expected ): bool {
		if ( is_array( $actual ) ) {
			return self::in_list( $actual, $expected );
		}

		return false !== strpos( self::as_text( $actual ), self::as_text( $expected ) );
	}

	/**
	 * Whether a value is one of a set.
	 *
	 * @param mixed $actual   Value read from the context.
	 * @param mixed $expected Set, as the published list.
	 * @return bool
	 */
	private static function member( mixed $actual, mixed $expected ): bool {
		return self::in_list( is_array( $expected ) ? $expected : array( $expected ), $actual );
	}

	/**
	 * Whether a needle is in a list, comparing the way `same` compares.
	 *
	 * @param array<int, mixed> $entries List.
	 * @param mixed             $needle  Needle.
	 * @return bool
	 */
	private static function in_list( array $entries, mixed $needle ): bool {
		foreach ( $entries as $entry ) {
			if ( self::same( $entry, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A value as text, with the shapes that differ between the two languages fixed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function as_text( mixed $value ): string {
		if ( true === $value ) {
			return '1';
		}

		if ( false === $value ) {
			return '0';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_array( $value ) ) {
			return implode( ',', self::as_list( $value ) );
		}

		return (string) $value;
	}

	/**
	 * A value as a list of text.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string>
	 */
	private static function as_list( mixed $value ): array {
		return array_map(
			static function ( mixed $entry ): string {
				return self::as_text( $entry );
			},
			is_array( $value ) ? array_values( $value ) : array( $value )
		);
	}

	/**
	 * A value as a number, or null when it is not written as one.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function as_number( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return 1 === preg_match( self::NUMBER_PATTERN, $trimmed ) ? (float) $trimmed : null;
	}
}
