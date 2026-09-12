<?php
/**
 * The limited migrator for the ThemeHigh checkout field editor.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Migration;

use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaTransfer;

/**
 * Maps the structures that were read in the source, and reports everything else.
 *
 * ROADMAP.md section 21 states the scope and its limit in two sentences:
 *
 * > Migração do ThemeHigh é um escopo limitado: mapear configurações comprovadas no ZIP e
 * > relatar campos não suportados. Não importar configurações Premium ausentes por
 * > suposição. Importar schema não migra automaticamente valores históricos de pedidos.
 *
 * **What "comprovada" means here is written down, per key, in this file.** The mapping
 * table was not written from memory of how a checkout field editor usually works: it was
 * read out of an installation of the source and every key it maps is one that installation
 * uses. The record of that reading is {@see self::EVIDENCE}, and it names the version the
 * structures were read in — which is not the version the planning referenced, and saying so
 * is the difference between a mapping and a guess.
 *
 * **Nothing is dropped silently.** Every field the source offers ends in exactly one of two
 * lists: mapped, or reported with the reason it was not. A key inside a field that this
 * adapter does not know is reported beside the field rather than ignored, because the
 * sentence the acceptance asks for is "unsupported gera relatório" — and a field whose
 * `options` are silently thrown away is a select with nothing to select.
 *
 * **A type that is not in the table is not mapped to `text`.** That is the tempting
 * default and it is the one that loses data: a field the merchant configured as something
 * this plugin does not have becomes a text box, the merchant does not notice, and the
 * migration reports success.
 *
 * **With no source present, it does nothing and says so.** Reading another plugin's options
 * that are not there returns empties, and empties are not a migration: the report names the
 * absence rather than producing an empty document that would look like a configured store
 * with no fields.
 */
final class ThemeHighAdapter {

	/**
	 * Identifier of the source this adapter reads.
	 */
	public const SOURCE = 'themehigh/checkout-field-editor';

	/**
	 * What was read, and where.
	 *
	 * Every entry names the file and the constant or key it was read from. A mapping table
	 * without this is a table somebody remembered.
	 *
	 * @var array<int, array{what: string, where: string}>
	 */
	public const EVIDENCE = array(
		array(
			'what'  => 'Configuration is stored in options, one per section: billing, shipping and additional.',
			'where' => 'includes/utils/class-thwcfd-utils.php, OPTION_KEY_BILLING_FIELDS, OPTION_KEY_SHIPPING_FIELDS, OPTION_KEY_ADDITIONAL_FIELDS',
		),
		array(
			'what'  => 'The option name is built as wc_fields_<section>, and a missing option falls back to the platform defaults rather than to nothing.',
			'where' => 'includes/utils/class-thwcfd-utils.php, get_fields($key)',
		),
		array(
			'what'  => 'A field entry is an array keyed by field name carrying type, label, required, enabled, custom, show_in_email and show_in_order.',
			'where' => 'includes/utils/class-thwcfd-utils.php, prepare_default_fields() and the admin rendering path',
		),
		array(
			'what'  => 'Choice fields carry their choices as an encoded string that is decoded per type.',
			'where' => 'includes/utils/class-thwcfd-utils.php, prepare_options_array($options_json, $type)',
		),
		array(
			'what'  => 'The types found in the source are text, textarea, select, radio, checkbox, email, tel, country and state.',
			'where' => 'includes/utils/class-thwcfd-utils-block.php and the address defaults of the platform',
		),
	);

	/**
	 * The version of the source the structures above were read in.
	 *
	 * The planning referenced a ZIP declared as 2.2.0; the installation these structures
	 * were read in declares 2.1.5. The difference is recorded rather than smoothed over,
	 * because a mapping verified against one version is a mapping about that version.
	 */
	public const VERIFIED_AGAINST = '2.1.5';

	/**
	 * The version the planning referenced.
	 */
	public const PLANNING_REFERENCE = '2.2.0';

