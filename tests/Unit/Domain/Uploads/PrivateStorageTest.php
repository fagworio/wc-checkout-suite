<?php
/**
 * Private storage tests.
 *
 * The storage decisions that can be tested without a web server are the ones about
 * paths, and they are the security-relevant half: a name that arrives from a browser
 * must never become a path, and a stored path must never be able to point outside
 * the directory that owns it.
 *
 * The privacy of the directory itself cannot be tested here — it is a question for
 * the running server, and the integration proof asks it there.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Uploads;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Uploads\PrivateStorage;
use WCCheckoutSuite\Domain\Uploads\UploadsTable;

/**
 * Paths, and the operational table's declared shape.
 */
final class PrivateStorageTest extends TestCase {

	/**
	 * A file name is refused when it tries to leave the directory.
	 *
	 * @return void
	 */
	public function test_a_name_that_tries_to_leave_the_directory_gets_no_address(): void {
		self::assertSame( '', PrivateStorage::url_for( '../wp-config.php' ) );
		self::assertSame( '', PrivateStorage::url_for( 'a/../../b.txt' ) );
		self::assertSame( '', PrivateStorage::url_for( '' ) );
	}

	/**
	 * A path outside the directory resolves to nothing.
	 *
	 * @return void
	 */
	public function test_a_path_outside_the_directory_resolves_to_nothing(): void {
		self::assertSame( '', PrivateStorage::resolve( '/etc/passwd' ) );
		self::assertSame( '', PrivateStorage::resolve( __FILE__ ) );
		self::assertSame( '', PrivateStorage::resolve( '' ) );
	}

	/**
	 * The table knows which columns it installs.
	 *
	 * @return void
	 */
	public function test_the_table_declares_the_columns_it_installs(): void {
		self::assertContains( 'token', UploadsTable::columns() );
		self::assertContains( 'owner', UploadsTable::columns() );
		self::assertContains( 'expires_at', UploadsTable::columns() );
		self::assertSame( 'wccs_uploads', UploadsTable::TABLE );
	}
}
