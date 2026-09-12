<?php
/**
 * What one area shows, in the order and under the titles it was configured with.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/**
 * The document, read the way one area reads it.
 *
 * Every surface — the order screen, the customer's page, both e-mails — asks the same
 * question and has to answer it the same way: which entries does this area show, under
 * which section, with which title, and in which order? The answer is the *link* the
 * merchant configured for that destination, not the field's own position, because the
 * same field may be third on the order screen and first in the customer's e-mail.
 *
 * Nothing here decides whether a value may be shown: that is the destination link and
 * the file permissions, and the callers ask before they get here. What this class does
 * is the rest of the sentence in section 14 — the section, the title and the order.
 *
 * @see ROADMAP.md section 14
 */
final class AreaProjection {

	/**
	 * Groups the entries one area shows.
	 *
	 * A group is a section: it carries the section's identifier, the title the document
	 * gave it, and the entries in the order the links configured. An entry with no
	 * section in its link falls into its field's own section, which is how a link that
	 * only turns a destination on keeps behaving the way it did before.
	 *
	 * @param array<int, OrderFieldEntry>      $entries     Entries the caller already allowed.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @param array<int, array<string, mixed>> $sections    Published sections.
	 * @param string                           $destination Destination key.
	 * @return array<int, array{id: string, title: string, position: int, fields: array<int, array{entry: OrderFieldEntry, title: string, position: int}>}>
	 */
	public static function group( array $entries, array $definitions, array $sections, string $destination ): array {
		$declared = array();
		$order    = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );

			if ( '' !== $definition->id() ) {
				$declared[ $definition->id() ] = $definition;
			}
		}

		foreach ( $sections as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$section = SectionDefinition::from_array( $raw );

			if ( '' !== $section->id() ) {
				$order[ $section->id() ] = $section;
			}
		}

		$groups = array();

		foreach ( $entries as $entry ) {
			$definition = $declared[ $entry->id() ] ?? null;

			if ( null === $definition ) {
				continue;
			}

			$link = $definition->destinations()[ $destination ] ?? array();

			if ( empty( $link['enabled'] ) ) {
				// An entry that reached here without an enabled link is not this area's
				// to show. Deciding it again is cheap and keeps the rule true however
				// the caller built the list.
				continue;
			}

			$id = isset( $link['section'] ) && '' !== (string) $link['section']
				? (string) $link['section']
				: (string) $definition->to_array()['section'];

			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'id'       => $id,
					'title'    => isset( $order[ $id ] ) ? $order[ $id ]->title() : '',
					'position' => isset( $order[ $id ] ) ? $order[ $id ]->position() : 0,
					'fields'   => array(),
				);
			}

			$title = isset( $link['title'] ) && '' !== trim( (string) $link['title'] )
				? (string) $link['title']
				: $entry->label();

			$position = isset( $link['position'] ) && is_numeric( $link['position'] )
				? (int) $link['position']
				: $definition->position();

			$groups[ $id ]['fields'][] = array(
				'entry'    => $entry,
				'title'    => $title,
				'position' => $position,
			);
		}

		// Within a section the link's order decides, and the entry's own order breaks a
		// tie — two fields configured at the same position still come out in the same
		// order every time, which is what makes the projection testable at all.
		foreach ( $groups as $id => $group ) {
			usort(
				$groups[ $id ]['fields'],
				static function ( array $left, array $right ): int {
					return $left['position'] <=> $right['position'];
				}
			);
		}

		// The sections come out in the document's own order, then by position.
		$list = array_values( $groups );

		usort(
			$list,
			static function ( array $left, array $right ): int {
				return $left['position'] <=> $right['position'];
			}
		);

		return $list;
	}
}
