<?php
/**
 * One field value as it reads on an order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

/**
 * A stored value together with what is needed to make sense of it.
 *
 * The value is typed exactly as it was stored. The label, the type and the option
 * labels are the ones in force when the order was placed, read from the order's
 * own snapshot when it has one — which is what keeps "renaming a field does not
 * rename history" true. When the snapshot does not know the field, the current
 * schema answers, and when neither does, the entry says so and still carries the
 * value: an order is never unreadable, only less labelled.
 *
 * `source()` reports where the label and type came from, and `is_current()`
 * whether the schema in force today still agrees with them. The two are separate
 * on purpose. A renamed field is `snapshot` and current — the label shown is the
 * historical one and nothing is wrong. A retyped field is `snapshot` and not
 * current, and that is the case a reader must not paper over by formatting an old
 * value with a new type.
 *
 * @see ROADMAP.md sections 13 and 14
 * @see \docs/adr/ADR-0001-storage-authority.md
 */
final class OrderFieldEntry {

	/**
	 * Where the label and type came from.
	 *
	 * `snapshot` — the order's own record. `schema` — the schema in force today,
	 * because the order has no snapshot entry for this field. `unlabelled` —
	 * neither, so the field identifier is all there is.
	 */
	public const SOURCE_SNAPSHOT = 'snapshot';

	/**
	 * Source: the current published schema.
	 */
	public const SOURCE_SCHEMA = 'schema';

	/**
	 * Source: nothing knows this field.
	 */
	public const SOURCE_UNLABELLED = 'unlabelled';

	/**
	 * Constructor.
	 *
	 * @param string                $id      Field identifier.
	 * @param string                $label   Label as it was when captured.
	 * @param string                $type    Type as it was when captured, or '' when unknown.
	 * @param mixed                 $value   Stored value, unchanged.
	 * @param array<string, string> $options Option labels as they were when captured.
	 * @param string                $source  Where the label and type came from.
	 * @param bool                  $current Whether the published schema still agrees.
	 */
	public function __construct(
		private string $id,
		private string $label,
		private string $type,
		private mixed $value,
		private array $options,
		private string $source,
		private bool $current
	) {
	}

	/**
	 * Field identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Label as it was when the order was placed.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Type as it was when the order was placed.
	 *
	 * Empty when nothing can say what the field was. A caller must not format a
	 * value whose type is unknown, which is why this is empty rather than a
	 * convenient guess at `text`.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The stored value, with its type.
	 *
	 * @return mixed
	 */
	public function value(): mixed {
		return $this->value;
	}

	/**
	 * Option labels as they were when the order was placed.
	 *
	 * This is what turns a stored key into what the customer chose. An option key
	 * is stable and its label is not, so a key whose label changed since reads
	 * here with the label it had.
	 *
	 * @return array<string, string>
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Where the label and type came from.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Whether the published schema still agrees with this entry.
	 *
	 * False when the field has been removed from the schema or its type has
	 * changed since. It does not mean the entry is wrong: it means the schema has
	 * moved, and the historical record is what is being read.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		return $this->current;
	}
}
