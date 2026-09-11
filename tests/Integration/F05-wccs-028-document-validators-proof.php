<?php
/**
 * WCCS-028 proof harness — the Brazilian validators.
 *
 * Task:   WCCS-028 "Implementar validadores PHP/JS"
 * Phase:  F05
 * Accept: "Mesmos fixtures passam/falham; DV não é apresentado como validação de identidade."
 *
 * The rules themselves are covered by the unit suite and, on the other side of the
 * same fixture file, by the JavaScript suite. What this harness adds is the phase
 * gate: a wrong document has to stop the checkout, and that is a claim about the
 * running plugin rather than about a function.
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
 * @param string $id     Field identifier.
 * @param string $preset Preset key.
 * @return array<string, mixed>
 */
function wccs_proof_from_preset( string $id, string $preset ): array {
	$registered = \WCCheckoutSuite\Domain\Registries::instance()->presets()->preset( $preset );

	if ( null === $registered ) {
		return array();
	}

	$defaults = $registered->defaults();

	return array(
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
		'mask'           => $defaults['mask'] ?? null,
		'normalizer'     => $defaults['normalizer'] ?? null,
		'validators'     => $defaults['validators'] ?? array(),
		'conditions'     => array(),
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'visibility'     => array(
			'admin_order' => true,
		),
	);
}

/**
 * Publishes a document into the published slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 6,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * The form WooCommerce builds from the stored document.
 *
 * @return array<string, mixed>
 */
function wccs_proof_form(): array {
	$checkout = new WC_Checkout();

	return $checkout->get_checkout_fields();
}

/**
 * Runs the checkout's own validation over a set of posted values.
 *
 * @param array<string, mixed> $data Posted values.
 * @return WP_Error
 */
function wccs_proof_errors( array $data ): WP_Error {
	$errors = new WP_Error();

	do_action( 'woocommerce_after_checkout_validation', $data, $errors );

	return $errors;
}

/**
 * Reads the patterns a source file declares.
 *
 * The rule ADR-0003 asks to check is about code, and three of these files explain
 * the forbidden expression in their own documentation — the opposite of hiding it
 * — so comments are removed first. What is then read differs by language for a
 * reason worth stating: in PHP a pattern is a string literal, and searching the
 * whole of a PHP file for the two characters would match every namespace
 * separator followed by a `D`, which `WCCheckoutSuite\Domain` is full of. So PHP
 * contributes its string literals and JavaScript contributes its comment-stripped
 * source, where its regex literals live.
 *
 * @param string $path Absolute path.
 * @return string
 */
function wccs_proof_patterns( string $path ): string {
	$source = (string) file_get_contents( $path );

	if ( ! str_ends_with( $path, '.php' ) ) {
		$source = (string) preg_replace( '#/\*.*?\*/#s', '', $source );

		return (string) preg_replace( '#(^|\s)//[^\n]*#', '$1', $source );
	}

	$literals = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$literals .= $token[1] . "\n";
		}
	}

	return $literals;
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
wccs_proof_out( 'WCCS-028 proof — the Brazilian validators' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_published = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );
$wccs_registry  = \WCCheckoutSuite\Domain\Registries::instance();

delete_option( $wccs_published );

// ---------------------------------------------------------------------------
// 1. The rules exist, and RG has none.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The validators are registered at boot' );

foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' ) as $wccs_key ) {
	wccs_proof_check(
		'The validator ' . $wccs_key . ' is registered',
		$wccs_registry->validators()->has( $wccs_key )
	);
}

wccs_proof_check(
	'RG has no validator, and the registry is where that is visible',
	! $wccs_registry->validators()->has( 'br.rg' ),
	'the format depends on the issuing state, and a key that accepted everything would look like a check'
);

