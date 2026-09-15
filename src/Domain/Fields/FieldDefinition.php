<?php
/**
 * Canonical field definition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Canonical description of one checkout field.
 *
 * The identifier is permanent and the storage key is stable: renaming a label
 * never renames the stored key, and changing the type or the normalizer is a
 * migration, not a dropdown change.
 *
 * @see \ROADMAP.md section 4
 */
final class FieldDefinition {

	/**
	 * Constructor.
	 *
	 * @param string                           $id                 Permanent identifier.
	 * @param string                           $integration_id     Stable integration key, e.g. `wc-checkoutsuite/billing-document`.
	 * @param string                           $origin             `core` or `custom`.
	 * @param string                           $type               Registered field type key.
	 * @param string|null                      $preset             Preset key, when the field was created from one.
	 * @param string                           $label              Translatable label.
	 * @param string                           $description        Help text shown under the field.
	 * @param string                           $section            Section identifier.
	 * @param bool                             $enabled            Whether the field is active.
	 * @param bool                             $required           Whether the field is required.
	 * @param int                              $position           Ordering position.
	 * @param array<string, int>               $layout             Width per viewport.
	 * @param array<string, mixed>             $settings           Per-type settings.
	 * @param array<string, mixed>|null        $mask               Mask reference.
	 * @param string|null                      $normalizer         Normalizer key.
	 * @param array<int, array<string, mixed>> $validators         Validator references.
	 * @param array<string, mixed>             $conditions         Visibility and requirement rules.
	 * @param string                           $hidden_value_policy `discard` or `preserve`.
	 * @param array<string, mixed>             $storage            Storage scope and sensitivity.
	 * @param int                              $schema_version     Definition schema version.
	 * @param array<string, mixed>             $destinations       Where the answer may be shown, per destination.
	 * @param array<string, mixed>|null        $approval           Optional approval flow, or null.
	 * @param string                           $collection_surface Where the field is collected.
	 * @param array<string, bool>              $sync               Which ways a customer value flows.
	 * @param array<int, mixed>                $bindings           Every use of the field, as stored.
	 * @param bool                             $canonical          Whether the document stored `bindings`.
	 */
	public function __construct(
		private string $id,
		private string $integration_id,
		private string $origin,
		private string $type,
		private ?string $preset,
		private string $label,
		private string $description,
		private string $section,
		private bool $enabled,
		private bool $required,
		private int $position,
		private array $layout,
		private array $settings,
		private ?array $mask,
		private ?string $normalizer,
		private array $validators,
		private array $conditions,
		private string $hidden_value_policy,
		private array $storage,
		private int $schema_version,
		private array $destinations = array(),
		private ?array $approval = null,
		private string $collection_surface = 'checkout',
		private array $sync = array(),
		private array $bindings = array(),
		private bool $canonical = false
	) {
	}

	/**
	 * Where the answer may be shown, from the raw definition.
	 *
	 * Three cases, in order: a document that carries `destinations` is taken as it
	 * is; a document carrying the legacy flat map is migrated, so a store upgrading
	 * does not lose what it had configured; a document carrying neither has **no
	 * destination enabled**, which is the rule ROADMAP.md section 4 states — nothing
	 * appears anywhere without explicit configuration.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return array<string, mixed>
	 */
	/**
	 * Which ways a customer's value flows, read from a stored definition.
	 *
	 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §10.4 lists the
	 * directions separately and says not to assume bidirectional synchronisation. Two of them are
	 * decisions this key carries:
	 *
	 * - `to_checkout` — **perfil → checkout**: the checkout starts with the value the customer
	 *   already has on their profile.
	 * - `from_checkout` — **checkout → perfil**: what the customer types at the checkout is kept
	 *   on their profile for the next order.
	 *
	 * The third direction — **Minha Conta ↔ perfil** — is not a key here: the two surfaces read and
	 * write one stored value, so who may write it is a decision of each *use*, taken by the
	 * binding's `editable`. A use that only reads is a surface that cannot write back.
	 *
	 * A document written before this key existed gets both directions off: a store that never asked
	 * for a value to travel keeps behaving exactly as it did.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return array<string, bool>
	 */
	private static function sync_from( array $data ): array {
		$raw = isset( $data['sync'] ) && is_array( $data['sync'] ) ? $data['sync'] : array();

		return array(
			'to_checkout'   => ! empty( $raw['to_checkout'] ),
			'from_checkout' => ! empty( $raw['from_checkout'] ),
		);
	}

