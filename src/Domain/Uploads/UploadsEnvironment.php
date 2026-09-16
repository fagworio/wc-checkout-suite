<?php
/**
 * Whether the uploads feature may run at all in this environment.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * The gate that turns the uploads feature off when the store cannot keep a file
 * private.
 *
 * Section 12 asks for private upload storage and section 20 for an environment that
 * declares what it can and cannot do. Together they mean one thing here: an upload
 * that is not private must not be offered. A store whose web server serves the
 * private directory would be collecting documents with a promise it cannot keep, and
 * "the customer uploaded it and then the internet could read it" is not a failure a
 * later task can repair.
 *
 * So the feature is unavailable until the environment has been **observed** to
 * protect it. The observation is a probe over HTTP, and its result is cached so a
 * storefront request does not make one: the cache is the point where a stored claim
 * could go stale, so it expires, and a stored *refusal* is never overwritten by a
 * cache — only a fresh probe can turn the feature back on.
 *
 * @see ROADMAP.md sections 12 and 20
 * @see docs/adr/ADR-0002-private-upload-storage.md
 */
final class UploadsEnvironment {

	/**
	 * Option holding the last observation.
	 */
	public const STATE_OPTION = 'wccs_uploads_privacy';

	/**
	 * How long an observation is trusted, in seconds.
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Whether the feature may run.
	 *
	 * This **reads** the observation and never makes one. Probing means an outbound
	 * HTTP request to the store's own address, and doing that from a storefront
	 * request would put a network round trip in front of the checkout and let a slow
	 * or unreachable server decide whether a customer can upload a document. It would
	 * also make building the checkout's data a write, which is how a read path ends
	 * up with a side effect nobody expects.
	 *
	 * So an environment that has not been observed yet is simply not available yet,
	 * and says so. The observation happens when someone asks for it: the diagnostic
	 * surface, the administration, or a scheduled check.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::observed()['protected'] && UploadsTable::exists() && '' !== PrivateStorage::directory();
	}

	/**
	 * Why the feature is off, in words.
	 *
	 * @return string Empty when it is on.
	 */
	public static function reason(): string {
		if ( ! UploadsTable::exists() ) {
			return __( 'The uploads table has not been created, so an upload has nowhere to be recorded.', 'wc-checkoutsuite' );
		}

		if ( '' === PrivateStorage::directory() ) {
			return __( 'The private upload directory could not be resolved on this installation.', 'wc-checkoutsuite' );
		}

		$observed = self::observed();

		if ( ! $observed['observed'] ) {
			return __( 'Uploads have not been checked on this store yet, so they are not offered.', 'wc-checkoutsuite' );
		}

		return $observed['reason'];
	}

	/**
	 * The observation, when there is a fresh one.
	 *
	 * @return array{protected: bool, status: int, reason: string, observed: bool}
	 */
	public static function observed(): array {
		$stored = get_option( self::STATE_OPTION );
		$state  = is_array( $stored ) ? $stored : array();

		$checked = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
		$fresh   = $checked > 0 && ( time() - $checked ) < self::TTL;

		if ( ! $fresh ) {
			return array(
				'protected' => false,
				'status'    => 0,
				'reason'    => '',
				'observed'  => false,
			);
		}

		return array(
			'protected' => ! empty( $state['protected'] ),
			'status'    => isset( $state['status'] ) ? (int) $state['status'] : 0,
			'reason'    => isset( $state['reason'] ) ? (string) $state['reason'] : '',
			'observed'  => true,
		);
	}

	/**
	 * The last result for the diagnostic surface, without probing.
	 *
	 * Reading diagnostics must not change the environment. The explicit probe action
	 * calls {@see self::state()} and this method only exposes the record it left behind.
	 *
	 * @return array{protected: bool, status: int, reason: string, observed: bool, checked_at: int}
	 */
	public static function last_state(): array {
		$stored   = get_option( self::STATE_OPTION );
		$checked  = is_array( $stored ) && isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0;
		$observed = self::observed();

		return array(
			'protected'  => $observed['protected'],
			'status'     => $observed['status'],
			'reason'     => $observed['observed'] ? $observed['reason'] : self::reason(),
			'observed'   => $observed['observed'],
			'checked_at' => $checked,
		);
	}

	/**
	 * The last observation, refreshed when it has expired.
	 *
	 * @param bool $force Whether to observe again even if the record is fresh.
	 * @return array{protected: bool, status: int, reason: string, checked_at: int}
	 */
	public static function state( bool $force = false ): array {
		$stored = get_option( self::STATE_OPTION );
		$state  = is_array( $stored ) ? $stored : array();

		$checked = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
		$fresh   = $checked > 0 && ( time() - $checked ) < self::TTL;

		if ( $fresh && ! $force ) {
			return array(
				'protected'  => ! empty( $state['protected'] ),
				'status'     => isset( $state['status'] ) ? (int) $state['status'] : 0,
				'reason'     => isset( $state['reason'] ) ? (string) $state['reason'] : '',
				'checked_at' => $checked,
			);
		}

		$observed = PrivateStorage::probe();

		$record = array(
			'protected'  => (bool) $observed['protected'],
			'status'     => (int) $observed['status'],
			'reason'     => (string) $observed['reason'],
			'checked_at' => time(),
		);

		update_option( self::STATE_OPTION, $record, false );

		return $record;
	}

	/**
	 * Forgets the observation, so the next question observes again.
	 *
	 * Used by the diagnostic surface: an operator who has just fixed their server
	 * configuration should not have to wait for the record to expire, and there is no
	 * way for the plugin to notice the change by itself.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::STATE_OPTION );
	}
}
