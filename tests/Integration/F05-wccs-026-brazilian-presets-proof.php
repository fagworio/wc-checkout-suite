<?php
/**
 * WCCS-026 proof harness — the Brazilian presets and their contracts.
 *
 * Task:   WCCS-026 "Implementar presets brasileiros"
 * Phase:  F05
 * Accept: "CPF, CNPJ numérico/alfanumérico, RG, CEP, telefone e endereço com
 *          contratos próprios."
 *
 * The contracts themselves are covered by the unit suite, which walks the shared
 * fixture file. What this harness adds is that they hold in the running plugin:
 * the registries the bootstrap wires, the catalogue the admin really receives
 * over REST, a definition built the way the picker builds it passing the real
 * validator, and the real pipeline turning what a customer typed into what the
 * store keeps.
 *
 * It also asserts the phase gate, because the pipeline is where it can be
 * asserted: an alphanumeric CNPJ is neither truncated nor turned into a number.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

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
	$ok                                = (bool) $condition;
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

/**
 * A definition built the way the picker builds one from a preset.
 *
 * This mirrors `buildField` in the administration application: the identity and
 * the surfaces come from the application, and the mask and the normalizer come
 * from the preset. Building it here rather than through the browser is what makes
 * the contract checkable before there is a browser.
 *
 * @param string               $id     Field identifier.
 * @param string               $preset Preset key.
 * @param array<string, mixed> $extra  Extra definition values.
 * @return array<string, mixed>
 */
function wccs_proof_from_preset( string $id, string $preset, array $extra = array() ): array {
	$registered = \WCCheckoutSuite\Domain\Registries::instance()->presets()->preset( $preset );

	if ( null === $registered ) {
		return array();
	}

	$defaults = $registered->defaults();

	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => $registered->type(),
			'preset'         => $preset,
			'label'          => $registered->label(),
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => $registered->settings(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array(
				'admin_order' => true,
			),
		),
		array(
			// What the preset seeds, which is what the client half now applies.
			'mask'       => $defaults['mask'] ?? null,
			'normalizer' => $defaults['normalizer'] ?? null,
		),
		$extra
	);
}

/**
 * Runs one value through the real pipeline for a definition.
 *
 * @param array<string, mixed> $definition Definition.
 * @param mixed                $value      Value.
 * @return \WCCheckoutSuite\Domain\Validation\ProcessedValue
 */
function wccs_proof_process( array $definition, mixed $value ): \WCCheckoutSuite\Domain\Validation\ProcessedValue {
	return \WCCheckoutSuite\Domain\Registries::instance()->value_processor()->process(
		\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $definition ),
		$value,
		new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), 'classic' )
	);
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

$wccs_options_before = wccs_proof_option_count();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-026 proof — Brazilian presets and their contracts' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_registries = \WCCheckoutSuite\Domain\Registries::instance();

// ---------------------------------------------------------------------------
// 1. The bootstrap wires the Brazilian layer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The Brazilian layer is registered at boot' );

$wccs_masks       = array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' );
$wccs_normalizers = array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone' );

foreach ( $wccs_masks as $wccs_key ) {
	wccs_proof_check(
		'The mask ' . $wccs_key . ' is registered',
		$wccs_registries->masks()->has( $wccs_key ),
		'definition=' . wp_json_encode( $wccs_registries->masks()->mask( $wccs_key )?->definition() )
	);
}

foreach ( $wccs_normalizers as $wccs_key ) {
	wccs_proof_check(
		'The normalizer ' . $wccs_key . ' is registered',
		$wccs_registries->normalizers()->has( $wccs_key )
	);
}

wccs_proof_check(
	'No registration was rejected',
	array() === $wccs_registries->diagnostics(),
	'diagnostics=' . wp_json_encode( array_column( $wccs_registries->diagnostics(), 'code' ) )
);

// ---------------------------------------------------------------------------
// 2. The admin receives the contracts, not just the labels.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the picker receives' );

$wccs_response = rest_do_request(
	new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES )
);

