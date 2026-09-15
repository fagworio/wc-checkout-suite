<?php
/**
 * Section validation tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Sections;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;
use WCCheckoutSuite\Domain\Sections\SectionValidator;

/**
 * Covers the two gaps this task closed.
 *
 * Sections were stored without ever being validated, so a document could carry a
 * section with no title or two sections sharing an identifier. And a field's
 * section was never checked against anything, so it could point at a section that
 * does not exist — which on a checkout means a field that is configured, saved,
 * and rendered nowhere.
 */
final class SectionValidatorTest extends TestCase {

	/**
	 * Builds a valid section.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private function section( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 'dados_extras',
				'title'       => 'Dados extras',
				'description' => '',
				'position'    => 10,
				'location'    => 'billing',
			),
			$overrides
		);
	}

	/**
	 * A well-formed section is accepted.
	 *
	 * @return void
	 */
	public function test_a_valid_section_is_accepted(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section() ) );

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A section needs an identifier.
	 *
	 * @return void
	 */
	public function test_a_section_requires_an_id(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'id' => '' ) ) ) );

		self::assertContains( 'section_missing_id', $result->error_codes() );
	}

	/**
	 * An identifier the stored fields could not point at is refused.
	 *
	 * @return void
	 */
	public function test_a_section_id_must_be_machine_readable(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array( $this->section( array( 'id' => 'Dados Extras' ) ) )
		);

		self::assertContains( 'section_invalid_id', $result->error_codes() );
	}

	/**
	 * A section needs a title.
	 *
	 * @return void
	 */
	public function test_a_section_requires_a_title(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'title' => '' ) ) ) );

		self::assertContains( 'section_missing_title', $result->error_codes() );
	}

	/**
	 * A negative position is refused.
	 *
	 * @return void
	 */
	public function test_a_negative_position_is_refused(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'position' => -1 ) ) ) );

		self::assertContains( 'section_invalid_position', $result->error_codes() );
	}

	/**
	 * Only the five domain concepts are locations.
	 *
	 * @return void
	 */
	public function test_a_location_must_be_a_domain_concept(): void {
		$result = SectionValidator::validate( SectionDefinition::from_array( $this->section( array( 'location' => 'sidebar' ) ) ) );

		self::assertContains( 'section_unknown_location', $result->error_codes() );
	}

	/**
	 * Two sections cannot share an identifier.
	 *
	 * @return void
	 */
	public function test_duplicate_section_ids_are_refused(): void {
		$result = SectionValidator::validate_sections(
			array(
				$this->section(),
				$this->section( array( 'title' => 'Outra' ) ),
			)
		);

		self::assertContains( 'duplicate_section_id', $result->error_codes() );
	}

	/**
	 * My Account sections need the endpoint details the runtime will register.
	 *
	 * @return void
	 */
	public function test_my_account_section_requires_endpoint_presentation(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array(
				$this->section(
					array(
						'areas' => array( 'customer_account' ),
					)
				)
			)
		);

		self::assertContains( 'invalid_account_endpoint_slug', $result->error_codes() );
		self::assertContains( 'invalid_account_menu_label', $result->error_codes() );
	}

	/**
	 * Two sections cannot register the same public account URL.
	 *
	 * @return void
	 */
	public function test_duplicate_my_account_endpoint_slugs_are_refused(): void {
		$presentation = array(
			'account' => array(
				'slug'       => 'meus-documentos',
				'menu_label' => 'Meus documentos',
				'icon'       => 'file',
				'position'   => 5,
				'mode'       => 'edit',
			),
		);
		$result       = SectionValidator::validate_sections(
			array(
				$this->section(
					array(
						'areas'        => array( 'customer_account' ),
						'presentation' => $presentation,
					)
				),
				$this->section(
					array(
						'id'           => 'outros_documentos',
						'title'        => 'Outros documentos',
						'areas'        => array( 'customer_account' ),
						'presentation' => $presentation,
					)
				),
			)
		);

		self::assertContains( 'duplicate_account_endpoint_slug', $result->error_codes() );
	}

	/**
	 * A target the domain does not know is refused, even when the location beside it is fine.
	 *
	 * `target` is the canonical key the final model writes, and it is what the adapters read
	 * to decide where a container lands. A document that sets it to something no adapter can
	 * perform is configuration the merchant believes is in place.
	 *
	 * @return void
	 */
	public function test_a_target_must_be_a_domain_concept(): void {
		$refused = SectionValidator::validate(
			SectionDefinition::from_array(
				$this->section(
					array(
						'target'   => 'sidebar',
						'location' => 'billing',
					)
				)
			)
		);

		self::assertFalse( $refused->is_valid() );
		self::assertContains( 'section_unknown_target', $refused->error_codes() );

		$accepted = SectionValidator::validate(
			SectionDefinition::from_array(
				$this->section(
					array(
						'target'   => 'shipping',
						'location' => 'billing',
					)
				)
			)
		);

		self::assertTrue(
			$accepted->is_valid(),
			'the canonical key decides where it lands: ' . implode( ', ', $accepted->error_codes() )
		);
	}

	/**
	 * An account page is the customer's own form, so the field it collects has to
	 * store where that page writes.
	 *
	 * A link whose section is an account page and whose field stores with the order
	 * (or nowhere at all) is configuration the merchant believes is in place while
	 * the page offers a form that saves nothing. The rule can only be asked here,
	 * because it needs the link and the section it names at the same time.
	 *
	 * @return void
	 */
	public function test_an_account_link_requires_a_field_stored_on_the_customer(): void {
		$section = $this->section(
			array(
				'id'           => 'preferencias_do_perfil',
				'title'        => 'Preferências',
				'areas'        => array( 'customer_account' ),
				'presentation' => array(
					'account' => array(
						'slug'       => 'preferencias',
						'menu_label' => 'Preferências',
						'icon'       => 'user',
						'position'   => 0,
						'mode'       => 'edit',
					),
				),
			)
		);

		$field = static function ( array $overrides ): array {
			return array(
				array_merge(
					array(
						'id'           => 'preferencia_perfil',
						'section'      => 'preferencias_do_perfil',
						'storage'      => array( 'scope' => 'customer' ),
						'destinations' => array(
							'customer_account' => array(
								'enabled' => true,
								'section' => 'preferencias_do_perfil',
								'mode'    => 'edit',
							),
						),
					),
					$overrides
				),
			);
		};

		foreach ( array( 'order', 'none', '' ) as $scope ) {
			$overrides = array( 'storage' => array() );

			if ( '' !== $scope ) {
				$overrides = array( 'storage' => array( 'scope' => $scope ) );
			}

			$refused = SectionValidator::validate_references( array( $section ), $field( $overrides ) );

			self::assertContains(
				'account_section_requires_customer_storage',
				$refused->error_codes(),
				'scope=' . ( '' === $scope ? '(absent)' : $scope )
			);
		}

		$accepted = SectionValidator::validate_references(
			array( $section ),
			$field( array( 'storage' => array( 'scope' => 'customer' ) ) )
		);

		self::assertTrue( $accepted->is_valid(), implode( ', ', $accepted->error_codes() ) );
	}

	/**
	 * The rule belongs to the surfaces that write to the customer, not to a shape of
	 * section: a panel offered to staff writes the same store as the customer's page, so
	 * a field linked into either has to keep its value there.
	 *
	 * @return void
	 */
	public function test_both_customer_surfaces_require_customer_storage(): void {
		$sections = array(
			$this->section(
				array(
					'id'    => 'documentos_do_cliente',
					'areas' => array( 'admin_customer_profile' ),
				)
			),
		);

		foreach ( array( 'customer_account', 'admin_customer_profile' ) as $surface ) {
			$refused = SectionValidator::validate_references(
				$sections,
				array(
					array(
						'id'           => 'preferencia_perfil',
						'section'      => 'documentos_do_cliente',
						'storage'      => array( 'scope' => 'order' ),
						'destinations' => array(
							$surface => array(
								'enabled' => true,
								'section' => 'documentos_do_cliente',
							),
						),
					),
				)
			);

			self::assertContains(
				'account_section_requires_customer_storage',
				$refused->error_codes(),
				$surface
			);
		}
	}

	/**
	 * A link that is not into a customer surface is ordinary configuration: the value
	 * travels with the order, and there is nothing to require of it.
	 *
	 * @return void
	 */
	public function test_a_link_outside_the_customer_surfaces_is_not_subject_to_the_rule(): void {
		$result = SectionValidator::validate_references(
			array(
				$this->section(
					array(
						'id'    => 'documentos_enviados',
						'areas' => array( 'customer_order' ),
					)
				),
			),
			array(
				array(
					'id'           => 'preferencia_perfil',
					'section'      => 'documentos_enviados',
					'storage'      => array( 'scope' => 'order' ),
					'destinations' => array(
						'customer_order' => array(
							'enabled' => true,
							'section' => 'documentos_enviados',
						),
					),
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A field may belong to a declared section.
	 *
	 * @return void
	 */
	public function test_a_field_in_a_declared_section_is_accepted(): void {
		$result = SectionValidator::validate_references(
			array( $this->section() ),
			array(
				array(
					'id'      => 'billing_document',
					'section' => 'dados_extras',
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A field may belong to one of the five domain concepts without the document
	 * declaring a section for it, which is what adopting a WooCommerce field does.
	 *
	 * @return void
	 */
	public function test_a_field_in_a_domain_location_needs_no_declaration(): void {
		foreach ( array( 'billing', 'shipping', 'contact', 'account', 'order' ) as $location ) {
			$result = SectionValidator::validate_references(
				array(),
				array(
					array(
						'id'      => 'billing_document',
						'section' => $location,
					),
				)
			);

			self::assertTrue( $result->is_valid(), $location . ': ' . implode( ', ', $result->error_codes() ) );
		}
	}

	/**
	 * A field pointing at a section nobody declared is refused.
	 *
	 * @return void
	 */
	public function test_a_field_in_an_undeclared_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => 'minha_secao',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * Collection happens only in a section that explicitly offers checkout. A
	 * customer/admin-only section can still be a valid display destination, but
	 * cannot be used as the field's own checkout section.
	 *
	 * @return void
	 */
	public function test_a_field_section_is_collected_even_when_areas_are_post_checkout_only(): void {
		$result = SectionValidator::validate_references(
			array(
				$this->section(
					array(
						'id'    => 'customer_documents',
						'areas' => array( 'customer_order' ),
					)
				),
			),
			array(
				array(
					'id'      => 'billing_document',
					'section' => 'customer_documents',
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An empty section is refused rather than assumed.
	 *
	 * @return void
	 */
	public function test_a_field_without_a_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => '',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * A field with no `section` key means "order", the same answer the definition
	 * model gives.
	 *
	 * This is not a hypothetical: the check originally read the raw key, so a
	 * document that omitted it was valid to FieldDefinition and invalid to the
	 * reference check at the same time. A stored document written by an older
	 * build depends on the two agreeing.
	 *
	 * @return void
	 */
	public function test_an_omitted_section_means_the_default_one(): void {
		$result = SectionValidator::validate_references(
			array(),
			array( array( 'id' => 'billing_document' ) )
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An explicit empty string is not the same as an omitted key: it names no
	 * section at all, and is refused.
	 *
	 * @return void
	 */
	public function test_an_explicitly_empty_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'      => 'billing_document',
					'section' => '',
				),
			)
		);

		self::assertContains( 'unknown_section', $result->error_codes() );
	}

	/**
	 * An entry that is not an array is reported rather than skipped.
	 *
	 * @return void
	 */
	public function test_a_non_array_entry_is_reported(): void {
		$result = SectionValidator::validate_sections( array( 'not a section' ) );

		self::assertContains( 'invalid_section_entry', $result->error_codes() );
	}

	/**
	 * A section is offered somewhere. The checkout is what it was before areas
	 * existed, and it keeps working because the definition defaults to it.
	 *
	 * @return void
	 */
	public function test_a_section_without_areas_is_read_as_a_checkout_section(): void {
		$definition = SectionDefinition::from_array( $this->section() );

		self::assertSame( array( 'checkout' ), $definition->areas() );
		self::assertTrue( $definition->is_offered_in( 'checkout' ) );
		self::assertFalse( $definition->is_offered_in( 'admin_order' ) );
	}

	/**
	 * The same section may be offered in more than one area, which is what makes one
	 * answer appear in two places without a second copy of it.
	 *
	 * @return void
	 */
	public function test_a_section_may_be_offered_in_several_areas(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array(
				$this->section(
					array(
						'areas' => array( 'checkout', 'admin_order', 'customer_order' ),
					)
				)
			)
		);

		self::assertTrue( $result->is_valid() );
	}

	/**
	 * An area that is not one a section may be offered in is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_area_is_refused(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array(
				$this->section( array( 'areas' => array( 'checkout', 'sidebar' ) ) )
			)
		);

		self::assertContains( 'section_unknown_area', $result->error_codes() );
	}

	/**
	 * A section offered nowhere cannot be chosen by anything, so it is refused.
	 *
	 * @return void
	 */
	public function test_a_section_offered_nowhere_is_refused(): void {
		$result = SectionValidator::validate(
			SectionDefinition::from_array( $this->section( array( 'areas' => array() ) ) )
		);

		self::assertContains( 'section_without_area', $result->error_codes() );
	}

	/**
	 * A link to a section that is not offered in that destination's area is refused:
	 * it is configuration the merchant believes is in place and which would insert
	 * nothing anywhere.
	 *
	 * @return void
	 */
	public function test_a_destination_link_must_point_at_a_section_offered_there(): void {
		$sections = array(
			array(
				'id'       => 'documentos',
				'title'    => 'Documentos',
				'position' => 10,
				'location' => 'billing',
				'areas'    => array( 'checkout' ),
			),
		);

		$fields = array(
			array(
				'id'           => 'autorizacao',
				'origin'       => 'custom',
				'type'         => 'text',
				'label'        => 'Autorização',
				'section'      => 'billing',
				'destinations' => array(
					'admin_order' => array(
						'enabled' => true,
						'section' => 'documentos',
					),
				),
			),
		);

		$result = SectionValidator::validate_references( $sections, $fields );

		self::assertContains( 'destination_section_not_offered', $result->error_codes() );

		// And the same link is accepted once the section is offered in that area.
		$sections[0]['areas'] = array( 'checkout', 'admin_order' );

		self::assertNotContains(
			'destination_section_not_offered',
			SectionValidator::validate_references( $sections, $fields )->error_codes()
		);
	}

	/**
	 * A link to a section the document does not declare is refused as well.
	 *
	 * @return void
	 */
	public function test_a_destination_link_to_an_undeclared_section_is_refused(): void {
		$result = SectionValidator::validate_references(
			array(),
			array(
				array(
					'id'           => 'autorizacao',
					'origin'       => 'custom',
					'type'         => 'text',
					'label'        => 'Autorização',
					'section'      => 'billing',
					'destinations' => array(
						'customer_order' => array(
							'enabled' => true,
							'section' => 'nao_existe',
						),
					),
				),
			)
		);

		self::assertContains( 'destination_section_not_offered', $result->error_codes() );
	}

	/**
	 * Two sections, one offered in each of two destinations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function two_areas(): array {
		return array(
			$this->section(
				array(
					'id'       => 'documentos_para_analise',
					'position' => 10,
					'areas'    => array( 'admin_order' ),
				)
			),
			$this->section(
				array(
					'id'       => 'documentos_enviados',
					'position' => 20,
					'areas'    => array( 'customer_order' ),
				)
			),
		);
	}

	/**
	 * One use of a field, in the final model.
	 *
	 * @param string               $destination Destination.
	 * @param string               $container   Container.
	 * @param array<string, mixed> $extra       Extra keys.
	 * @return array<string, mixed>
	 */
	private function use_row( string $destination, string $container, array $extra = array() ): array {
		return array_merge(
			array(
				'field_id'     => 'autorizacao',
				'container_id' => $container,
				'destination'  => $destination,
				'visible'      => true,
			),
			$extra
		);
	}

	/**
	 * One field that stores its uses.
	 *
	 * @param array<int, mixed>    $bindings Uses.
	 * @param array<string, mixed> $extra    Extra keys.
	 * @return array<string, mixed>
	 */
	private function bound_field( array $bindings, array $extra = array() ): array {
		return array_merge(
			array(
				'id'       => 'autorizacao',
				'origin'   => 'custom',
				'type'     => 'text',
				'label'    => 'Autorização',
				'section'  => 'billing',
				'bindings' => $bindings,
			),
			$extra
		);
	}

	/**
	 * A use whose container is not offered in its destination is refused.
	 *
	 * The container is the use's own, so the answer is per use: a field may sit in the
	 * section the staff area offers and in the one the customer area offers, and each of
	 * them is asked about its own destination.
	 *
	 * @return void
	 */
	public function test_a_use_in_a_container_the_area_does_not_offer_is_refused(): void {
		$accepted = SectionValidator::validate_references(
			$this->two_areas(),
			array(
				$this->bound_field(
					array(
						$this->use_row( 'admin_order', 'documentos_para_analise' ),
						$this->use_row( 'customer_order', 'documentos_enviados' ),
					)
				),
			)
		);

		self::assertTrue( $accepted->is_valid(), implode( ', ', $accepted->error_codes() ) );

		$refused = SectionValidator::validate_references(
			$this->two_areas(),
			array(
				$this->bound_field(
					array(
						$this->use_row( 'admin_order', 'documentos_para_analise' ),
						$this->use_row( 'admin_order', 'documentos_enviados' ),
					)
				),
			)
		);

		self::assertFalse( $refused->is_valid(), 'the second use names a container of the customer area' );
		self::assertContains( 'destination_section_not_offered', $refused->error_codes() );
	}

	/**
	 * A use is asked about its own destination even when another use names the same container.
	 *
	 * A field used twice in one area can name a container the area offers in the first use
	 * and one it does not in the second; the map keeps one entry, so only the list can tell
	 * them apart.
	 *
	 * @return void
	 */
	public function test_the_second_use_of_an_area_is_checked_on_its_own(): void {
		$refused = SectionValidator::validate_references(
			$this->two_areas(),
			array(
				$this->bound_field(
					array(
						$this->use_row( 'admin_order', 'documentos_para_analise', array( 'position' => 10 ) ),
						$this->use_row( 'admin_order', 'documentos_enviados', array( 'position' => 20 ) ),
					)
				),
			)
		);

		self::assertContains( 'destination_section_not_offered', $refused->error_codes() );
	}

	/**
	 * A use that offers a form has to store where that form writes.
	 *
	 * The rule is about the use, not the field: a use that is not visible offers no form,
	 * so it makes no promise, while the visible one still has to store with the customer.
	 *
	 * @return void
	 */
	public function test_each_visible_use_on_a_customer_surface_is_asked_about_storage(): void {
		$sections = array(
			$this->section(
				array(
					'id'           => 'preferencias_do_perfil',
					'title'        => 'Preferências',
					'areas'        => array( 'customer_account' ),
					'presentation' => array(
						'account' => array(
							'slug'       => 'preferencias',
							'menu_label' => 'Preferências',
							'icon'       => 'user',
							'position'   => 0,
							'mode'       => 'edit',
						),
					),
				)
			),
		);

		$refused = SectionValidator::validate_references(
			$sections,
			array(
				$this->bound_field(
					array(
						$this->use_row( 'customer_account', 'preferencias_do_perfil' ),
					),
					array( 'storage' => array( 'scope' => 'order' ) )
				),
			)
		);

		self::assertContains( 'account_section_requires_customer_storage', $refused->error_codes() );

		$hidden = SectionValidator::validate_references(
			$sections,
			array(
				$this->bound_field(
					array(
						$this->use_row( 'customer_account', 'preferencias_do_perfil', array( 'visible' => false ) ),
					),
					array( 'storage' => array( 'scope' => 'order' ) )
				),
			)
		);

		self::assertTrue(
			$hidden->is_valid(),
			'a use nobody sees offers no form, so it promises nothing: ' . implode( ', ', $hidden->error_codes() )
		);
	}
}
