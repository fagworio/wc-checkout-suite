<?php
/**
 * Difference between two stored schema documents.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/**
 * Describes what publishing would change.
 *
 * ROADMAP.md section 428 asks the publication to show differences, and a diff is
 * only useful if it says what a person would recognise. Two consequences shape
 * this class:
 *
 * 1. **Order is reported as order.** A move changes the `position` of every field
 *    below it, so reporting positions would turn one drag into a screen of noise.
 *    Each section whose field order changed is reported once, with the two orders.
 *
 * 2. **The identifier is never a change.** It is what stored fields and orders
 *    point at; a definition whose id changed is a removal and an addition, and
 *    saying so is more honest than reporting a renamed field.
 *
 * The comparison runs on the canonical arrays, not on the objects, so a key added
 * to the definition in a later phase appears in the diff without this class
 * needing to know about it.
 *
 * @see ROADMAP.md sections 4 and 428
 */
final class SchemaDiff {

	/**
	 * Keys compared per definition.
	 *
	 * `position` is excluded because order is reported per section, and `id` is
	 * excluded because it is the identity being matched on.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED_KEYS = array( 'id', 'position' );

	/**
	 * Computes the difference between the published document and the draft.
	 *
	 * @param SchemaDocument $published Currently published document.
	 * @param SchemaDocument $draft     Draft document.
	 * @return array<string, mixed>
	 */
	public static function between( SchemaDocument $published, SchemaDocument $draft ): array {
		$fields   = self::compare_fields( $published->fields(), $draft->fields() );
		$sections = self::compare_sections( $published->sections(), $draft->sections() );

		$order = self::compare_order( $published->fields(), $draft->fields() );

		$empty = array() === $fields['added']
			&& array() === $fields['removed']
			&& array() === $fields['changed']
			&& array() === $sections['added']
			&& array() === $sections['removed']
			&& array() === $sections['changed']
			&& array() === $order
			&& self::normalise( $published->settings() ) === self::normalise( $draft->settings() );

		return array(
			'empty'         => $empty,
			'fields'        => $fields,
			'sections'      => $sections,
			'order'         => $order,
			'settings'      => self::compare_maps( $published->settings(), $draft->settings() ),
			'published'     => array(
				'revision'   => $published->revision(),
				'updated_at' => (string) ( $published->to_array()['updated_at'] ?? '' ),
			),
			'draft'         => array(
				'revision'   => $draft->revision(),
				'updated_at' => (string) ( $draft->to_array()['updated_at'] ?? '' ),
			),
			'total_changes' => self::count_changes( $fields, $sections, $order ),
		);
	}

	/**
	 * Total number of reported changes.
	 *
	 * Reported so the interface can say "3 changes" without recomputing the diff,
	 * and so a test has one number to assert on.
	 *
	 * @param array<string, mixed> $fields   Field comparison.
	 * @param array<string, mixed> $sections Section comparison.
	 * @param array<int, mixed>    $order    Order comparison.
	 * @return int
	 */
	private static function count_changes( array $fields, array $sections, array $order ): int {
		return count( $fields['added'] )
			+ count( $fields['removed'] )
			+ count( $fields['changed'] )
			+ count( $sections['added'] )
			+ count( $sections['removed'] )
			+ count( $sections['changed'] )
			+ count( $order );
	}

	/**
	 * Compares the field lists.
	 *
	 * @param array<int, mixed> $before Published fields.
	 * @param array<int, mixed> $after  Draft fields.
	 * @return array{added: array<int, mixed>, removed: array<int, mixed>, changed: array<int, mixed>}
	 */
	private static function compare_fields( array $before, array $after ): array {
		$by_before = self::index_fields( $before );
		$by_after  = self::index_fields( $after );

		$added   = array();
		$removed = array();
		$changed = array();

		foreach ( $by_after as $id => $field ) {
			if ( ! isset( $by_before[ $id ] ) ) {
				$added[] = self::summarise_field( $field );
			}
		}

		foreach ( $by_before as $id => $field ) {
			if ( ! isset( $by_after[ $id ] ) ) {
				$removed[] = self::summarise_field( $field );

				continue;
			}

			$differences = self::compare_definitions(
				FieldDefinition::from_array( $field )->to_array(),
				FieldDefinition::from_array( $by_after[ $id ] )->to_array()
			);

			if ( array() !== $differences ) {
				$changed[] = array(
					'id'          => $id,
					'label'       => (string) ( $by_after[ $id ]['label'] ?? '' ),
					'differences' => $differences,
				);
			}
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
		);
	}

	/**
	 * Compares the section lists.
	 *
	 * @param array<int, mixed> $before Published sections.
	 * @param array<int, mixed> $after  Draft sections.
	 * @return array{added: array<int, mixed>, removed: array<int, mixed>, changed: array<int, mixed>}
	 */
	private static function compare_sections( array $before, array $after ): array {
		$by_before = self::index_sections( $before );
		$by_after  = self::index_sections( $after );

		$added   = array();
		$removed = array();
		$changed = array();

		foreach ( $by_after as $id => $section ) {
			if ( ! isset( $by_before[ $id ] ) ) {
				$added[] = self::summarise_section( $section );
			}
		}

		foreach ( $by_before as $id => $section ) {
			if ( ! isset( $by_after[ $id ] ) ) {
				$removed[] = self::summarise_section( $section );

				continue;
			}

			$differences = self::compare_definitions(
				SectionDefinition::from_array( $section )->to_array(),
				SectionDefinition::from_array( $by_after[ $id ] )->to_array()
			);

			if ( array() !== $differences ) {
				$changed[] = array(
					'id'          => $id,
					'title'       => (string) ( $by_after[ $id ]['title'] ?? '' ),
					'differences' => $differences,
				);
			}
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
		);
	}

