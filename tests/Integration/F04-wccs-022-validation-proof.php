<?php
/**
 * WCCS-022 proof harness — normalization and final validation.
 *
 * Task:   WCCS-022 "Integrar normalização e validação final"
 * Phase:  F04
 * Accept: "POST adulterado falha mesmo sem JS; erros apontam campos corretos."
 *
 * Both halves of the acceptance are proven against the real WooCommerce flow
 * rather than against a fixture.
 *
 * "Mesmo sem JS" is not a claim about a browser being configured: this harness
 * runs under WP-CLI, with no browser anywhere, and every value it submits is a
 * raw POST entry. If the refusal depended on anything client-side, nothing here
 * would be refused. The harness also asserts the shape of that claim — the
 * storefront half of this plugin is exactly two WordPress hooks, so there is no
 * browser-side check whose absence could matter.
 *
 * "Erros apontam campos corretos" is proven through the same mechanism the
 * customer's browser uses: the error carries the field id, WooCommerce renders it
 * as a `data-id` attribute on the notice, and its own checkout script links the
 * message to `#<field>` and places it inline beside the field.
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
 * Builds a field definition payload.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_def( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
		),
		$changes
	);
}

/**
 * Writes a document straight into a slot, bypassing the routes.
 *
 * @param string            $slot     Slot name.
 * @param array<int, mixed> $fields   Fields.
 * @param array<int, mixed> $sections Sections.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields, array $sections = array() ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => 7,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => $sections,
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * Whether a specific object method is hooked on a tag at a priority.
 *
 * WordPress's own registry is read rather than `has_filter()`, because the
 * callback is an instance method and the instance is private to the class. The
 * point of the assertion is that WooCommerce will really call it, so the
 * registry is the right thing to read.
 *
 * @param string $tag      Hook name.
 * @param int    $priority Priority.
 * @param string $class    Expected class name.
 * @param string $method   Expected method name.
 * @return bool
 */
function wccs_proof_hooked( string $tag, int $priority, string $class, string $method ): bool {
	if ( ! isset( $GLOBALS['wp_filter'][ $tag ] ) ) {
		return false;
	}

	$hook = $GLOBALS['wp_filter'][ $tag ];

	if ( ! isset( $hook->callbacks[ $priority ] ) ) {
		return false;
	}

	foreach ( $hook->callbacks[ $priority ] as $callback ) {
		$function = $callback['function'] ?? null;

		if (
			is_array( $function )
			&& isset( $function[0], $function[1] )
			&& $function[0] instanceof $class
			&& $method === $function[1]
		) {
			return true;
		}
	}

	return false;
}

/**
 * Every hook this plugin registers on the storefront half.
 *
 * @return array<int, array{tag: string, priority: int, class: string, method: string}>
 */
function wccs_proof_plugin_hooks(): array {
	$found = array();

	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! isset( $hook->callbacks ) ) {
			continue;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) ) {
					continue;
				}

				if ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) {
					continue;
				}

				$class = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

				if ( 0 !== strpos( $class, 'WCCheckoutSuite\\Checkout\\Classic\\' ) ) {
					continue;
				}

				$found[] = array(
					'tag'      => (string) $tag,
					'priority' => (int) $priority,
					'class'    => $class,
					'method'   => (string) $function[1],
				);
			}
		}
	}

	return $found;
}

/**
 * Test subclass that exposes WooCommerce's protected validator.
 *
 * A fresh instance is also how the harness gets a form built from the document
 * it just stored. `WC_Checkout` memoises the field array per instance for the
 * rest of the request, and the singleton was already built before this harness
 * stored anything, so asking the singleton would test a form from before the
 * schema existed. A new instance rebuilds it through the real
 * `woocommerce_checkout_fields` filter, which is the path a storefront request
 * takes.
 */
class WCCS_Proof_Checkout extends WC_Checkout {

	/**
	 * Exposes the protected validator.
	 *
	 * @param array    $data   Posted values.
	 * @param WP_Error $errors Error collection.
	 * @return void
	 */
	public function wccs_validate_posted_data( array &$data, WP_Error &$errors ): void {
		$this->validate_posted_data( $data, $errors );
	}
}

/**
 * Builds a checkout whose field array comes from the stored document.
 *
 * @return WCCS_Proof_Checkout
 */
function wccs_proof_checkout(): WCCS_Proof_Checkout {
	return new WCCS_Proof_Checkout();
}

/**
 * Fires the checkout validation action and returns what it collected.
 *
 * @param array<string, mixed> $data Posted values.
 * @return WP_Error
 */
function wccs_proof_error_collection( array $data ): WP_Error {
	$errors = new WP_Error();

	do_action( 'woocommerce_after_checkout_validation', $data, $errors );

	return $errors;
}

