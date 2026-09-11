<?php
/**
 * WCCS-007 proof harness — field types, registries, value and settings validation.
 *
 * Task:   WCCS-007 "Implementar schema e registries"
 * Phase:  F01
 * Accept: "Tipos, valores e settings validados; registro externo funciona sem editar factory."
 *
 * Prerequisite: the plugin must be ACTIVE (the domain autoloader lives in the plugin file).
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Registries;

$GLOBALS['wccs_proof'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'checks' => array(),
	'notes'  => array(),
);

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok = (bool) $condition;

	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];

	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-007 proof — schema and registries' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_registries = Registries::instance();
$wccs_types      = $wccs_registries->types();
$wccs_presets    = $wccs_registries->presets();
$wccs_validators = $wccs_registries->validators();

// ---------------------------------------------------------------------------
// 1. Core registration through the public API.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Core registration' );

wccs_proof_check(
	'The wccs_register_field_types extension point was fired at boot',
	did_action( 'wccs_register_field_types' ) > 0,
	'did_action=' . did_action( 'wccs_register_field_types' )
);

$wccs_expected_types = array(
	'text',
	'textarea',
	'email',
	'tel',
	'url',
	'number',
	'select',
	'multiselect',
	'radio',
	'checkbox-group',
	'checkbox',
	'date',
	'time',
	'datetime',
	'hidden',
	'heading',
	'paragraph',
	'html',
	'file',
	'country',
	'state',
);

$wccs_missing = array_values( array_diff( $wccs_expected_types, $wccs_types->keys() ) );
wccs_proof_check(
	'All 21 field types declared for v1 are registered',
	array() === $wccs_missing,
	'registered=' . count( $wccs_types->keys() ) . ' missing=' . ( $wccs_missing ? implode( ',', $wccs_missing ) : 'none' )
);

wccs_proof_check(
	'Every registered type exposes the full contract',
	( static function () use ( $wccs_types ) {
		foreach ( $wccs_types->types() as $type ) {
			if ( '' === $type->key() || '' === $type->label() || '' === $type->contract_version() ) {
				return false;
			}
			if ( ! is_array( $type->valueSchema() ) || ! is_array( $type->settingsSchema() ) || ! is_array( $type->supports() ) ) {
				return false;
			}
		}
		return true;
	} )()
);

wccs_proof_check(
	'15 Brazilian presets are registered',
	15 === count( $wccs_presets->keys() ),
	'registered=' . count( $wccs_presets->keys() )
);

wccs_proof_check(
	'3 optional presets ship disabled',
	3 === count( array_filter( $wccs_presets->presets(), static fn( $p ) => ! $p->is_enabled() ) ),
	implode( ',', array_keys( $wccs_presets->grouped( true )['br'] ?? array() ) ) . ' enabled'
);

wccs_proof_check(
	'No preset points at a missing type',
	array() === $wccs_presets->orphaned( $wccs_types )
);

// ---------------------------------------------------------------------------
// 2. External registration without editing the core.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. External registration (no core edit)' );

/**
 * A field type contributed by a hypothetical third-party plugin.
 */
final class WccsProofMembershipCodeType extends AbstractFieldType {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'example.membership-code';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Membership code';
	}

	/**
	 * {@inheritDoc}
	 */
	public function valueSchema(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 24,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settingsSchema(): array {
		return array(
			'prefix' => array(
				'type'      => 'string',
				'maxLength' => 8,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		return is_string( $value ) ? strtoupper( trim( $value ) ) : $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) ) {
			return ValidationResult::valid();
		}

		$prefix = $context->setting( 'prefix' );

		if ( is_string( $prefix ) && '' !== $prefix && ! str_starts_with( (string) $value, $prefix ) ) {
			return ValidationResult::invalid(
				'missing_prefix',
				sprintf( 'The membership code must start with %s.', $prefix )
			);
		}

		return ValidationResult::valid();
	}
}

add_action(
	'wccs_register_field_types',
	static function ( $registry ) {
		$registry->register_type( new WccsProofMembershipCodeType(), 'proof-plugin' );
	}
);

// `plugins_loaded` has already passed inside wp eval-file, so the harness fires the
// documented hook again to exercise the external path. The registry rejects
// duplicates, so this cannot silently replace a core type.
do_action( 'wccs_register_field_types', $wccs_types );

$wccs_external = $wccs_types->type( 'example.membership-code' );

wccs_proof_check(
	'A type registered through the public hook appears in the registry',
	$wccs_external instanceof WccsProofMembershipCodeType,
	'key=example.membership-code'
);

wccs_proof_check(
	'The external type reports its own origin',
	'proof-plugin' === $wccs_types->source_of( 'example.membership-code' ),
	'source=' . $wccs_types->source_of( 'example.membership-code' )
);

