<?php
/**
 * How long the store keeps an uploaded file.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * The retention rules, and the job that applies them.
 *
 * An upload is one of three things, and each has a different life:
 *
 * - **temporary** — uploaded during a checkout that has not been placed. It has no
 *   order to belong to, so nobody will ever read it, and it is the case that keeps
 *   filling a disk: a customer who abandons a cart leaves a document behind. It
 *   expires.
 * - **effective** — bound to an order that exists. The order is the reason it was
 *   kept, and while the order exists the file is part of it. It stays.
 * - **orphaned** — bound to an order that no longer exists. The reason it was kept
 *   is gone, so it goes with it. This is the case a store that deletes test orders
 *   collects without noticing.
 *
 * A failed upload is not a case here at all, and saying so is part of the rule: a
 * failure means nothing was written — the endpoint stores the file and the row
 * together, and a refusal stores neither — so there is nothing to expire and a
 * cleaner that looked for failures would be looking for rows that do not exist.
 *
 * **Every cleanup can be repeated.** The sweep works in batches, deletes files and
 * rows together, and treats "it was already gone" as success rather than as an
 * error: a cron that fires twice, or two crons that overlap, must not corrupt
 * anything, and the second run simply finds nothing. That is the same property the
 * order binding has, and for the same reason — a scheduled job is a retry.
 *
 * @see ROADMAP.md section 12
 */
final class UploadsRetention {

	/**
	 * How many rows one sweep takes at a time.
	 *
	 * Batched rather than unbounded: a store that has never cleaned up could have
	 * thousands of expired uploads, and a cron that tried to delete them all in one
	 * request would time out and delete none.
	 */
	public const BATCH = 50;

	/**
	 * The decision for one upload.
	 *
	 * @param array<string, mixed> $record       Upload record.
	 * @param int                  $now          Current timestamp.
	 * @param bool                 $order_exists Whether the bound order still exists.
	 * @return string One of `keep`, `expire` or `orphaned`.
	 */
	public static function decision( array $record, int $now, bool $order_exists ): string {
		$status   = isset( $record['status'] ) ? (string) $record['status'] : '';
		$order_id = isset( $record['order_id'] ) ? (int) $record['order_id'] : 0;

		if ( $order_id > 0 ) {
			return $order_exists ? 'keep' : 'orphaned';
		}

		if ( UploadRepository::STATUS_ORDERED === $status ) {
			// Bound, but the row does not say to what: the order is gone, or the row
			// is damaged. Either way the reason it was kept cannot be checked, and a
			// file nothing can justify keeping is not kept.
			return 'orphaned';
		}

		$expires = isset( $record['expires_at'] ) && is_string( $record['expires_at'] ) ? strtotime( $record['expires_at'] ) : false;

		if ( false === $expires ) {
			return 'keep';
		}

		return $expires <= $now ? 'expire' : 'keep';
	}

	/**
	 * Removes the uploads that should not be here any more.
	 *
	 * @param int                   $now    Current timestamp.
	 * @param int                   $limit  How many rows to consider.
	 * @param UploadRepository|null $repository Repository.
	 * @return array{expired: int, orphaned: int, kept: int, files_removed: int} What it did.
	 */
	public static function sweep( int $now, int $limit = self::BATCH, ?UploadRepository $repository = null ): array {
		$repository = $repository ?? new UploadRepository();
		$tally      = array(
			'expired'       => 0,
			'orphaned'      => 0,
			'kept'          => 0,
			'files_removed' => 0,
		);

		foreach ( $repository->candidates( $now, $limit ) as $record ) {
			$order_id = (int) ( $record['order_id'] ?? 0 );
			$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

			$decision = self::decision( $record, $now, $order instanceof \WC_Order );

			if ( 'keep' === $decision ) {
				++$tally['kept'];

				continue;
			}

			// The file first and the row second. The other way round, a failure
			// between the two would leave a file with nothing pointing at it — which is
			// exactly the orphan this job exists to remove. This way the worst case is
			// a row whose file is already gone, which the next run deletes.
			if ( PrivateStorage::delete( (string) ( $record['path'] ?? '' ) ) ) {
				++$tally['files_removed'];
			}

			$repository->forget( (string) ( $record['token'] ?? '' ) );

			// The decision is named for what it is and the tally for what happened:
			// `expire` is the instruction, `expired` is the count. Incrementing by the
			// decision itself created a key nobody read and left the real one at zero —
			// which is what a tally printed next to the assertion is for.
			++$tally[ 'expire' === $decision ? 'expired' : 'orphaned' ];
		}

		return $tally;
	}

	/**
	 * Ensures the cleanup is scheduled.
	 *
	 * A single event rather than a recurring one: the job reschedules itself at the
	 * end of a successful run. A recurring event that keeps firing while the site is
	 * unable to run it — a cron that never comes back, a fatal in a neighbour plugin
	 * — piles up missed runs; a job that schedules its own next occurrence has at most
	 * one waiting.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
	}

	/**
	 * The cron hook.
	 */
	public const HOOK = 'wccs_uploads_cleanup';

	/**
	 * Runs the cleanup and schedules the next one.
	 *
	 * @return array{expired: int, orphaned: int, kept: int, files_removed: int}
	 */
	public static function run(): array {
		$tally = self::sweep( time() );

		self::schedule();

		return $tally;
	}

	/**
	 * Registers the job.
	 *
	 * The hook runs a void callback rather than {@see self::run()} directly: an action
	 * callback returns nothing, and the tally is for whoever asked for the sweep, not
	 * for WordPress to ignore.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function (): void {
				self::run();
			}
		);

		add_action( 'init', array( self::class, 'schedule' ) );
	}
}
