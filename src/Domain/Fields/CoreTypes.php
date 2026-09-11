<?php
/**
 * Core type and preset registration.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

use WCCheckoutSuite\Domain\Fields\Types\AddressFieldType;
use WCCheckoutSuite\Domain\Fields\Types\CheckboxFieldType;
use WCCheckoutSuite\Domain\Fields\Types\ChoiceFieldType;
use WCCheckoutSuite\Domain\Fields\Types\ContentFieldType;
use WCCheckoutSuite\Domain\Fields\Types\FileFieldType;
use WCCheckoutSuite\Domain\Fields\Types\NumberFieldType;
use WCCheckoutSuite\Domain\Fields\Types\TemporalFieldType;
use WCCheckoutSuite\Domain\Fields\Types\TextFieldType;

/**
 * Registers the field types and Brazilian presets shipped with the plugin.
 *
 * Everything here goes through the public registry API, exactly like an external
 * plugin would. The core has no privileged path, which is what proves that a
 * third party can add a type without editing any core file.
 *
 * @see \ROADMAP.md sections 5 and 6
 */
final class CoreTypes {

	/**
	 * Registers the core field types.
	 *
	 * @param FieldTypeRegistry $types Field type registry.
	 * @return void
	 */
	public static function register_types( FieldTypeRegistry $types ): void {
		// The third argument is the picker category. It is declared here, in the
		// only place that knows the shipped types, and never on the interface:
		// adding a method to the published contract would break extensions.
		// Text group.
		$types->register_type( new TextFieldType( 'text', __( 'Text', 'wc-checkoutsuite' ) ), 'core', 'text' );
		$types->register_type( new TextFieldType( 'textarea', __( 'Textarea', 'wc-checkoutsuite' ), 'plain', true ), 'core', 'text' );
		$types->register_type( new TextFieldType( 'email', __( 'Email', 'wc-checkoutsuite' ), 'email' ), 'core', 'text' );
		$types->register_type( new TextFieldType( 'tel', __( 'Phone', 'wc-checkoutsuite' ), 'tel' ), 'core', 'text' );
		$types->register_type( new TextFieldType( 'url', __( 'URL', 'wc-checkoutsuite' ), 'url' ), 'core', 'text' );

		// Number group.
		$types->register_type( new NumberFieldType(), 'core', 'number' );

		// Choice group.
		$types->register_type( new ChoiceFieldType( 'select', __( 'Select', 'wc-checkoutsuite' ) ), 'core', 'choice' );
		$types->register_type( new ChoiceFieldType( 'multiselect', __( 'Multi Select', 'wc-checkoutsuite' ), true ), 'core', 'choice' );
		$types->register_type( new ChoiceFieldType( 'radio', __( 'Radio', 'wc-checkoutsuite' ) ), 'core', 'choice' );
		$types->register_type( new ChoiceFieldType( 'checkbox-group', __( 'Checkbox Group', 'wc-checkoutsuite' ), true ), 'core', 'choice' );
		$types->register_type( new CheckboxFieldType(), 'core', 'choice' );

		// Date group.
		$types->register_type( new TemporalFieldType( 'date', __( 'Date', 'wc-checkoutsuite' ) ), 'core', 'datetime' );
		$types->register_type( new TemporalFieldType( 'time', __( 'Time', 'wc-checkoutsuite' ) ), 'core', 'datetime' );
		$types->register_type( new TemporalFieldType( 'datetime', __( 'Date and time', 'wc-checkoutsuite' ) ), 'core', 'datetime' );

		// Content group. Only `hidden` stores a value.
		$types->register_type( new ContentFieldType( 'hidden', __( 'Hidden', 'wc-checkoutsuite' ), true ), 'core', 'layout' );
		$types->register_type( new ContentFieldType( 'heading', __( 'Heading', 'wc-checkoutsuite' ) ), 'core', 'layout' );
		$types->register_type( new ContentFieldType( 'paragraph', __( 'Paragraph', 'wc-checkoutsuite' ) ), 'core', 'layout' );
		$types->register_type( new ContentFieldType( 'html', __( 'Restricted HTML', 'wc-checkoutsuite' ) ), 'core', 'layout' );

		// File group. Single or multiple is expressed by the maxFiles setting.
		$types->register_type( new FileFieldType( 'file', __( 'File', 'wc-checkoutsuite' ), true ), 'core', 'upload' );

		// Address group. Allowed lists come from the adapter through the context.
		$types->register_type( new AddressFieldType( 'country', __( 'Country', 'wc-checkoutsuite' ), 'countries' ), 'core', 'address' );
		$types->register_type( new AddressFieldType( 'state', __( 'State', 'wc-checkoutsuite' ), 'states' ), 'core', 'address' );
	}

