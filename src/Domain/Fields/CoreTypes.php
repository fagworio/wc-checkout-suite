<?php
/**
 * Core field type registration.
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
 * Registers the field types shipped with the plugin.
 *
 * The Brazilian presets used to live here. They moved to {@see BrazilianPresets}
 * when they gained contracts of their own — a mask and a normalizer each — because
 * a type registry and a document catalogue are two different things to read.
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
}
