<?php
/**
 * The migration layer that upgrades a stored document to the final model.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Sections\ContainerDefinition;

/**
 * Reads a stored document and answers it in the shape the final model defines.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §23 fixes two
 * rules for this layer:
 *
 * 1. **Preserve what is not ambiguous.** Field ids, container ids, historical values,
 *    upload references and order snapshots are never touched here — this class works on
 *    configuration, and rewrites it in place without inventing anything.
 * 2. **Ask when it is ambiguous.** A destination that meant two different places cannot be
 *    chosen for the merchant; the document is refused by name (the validator's
 *    `ambiguous_destination`) and the editor asks. This class converts only what has one
 *    possible meaning.
 *
 * What it converts, and why each conversion is safe:
 *
 * - `admin_customer` → `admin_customer_profile`. The first key was written by an interim
 *   version of the plugin, was never released, and named exactly the panel staff read on
 *   the customer's profile. One key, one meaning, one answer.
 * - a container offered in several destinations → **one container per destination**, the
 *   first keeping the identifier it always had. A container belongs to one destination in
 *   the final model, and duplicating it is what "the same group of fields appears in both
 *   places" always meant. Every binding that named the original keeps naming the variant
 *   for its own destination, so nothing is lost.
 * - `destinations[destination] = link` → `bindings[]`.
 * - `title` → `name`, `location` → `target`, `presentation.show_title` → `show_title` and
 *   `presentation.account.*` → `display_title`/`icon`, so the canonical keys are the ones
 *   stored from then on.
 *
 * It is idempotent: running it on a document it already produced returns the same document
 * and reports no change. That property is what lets it run on every read.
 *
 * @see ROADMAP.md section 4
 */
final class DocumentMigrator {

	/**
	 * Destination keys that name one place under a name this product no longer uses.
	 *
	 * @var array<string, string>
	 */
	private const RENAMED_DESTINATIONS = array(
		'admin_customer' => 'admin_customer_profile',
	);

	/**
	 * Upgrades a stored document to the final model.
	 *
	 * @param array<string, mixed> $document Raw document.
	 * @return array{document: array<string, mixed>, changed: bool, migrations: array<int, string>}
	 */
	public static function migrate( array $document ): array {
		$migrations = array();

		list( $sections, $remap ) = self::containers( $document, $migrations );
		$fields                   = self::fields( $document, $remap, $migrations );

		if ( array() === $migrations ) {
			return array(
				'document'   => $document,
				'changed'    => false,
				'migrations' => array(),
			);
		}

		$document['fields']   = $fields;
		$document['sections'] = $sections;

		return array(
			'document'   => $document,
			'changed'    => true,
			'migrations' => array_values( array_unique( $migrations ) ),
		);
	}

	/**
	 * The containers, one per destination, with the canonical keys.
	 *
	 * @param array<string, mixed> $document   Raw document.
	 * @param array<int, string>   $migrations Migrations applied, by reference.
	 * @return array{0: array<int, mixed>, 1: array<string, string>} Containers and the identifier remap.
	 */
	private static function containers( array $document, array &$migrations ): array {
		$raw   = isset( $document['sections'] ) && is_array( $document['sections'] ) ? $document['sections'] : array();
		$out   = array();
		$remap = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				$out[] = $entry;

				continue;
			}

			$container = ContainerDefinition::from_array( $entry );
			$areas     = array();

			foreach ( $container->destinations() as $area ) {
				$renamed = self::destination( (string) $area, $migrations );

				if ( $renamed !== (string) $area ) {
					$migrations[] = 'destination_renamed';
				}

				$areas[] = $renamed;
			}

			if ( array() === $areas ) {
				$areas = array( $container->destination() );
			}

			if ( count( $areas ) > 1 ) {
				$migrations[] = 'container_split';
			}

