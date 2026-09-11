<?php
/**
 * WCCS-036 proof harness — the Blocks adapter on a real store.
 *
 * Task:   WCCS-036 "Implementar native additional fields"
 * Phase:  F07
 * Accept: "Text/select/checkbox/date com feature detection e políticas de localização/storage."
 *
 * The unit suite proves the translation. What this harness proves is that the
 * translation reaches WooCommerce's own registry and that the policies hold where it
 * counts:
 *
 * 1. **Feature detection** — the API is detected, and the list of native types is
 *    read from the platform rather than trusted to a comment.
 * 2. **The three native types register, and land where they belong** — text,
 *    select and checkbox in the contact, address and order locations, read back
 *    out of `CheckoutFields::get_additional_fields()`.
 * 3. **Date is refused, not approximated** — the acceptance names it, the platform
 *    does not have it, and the field is reported rather than registered as text.
 * 4. **The location and storage policies hold** — a field in an unpublished
 *    section, a field that asks not to be stored and a field that asks to be stored
 *    on the customer are each refused with a reason, and a refusal never takes the
 *    other registrations down with it.
 * 5. **The condition compiler's output is what gets registered** — a compilable
 *    rule arrives as the native `required` and `hidden` on the field WooCommerce
 *    holds.
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
 * Writes a document straight into the published slot.
 *
 * @param array<int, mixed> $fields   Fields.
 * @param array<int, mixed> $sections Sections.
 * @return void
 */
function wccs_proof_publish( array $fields, array $sections = array() ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 5,
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
 * A field definition.
 *
 * @param string               $id      Identifier.
 * @param string               $type    Type.
 * @param string               $section Section.
 * @param array<string, mixed> $extra   Extra keys.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $type = 'text', string $section = 'billing', array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => $type,
			'label'          => $id,
			'section'        => $section,
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => array(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array( 'admin_order' => true ),
		),
		$extra
	);
}

/**
 * A section.
 *
 * @param string $id       Identifier.
 * @param string $location Location.
 * @return array<string, mixed>
 */
function wccs_proof_section( string $id, string $location ): array {
	return array(
		'id'       => $id,
		'title'    => $id,
		'location' => $location,
		'position' => 10,
	);
}

/**
 * The checkout fields service WooCommerce holds the registrations in.
 *
 * @return object|null
 */
function wccs_proof_registry(): ?object {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
		return null;
	}

	try {
		$service = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
	} catch ( \Throwable $error ) {
		return null;
	}

	return is_object( $service ) ? $service : null;
}

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-036 proof — the Blocks adapter' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. Feature detection.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Feature detection' );

$wccs_registry = wccs_proof_registry();

wccs_proof_check(
	'The Blocks additional-fields API is present on this store',
	\WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::is_available()
		&& function_exists( 'woocommerce_register_additional_checkout_field' )
);

wccs_proof_check(
	'The checkout fields service is reachable',
	null !== $wccs_registry && method_exists( $wccs_registry, 'get_additional_fields' )
);

// The list is read from the platform by asking it to register a type it does not
// have: WooCommerce answers with the types it does, which is the only way to read a
// private, unfiltered array.
$wccs_doing_it_wrong = array();

add_action(
	'doing_it_wrong_run',
	static function ( $function_name, $message ) use ( &$wccs_doing_it_wrong ) {
		$wccs_doing_it_wrong[] = (string) $message;
	},
	10,
	2
);

woocommerce_register_additional_checkout_field(
	array(
		'id'       => 'wc-checkoutsuite/probe-date-' . wp_rand( 1000, 9999 ),
		'label'    => 'Probe date',
		'location' => 'contact',
		'type'     => 'date',
	)
);

$wccs_native_types = array();

foreach ( $wccs_doing_it_wrong as $wccs_message ) {
	if ( preg_match( '/supported types are:\s*(.+?)\./i', $wccs_message, $wccs_match ) ) {
		$wccs_native_types = array_map( 'trim', explode( ',', $wccs_match[1] ) );
	}
}

wccs_proof_check(
	'The types the adapter declares are the types the platform lists',
	$wccs_native_types === \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::NATIVE_TYPES,
	'platform=' . wp_json_encode( $wccs_native_types ) . ' adapter=' . wp_json_encode( \WCCheckoutSuite\Checkout\Blocks\BlocksAdapter::NATIVE_TYPES )
);

wccs_proof_check(
	'And date is not among them, which is what the refusal is about',
	! in_array( 'date', $wccs_native_types, true )
);