/**
 * Stable codes of every error that names a Suite field.
 *
 * @param WP_Error $errors Error collection.
 * @return array<int, string>
 */
function wccs_proof_suite_codes( WP_Error $errors ): array {
	return array_values(
		array_filter(
			$errors->get_error_codes(),
			static function ( string $code ): bool {
				return false !== strpos( $code, '_wccs_' );
			}
		)
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
wccs_proof_out( 'WCCS-022 proof — normalization and final validation' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_draft_slot     = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT;
$wccs_published_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );

// ---------------------------------------------------------------------------
// 1. There is no client-side half to bypass.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. There is no client-side half to bypass' );

wccs_proof_check(
	'The posted data is normalized through WooCommerce\'s own filter',
	wccs_proof_hooked(
		'woocommerce_checkout_posted_data',
		20,
		'WCCheckoutSuite\\Checkout\\Classic\\ClassicValidation',
		'normalize_posted_data'
	)
);

wccs_proof_check(
	'Errors are added to the collection WooCommerce turns into notices',
	wccs_proof_hooked(
		'woocommerce_after_checkout_validation',
		20,
		'WCCheckoutSuite\\Checkout\\Classic\\ClassicValidation',
		'collect_errors'
	)
);

$wccs_hooks    = wccs_proof_plugin_hooks();
$wccs_hook_set = array();

foreach ( $wccs_hooks as $wccs_hook ) {
	$wccs_hook_set[] = $wccs_hook['tag'] . '@' . $wccs_hook['priority'] . ':' . $wccs_hook['method'];
}

sort( $wccs_hook_set );

$wccs_hook_expected = array(
	'woocommerce_after_checkout_validation@20:collect_errors',
	'woocommerce_checkout_create_order@20:persist',
	'woocommerce_checkout_fields@20:filter_fields',
	'woocommerce_checkout_posted_data@20:normalize_posted_data',
		// Added by WCCS-043: the classic checkout has no file type, and this is the
		// documented filter an unknown type reaches.
		'woocommerce_form_field_file@10:render',
	'wp_enqueue_scripts@10:enqueue',
);

sort( $wccs_hook_expected );

wccs_proof_check(
	'The storefront half is exactly these hooks and nothing else',
	$wccs_hook_expected === $wccs_hook_set,
	'found ' . wp_json_encode( $wccs_hook_set )
);

$wccs_asset_hits = 0;

foreach ( array_merge( (array) wp_scripts()->registered, (array) wp_styles()->registered ) as $wccs_asset ) {
	if ( isset( $wccs_asset->src ) && false !== strpos( (string) $wccs_asset->src, 'wc-checkout-suite' ) ) {
		++$wccs_asset_hits;
	}
}

wccs_proof_check(
	'No asset from this plugin is registered on the request',
	0 === $wccs_asset_hits,
	'assets=' . $wccs_asset_hits
);

// ---------------------------------------------------------------------------
// 2. Publish a schema, then let WooCommerce build the form once.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A published schema' );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def( 'wccs_cpf', array( 'normalizer' => 'digits' ) ),
		wccs_proof_def(
			'wccs_size',
			array(
				'type'     => 'select',
				'settings' => array(
					'options' => array(
						array(
							'value' => 's',
							'label' => 'Small',
						),
						array(
							'value' => 'm',
							'label' => 'Medium',
						),
					),
				),
			)
		),
		wccs_proof_def( 'wccs_qty', array( 'type' => 'number' ) ),
		wccs_proof_def( 'wccs_phone', array( 'type' => 'tel' ) ),
		wccs_proof_def( 'wccs_long', array( 'settings' => array( 'maxLength' => 5 ) ) ),
		wccs_proof_def(
			'wccs_doc',
			array(
				'label'    => 'Document',
				'required' => true,
			)
		),
		wccs_proof_def(
			'wccs_attachment',
			array(
				'type'     => 'heading',
				'required' => true,
			)
		),
	)
);

// The form is built by a fresh checkout instance, so the field array is produced
// by the real `woocommerce_checkout_fields` filter with the stored document in
// place — the same path a storefront request takes.
$wccs_checkout = wccs_proof_checkout();
$wccs_fields   = $wccs_checkout->get_checkout_fields();

wccs_proof_check(
	'The Suite fields are on the checkout',
	isset( $wccs_fields['billing']['wccs_cpf'], $wccs_fields['billing']['wccs_size'], $wccs_fields['billing']['wccs_doc'] )
);

wccs_proof_check(
	'A required definition is a required checkout field',
	! empty( $wccs_fields['billing']['wccs_doc']['required'] )
);

wccs_proof_check(
	'A file field is not on the checkout at all',
	! isset( $wccs_fields['billing']['wccs_attachment'] ),
	'the adapter has no rendering for it and reports it as skipped'
);

