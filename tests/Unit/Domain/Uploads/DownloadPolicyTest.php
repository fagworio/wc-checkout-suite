<?php
/**
 * Download policy tests.
 *
 * Who may read a private file is the whole of its privacy, and it is a pure
 * decision: given a record and a caller, yes or no. That makes it the one part of
 * the storage that can be pinned down completely without a server, and the two
 * properties worth pinning are that a stranger is refused and that a refusal says
 * nothing about whether the file exists.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Uploads;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Uploads\DownloadPolicy;

/**
 * The rule, on its own.
 */
final class DownloadPolicyTest extends TestCase {

	/**
	 * A record.
	 *
	 * @param string $owner Owner identifier.
	 * @return array<string, mixed>
	 */
	private function record( string $owner = 'owner-a' ): array {
		return array(
			'token'    => str_repeat( 'a', 64 ),
			'owner'    => $owner,
			'order_id' => 42,
		);
	}

	/**
	 * The session that uploaded it may read it.
	 *
	 * @return void
	 */
	public function test_the_session_that_uploaded_it_may_read_it(): void {
		self::assertTrue( DownloadPolicy::allows( $this->record(), array( 'owner' => 'owner-a' ) ) );
	}

	/**
	 * Any other session may not, whoever it is logged in as.
	 *
	 * @return void
	 */
	public function test_another_session_may_not(): void {
		self::assertFalse( DownloadPolicy::allows( $this->record(), array( 'owner' => 'owner-b' ) ) );
		self::assertFalse( DownloadPolicy::allows( $this->record(), array( 'owner' => '' ) ) );
		self::assertFalse(
			DownloadPolicy::allows(
				$this->record(),
				array(
					'owner'      => '',
					'user_id'    => 7,
					'can_manage' => false,
				)
			)
		);
	}

	/**
	 * Staff who manage the store may read it.
	 *
	 * @return void
	 */
	public function test_staff_may_read_it(): void {
		self::assertTrue(
			DownloadPolicy::allows(
				$this->record(),
				array(
					'owner'      => '',
					'user_id'    => 1,
					'can_manage' => true,
				)
			)
		);

		// A capability is not a user id: somebody logged in without it is a stranger.
		self::assertFalse(
			DownloadPolicy::allows(
				$this->record(),
				array(
					'owner'      => '',
					'user_id'    => 1,
					'can_manage' => false,
				)
			)
		);
	}

	/**
	 * The customer may read a document stored on their own profile.
	 *
	 * @return void
	 */
	public function test_customer_may_read_their_profile_document(): void {
		self::assertTrue(
			DownloadPolicy::allows(
				array( 'owner' => 'customer:42' ),
				array(
					'owner'      => 'another-session',
					'user_id'    => 42,
					'can_manage' => false,
				)
			)
		);
	}

	/**
	 * The customer the order belongs to may read it, and nobody else may.
	 *
	 * @return void
	 */
	public function test_the_orders_customer_may_read_it(): void {
		self::assertTrue(
			DownloadPolicy::allows(
				$this->record(),
				array(
					'owner'          => '',
					'user_id'        => 7,
					'order_customer' => 7,
				)
			)
		);

		self::assertFalse(
			DownloadPolicy::allows(
				$this->record(),
				array(
					'owner'          => '',
					'user_id'        => 8,
					'order_customer' => 7,
				)
			)
		);
	}

	/**
	 * An upload with no owner is readable by nobody.
	 *
	 * @return void
	 */
	public function test_an_upload_with_no_owner_is_readable_by_nobody(): void {
		self::assertFalse(
			DownloadPolicy::allows(
				array( 'owner' => '' ),
				array(
					'owner'   => '',
					'user_id' => 1,
				)
			)
		);
	}

	/**
	 * The comparison is not a prefix test.
	 *
	 * @return void
	 */
	public function test_an_owner_that_merely_starts_the_same_is_refused(): void {
		self::assertFalse(
			DownloadPolicy::allows( $this->record( 'abcdef' ), array( 'owner' => 'abc' ) )
		);
	}

	/**
	 * The refusal is one answer, and it does not say whether the file exists.
	 *
	 * @return void
	 */
	public function test_the_refusal_is_one_answer(): void {
		$refusal = DownloadPolicy::refusal();

		self::assertSame( 'not_allowed', $refusal['code'] );
		self::assertStringNotContainsString( 'token', strtolower( $refusal['message'] ) );
		self::assertStringNotContainsString( 'exists', strtolower( $refusal['message'] ) );
	}
}
