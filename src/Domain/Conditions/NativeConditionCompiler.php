<?php
/**
 * Compiles a condition rule into the checkout's native rule language.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * Translates the declarative tree into the JSON Schema the Blocks checkout already
 * understands.
 *
 * Section 11 asks for exactly this: "Nos Blocks, compilar condições representáveis
 * para os mecanismos nativos de hidden/required, que usam JSON Schema". The native
 * mechanism is `CheckoutFields::is_hidden_field()` and `is_required_field()`, which
 * hand the rule to `Validation::validate_document_object()` with the checkout's
 * document object — so the target language is JSON Schema draft-07 over that
 * document, and the compiler's whole job is to say which rules fit in it.
 *
 * Three decisions shape the result:
 *
 * 1. **All or nothing per field.** A rule is compiled only when every leaf of it is
 *    representable. Compiling the leaves that fit and dropping the rest would not
 *    be a partial translation, it would be a different rule: `all` would become
 *    weaker and `any` would become broader, and the same document would hide
 *    different fields in two checkouts. A rule that cannot be compiled whole is not
 *    compiled at all, and the notes name every leaf that stopped it.
 *
 * 2. **The document decides what exists.** A source is compiled only when the
 *    document object really carries it. `payment_method` and `cart_categories` are
 *    named by section 11 and are not in the document, so they are refused with that
 *    as the reason rather than mapped to something that merely looks similar.
 *
 * 3. **The unit is part of the meaning.** `cart_total` is published in the document
 *    as `total_price` in minor units. A comparison against a value the merchant
 *    typed in the store's currency has to be converted, and the conversion is
 *    carried out here, once, where the currency's decimals are known.
 *
 * What this class does **not** do is decide where a Suite field's value lives in the
 * document. A field reference is a path that depends on the Blocks adapter's
 * location mapping, which belongs to the adapter that registers the field; it is
 * refused here with that as the reason rather than guessed at.
 *
 * @see \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation
 * @see \ROADMAP.md section 11
 * @see docs/adr/ADR-0008-incompatibility-is-not-validation.md
 */
final class NativeConditionCompiler {

	/**
	 * Paths the document object carries, by source key.
	 *
	 * Verified against `DocumentObject::get_data()` in the installed WooCommerce.
	 * `cart.items` holds one product id per unit in the cart, `cart.shipping_rates`
	 * holds the selected rate ids, and `customer.id` is zero for a guest.
	 */
	private const PATHS = array(
		'country'            => array( 'customer', 'billing_address', 'country' ),
		'state'              => array( 'customer', 'billing_address', 'state' ),
		'shipping_method'    => array( 'cart', 'shipping_rates' ),
		'cart_items'         => array( 'cart', 'items' ),
		'cart_total'         => array( 'cart', 'totals', 'total_price' ),
		'customer_logged_in' => array( 'customer', 'id' ),
	);

	/**
	 * The shape each compiled path holds.
	 *
	 * The comparison keywords are not interchangeable: `maxLength` means nothing to
	 * an array and `contains` means nothing to a string, and a compiler that emitted
	 * whichever it knew would produce rules that silently always pass.
	 */
	private const SHAPES = array(
		'country'            => Operator::TYPE_STRING,
		'state'              => Operator::TYPE_STRING,
		'shipping_method'    => Operator::TYPE_LIST,
		'cart_items'         => Operator::TYPE_LIST,
		'cart_total'         => Operator::TYPE_NUMBER,
		'customer_logged_in' => Operator::TYPE_BOOLEAN,
	);

	/**
	 * The value type each compiled path holds, as JSON sees it.
	 *
	 * Not the same question as `SHAPES`, which is about which comparison keywords
	 * apply. A `contains` over `cart.items` is a membership test over a list of
	 * **integers**, and comparing it with the text `'12'` would never match: the
	 * value a merchant typed has to be cast to what the document actually holds.
	 */
	private const VALUE_SHAPES = array(
		'country'            => 'string',
		'state'              => 'string',
		'shipping_method'    => 'string',
		'cart_items'         => 'integer',
		'cart_total'         => 'integer',
		'customer_logged_in' => 'boolean',
	);

	/**
	 * Sources the document object does not carry, with the reason.
	 */
	private const ABSENT = array(
		'payment_method'  => 'the document object does not carry the chosen payment method',
		'cart_categories' => 'the document object carries product identifiers, not the categories they belong to',
		'field'           => 'the path of another field depends on the location the Blocks adapter gives it, which this compiler does not decide',
	);

