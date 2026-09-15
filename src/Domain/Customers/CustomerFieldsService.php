<?php
/**
 * Customer-owned values for My Account sections.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Customers;

/**
 * One storage authority for values the customer edits outside an order.
 */
final class CustomerFieldsService {

	public const META_FIELDS = '_wccs_customer_fields';

	/**
	 * Reads the values that belong to one customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return array<string,mixed> Stored values.
	 */
	public function values( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_FIELDS, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : array();
	}

	/**
	 * Merges validated updates into a customer's values.
	 *
	 * @param int                 $user_id Customer user ID.
	 * @param array<string,mixed> $updates Validated updates.
	 * @return void
	 */
	public function update( int $user_id, array $updates ): void {
		$this->replace( $user_id, array_merge( $this->values( $user_id ), $updates ) );
	}

	/**
	 * Replaces everything stored for one customer.
	 *
	 * The merge above is the only writer a form needs; this exists for the one caller
	 * that has to remove values rather than add them — the erasure a person asks for
	 * through WordPress's own tools. An empty map deletes the meta instead of storing
	 * an empty one, so a customer whose data was erased leaves nothing behind for a
	 * later read to find.
	 *
	 * @param int                 $user_id Customer user ID.
	 * @param array<string,mixed> $values  Values to keep.
	 * @return void
	 */
	public function replace( int $user_id, array $values ): void {
		if ( array() === $values ) {
			delete_user_meta( $user_id, self::META_FIELDS );

			return;
		}

		// The metadata API unslashes the value it is handed, and the JSON payload is
		// full of backslashes: `\u00e3` for an accented character, `\"` inside a
		// quoted value. Passing it unslashed would store it with those backslashes
		// already eaten and read the customer's own text back corrupted, so the
		// payload is slashed exactly the way WordPress expects it.
		update_user_meta(
			$user_id,
			self::META_FIELDS,
			wp_slash( (string) wp_json_encode( $values, JSON_PRESERVE_ZERO_FRACTION ) )
		);
	}
}
