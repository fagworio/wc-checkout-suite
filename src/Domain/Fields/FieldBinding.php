<?php
/**
 * One use of a field: the field, the container it sits in, and how it appears there.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * The link between a field and a container.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.3 makes the
 * **binding** its own concept, and for a reason the model it replaces could not express:
 * one definition of a field may be used in several places, with its own position, its own
 * visible/editable decision, its own required or label override, its own permissions and
 * its own conditions. The old shape held exactly one link per destination, which is why a
 * field could not appear twice under the same email, or be shown to the customer and only
 * listed to the staff with different titles.
 *
 * A binding is stored as a list per field (`bindings[]`); a document written before the
 * split carries a map of destination links instead, and {@see self::from_link()} reads one
 * of those as a binding so both shapes answer the same questions.
 *
 * @see ROADMAP.md section 4
 */
final class FieldBinding {

	/**
	 * Constructor.
	 *
	 * @param string               $field_id             Field this binding belongs to.
	 * @param string               $container_id         Container it sits in.
	 * @param string               $destination          Destination it appears in.
	 * @param string               $id                   Binding identifier.
	 * @param int|null             $position             Ordering position inside the container, or null
	 *                                                   when the use did not configure one.
	 * @param bool                 $visible              Whether it is shown there.
	 * @param bool                 $editable             Whether the person reading it may change the value.
	 * @param bool                 $required_override    Whether it becomes required there.
	 * @param string               $label_override       Title shown there, when it differs from the field's.
	 * @param string               $description_override Help shown there, when it differs.
	 * @param array<int, string>   $permissions          What that destination may do with the value.
	 * @param array<string, mixed> $conditions          Conditions that decide visibility there.
	 */
	public function __construct(
		private string $field_id,
		private string $container_id,
		private string $destination,
		private string $id = '',
		private ?int $position = null,
		private bool $visible = true,
		private bool $editable = false,
		private bool $required_override = false,
		private string $label_override = '',
		private string $description_override = '',
		private array $permissions = array(),
		private array $conditions = array()
	) {
		if ( '' === $this->id ) {
			$this->id = self::identifier_for( $this->field_id, $this->destination, $this->container_id );
		}
	}

	/**
	 * The deterministic identifier of one use of a field.
	 *
	 * A destination link written before bindings existed had no identifier of its own, and
	 * the migration must be idempotent: running it twice has to produce the same document.
	 * Deriving the identifier from the three things that identify the use — field,
	 * destination and container — is what makes that true.
	 *
	 * @param string $field_id     Field identifier.
	 * @param string $destination  Destination key.
	 * @param string $container_id Container identifier.
	 * @return string
	 */
	public static function identifier_for( string $field_id, string $destination, string $container_id ): string {
		return $field_id . '@' . $destination . ( '' !== $container_id ? '/' . $container_id : '' );
	}

	/**
	 * Reads one destination link as a binding.
	 *
	 * The link's `mode` said how the field appeared there — `edit` or `view` — and the
	 * binding says it with two flags: everything is visible, and only `edit` is editable.
	 * The link's `actions` are the binding's permissions: showing a file name, opening it,
	 * taking a copy, approving it or sending a new version are decisions about a value, and
	 * they belong to the use rather than to the definition.
	 *
	 * @param string               $field_id    Field identifier.
	 * @param string               $destination Destination key.
	 * @param array<string, mixed> $link        Destination link.
	 * @return self
	 */
	public static function from_link( string $field_id, string $destination, array $link ): self {
		$mode = isset( $link['mode'] ) ? (string) $link['mode'] : 'edit';

		return new self(
			$field_id,
			isset( $link['section'] ) ? (string) $link['section'] : '',
			$destination,
			isset( $link['id'] ) ? (string) $link['id'] : '',
			isset( $link['position'] ) && is_numeric( $link['position'] ) ? (int) $link['position'] : null,
			! empty( $link['enabled'] ),
			'view' !== $mode,
			! empty( $link['required'] ),
			isset( $link['title'] ) ? (string) $link['title'] : '',
			isset( $link['description'] ) ? (string) $link['description'] : '',
			isset( $link['actions'] ) && is_array( $link['actions'] )
				? array_values( array_map( 'strval', $link['actions'] ) )
				: array(),
			isset( $link['conditions'] ) && is_array( $link['conditions'] ) ? $link['conditions'] : array()
		);
	}

