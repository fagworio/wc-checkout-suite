<?php
/**
 * The single writer of Suite field values on an order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WC_Order;

/**
 * Reads and writes the Suite's canonical field values on an order.
 *
 * This class is the only place that knows the shape of `_wccs_fields`. That is the
 * whole point of it: ADR-0001 gives one storage authority per field origin, and a
 * format known in two places is a format that drifts in two directions.
 *
 * Everything goes through `WC_Order`. Reading order meta from `wp_postmeta` or
 * `wp_posts` is what breaks under HPOS, where the authoritative store is a
 * dedicated table and the post row — when it exists at all — is a placeholder.
 * Using the CRUD means the backend is WooCommerce's decision and not this
 * plugin's, which is what makes the same code correct with HPOS on and off.
 *
 * The payload is a JSON string, not a serialised array: section 13 asks for
 * arrays and JSON rather than serialised PHP objects from extensions, and a
 * string is the one shape that reads back identically from both stores.
 *
 * ```
 * { "format": 1, "values": { "wccs_cpf": "12345678909", "wccs_consent": false } }
 * ```
 *
 * It is encoded with `JSON_PRESERVE_ZERO_FRACTION` because plain encoding turns
 * the float `0.0` into `0`, which reads back as an integer. The acceptance asks
 * for typed values and for zeros to be preserved, and a zero whose type silently
 * changed is exactly the loss it is about. The flag changes nothing else.
 *
 * The schema revision is stored beside it rather than inside it, because it is
 * meaningful on its own: it says which schema the values were captured against,
 * and that is what WCCS-024 needs to read an old order after the field changed.
 *
 * @see ROADMAP.md section 13
 * @see \docs/adr/ADR-0001-storage-authority.md
 */
final class OrderFieldsService {

	/**
	 * Order meta holding the typed values.
	 */
	public const META_FIELDS = '_wccs_fields';

	/**
	 * Order meta holding the schema revision the values were captured against.
	 */
	public const META_REVISION = '_wccs_schema_revision';

	/**
	 * Version of the stored payload.
	 *
	 * Written so a later build can recognise a payload it did not write instead
	 * of misreading it. A reader that guesses is worse than one that refuses.
	 */
	public const FORMAT = 1;

	/**
	 * Reads the values stored on an order.
	 *
	 * A payload this build cannot read returns an empty set, which is the same
	 * answer {@see self::read_status()} exists to distinguish. Callers that have
	 * to tell "no values" from "values I do not understand" ask for the status.
	 *
	 * @param WC_Order $order Order.
	 * @return OrderFieldValues
	 */
	public function read( WC_Order $order ): OrderFieldValues {
		$payload = $this->decode( $order );

		return 'readable' === $payload['state']
			? OrderFieldValues::from_array( $payload['values'] )
			: OrderFieldValues::none();
	}

	/**
	 * Reports what is stored on an order, without pretending it is empty.
	 *
	 * One of `absent`, `readable`, `unsupported_format` or `corrupt`. The last two
	 * are not "no values": they are values that exist and cannot be read, and an
	 * interface that shows them as an empty order is showing the wrong thing.
	 *
	 * @param WC_Order $order Order.
	 * @return array{state: string, format: int|null, revision: int|null}
	 */
	public function read_status( WC_Order $order ): array {
		$payload  = $this->decode( $order );
		$revision = $order->get_meta( self::META_REVISION, true, 'edit' );

		return array(
			'state'    => $payload['state'],
			'format'   => $payload['format'],
			'revision' => is_numeric( $revision ) ? (int) $revision : null,
		);
	}

	/**
	 * Writes the Suite's values to an order.
	 *
	 * The values are filtered through the published definitions, so a field
	 * WooCommerce owns or a Blocks additional field never reaches this storage
	 * even if a caller passes it. A value that is not the Suite's to keep is the
	 * one thing this method must not store.
	 *
	 * The order is not saved here. When this runs from the checkout, the order has
	 * not been saved yet and WooCommerce saves it immediately afterwards; saving
	 * inside would write an incomplete order twice.
	 *
	 * @param WC_Order                         $order            Order.
	 * @param array<string, mixed>             $values           Canonical values, keyed by field identifier.
	 * @param array<int, array<string, mixed>> $definitions      Published field definitions.
	 * @param int                              $schema_revision  Revision the values were captured against.
	 * @return OrderFieldValues The values that were stored.
	 */
	public function write( WC_Order $order, array $values, array $definitions, int $schema_revision ): OrderFieldValues {
		$owned = OrderFieldValues::from_definitions( $values, $definitions );

		if ( $owned->is_empty() ) {
			// An order with no Suite values must not claim to have them: leaving
			// an older payload in place would be worse than storing nothing.
			$order->delete_meta_data( self::META_FIELDS );
			$order->delete_meta_data( self::META_REVISION );

			return OrderFieldValues::none();
		}

		$payload = wp_json_encode(
			array(
				'format' => self::FORMAT,
				'values' => $owned->all(),
			),
			JSON_PRESERVE_ZERO_FRACTION
		);

		if ( ! is_string( $payload ) ) {
			// Encoding failed, so there is no faithful representation of these
			// values. Storing a partial one would read as a different answer.
			return OrderFieldValues::none();
		}

		$order->update_meta_data( self::META_FIELDS, $payload );
		$order->update_meta_data( self::META_REVISION, (string) $schema_revision );

		return $owned;
	}

	/**
	 * Decodes the stored payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array{state: string, format: int|null, values: array<string, mixed>}
	 */
	private function decode( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_FIELDS, true, 'edit' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array(
				'state'  => 'absent',
				'format' => null,
				'values' => array(),
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['format'] ) || ! is_int( $decoded['format'] ) ) {
			return array(
				'state'  => 'corrupt',
				'format' => null,
				'values' => array(),
			);
		}

		if ( self::FORMAT !== $decoded['format'] ) {
			return array(
				'state'  => 'unsupported_format',
				'format' => $decoded['format'],
				'values' => array(),
			);
		}

		if ( ! isset( $decoded['values'] ) || ! is_array( $decoded['values'] ) ) {
			return array(
				'state'  => 'corrupt',
				'format' => $decoded['format'],
				'values' => array(),
			);
		}

		return array(
			'state'  => 'readable',
			'format' => $decoded['format'],
			'values' => $decoded['values'],
		);
	}
}
