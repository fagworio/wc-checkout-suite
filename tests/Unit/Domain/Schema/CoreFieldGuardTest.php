<?php
/**
 * Core field protection tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Schema;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;

/**
 * Proves that a field WooCommerce owns cannot be destroyed through the schema.
 *
 * The acceptance for WCCS-016 asks for "campos core protegidos". These tests are
 * the definition of what that means, case by case, including the two ways a
 * naive implementation would let protection be bypassed: relabelling the field as
 * custom, and duplicating its identifier so the guard matches the wrong entry.
 */
final class CoreFieldGuardTest extends TestCase {

	/**
	 * Guard under test.
	 *
	 * @var CoreFieldGuard
	 */
	private CoreFieldGuard $guard;

	/**
	 * Sets up the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->guard = new CoreFieldGuard();
	}

	/**
	 * A stored core field, as the repository would hold it.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private function core_field( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'billing_first_name',
				'integration_id' => 'billing_first_name',
				'origin'         => 'core',
				'type'           => 'text',
				'label'          => 'First name',
				'section'        => 'billing',
				'enabled'        => true,
				'required'       => true,
				'position'       => 10,
			),
			$overrides
		);
	}

	/**
	 * A stored custom field.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private function custom_field( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'billing_document',
				'integration_id' => 'wc-checkoutsuite/billing-document',
				'origin'         => 'custom',
				'type'           => 'text',
				'label'          => 'CPF',
				'section'        => 'billing',
				'enabled'        => true,
				'required'       => false,
				'position'       => 20,
			),
			$overrides
		);
	}

	/**
	 * Asserts a result failed with a specific code.
	 *
	 * @param \WCCheckoutSuite\Domain\Fields\ValidationResult $result Result.
	 * @param string                                          $code   Expected error code.
	 * @return void
	 */
	private function assertRejectedWith( $result, string $code ): void {
		$this->assertFalse( $result->is_valid(), "Expected a rejection with code {$code}." );
		$this->assertContains( $code, $result->error_codes(), 'Codes: ' . implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An unchanged document is accepted.
	 *
	 * @return void
	 */
	public function test_unchanged_document_is_accepted(): void {
		$fields = array( $this->core_field(), $this->custom_field() );

		$this->assertTrue( $this->guard->guard( $fields, $fields )->is_valid() );
	}

	/**
	 * A core field cannot be removed.
	 *
	 * @return void
	 */
	public function test_core_field_cannot_be_removed(): void {
		$result = $this->guard->guard(
			array( $this->core_field(), $this->custom_field() ),
			array( $this->custom_field() )
		);

		$this->assertRejectedWith( $result, 'core_field_removed' );
	}

	/**
	 * A core field cannot be disabled.
	 *
	 * @return void
	 */
	public function test_core_field_cannot_be_archived(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array( $this->core_field( array( 'enabled' => false ) ) )
		);

		$this->assertRejectedWith( $result, 'core_field_disabled' );
	}

	/**
	 * A core field cannot change what it stores.
	 *
	 * @return void
	 */
	public function test_core_field_type_cannot_change(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array( $this->core_field( array( 'type' => 'hidden' ) ) )
		);

		$this->assertRejectedWith( $result, 'core_field_type_changed' );
	}

	/**
	 * A core field cannot change its integration key.
	 *
	 * @return void
	 */
	public function test_core_field_identifier_cannot_change(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array( $this->core_field( array( 'integration_id' => 'something-else' ) ) )
		);

