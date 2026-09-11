<?php
/**
 * Condition rule validation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Whether a rule is one the store can keep.
 *
 * Two layers, because they know different things.
 *
 * **A rule on its own** is checked against the vocabularies: the shape, the
 * operator, the source, whether the operator takes a value and whether that value
 * has a type the operator can compare against. That is everything that can be
 * decided without looking at the rest of the document, and it is what
 * {@see \WCCheckoutSuite\Domain\Fields\DefinitionValidator} asks for.
 *
 * **Rules together** are checked against each other: a condition naming a field
 * that does not exist, and a condition naming a field that eventually names it
 * back. Only the document knows those, so the document asks.
 *
 * A cycle is refused rather than tolerated. Two fields whose visibility depends on
 * each other have no answer — not a wrong answer, none — and an engine that
 * guessed one would show whichever field it happened to evaluate first. Section 11
 * asks for cycles to be detected, and the only useful moment to detect one is
 * before it is stored.
 *
 * @see \ROADMAP.md section 11
 */
final class ConditionValidator {

	/**
	 * Deepest a tree may be.
	 *
	 * A limit rather than a guess: a tree deep enough to matter is a tree nobody
	 * can read, and an unbounded one is a way to make the checkout slow.
	 */
	public const MAX_DEPTH = 8;

	/**
	 * Most nodes a tree may hold.
	 */
	public const MAX_NODES = 100;

	/**
	 * Validates the rules of one field.
	 *
	 * @param string $field_id Field the rules belong to.
	 * @param mixed  $rules    Declarative rules.
	 * @return ValidationResult
	 */
	public static function validate_rules( string $field_id, mixed $rules ): ValidationResult {
		$result = ValidationResult::valid();

		if ( ! is_array( $rules ) ) {
			return ValidationResult::invalid(
				'invalid_conditions',
				__( 'The conditions of a field must be a rule, not a value of another kind.', 'wc-checkoutsuite' ),
				array( 'field' => $field_id )
			);
		}

		// An empty condition list is the ordinary case: a field that is always
		// there. Everything below this line is about rules that were written.
		$visible = $rules['visible'] ?? null;

		if ( null === $visible || array() === $visible ) {
			return $result;
		}

		return self::walk( $field_id, $visible, 'visible' );
	}

	/**
	 * Validates one rule tree.
	 *
	 * @param string $field_id Field the rules belong to.
	 * @param mixed  $node     Raw node.
	 * @param string $path     Where in the tree this node is.
	 * @return ValidationResult
	 */
	private static function walk( string $field_id, mixed $node, string $path ): ValidationResult {
		$shape = ConditionTree::shape( $node );

		if ( ConditionTree::SHAPE_INVALID === $shape ) {
			return ValidationResult::invalid(
				'invalid_condition_node',
				sprintf(
					/* translators: %s: path inside the rule */
					__( 'The rule at %s is neither a group nor a comparison.', 'wc-checkoutsuite' ),
					$path
				),
				array(
					'field' => $field_id,
					'path'  => $path,
				)
			);
		}

		if ( ConditionTree::SHAPE_GROUP === $shape ) {
			return self::walk_group( $field_id, (array) $node, $path );
		}

		return self::walk_leaf( $field_id, (array) $node, $path );
	}

	/**
	 * Validates a group node.
	 *
	 * @param string               $field_id Field the rules belong to.
	 * @param array<string, mixed> $node     Raw node.
	 * @param string               $path     Path.
	 * @return ValidationResult
	 */
	private static function walk_group( string $field_id, array $node, string $path ): ValidationResult {
		$group = array_key_exists( ConditionTree::GROUP_ALL, $node )
			? ConditionTree::GROUP_ALL
			: ConditionTree::GROUP_ANY;

		$children = (array) $node[ $group ];

		if ( array() === $children ) {
			return ValidationResult::invalid(
				'empty_condition_group',
				sprintf(
					/* translators: %s: path inside the rule */
					__( 'The group at %s has no conditions in it.', 'wc-checkoutsuite' ),
					$path
				),
				array(
					'field' => $field_id,
					'path'  => $path,
				)
			);
		}

		$result = ValidationResult::valid();

		foreach ( array_values( $children ) as $index => $child ) {
			$result = $result->merge( self::walk( $field_id, $child, $path . '.' . $group . '.' . (int) $index ) );
		}

		return $result;
	}

