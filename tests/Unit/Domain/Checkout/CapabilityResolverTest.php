<?php
/**
 * Capability resolver tests.
 *
 * The resolver answers what each checkout does with a field, and its value is that
 * the answer is *specific*: a level per family and adapter, with the reason. A
 * resolver that returned "limited" for everything would satisfy the shape and tell
 * the merchant nothing.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Checkout\CapabilityResolver;

/**
 * Limits per field, per family.
 */
final class CapabilityResolverTest extends TestCase {

	/**
	 * A section.
	 *
	 * @param string $id       Identifier.
	 * @param string $location Location.
	 * @return array<string, mixed>
	 */
	private function section( string $id, string $location ): array {
		return array(
			'id'       => $id,
			'title'    => $id,
			'location' => $location,
			'position' => 10,
		);
	}

	/**
	 * A definition.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function definition( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => 'wccs_note',
				'type'     => 'textarea',
				'label'    => 'Note',
				'section'  => 'billing',
				'origin'   => 'custom',
				'enabled'  => true,
				'required' => false,
				'layout'   => array(
					'desktop' => 12,
					'tablet'  => 12,
					'mobile'  => 12,
				),
				'settings' => array(),
				'storage'  => array(
					'scope'       => 'order',
					'sensitivity' => 'personal',
				),
			),
			$overrides
		);
	}

	/**
	 * The limits of one field, keyed by family and adapter.
	 *
	 * @param array<int, array<string, mixed>> $fields   Fields.
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return array<string, array<string, mixed>>
	 */
	private function limits( array $fields, array $sections ): array {
		$resolved = CapabilityResolver::resolve( $fields, $sections );
		$indexed  = array();

		foreach ( $resolved[0]['limits'] as $limit ) {
			$indexed[ $limit['family'] . ':' . $limit['adapter'] ] = $limit;
		}

		return $indexed;
	}

	/**
	 * A type the platform does not have is a limit, and it says which one.
	 *
	 * @return void
	 */
	public function test_a_type_the_blocks_checkout_cannot_render_is_reported_as_such(): void {
		$limits = $this->limits(
			array( $this->definition( array( 'type' => 'file' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::UNAVAILABLE, $limits['type:blocks']['level'] );
		self::assertNotSame( '', $limits['type:blocks']['reason'] );
		self::assertSame( CapabilityResolver::UNAVAILABLE, $limits['type:classic']['level'] );
	}

	/**
	 * A controlled type is limited, not unavailable, and the reason says who renders it.
	 *
	 * @return void
	 */
	public function test_a_controlled_type_is_limited_with_its_reason(): void {
		$limits = $this->limits(
			array( $this->definition( array( 'type' => 'date' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::LIMITED, $limits['type:blocks']['level'] );
		self::assertStringContainsString( 'WCCS-037', $limits['type:blocks']['reason'] );
		self::assertSame( CapabilityResolver::PROVIDED, $limits['type:classic']['level'] );
	}

	/**
	 * The width is provided by one checkout and limited by the other.
	 *
	 * @return void
	 */
	public function test_the_width_is_provided_by_one_checkout_and_limited_by_the_other(): void {
		$limits = $this->limits(
			array( $this->definition() ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::PROVIDED, $limits['width:classic']['level'] );
		self::assertSame( CapabilityResolver::LIMITED, $limits['width:blocks']['level'] );
	}

	/**
	 * A width the grid does not offer is reported as not applied.
	 *
	 * @return void
	 */
	public function test_a_width_outside_the_grid_is_reported(): void {
		$limits = $this->limits(
			array(
				$this->definition(
					array(
						'layout' => array(
							'desktop' => 5,
							'tablet'  => 12,
							'mobile'  => 12,
						),
					)
				),
			),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::UNAVAILABLE, $limits['width:all']['level'] );
		self::assertStringContainsString( 'desktop', $limits['width:all']['reason'] );
	}

	/**
	 * A section that is not in the document leaves the field with nowhere to go.
	 *
	 * @return void
	 */
	public function test_a_section_that_is_not_in_the_document_is_unavailable(): void {
		$limits = $this->limits(
			array( $this->definition( array( 'section' => 'nowhere' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::UNAVAILABLE, $limits['section:all']['level'] );
	}

	/**
	 * A contact section is drawn with the billing address by the classic checkout.
	 *
	 * @return void
	 */
	public function test_a_contact_section_is_limited_in_the_classic_checkout(): void {
		$limits = $this->limits(
			array( $this->definition( array( 'section' => 'contact' ) ) ),
			array( $this->section( 'contact', 'contact' ) )
		);

		self::assertSame( CapabilityResolver::LIMITED, $limits['section:classic']['level'] );
		self::assertSame( CapabilityResolver::PROVIDED, $limits['section:blocks']['level'] );
	}

	/**
	 * A core field is limited, and says what can and cannot be done to it.
	 *
	 * @return void
	 */
	public function test_a_core_field_says_what_can_be_done_to_it(): void {
		$limits = $this->limits(
			array( $this->definition( array( 'origin' => 'core' ) ) ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertSame( CapabilityResolver::LIMITED, $limits['core:all']['level'] );
		self::assertStringContainsString( 'cannot be removed', $limits['core:all']['reason'] );
	}

	/**
	 * A custom field carries no core limit at all.
	 *
	 * @return void
	 */
	public function test_a_custom_field_has_no_core_limit(): void {
		$limits = $this->limits(
			array( $this->definition() ),
			array( $this->section( 'billing', 'billing' ) )
		);

		self::assertArrayNotHasKey( 'core:all', $limits );
	}

	/**
	 * Every family the acceptance names is answered for every field.
	 *
	 * @return void
	 */
	public function test_every_family_is_answered(): void {
		$resolved = CapabilityResolver::resolve(
			array( $this->definition() ),
			array( $this->section( 'billing', 'billing' ) )
		);

		$families = array_values( array_unique( array_column( $resolved[0]['limits'], 'family' ) ) );
		sort( $families );

		self::assertSame( array( 'section', 'type', 'width' ), $families );
	}
}
