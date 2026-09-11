<?php
/**
 * Retention rule tests.
 *
 * The rules decide whether a file stays, and every one of them is a case somebody
 * will eventually hit: a cart nobody finished, an order that was deleted, and an
 * upload whose row says it belongs to an order that is not there. The interesting
 * half is the last two, because they are what a store accumulates without noticing.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Uploads;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Uploads\UploadsRetention;

/**
 * The decision, on its own.
 */
final class UploadsRetentionTest extends TestCase {

	/**
	 * Now.
	 *
	 * @var int
	 */
	private int $now = 1700000000;

	/**
	 * A record.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function record( array $overrides = array() ): array {
		return array_merge(
			array(
				'token'      => str_repeat( 'a', 64 ),
				'owner'      => 'owner-a',
				'status'     => 'temporary',
				'order_id'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $this->now - 60 ),
				'path'       => '/tmp/nothing',
			),
			$overrides
		);
	}

	/**
	 * A temporary upload past its time expires.
	 *
	 * @return void
	 */
	public function test_a_temporary_upload_past_its_time_expires(): void {
		self::assertSame( 'expire', UploadsRetention::decision( $this->record(), $this->now, false ) );
	}

	/**
	 * One inside its time does not.
	 *
	 * @return void
	 */
	public function test_a_temporary_upload_inside_its_time_is_kept(): void {
		self::assertSame(
			'keep',
			UploadsRetention::decision(
				$this->record( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $this->now + 600 ) ) ),
				$this->now,
				false
			)
		);
	}

	/**
	 * The boundary belongs to the expiry, and not to the row.
	 *
	 * @return void
	 */
	public function test_the_expiry_instant_itself_expires(): void {
		self::assertSame(
			'expire',
			UploadsRetention::decision(
				$this->record( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $this->now ) ) ),
				$this->now,
				false
			)
		);
	}

	/**
	 * An upload bound to an order that exists stays.
	 *
	 * @return void
	 */
	public function test_an_upload_bound_to_a_living_order_is_kept(): void {
		self::assertSame(
			'keep',
			UploadsRetention::decision(
				$this->record(
					array(
						'status'   => 'ordered',
						'order_id' => 42,
					)
				),
				$this->now,
				true
			)
		);
	}

	/**
	 * And one whose order is gone does not, expired or not.
	 *
	 * @return void
	 */
	public function test_an_upload_whose_order_is_gone_is_orphaned(): void {
		self::assertSame(
			'orphaned',
			UploadsRetention::decision(
				$this->record(
					array(
						'status'     => 'ordered',
						'order_id'   => 42,
						'expires_at' => gmdate( 'Y-m-d H:i:s', $this->now + 86400 ),
					)
				),
				$this->now,
				false
			)
		);
	}

	/**
	 * A row that says it is ordered but names no order cannot justify itself.
	 *
	 * @return void
	 */
	public function test_a_row_that_claims_to_be_ordered_without_an_order_is_orphaned(): void {
		self::assertSame(
			'orphaned',
			UploadsRetention::decision(
				$this->record(
					array(
						'status'     => 'ordered',
						'order_id'   => 0,
						'expires_at' => gmdate( 'Y-m-d H:i:s', $this->now + 86400 ),
					)
				),
				$this->now,
				false
			)
		);
	}

	/**
	 * A row with no expiry is kept rather than guessed at.
	 *
	 * @return void
	 */
	public function test_a_row_with_no_usable_expiry_is_kept(): void {
		self::assertSame( 'keep', UploadsRetention::decision( $this->record( array( 'expires_at' => null ) ), $this->now, false ) );
		self::assertSame( 'keep', UploadsRetention::decision( $this->record( array( 'expires_at' => 'not a date' ) ), $this->now, false ) );
	}

	/**
	 * There is no case for a failed upload, because a failure stores nothing.
	 *
	 * @return void
	 */
	public function test_a_failed_upload_is_not_a_case_the_rules_have(): void {
		// A refusal at the endpoint writes neither the file nor the row, so every
		// case here is about something that exists. This asserts the vocabulary stays
		// closed: three answers, and nothing that means "failed".
		$answers = array();

		foreach ( array( 'temporary', 'ordered', 'expired' ) as $status ) {
			$answers[] = UploadsRetention::decision( $this->record( array( 'status' => $status ) ), $this->now, false );
		}

		self::assertSame( array( 'expire', 'orphaned', 'expire' ), $answers );
	}
}