wccs_proof_check(
	'A definition using the external type validates without touching the core',
	$wccs_registries->definition_validator()->validate_array(
		array(
			'id'       => 'membership_code',
			'type'     => 'example.membership-code',
			'label'    => 'Membership code',
			'settings' => array( 'prefix' => 'WCCS-' ),
		)
	)->is_valid()
);

wccs_proof_check(
	'The external type normalizes and validates its own values',
	'WCCS-42' === $wccs_external->normalize( '  wccs-42 ', new FieldContext( array(), 'classic', array( 'prefix' => 'WCCS-' ) ) )
		&& ! $wccs_external->validate( 'XX-1', new FieldContext( array(), 'classic', array( 'prefix' => 'WCCS-' ) ) )->is_valid()
);

wccs_proof_check(
	'Re-registering the same key is rejected and reported, never silently overwritten',
	false === $wccs_types->register_type( new WccsProofMembershipCodeType(), 'other-plugin' )
		&& $wccs_types->has_diagnostics()
);

wccs_proof_note(
	'Duplicate diagnostic',
	$wccs_types->diagnostics()[0]['message'] ?? '(none)'
);

// ---------------------------------------------------------------------------
// 3. Settings validation.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Settings validation' );

$wccs_definition_validator = $wccs_registries->definition_validator();

$wccs_case = static function ( array $override ): array {
	return array_merge(
		array(
			'id'    => 'billing_document',
			'type'  => 'text',
			'label' => 'Document',
		),
		$override
	);
};

$wccs_valid = $wccs_definition_validator->validate_array( $wccs_case( array( 'settings' => array( 'maxLength' => 20 ) ) ) );
wccs_proof_check( 'A well formed definition is accepted', $wccs_valid->is_valid(), implode( ',', $wccs_valid->error_codes() ) );

$wccs_unknown = $wccs_definition_validator->validate_array( $wccs_case( array( 'settings' => array( 'nonsense' => 1 ) ) ) );
wccs_proof_check(
	'An undeclared setting is rejected (mass assignment guard)',
	in_array( 'unknown_setting', $wccs_unknown->error_codes(), true ),
	implode( ',', $wccs_unknown->error_codes() )
);

$wccs_wrong_type = $wccs_definition_validator->validate_array( $wccs_case( array( 'settings' => array( 'maxLength' => 'twenty' ) ) ) );
wccs_proof_check(
	'A setting with the wrong type is rejected',
	in_array( 'invalid_setting_type', $wccs_wrong_type->error_codes(), true ),
	implode( ',', $wccs_wrong_type->error_codes() )
);

$wccs_required = $wccs_definition_validator->validate_array(
	array(
		'id'       => 'upload',
		'type'     => 'file',
		'label'    => 'Upload',
		'settings' => array( 'maxBytes' => 1024 ),
	)
);
wccs_proof_check(
	'A missing required setting is rejected',
	in_array( 'missing_required_setting', $wccs_required->error_codes(), true ),
	implode( ',', $wccs_required->error_codes() )
);

$wccs_unknown_type = $wccs_definition_validator->validate_array( $wccs_case( array( 'type' => 'does-not-exist' ) ) );
wccs_proof_check(
	'An unregistered type is rejected',
	in_array( 'unknown_type', $wccs_unknown_type->error_codes(), true ),
	implode( ',', $wccs_unknown_type->error_codes() )
);

$wccs_bad_layout = $wccs_definition_validator->validate_array( $wccs_case( array( 'layout' => array( 'desktop' => 99 ) ) ) );
wccs_proof_check(
	'An out-of-range layout width is rejected',
	in_array( 'invalid_layout_width', $wccs_bad_layout->error_codes(), true ),
	implode( ',', $wccs_bad_layout->error_codes() )
);

$wccs_preset_mismatch = $wccs_definition_validator->validate_array( $wccs_case( array( 'preset' => 'br.cnpj', 'type' => 'number' ) ) );
wccs_proof_check(
	'A preset built on another type is rejected',
	in_array( 'preset_type_mismatch', $wccs_preset_mismatch->error_codes(), true ),
	implode( ',', $wccs_preset_mismatch->error_codes() )
);

$wccs_unknown_validator = $wccs_definition_validator->validate_array( $wccs_case( array( 'validators' => array( array( 'key' => 'br.cpf' ) ) ) ) );
wccs_proof_check(
	'A validator that is not registered yet is rejected',
	in_array( 'unknown_validator', $wccs_unknown_validator->error_codes(), true ),
	'validators and masks arrive in WCCS-028'
);

// ---------------------------------------------------------------------------
// 4. Value normalization and validation per type.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Value validation' );

$wccs_plain = new FieldContext();

$wccs_email = $wccs_types->type( 'email' );
wccs_proof_check( 'email rejects a malformed address', ! $wccs_email->validate( 'not-an-email', $wccs_plain )->is_valid() );
wccs_proof_check( 'email accepts a valid address', $wccs_email->validate( 'buyer@example.com', $wccs_plain )->is_valid() );
wccs_proof_check(
	'email treats an absent value as valid, leaving requiredness to the definition',
	$wccs_email->validate( '', $wccs_plain )->is_valid()
);

