<?php
/**
 * The uploads the store is holding.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * Reads and writes the uploads table.
 *
 * Everything here is keyed by the token, which is the only identifier that ever
 * leaves the server, and by the owner, which is what makes "session A cannot use
 * session B's token" a property of the query rather than of a check somebody has to
 * remember to write. A method that takes a token and not an owner is a method that
 * can only be used by something that has already checked — and the two that need to
 * answer "whose is this" take both.
 *
 * @see ROADMAP.md section 12
 */
final class UploadRepository {

	/**
	 * Record status: held for a checkout that has not been placed.
	 */
	public const STATUS_TEMPORARY = 'temporary';

	/**
	 * Record status: bound to an order.
	 */
	public const STATUS_ORDERED = 'ordered';

	/**
	 * Record status: past its expiry without an order.
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Inserts a record.
	 *
	 * The token is generated here rather than accepted from a caller: it is the
	 * handle the browser will hold, and a handle anybody can choose is a handle
	 * anybody can guess.
	 *
	 * @param array{owner: string, field_id: string, file_name: string, mime_type: string, byte_size: int, path: string, expires_at: string} $record Record.
	 * @return string The token, or an empty string when it could not be stored.
	 */
	public function insert( array $record ): string {
		global $wpdb;

		$token = bin2hex( random_bytes( 32 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A write to this plugin's own table, whose name is built from the WordPress prefix and a constant; there is nothing to invalidate.
		$ok = $wpdb->insert(
			UploadsTable::name(),
			array(
				'token'      => $token,
				'owner'      => (string) $record['owner'],
				'field_id'   => (string) $record['field_id'],
				'file_name'  => (string) $record['file_name'],
				'mime_type'  => (string) $record['mime_type'],
				'byte_size'  => (int) $record['byte_size'],
				'path'       => (string) $record['path'],
				'status'     => self::STATUS_TEMPORARY,
				'order_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
				'expires_at' => (string) $record['expires_at'],
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return false === $ok ? '' : $token;
	}

	/**
	 * One upload, when it belongs to the owner asking.
	 *
	 * @param string $token Token.
	 * @param string $owner Owner identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( string $token, string $owner ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A read of this plugin's own table; the table name is built from the prefix and a constant, and the values are placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . UploadsTable::name() . ' WHERE token = %s AND owner = %s LIMIT 1',
				$token,
				$owner
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a token exists at all, whatever its owner.
	 *
	 * Used to tell "not yours" from "not on file": the two are different answers for
	 * the customer and different events for whoever is watching, and collapsing them
	 * into one message hides a probing attempt inside an ordinary mistake.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public function exists( string $token ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- See above.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . UploadsTable::name() . ' WHERE token = %s LIMIT 1', $token )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return null !== $found;
	}

	/**
	 * Bytes an owner is holding, whatever their status.
	 *
	 * Every row counts, including the expired ones: a byte that has not been cleaned
	 * up is still a byte on the disk, and a quota that ignored it would be enforced
	 * on a number that is not the one the disk sees.
	 *
	 * @param string $owner Owner identifier.
	 * @return int
	 */
	public function used_bytes( string $owner ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- See above.
		$total = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(byte_size), 0) FROM ' . UploadsTable::name() . ' WHERE owner = %s', $owner )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $total;
	}

	/**
	 * Forgets one upload, returning its row so its file can be removed.
	 *
	 * @param string $token Token.
	 * @param string $owner Owner identifier.
	 * @return array<string, mixed>|null
	 */
	public function delete( string $token, string $owner ): ?array {
		global $wpdb;

		$row = $this->find( $token, $owner );

		if ( null === $row ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->delete(
			UploadsTable::name(),
			array(
				'token' => $token,
				'owner' => $owner,
			)
		);

		return $row;
	}
}
