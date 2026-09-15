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

			$canonical = self::canonical_field( $entry, $remap );

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
	 * @param array<string, mixed>  $field Raw definition.
	 * @param array<string, string> $remap Container identifier remap, by destination.
	 * @return array<string, mixed>
	 */
	private static function canonical_field( array $field, array $remap ): array {
		$id           = isset( $field['id'] ) ? (string) $field['id'] : '';
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
