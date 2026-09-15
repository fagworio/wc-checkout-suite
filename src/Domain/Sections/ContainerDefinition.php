<?php
/**
 * Canonical container definition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Sections;

/**
 * Where a group of fields is shown, and how.
 *
 * The document's own word for this used to be *section*, because the only place it could
 * live was the checkout. `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais`
 * §3.2 fixes the final name and shape: a **container** belongs to **one destination**, and
 * carries the presentation of that place — the title it shows (or hides), the title it
 * displays, its icon, where it is inserted (`target`), and its own settings.
 *
 * Two things are deliberately preserved from the model it replaces:
 *
 * 1. **The identifier.** Stored fields and bindings point at it, so it is permanent: a
 *    renamed container must never orphan what it groups.
 * 2. **The historical keys, as a read projection.** `title`, `areas` and `location` are
 *    emitted alongside the canonical keys so the surfaces that still read them keep
 *    working while they migrate to `name()`, `destination()` and `target()`. They are
 *    derived on the way out, never stored as independent state, so there is one truth.
 *
 * A container written before a single destination existed may still name several areas
 * (`areas`). It is read as: the first area is *the* destination, and the rest are the other
 * places the same group is offered in — which is what the migration layer splits into one
 * container per destination when it can do so without losing anything (§23.2).
 *
 * The constructor is part of the contract with {@see SectionDefinition}, which is the
 * historical name of this same container: `from_array()` builds `static`, and a subclass
 * that changed the constructor would break it.
 *
 * @phpstan-consistent-constructor
 *
 * @see ROADMAP.md section 4
 */
class ContainerDefinition {

	/**
	 * Constructor.
	 *
	 * @param string                 $id           Permanent identifier.
	 * @param string                 $name         Name the merchant gave the container.
	 * @param string                 $destination  The one destination it belongs to.
	 * @param array<string,mixed>    $presentation Destination-specific presentation.
	 * @param int                    $position     Ordering position among containers.
	 * @param bool                   $enabled      Whether it is offered at all.
	 * @param bool                   $show_title   Whether the title is shown where it appears.
	 * @param string                 $display_title Title shown, when it differs from the name.
	 * @param string                 $description  Optional explanation.
	 * @param string                 $icon         Icon key.
	 * @param string                 $target       Where it is inserted in that destination.
	 * @param array<string,mixed>    $settings     Per-container settings.
	 * @param string                 $location     Logical checkout location (historical).
	 * @param array<int,string>|null $areas     Destinations offered: `null` derives it from the
	 *                                          destination, an empty array means offered nowhere.
	 */
	public function __construct(
		private string $id = '',
		private string $name = '',
		private string $destination = 'checkout',
		private array $presentation = array(),
		private int $position = 0,
		private bool $enabled = true,
		private bool $show_title = true,
		private string $display_title = '',
		private string $description = '',
		private string $icon = '',
		private string $target = '',
		private array $settings = array(),
		private string $location = 'order',
		private ?array $areas = null
	) {
		if ( null === $this->areas ) {
			// No list was given, so the container is offered where it belongs.
			$this->areas = array( '' === $this->destination ? 'checkout' : $this->destination );
		}

		if ( '' === $this->destination && array() !== $this->areas ) {
			// A container written before a single destination existed is edited from the
			// first place it was offered in.
			$this->destination = (string) $this->areas[0];
		}
	}