	/**
	 * Compiles the visibility rule of a definition.
	 *
	 * @param array<string, mixed> $definition Field definition, as stored.
	 * @param int                  $decimals   Decimals of the store currency, for the total.
	 * @return NativeConditionCompilation
	 */
	public function compile( array $definition, int $decimals = 2 ): NativeConditionCompilation {
		$conditions = isset( $definition['conditions'] ) && is_array( $definition['conditions'] ) ? $definition['conditions'] : array();
		$visible    = isset( $conditions['visible'] ) && is_array( $conditions['visible'] ) ? $conditions['visible'] : array();

		if ( array() === $visible ) {
			// A field with no rule is always visible, and there is nothing for the
			// native mechanism to decide. That is not a failure to compile.
			return new NativeConditionCompilation( null, array() );
		}

		$notes = array();
		$node  = $this->compile_node( $visible, $decimals, $notes );

		if ( array() !== $notes ) {
			return new NativeConditionCompilation( null, $notes );
		}

		if ( null === $node ) {
			return new NativeConditionCompilation(
				null,
				array(
					array(
						'source' => 'rule',
						'reason' => 'the rule is not shaped like a group or a comparison',
					),
				)
			);
		}

		return new NativeConditionCompilation( $node );
	}

	/**
	 * Compiles one node, or records why it cannot be.
	 *
	 * @param array<string, mixed>                              $node     Raw rule node.
	 * @param int                                               $decimals Currency decimals.
	 * @param array<int, array{source: string, reason: string}> $notes    Collected notes, by reference.
	 * @return array<string, mixed>|null
	 */
	private function compile_node( array $node, int $decimals, array &$notes ): ?array {
		$children = array_key_exists( 'all', $node ) ? $node['all'] : ( array_key_exists( 'any', $node ) ? $node['any'] : null );

		if ( is_array( $children ) ) {
			$kind = array_key_exists( 'all', $node ) ? 'allOf' : 'anyOf';

			if ( array() === $children ) {
				$notes[] = array(
					'source' => $kind,
					'reason' => 'a group with nothing in it says nothing, and an empty native group validates everything',
				);

				return null;
			}

			$compiled = array();

			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					$notes[] = array(
						'source' => $kind,
						'reason' => 'a child of the group is not a rule',
					);

					return null;
				}

				$part = $this->compile_node( $child, $decimals, $notes );

				if ( null === $part ) {
					return null;
				}

				$compiled[] = $part;
			}

