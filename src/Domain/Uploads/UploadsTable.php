<?php
/**
 * The operational table the uploads subsystem keeps its own state in.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * Creates and versions the uploads table.
 *
 * Uploads need state of their own — which file belongs to which session, how many
 * bytes an owner has spent, when a temporary file expires — and none of it belongs
 * in post meta or an option. A store with thousands of uploads would otherwise load
 * one serialised array to answer a question about one row, and a quota cannot be
 * counted from an option at all.
 *
 * The table is created by `dbDelta()` on activation and versioned in an option, the
 * same way the schema document is: a plugin that is updated without being
 * reactivated still installs what it needs, which is why the version is compared on
 * every boot rather than only on the activation hook. The activation hook ran in a
 * request that may have been years ago.
 *
 * @see ROADMAP.md section 12
 */
final class UploadsTable {

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'wccs_uploads_db_version';

	/**
	 * Schema version this build installs.
	 *
	 * 2 adds `user_id`: a document a customer keeps on their own profile has no order to
	 * belong to, and the row has to say whose it is for the account page to find it again
	 * from another device.
	 */
	public const VERSION = '2';

	/**
	 * Table name, without the prefix.
	 */
	public const TABLE = 'wccs_uploads';

	/**
	 * The full table name for this installation.
	 *
	 * @return string
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Installs the table when the installed version is not this build's.
	 *
	 * Called on every boot because a plugin update does not run the activation hook:
	 * a store that updated over the files would otherwise have a new build and an old
	 * table, and would discover it one failed upload at a time.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates or updates the table.
	 *
	 * @return bool Whether the table is the one this build expects.
	 */
	public static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();

		// `dbDelta()` is whitespace sensitive: two spaces after PRIMARY KEY, one
		// column per line, and the key on its own line. The format is not a style —
		// it is what makes the diff work, and a reformatted statement silently
		// reinstalls the table on every boot.
		$sql = "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(64) NOT NULL,
			owner char(64) NOT NULL,
			field_id varchar(191) NOT NULL,
			file_name varchar(255) NOT NULL,
			mime_type varchar(100) NOT NULL,
			byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
			path varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'temporary',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY owner (owner),
			KEY status_expires (status,expires_at),
			KEY order_id (order_id),
			KEY user_id (user_id)
		) {$collate};";

		dbDelta( $sql );

		if ( ! self::exists() ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );

		return true;
	}

	/**
	 * Whether the table exists.
	 *
	 * Asked of the database rather than assumed from the version option: a table can
	 * be dropped by an operator after the option was written, and a subsystem that
	 * trusted the option would fail on its first insert instead of reporting itself
	 * unavailable.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence is a question about the schema, which has no API and nothing worth caching: a cached answer to "does the table exist" would be wrong exactly when it matters.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::name() ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return self::name() === $found;
	}

	/**
	 * The columns this build expects, for a diagnostic that has to notice drift.
	 *
	 * @return array<int, string>
	 */
	public static function columns(): array {
		return array(
			'id',
			'token',
			'owner',
			'field_id',
			'file_name',
			'mime_type',
			'byte_size',
			'path',
			'status',
			'order_id',
			'user_id',
			'created_at',
			'expires_at',
		);
	}
}