	/**
	 * Validates a leaf node.
	 *
	 * @param string               $field_id Field the rules belong to.
	 * @param array<string, mixed> $node     Raw node.
	 * @param string               $path     Path.
	 * @return ValidationResult
	 */
	private static function walk_leaf( string $field_id, array $node, string $path ): ValidationResult {
		$source_key   = (string) $node[ ConditionTree::KEY_SOURCE ];
		$operator_key = (string) $node[ ConditionTree::KEY_OPERATOR ];
		$context      = array(
			'field' => $field_id,
			'path'  => $path,
		);

		$source = Sources::get( $source_key );

		if ( null === $source ) {
			return ValidationResult::invalid(
				'unknown_condition_source',
				sprintf(
					/* translators: %s: source key */
					__( 'The condition reads "%s", which is not something this store can look at.', 'wc-checkoutsuite' ),
					$source_key
				),
				$context
			);
		}

		$operator = Operators::get( $operator_key );

		if ( null === $operator ) {
			return ValidationResult::invalid(
				'unknown_condition_operator',
				sprintf(
					/* translators: %s: operator key */
					__( 'The condition uses the operator "%s", which does not exist.', 'wc-checkoutsuite' ),
					$operator_key
				),
				$context
			);
		}

		$result = ValidationResult::valid();

		if ( $source->is_reference() ) {
			$referenced = isset( $node[ ConditionTree::KEY_FIELD ] ) ? (string) $node[ ConditionTree::KEY_FIELD ] : '';

			if ( '' === $referenced ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'condition_field_missing',
						__( 'The condition reads another field but does not say which one.', 'wc-checkoutsuite' ),
						$context
					)
				);
			} elseif ( $referenced === $field_id ) {
				// The shortest cycle there is, and the one worth its own message:
				// a field that is visible when it is visible.
				$result = $result->merge(
					ValidationResult::invalid(
						'condition_self_reference',
						__( 'A field cannot depend on itself.', 'wc-checkoutsuite' ),
						$context
					)
				);
			}
		} elseif ( ! $operator->accepts_source( $source->type() ) ) {
			// The other half of the same rule, and the one a catalogue source can be
			// judged on where it stands: "Country is greater than 5" is a comparison
			// with no meaning, and the operator says so itself by not listing the
			// type of what it is asked to read.
			//
			// This belongs here and not only in the editor's offer list. A closed
			// vocabulary is closed on both sides — the set the editor offers and the
			// set the validator accepts have to be the same set, which is what
			// ADR-0007 asks for — and until this check existed the validator accepted
			// rules the editor could not produce.
			$result = $result->merge(
				ValidationResult::invalid(
					'condition_source_incompatible',
					sprintf(
						/* translators: 1: operator label, 2: source label, 3: value type */
						__( 'The operator "%1$s" cannot read %2$s, which holds a value of type %3$s.', 'wc-checkoutsuite' ),
						$operator->label(),
						$source->label(),
						$source->type()
					),
					array_merge( $context, array( 'sourceType' => $source->type() ) )
				)
			);
		}

		if ( ! $operator->takes_value() ) {
			if ( array_key_exists( ConditionTree::KEY_VALUE, $node ) && null !== $node[ ConditionTree::KEY_VALUE ] ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'condition_value_unexpected',
						sprintf(
							/* translators: %s: operator key */
							__( 'The operator "%s" asks a question about what is there and takes no value.', 'wc-checkoutsuite' ),
							$operator_key
						),
						$context
					)
				);
			}

			return $result;
		}

		if ( ! array_key_exists( ConditionTree::KEY_VALUE, $node ) ) {
			return $result->merge(
				ValidationResult::invalid(
					'condition_value_missing',
					sprintf(
						/* translators: %s: operator key */
						__( 'The operator "%s" needs something to compare against.', 'wc-checkoutsuite' ),
						$operator_key
					),
					$context
				)
			);
		}

		$value = $node[ ConditionTree::KEY_VALUE ];
		$type  = self::value_type( $value );

		if ( null === $type ) {
			return $result->merge(
				ValidationResult::invalid(
					'condition_value_type',
					__( 'A condition compares against text, a number, a boolean or a list, and nothing else.', 'wc-checkoutsuite' ),
					$context
				)
			);
		}

		if ( ! $operator->accepts_value( $type ) ) {
			return $result->merge(
				ValidationResult::invalid(
					'condition_value_incompatible',
					sprintf(
						/* translators: 1: operator key, 2: value type */
						__( 'The operator "%1$s" cannot compare against a value of type %2$s.', 'wc-checkoutsuite' ),
						$operator_key,
						$type
					),
					array_merge( $context, array( 'valueType' => $type ) )
				)
			);
		}

		return $result;
	}

	/**
	 * The type of a comparison value.
	 *
	 * @param mixed $value Value.
	 * @return string|null Null when nothing can be compared against it.
	 */
	public static function value_type( mixed $value ): ?string {
		if ( is_bool( $value ) ) {
			return Operator::TYPE_BOOLEAN;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return Operator::TYPE_NUMBER;
		}

		if ( is_string( $value ) ) {
			return Operator::TYPE_STRING;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( $value as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				return null;
			}
		}

		return Operator::TYPE_LIST;
	}

	/**
	 * Validates the rules of a whole document.
	 *
	 * Three questions only the document can answer: whether a rule names a field
	 * that exists, whether the operator can read what that field holds, and
	 * whether two fields end up depending on each other.
	 *
	 * @param array<int, array<string, mixed>> $fields Raw field definitions.
	 * @return ValidationResult
	 */
	public static function validate_document( array $fields ): ValidationResult {
		$result    = ValidationResult::valid();
		$types     = array();
		$graphs    = array();
		$documents = array();

		foreach ( $fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id ) {
				continue;
			}

			$types[ $id ]     = self::definition_type( $definition );
			$documents[ $id ] = $definition;
			$graphs[ $id ]    = self::references( $raw );
		}

		foreach ( $graphs as $id => $references ) {
			foreach ( $references as $reference ) {
				if ( ! isset( $types[ $reference['field'] ] ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'condition_field_unknown',
							sprintf(
								/* translators: 1: field id, 2: referenced field id */
								__( 'The conditions of "%1$s" read the field "%2$s", which is not in the schema.', 'wc-checkoutsuite' ),
								(string) $id,
								(string) $reference['field']
							),
							array(
								'field' => (string) $id,
								'reads' => (string) $reference['field'],
							)
						)
					);
				}
			}
		}

		$result = $result->merge( self::validate_operator_types( $graphs, $types ) );

		return $result->merge( self::validate_acyclic( $graphs ) );
	}

	/**
	 * Whether every operator can read what the field it names holds.
	 *
	 * This is the half of "operatoros tipados" that only the document can check:
	 * "greater than" on a checkbox is a rule with no meaning, and it can only be
	 * seen by resolving the field the rule reads.
	 *
	 * @param array<string, array<int, array{path: string, field: string, operator: string}>> $graphs References by field.
	 * @param array<string, string>                                                           $types  Value type by field.
	 * @return ValidationResult
	 */
	private static function validate_operator_types( array $graphs, array $types ): ValidationResult {
		$result = ValidationResult::valid();

		foreach ( $graphs as $id => $references ) {
			foreach ( $references as $reference ) {
				$operator = Operators::get( $reference['operator'] );
				$type     = $types[ $reference['field'] ] ?? '';

				if ( null === $operator || '' === $type ) {
					// An unknown operator and an unknown field are reported by the
					// two checks above; this one is about the combination.
					continue;
				}

				if ( ! $operator->accepts_source( $type ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'condition_source_incompatible',
							sprintf(
								/* translators: 1: operator key, 2: field id, 3: value type */
								__( 'The operator "%1$s" cannot read the field "%2$s", which holds a value of type %3$s.', 'wc-checkoutsuite' ),
								$reference['operator'],
								(string) $reference['field'],
								$type
							),
							array(
								'field' => (string) $id,
								'path'  => $reference['path'],
							)
						)
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Whether the fields form a dependency cycle.
	 *
	 * Depth-first with three colours: a field still on the stack that is reached
	 * again is a cycle. Reported on the field the walk started from, which is the
	 * one a merchant would have been editing.
	 *
	 * @param array<string, array<int, array{path: string, field: string, operator: string}>> $graphs References by field.
	 * @return ValidationResult
	 */
	private static function validate_acyclic( array $graphs ): ValidationResult {
		$result  = ValidationResult::valid();
		$done    = array();
		$onstack = array();

		$visit = function ( string $id, array $trail ) use ( &$visit, &$done, &$onstack, &$result, $graphs ): void {
			if ( isset( $done[ $id ] ) ) {
				return;
			}

			if ( isset( $onstack[ $id ] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'condition_cycle',
						sprintf(
							/* translators: 1: field id, 2: the chain of fields */
							__( 'The conditions of "%1$s" depend on themselves, through %2$s.', 'wc-checkoutsuite' ),
							$trail[0] ?? $id,
							implode( ' -> ', array_merge( $trail, array( $id ) ) )
						),
						array( 'field' => $trail[0] ?? $id )
					)
				);

				return;
			}

			$onstack[ $id ] = true;

			foreach ( $graphs[ $id ] ?? array() as $reference ) {
				if ( isset( $graphs[ $reference['field'] ] ) ) {
					$visit( $reference['field'], array_merge( $trail, array( $id ) ) );
				}
			}

			unset( $onstack[ $id ] );

			$done[ $id ] = true;
		};

		foreach ( array_keys( $graphs ) as $id ) {
			$visit( (string) $id, array() );
		}

		return $result;
	}

	/**
	 * Every field reference a definition's conditions make.
	 *
	 * @param array<string, mixed> $raw Raw definition.
	 * @return array<int, array{path: string, field: string, operator: string}>
	 */
	private static function references( array $raw ): array {
		$visible = $raw['conditions']['visible'] ?? null;

		if ( ! is_array( $visible ) ) {
			return array();
		}

		$tree = ConditionTree::parse( $visible );

		if ( null === $tree ) {
			return array();
		}

		$found = array();

		$collect = function ( ConditionTree $node, string $path ) use ( &$collect, &$found ): void {
			if ( ! $node->is_group() ) {
				$leaf = $node->leaf();

				if ( 'field' === ( $leaf[ ConditionTree::KEY_SOURCE ] ?? '' ) ) {
					$found[] = array(
						'path'     => $path,
						'field'    => (string) ( $leaf[ ConditionTree::KEY_FIELD ] ?? '' ),
						'operator' => (string) ( $leaf[ ConditionTree::KEY_OPERATOR ] ?? '' ),
					);
				}

				return;
			}

			foreach ( $node->children() as $index => $child ) {
				$collect( $child, $path . '.' . $node->group() . '.' . (int) $index );
			}
		};

		$collect( $tree, 'visible' );

		return $found;
	}

	/**
	 * The value type a field holds, from the type it is built on.
	 *
	 * The registry knows better than a table here would, but asking it would make
	 * this class depend on the registries, and a rule about types is a rule about
	 * the value: a number, a boolean, a list or text. The mapping is the one the
	 * field types themselves declare through `valueSchema()`.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return string
	 */
	private static function definition_type( FieldDefinition $definition ): string {
		$schemas = array(
			'number'   => Operator::TYPE_NUMBER,
			'checkbox' => Operator::TYPE_BOOLEAN,
		);

		if ( isset( $schemas[ $definition->type() ] ) ) {
			return $schemas[ $definition->type() ];
		}

		// A choice field that accepts several options holds a list, and so does a
		// file field with more than one upload. Everything else holds text.
		$multiple = array( 'multiselect', 'checkbox-group' );

		return in_array( $definition->type(), $multiple, true )
			? Operator::TYPE_LIST
			: Operator::TYPE_STRING;
	}
}