$wccs_number = $wccs_types->type( 'number' );
wccs_proof_check(
	'number normalizes a numeric string to an integer, preserving 0',
	0 === $wccs_number->normalize( '0', $wccs_plain )
);
wccs_proof_check(
	'number honours the min setting from the context',
	! $wccs_number->validate( 5, new FieldContext( array(), 'classic', array( 'min' => 10 ) ) )->is_valid()
);

$wccs_select = $wccs_types->type( 'select' );
$wccs_select_ctx = new FieldContext( array(), 'classic', array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) );
wccs_proof_check( 'select rejects a value outside its options', ! $wccs_select->validate( 'z', $wccs_select_ctx )->is_valid() );
wccs_proof_check( 'select accepts a declared option', $wccs_select->validate( 'a', $wccs_select_ctx )->is_valid() );

$wccs_checkbox = $wccs_types->type( 'checkbox' );
wccs_proof_check( 'checkbox normalizes an absent value to false', false === $wccs_checkbox->normalize( '', $wccs_plain ) );
wccs_proof_check( 'checkbox keeps false as a real value', $wccs_checkbox->validate( false, $wccs_plain )->is_valid() );

$wccs_date = $wccs_types->type( 'date' );
wccs_proof_check( 'date accepts the canonical format and rejects a loose one', $wccs_date->validate( '2026-09-11', $wccs_plain )->is_valid() && ! $wccs_date->validate( '11/09/2026', $wccs_plain )->is_valid() );

$wccs_heading = $wccs_types->type( 'heading' );
wccs_proof_check(
	'A presentational type declares that it stores no value',
	false === $wccs_heading->supports()['value'] && null === $wccs_heading->normalize( 'x', $wccs_plain )
);

$wccs_absence = WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( null )
	&& WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( '' )
	&& ! WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( 0 )
	&& ! WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( '0' )
	&& ! WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( false )
	&& ! WCCheckoutSuite\Domain\Fields\AbstractFieldType::is_absent( array() );
wccs_proof_check( '0, "0", false and [] are distinct from an absent value', $wccs_absence );

// ---------------------------------------------------------------------------
// 5. Validator registry.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Validator registry' );

$wccs_validators->register_validator(
	'proof.even',
	static function ( $value, $context ) {
		return ( is_int( $value ) && 0 === $value % 2 )
			? ValidationResult::valid()
			: ValidationResult::invalid( 'not_even', 'Value must be even.' );
	},
	'proof-plugin'
);

wccs_proof_check( 'A registered validator runs and reports failure', ! $wccs_validators->run( 'proof.even', 3, $wccs_plain )->is_valid() );
wccs_proof_check( 'The same validator reports success', $wccs_validators->run( 'proof.even', 4, $wccs_plain )->is_valid() );
wccs_proof_check(
	'An unknown validator fails closed instead of passing',
	in_array( 'unknown_validator', $wccs_validators->run( 'missing', 4, $wccs_plain )->error_codes(), true )
);

// ---------------------------------------------------------------------------
// 6. Definition round trip.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Definition round trip' );

$wccs_raw = array(
	'id'             => 'billing_document',
	'integration_id' => 'wc-checkoutsuite/billing-document',
	'type'           => 'text',
	'preset'         => 'br.cnpj',
	'label'          => 'CNPJ',
	'section'        => 'billing',
	'required'       => true,
	'position'       => 40,
	'layout'         => array(
		'desktop' => 6,
		'tablet'  => 6,
		'mobile'  => 12,
	),
	'settings'       => array( 'maxLength' => 18 ),
);

$wccs_definition = FieldDefinition::from_array( $wccs_raw );
$wccs_round_trip = $wccs_definition->to_array();

wccs_proof_check(
	'Defaults are filled for omitted keys',
	'custom' === $wccs_round_trip['origin']
		&& 'discard' === $wccs_round_trip['hidden_value_policy']
		&& 1 === $wccs_round_trip['schema_version'],
	'origin/hidden_value_policy/schema_version defaults'
);

wccs_proof_check(
	'Declared values survive the round trip',
	$wccs_round_trip['id'] === $wccs_raw['id']
		&& $wccs_round_trip['preset'] === $wccs_raw['preset']
		&& $wccs_round_trip['layout'] === $wccs_raw['layout']
);

wccs_proof_check(
	'The canonical definition example from the planning validates',
	$wccs_definition_validator->validate( $wccs_definition )->is_valid(),
	implode( ',', $wccs_definition_validator->validate( $wccs_definition )->error_codes() )
);

$wccs_persisted = FieldDefinition::from_array( $wccs_round_trip )->to_array();
wccs_proof_check( 'Rehydrating a serialized definition is lossless', $wccs_persisted === $wccs_round_trip );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