	/**
	 * Builds a container from a plain array, filling documented defaults.
	 *
	 * Both shapes are accepted: the canonical one this class writes, and the one stored by
	 * every version before it. Reading is tolerant; writing is canonical.
	 *
	 * @param array<string, mixed> $data Raw container.
	 * @return static
	 */
	public static function from_array( array $data ): static {
		$given = isset( $data['areas'] ) && is_array( $data['areas'] );
		$areas = $given ? array_values( array_map( 'strval', $data['areas'] ) ) : null;

		$destination = isset( $data['destination'] ) ? (string) $data['destination'] : '';

		if ( '' === $destination && null !== $areas && array() !== $areas ) {
			// A container written before a single destination existed was offered in the
			// areas it listed; the first one is where it is edited from now on.
			$destination = $areas[0];
		}

		$presentation = isset( $data['presentation'] ) && is_array( $data['presentation'] )
			? $data['presentation']
			: array();

		$show_title = isset( $data['show_title'] )
			? (bool) $data['show_title']
			: ( ! isset( $presentation['show_title'] ) || (bool) $presentation['show_title'] );

		$location = isset( $data['location'] ) ? (string) $data['location'] : 'order';
		$target   = isset( $data['target'] ) ? (string) $data['target'] : '';

		return new static(
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['name'] ) ? (string) $data['name'] : ( isset( $data['title'] ) ? (string) $data['title'] : '' ),
			$destination,
			$presentation,
			isset( $data['position'] ) ? (int) $data['position'] : 0,
			! isset( $data['enabled'] ) || (bool) $data['enabled'],
			$show_title,
			isset( $data['display_title'] ) ? (string) $data['display_title'] : '',
			isset( $data['description'] ) ? (string) $data['description'] : '',
			isset( $data['icon'] ) ? (string) $data['icon'] : '',
			$target,
			isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			$location,
			$areas
		);
	}

	/**
	 * The one destination this container belongs to.
	 *
	 * @return string
	 */
	public function destination(): string {
		return $this->destination;
	}

	/**
	 * Every destination this container is offered in.
	 *
	 * One, for anything written from now on. More than one only while a document written
	 * before the split is still being read — and the editor shows them instead of asking
	 * the merchant to choose them again. Empty means the container is offered nowhere,
	 * which the validator refuses: it would be configuration nothing can reach.
	 *
	 * @return array<int, string>
	 */
	public function destinations(): array {
		return $this->areas ?? array();
	}

	/**
	 * Whether this container may be offered in one destination.
	 *
	 * @param string $destination Destination key.
	 * @return bool
	 */
	public function is_offered_in( string $destination ): bool {
		return in_array( $destination, $this->destinations(), true );
	}

	/**
	 * The destinations this container is offered in.
	 *
	 * Kept under its historical name because the validator, the editor and the surfaces
	 * read it; it is the same list as {@see self::destinations()}.
	 *
	 * @return array<int, string>
	 */
	public function areas(): array {
		return $this->destinations();
	}

	/**
	 * The name the merchant gave the container.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The name, under the name every reader used before the final model.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->name;
	}

	/**
	 * Whether this container is offered at all.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Whether the title is shown where the container appears.
	 *
	 * @return bool
	 */
	public function show_title(): bool {
		return $this->show_title;
	}

	/**
	 * Whether the title is shown, under its historical name.
	 *
	 * @return bool
	 */
	public function shows_title(): bool {
		return $this->show_title;
	}

	/**
	 * The title actually displayed, which may differ from the name.
	 *
	 * @return string
	 */
	public function display_title(): string {
		return '' !== $this->display_title ? $this->display_title : $this->name;
	}

	/**
	 * Icon key.
	 *
	 * @return string
	 */
	public function icon(): string {
		return $this->icon;
	}

	/**
	 * Where the container is inserted in its destination.
	 *
	 * For a checkout container the insertion point *is* the logical location, which is
	 * what every version before this one stored; for the other destinations it is the
	 * place named by the container's own settings.
	 *
	 * @return string
	 */
	public function target(): string {
		return '' !== $this->target ? $this->target : $this->location;
	}

	/**
	 * Logical checkout location, kept for the adapters that insert the section.
	 *
	 * @return string
	 */
	public function location(): string {
		return $this->location;
	}

	/**
	 * Per-container settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Returns presentation details that apply to one destination.
	 *
	 * @return array<string,mixed> Presentation data.
	 */
	public function presentation(): array {
		return $this->presentation;
	}

	/**
	 * Returns My Account endpoint settings.
	 *
	 * @return array<string,mixed> Account presentation data.
	 */
	public function account(): array {
		return isset( $this->presentation['account'] ) && is_array( $this->presentation['account'] )
			? $this->presentation['account']
			: array();
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
	 * Optional explanation.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Ordering position among containers.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * The same container, offered in exactly one destination.
	 *
	 * Used by the migration layer when a stored container named several destinations: it
	 * cannot be one container any more, so it becomes one per destination — the first
	 * keeping the identifier it always had, the others deriving theirs from it.
	 *
	 * @param string $destination Destination key.
	 * @return static
	 */
	public function for_destination( string $destination ): static {
		$copy = clone $this;

		$copy->destination = $destination;
		$copy->areas       = array( $destination );

		if ( $destination !== $this->destination ) {
			$copy->id = $this->id . '__' . $destination;
		}

		return $copy;
	}

	/**
	 * Exports the container as an array.
	 *
	 * The canonical keys come first. `title`, `areas` and `location` follow them as the
	 * read projection the surfaces still use; they are derived here, so a single write
	 * cannot leave the two shapes disagreeing.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'name'          => $this->name,
			'destination'   => $this->destination,
			'position'      => $this->position,
			'enabled'       => $this->enabled,
			'show_title'    => $this->show_title,
			'display_title' => $this->display_title,
			'description'   => $this->description,
			'icon'          => $this->icon,
			'target'        => $this->target(),
			'settings'      => $this->settings,
			'presentation'  => $this->presentation,
			// Read projection for the surfaces and validators that still ask by these names.
			'title'         => $this->name,
			'location'      => $this->location,
			'areas'         => $this->destinations(),
		);
	}
}
