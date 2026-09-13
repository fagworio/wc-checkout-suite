<?php
/**
 * Typed order field values.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * The canonical values of the Suite's fields for one order.
 *
 * Values are typed, not strings. `0`, `0.0`, `false`, an empty string and an empty
 * list are all real answers to a field, and four of them are the ones a naive
 * implementation loses: casting to string turns `false` into `''` and `0` into
 * `'0'`, and an `empty()` check throws away `0`, `'0'`, `false` and `[]` at once.
 * Everything in this class is therefore written to compare with `===` and to test
 * presence with `array_key_exists()` rather than truthiness.
 *
 * What a field can hold is the JSON data model minus objects: `null`, a boolean,
 * a number, a string, or a list of those. A type that returns anything else has
 * broken the value contract from section 6, and its value is left out rather than
 * converted into something that would read as a different answer.
 *
 * @see ROADMAP.md sections 5 and 13
 * @see \docs/adr/ADR-0001-storage-authority.md
 */
final class OrderFieldValues {

	/**
	 * Values, keyed by field identifier.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Values, keyed by field identifier.
	 */
	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * An empty set.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Builds a set from values, keeping only what a field can hold.
	 *
	 * @param array<string, mixed> $values Values, keyed by field identifier.
	 * @return self
	 */
	public static function from_array( array $values ): self {
		$kept = array();

		foreach ( $values as $id => $value ) {
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}

			if ( ! self::is_representable( $value ) ) {
				continue;
			}

			$kept[ $id ] = $value;
		}

		return new self( $kept );
	}

	/**
	 * Builds a set from values, keeping only the fields the Suite owns.
	 *
	 * This is ADR-0001's authority rule expressed once. A field WooCommerce owns
	 * and a Blocks additional field both have their own authority; copying either
	 * into the Suite's storage would create the second version of the truth that
	 * section 25 lists as a high risk. A definition that is not in the published
	 * document is also left out: the Suite stores what its schema defines and
	 * nothing else.
	 *
	 * @param array<string, mixed>             $values      Values, keyed by field identifier.
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return self
	 */
	public static function from_definitions( array $values, array $definitions ): self {
		$owned = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( ! $definition->is_enabled() ) {
				continue;
			}

			if ( 'custom' !== $definition->origin() ) {
				continue;
			}

			if ( ! array_key_exists( $id, $values ) ) {
				continue;
			}

			$owned[ $id ] = $values[ $id ];
		}

		return self::from_array( $owned );
	}

	/**
	 * Every value, keyed by field identifier.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Identifiers in the order they were given.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_keys( $this->values );
	}

	/**
	 * Whether a field has a value in this set.
	 *
	 * Presence, not truthiness: `false`, `0` and `''` are all present values.
	 *
	 * @param string $id Field identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return array_key_exists( $id, $this->values );
	}

	/**
	 * Reads one value.
	 *
	 * @param string $id       Field identifier.
	 * @param mixed  $fallback Returned when the field has no value here.
	 * @return mixed
	 */
	public function get( string $id, mixed $fallback = null ): mixed {
		return array_key_exists( $id, $this->values ) ? $this->values[ $id ] : $fallback;
	}

	/**
	 * Whether there is nothing to store.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->values;
	}

	/**
	 * Whether a stored value is an answer at all.
	 *
	 * The one rule for "the customer answered something", kept here because two readers need
	 * it and neither should own it: the approval flow, which holds an order only when the
	 * field it reviews has an answer, and the reader of the values the platform stored, which
	 * fills in only where the Suite's own payload has none. An empty list is how a file field
	 * says "nothing attached", and an empty string is how a text field says the same.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_answer( mixed $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return true;
	}

	/**
	 * Whether one value is something a field can hold.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	private static function is_representable( mixed $value ): bool {
		if ( null === $value || is_scalar( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $entry ) {
			if ( ! self::is_representable( $entry ) ) {
				return false;
			}
		}

		return true;
	}
}
