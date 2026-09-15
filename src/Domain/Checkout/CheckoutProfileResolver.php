<?php
/**
 * Which checkout a cart gets.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Sections\ContainerDefinition;

/**
 * Picks the profile a cart is served by, and assembles the document that follows from it.
 *
 * `roadmap/…` §6.9 names the two mechanisms and the order they are consulted in: the profiles that
 * match are considered by **priority**, and the store's own checkout is the **fallback**. §1844
 * puts this step before the effective schema is assembled, which is why resolution and composition
 * are one class: a profile that were resolved but never applied would be a decision nobody obeys.
 *
 * Three rules keep the answer explainable:
 *
 * 1. **Only enabled profiles are considered.** A profile the merchant turned off is not a
 *    candidate, and it is not a fallback either — an off profile is off.
 * 2. **A rule is asked of the shared engine** (§14), against the trusted cart context the checkout
 *    already builds. There is no second dialect for profiles.
 * 3. **Priority decides, and the document decides ties.** Two profiles that both match are
 *    ordered by priority, and two with the same priority keep the order the merchant declared
 *    them in — so the same cart always gets the same checkout.
 *
 * @see \ROADMAP.md sections 6.3 and 6.9
 */
final class CheckoutProfileResolver {

	/**
	 * Destination a profile composes.
	 */
	public const CHECKOUT = 'checkout';

	/**
	 * The profiles a cart may be served by, best first.
	 *
	 * @param array<int, mixed> $profiles Stored profiles.
	 * @param FieldContext      $context  Trusted cart context.
	 * @return array<int, CheckoutProfile> Matching profiles, in the order they are considered.
	 */
	public static function candidates( array $profiles, FieldContext $context ): array {
		$matching = array();

		foreach ( $profiles as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$profile = CheckoutProfile::from_array( $raw );

			if ( '' === $profile->id() || ! $profile->is_enabled() ) {
				continue;
			}

			// The shared engine, not a second dialect (§14): the same tree a field uses, asked
			// against the same trusted context.
			if ( ! $profile->is_unconditional() && ! ( new TreeConditionEvaluator() )->evaluate( $profile->conditions(), $context ) ) {
				continue;
			}

			$matching[] = array(
				'profile' => $profile,
				'index'   => (int) $index,
			);
		}

		usort(
			$matching,
			static function ( array $a, array $b ): int {
				$by_priority = $b['profile']->priority() <=> $a['profile']->priority();

				return 0 !== $by_priority ? $by_priority : $a['index'] <=> $b['index'];
			}
		);

		return array_values( array_map( static fn( array $entry ): CheckoutProfile => $entry['profile'], $matching ) );
	}

	/**
	 * The profile a cart is served by, or null when the store's own checkout answers.
	 *
	 * A profile that matches is used. When none matches, the **fallback** profile answers — the
	 * one the merchant marked, whatever its priority — and when there is no fallback profile the
	 * store's own checkout is the answer, which is what "the default checkout is always there"
	 * means.
	 *
	 * @param array<int, mixed> $profiles Stored profiles.
	 * @param FieldContext      $context  Trusted cart context.
	 * @return CheckoutProfile|null
	 */
	public static function resolve( array $profiles, FieldContext $context ): ?CheckoutProfile {
		$matching = self::candidates( $profiles, $context );

		if ( array() !== $matching ) {
			return $matching[0];
		}

		return self::fallback( $profiles );
	}

	/**
	 * The profile that answers a cart nothing matched.
	 *
	 * @param array<int, mixed> $profiles Stored profiles.
	 * @return CheckoutProfile|null
	 */
	public static function fallback( array $profiles ): ?CheckoutProfile {
		foreach ( $profiles as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$profile = CheckoutProfile::from_array( $raw );

			if ( '' === $profile->id() || ! $profile->is_enabled() || ! $profile->is_fallback() ) {
				continue;
			}

			// A merchant may mark more than one, which the validator refuses; if a document was
			// written before that rule, the one declared first answers rather than the answer
			// depending on the order the array happened to be in.
			return $profile;
		}

		return null;
	}

	/**
	 * The document one cart is served by.
	 *
	 * With no profile, the document itself is the checkout: that is the store's own composition,
	 * and it is what every store has whether or not it ever writes a profile.
	 *
	 * With a profile, its **containers replace the checkout ones**. Not the whole list: a document
	 * carries containers for every destination it serves — the order panel, the emails, the
	 * customer profile — and a checkout profile is a composition **of the checkout** (§3.4). A
	 * profile that replaced the whole list would silently dismantle the order and email surfaces
	 * for every cart it matched, which is not something the merchant asked for by editing a
	 * checkout. So the containers offered in `checkout` are the ones the profile owns, and the
	 * rest of the document is carried through untouched.
	 *
	 * The fields do not move: a binding names a container, so a field appears in the profile that
	 * declares the container it was bound to, and a field bound to no declared container appears in
	 * none of them. Containers with the same id in two profiles is the ordinary way to share one
	 * field library between several checkouts.
	 *
	 * The profile's **presentation** is deliberately not folded in here. It is not schema, and a
	 * document that carried it would be a second place the same bytes live; the caller that
	 * resolved the profile still holds it.
	 *
	 * @param SchemaDocument       $document Document.
	 * @param CheckoutProfile|null $profile  Resolved profile, when there is one.
	 * @return SchemaDocument
	 */
	public static function compose( SchemaDocument $document, ?CheckoutProfile $profile ): SchemaDocument {
		if ( null === $profile ) {
			return $document;
		}

		$elsewhere = array();

		foreach ( $document->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			if ( ! ContainerDefinition::from_array( $raw )->is_offered_in( self::CHECKOUT ) ) {
				$elsewhere[] = $raw;
			}
		}

		return $document->with_sections( array_merge( $profile->sections(), $elsewhere ) );
	}

	/**
	 * The profiles of a document that could be selected by the same cart.
	 *
	 * §6.9 asks for an overlap to be **shown**, not refused: two profiles whose rules both match a
	 * cart are a decision the merchant is allowed to make — priority resolves it — but one they
	 * should be able to see while making it. So this reports the pairs and their shared destination,
	 * and validation stays out of it.
	 *
	 * Two unconditional profiles always overlap; two conditional ones overlap exactly when the
	 * sample the editor evaluates matches both.
	 *
	 * @param array<int, mixed> $profiles Stored profiles.
	 * @param FieldContext      $context  Context to test the rules against.
	 * @return array<int, array{profile: string, other: string, priority: int, other_priority: int}>
	 */
	public static function overlaps( array $profiles, FieldContext $context ): array {
		$candidates = self::candidates( $profiles, $context );
		$overlaps   = array();

		foreach ( $candidates as $index => $profile ) {
			foreach ( array_slice( $candidates, $index + 1 ) as $other ) {
				$overlaps[] = array(
					'profile'        => $profile->id(),
					'other'          => $other->id(),
					'priority'       => $profile->priority(),
					'other_priority' => $other->priority(),
				);
			}
		}

		return $overlaps;
	}
}
