<?php
/**
 * What stands between a draft and a working checkout.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use WCCheckoutSuite\Domain\Checkout\AdapterCapabilities;
use WCCheckoutSuite\Domain\Checkout\CoreFields;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Finds the configurations a checkout will not honour as written.
 *
 * ROADMAP.md section 428 asks the publication to show "diferenças, validações e
 * incompatibilidades" — three different things. A validation says the schema is
 * wrong and publication is refused. An incompatibility says the schema is
 * perfectly valid and the store will still not do what it says. Merging them
 * would hide the second kind behind the first, and the second kind is the one
 * that reaches a customer.
 *
 * Two families are reported, and both are facts rather than opinions:
 *
 * 1. **A type the target adapter cannot render on its own.** The Block checkout
 *    provides text, select and checkbox; anything else needs a Suite component.
 *    That is verifiable from the registry and from WCCS-003.
 *
 * 2. **A core override whose WooCommerce field is gone.** The Suite stores an
 *    override rather than a copy, so a field removed by another plugin or by a
 *    WooCommerce upgrade leaves the override pointing at nothing. This is exactly
 *    the drift ADR-0001 chose to be able to detect.
 *
 * The second family is only reported when the inventory could actually be read.
 * An unreadable inventory is not evidence that every core field disappeared, and
 * treating it as such would fill the screen with alarms every time WooCommerce
 * failed to start.
 *
 * @see ROADMAP.md sections 7, 8 and 428
 */
final class PublishIncompatibilities {

	/**
	 * Checks a draft against every known adapter and against the store.
	 *
	 * @param array<int, mixed> $fields Raw field list.
	 * @param CoreFields|null   $core   Core field inventory, when available.
	 * @return array<string, mixed>
	 */
	public static function check( array $fields, ?CoreFields $core = null ): array {
		$by_adapter = array();
		$total      = 0;

		foreach ( AdapterCapabilities::values() as $adapter ) {
			$entries = self::for_adapter( $fields, $adapter );

			$by_adapter[ $adapter ] = $entries;
			$total                 += count( $entries );
		}

		$store  = self::against_store( $fields, $core );
		$total += count( $store );

		return array(
			'total'    => $total,
			'adapters' => $by_adapter,
			'store'    => $store,
		);
	}

	/**
	 * Checks a draft against one adapter.
	 *
	 * @param array<int, mixed> $fields  Raw field list.
	 * @param string            $adapter Adapter value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function for_adapter( array $fields, string $adapter ): array {
		$entries = array();

		foreach ( $fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$capability = AdapterCapabilities::for_type( $definition->type(), $adapter );

			if ( AdapterCapabilities::is_native( $capability['level'] ) ) {
				continue;
			}

			if ( ! $definition->is_enabled() ) {
				// An archived field is not rendered, so it cannot be incompatible
				// with anything. Reporting it would be noise the merchant cannot
				// act on.
				continue;
			}

			$entries[] = array(
				'kind'   => 'adapter',
				'field'  => $definition->id(),
				'label'  => $definition->label(),
				'type'   => $definition->type(),
				'level'  => $capability['level'],
				'reason' => $capability['reason'],
			);
		}

		return $entries;
	}

	/**
	 * Checks a draft against the store it will run on.
	 *
	 * @param array<int, mixed> $fields Raw field list.
	 * @param CoreFields|null   $core   Core field inventory.
	 * @return array<int, array<string, mixed>>
	 */
	private static function against_store( array $fields, ?CoreFields $core ): array {
		if ( null === $core || ! $core->available() ) {
			return array();
		}

		$known   = $core->ids();
		$entries = array();

		foreach ( $fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$raw_array  = $definition->to_array();

			if ( 'core' !== ( $raw_array['origin'] ?? '' ) ) {
				continue;
			}

			if ( in_array( $definition->id(), $known, true ) ) {
				continue;
			}

			$entries[] = array(
				'kind'   => 'store',
				'field'  => $definition->id(),
				'label'  => $definition->label(),
				'type'   => $definition->type(),
				'level'  => AdapterCapabilities::UNSUPPORTED,
				'reason' => sprintf(
					/* translators: %s: field id */
					__(
						'The WooCommerce field "%s" no longer exists on this store, so this override applies to nothing.',
						'wc-checkoutsuite'
					),
					$definition->id()
				),
			);
		}

		return $entries;
	}
}