			foreach ( $areas as $area ) {
				$variant = $container->for_destination( $area );
				$variant = ContainerDefinition::from_array( $variant->to_array() );

				$canonical                               = self::canonical_container( $variant, $variant->id() );
				$remap[ $area . '|' . $container->id() ] = $variant->id();

				if ( $canonical !== $entry ) {
					$migrations[] = 'container_keys';
				}

				$out[] = $canonical;
			}
		}

		return array( $out, $remap );
	}

	/**
	 * The canonical array of one container variant.
	 *
	 * @param ContainerDefinition $container Container.
	 * @param string              $id        Identifier this variant keeps.
	 * @return array<string, mixed>
	 */
	private static function canonical_container( ContainerDefinition $container, string $id ): array {
		$array       = $container->to_array();
		$array['id'] = $id;

		// The account presentation moves to the container's own keys, which is where the
		// final model keeps it. The nested block stays while the account renderer reads it.
		$account = $container->account();

		if ( '' === $array['display_title'] && isset( $account['menu_label'] ) ) {
			$array['display_title'] = (string) $account['menu_label'];
		}

		if ( '' === $array['icon'] && isset( $account['icon'] ) ) {
			$array['icon'] = (string) $account['icon'];
		}

		return $array;
	}

	/**
	 * The fields, with their destination map read as bindings.
	 *
	 * @param array<string, mixed>  $document   Raw document.
	 * @param array<string, string> $remap      Container identifier remap, by destination.
	 * @param array<int, string>    $migrations Migrations applied, by reference.
	 * @return array<int, mixed>
	 */
	private static function fields( array $document, array $remap, array &$migrations ): array {
		$raw = isset( $document['fields'] ) && is_array( $document['fields'] ) ? $document['fields'] : array();
		$out = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				$out[] = $entry;

				continue;
			}

			$canonical = self::canonical_field( $entry, $remap, $migrations );

			if ( $canonical !== $entry ) {
				$migrations[] = 'field_bindings';
			}

			$out[] = $canonical;
		}

		return $out;
	}

	/**
	 * One field, in the canonical shape.
	 *
	 * A field that already stores its uses is taken at its word: the list is the authority
	 * the map is derived from, and reading the map instead would erase a second use of the
	 * same destination — the very thing the list exists to carry. Its uses are still
	 * followed through the container remap, because a container offered in several
	 * destinations was split by the pass before this one.
	 *
	 * @param array<string, mixed>  $field      Raw definition.
	 * @param array<string, string> $remap      Container identifier remap, by destination.
	 * @param array<int, string>    $migrations Migrations applied, by reference.
	 * @return array<string, mixed>
	 */
	private static function canonical_field( array $field, array $remap, array &$migrations ): array {
		$id = isset( $field['id'] ) ? (string) $field['id'] : '';

		if ( isset( $field['bindings'] ) && is_array( $field['bindings'] ) ) {
			return self::field_with_bindings( $field, $id, $remap, $migrations );
		}

		$destinations = self::effective_destinations( $field );

		$bindings = array();
		$map      = array();
		// Renames this pass noticed; the containers pass already reported them.
		$renames = array();

		foreach ( $destinations as $key => $link ) {
			$destination = self::destination( (string) $key, $renames );

			if ( ! is_array( $link ) ) {
				$map[ $destination ] = $link;

				continue;
			}

			if ( empty( $link['enabled'] ) ) {
				$map[ $destination ] = $link;

				continue;
			}

			$container = isset( $link['section'] ) ? (string) $link['section'] : '';
			$remapped  = $remap[ $destination . '|' . $container ] ?? $container;

			if ( $remapped !== $container ) {
				$link['section'] = $remapped;
			}

			$binding             = FieldBinding::from_link( $id, $destination, $link );
			$bindings[]          = $binding->to_array();
			$map[ $destination ] = $binding->to_link();
		}

		$field['destinations'] = $map;
		$field['bindings']     = $bindings;

		return $field;
	}

	/**
	 * A field that already stores its uses, with both shapes agreeing.
	 *
	 * Two views of one configuration arrive here. The **list** is the authority: it is the
	 * only shape that can carry two uses of the same field in one destination, and reading
	 * the map instead would erase the second of them. The **map** is what the editor writes
	 * today, and it is an edit to the use it describes — one use per destination fits the map
	 * exactly, so for a destination with a single use the map is the newer of the two views
	 * and the use follows it. A destination with more than one use is expressible only in the
	 * list, so there the list decides and the map is its projection.
	 *
	 * Either way the map ends up as the projection of the list, keeping the entries no use
	 * justifies: a link that is off is configuration the merchant typed, and the surfaces
	 * still read it.
	 *
	 * @param array<string, mixed>  $field      Raw definition.
	 * @param string                $id         Field identifier.
	 * @param array<string, string> $remap      Container identifier remap, by destination.
	 * @param array<int, string>    $migrations Migrations applied, by reference.
	 * @return array<string, mixed>
	 */
	private static function field_with_bindings( array $field, string $id, array $remap, array &$migrations ): array {
		$map   = isset( $field['destinations'] ) && is_array( $field['destinations'] ) ? $field['destinations'] : array();
		$uses  = array();
		$tally = array();

		foreach ( array_values( $field['bindings'] ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				// An entry the model cannot read is left where it is, for the validator to
				// refuse: dropping it here would hide what the document says.
				$uses[] = array(
					'raw'         => $raw,
					'destination' => null,
					'binding'     => null,
				);

				continue;
			}

			$destination = isset( $raw['destination'] ) ? (string) $raw['destination'] : '';
			$renamed     = self::destination( $destination, $migrations );

			if ( $renamed !== $destination ) {
				$raw['destination'] = $renamed;
				$destination        = $renamed;
			}

			$container = isset( $raw['container_id'] ) ? (string) $raw['container_id'] : '';
			$remapped  = $remap[ $destination . '|' . $container ] ?? $container;

			if ( $remapped !== $container ) {
				$raw['container_id'] = $remapped;
			}

			$tally[ $destination ] = ( $tally[ $destination ] ?? 0 ) + 1;

			$uses[] = array(
				'raw'         => $raw,
				'destination' => $destination,
				'binding'     => FieldBinding::from_array( $raw ),
			);
		}

		$bindings  = array();
		$projected = array();

		foreach ( $uses as $use ) {
			$binding     = $use['binding'];
			$destination = $use['destination'];

			if (
				null !== $binding &&
				1 === ( $tally[ $destination ] ?? 0 ) &&
				isset( $map[ $destination ] ) &&
				is_array( $map[ $destination ] )
			) {
				$binding = FieldBinding::from_link( $id, (string) $destination, $map[ $destination ] );
			}

			if ( null === $binding ) {
				$bindings[] = $use['raw'];

				continue;
			}

			$bindings[] = $binding->to_array();

			// The projection the surfaces read, refreshed use by use: the last use in a
			// destination is what the map can say, and the list is what says the rest.
			$projected[ (string) $destination ] = $binding->to_link();
		}

		// The map is a projection of the list, so it carries what the list justifies and
		// nothing else: a destination the list no longer uses is not left saying it is on,
		// which is what turning a destination off has to mean. A link that is *off* stays —
		// that is configuration the merchant typed, inert, and still theirs.
		foreach ( $map as $key => $link ) {
			if ( is_array( $link ) && ! empty( $link['enabled'] ) && ! isset( $projected[ (string) $key ] ) ) {
				unset( $map[ $key ] );
			}
		}

		$field['destinations'] = array_merge( $map, $projected );
		$field['bindings']     = $bindings;

		return $field;
	}

	/**
	 * Where a field may be shown, in whichever shape the document stores it.
	 *
	 * A document that carries the `destinations` map is taken as it is. One that carries
	 * only the flat audience map of the oldest versions is converted first, with the same
	 * rule the model itself uses when it reads it: converting the map *after* answering
	 * "no destinations configured" would erase what the store had turned on.
	 *
	 * @param array<string, mixed> $field Raw definition.
	 * @return array<string, mixed>
	 */
	private static function effective_destinations( array $field ): array {
		if ( isset( $field['destinations'] ) && is_array( $field['destinations'] ) ) {
			return $field['destinations'];
		}

		if ( isset( $field['visibility'] ) && is_array( $field['visibility'] ) ) {
			return DefinitionVocabulary::destinations_from_visibility( $field['visibility'] );
		}

		return DefinitionVocabulary::default_destinations();
	}

	/**
	 * One destination key, under the name this version uses.
	 *
	 * @param string             $destination Destination key found in the document.
	 * @param array<int, string> $migrations  Migrations applied, by reference.
	 * @return string
	 */
	private static function destination( string $destination, array &$migrations ): string {
		if ( isset( self::RENAMED_DESTINATIONS[ $destination ] ) ) {
			$migrations[] = 'destination_renamed';

			return self::RENAMED_DESTINATIONS[ $destination ];
		}

		return $destination;
	}
}
