<?php
/**
 * A checkout profile the store cannot decide must not be stored.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Checkout\ProfileValidator;

/**
 * The rules a stored profile has to satisfy.
 *
 * Every one of them exists because the alternative is a profile that answers nothing and says
 * nothing: an id nobody can name, a rule in another dialect, two fallbacks, or a container the
 * profile promises and the composition never inserts. The complaint has to come from the validator
 * and not from a silently absent checkout.
 */
final class ProfileValidatorTest extends TestCase {

	/**
	 * A container offered in the checkout.
	 *
	 * @param string $id Container id.
	 * @return array<string, mixed>
	 */
	private static function container( string $id = 'contato' ): array {
		return array(
			'id'       => $id,
			'title'    => $id,
			'position' => 10,
			'location' => 'billing',
			'areas'    => array( 'checkout' ),
		);
	}

	/**
	 * A valid profile, with overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private static function profile( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => 'digital',
				'name'     => 'Checkout digital',
				'source'   => 'duplicate_profile',
				'priority' => 1,
				'sections' => array( self::container() ),
			),
			$overrides
		);
	}

	/**
	 * A profile with an id, a name, a known source and a checkout container is accepted.
	 *
	 * @return void
	 */
	public function test_a_complete_profile_is_accepted(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile() ) );

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A store that never wrote a profile is a store the validator has nothing to say about.
	 *
	 * @return void
	 */
	public function test_no_profiles_is_valid(): void {
		self::assertTrue( ProfileValidator::validate_profiles( array() )->is_valid() );
	}

	/**
	 * A profile with no id can never be selected.
	 *
	 * @return void
	 */
	public function test_an_id_is_required(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'id' => '' ) ) ) );

		self::assertContains( 'profile_id_required', $result->error_codes() );
	}

	/**
	 * Two profiles with the same id make one name mean two compositions.
	 *
	 * @return void
	 */
	public function test_the_id_has_to_be_unique(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile(), self::profile() ) );

		self::assertContains( 'duplicate_profile_id', $result->error_codes() );
	}

	/**
	 * The merchant has to be able to tell one checkout from another.
	 *
	 * @return void
	 */
	public function test_a_name_is_required(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'name' => '  ' ) ) ) );

		self::assertContains( 'profile_name_required', $result->error_codes() );
	}

	/**
	 * The source is a closed list (§3.4).
	 *
	 * @return void
	 */
	public function test_an_unknown_source_is_refused(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'source' => 'imported_from_a_dream' ) ) ) );

		self::assertContains( 'profile_source_unknown', $result->error_codes() );
	}

	/**
	 * A fractional priority is not a priority.
	 *
	 * @return void
	 */
	public function test_a_fractional_priority_is_refused(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'priority' => 1.5 ) ) ) );

		self::assertContains( 'profile_priority_not_an_integer', $result->error_codes() );
	}

	/**
	 * A whole number that arrived as a float is still a whole number.
	 *
	 * JSON has one number type, so refusing `2.0` would refuse a file the plugin itself wrote.
	 *
	 * @return void
	 */
	public function test_a_whole_number_written_as_a_float_is_accepted(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'priority' => 2.0 ) ) ) );

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * Two fallbacks have no answer, so the store is not allowed to have them.
	 *
	 * @return void
	 */
	public function test_only_one_profile_may_be_the_fallback(): void {
		$result = ProfileValidator::validate_profiles(
			array(
				self::profile(
					array(
						'id'       => 'um',
						'fallback' => true,
					)
				),
				self::profile(
					array(
						'id'       => 'dois',
						'fallback' => true,
					)
				),
			)
		);

		self::assertContains( 'multiple_fallback_profiles', $result->error_codes() );
	}

	/**
	 * A profile composes the checkout, so a container it offers elsewhere is refused by name.
	 *
	 * The composition replaces the checkout containers; a container for the order panel declared
	 * here would be promised by the profile and never inserted as the profile promised it.
	 *
	 * @return void
	 */
	public function test_a_container_that_is_not_a_checkout_container_is_refused(): void {
		$result = ProfileValidator::validate_profiles(
			array(
				self::profile(
					array(
						'sections' => array(
							array(
								'id'       => 'documentos',
								'title'    => 'Documentos',
								'position' => 10,
								'location' => 'order',
								'areas'    => array( 'admin_order' ),
							),
						),
					)
				),
			)
		);

		self::assertContains( 'profile_section_not_a_checkout_container', $result->error_codes() );
	}

	/**
	 * The rules of a profile are the shared rules (§14), and a bad one is refused as such.
	 *
	 * @return void
	 */
	public function test_a_rule_in_another_dialect_is_refused(): void {
		$result = ProfileValidator::validate_profiles(
			array(
				self::profile(
					array(
						'conditions' => array(
							'all' => array(
								array(
									'source'   => 'cart_total',
									'operator' => 'greater_than',
									'value'    => 100,
								),
								array( 'source' => 'cart_total' ),
							),
						),
					)
				),
			)
		);

		self::assertFalse( $result->is_valid() );
		self::assertContains( 'invalid_condition_node', $result->error_codes() );
	}

	/**
	 * A valid rule tree is accepted.
	 *
	 * @return void
	 */
	public function test_a_rule_tree_is_accepted(): void {
		$result = ProfileValidator::validate_profiles(
			array(
				self::profile(
					array(
						'conditions' => array(
							'any' => array(
								array(
									'source'   => 'cart_categories',
									'operator' => 'contains',
									'value'    => 'quimicos',
								),
							),
						),
					)
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An error says which profile it is about.
	 *
	 * A message that named only the rule would leave a merchant with two profiles guessing which
	 * of them the store refused.
	 *
	 * @return void
	 */
	public function test_an_error_names_the_profile(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile( array( 'source' => 'nope' ) ) ) );
		$errors = $result->errors();

		self::assertNotSame( array(), $errors );
		self::assertSame( 'digital', $errors[0]['context']['profile'] ?? null );
	}

	/**
	 * An entry that is not a definition is reported rather than skipped.
	 *
	 * @return void
	 */
	public function test_a_non_definition_entry_is_refused(): void {
		$result = ProfileValidator::validate_profiles( array( 'digital' ) );

		self::assertContains( 'invalid_profile_entry', $result->error_codes() );
	}

	/**
	 * A profile that is not the fallback and carries no rule is a valid always-on checkout.
	 *
	 * The design decision: it always matches, so it wins wherever its priority puts it. That is a
	 * composition the merchant asked for, so the validator must not refuse it.
	 *
	 * @return void
	 */
	public function test_an_unconditional_profile_that_is_not_the_fallback_is_accepted(): void {
		$result = ProfileValidator::validate_profiles( array( self::profile() ) );

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}
}