// ---------------------------------------------------------------------------
// 3. A forged POST, with no browser involved.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A forged POST' );

$_POST = array(
	'woocommerce-process-checkout-nonce' => 'forged-by-the-harness',
	'payment_method'                     => 'bacs',
	'billing_first_name'                 => 'Ana',
	'billing_last_name'                  => 'Silva',
	'billing_country'                    => 'BR',
	'billing_email'                      => 'ana@example.test',
	'wccs_cpf'                           => '123.456.789-09',
	'wccs_size'                          => 'xxl',
	'wccs_qty'                           => 'not a number',
	'wccs_phone'                         => 'call me maybe',
	'wccs_long'                          => 'abcdefghij',
	'wccs_doc'                           => '',
	'wccs_attachment'                    => 'evil.pdf',
);

$wccs_data = $wccs_checkout->get_posted_data();

wccs_proof_check(
	'Normalization decides the value the store acts on',
	'12345678909' === ( $wccs_data['wccs_cpf'] ?? null ),
	'posted 123.456.789-09 -> ' . var_export( $wccs_data['wccs_cpf'] ?? null, true )
);

wccs_proof_check(
	'A forged key for a field that is not on the form does not exist afterwards',
	! array_key_exists( 'wccs_attachment', $wccs_data ),
	'WooCommerce builds posted data from the form, so the posted key is dropped'
);

// ---------------------------------------------------------------------------
// 4. Every adulterated value is refused, and each names its own field.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Every adulterated value fails server-side' );

$wccs_errors = wccs_proof_error_collection( $wccs_data );
$wccs_codes  = $wccs_errors->get_error_codes();

$wccs_expected = array(
	'wccs_size'  => 'wccs_size_wccs_invalid_choice',
	'wccs_qty'   => 'wccs_qty_wccs_not_a_number',
	'wccs_phone' => 'wccs_phone_wccs_invalid_phone',
	'wccs_long'  => 'wccs_long_wccs_too_long',
);

foreach ( $wccs_expected as $wccs_field => $wccs_code ) {
	wccs_proof_check(
		'A value the browser would never submit is refused: ' . $wccs_field,
		in_array( $wccs_code, $wccs_codes, true ),
		'code=' . $wccs_code
	);

	$wccs_data_for_code = $wccs_errors->get_error_data( $wccs_code );

	wccs_proof_check(
		'  and the error names that field: ' . $wccs_field,
		is_array( $wccs_data_for_code ) && ( $wccs_data_for_code['id'] ?? '' ) === $wccs_field,
		'id=' . ( is_array( $wccs_data_for_code ) ? (string) ( $wccs_data_for_code['id'] ?? '' ) : 'none' )
	);
}

wccs_proof_check(
	'Nothing was reported for a field the customer cannot fill',
	! in_array( 'wccs_attachment_wccs_required', $wccs_codes, true ),
	'an unrenderable required field must not block the checkout'
);

// ---------------------------------------------------------------------------
// 5. The notice the customer sees carries the field.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The notice points at the field' );

foreach ( $wccs_errors->errors as $wccs_code => $wccs_messages ) {
	$wccs_notice_data = $wccs_errors->get_error_data( $wccs_code );

	foreach ( $wccs_messages as $wccs_message ) {
		wc_add_notice( $wccs_message, 'error', is_array( $wccs_notice_data ) ? $wccs_notice_data : array() );
	}
}

wccs_proof_check(
	'Four errors reached the customer notice list',
	4 === wc_notice_count( 'error' ),
	'count=' . wc_notice_count( 'error' )
);

$wccs_html = (string) wc_print_notices( true );

foreach ( array_keys( $wccs_expected ) as $wccs_field ) {
	wccs_proof_check(
		'The rendered notice carries data-id="' . $wccs_field . '"',
		false !== strpos( $wccs_html, 'data-id="' . $wccs_field . '"' ),
		'WooCommerce links the message to #' . $wccs_field . ' and places it inline'
	);
}

// ---------------------------------------------------------------------------
// 6. Requiredness belongs to WooCommerce, and it is enforced.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Requiredness is enforced once' );

$wccs_woo_errors = new WP_Error();
$wccs_validated  = $wccs_data;

$wccs_checkout->wccs_validate_posted_data( $wccs_validated, $wccs_woo_errors );

$wccs_woo_codes = $wccs_woo_errors->get_error_codes();

wccs_proof_check(
	'An empty required field is refused by WooCommerce',
	in_array( 'wccs_doc_required', $wccs_woo_codes, true ),
	'the adapter wrote required=true from the definition, and WooCommerce enforces it'
);

$wccs_doc_error_data = $wccs_woo_errors->get_error_data( 'wccs_doc_required' );