	/**
	 * The uses a stored definition carries, when it carries the final model.
	 *
	 * The list is kept as it was stored, entries that are not maps included: reading is
	 * where an entry the model cannot use is dropped, and refusing it is the validator's
	 * job — a definition that dropped it here would make a document the store does not
	 * understand look like a document with one use fewer.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return array<int, mixed>
	 */
	private static function bindings_from( array $data ): array {
		if ( ! isset( $data['bindings'] ) || ! is_array( $data['bindings'] ) ) {
			return array();
		}

		return array_values( $data['bindings'] );
	}

	/**
	 * Where the answer may be shown, in whichever shape the document stores it.
	 *
	 * A document that carries the `destinations` map is taken as it is; one that carries
	 * only the flat audience map of the oldest versions is migrated, so a store upgrading
	 * does not lose what it had configured.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return array<string, mixed>
	 */
	private static function destinations_from( array $data ): array {
		if ( isset( $data['destinations'] ) && is_array( $data['destinations'] ) ) {
			return $data['destinations'];
		}

		if ( isset( $data['visibility'] ) && is_array( $data['visibility'] ) ) {
			return DefinitionVocabulary::destinations_from_visibility( $data['visibility'] );
		}

		return DefinitionVocabulary::default_destinations();
	}

	/**
	 * Builds a definition from a plain array, filling documented defaults.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$id    = isset( $data['id'] ) ? (string) $data['id'] : '';
		$type  = isset( $data['type'] ) ? (string) $data['type'] : '';
		$label = isset( $data['label'] ) ? (string) $data['label'] : '';

		$layout = isset( $data['layout'] ) && is_array( $data['layout'] ) ? $data['layout'] : array();

		return new self(
			$id,
			isset( $data['integration_id'] ) ? (string) $data['integration_id'] : $id,
			isset( $data['origin'] ) ? (string) $data['origin'] : 'custom',
			$type,
			isset( $data['preset'] ) ? (string) $data['preset'] : null,
			$label,
			isset( $data['description'] ) ? (string) $data['description'] : '',
			isset( $data['section'] ) ? (string) $data['section'] : 'order',
			! isset( $data['enabled'] ) || (bool) $data['enabled'],
			isset( $data['required'] ) && (bool) $data['required'],
			isset( $data['position'] ) ? (int) $data['position'] : 0,
			array(
				'desktop' => isset( $layout['desktop'] ) ? (int) $layout['desktop'] : 12,
				'tablet'  => isset( $layout['tablet'] ) ? (int) $layout['tablet'] : 12,
				'mobile'  => isset( $layout['mobile'] ) ? (int) $layout['mobile'] : 12,
			),
			isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			isset( $data['mask'] ) && is_array( $data['mask'] ) ? $data['mask'] : null,
			isset( $data['normalizer'] ) ? (string) $data['normalizer'] : null,
			isset( $data['validators'] ) && is_array( $data['validators'] ) ? array_values( $data['validators'] ) : array(),
			isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? $data['conditions'] : array(),
			isset( $data['hidden_value_policy'] ) ? (string) $data['hidden_value_policy'] : DefinitionVocabulary::DEFAULT_HIDDEN_VALUE_POLICY,
			isset( $data['storage'] ) && is_array( $data['storage'] )
				? $data['storage']
				: DefinitionVocabulary::default_storage( true ),
			isset( $data['schema_version'] ) ? (int) $data['schema_version'] : 1,
			self::destinations_from( $data ),
			isset( $data['approval'] ) && is_array( $data['approval'] ) ? $data['approval'] : null,
			isset( $data['collection_surface'] ) ? (string) $data['collection_surface'] : 'checkout',
			self::sync_from( $data ),
			self::bindings_from( $data ),
			// Only a document that carries the list is canonical. A canonical document
			// with nothing bound carries an empty list, and that is a decision too.
			isset( $data['bindings'] ) && is_array( $data['bindings'] )
		);
	}

	/**
	 * Where this answer may be shown, per destination.
	 *
	 * @return array<string, mixed>
	 */
	public function destinations(): array {
		return $this->destinations;
	}