			return array( $kind => $compiled );
		}

		return $this->compile_leaf( $node, $decimals, $notes );
	}

	/**
	 * Compiles one comparison.
	 *
	 * @param array<string, mixed>                              $leaf     Leaf.
	 * @param int                                               $decimals Currency decimals.
	 * @param array<int, array{source: string, reason: string}> $notes    Collected notes, by reference.
	 * @return array<string, mixed>|null
	 */
	private function compile_leaf( array $leaf, int $decimals, array &$notes ): ?array {
		$source_key   = isset( $leaf['source'] ) && is_string( $leaf['source'] ) ? $leaf['source'] : '';
		$operator_key = isset( $leaf['operator'] ) && is_string( $leaf['operator'] ) ? $leaf['operator'] : '';

		if ( '' === $source_key ) {
			$notes[] = array(
				'source' => 'rule',
				'reason' => 'the node names neither a group nor a source',
			);

			return null;
		}

		if ( isset( self::ABSENT[ $source_key ] ) ) {
			$notes[] = array(
				'source' => $source_key,
				'reason' => self::ABSENT[ $source_key ],
			);

			return null;
		}

		if ( ! isset( self::PATHS[ $source_key ], self::SHAPES[ $source_key ] ) ) {
			$notes[] = array(
				'source' => $source_key,
				'reason' => 'the source is not one the document object carries',
			);

			return null;
		}

		$operator = Operators::get( $operator_key );

		if ( null === $operator ) {
			$notes[] = array(
				'source' => $source_key,
				'reason' => sprintf( 'the operator "%s" is not one this store has', $operator_key ),
			);

			return null;
		}

		$shape = self::SHAPES[ $source_key ];
		$value = $leaf[ ConditionTree::KEY_VALUE ] ?? null;

		// The total is published in minor units and the merchant typed a price.
		if ( 'cart_total' === $source_key && $operator->takes_value() ) {
			$value = $this->in_minor_units( $value, $decimals );
		}

		// Whether the customer is logged in is a boolean in the vocabulary and a
		// customer id in the document, so the comparison is about the id: a rule
		// that asked the id to equal `true` would never match anything.
		$schema = 'customer_logged_in' === $source_key
			? $this->logged_in_schema( $operator_key, $value )
			: $this->schema_for( $operator_key, $shape, $value, self::VALUE_SHAPES[ $source_key ] );

		if ( null === $schema ) {
			$notes[] = array(
				'source' => $source_key,
				'reason' => sprintf(
					'"%s" cannot be expressed over a value of type %s',
					$operator->label(),
					$shape
				),
			);

			return null;
		}

		return $this->nest( self::PATHS[ $source_key ], $schema );
	}

	/**
	 * Nests a leaf schema under the path it applies to.
	 *
	 * A document path can be two segments deep or three, and the schema belongs at
	 * the end of it: `cart.total_price` is written as `cart.properties.totals…`,
	 * not as a rule about `cart` as a whole.
	 *
	 * @param array<int, string>   $path   Path segments.
	 * @param array<string, mixed> $schema Leaf schema.
	 * @return array<string, mixed>
	 */
	private function nest( array $path, array $schema ): array {
		$node = $schema;

		for ( $index = count( $path ) - 1; $index >= 1; $index-- ) {
			$node = array(
				'properties' => array(
					$path[ $index ] => $node,
				),
			);
		}

		return array( $path[0] => $node );
	}

	/**
	 * A boolean question about the customer, asked of the customer id.
	 *
	 * The vocabulary has a `customer_logged_in` source and the document has a
	 * numeric `customer.id`, so the translation is not a cast: "is logged in" is
	 * "the id is at least one" and "is not logged in" is "the id is zero". Casting
	 * the boolean into the document would produce a comparison that can never hold,
	 * which is worse than refusing to compile: it would hide a field in one checkout
	 * and not in another, silently.
	 *
	 * @param string $operator Operator key.
	 * @param mixed  $value    Comparison value.
	 * @return array<string, mixed>|null Null when it cannot be expressed.
	 */
	private function logged_in_schema( string $operator, mixed $value ): ?array {
		$about_id = static function ( mixed $entry ): ?array {
			if ( in_array( $entry, array( true, 1, '1', 'true', 'yes' ), true ) ) {
				return array( 'minimum' => 1 );
			}

			if ( in_array( $entry, array( false, 0, '0', 'false', 'no' ), true ) ) {
				return array( 'maximum' => 0 );
			}

			return null;
		};

		if ( 'equals' === $operator || 'not_equals' === $operator ) {
			$inner = $about_id( $value );

			if ( null === $inner ) {
				return null;
			}

			return 'not_equals' === $operator ? array( 'not' => $inner ) : $inner;
		}

		if ( 'in' === $operator || 'not_in' === $operator ) {
			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$parts  = array();

			foreach ( $values as $entry ) {
				$part = $about_id( $entry );

				if ( null === $part ) {
					return null;
				}

				$parts[] = $part;
			}

			if ( array() === $parts ) {
				return null;
			}

			// Asking whether the customer is either logged in or not is asking
			// nothing, and a schema that matches everything is not a rule.
			if ( count( $parts ) > 1 ) {
				return null;
			}

			return 'not_in' === $operator ? array( 'not' => $parts[0] ) : $parts[0];
		}

		return null;
	}

	/**
	 * The JSON Schema keyword one operator becomes over one shape.
	 *
	 * Every keyword here is draft-07, which is what the native validator declares and
	 * what its meta-schema accepts.
	 *
	 * @param string $operator Operator key.
	 * @param string $shape    Shape of the compiled path.
	 * @param mixed  $value    Comparison value.
	 * @param string $holds    Value type the path holds, as JSON sees it.
	 * @return array<string, mixed>|null Null when the pair cannot be expressed.
	 */
	private function schema_for( string $operator, string $shape, mixed $value, string $holds ): ?array {
		$list = Operator::TYPE_LIST === $shape;

		switch ( $operator ) {
			case 'equals':
				$inner = $list
					? array( 'contains' => array( 'const' => $this->as_value( $value, $holds ) ) )
					: array( 'const' => $this->as_value( $value, $holds ) );

				return $inner;
			case 'not_equals':
				$inner = $list
					? array( 'contains' => array( 'const' => $this->as_value( $value, $holds ) ) )
					: array( 'const' => $this->as_value( $value, $holds ) );

				return array( 'not' => $inner );
			case 'contains':
				return $list
					? array( 'contains' => array( 'const' => $this->as_value( $value, $holds ) ) )
					: $this->text_contains( $value );
			case 'not_contains':
				$inner = $list
					? array( 'contains' => array( 'const' => $this->as_value( $value, $holds ) ) )
					: $this->text_contains( $value );

				return null === $inner ? null : array( 'not' => $inner );
			case 'greater_than':
				$number = $this->as_value( $value, $holds );

				return is_int( $number ) || is_float( $number ) ? array( 'exclusiveMinimum' => $number ) : null;
			case 'less_than':
				$number = $this->as_value( $value, $holds );

				return is_int( $number ) || is_float( $number ) ? array( 'exclusiveMaximum' => $number ) : null;
			case 'is_empty':
				$empty = $this->empty_schema( $shape );

				return $empty;
			case 'is_not_empty':
				$empty = $this->empty_schema( $shape );

				return null === $empty ? null : array( 'not' => $empty );
			case 'in':
			case 'not_in':
				// Named `values` and not `list`: the flag above already means "the
				// path holds a list", and shadowing it here silently turned every
				// membership test into a containment test.
				$values = is_array( $value ) ? array_values( $value ) : array( $value );
				$enum   = array();

				foreach ( $values as $entry ) {
					$enum[] = $this->as_value( $entry, $holds );
				}

				if ( array() === $enum ) {
					return null;
				}

				// Over a list, "is one of" asks whether any entry is one of the
				// values — which is what a merchant means by "the shipping method is
				// one of these" when the checkout has more than one package.
				$schema = $list ? array( 'contains' => array( 'enum' => $enum ) ) : array( 'enum' => $enum );

				return 'not_in' === $operator ? array( 'not' => $schema ) : $schema;
		}

		return null;
	}

	/**
	 * "Empty" is not one keyword, because it is not one shape.
	 *
	 * A string is empty when it has no length and a list when it has no entries. An
	 * absent property passes both, which is the same answer the engine gives: a
	 * value that was never submitted is absent, and absent is empty. A number or a
	 * boolean is never empty — `0` and `false` are values — and JSON Schema has no
	 * keyword that says so, which is why those two shapes are refused rather than
	 * approximated.
	 *
	 * @param string $shape Shape of the compiled path.
	 * @return array<string, mixed>|null
	 */
	private function empty_schema( string $shape ): ?array {
		if ( Operator::TYPE_LIST === $shape ) {
			return array( 'maxItems' => 0 );
		}

		if ( Operator::TYPE_STRING === $shape ) {
			return array( 'maxLength' => 0 );
		}

		return null;
	}

	/**
	 * A substring test, escaped so a value cannot become a pattern.
	 *
	 * @param mixed $value Value to look for.
	 * @return array<string, mixed>|null
	 */
	private function text_contains( mixed $value ): ?array {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}

		return array( 'pattern' => preg_quote( (string) $value, '/' ) );
	}

	/**
	 * Coerces a value to what the compiled path actually holds.
	 *
	 * The cast is not cosmetic. The document publishes product identifiers as
	 * integers, so a `const` of `'12'` would never match item `12`; and a boolean
	 * source compared with the text `'1'` would never match `true`.
	 *
	 * @param mixed  $value Value.
	 * @param string $holds Value type.
	 * @return mixed
	 */
	private function as_value( mixed $value, string $holds ): mixed {
		if ( 'integer' === $holds ) {
			return is_numeric( $value ) ? (int) $value : $value;
		}

		if ( 'boolean' === $holds ) {
			if ( is_bool( $value ) ) {
				return $value;
			}

			return in_array( $value, array( '1', 1, 'true', 'yes' ), true );
		}

		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * A price in the minor units the document publishes.
	 *
	 * @param mixed $value    Value.
	 * @param int   $decimals Currency decimals.
	 * @return mixed
	 */
	private function in_minor_units( mixed $value, int $decimals ): mixed {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		return (int) round( ( (float) $value ) * ( 10 ** $decimals ) );
	}
}
