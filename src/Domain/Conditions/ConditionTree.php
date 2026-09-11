<?php
/**
 * Condition syntax tree.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * The shape of a rule: groups of groups, ending in leaves.
 *
 * Two kinds of node and no more. A **group** is `all` or `any` over a list of
 * children, which is section 11's AND and OR; a **leaf** names a source, an
 * operator and, when the operator asks for one, a value. Negation is not a third
 * kind of node: it is the negated operators — `not_equals`, `not_contains`,
 * `not_in` — so a rule that hides a field when two things differ says that, rather
 * than saying "not (they are equal)". One way to express a thing is one way to get
 * it wrong.
 *
 * This class owns the **shape** and nothing else, on purpose. The shape is what
 * the validator and the evaluators both have to agree about, and a shape decided
 * in two places is a shape that disagrees the first time one of them is edited.
 * What a node *means* belongs to the evaluator, and whether it is *allowed*
 * belongs to the validator.
 *
 * @see \ROADMAP.md section 11
 */
final class ConditionTree {

	/**
	 * A node that is a group of everything.
	 */
	public const GROUP_ALL = 'all';

	/**
	 * A node that is a group of anything.
	 */
	public const GROUP_ANY = 'any';

	/**
	 * The node is a group.
	 */
	public const SHAPE_GROUP = 'group';

	/**
	 * The node is a leaf.
	 */
	public const SHAPE_LEAF = 'leaf';

	/**
	 * The node is neither.
	 */
	public const SHAPE_INVALID = 'invalid';

	/**
	 * Key a leaf carries its source under.
	 */
	public const KEY_SOURCE = 'source';

	/**
	 * Key a leaf carries its operator under.
	 */
	public const KEY_OPERATOR = 'operator';

	/**
	 * Key a leaf carries its comparison value under.
	 */
	public const KEY_VALUE = 'value';

	/**
	 * Key a leaf carries the field it reads under.
	 */
	public const KEY_FIELD = 'field';

	/**
	 * Constructor.
	 *
	 * @param string               $shape    Group or leaf.
	 * @param string               $group    Group key, when it is a group.
	 * @param array<int, self>     $children Children, when it is a group.
	 * @param array<string, mixed> $leaf     Leaf entries, when it is a leaf.
	 */
	private function __construct(
		private string $shape,
		private string $group,
		private array $children,
		private array $leaf
	) {
	}

	/**
	 * The shape of a raw node.
	 *
	 * One place decides this. A key that is not `all` or `any` is not a group, and
	 * a node that is not a group is a leaf only if it names a source and an
	 * operator; anything else is invalid, and the validator says so rather than
	 * guessing at what was meant.
	 *
	 * @param mixed $node Raw node.
	 * @return string One of the SHAPE_ constants.
	 */
	public static function shape( mixed $node ): string {
		if ( ! is_array( $node ) ) {
			return self::SHAPE_INVALID;
		}

		$group = self::group_key( $node );

		if ( '' !== $group ) {
			return is_array( $node[ $group ] ) ? self::SHAPE_GROUP : self::SHAPE_INVALID;
		}

		if ( isset( $node[ self::KEY_SOURCE ], $node[ self::KEY_OPERATOR ] ) ) {
			return is_string( $node[ self::KEY_SOURCE ] ) && is_string( $node[ self::KEY_OPERATOR ] )
				? self::SHAPE_LEAF
				: self::SHAPE_INVALID;
		}

		return self::SHAPE_INVALID;
	}