	/**
	 * Whether one destination is enabled for this field.
	 *
	 * A field may be bound into the same destination more than once (§3.3), so the
	 * question is answered by the bindings: there is at least one for that destination.
	 *
	 * @param string $destination Destination key.
	 * @return bool
	 */
	public function shows_in( string $destination ): bool {
		return array() !== $this->bindings_for( $destination );
	}

	/**
	 * Every use of this field, in the final model.
	 *
	 * A document written before bindings existed carries a map of destination links
	 * instead; each *enabled* link is one use, and it is read through
	 * {@see FieldBinding::from_link()} so both shapes answer the same questions. The order
	 * is stable — by destination, then by position — because a projection that reorders
	 * itself between reads is a projection nobody can test.
	 *
	 * @return array<int, FieldBinding>
	 */
	public function bindings(): array {
		if ( ! $this->canonical ) {
			$derived = array();

			foreach ( $this->destinations as $destination => $link ) {
				if ( ! is_array( $link ) || empty( $link['enabled'] ) ) {
					continue;
				}

				$derived[] = FieldBinding::from_link( $this->id, (string) $destination, $link );
			}

			usort( $derived, static fn( FieldBinding $a, FieldBinding $b ): int => $a->destination() <=> $b->destination() );

			return $derived;
		}

		$bindings = array();

		foreach ( $this->bindings as $raw ) {
			if ( is_array( $raw ) ) {
				$bindings[] = FieldBinding::from_array( $raw );
			}
		}

		return $bindings;
	}

	/**
	 * The uses of this field in one destination.
	 *
	 * @param string $destination Destination key.
	 * @return array<int, FieldBinding>
	 */
	public function bindings_for( string $destination ): array {
		return array_values(
			array_filter(
				$this->bindings(),
				static fn( FieldBinding $binding ): bool => $binding->destination() === $destination
			)
		);
	}

	/**
	 * The stored list of uses, before it is read as objects.
	 *
	 * {@see self::bindings()} answers with objects and drops an entry that is not a map,
	 * because a surface has nothing to draw from one. A validator has the opposite duty:
	 * an entry it cannot read is a document that says something the store does not
	 * understand, and it is refused rather than dropped. That is why the raw list is
	 * reachable here — and why it is empty for a document that does not store one.
	 *
	 * @return array<int, mixed>
	 */
	public function raw_bindings(): array {
		return $this->canonical ? $this->bindings : array();
	}

	/**
	 * Whether the stored definition carries the final model.
	 *
	 * It decides what {@see self::to_array()} writes: a document keeps the shape it was
	 * written in until the migration layer converts it, so an editor that still edits the
	 * destination map cannot end up with a canonical list that disagrees with it.
	 *
	 * @return bool
	 */
	public function is_canonical(): bool {
		return $this->canonical;
	}

	/**
	 * The optional approval flow, or null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	public function approval(): ?array {
		return $this->approval;
	}

	/**
	 * Permanent identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Key used to integrate with external systems.
	 *
	 * @return string
	 */
	public function integration_id(): string {
		return $this->integration_id;
	}

	/**
	 * Where the field comes from.
	 *
	 * `core` means the definition overrides a field WooCommerce owns, `custom`
	 * means the Suite owns it. The distinction decides who persists the value, so
	 * it is read through the model rather than from the raw array: the model is
	 * the only place that knows an omitted origin means `custom`.
	 *
	 * @return string
	 */
	public function origin(): string {
		return $this->origin;
	}

	/**
	 * Registered type key.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Preset key, when any.
	 *
	 * @return string|null
	 */
	public function preset(): ?string {
		return $this->preset;
	}

