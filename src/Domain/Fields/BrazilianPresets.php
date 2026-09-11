<?php
/**
 * The Brazilian field presets.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Registers the Brazilian presets with their own contracts.
 *
 * Each preset declares what the field *is*: the type it is built on, the mask it
 * is typed with, the normalizer that turns what the customer typed into what the
 * store keeps, and the settings the two need. That is the whole of a preset — it
 * is data, and ADR-0003 requires that the mask is never the authority, so the
 * normalizer is named here and the server applies it.
 *
 * Three things are deliberately absent.
 *
 * **Validators.** Check digits are WCCS-028's contract, fixed against the official
 * sources. Naming a validator key that is not registered would make every field
 * built from the preset fail validation at save, and naming one that exists but
 * does nothing would be a promise the code does not keep.
 *
 * **A mask for RG.** Its format depends on the issuing state and on the document
 * type; inventing a national one was rejected in ADR-0003. The field is a plain
 * string, which is what RG actually is.
 *
 * **Any numeric type for a document.** ADR-0003 rule 7 is explicit: CPF, CNPJ and
 * CEP are strings. `number` is not available to them, here or anywhere.
 *
 * @see \ROADMAP.md sections 5 and 9
 * @see \docs/adr/ADR-0003-cnpj-alphanumeric.md
 */
final class BrazilianPresets {

	/**
	 * Group the picker shows these under.
	 */
	private const GROUP = 'br';