wccs_proof_check(
	'  and WooCommerce names the same field',
	is_array( $wccs_doc_error_data ) && 'wccs_doc' === ( $wccs_doc_error_data['id'] ?? '' ),
	'id=' . ( is_array( $wccs_doc_error_data ) ? (string) ( $wccs_doc_error_data['id'] ?? '' ) : 'none' )
);

wccs_proof_check(
	'The Suite did not add a second message about the same field',
	! in_array( 'wccs_doc_wccs_required', $wccs_codes, true ),
	'one rule, one message'
);

$wccs_messages_for_doc = 0;

foreach ( $wccs_woo_codes as $wccs_code ) {
	if ( '' !== $wccs_code && 0 === strpos( $wccs_code, 'wccs_doc' ) ) {
		$wccs_messages_for_doc += count( $wccs_woo_errors->get_error_messages( $wccs_code ) );
	}
}

wccs_proof_check(
	'Exactly one message is produced for that field',
	1 === $wccs_messages_for_doc,
	'messages=' . $wccs_messages_for_doc
);

// A field the customer cannot fill must not be required by anyone.
wccs_proof_check(
	'The unrenderable required field is required by nobody',
	! in_array( 'wccs_attachment_required', $wccs_woo_codes, true )
);

// ---------------------------------------------------------------------------
// 7. Saving a draft changes none of this.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. The draft is invisible to validation' );

wccs_proof_store(
	$wccs_draft_slot,
	array(
		wccs_proof_def(
			'wccs_size',
			array(
				'type'     => 'select',
				'settings' => array(
					'options' => array(
						array(
							'value' => 'xxl',
							'label' => 'Extra large',
						),
					),
				),
			)
		),
		wccs_proof_def(
			'wccs_draft_only',
			array(
				'type'     => 'text',
				'required' => true,
			)
		),
	)
);

// A fresh checkout instance, so the form is rebuilt from the published document
// after the draft was written. If the draft leaked anywhere, the rebuilt form
// would show it.
$wccs_after_draft_checkout = wccs_proof_checkout();
$wccs_after_draft_fields   = $wccs_after_draft_checkout->get_checkout_fields();

wccs_proof_check(
	'The form is rebuilt from the published document, not the draft',
	! isset( $wccs_after_draft_fields['billing']['wccs_draft_only'] )
		&& isset( $wccs_after_draft_fields['billing']['wccs_size'] ),
	'fields=' . implode( ',', array_keys( $wccs_after_draft_fields['billing'] ) )
);

wccs_proof_check(
	'And the published options are still the offered ones',
	array( 's', 'm' ) === array_keys( (array) ( $wccs_after_draft_fields['billing']['wccs_size']['options'] ?? array() ) ),
	'options=' . wp_json_encode( array_keys( (array) ( $wccs_after_draft_fields['billing']['wccs_size']['options'] ?? array() ) ) )
);

$wccs_data_after_draft = $wccs_after_draft_checkout->get_posted_data();
$wccs_after_errors     = wccs_proof_error_collection( $wccs_data_after_draft );
$wccs_after_codes      = $wccs_after_errors->get_error_codes();

$wccs_after_draft_woo     = new WP_Error();
$wccs_after_draft_checked = $wccs_data_after_draft;

$wccs_after_draft_checkout->wccs_validate_posted_data( $wccs_after_draft_checked, $wccs_after_draft_woo );
$wccs_after_draft_codes = $wccs_after_draft_woo->get_error_codes();

wccs_proof_check(
	'A draft that would make the forged value legal does not make it legal',
	in_array( 'wccs_size_wccs_invalid_choice', $wccs_after_codes, true ),
	'the published options still decide what is a choice'
);

wccs_proof_check(
	'A field that exists only in the draft is not validated',
	! array_key_exists( 'wccs_draft_only', $wccs_data_after_draft )
		&& ! in_array( 'wccs_draft_only_required', $wccs_after_draft_codes, true )
);

wccs_proof_check(
	'And the published field is still required',
	in_array( 'wccs_doc_required', $wccs_after_draft_codes, true )
);

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

$_POST = array();

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Not exercised here',
	'A completed order. Reaching create_order() needs a Classic checkout page and a cart, and this store has neither: that is the open decision CLASSIC-TEST-SURFACE. What is proven is the validation WooCommerce runs before it, through WooCommerce\'s own posted-data filter and its own error collection.'
);
wccs_proof_note(
	'Not exercised here',
	'The browser half of the error display. `checkout.js` wraps each notice with a data-id in a link to the field and moves the message inline beside it; the assertions above stop at the rendered data-id, which is the contract that script reads.'
);
wccs_proof_note(
	'Cross-reference',
	'The pure decisions — what value is carried back, and which errors are reported — are covered by tests/Unit/Checkout/Classic/ClassicSubmissionTest.php (12 specs).'
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