foreach ( array( 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.landline', 'br.phone.mobile' ) as $wccs_key ) {
	$wccs_definition = wccs_proof_from_preset( str_replace( '.', '_', $wccs_key ), $wccs_key );

	wccs_proof_check(
		'A field built from ' . $wccs_key . ' now passes the real validator',
		$wccs_registry->definition_validator()->validate_array( $wccs_definition )->is_valid(),
		'errors=' . wp_json_encode( $wccs_registry->definition_validator()->validate_array( $wccs_definition )->error_codes() )
	);
}

// ---------------------------------------------------------------------------
// 2. The gate: a wrong document stops the checkout.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A wrong document does not finish' );

wccs_proof_publish(
	array(
		wccs_proof_from_preset( 'wccs_cpf', 'br.cpf' ),
		wccs_proof_from_preset( 'wccs_cnpj', 'br.cnpj' ),
		wccs_proof_from_preset( 'wccs_cep', 'br.cep' ),
		wccs_proof_from_preset( 'wccs_mobile', 'br.phone.mobile' ),
	)
);

$wccs_form = wccs_proof_form();

wccs_proof_check(
	'The Suite fields are on the checkout',
	isset( $wccs_form['billing']['wccs_cpf'], $wccs_form['billing']['wccs_cnpj'] ),
	'fields=' . wp_json_encode( array_keys( (array) $wccs_form['billing'] ) )
);

/**
 * Submits a forged POST through the real checkout filters.
 *
 * @param array<string, string> $values Posted values.
 * @return array{data: array<string, mixed>, codes: array<int, string>, messages: array<int, string>}
 */
function wccs_proof_submit( array $values ): array {
	$_POST = array_merge(
		array(
			'woocommerce-process-checkout-nonce' => 'forged-by-the-harness',
			'payment_method'                     => 'bacs',
			'billing_first_name'                 => 'Ana',
			'billing_last_name'                  => 'Silva',
			'billing_country'                    => 'BR',
			'billing_email'                      => 'ana@example.test',
		),
		$values
	);

	// A fresh checkout instance: the singleton memoises its field array for the
	// request, and this harness publishes a document after the singleton may
	// already have been built.
	$checkout = new WC_Checkout();
	$data     = $checkout->get_posted_data();
	$errors   = wccs_proof_errors( $data );

	return array(
		'data'     => $data,
		'codes'    => $errors->get_error_codes(),
		'messages' => array_map(
			static function ( string $code ) use ( $errors ): string {
				return implode( ' ', $errors->get_error_messages( $code ) );
			},
			$errors->get_error_codes()
		),
	);
}

$wccs_bad = wccs_proof_submit(
	array(
		'wccs_cpf'    => '529.982.247-24',
		'wccs_cnpj'   => '12.ABC.345/01DE-36',
		'wccs_cep'    => '0131010',
		'wccs_mobile' => '(11) 89999-8888',
	)
);

wccs_proof_check(
	'A CPF whose check digits do not match is refused, and the error names the field',
	in_array( 'wccs_cpf_wccs_invalid_cpf', $wccs_bad['codes'], true ),
	'codes=' . wp_json_encode( $wccs_bad['codes'] )
);

wccs_proof_check(
	'An alphanumeric CNPJ whose check digits do not match is refused',
	in_array( 'wccs_cnpj_wccs_invalid_cnpj', $wccs_bad['codes'], true )
);

wccs_proof_check(
	'A postcode with seven digits is refused',
	in_array( 'wccs_cep_wccs_invalid_postcode', $wccs_bad['codes'], true )
);

wccs_proof_check(
	'A number that is not a mobile is refused',
	in_array( 'wccs_mobile_wccs_invalid_phone', $wccs_bad['codes'], true )
);

wccs_proof_check(
	'Four fields, four refusals: nothing was silently accepted',
	4 === count( $wccs_bad['codes'] ),
	'codes=' . wp_json_encode( $wccs_bad['codes'] )
);

$wccs_good = wccs_proof_submit(
	array(
		'wccs_cpf'    => '529.982.247-25',
		'wccs_cnpj'   => '12.ABC.345/01DE-35',
		'wccs_cep'    => '01310-100',
		'wccs_mobile' => '(11) 99999-8888',
	)
);

wccs_proof_check(
	'The same four fields, correctly filled, produce no error at all',
	array() === $wccs_good['codes'],
	'codes=' . wp_json_encode( $wccs_good['codes'] )
);

wccs_proof_check(
	'And the values reaching the checkout are the canonical ones',
	'52998224725' === ( $wccs_good['data']['wccs_cpf'] ?? null )
		&& '12ABC34501DE35' === ( $wccs_good['data']['wccs_cnpj'] ?? null )
		&& '01310100' === ( $wccs_good['data']['wccs_cep'] ?? null ),
	'cpf=' . wp_json_encode( $wccs_good['data']['wccs_cpf'] ?? null )
		. ' cnpj=' . wp_json_encode( $wccs_good['data']['wccs_cnpj'] ?? null )
);

// ---------------------------------------------------------------------------
// 3. An alphanumeric CNPJ survives the whole path.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. An alphanumeric CNPJ is never reduced to digits' );

$wccs_alphanumeric = $wccs_good['data']['wccs_cnpj'] ?? null;

wccs_proof_check(
	'The letters are still there after normalization and validation',
	is_string( $wccs_alphanumeric )
		&& str_contains( $wccs_alphanumeric, 'ABC' )
		&& str_contains( $wccs_alphanumeric, 'DE' ),
	'value=' . wp_json_encode( $wccs_alphanumeric )
);

wccs_proof_check(
	'Nothing was truncated and nothing became a number',
	is_string( $wccs_alphanumeric )
		&& 14 === strlen( $wccs_alphanumeric )
		&& ! is_numeric( $wccs_alphanumeric ),
	'type=' . gettype( $wccs_alphanumeric ) . ' length=' . strlen( (string) $wccs_alphanumeric )
);

$wccs_published_example = wccs_proof_submit( array( 'wccs_cnpj' => 'UK.PVM.E1E/8HI9-96' ) );

wccs_proof_check(
	'The published alphanumeric test number is accepted',
	array() === $wccs_published_example['codes'],
	'codes=' . wp_json_encode( $wccs_published_example['codes'] )
);

// ---------------------------------------------------------------------------
// 4. What the customer is told.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The message is about the number' );

$wccs_message = '';

foreach ( $wccs_bad['codes'] as $wccs_index => $wccs_code ) {
	if ( 'wccs_cpf_wccs_invalid_cpf' === $wccs_code ) {
		$wccs_message = $wccs_bad['messages'][ $wccs_index ];
	}
}

wccs_proof_check(
	'The refusal reaches the customer as a sentence',
	'' !== $wccs_message,
	'message=' . $wccs_message
);

$wccs_forbidden = array( 'identity', 'identidade', 'titular', 'owner', 'verified', 'verificad', 'confirms', 'authentic', 'autêntic' );

foreach ( $wccs_forbidden as $wccs_word ) {
	wccs_proof_check(
		'And it does not claim to have verified anybody: "' . $wccs_word . '"',
		! str_contains( strtolower( $wccs_message ), $wccs_word ),
		'message=' . $wccs_message
	);
}

// ---------------------------------------------------------------------------
// 5. The literal rule ADR-0003 asks for.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. No document is ever "cleaned" into looking valid' );

// ADR-0003 asks for an explicit check that preg_replace( '/\D/' ) is not used on
// the document path. It is a source check, and it is here because the ADR asks for
// it; the behavioural assertions above are what actually establish the property.
$wccs_document_path = array(
	'src/Domain/Validation/CheckDigits.php',
	'src/Domain/Validation/Normalizers/BrazilianDocumentNormalizer.php',
	'src/Domain/Validation/Validators/DocumentValidator.php',
	'resources/checkout/validators.js',
);

foreach ( $wccs_document_path as $wccs_relative ) {
	$wccs_code = wccs_proof_patterns( WCCS_PLUGIN_DIR . $wccs_relative );

	wccs_proof_check(
		$wccs_relative . ' declares no pattern with a \\D class',
		! str_contains( $wccs_code, '\\D' ),
		'a class that removes every non-digit is what turns an alphanumeric CNPJ into a different number'
	);
}

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

$_POST = array();

delete_option( $wccs_published );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'One deliberate difference between the two sides',
	'The server validates the canonical value, because ADR-0003 makes the named normalizer the only thing allowed to transform a document, so it refuses a formatted or lowercase value rather than cleaning it. The browser validates what is in the field, which is the formatted value the mask produced, so it removes the punctuation and uppercases before checking. The shared fixture keeps those cases in their own list, named refused_by: server and accepted_by: browser, so the difference is a decision rather than a drift, and both sides assert their own answer.'
);

wccs_proof_note(
	'A hazard that remains reachable',
	'The generic `digits` normalizer is still a preg_replace( \'/\\D/\', ... ) and a merchant can still select it for a document field in the inspector. Choosing it for a CNPJ field would feed the validator a truncated value, which is refused loudly rather than stored — a failure, not a silent corruption. Steering the inspector away from that combination belongs with the field list and the inspector work, and is recorded here rather than fixed by narrowing a primitive that other fields legitimately use.'
);

wccs_proof_note(
	'Not exercised here',
	'A rendered classic checkout. This harness drives the same two hooks a checkout does — the posted-data filter and the validation action — so the refusal is proven at the seam where a real request would produce it; observing it on a page needs the checkout page this store does not have.'
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