	/**
	 * Registers every Brazilian preset.
	 *
	 * @param PresetRegistry $presets Preset registry.
	 * @return void
	 */
	public static function register_presets( PresetRegistry $presets ): void {
		$presets->register_preset( self::document( 'br.cpf', __( 'CPF', 'wc-checkoutsuite' ) ) );
		$presets->register_preset( self::document( 'br.cnpj', __( 'CNPJ', 'wc-checkoutsuite' ) ) );

		// RG is a plain string: no mask, no normalizer, nothing claimed about its
		// shape. A document whose format depends on the state it was issued in is
		// not made more correct by a pattern.
		$presets->register_preset(
			new Preset(
				'br.rg',
				__( 'RG', 'wc-checkoutsuite' ),
				'text',
				array( 'label' => __( 'RG', 'wc-checkoutsuite' ) ),
				array( 'maxLength' => 20 ),
				self::GROUP
			)
		);

		$presets->register_preset( self::document( 'br.cep', __( 'CEP', 'wc-checkoutsuite' ) ) );

		$presets->register_preset(
			new Preset(
				'br.phone.landline',
				__( 'Landline phone', 'wc-checkoutsuite' ),
				'tel',
				array(
					'label'      => __( 'Phone', 'wc-checkoutsuite' ),
					'normalizer' => 'br.phone',
					'mask'       => self::mask( 'br.phone.landline' ),
					'validators' => self::validator( 'br.phone.landline' ),
				),
				array( 'placeholder' => '(00) 0000-0000' ),
				self::GROUP
			)
		);

		$presets->register_preset(
			new Preset(
				'br.phone.mobile',
				__( 'Mobile phone', 'wc-checkoutsuite' ),
				'tel',
				array(
					'label'      => __( 'Mobile phone', 'wc-checkoutsuite' ),
					'normalizer' => 'br.phone',
					'mask'       => self::mask( 'br.phone.mobile' ),
					'validators' => self::validator( 'br.phone.mobile' ),
				),
				array( 'placeholder' => '(00) 00000-0000' ),
				self::GROUP
			)
		);

		$presets->register_preset(
			new Preset(
				'br.person-type',
				__( 'Person type (PF/PJ)', 'wc-checkoutsuite' ),
				'radio',
				array( 'label' => __( 'Person type', 'wc-checkoutsuite' ) ),
				array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => __( 'Individual', 'wc-checkoutsuite' ),
						),
						array(
							'value' => 'pj',
							'label' => __( 'Company', 'wc-checkoutsuite' ),
						),
					),
				),
				self::GROUP
			)
		);

		$presets->register_preset( self::text( 'br.company-name', __( 'Legal name', 'wc-checkoutsuite' ), 200 ) );
		$presets->register_preset( self::text( 'br.trade-name', __( 'Trade name', 'wc-checkoutsuite' ), 200 ) );
		$presets->register_preset( self::text( 'br.address.number', __( 'Number', 'wc-checkoutsuite' ), 20 ) );
		$presets->register_preset( self::text( 'br.address.neighborhood', __( 'Neighborhood', 'wc-checkoutsuite' ), 120 ) );
		$presets->register_preset( self::text( 'br.address.complement', __( 'Complement', 'wc-checkoutsuite' ), 120 ) );

		// Declared optional in the planning: they ship disabled and are enabled
		// deliberately by the merchant.
		$presets->register_preset( self::optional( 'br.state-registration', __( 'State registration', 'wc-checkoutsuite' ), 'text', 20 ) );
		$presets->register_preset( self::optional( 'br.municipal-registration', __( 'Municipal registration', 'wc-checkoutsuite' ), 'text', 20 ) );
		$presets->register_preset( self::optional( 'br.birthdate', __( 'Date of birth', 'wc-checkoutsuite' ), 'date', 0 ) );
	}

	/**
	 * A document preset: a string typed with a mask and normalized on the server.
	 *
	 * The length is the length of the *written* form, which is what a browser can
	 * count, and not the length of the stored one. A CPF is written with fourteen
	 * characters and stored with eleven, and confusing the two is how a mask ends
	 * up refusing a valid document.
	 *
	 * @param string $key   Preset key, which is also the mask and normalizer key.
	 * @param string $label Translatable label.
	 * @return Preset
	 */
	private static function document( string $key, string $label ): Preset {
		return new Preset(
			$key,
			$label,
			'text',
			array(
				'label'      => $label,
				'normalizer' => $key,
				'mask'       => self::mask( $key ),
				'validators' => self::validator( $key ),
			),
			array(
				'placeholder' => self::written( $key ),
				'maxLength'   => strlen( self::written( $key ) ),
			),
			self::GROUP
		);
	}

	/**
	 * A plain text preset with a length.
	 *
	 * @param string $key       Preset key.
	 * @param string $label     Translatable label.
	 * @param int    $max_length Maximum length.
	 * @return Preset
	 */
	private static function text( string $key, string $label, int $max_length ): Preset {
		return new Preset(
			$key,
			$label,
			'text',
			array( 'label' => $label ),
			array( 'maxLength' => $max_length ),
			self::GROUP
		);
	}

	/**
	 * An optional preset, shipped disabled.
	 *
	 * @param string $key        Preset key.
	 * @param string $label      Translatable label.
	 * @param string $type       Underlying type.
	 * @param int    $max_length Maximum length, or 0 for none.
	 * @return Preset
	 */
	private static function optional( string $key, string $label, string $type, int $max_length ): Preset {
		$settings = $max_length > 0 ? array( 'maxLength' => $max_length ) : array();

		return new Preset( $key, $label, $type, array( 'label' => $label ), $settings, self::GROUP, false );
	}

	/**
	 * The validator a document preset declares.
	 *
	 * One per preset, and the same key as the mask and the normalizer: the three
	 * are one contract about one document, and spelling them differently is how
	 * they drift apart. WCCS-026 left this out on purpose — naming a key that was
	 * not registered would have made every field built from the preset unsaveable
	 * — and WCCS-028 registers them.
	 *
	 * @param string $key Preset key.
	 * @return array<int, array{key: string}>
	 */
	private static function validator( string $key ): array {
		return array( array( 'key' => $key ) );
	}

	/**
	 * A mask reference.
	 *
	 * The version travels with the key because a definition stores the version it
	 * was configured against, so a later change to the mask is detectable instead
	 * of silently altering stored values.
	 *
	 * @param string $key Mask key.
	 * @return array{key: string, version: int}
	 */
	private static function mask( string $key ): array {
		return array(
			'key'     => $key,
			'version' => 1,
		);
	}

	/**
	 * The written form of a document, as the customer sees it.
	 *
	 * Kept beside the mask it mirrors rather than derived from it: the mask is a
	 * pattern for a library and the placeholder is what a person reads, and
	 * deriving one from the other would need a pattern parser whose only purpose
	 * is to undo the pattern.
	 *
	 * The map is indexed directly rather than searched, so a document preset added
	 * without a written form fails as soon as it is registered instead of quietly
	 * arriving with an empty placeholder and a maximum length of zero.
	 *
	 * @param string $key Preset key.
	 * @return string
	 */
	private static function written( string $key ): string {
		$written = array(
			'br.cpf'  => '000.000.000-00',
			'br.cnpj' => '00.000.000/0000-00',
			'br.cep'  => '00000-000',
		);

		return $written[ $key ];
	}
}