// ---------------------------------------------------------------------------
// 2. The native types register, where they belong.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What registers, and where' );

wccs_proof_publish(
	array(
		wccs_proof_field( 'contact_note', 'text', 'contact' ),
		wccs_proof_field(
			'person_type',
			'select',
			'billing',
			array(
				'settings' => array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => 'Person',
						),
						array(
							'value' => 'pj',
							'label' => 'Company',
						),
					),
				),
			)
		),
		wccs_proof_field( 'consent', 'checkbox', 'order' ),
		wccs_proof_field( 'birth_date', 'date', 'billing' ),
		wccs_proof_field( 'not_stored', 'text', 'billing', array( 'storage' => array( 'scope' => 'none', 'sensitivity' => 'personal' ) ) ),
		wccs_proof_field( 'on_customer', 'text', 'billing', array( 'storage' => array( 'scope' => 'customer', 'sensitivity' => 'personal' ) ) ),
		wccs_proof_field( 'lost_section', 'text', 'nowhere' ),
		wccs_proof_field( 'disabled_one', 'text', 'billing', array( 'enabled' => false ) ),
		wccs_proof_field(
			'conditional',
			'text',
			'billing',
			array(
				'required'   => true,
				'conditions' => array(
					'visible' => array(
						'source'   => 'country',
						'operator' => 'equals',
						'value'    => 'BR',
					),
				),
			)
		),
	),
	array(
		wccs_proof_section( 'contact', 'contact' ),
		wccs_proof_section( 'billing', 'billing' ),
		wccs_proof_section( 'order', 'order' ),
	)
);

$wccs_report = array();

add_action(
	\WCCheckoutSuite\Checkout\Blocks\BlocksCheckout::REPORT_ACTION,
	static function ( $registered, $refused ) use ( &$wccs_report ) {
		$wccs_report = array(
			'registered' => $registered,
			'refused'    => $refused,
		);
	},
	10,
	2
);

$wccs_outcome = \WCCheckoutSuite\Checkout\Blocks\BlocksCheckout::apply();

$wccs_fields   = array();
$wccs_location = array();

if ( null !== $wccs_registry ) {
	$wccs_fields = (array) $wccs_registry->get_additional_fields();

	foreach ( $wccs_fields as $wccs_key => $wccs_field ) {
		$wccs_location[ (string) $wccs_key ] = isset( $wccs_field['location'] ) ? (string) $wccs_field['location'] : '';
	}
}

wccs_proof_check(
	'The adapter reports what it registered',
	$wccs_report !== array() && count( $wccs_report['registered'] ) === count( $wccs_outcome['registered'] ),
	'registered=' . wp_json_encode( $wccs_outcome['registered'] )
);

foreach ( array( 'wc-checkoutsuite/wccs_contact_note', 'wc-checkoutsuite/wccs_person_type', 'wc-checkoutsuite/wccs_consent' ) as $wccs_id ) {
	wccs_proof_check(
		sprintf( 'WooCommerce holds the registered field %s', $wccs_id ),
		isset( $wccs_fields[ $wccs_id ] )
	);
}

wccs_proof_check(
	'The text field landed in the contact location',
	'contact' === ( $wccs_location['wc-checkoutsuite/wccs_contact_note'] ?? '' ),
	'location=' . ( $wccs_location['wc-checkoutsuite/wccs_contact_note'] ?? '?' )
);

wccs_proof_check(
	'The select landed in the address location, with its options',
	'address' === ( $wccs_location['wc-checkoutsuite/wccs_person_type'] ?? '' )
		&& array( 'pf', 'pj' ) === array_column( $wccs_fields['wc-checkoutsuite/wccs_person_type']['options'] ?? array(), 'value' ),
	'options=' . wp_json_encode( array_column( $wccs_fields['wc-checkoutsuite/wccs_person_type']['options'] ?? array(), 'value' ) )
);

wccs_proof_check(
	'The checkbox landed in the order location',
	'order' === ( $wccs_location['wc-checkoutsuite/wccs_consent'] ?? '' )
);

// ---------------------------------------------------------------------------
// 3. What is refused, and why.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What is refused, and why' );

$wccs_codes = array();

foreach ( $wccs_outcome['refused'] as $wccs_entry ) {
	$wccs_codes[ (string) $wccs_entry['field'] ] = (string) $wccs_entry['code'];
}

wccs_proof_check(
	'The date field was not registered',
	! isset( $wccs_fields['wc-checkoutsuite/wccs_birth_date'] ),
	'it would have been registered as text, which is not what was configured'
);