	/**
	 * Builds a binding from a plain array, filling documented defaults.
	 *
	 * @param array<string, mixed> $data Raw binding.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['field_id'] ) ? (string) $data['field_id'] : '',
			isset( $data['container_id'] ) ? (string) $data['container_id'] : '',
			isset( $data['destination'] ) ? (string) $data['destination'] : '',
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['position'] ) && is_numeric( $data['position'] ) ? (int) $data['position'] : null,
			! isset( $data['visible'] ) || (bool) $data['visible'],
			! isset( $data['editable'] ) || (bool) $data['editable'],
			! empty( $data['required_override'] ),
			isset( $data['label_override'] ) ? (string) $data['label_override'] : '',
			isset( $data['description_override'] ) ? (string) $data['description_override'] : '',
			isset( $data['permissions'] ) && is_array( $data['permissions'] )
				? array_values( array_map( 'strval', $data['permissions'] ) )
				: array(),
			isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? $data['conditions'] : array()
		);
	}

	/**
	 * Binding identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Field this binding belongs to.
	 *
	 * @return string
	 */
	public function field_id(): string {
		return $this->field_id;
	}

	/**
	 * Container this binding sits in.
	 *
	 * @return string
	 */
	public function container_id(): string {
		return $this->container_id;
	}

	/**
	 * Destination this binding appears in.
	 *
	 * @return string
	 */
	public function destination(): string {
		return $this->destination;
	}

	/**
	 * Ordering position inside the container.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position ?? 0;
	}

	/**
	 * Whether this use configured its own position.
	 *
	 * A use that did not keeps the field's own order, which is what a link that only
	 * turned a destination on always did.
	 *
	 * @return bool
	 */
	public function has_position(): bool {
		return null !== $this->position;
	}

	/**
	 * Whether the value is shown there.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return $this->visible;
	}

	/**
	 * Whether the person reading it may change it.
	 *
	 * @return bool
	 */
	public function is_editable(): bool {
		return $this->editable;
	}

	/**
	 * Whether the binding makes the field required there.
	 *
	 * @return bool
	 */
	public function is_required_override(): bool {
		return $this->required_override;
	}

	/**
	 * Title configured for this use, or an empty string.
	 *
	 * @return string
	 */
	public function label_override(): string {
		return $this->label_override;
	}

	/**
	 * Help text configured for this use, or an empty string.
	 *
	 * @return string
	 */
	public function description_override(): string {
		return $this->description_override;
	}

	/**
	 * What this destination may do with the value.
	 *
	 * @return array<int, string>
	 */
	public function permissions(): array {
		return $this->permissions;
	}

	/**
	 * Conditions that decide whether it appears.
	 *
	 * @return array<string, mixed>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * The title to show for this use, falling back to the field's own label.
	 *
	 * @param string $label Field label.
	 * @return string
	 */
	public function title_for( string $label ): string {
		return '' !== trim( $this->label_override ) ? $this->label_override : $label;
	}

	/**
	 * Exports the binding as the canonical array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                   => $this->id,
			'field_id'             => $this->field_id,
			'container_id'         => $this->container_id,
			'destination'          => $this->destination,
			'position'             => $this->position(),
			'visible'              => $this->visible,
			'editable'             => $this->editable,
			'required_override'    => $this->required_override,
			'label_override'       => $this->label_override,
			'description_override' => $this->description_override,
			'permissions'          => $this->permissions,
			'conditions'           => $this->conditions,
		);
	}

	/**
	 * The same binding as the destination link it replaced.
	 *
	 * The surfaces and the editor still read the map of destination links while they
	 * migrate to bindings, and this is the projection they read. It is derived here so the
	 * two shapes cannot disagree: `mode` says `edit` or `view`, and `actions` are the
	 * permissions.
	 *
	 * @return array<string, mixed>
	 */
	public function to_link(): array {
		return array(
			'enabled'  => $this->visible,
			'section'  => $this->container_id,
			'title'    => $this->label_override,
			'position' => $this->position(),
			'mode'     => $this->editable ? 'edit' : 'view',
			'actions'  => $this->permissions,
		);
	}
}
