<?php
/**
 * The single writer of Suite field values on an order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Orders;

use WC_Order;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

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
 * {
 *   "format": 2,
 *   "values":   { "wccs_cpf": "12345678909", "wccs_consent": false },
 *   "snapshot": { "wccs_cpf": { "label": "CPF", "type": "text", "options": {} } }
 * }
 * ```
 *
 * It is encoded with `JSON_PRESERVE_ZERO_FRACTION` because plain encoding turns
 * the float `0.0` into `0`, which reads back as an integer. The acceptance asks
 * for typed values and for zeros to be preserved, and a zero whose type silently
 * changed is exactly the loss it is about. The flag changes nothing else.
 *
 * The snapshot is what WCCS-024 added, and it is why the format went from 1 to 2.
 * Format 1 payloads stay readable — that is what the version marker is for — and
 * they read as an order that remembers its values but not what they were called.
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
	public const FORMAT = 2;

	/**
	 * Formats this build can read.
	 *
	 * Format 1 carried values without the snapshot. A build that can write a
	 * newer payload is the build that has to keep reading the older one, and
	 * refusing it would turn a working order into an unreadable one — which is
	 * the opposite of what a version marker is for.
	 */
	private const READABLE_FORMATS = array( 1, 2 );

	/**
	 * This storage's values for one order, plus the ones the platform stored.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<string, mixed>             $payload     Decoded payload, as `decode()` returns it.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, mixed> Values, keyed by field identifier.
	 */
	private function own_values( WC_Order $order, array $payload, array $definitions ): array {
		$own = 'readable' === $payload['state'] && is_array( $payload['values'] ) ? $payload['values'] : array();

		if ( array() === $definitions ) {
			return $own;
		}

		return NativeOrderValues::merge( $own, NativeOrderValues::all( $order, $definitions ) );
	}

	/**
	 * Reads the values stored on an order.
	 *
	 * A payload this build cannot read returns an empty set, which is the same
	 * answer {@see self::read_status()} exists to distinguish. Callers that have
	 * to tell "no values" from "values I do not understand" ask for the status.
	 *
	 * **Two authorities, one answer.** A field the platform renders itself — an additional
	 * checkout field — is persisted by WooCommerce, not here, and the order carries it under
	 * WooCommerce's own meta keys. Hand the published definitions in and those values are
	 * read as well, in the one place every projection goes through, so a store whose fields
	 * are captured natively shows them exactly like a store whose fields are captured by this
	 * plugin. Nothing is copied: the value keeps the authority that stored it, and
	 * {@see NativeOrderValues::merge()} lets this storage win wherever it has an answer.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<int, array<string, mixed>> $definitions Published definitions, when the caller has them.
	 * @return OrderFieldValues
	 */
	public function read( WC_Order $order, array $definitions = array() ): OrderFieldValues {
		$payload = $this->decode( $order );

		if ( ! in_array( $payload['state'], array( 'readable', 'absent' ), true ) ) {
			return OrderFieldValues::none();
		}

		$values = $this->own_values( $order, $payload, $definitions );

		return array() === $values ? OrderFieldValues::none() : OrderFieldValues::from_array( $values );
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
				'format'   => self::FORMAT,
				'values'   => $owned->all(),
				'snapshot' => OrderFieldSnapshot::from_definitions( $owned->ids(), $definitions )->all(),
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
	 * Reads an order's values as they were captured.
	 *
	 * This is the reader the acceptance is about. It answers with one entry per
	 * stored value, and it never drops a value: a field that has since been
	 * renamed reads with the label it had, a field that has been taken out of the
	 * schema reads from the order's own snapshot, and a value nothing can name
	 * reads with its identifier and an empty type rather than being hidden.
	 *
	 * The current definitions are an argument rather than something this class
	 * looks up. The service knows the shape of order storage; which schema is
	 * published is the schema layer's business, and a class that read both would
	 * have to be trusted to keep them apart.
	 *
	 * Pass no definitions and every entry reads from the snapshot — which is the
	 * right answer for a caller that only wants history, and the only answer
	 * available to an order whose fields no longer exist anywhere.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return array<int, OrderFieldEntry>
	 */
	public function history( WC_Order $order, array $definitions = array() ): array {
		$payload = $this->decode( $order );

		if ( ! in_array( $payload['state'], array( 'readable', 'absent' ), true ) ) {
			return array();
		}

		$snapshot = OrderFieldSnapshot::from_array( is_array( $payload['snapshot'] ) ? $payload['snapshot'] : array() );
		$current  = $this->current_definitions( $definitions );
		$values   = $this->own_values( $order, $payload, $definitions );

		$entries = array();

		foreach ( $values as $id => $value ) {
			$id       = (string) $id;
			$recorded = $snapshot->entry( $id );
			$now      = $current[ $id ] ?? null;

			if ( null !== $recorded ) {
				$entries[] = new OrderFieldEntry(
					$id,
					$recorded['label'],
					$recorded['type'],
					$value,
					$recorded['options'],
					OrderFieldEntry::SOURCE_SNAPSHOT,
					null !== $now && $now->type() === $recorded['type']
				);

				continue;
			}

			if ( null !== $now ) {
				$entries[] = new OrderFieldEntry(
					$id,
					$now->label(),
					$now->type(),
					$value,
					$now->options(),
					OrderFieldEntry::SOURCE_SCHEMA,
					true
				);

				continue;
			}

			// Neither the order nor the schema can say what this field was. The
			// value is still the customer's answer, so it is returned with what
			// little is known rather than dropped: an entry that says less is
			// readable, an entry that is missing is not.
			$entries[] = new OrderFieldEntry(
				$id,
				$id,
				'',
				$value,
				array(),
				OrderFieldEntry::SOURCE_UNLABELLED,
				false
			);
		}

		return $entries;
	}

	/**
	 * The published definitions, keyed by identifier.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return array<string, FieldDefinition>
	 */
	private function current_definitions( array $definitions ): array {
		$by_id = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || isset( $by_id[ $id ] ) ) {
				continue;
			}

			$by_id[ $id ] = $definition;
		}

		return $by_id;
	}

	/**
	 * Decodes the stored payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array{state: string, format: int|null, values: array<string, mixed>, snapshot: mixed}
	 */
	private function decode( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_FIELDS, true, 'edit' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array(
				'state'    => 'absent',
				'format'   => null,
				'values'   => array(),
				'snapshot' => null,
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['format'] ) || ! is_int( $decoded['format'] ) ) {
			return array(
				'state'    => 'corrupt',
				'format'   => null,
				'values'   => array(),
				'snapshot' => null,
			);
		}

		if ( ! in_array( $decoded['format'], self::READABLE_FORMATS, true ) ) {
			return array(
				'state'    => 'unsupported_format',
				'format'   => $decoded['format'],
				'values'   => array(),
				'snapshot' => null,
			);
		}

		if ( ! isset( $decoded['values'] ) || ! is_array( $decoded['values'] ) ) {
			return array(
				'state'    => 'corrupt',
				'format'   => $decoded['format'],
				'values'   => array(),
				'snapshot' => null,
			);
		}

		return array(
			'state'    => 'readable',
			'format'   => $decoded['format'],
			'values'   => $decoded['values'],
			'snapshot' => $decoded['snapshot'] ?? null,
		);
	}
}