	/**
	 * Reports the sections whose field order changed.
	 *
	 * @param array<int, mixed> $before Published fields.
	 * @param array<int, mixed> $after  Draft fields.
	 * @return array<int, array{section: string, from: array<int, string>, to: array<int, string>}>
	 */
	private static function compare_order( array $before, array $after ): array {
		$from = self::order_by_section( $before );
		$to   = self::order_by_section( $after );

		$changed  = array();
		$sections = array_values( array_unique( array_merge( array_keys( $from ), array_keys( $to ) ) ) );

		sort( $sections );

		foreach ( $sections as $section ) {
			$before_ids = $from[ $section ] ?? array();
			$after_ids  = $to[ $section ] ?? array();

			// Only the identifiers present on both sides are compared. An addition
			// or a removal already appears as one, and counting it again as a
			// reordering would report one decision twice.
			$shared_before = array_values( array_intersect( $before_ids, $after_ids ) );
			$shared_after  = array_values( array_intersect( $after_ids, $before_ids ) );

			if ( $shared_before === $shared_after ) {
				continue;
			}

			$changed[] = array(
				'section' => $section,
				'from'    => $shared_before,
				'to'      => $shared_after,
			);
		}

		return $changed;
	}

	/**
	 * Field identifiers of each section, in position order.
	 *
	 * @param array<int, mixed> $fields Fields.
	 * @return array<string, array<int, string>>
	 */
	private static function order_by_section( array $fields ): array {
		$grouped = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $field );
			$section    = (string) $definition->to_array()['section'];

			$grouped[ $section ][] = array(
				'id'       => $definition->id(),
				'position' => $definition->position(),
			);
		}

		$order = array();

		foreach ( $grouped as $section => $entries ) {
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					$by_position = $a['position'] <=> $b['position'];

					return 0 !== $by_position ? $by_position : strcmp( $a['id'], $b['id'] );
				}
			);

			$order[ $section ] = array_map(
				static function ( array $entry ): string {
					return $entry['id'];
				},
				$entries
			);
		}

		return $order;
	}

	/**
	 * Compares two canonical definitions key by key.
	 *
	 * @param array<string, mixed> $before Before.
	 * @param array<string, mixed> $after  After.
	 * @return array<int, array{key: string, from: mixed, to: mixed}>
	 */
	private static function compare_definitions( array $before, array $after ): array {
		$keys        = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		$differences = array();

		sort( $keys );

		foreach ( $keys as $key ) {
			if ( in_array( (string) $key, self::IGNORED_KEYS, true ) ) {
				continue;
			}

			$from = $before[ $key ] ?? null;
			$to   = $after[ $key ] ?? null;

			if ( self::normalise( $from ) === self::normalise( $to ) ) {
				continue;
			}

			$differences[] = array(
				'key'  => (string) $key,
				'from' => $from,
				'to'   => $to,
			);
		}

		return $differences;
	}

	/**
	 * Compares two maps and reports the keys that differ.
	 *
	 * @param array<string, mixed> $before Before.
	 * @param array<string, mixed> $after  After.
	 * @return array<int, array{key: string, from: mixed, to: mixed}>
	 */
	private static function compare_maps( array $before, array $after ): array {
		return self::compare_definitions( $before, $after );
	}

	/**
	 * Canonical form used for comparison, so key order never counts as a change.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function normalise( mixed $value ): string {
		return (string) wp_json_encode( self::sort_deep( $value ) );
	}

	/**
	 * Sorts a value recursively so two equal maps compare equal.
	 *
	 * @param mixed $value Value.
	 * @return mixed Sorted value.
	 */
	private static function sort_deep( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $entry ) {
			$value[ $key ] = self::sort_deep( $entry );
		}

		return $value;
	}

	/**
	 * Indexes raw fields by identifier, keeping the first entry of a duplicate.
	 *
	 * @param array<int, mixed> $fields Raw fields.
	 * @return array<string, array<string, mixed>>
	 */
	private static function index_fields( array $fields ): array {
		$indexed = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = isset( $field['id'] ) ? (string) $field['id'] : '';

			if ( '' !== $id && ! isset( $indexed[ $id ] ) ) {
				$indexed[ $id ] = $field;
			}
		}

		return $indexed;
	}

	/**
	 * Indexes raw sections by identifier.
	 *
	 * @param array<int, mixed> $sections Raw sections.
	 * @return array<string, array<string, mixed>>
	 */
	private static function index_sections( array $sections ): array {
		$indexed = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$id = isset( $section['id'] ) ? (string) $section['id'] : '';

			if ( '' !== $id && ! isset( $indexed[ $id ] ) ) {
				$indexed[ $id ] = $section;
			}
		}

		return $indexed;
	}

	/**
	 * Short description of a field for a list entry.
	 *
	 * @param array<string, mixed> $field Raw field.
	 * @return array<string, mixed>
	 */
	private static function summarise_field( array $field ): array {
		$definition = FieldDefinition::from_array( $field );

		return array(
			'id'      => $definition->id(),
			'label'   => $definition->label(),
			'type'    => $definition->type(),
			'section' => (string) $definition->to_array()['section'],
			'origin'  => (string) $definition->to_array()['origin'],
		);
	}

	/**
	 * Short description of a section for a list entry.
	 *
	 * @param array<string, mixed> $section Raw section.
	 * @return array<string, mixed>
	 */
	private static function summarise_section( array $section ): array {
		$definition = SectionDefinition::from_array( $section );

		return array(
			'id'       => $definition->id(),
			'title'    => $definition->title(),
			'location' => $definition->location(),
		);
	}
}