	/**
	 * The group key of a raw node, or an empty string.
	 *
	 * @param array<string, mixed> $node Raw node.
	 * @return string
	 */
	private static function group_key( array $node ): string {
		foreach ( array( self::GROUP_ALL, self::GROUP_ANY ) as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Parses a node every one of whose parts the validator has already accepted.
	 *
	 * Returns null for anything malformed rather than throwing: this is called on
	 * data that came out of an option, and a store whose tree cannot be parsed
	 * should evaluate as "no condition" instead of taking the checkout down.
	 *
	 * @param mixed $raw Raw node.
	 * @return self|null
	 */
	public static function parse( mixed $raw ): ?self {
		$shape = self::shape( $raw );

		if ( self::SHAPE_INVALID === $shape ) {
			return null;
		}

		if ( self::SHAPE_LEAF === $shape ) {
			return new self(
				self::SHAPE_LEAF,
				'',
				array(),
				array(
					self::KEY_SOURCE   => (string) $raw[ self::KEY_SOURCE ],
					self::KEY_OPERATOR => (string) $raw[ self::KEY_OPERATOR ],
					self::KEY_FIELD    => isset( $raw[ self::KEY_FIELD ] ) ? (string) $raw[ self::KEY_FIELD ] : '',
					self::KEY_VALUE    => $raw[ self::KEY_VALUE ] ?? null,
				)
			);
		}

		$group    = self::group_key( (array) $raw );
		$children = array();

		foreach ( (array) $raw[ $group ] as $child ) {
			$parsed = self::parse( $child );

			if ( null === $parsed ) {
				return null;
			}

			$children[] = $parsed;
		}

		return new self( self::SHAPE_GROUP, $group, $children, array() );
	}

	/**
	 * Whether this node is a group.
	 *
	 * @return bool
	 */
	public function is_group(): bool {
		return self::SHAPE_GROUP === $this->shape;
	}

	/**
	 * The group key, when this node is a group.
	 *
	 * @return string
	 */
	public function group(): string {
		return $this->group;
	}

	/**
	 * The children, when this node is a group.
	 *
	 * @return array<int, self>
	 */
	public function children(): array {
		return $this->children;
	}

	/**
	 * The leaf entries, when this node is a leaf.
	 *
	 * @return array<string, mixed>
	 */
	public function leaf(): array {
		return $this->leaf;
	}

	/**
	 * Every field this tree reads.
	 *
	 * Used to find cycles and removed fields, which are properties of the whole
	 * document rather than of one rule.
	 *
	 * @return array<int, string>
	 */
	public function references(): array {
		if ( ! $this->is_group() ) {
			return '' === ( $this->leaf[ self::KEY_FIELD ] ?? '' )
				? array()
				: array( (string) $this->leaf[ self::KEY_FIELD ] );
		}

		$references = array();

		foreach ( $this->children as $child ) {
			$references = array_merge( $references, $child->references() );
		}

		return array_values( array_unique( $references ) );
	}

	/**
	 * The number of nodes, so a tree that is too deep or too wide can be refused.
	 *
	 * @return int
	 */
	public function size(): int {
		if ( ! $this->is_group() ) {
			return 1;
		}

		$size = 1;

		foreach ( $this->children as $child ) {
			$size += $child->size();
		}

		return $size;
	}

	/**
	 * The depth of the tree.
	 *
	 * @return int
	 */
	public function depth(): int {
		if ( ! $this->is_group() ) {
			return 1;
		}

		$deepest = 0;

		foreach ( $this->children as $child ) {
			$deepest = max( $deepest, $child->depth() );
		}

		return 1 + $deepest;
	}

	/**
	 * Exports the tree as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		if ( ! $this->is_group() ) {
			$leaf = array(
				self::KEY_SOURCE   => $this->leaf[ self::KEY_SOURCE ],
				self::KEY_OPERATOR => $this->leaf[ self::KEY_OPERATOR ],
			);

			if ( '' !== ( $this->leaf[ self::KEY_FIELD ] ?? '' ) ) {
				$leaf[ self::KEY_FIELD ] = $this->leaf[ self::KEY_FIELD ];
			}

			if ( array_key_exists( self::KEY_VALUE, $this->leaf ) && null !== $this->leaf[ self::KEY_VALUE ] ) {
				$leaf[ self::KEY_VALUE ] = $this->leaf[ self::KEY_VALUE ];
			}

			return $leaf;
		}

		return array(
			$this->group => array_values(
				array_map(
					static function ( self $child ): array {
						return $child->to_array();
					},
					$this->children
				)
			),
		);
	}
}