wccs_proof_check(
	'And it is reported as needing a controlled component',
	'needs_controlled_component' === ( $wccs_codes['wccs_birth_date'] ?? '' ),
	'code=' . ( $wccs_codes['wccs_birth_date'] ?? '(none)' )
);

wccs_proof_check(
	'A field that declares it is not stored is refused',
	! isset( $wccs_fields['wc-checkoutsuite/wccs_not_stored'] )
		&& 'storage_not_native' === ( $wccs_codes['wccs_not_stored'] ?? '' )
);

wccs_proof_check(
	'A field that declares customer storage is refused',
	! isset( $wccs_fields['wc-checkoutsuite/wccs_on_customer'] )
		&& 'storage_not_native' === ( $wccs_codes['wccs_on_customer'] ?? '' )
);

wccs_proof_check(
	'A field whose section is not published is refused',
	! isset( $wccs_fields['wc-checkoutsuite/wccs_lost_section'] )
		&& 'unknown_location' === ( $wccs_codes['wccs_lost_section'] ?? '' )
);

wccs_proof_check(
	'A disabled field is neither registered nor reported',
	! isset( $wccs_fields['wc-checkoutsuite/wccs_disabled_one'] )
		&& ! isset( $wccs_codes['wccs_disabled_one'] )
);

wccs_proof_check(
	'A refusal does not stop the fields that can be registered',
	count( $wccs_outcome['registered'] ) >= 4,
	'registered=' . count( $wccs_outcome['registered'] ) . ' refused=' . count( $wccs_outcome['refused'] )
);

// ---------------------------------------------------------------------------
// 4. The condition compiler's output, as WooCommerce now holds it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The condition, as the native mechanism holds it' );

$wccs_conditional = $wccs_fields['wc-checkoutsuite/wccs_conditional'] ?? array();

wccs_proof_check(
	'The compiled rule reached the field as its required rule',
	is_array( $wccs_conditional['required'] ?? null )
		&& 'BR' === ( $wccs_conditional['required']['customer']['properties']['billing_address']['properties']['country']['const'] ?? null ),
	'required=' . wp_json_encode( $wccs_conditional['required'] ?? null )
);

wccs_proof_check(
	'And as its hidden rule, negated',
	is_array( $wccs_conditional['hidden'] ?? null )
		&& isset( $wccs_conditional['hidden']['not'] )
);

wccs_proof_check(
	'Which is what makes the field required exactly when it is shown',
	\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation::is_valid_schema( $wccs_conditional['required'] )
		&& \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation::is_valid_schema( $wccs_conditional['hidden'] )
);

// A rule the compiler cannot express does not stop the registration; it is
// reported, and the server keeps deciding (ADR-0008).
wccs_proof_publish(
	array(
		wccs_proof_field(
			'by_category',
			'text',
			'billing',
			array(
				'conditions' => array(
					'visible' => array(
						'source'   => 'cart_categories',
						'operator' => 'contains',
						'value'    => 'books',
					),
				),
			)
		),
	),
	array( wccs_proof_section( 'billing', 'billing' ) )
);

$wccs_second = \WCCheckoutSuite\Checkout\Blocks\BlocksCheckout::apply();

$wccs_second_codes = array();

foreach ( $wccs_second['refused'] as $wccs_entry ) {
	$wccs_second_codes[ (string) $wccs_entry['field'] ] = (string) $wccs_entry['code'];
}

wccs_proof_check(
	'A rule that cannot be expressed natively is reported, not fatal',
	in_array( 'wc-checkoutsuite/wccs_by_category', $wccs_second['registered'], true )
		&& 'condition_not_native' === ( $wccs_second_codes['wccs_by_category'] ?? '' ),
	'code=' . ( $wccs_second_codes['wccs_by_category'] ?? '(none)' )
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why a refusal is not an error',
	'Each refusal is a capability the platform does not have: no native date type, no customer storage, no place to put a field whose section was not published. Registering them anyway would produce a field that collects something other than what the merchant configured. ADR-0008 is the other side of the same rule: a condition that cannot be expressed natively is reported and the field is registered, because the server still decides it.'
);

wccs_proof_note(
	'The registrations live for this request only',
	'Additional fields are registered on every request that loads the Blocks checkout, from the published document. This harness registers them in a WP-CLI request, which is why the fields it reads back are the ones it just registered — and why the published option is deleted at the end.'
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
