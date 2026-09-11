<?php
/**
 * Definition validator tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\CoreTypes;
use WCCheckoutSuite\Domain\Fields\DefinitionValidator;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\PresetRegistry;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;
use WCCheckoutSuite\Domain\Validation\CoreProcessing;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;

/**
 * Covers the rules that decide whether a definition may be stored.
 *
 * The origin rule is the one that matters most here. CoreFieldGuard protects a
 * field because it is *stored* as core, so a definition that claimed an
 * identifier WooCommerce owns while declaring itself custom would be a way around
 * every other protection. It is the rule that has to hold before anything else
 * can be believed.
 */
final class DefinitionValidatorTest extends TestCase {

	/**
	 * Builds a validator whose core field list is chosen by the test.
	 *
	 * @param array<int, string> $core_ids Identifiers WooCommerce owns.
	 * @return DefinitionValidator
	 */
	private function validator( array $core_ids = array() ): DefinitionValidator {
		$types       = new FieldTypeRegistry();
		$presets     = new PresetRegistry();
		$validators  = new ValidatorRegistry();
		$normalizers = new NormalizerRegistry();

		$masks = new MaskRegistry();

		CoreTypes::register_types( $types );
		CoreTypes::register_presets( $presets );
		CoreProcessing::register_normalizers( $normalizers );
		CoreProcessing::register_masks( $masks );

		return new DefinitionValidator( $types, $presets, $validators, $normalizers, $core_ids, $masks );
	}

	/**
	 * A definition that declares the core origin for a core id is accepted.
	 *
	 * @return void
	 */
	public function test_a_core_override_is_accepted(): void {
		$result = $this->validator( array( 'billing_first_name' ) )->validate_array(
			array(
				'id'     => 'billing_first_name',
				'origin' => 'core',
				'type'   => 'text',
				'label'  => 'Nome',
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * Claiming a core identifier as custom is refused.
	 *
	 * @return void
	 */
	public function test_claiming_a_core_id_as_custom_is_refused(): void {
		$result = $this->validator( array( 'billing_first_name' ) )->validate_array(
			array(
				'id'     => 'billing_first_name',
				'origin' => 'custom',
				'type'   => 'text',
				'label'  => 'Nome',
			)
		);

		self::assertFalse( $result->is_valid() );
		self::assertContains( 'core_field_origin_required', $result->error_codes() );
	}

	/**
	 * A definition with no origin at all defaults to custom, so it is refused
	 * for a core id. The default must not become a quiet exemption.
	 *
	 * @return void
	 */
	public function test_an_omitted_origin_does_not_slip_through(): void {
		$result = $this->validator( array( 'billing_first_name' ) )->validate_array(
			array(
				'id'    => 'billing_first_name',
				'type'  => 'text',
				'label' => 'Nome',
			)
		);

		self::assertContains( 'core_field_origin_required', $result->error_codes() );
	}

	/**
	 * An identifier WooCommerce does not own is unaffected.
	 *
	 * @return void
	 */
	public function test_a_custom_identifier_is_unaffected(): void {
		$result = $this->validator( array( 'billing_first_name' ) )->validate_array(
			array(
				'id'     => 'billing_document',
				'origin' => 'custom',
				'type'   => 'text',
				'label'  => 'CPF',
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * With no core fields, the rule is inert rather than wrong.
	 *
	 * That is the honest state when WooCommerce is absent: there is nothing to
	 * protect, so nothing is refused.
	 *
	 * @return void
	 */
	public function test_the_rule_is_inert_without_core_fields(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'     => 'billing_first_name',
				'origin' => 'custom',
				'type'   => 'text',
				'label'  => 'Nome',
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}
	/**
	 * A mask is refused on a type that cannot be masked.
	 *
	 * @return void
	 */
	public function test_a_mask_is_refused_where_it_is_not_supported(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'     => 'billing_mail',
				'origin' => 'custom',
				'type'   => 'email',
				'label'  => 'Email',
				'mask'   => array(
					'key'     => 'numeric',
					'version' => 1,
				),
			)
		);

		self::assertContains( 'mask_not_supported', $result->error_codes() );
	}

	/**
	 * An unknown mask key is refused.
	 *
	 * @return void
	 */
	public function test_an_unregistered_mask_is_refused(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'     => 'billing_phone',
				'origin' => 'custom',
				'type'   => 'tel',
				'label'  => 'Phone',
				'mask'   => array(
					'key'     => 'br.cpf',
					'version' => 1,
				),
			)
		);

		self::assertContains( 'unknown_mask', $result->error_codes() );
	}

	/**
	 * A mask version that no longer matches is refused, so a changed mask is
	 * noticed instead of silently altering what the field accepts.
	 *
	 * @return void
	 */
	public function test_a_stale_mask_version_is_refused(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'     => 'billing_phone',
				'origin' => 'custom',
				'type'   => 'tel',
				'label'  => 'Phone',
				'mask'   => array(
					'key'     => 'numeric',
					'version' => 7,
				),
			)
		);

		self::assertContains( 'mask_version_stale', $result->error_codes() );
	}

