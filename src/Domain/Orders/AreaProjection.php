<?php
/**
 * What one area shows, in the order and under the titles it was configured with.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\ContainerDefinition;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;

/**
 * The document, read the way one area reads it.
 *
 * Every surface — the order screen, the customer's page, both e-mails — asks the same
 * question and has to answer it the same way: which entries does this area show, under
 * which section, with which title, and in which order? The answer is the *link* the
 * merchant configured for that destination, not the field's own position, because the
 * same field may be third on the order screen and first in the customer's e-mail.
 *
 * Nothing here decides whether a value may be shown: that is the binding and the file
 * permissions, and the callers ask before they get here. What this class does is the rest
 * of the sentence in section 14 — the container, the title and the order.
 *
 * @see ROADMAP.md section 14
 */
final class AreaProjection {

	/**
	 * Groups the entries one area shows, one group per container.
	 *
	 * A group is a container: it carries the container's identifier, the name the document
	 * gave it, and the entries in the order the bindings configured. Where the *binding*
	 * decides, not the field: the same field may be third on the order screen and first in
	 * the customer's e-mail, and — since `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais`
	 * §3.3 — it may also be used twice in the same area, each use with its own container,
	 * title and order. That is why this reads bindings and not a destination link.
	 *
	 * An entry the caller did not pass is not shown: a binding is a decision about *how* a
	 * value appears, never a reason to read one that the surface was not given.
	 *
	 * @param array<int, OrderFieldEntry>      $entries     Entries the caller already allowed.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @param array<int, array<string, mixed>> $containers  Published containers.
	 * @param string                           $destination Destination key.
	 * @return array<int, array{id: string, title: string, position: int, fields: array<int, array{entry: OrderFieldEntry, title: string, position: int, binding: FieldBinding}>}>
	 */
	public static function group( array $entries, array $definitions, array $containers, string $destination ): array {
		$declared = array();
		$by_id    = array();
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

		foreach ( $entries as $entry ) {
			$by_id[ $entry->id() ] = $entry;
		}

		foreach ( $containers as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$container = ContainerDefinition::from_array( $raw );

			if ( '' !== $container->id() ) {
				$order[ $container->id() ] = $container;
			}
		}

		$groups = array();

		foreach ( $declared as $definition ) {
			foreach ( $definition->bindings_for( $destination ) as $binding ) {
				$entry = $by_id[ $definition->id() ] ?? null;

				if ( null === $entry || ! $binding->is_visible() ) {
					continue;
				}

				// A file only appears where the use allows its name and details to be
				// shown: the use decides, so the same document may show it on the order
				// screen and hide it in the customer's e-mail.
				if (
					FilePermissions::is_file( $definition )
					&& ! FilePermissions::allows_binding( $binding, $destination, 'show_metadata' )
				) {
					continue;
				}

				$id = '' !== $binding->container_id()
					? $binding->container_id()
					: (string) $definition->to_array()['section'];

				if ( ! isset( $groups[ $id ] ) ) {
					$groups[ $id ] = array(
						'id'       => $id,
						'title'    => isset( $order[ $id ] ) ? $order[ $id ]->name() : '',
						'position' => isset( $order[ $id ] ) ? $order[ $id ]->position() : 0,
						'fields'   => array(),
					);
				}

				$groups[ $id ]['fields'][] = array(
					'entry'    => $entry,
					'title'    => $binding->title_for( $entry->label() ),
					'position' => $binding->has_position()
						? $binding->position()
						: $definition->position(),
					'binding'  => $binding,
				);
			}
		}

		// Within a container the binding's order decides, and the binding's own identifier
		// breaks a tie — two uses of the same field configured at the same position still
		// come out in the same order every time, which is what makes the projection
		// testable at all.
		foreach ( $groups as $id => $group ) {
			usort(
				$groups[ $id ]['fields'],
				static function ( array $left, array $right ): int {
					$by_position = $left['position'] <=> $right['position'];

					if ( 0 !== $by_position ) {
						return $by_position;
					}

					return $left['binding']->id() <=> $right['binding']->id();
				}
			);
		}

		// The containers come out in the document's own order, then by position.
		$list = array_values( $groups );

		usort(
			$list,
			static function ( array $left, array $right ): int {
				$by_position = $left['position'] <=> $right['position'];

				if ( 0 !== $by_position ) {
					return $by_position;
				}

				return $left['id'] <=> $right['id'];
			}
		);

		return $list;
	}
}
