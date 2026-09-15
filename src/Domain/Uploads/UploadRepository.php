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
	 * Record status: a document the customer keeps on their own profile.
	 *
	 * It belongs to a customer, not to a checkout, so it does not expire: it stays while the
	 * customer exists and goes with them when they do. That is the fourth life an upload can
	 * have, and the one the account page needs — a profile document that vanished after a day
	 * would be a document the customer could never rely on.
	 */
	public const STATUS_STORED = 'stored';

	/**
	 * Inserts a record.
	 *
	 * The token is generated here rather than accepted from a caller: it is the
	 * handle the browser will hold, and a handle anybody can choose is a handle
	 * anybody can guess.
	 *
	 * A record that belongs to a **customer** (`user_id` above zero) is inserted as
	 * `stored` with no expiry: it is kept because the customer exists, not because a
	 * checkout is in progress. Everything else is the temporary case, which is the life
	 * a file has before an order claims it.
	 *
	 * @param array{owner: string, field_id: string, file_name: string, mime_type: string, byte_size: int, path: string, expires_at?: string|null, user_id?: int} $record Record.
	 * @return string The token, or an empty string when it could not be stored.
	 */
	public function insert( array $record ): string {
		global $wpdb;

		$token   = bin2hex( random_bytes( 32 ) );
		$user_id = isset( $record['user_id'] ) ? max( 0, (int) $record['user_id'] ) : 0;
		$expires = $record['expires_at'] ?? null;

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
				'status'     => $user_id > 0 ? self::STATUS_STORED : self::STATUS_TEMPORARY,
				'order_id'   => 0,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql', true ),
				'expires_at' => is_string( $expires ) && '' !== $expires ? $expires : null,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return false === $ok ? '' : $token;
	}

	/**
	 * The document a customer currently keeps for one field.
	 *
	 * Keyed by the customer and the field rather than by a token, because the account page
	 * does not hold a token when it renders: the value the document stores is the token, but
	 * the question the page asks is "what is my file for this field", and a customer who
	 * signs in on another device has no session to have remembered one in.
	 *
	 * The newest row wins: replacing a document is how a customer sends a new version, and
	 * the older one is then a file nobody points at.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $field_id Field.
	 * @return array<string, mixed>|null
	 */
	public function for_user( int $user_id, string $field_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A read of this plugin's own table; the table name is built from the prefix and a constant, and the values are placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . UploadsTable::name() . ' WHERE user_id = %d AND field_id = %s ORDER BY id DESC LIMIT 1',
				$user_id,
				$field_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
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
	 * One upload by token, whoever owns it.
	 *
	 * Used by the download policy, which has to decide about a file it does not yet
	 * know the owner of — and which then applies the ownership itself. Every other
	 * reader in this class takes the owner, because every other reader is asked a
	 * question *by* an owner.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function find_any( string $token ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A read of this plugin's own table; the name is built from the prefix and a constant, and the token is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . UploadsTable::name() . ' WHERE token = %s LIMIT 1', $token ),
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
	 * Binds the uploads a checkout submitted to the order it created.
	 *
	 * One statement, and every guarantee comes from it rather than from the code
	 * around it:
	 *
	 * - **Atomic.** A single `UPDATE`, so there is no window in which a token is
	 *   half-bound, and no read-then-write for two requests to interleave in.
	 * - **Idempotent.** `order_id = 0` is part of the condition, so running it again
	 *   after a retry matches nothing and changes nothing. That is what makes a
	 *   client that submits twice safe, and it is why the reply is a count: the first
	 *   call answers with the number bound, the second with zero, and neither is an
	 *   error.
	 * - **Owned.** `owner = %s` is part of the same condition, so a token that belongs
	 *   to another session cannot be bound by this one, however it was obtained. A
	 *   check in PHP would have to be remembered at every call site; this cannot be
	 *   forgotten because it is in the query.
	 *
	 * Only temporary rows are touched: an upload already bound to another order stays
	 * where it is, which is what a customer reusing a token across two checkouts
	 * would otherwise move.
	 *
	 * @param array<int, string> $tokens   Tokens the checkout submitted.
	 * @param string             $owner    Owner identifier of the session.
	 * @param int                $order_id Order.
	 * @return int Number of uploads bound by this call.
	 */
	public function bind( array $tokens, string $owner, int $order_id ): int {
		global $wpdb;

		$tokens = array_values(
			array_filter(
				array_unique( array_map( 'strval', $tokens ) ),
				static function ( string $token ): bool {
					return 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
				}
			)
		);

		if ( array() === $tokens || $order_id <= 0 || '' === $owner ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $tokens ), '%s' ) );
		$arguments    = array_merge( $tokens, array( $owner, $order_id ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name is built from the prefix and a constant, the placeholder list is built from the token count, and every value travels as a placeholder.
		$bound = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . UploadsTable::name() . " SET order_id = %d, status = %s WHERE token IN ( {$placeholders} ) AND owner = %s AND order_id = 0",
				array_merge( array( $order_id, self::STATUS_ORDERED ), $arguments )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return is_int( $bound ) ? $bound : 0;
	}

	/**
	 * The rows a cleanup has to look at.
	 *
	 * Two kinds are candidates, and asking for both in one query is what keeps the
	 * job from needing two passes: the unbound ones whose time is up, and the ones
	 * whose bound order may no longer exist. The second kind cannot be filtered here —
	 * whether an order exists is a question for WooCommerce, not for this table — so
	 * the row is returned and the decision is made by the caller.
	 *
	 * @param int $now   Current timestamp.
	 * @param int $limit How many to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( int $now, int $limit = 50 ): array {
		global $wpdb;

		$moment = gmdate( 'Y-m-d H:i:s', $now );

		// Three reasons a row has to be looked at: a temporary upload past its time, a binding
		// to an order that may be gone, and a document kept for a customer who may be gone.
		// A customer row has no expiry, so leaving it out of this query would be a file kept
		// for a customer who no longer exists — the case nothing else would ever collect.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A read of this plugin's own table, batched; the name is built from the prefix and a constant.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . UploadsTable::name() . ' WHERE user_id > 0 OR ( order_id = 0 AND expires_at IS NOT NULL AND expires_at <= %s ) OR order_id > 0 ORDER BY id ASC LIMIT %d',
				$moment,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Forgets one upload by token, whoever owns it.
	 *
	 * The cleanup runs outside any session, so it cannot answer the ownership
	 * question — and does not need to: it acts on rows a decision has already been
	 * made about.
	 *
	 * @param string $token Token.
	 * @return bool Whether a row was removed.
	 */
	public function forget( string $token ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( UploadsTable::name(), array( 'token' => $token ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_int( $deleted ) && $deleted > 0;
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