wccs_proof_check(
	'The catalogue route answers',
	200 === $wccs_response->get_status(),
	'status=' . $wccs_response->get_status()
);

$wccs_catalogue = (array) $wccs_response->get_data();
$wccs_by_key    = array();

foreach ( (array) ( $wccs_catalogue['presets'] ?? array() ) as $wccs_preset ) {
	$wccs_by_key[ (string) ( $wccs_preset['key'] ?? '' ) ] = $wccs_preset;
}

wccs_proof_check(
	'The catalogue carries the Brazilian presets',
	isset( $wccs_by_key['br.cpf'], $wccs_by_key['br.cnpj'], $wccs_by_key['br.cep'], $wccs_by_key['br.phone.mobile'] ),
	'keys=' . wp_json_encode( array_keys( $wccs_by_key ) )
);

wccs_proof_check(
	'Each carries the mask its field will be typed with',
	'br.cpf' === ( $wccs_by_key['br.cpf']['defaults']['mask']['key'] ?? null )
		&& 'br.cnpj' === ( $wccs_by_key['br.cnpj']['defaults']['mask']['key'] ?? null )
		&& 1 === ( $wccs_by_key['br.cnpj']['defaults']['mask']['version'] ?? null ),
	'cpf=' . wp_json_encode( $wccs_by_key['br.cpf']['defaults']['mask'] ?? null )
);

wccs_proof_check(
	'And the normalizer the server will apply',
	'br.cpf' === ( $wccs_by_key['br.cpf']['defaults']['normalizer'] ?? null )
		&& 'br.cnpj' === ( $wccs_by_key['br.cnpj']['defaults']['normalizer'] ?? null )
		&& 'br.phone' === ( $wccs_by_key['br.phone.mobile']['defaults']['normalizer'] ?? null )
);

foreach ( array( 'br.state-registration', 'br.municipal-registration', 'br.birthdate' ) as $wccs_optional ) {
	wccs_proof_check(
		'The optional preset ' . $wccs_optional . ' is not offered',
		! isset( $wccs_by_key[ $wccs_optional ] ),
		'it ships disabled and the catalogue omits disabled presets'
	);
}

// ---------------------------------------------------------------------------
// 3. A field built from a preset passes the real validator.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The contract is publishable' );

$wccs_validator = $wccs_registries->definition_validator();

foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' ) as $wccs_preset_key ) {
	$wccs_id         = str_replace( '.', '_', $wccs_preset_key );
	$wccs_definition = wccs_proof_from_preset( $wccs_id, $wccs_preset_key );

	$wccs_result = $wccs_validator->validate_array( $wccs_definition );

	wccs_proof_check(
		'A field built from ' . $wccs_preset_key . ' is accepted by the validator',
		$wccs_result->is_valid(),
		'errors=' . wp_json_encode( $wccs_result->error_codes() )
	);
}

$wccs_broken = wccs_proof_from_preset(
	'wccs_broken',
	'br.cpf',
	array(
		'mask' => array(
			'key'     => 'br.not-registered',
			'version' => 1,
		),
	)
);

wccs_proof_check(
	'And a mask that is not registered is refused',
	in_array( 'unknown_mask', $wccs_validator->validate_array( $wccs_broken )->error_codes(), true ),
	'the reference is checked rather than trusted'
);

// ---------------------------------------------------------------------------
// 4. What a customer types is what the store keeps.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The pipeline produces the declared value' );

$wccs_cpf = wccs_proof_process( wccs_proof_from_preset( 'wccs_cpf', 'br.cpf' ), '123.456.789-09' );

wccs_proof_check(
	'A typed CPF becomes the eleven digits of the document',
	'12345678909' === $wccs_cpf->value(),
	'value=' . wp_json_encode( $wccs_cpf->value() ) . ' type=' . gettype( $wccs_cpf->value() )
);

wccs_proof_check(
	'And it is a string, so a leading zero cannot be lost',
	is_string( $wccs_cpf->value() )
);

$wccs_zeroed = wccs_proof_process( wccs_proof_from_preset( 'wccs_cpf', 'br.cpf' ), '012.345.678-90' );

