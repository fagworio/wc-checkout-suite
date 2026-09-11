<?php
/**
 * Canonical section definition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Sections;

/**
 * Description of one section of the checkout.
 *
 * ROADMAP.md section 4 lists what a section carries: a stable id, a title, a
 * description, a position, a logical location, rules and an insertion mapping.
 * The id, title, description, position and location live here. The rules are the
 * conditions engine (F06) and the insertion mapping is the adapter's business
 * (F04), so neither is represented as a field that nothing yet reads — an
 * unread key is a promise the code does not keep.
 *
 * The identifier is permanent for the same reason a field's is: it is what stored
 * fields point at, and renaming a title must never orphan them.
 *
 * @see ROADMAP.md section 4
 */
final class SectionDefinition {

	/**
	 * Constructor.
	 *
	 * @param string $id          Permanent identifier.
	 * @param string $title       Translatable title.
	 * @param string $description Optional explanation.
	 * @param int    $position    Ordering position among sections.
	 * @param string $location    Logical location, one of SectionLocations.
	 */
	public function __construct(
		private string $id,
		private string $title,
		private string $description,
		private int $position,
		private string $location
	) {
	}

	/**
	 * Builds a section from a plain array, filling documented defaults.
	 *
	 * @param array<string, mixed> $data Raw section.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['title'] ) ? (string) $data['title'] : '',
			isset( $data['description'] ) ? (string) $data['description'] : '',
			isset( $data['position'] ) ? (int) $data['position'] : 0,
			isset( $data['location'] ) ? (string) $data['location'] : 'order'
		);
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
	 * Translatable title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
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
	 * Ordering position among sections.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Logical location.
	 *
	 * @return string
	 */
	public function location(): string {
		return $this->location;
	}

	/**
	 * Exports the section as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'title'       => $this->title,
			'description' => $this->description,
			'position'    => $this->position,
			'location'    => $this->location,
		);
	}
}
