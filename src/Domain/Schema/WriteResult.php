<?php
/**
 * Repository write outcome.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

/**
 * Outcome of a compare-and-swap write.
 *
 * The repository never decides an HTTP status. It reports what happened and the
 * transport layer maps `conflict` to 409 and `invalid` to 422.
 *
 * @see \ROADMAP.md sections 13 and 19
 */
final class WriteResult {

	/**
	 * Write applied.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * Another writer changed the revision first.
	 */
	public const STATUS_CONFLICT = 'conflict';

	/**
	 * The document did not pass validation.
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param string                          $status          One of the status constants.
	 * @param int                             $revision        Revision stored, or the current one when conflicting.
	 * @param array<int, array<string,mixed>> $errors          Validation errors, when invalid.
	 */
	private function __construct(
		private string $status,
		private int $revision,
		private array $errors
	) {
	}

	/**
	 * A successful write.
	 *
	 * @param int $revision Revision that was stored.
	 * @return self
	 */
	public static function ok( int $revision ): self {
		return new self( self::STATUS_OK, $revision, array() );
	}

	/**
	 * A rejected write because the revision moved.
	 *
	 * @param int $current_revision Revision currently stored.
	 * @return self
	 */
	public static function conflict( int $current_revision ): self {
		return new self( self::STATUS_CONFLICT, $current_revision, array() );
	}

	/**
	 * A rejected write because the document is invalid.
	 *
	 * @param array<int, array<string, mixed>> $errors Validation errors.
	 * @return self
	 */
	public static function invalid( array $errors ): self {
		return new self( self::STATUS_INVALID, 0, $errors );
	}

	/**
	 * Whether the write was applied.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::STATUS_OK === $this->status;
	}

	/**
	 * Whether the write lost a race.
	 *
	 * @return bool
	 */
	public function is_conflict(): bool {
		return self::STATUS_CONFLICT === $this->status;
	}

	/**
	 * Outcome status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Revision stored, or the current one when conflicting.
	 *
	 * @return int
	 */
	public function revision(): int {
		return $this->revision;
	}

	/**
	 * Validation errors, when invalid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