wccs_proof_check(
	'A CPF that starts with a zero keeps it',
	'01234567890' === $wccs_zeroed->value(),
	'value=' . wp_json_encode( $wccs_zeroed->value() )
);

$wccs_cep = wccs_proof_process( wccs_proof_from_preset( 'wccs_cep', 'br.cep' ), '01310-100' );

wccs_proof_check(
	'A postcode keeps its leading zero',
	'01310100' === $wccs_cep->value(),
	'value=' . wp_json_encode( $wccs_cep->value() )
);

$wccs_phone = wccs_proof_process( wccs_proof_from_preset( 'wccs_mobile', 'br.phone.mobile' ), '(11) 99999-8888' );

wccs_proof_check(
	'A mobile number becomes digits',
	'11999998888' === $wccs_phone->value(),
	'value=' . wp_json_encode( $wccs_phone->value() )
);

// ---------------------------------------------------------------------------
// 5. The gate: an alphanumeric CNPJ is neither truncated nor made a number.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. An alphanumeric CNPJ survives' );

$wccs_cnpj_definition = wccs_proof_from_preset( 'wccs_cnpj', 'br.cnpj' );

$wccs_alphanumeric = wccs_proof_process( $wccs_cnpj_definition, '12.ABC.345/01DE-35' );

wccs_proof_check(
	'The letters are still there',
	'12ABC34501DE35' === $wccs_alphanumeric->value(),
	'value=' . wp_json_encode( $wccs_alphanumeric->value() )
);

wccs_proof_check(
	'Nothing was truncated',
	14 === strlen( (string) $wccs_alphanumeric->value() ),
	'length=' . strlen( (string) $wccs_alphanumeric->value() )
);

wccs_proof_check(
	'And it is not a number',
	is_string( $wccs_alphanumeric->value() )
		&& ! is_numeric( $wccs_alphanumeric->value() ),
	'type=' . gettype( $wccs_alphanumeric->value() )
);

wccs_proof_check(
	'Lowercase is the same document',
	'12ABC34501DE35' === wccs_proof_process( $wccs_cnpj_definition, '12.abc.345/01de-35' )->value()
);

$wccs_numeric_cnpj = wccs_proof_process( $wccs_cnpj_definition, '00.111.222/3333-44' );

wccs_proof_check(
	'The legacy numeric format is still accepted, leading zeros included',
	'00111222333344' === $wccs_numeric_cnpj->value(),
	'value=' . wp_json_encode( $wccs_numeric_cnpj->value() )
);

$wccs_illegal = wccs_proof_process( $wccs_cnpj_definition, '12.ABC.345/01DE-3!' );

wccs_proof_check(
	'An illegal character is kept for the validator rather than cleaned away',
	str_contains( (string) $wccs_illegal->value(), '!' ),
	'value=' . wp_json_encode( $wccs_illegal->value() )
);

// The mask and the stored shape have to agree, or the field refuses its own
// value at the last keystroke.
$wccs_mask_definition = (string) $wccs_registries->masks()->mask( 'br.cnpj' )?->definition();

wccs_proof_check(
	'The CNPJ mask offers one position per character of the stored value',
	14 === ( substr_count( $wccs_mask_definition, '0' ) + substr_count( $wccs_mask_definition, '*' ) ),
	'pattern=' . $wccs_mask_definition
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Where the contracts are covered in full',
	'tests/Unit/Domain/Validation/BrazilianDocumentsTest.php walks resources/fixtures/br-documents.json, the same file the JavaScript suite reads, and asserts every declared value, the mask token counts and the preset references.'
);

wccs_proof_note(
	'Not claimed here',
	'Validation. Check digits are WCCS-028s contract, and no preset names a validator, so a wrong document is normalized correctly and accepted for now. The fixtures say so at the top of the file so they cannot be read as validity vectors.'
);

wccs_proof_note(
	'Not exercised here',
	'A rendered classic checkout. The administration side is exercised through the real REST route; the checkout side is the same definition read by the adapter, which WCCS-021 proves against the field array WooCommerce builds.'
);

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
