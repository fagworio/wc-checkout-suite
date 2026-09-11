<?php
/**
 * Upload rule tests.
 *
 * The rules are the part of the upload subsystem that can be checked without a
 * request, a session or a disk, and they are where a store gets an upload feature
 * wrong: an extension is not evidence of a type, and a limit per file does nothing
 * about a thousand small files.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Uploads;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Uploads\UploadRules;

/**
 * MIME, bytes and quota.
 */
final class UploadRulesTest extends TestCase {

	/**
	 * A file the rules accept.
	 *
	 * @return void
	 */
	public function test_a_permitted_type_within_the_limits_is_accepted(): void {
		self::assertSame( '', UploadRules::check( 'application/pdf', 1024 ) );
		self::assertSame( '', UploadRules::check( 'image/png', 1024 ) );
		self::assertSame( '', UploadRules::check( 'text/plain', 1 ) );
	}

	/**
	 * A type outside the list is refused.
	 *
	 * @return void
	 */
	public function test_a_type_outside_the_list_is_refused(): void {
		self::assertSame( 'mime_not_allowed', UploadRules::check( 'application/x-httpd-php', 1024 ) );
		self::assertSame( 'mime_not_allowed', UploadRules::check( 'text/x-shellscript', 10 ) );
		self::assertSame( 'mime_not_allowed', UploadRules::check( 'application/zip', 1024 ) );
	}

	/**
	 * An empty file is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_an_empty_file_is_refused(): void {
		self::assertSame( 'empty_file', UploadRules::check( 'text/plain', 0 ) );
		self::assertSame( 'empty_file', UploadRules::check( 'text/plain', -1 ) );
	}

	/**
	 * A file above the maximum is refused, and one exactly at it is not.
	 *
	 * @return void
	 */
	public function test_the_maximum_is_a_boundary_and_not_a_range(): void {
		self::assertSame( 'file_too_large', UploadRules::check( 'text/plain', 101, 0, 100, 1000 ) );
		self::assertSame( '', UploadRules::check( 'text/plain', 100, 0, 100, 1000 ) );
	}

	/**
	 * The quota counts what the owner has already stored.
	 *
	 * @return void
	 */
	public function test_the_quota_counts_what_is_already_stored(): void {
		self::assertSame( '', UploadRules::check( 'text/plain', 10, 90, 1000, 100 ) );
		self::assertSame( 'quota_exceeded', UploadRules::check( 'text/plain', 11, 90, 1000, 100 ) );
		self::assertSame( '', UploadRules::check( 'text/plain', 10, 90, 1000, 100 ) );
	}

	/**
	 * The order of the checks puts the cheapest question first.
	 *
	 * A file that is both too large and of a refused type is reported as too large,
	 * which is the answer the customer can act on.
	 *
	 * @return void
	 */
	public function test_the_size_is_reported_before_the_type(): void {
		self::assertSame( 'file_too_large', UploadRules::check( 'application/zip', 5000, 0, 1000, 10000 ) );
	}

	/**
	 * Every code has a message, and none of them is the generic one.
	 *
	 * @return void
	 */
	public function test_every_code_is_explained(): void {
		$generic = UploadRules::message( 'something-nobody-declared' );

		foreach ( array( 'empty_file', 'bad_upload', 'file_too_large', 'mime_not_allowed', 'quota_exceeded', 'not_available', 'not_yours', 'unknown_token' ) as $code ) {
			$message = UploadRules::message( $code );

			self::assertNotSame( '', $message, $code );
			self::assertNotSame( $generic, $message, $code );
		}
	}

	/**
	 * The permitted list is short and specific.
	 *
	 * @return void
	 */
	public function test_the_permitted_types_are_the_documents_a_checkout_asks_for(): void {
		$allowed = UploadRules::allowed_mimes();

		self::assertContains( 'application/pdf', $allowed );
		self::assertContains( 'image/jpeg', $allowed );
		self::assertNotContains( 'application/octet-stream', $allowed );
		self::assertNotContains( 'text/html', $allowed );
	}
}
