<?php
/**
 * The modes a payment gateway can be in, as a closed vocabulary.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * What this plugin is allowed to do to a gateway's payment area.
 *
 * Four answers and not one of them is a promise made on a gateway's behalf. The
 * vocabulary is closed for the reason every other vocabulary in this plugin is closed
 * (ADR-0007): the editor offers what the validator accepts, the checkout applies what
 * the matrix recorded, and a fifth mode invented in one of those places would be a
 * mode the other two do not know.
 *
 * `UNDECIDED` is the important one. It is not the absence of an answer — it is the
 * answer "nothing was observed here", and it is reported as such. A plugin that
 * treated an untested gateway as safe would be promising compatibility with every
 * gateway in the world, which is the sentence ROADMAP.md section 15 forbids in as
 * many words.
 */
final class PaymentMode {

	/**
	 * Every recorded decoration was applied and the gateway tolerated it.
	 */
	public const DECORATED = 'decorated';

	/**
	 * A decoration this gateway did not tolerate is withheld.
	 */
	public const COMPATIBLE = 'compatible';

	/**
	 * The custom presentation must not offer this gateway at all.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * No homologation record exists; nothing is promised.
	 */
	public const UNDECIDED = 'undecided';

	/**
	 * Every mode, in the order a report reads them.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::DECORATED, self::COMPATIBLE, self::UNAVAILABLE, self::UNDECIDED );
	}

	/**
	 * Whether a value is a mode of this vocabulary.
	 *
	 * @param string $mode Candidate.
	 * @return bool
	 */
	public static function is_known( string $mode ): bool {
		return in_array( $mode, self::all(), true );
	}

	/**
	 * Whether a mode was decided by an observation.
	 *
	 * `undecided` is a legitimate answer and it is not one of these: the difference
	 * between "we tested it and it worked" and "we have not looked" is the whole point
	 * of the matrix.
	 *
	 * @param string $mode Mode.
	 * @return bool
	 */
	public static function is_homologated( string $mode ): bool {
		return in_array( $mode, array( self::DECORATED, self::COMPATIBLE, self::UNAVAILABLE ), true );
	}

	/**
	 * What a mode means, in words a merchant or a developer can act on.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function reason( string $mode ): string {
		switch ( $mode ) {
			case self::DECORATED:
				return 'The gateway was homologated with the full presentation.';

			case self::COMPATIBLE:
				return 'The gateway was homologated with one or more decorations withheld.';

			case self::UNAVAILABLE:
				return 'The gateway must not be offered by the custom presentation.';

			case self::UNDECIDED:
				return 'This gateway has no homologation record, so nothing is promised about it.';

			default:
				return '';
		}
	}
}