		$this->assertRejectedWith( $result, 'core_field_identifier_changed' );
	}

	/**
	 * A requirement WooCommerce imposes cannot be relaxed silently.
	 *
	 * @return void
	 */
	public function test_core_requirement_cannot_be_relaxed(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array( $this->core_field( array( 'required' => false ) ) )
		);

		$this->assertRejectedWith( $result, 'core_field_requirement_relaxed' );
	}

	/**
	 * Relabelling a core field as custom is the obvious bypass, and it fails.
	 *
	 * @return void
	 */
	public function test_origin_cannot_be_laundered_to_custom(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array(
				$this->core_field(
					array(
						'origin'  => 'custom',
						'enabled' => false,
					)
				),
			)
		);

		$this->assertRejectedWith( $result, 'core_field_origin_changed' );
	}

	/**
	 * Duplicated identifiers are refused because they make matching ambiguous.
	 *
	 * @return void
	 */
	public function test_duplicate_identifiers_are_refused(): void {
		$result = $this->guard->guard(
			array(),
			array( $this->custom_field(), $this->custom_field( array( 'label' => 'Copy' ) ) )
		);

		$this->assertRejectedWith( $result, 'duplicate_field_id' );
	}

	/**
	 * A duplicated identifier cannot be used to smuggle a core field out.
	 *
	 * The second entry would be the one matched while the first is the one read.
	 *
	 * @return void
	 */
	public function test_duplicate_cannot_smuggle_a_core_field_out(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array(
				$this->core_field(),
				$this->core_field( array( 'origin' => 'custom' ) ),
			)
		);

		$this->assertRejectedWith( $result, 'duplicate_field_id' );
	}

	/**
	 * A custom field may be removed, archived and retyped freely.
	 *
	 * Protection that also froze custom fields would make the product useless,
	 * so the limit of the rule is asserted, not just the rule.
	 *
	 * @return void
	 */
	public function test_custom_fields_are_not_frozen(): void {
		$result = $this->guard->guard(
			array( $this->custom_field() ),
			array()
		);

		$this->assertTrue( $result->is_valid() );

		$retyped = $this->guard->guard(
			array( $this->custom_field() ),
			array(
				$this->custom_field(
					array(
						'origin'  => 'custom',
						'type'    => 'select',
						'enabled' => false,
					)
				),
			)
		);

		$this->assertTrue( $retyped->is_valid() );
	}

	/**
	 * Presentation changes to a core field are exactly what the suite is for.
	 *
	 * @return void
	 */
	public function test_core_presentation_can_change(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array(
				$this->core_field(
					array(
						'label'    => 'Nome',
						'section'  => 'shipping',
						'position' => 5,
						'layout'   => array(
							'desktop' => 6,
							'tablet'  => 6,
							'mobile'  => 12,
						),
						'settings' => array( 'placeholder' => 'Como no documento' ),
					)
				),
			)
		);

		$this->assertTrue( $result->is_valid(), 'Codes: ' . implode( ', ', $result->error_codes() ) );
	}

	/**
	 * Adopting a core field for the first time is an addition, not a change.
	 *
	 * @return void
	 */
	public function test_adopting_a_core_field_is_allowed(): void {
		$result = $this->guard->guard(
			array( $this->custom_field() ),
			array( $this->custom_field(), $this->core_field() )
		);

		$this->assertTrue( $result->is_valid(), 'Codes: ' . implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A core field adopted into a draft can still be dropped before publishing.
	 *
	 * The baseline is what is stored, so removing something never stored is not a
	 * removal of anything.
	 *
	 * @return void
	 */
	public function test_core_field_added_to_the_same_document_can_be_dropped_again(): void {
		$result = $this->guard->guard( array(), array( $this->custom_field() ) );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * A field that was already disabled stays disabled without a new complaint.
	 *
	 * @return void
	 */
	public function test_an_already_disabled_core_field_stays_disabled(): void {
		$disabled = $this->core_field(
			array(
				'enabled'  => false,
				'required' => false,
			)
		);

		$this->assertTrue( $this->guard->guard( array( $disabled ), array( $disabled ) )->is_valid() );
	}

	/**
	 * Every violation in one document is reported, not only the first.
	 *
	 * A guard that stopped at the first problem would make a merchant fix one
	 * thing at a time and discover the rest by trial.
	 *
	 * @return void
	 */
	public function test_all_violations_are_reported_together(): void {
		$result = $this->guard->guard(
			array( $this->core_field() ),
			array(
				$this->core_field(
					array(
						'type'     => 'hidden',
						'required' => false,
						'enabled'  => false,
					)
				),
			)
		);

		$this->assertContains( 'core_field_type_changed', $result->error_codes() );
		$this->assertContains( 'core_field_disabled', $result->error_codes() );
		$this->assertContains( 'core_field_requirement_relaxed', $result->error_codes() );
	}
}
