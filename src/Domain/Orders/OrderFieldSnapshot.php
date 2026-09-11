<?php
/**
 * The historical record stored with an order's field values.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * What an order remembers about the fields it was captured with.
 *
 * An order has to stay readable after the schema moves on. A merchant renames a
 * label, changes what an option means, or takes a field out of the form
 * altogether; the orders placed before that keep the values they collected, and
 * those values must still say what they were. Rewriting the historic orders to
 * follow the change is what section 13 forbids, so the record travels with the
 * order instead.
 *
 * It is deliberately minimal — label, type and the option labels. Section 13
 * asks for "labels, options and formatters" and this carries the first two. A
 * mask or a preset is a *rendering* rule and belongs to the layer that renders
 * (F05); storing one here would be a promise nothing reads.
 *
 * Two things it is not:
 *
 * - It is not a copy of the definition. Nothing that only affects future
 *   behaviour — requiredness, conditions, width, validators, storage policy — is
 *   here, because none of it changes how a stored value reads.
 * - It is not authoritative over the schema. It describes what *this order* was
 *   captured with; a later order gets its own.
 *
 * @see ROADMAP.md section 13
 * @see \docs/adr/ADR-0001-storage-authority.md
 */
final class OrderFieldSnapshot {

	/**
	 * Entries, keyed by field identifier.
	 *
	 * @var array<string, array{label: string, type: string, options: array<string, string>}>
	 */
	private array $entries;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{label: string, type: string, options: array<string, string>}> $entries Entries.
	 */
	private function __construct( array $entries ) {
		$this->entries = $entries;
	}

	/**
	 * An empty snapshot.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Records the fields among the given identifiers.
	 *
	 * The identifiers come in already filtered to the fields the Suite owns, so
	 * the authority rule is applied once, where the values are filtered, and not
	 * a second time here.
	 *
	 * @param array<int, string>               $ids         Field identifiers to record.
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return self
	 */
	public static function from_definitions( array $ids, array $definitions ): self {
		$wanted = array_flip( $ids );
		$by_id  = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( ! isset( $wanted[ $id ] ) || isset( $by_id[ $id ] ) ) {
				continue;
			}

			$by_id[ $id ] = array(
				'label'   => $definition->label(),
				'type'    => $definition->type(),
				'options' => $definition->options(),
			);
		}

		return new self( $by_id );
	}

	/**
	 * Rebuilds a snapshot from what was decoded out of storage.
	 *
	 * The stored payload cannot be trusted to have the shape it was written with:
	 * it is JSON written by an older build, by a different plugin, or by nobody at
	 * all. Each entry is rebuilt from scratch and anything that is not a label, a
	 * type and a map of strings is dropped, so a malformed snapshot degrades to
	 * "this order remembers less" instead of propagating nonsense into a reading.
	 *
	 * @param mixed $data Decoded snapshot.
	 * @return self
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) ) {
			return self::none();
		}

		$entries = array();

		foreach ( $data as $id => $entry ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $entry ) ) {
				continue;
			}

			if ( ! isset( $entry['label'], $entry['type'] ) || ! is_string( $entry['label'] ) || ! is_string( $entry['type'] ) ) {
				continue;
			}

			$entries[ $id ] = array(
				'label'   => $entry['label'],
				'type'    => $entry['type'],
				'options' => self::strings( $entry['options'] ?? array() ),
			);
		}

		return new self( $entries );
	}

	/**
	 * Every entry.
	 *
	 * @return array<string, array{label: string, type: string, options: array<string, string>}>
	 */
	public function all(): array {
		return $this->entries;
	}

	/**
	 * Whether the snapshot remembers nothing.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->entries;
	}

	/**
	 * One entry, or null when this snapshot does not know the field.
	 *
	 * @param string $id Field identifier.
	 * @return array{label: string, type: string, options: array<string, string>}|null
	 */
	public function entry( string $id ): ?array {
		return $this->entries[ $id ] ?? null;
	}

	/**
	 * Keeps only the entries of a map that are strings, keyed by string.
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string, string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();

		foreach ( $value as $key => $entry ) {
			if ( is_string( $key ) && is_string( $entry ) ) {
				$strings[ $key ] = $entry;
			}
		}

		return $strings;
	}
}