	/**
	 * Registers the Brazilian presets.
	 *
	 * Presets are data only: they name a type and pre-fill settings. A merchant
	 * created preset may never contain executable PHP or JavaScript.
	 *
	 * Masks and mathematical validators are intentionally absent here: they are
	 * delivered by WCCS-026 (presets), WCCS-027 (masks) and WCCS-028 (validators),
	 * so a preset must not reference a validator that does not exist yet.
	 *
	 * @param PresetRegistry $presets Preset registry.
	 * @return void
	 */
	public static function register_presets( PresetRegistry $presets ): void {
		$presets->register_preset(
			new Preset(
				'br.cpf',
				__( 'CPF', 'wc-checkoutsuite' ),
				'text',
				array( 'label' => __( 'CPF', 'wc-checkoutsuite' ) ),
				array(
					'placeholder' => '000.000.000-00',
					'maxLength'   => 14,
				),
				'br'
			)
		);
		$presets->register_preset(
			new Preset(
				'br.cnpj',
				__( 'CNPJ', 'wc-checkoutsuite' ),
				'text',
				array( 'label' => __( 'CNPJ', 'wc-checkoutsuite' ) ),
				array(
					'placeholder' => '00.000.000/AAAA-00',
					'maxLength'   => 18,
				),
				'br'
			)
		);
		$presets->register_preset( new Preset( 'br.rg', __( 'RG', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'RG', 'wc-checkoutsuite' ) ), array( 'maxLength' => 20 ), 'br' ) );
		$presets->register_preset(
			new Preset(
				'br.cep',
				__( 'CEP', 'wc-checkoutsuite' ),
				'text',
				array( 'label' => __( 'CEP', 'wc-checkoutsuite' ) ),
				array(
					'placeholder' => '00000-000',
					'maxLength'   => 9,
				),
				'br'
			)
		);
		$presets->register_preset( new Preset( 'br.phone.landline', __( 'Landline phone', 'wc-checkoutsuite' ), 'tel', array( 'label' => __( 'Phone', 'wc-checkoutsuite' ) ), array( 'placeholder' => '(00) 0000-0000' ), 'br' ) );
		$presets->register_preset( new Preset( 'br.phone.mobile', __( 'Mobile phone', 'wc-checkoutsuite' ), 'tel', array( 'label' => __( 'Mobile phone', 'wc-checkoutsuite' ) ), array( 'placeholder' => '(00) 00000-0000' ), 'br' ) );
		$presets->register_preset( new Preset( 'br.person-type', __( 'Person type (PF/PJ)', 'wc-checkoutsuite' ), 'radio', array( 'label' => __( 'Person type', 'wc-checkoutsuite' ) ), array(), 'br' ) );
		$presets->register_preset( new Preset( 'br.company-name', __( 'Legal name', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Legal name', 'wc-checkoutsuite' ) ), array( 'maxLength' => 200 ), 'br' ) );
		$presets->register_preset( new Preset( 'br.trade-name', __( 'Trade name', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Trade name', 'wc-checkoutsuite' ) ), array( 'maxLength' => 200 ), 'br' ) );
		$presets->register_preset( new Preset( 'br.address.number', __( 'Address number', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Number', 'wc-checkoutsuite' ) ), array( 'maxLength' => 20 ), 'br' ) );
		$presets->register_preset( new Preset( 'br.address.neighborhood', __( 'Neighborhood', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Neighborhood', 'wc-checkoutsuite' ) ), array( 'maxLength' => 120 ), 'br' ) );
		$presets->register_preset( new Preset( 'br.address.complement', __( 'Address complement', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Complement', 'wc-checkoutsuite' ) ), array( 'maxLength' => 120 ), 'br' ) );

		// Declared optional in the planning: they ship disabled and are enabled deliberately.
		$presets->register_preset( new Preset( 'br.state-registration', __( 'State registration', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'State registration', 'wc-checkoutsuite' ) ), array( 'maxLength' => 20 ), 'br', false ) );
		$presets->register_preset( new Preset( 'br.municipal-registration', __( 'Municipal registration', 'wc-checkoutsuite' ), 'text', array( 'label' => __( 'Municipal registration', 'wc-checkoutsuite' ) ), array( 'maxLength' => 20 ), 'br', false ) );
		$presets->register_preset( new Preset( 'br.birthdate', __( 'Date of birth', 'wc-checkoutsuite' ), 'date', array( 'label' => __( 'Date of birth', 'wc-checkoutsuite' ) ), array(), 'br', false ) );
	}
}