	/**
	 * The declared options as `value => label`.
	 *
	 * Only the choice types declare options, so this is empty for everything else.
	 * It exists because three callers now need the same map — the adapter that
	 * renders a select, the validator that decides whether a submitted key is
	 * allowed, and the snapshot that has to remember what a key meant — and a set
	 * of choices read three different ways is three chances to disagree.
	 *
	 * A bare string in the list is its own label, which is the shape the choice
	 * type already accepts.
	 *
	 * @return array<string, string>
	 */
	public function options(): array {
		$declared = $this->settings['options'] ?? null;

		if ( ! is_array( $declared ) ) {
			return array();
		}

		$options = array();

		foreach ( $declared as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) ) {
				$value = (string) $option['value'];

				$options[ $value ] = isset( $option['label'] ) ? (string) $option['label'] : $value;

				continue;
			}

			if ( is_string( $option ) ) {
				$options[ $option ] = $option;
			}
		}

		return $options;
	}

	/**
	 * Translatable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Help text shown under the field.
	 *
	 * ROADMAP.md section 7 requires descriptions and section 4 repeats it in the
	 * definition's own list of properties, but the definition had nowhere to put
	 * one. It is optional and defaults to an empty string, so a document written
	 * before this key existed still reads correctly.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Per-type settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Validator references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function validators(): array {
		return $this->validators;
	}

	/**
	 * Width per viewport.
	 *
	 * @return array<string, int>
	 */
	public function layout(): array {
		return $this->layout;
	}

	/**
	 * Ordering position.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Whether the field is active.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Whether the field is required.
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * Returns where the customer enters this field's value.
	 *
	 * @return string Collection surface.
	 */
	public function collection_surface(): string {
		return $this->collection_surface;
	}

	/**
	 * Which ways a customer's value flows (§10.4).
	 *
	 * @return array<string, bool>
	 */
	public function sync(): array {
		return array(
			'to_checkout'   => ! empty( $this->sync['to_checkout'] ),
			'from_checkout' => ! empty( $this->sync['from_checkout'] ),
		);
	}

	/**
	 * Whether the checkout starts with the value the customer already has.
	 *
	 * @return bool
	 */
	public function prefills_checkout(): bool {
		return ! empty( $this->sync['to_checkout'] );
	}

	/**
	 * Whether what the customer types at the checkout is kept on their profile.
	 *
	 * @return bool
	 */
	public function writes_back_to_customer(): bool {
		return ! empty( $this->sync['from_checkout'] );
	}

	/**
	 * Exports the definition in its canonical array shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'integration_id'      => $this->integration_id,
			'origin'              => $this->origin,
			'type'                => $this->type,
			'preset'              => $this->preset,
			'label'               => $this->label,
			'description'         => $this->description,
			'section'             => $this->section,
			'enabled'             => $this->enabled,
			'required'            => $this->required,
			'position'            => $this->position,
			'layout'              => $this->layout,
			'settings'            => $this->settings,
			'mask'                => $this->mask,
			'normalizer'          => $this->normalizer,
			'validators'          => $this->validators,
			'conditions'          => $this->conditions,
			'hidden_value_policy' => $this->hidden_value_policy,
			'storage'             => $this->storage,
			// A canonical document writes its bindings and the map derived from them. A
			// document written before the split keeps the map it has — and does **not**
			// gain an empty `bindings` key for saying so, because an empty list is a
			// document that says "this field is used nowhere", which is a different
			// statement. It gains bindings when the migration layer converts it.
			'destinations'        => $this->exported_destinations(),
			'approval'            => $this->approval,
			'collection_surface'  => $this->collection_surface,
			'sync'                => $this->sync,
			'schema_version'      => $this->schema_version,
		) + ( $this->canonical ? array( 'bindings' => $this->exported_bindings() ) : array() );
	}

	/**
	 * The bindings as they are written back.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function exported_bindings(): array {
		return array_values( array_map( static fn( FieldBinding $binding ): array => $binding->to_array(), $this->bindings() ) );
	}

	/**
	 * The destination map the surfaces read, derived from the bindings.
	 *
	 * @return array<string, mixed>
	 */
	private function exported_destinations(): array {
		if ( ! $this->canonical ) {
			return $this->destinations;
		}

		$map = $this->destinations;

		foreach ( $this->bindings() as $binding ) {
			$map[ $binding->destination() ] = $binding->to_link();
		}

		return $map;
	}
}