	/**
	 * A correct mask reference is accepted.
	 *
	 * @return void
	 */
	public function test_a_current_mask_is_accepted(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'     => 'billing_phone',
				'origin' => 'custom',
				'type'   => 'tel',
				'label'  => 'Phone',
				'mask'   => array(
					'key'     => 'numeric',
					'version' => 1,
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * A type that stores nothing cannot claim to keep its value with the order.
	 *
	 * @return void
	 */
	public function test_a_valueless_type_cannot_claim_order_storage(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'       => 'intro_note',
				'origin'   => 'custom',
				'type'     => 'heading',
				'label'    => 'Intro',
				'settings' => array( 'content' => 'Bem-vindo' ),
			)
		);

		self::assertContains( 'storage_scope_requires_value', $result->error_codes() );
	}

	/**
	 * The same type is accepted once it declares that nothing is stored.
	 *
	 * @return void
	 */
	public function test_a_valueless_type_is_accepted_without_storage(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'         => 'intro_note',
				'origin'     => 'custom',
				'type'       => 'heading',
				'label'      => 'Intro',
				'settings'   => array( 'content' => 'Bem-vindo' ),
				'storage'    => array(
					'scope'       => 'none',
					'sensitivity' => 'public',
				),
				'visibility' => array( 'admin_order' => false ),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * An off-list storage scope is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_storage_scope_is_refused(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'      => 'billing_document',
				'origin'  => 'custom',
				'type'    => 'text',
				'label'   => 'CPF',
				'storage' => array(
					'scope'       => 'redis',
					'sensitivity' => 'personal',
				),
			)
		);

		self::assertContains( 'unknown_storage_scope', $result->error_codes() );
	}

	/**
	 * An audience that is not one a field can be shown to is refused rather than
	 * dropped, because dropping it would let a caller believe it had configured an
	 * exposure it never configured.
	 *
	 * @return void
	 */
	public function test_an_unknown_audience_is_refused(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'         => 'billing_document',
				'origin'     => 'custom',
				'type'       => 'text',
				'label'      => 'CPF',
				'visibility' => array(
					'admin_order' => true,
					'twitter'     => true,
				),
			)
		);

		self::assertContains( 'unknown_visibility_audience', $result->error_codes() );
	}

	/**
	 * A value that does not exist cannot be exposed through the Store API.
	 *
	 * @return void
	 */
	public function test_a_valueless_type_cannot_be_exposed(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'         => 'intro_note',
				'origin'     => 'custom',
				'type'       => 'heading',
				'label'      => 'Intro',
				'settings'   => array( 'content' => 'Bem-vindo' ),
				'storage'    => array(
					'scope'       => 'none',
					'sensitivity' => 'public',
				),
				'visibility' => array( 'public_api' => true ),
			)
		);

		self::assertContains( 'visibility_exposes_missing_value', $result->error_codes() );
	}

	/**
	 * An option that is not a value/label pair is refused.
	 *
	 * @return void
	 */
	public function test_an_option_must_be_a_pair(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'       => 'person_type',
				'origin'   => 'custom',
				'type'     => 'select',
				'label'    => 'Tipo',
				'settings' => array( 'options' => array( 'banana' ) ),
			)
		);

		self::assertFalse( $result->is_valid() );
		self::assertContains( 'invalid_setting_type', $result->error_codes() );
	}

	/**
	 * An option missing its label is refused.
	 *
	 * @return void
	 */
	public function test_an_option_without_a_label_is_refused(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'       => 'person_type',
				'origin'   => 'custom',
				'type'     => 'select',
				'label'    => 'Tipo',
				'settings' => array( 'options' => array( array( 'value' => 'pf' ) ) ),
			)
		);

		self::assertContains( 'missing_required_setting', $result->error_codes() );
	}

	/**
	 * Well-formed options are accepted.
	 *
	 * @return void
	 */
	public function test_well_formed_options_are_accepted(): void {
		$result = $this->validator()->validate_array(
			array(
				'id'       => 'person_type',
				'origin'   => 'custom',
				'type'     => 'select',
				'label'    => 'Tipo',
				'settings' => array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => 'Pessoa física',
						),
					),
				),
			)
		);

		self::assertTrue( $result->is_valid(), implode( ', ', $result->error_codes() ) );
	}

	/**
	 * The rule can be asked about one definition on its own.
	 *
	 * That is what lets the repository apply it on a draft write without running
	 * the whole validator over a half-finished document.
	 *
	 * @return void
	 */
	public function test_the_origin_rule_can_be_asked_separately(): void {
		$validator = $this->validator( array( 'billing_email' ) );

		self::assertTrue(
			$validator->validate_origin(
				\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
					array(
						'id'     => 'billing_email',
						'origin' => 'core',
						'type'   => 'email',
						'label'  => 'Email',
					)
				)
			)->is_valid()
		);

		self::assertFalse(
			$validator->validate_origin(
				\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
					array(
						'id'     => 'billing_email',
						'origin' => 'custom',
						'type'   => 'email',
						'label'  => 'Email',
					)
				)
			)->is_valid()
		);
	}
}
