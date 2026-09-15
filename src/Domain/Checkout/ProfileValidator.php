<?php
/**
 * Whether a checkout profile is one the store can keep.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Domain\Conditions\ConditionValidator;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Sections\ContainerDefinition;
use WCCheckoutSuite\Domain\Sections\SectionValidator;

/**
 * The rules a stored checkout profile has to satisfy.
 *
 * A profile is the one part of the document that decides **which** composition runs, so a profile
 * that cannot answer is worse than no profile: the merchant believes a checkout is in service and
 * the carts that match nothing quietly get another one. Every rule here is therefore about making
 * the answer decidable.
 *
 * 1. **It is identifiable.** An id and a name, unique among the profiles — a profile with no id
 *    can never be selected, and two with the same id make "the Produtos restritos checkout" name
 *    two different compositions.
 * 2. **It says where it came from** (§3.4). The source is a closed list, because it is a statement
 *    about provenance that the editor shows and that a later version will read.
 * 3. **At most one of them is the fallback.** Two fallbacks have no answer; the resolver would pick
 *    the first and the second would never run, which is configuration that lies.
 * 4. **Its containers are checkout containers.** A profile composes the checkout (§3.4), and
 *    composition only replaces the containers offered in `checkout` — a container for the order
 *    panel declared here would be kept in the document *and* promised by the profile, so it is
 *    refused by name rather than merged twice.
 * 5. **Its rules are the shared rules** (§14), validated by the shared validator, so that a profile
 *    cannot introduce a second dialect of the same idea.
 *
 * @see \ROADMAP.md sections 3.4, 6.3 and 6.9
 */
final class ProfileValidator {

	/**
	 * Validates the profile list of a document.
	 *
	 * @param array<int, mixed> $profiles Raw profile list.
	 * @return ValidationResult
	 */
	public static function validate_profiles( array $profiles ): ValidationResult {
		$result   = ValidationResult::valid();
		$seen     = array();
		$fallback = 0;

		foreach ( $profiles as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_profile_entry',
						sprintf(
							/* translators: %d: index of the entry */
							__( 'The profile at index %d is not a definition.', 'wc-checkoutsuite' ),
							(int) $index
						),
						array( 'index' => (int) $index )
					)
				);

				continue;
			}

			$profile = CheckoutProfile::from_array( $raw );
			$id      = $profile->id();

			if ( '' === $id ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'profile_id_required',
						__( 'A checkout profile needs an id: without one nothing can select it.', 'wc-checkoutsuite' ),
						array( 'index' => (int) $index )
					)
				);
			} elseif ( isset( $seen[ $id ] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'duplicate_profile_id',
						sprintf(
							/* translators: %s: profile id */
							__( 'The checkout profile id "%s" appears more than once.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'profile' => $id )
					)
				);
			} else {
				$seen[ $id ] = true;
			}

			if ( '' === trim( $profile->name() ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'profile_name_required',
						__( 'A checkout profile needs a name: it is how the merchant tells one checkout from another.', 'wc-checkoutsuite' ),
						array( 'profile' => $id )
					)
				);
			}

			if ( ! in_array( $profile->source(), CheckoutProfile::SOURCES, true ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'profile_source_unknown',
						sprintf(
							/* translators: 1: source value, 2: comma separated list of accepted sources */
							__( 'The checkout profile source "%1$s" is not one of %2$s.', 'wc-checkoutsuite' ),
							$profile->source(),
							implode( ', ', CheckoutProfile::SOURCES )
						),
						array( 'profile' => $id )
					)
				);
			}

			if ( ! self::is_whole_number( $raw['priority'] ?? 0 ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'profile_priority_not_an_integer',
						__( 'The priority of a checkout profile is a whole number: higher is considered first.', 'wc-checkoutsuite' ),
						array( 'profile' => $id )
					)
				);
			}

			if ( $profile->is_fallback() ) {
				++$fallback;
			}

			$result = $result->merge( self::rename( ConditionValidator::validate_tree( $id, $profile->conditions() ), $id ) );
			$result = $result->merge( self::validate_sections( $profile ) );
		}

		if ( $fallback > 1 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'multiple_fallback_profiles',
					sprintf(
						/* translators: %d: number of profiles marked as fallback */
						__( '%d checkout profiles are marked as the fallback. Only one can answer a cart that matches nothing.', 'wc-checkoutsuite' ),
						$fallback
					),
					array( 'count' => $fallback )
				)
			);
		}

		return $result;
	}

	/**
	 * Whether a stored priority is a whole number.
	 *
	 * JSON has one number type, so a priority that arrived as `2.0` is a float and still a whole
	 * number; `2.5` is not a priority at all. The check is on the value, not on the type it came in
	 * as, because the store reads its own option back and not the request that wrote it.
	 *
	 * @param mixed $value Raw priority.
	 * @return bool
	 */
	private static function is_whole_number( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}

		return is_float( $value ) && floor( $value ) === $value;
	}

	/**
	 * Validates the containers of one profile.
	 *
	 * @param CheckoutProfile $profile Profile.
	 * @return ValidationResult
	 */
	private static function validate_sections( CheckoutProfile $profile ): ValidationResult {
		$id       = $profile->id();
		$sections = array();
		$result   = ValidationResult::valid();

		foreach ( $profile->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				$sections[] = $raw;

				continue;
			}

			$container = ContainerDefinition::from_array( $raw );

			if ( ! $container->is_offered_in( CheckoutProfileResolver::CHECKOUT ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'profile_section_not_a_checkout_container',
						sprintf(
							/* translators: %s: container id */
							__( 'The container "%s" is not offered in the checkout, so a checkout profile cannot compose it.', 'wc-checkoutsuite' ),
							$container->id()
						),
						array(
							'profile' => $id,
							'section' => $container->id(),
						)
					)
				);

				continue;
			}

			$sections[] = $raw;
		}

		return $result->merge( self::rename( SectionValidator::validate_sections( $sections ), $id ) );
	}

	/**
	 * Re-labels the errors of an owned rule so they point at the profile.
	 *
	 * The shared validators report the owner of a rule as `field`, which is right for a field and
	 * wrong here. The code and the message are the shared ones — this moves the pointer, it does not
	 * translate the diagnosis.
	 *
	 * @param ValidationResult $result  Result to re-label.
	 * @param string           $profile Profile id.
	 * @return ValidationResult
	 */
	private static function rename( ValidationResult $result, string $profile ): ValidationResult {
		if ( $result->is_valid() ) {
			return $result;
		}

		$renamed = ValidationResult::valid();

		foreach ( $result->errors() as $error ) {
			$context = $error['context'];

			unset( $context['field'] );

			$context['profile'] = $profile;

			$renamed = $renamed->merge(
				ValidationResult::invalid( (string) $error['code'], (string) $error['message'], $context )
			);
		}

		return $renamed;
	}
}