	/**
	 * The options the source keeps its configuration in, by section.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_OPTIONS = array(
		'billing'    => 'wc_fields_billing',
		'shipping'   => 'wc_fields_shipping',
		'additional' => 'wc_fields_additional',
	);

	/**
	 * The entry keys this adapter understands, and what it does with each.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_KEYS = array(
		'type'          => 'mapped: the source type, through TYPE_MAP',
		'label'         => 'mapped: the label',
		'required'      => 'mapped: whether the store requires it',
		'enabled'       => 'mapped: a disabled field arrives disabled rather than dropped',
		'custom'        => 'read: whether the merchant added the field, used by nothing yet',
		'show_in_order' => 'mapped: the order screen visibility for the customer',
		'show_in_email' => 'mapped: the customer e-mail visibility',
		'options'       => 'mapped for choice types, through prepare_options_array',
		'placeholder'   => 'read: reported as unsupported, because this plugin has no placeholder',
		'class'         => 'read: reported as unsupported, because a CSS class is the theme\'s business',
		'priority'      => 'read: reported as unsupported, because ordering is per section here',
		'position'      => 'read: reported as unsupported, because ordering is per section here',
	);

	/**
	 * Source types this adapter maps, and to what.
	 *
	 * @var array<string, string>
	 */
	public const TYPE_MAP = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'email'    => 'email',
		'tel'      => 'tel',
		'phone'    => 'tel',
		'country'  => 'country',
		'state'    => 'state',
	);

	/**
	 * Reads the source configuration, when the source is present.
	 *
	 * @return array<string, mixed> Sections and whether anything was found.
	 */
	public static function read(): array {
		$sections = array();
		$found    = false;

		foreach ( self::SOURCE_OPTIONS as $section => $option ) {
			$value = get_option( $option, array() );

			$value = is_array( $value ) ? array_filter(
				$value,
				static function ( $entry ): bool {
					return is_array( $entry ) && array() !== $entry;
				}
			) : array();

			if ( array() !== $value ) {
				$found = true;
			}

			$sections[ $section ] = $value;
		}

		return array(
			'present'  => $found,
			'sections' => $sections,
		);
	}

	/**
	 * Maps what was read into a candidate document, with the report.
	 *
	 * @param array<string, mixed> $source Sections, keyed by section name.
	 * @return array{document: SchemaDocument, report: array<string, mixed>}
	 */
	public static function migrate( array $source ): array {
		$fields      = array();
		$sections    = array();
		$mapped      = array();
		$unsupported = array();
		$seen        = 0;
		$position    = 0;

		foreach ( self::SOURCE_OPTIONS as $section => $option ) {
			$entries = isset( $source[ $section ] ) && is_array( $source[ $section ] ) ? $source[ $section ] : array();

			if ( array() === $entries ) {
				continue;
			}

			$sections[] = array(
				'id'       => $section,
				'title'    => self::section_label( $section ),
				// The source's sections are the platform's address groups; this plugin's
				// locations are a vocabulary of its own and the two are not the same words.
				// "additional" is the source's name for the order notes, which live with the
				// order here — and writing the source's word through produced a section the
				// editor refuses, which is what the validator said the first time.
				'location' => self::location( $section ),
				'position' => $position,
			);

			foreach ( $entries as $name => $entry ) {
				++$seen;

				$name = (string) $name;

				if ( ! is_array( $entry ) ) {
					$unsupported[] = self::entry( $name, 'entry', '', 'The source stores this field as something other than a mapping.' );

					continue;
				}

				// Every key this adapter does not understand is reported beside the field,
				// before the field is judged: a field the merchant configured with a setting
				// that is not carried over is a field that arrives different, and the report
				// is where they learn it.
				foreach ( array_keys( $entry ) as $key ) {
					if ( ! isset( self::SOURCE_KEYS[ (string) $key ] ) ) {
						$unsupported[] = self::entry( $name, 'key', (string) $key, 'This plugin has no equivalent for this setting.' );

						continue;
					}

					if ( str_starts_with( self::SOURCE_KEYS[ (string) $key ], 'read:' ) ) {
						$unsupported[] = self::entry( $name, 'key', (string) $key, self::SOURCE_KEYS[ (string) $key ] );
					}
				}

				$type = isset( $entry['type'] ) ? strtolower( (string) $entry['type'] ) : '';

				if ( ! isset( self::TYPE_MAP[ $type ] ) ) {
					// Not mapped to text. A type this plugin does not have becomes a report
					// line, because a text box where a select used to be is data the merchant
					// loses without being told.
					$unsupported[] = self::entry( $name, 'type', $type, 'This plugin has no field type for this.' );

					continue;
				}

				$target = self::TYPE_MAP[ $type ];
				$id     = self::identifier( $name );
				$label  = isset( $entry['label'] ) && '' !== (string) $entry['label'] ? (string) $entry['label'] : $name;

				$visibility = array(
					'admin_order'    => true,
					// The source's own flags, honoured rather than assumed: a field the
					// merchant hid from the order is not shown here either.
					'customer_order' => ! empty( $entry['show_in_order'] ),
					'customer_email' => ! empty( $entry['show_in_email'] ),
					'admin_email'    => false,
					'public_api'     => false,
				);

				$definition = array(
					'id'             => $id,
					'integration_id' => 'wc-checkoutsuite/' . $id,
					'origin'         => empty( $entry['custom'] ) ? 'core' : 'custom',
					'type'           => $target,
					'label'          => $label,
					'enabled'        => ! isset( $entry['enabled'] ) || (bool) $entry['enabled'],
					'required'       => ! empty( $entry['required'] ),
					'position'       => $position,
					'layout'         => array(
						'desktop' => 12,
						'tablet'  => 12,
						'mobile'  => 12,
					),
					'settings'       => self::settings( $entry, $target ),
					'conditions'     => array(),
					'storage'        => array(
						'scope'       => 'order',
						'sensitivity' => 'personal',
					),
					'visibility'     => $visibility,
					'validators'     => array(),
				);

				++$position;

				$fields[] = $definition;

				$mapped[] = array(
					'name'        => $name,
					'id'          => $id,
					'section'     => $section,
					'source_type' => $type,
					'type'        => $target,
					'enabled'     => $definition['enabled'],
				);
			}
		}

		$document = SchemaDocument::from_array(
			array(
				'revision'       => 0,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 0,
				'fields'         => $fields,
				'sections'       => $sections,
				'settings'       => array(),
			)
		);

		return array(
			'document' => $document,
			'report'   => array(
				'source'             => self::SOURCE,
				'verified_against'   => self::VERIFIED_AGAINST,
				'planning_reference' => self::PLANNING_REFERENCE,
				'seen'               => $seen,
				'mapped'             => $mapped,
				'unsupported'        => $unsupported,
				'counts'             => array(
					'seen'        => $seen,
					'mapped'      => count( $mapped ),
					'unsupported' => count( $unsupported ),
				),
				'note'               => __( 'A migration carries the configuration and not the orders: values already stored on past orders are not migrated, which is stated in section 21 of the planning.', 'wc-checkoutsuite' ),
			),
		);
	}

	/**
	 * The migration as a file the import path already understands.
	 *
	 * @param array{document: SchemaDocument, report: array<string, mixed>} $result Migration result.
	 * @return array<string, mixed> Envelope.
	 */
	public static function envelope( array $result ): array {
		$envelope = SchemaTransfer::export( $result['document'] );

		// The provenance says where the document came from, because a file that arrives in
		// a store months later has to say what produced it.
		$envelope['exported_from']['migrated_from'] = self::SOURCE . '@' . self::VERIFIED_AGAINST;

		return $envelope;
	}

	/**
	 * The settings a target type carries.
	 *
	 * @param array<string, mixed> $entry  Source entry.
	 * @param string               $target Target type.
	 * @return array<string, mixed>
	 */
	private static function settings( array $entry, string $target ): array {
		if ( ! in_array( $target, array( 'select', 'radio', 'checkbox-group' ), true ) ) {
			return array();
		}

		$options = isset( $entry['options'] ) ? $entry['options'] : null;

		if ( is_string( $options ) ) {
			$decoded = json_decode( $options, true );
			$options = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $options ) ) {
			return array();
		}

		$choices = array();

		foreach ( $options as $value => $label ) {
			if ( is_array( $label ) && isset( $label['value'] ) ) {
				$choices[] = array(
					'value' => (string) $label['value'],
					'label' => (string) ( $label['label'] ?? $label['value'] ),
				);

				continue;
			}

			if ( is_scalar( $label ) ) {
				$choices[] = array(
					'value' => is_int( $value ) ? (string) $label : (string) $value,
					'label' => (string) $label,
				);
			}
		}

		return array( 'options' => $choices );
	}

	/**
	 * A source field name as this plugin's identifier.
	 *
	 * @param string $name Source name.
	 * @return string
	 */
	private static function identifier( string $name ): string {
		$name = strtolower( $name );
		$name = (string) preg_replace( '/[^a-z0-9_]+/', '_', $name );

		return str_starts_with( $name, 'wccs_' ) ? $name : 'wccs_' . trim( $name, '_' );
	}

	/**
	 * One report line.
	 *
	 * @param string $field  Field name.
	 * @param string $kind   entry, key or type.
	 * @param string $value  What it was.
	 * @param string $reason Why it is not carried.
	 * @return array<string, string>
	 */
	private static function entry( string $field, string $kind, string $value, string $reason ): array {
		return array(
			'field'  => $field,
			'kind'   => $kind,
			'value'  => $value,
			'reason' => $reason,
		);
	}

	/**
	 * The location a source section becomes.
	 *
	 * @param string $section Source section.
	 * @return string
	 */
	private static function location( string $section ): string {
		switch ( $section ) {
			case 'billing':
				return 'billing';

			case 'shipping':
				return 'shipping';

			default:
				// The source keeps the order notes in "additional"; the vocabulary this
				// plugin validates against calls that location "order".
				return 'order';
		}
	}

	/**
	 * A heading for a section.
	 *
	 * @param string $section Section.
	 * @return string
	 */
	private static function section_label( string $section ): string {
		switch ( $section ) {
			case 'billing':
				return __( 'Billing', 'wc-checkoutsuite' );

			case 'shipping':
				return __( 'Shipping', 'wc-checkoutsuite' );

			default:
				return __( 'Additional', 'wc-checkoutsuite' );
		}
	}
}
