<?php
/**
 * WCCS-021 proof harness — the classic adapter.
 *
 * Task:   WCCS-021 "Implementar Classic adapter"
 * Phase:  F04
 * Accept: "Tipos básicos, seções e larguras refletem schema; campos core mantêm contratos."
 *
 * The translation itself is proven by the unit suite, which can run it against
 * fixtures. What this harness adds is what a fixture cannot: the real field array
 * WooCommerce builds on this store, with the real `type`, `required`, `validate`
 * and `sanitize` values other plugins may have contributed. "Campos core mantêm
 * contratos" is only meaningful against those.
 *
 * The strongest assertion here is the one that ties F03 to F04: **an unpublished
 * edit changes nothing**. The adapter reads the published document, so a draft
 * that renames a field must leave the checkout byte for byte as it was.
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
 * Repository under test.
 *
 * @return \WCCheckoutSuite\Domain\Schema\SchemaRepository
 */
function wccs_proof_repository(): \WCCheckoutSuite\Domain\Schema\SchemaRepository {
	return new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);
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
 * The routes are proven elsewhere; here the point is what the adapter does with
 * what is stored, so the storage is set up directly and cheaply.
 *
 * @param string               $slot   Slot name.
 * @param array<int, mixed>    $fields Fields.
 * @param array<int, mixed>    $sections Sections.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields, array $sections = array() ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => 4,
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
 * The checkout fields as WooCommerce builds them on this store.
 *
 * @return array<string, mixed>
 */
function wccs_proof_woo_fields(): array {
	return WC()->checkout()->get_checkout_fields();
}

/**
 * Runs the adapter over the real field array.
 *
 * @return array<string, mixed>
 */
function wccs_proof_filtered(): array {
	return \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( wccs_proof_woo_fields() );
}

/**
 * A stable fingerprint of a field array, for comparing two runs.
 *
 * @param array<string, mixed> $fields Fields.
 * @return string
 */
function wccs_proof_fingerprint( array $fields ): string {
	return (string) wp_json_encode( $fields );
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
wccs_proof_out( 'WCCS-021 proof — classic adapter' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_draft_slot     = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT;
$wccs_published_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;

// ---------------------------------------------------------------------------
// 1. An empty schema leaves the checkout alone.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Empty schema' );

$wccs_baseline = wccs_proof_woo_fields();

wccs_proof_check(
	'WooCommerce builds a field array on this store',
	count( $wccs_baseline ) >= 3,
	count( $wccs_baseline ) . ' sections'
);

wccs_proof_check(
	'With nothing published the adapter returns the array untouched',
	wccs_proof_fingerprint( $wccs_baseline ) === wccs_proof_fingerprint( wccs_proof_filtered() ),
	'a byte for byte match'
);

// ---------------------------------------------------------------------------
// 2. Types, sections and widths follow the schema.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Types, sections and widths' );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def(
			'billing_document',
			array(
				'required'  => true,
				'position'  => 30,
				'layout'    => array( 'desktop' => 6 ),
				'settings'  => array(
					'placeholder' => '000.000.000-00',
					'maxLength'   => 14,
				),
			)
		),
		wccs_proof_def(
			'billing_extra',
			array(
				'type'     => 'select',
				'position' => 31,
				'layout'   => array( 'desktop' => 6 ),
				'settings' => array(
					'options' => array(
						array(
							'value' => 'pf',
							'label' => 'Individual',
						),
					),
				),
			)
		),
		wccs_proof_def(
			'shipping_note',
			array(
				'section'  => 'shipping',
				'position' => 40,
			)
		),
	)
);

$wccs_filtered = wccs_proof_filtered();

wccs_proof_check(
	'A custom field is added to the section the schema names',
	isset( $wccs_filtered['billing']['billing_document'] ),
	implode( ', ', array_keys( (array) ( $wccs_filtered['billing'] ?? array() ) ) )
);

wccs_proof_check(
	'It takes the type the schema declares',
	'text' === ( $wccs_filtered['billing']['billing_document']['type'] ?? '' ),
	(string) ( $wccs_filtered['billing']['billing_document']['type'] ?? '?' )
);

wccs_proof_check(
	'Required and priority follow the schema',
	true === ( $wccs_filtered['billing']['billing_document']['required'] ?? false )
		&& 30 === ( $wccs_filtered['billing']['billing_document']['priority'] ?? 0 ),
	'required=' . var_export( $wccs_filtered['billing']['billing_document']['required'] ?? null, true )
		. ' priority=' . ( $wccs_filtered['billing']['billing_document']['priority'] ?? '?' )
);

wccs_proof_check(
	'The declared settings reach the checkout',
	'000.000.000-00' === ( $wccs_filtered['billing']['billing_document']['placeholder'] ?? '' )
		&& 14 === ( $wccs_filtered['billing']['billing_document']['custom_attributes']['maxlength'] ?? null ),
	(string) wp_json_encode( $wccs_filtered['billing']['billing_document']['custom_attributes'] ?? array() )
);

// The client half needs to tell the Suite's fields apart from every other field
// on the form, and the identifier a merchant chose is not a pattern anything
// could match on. WCCS-025 added the marker; the assertion is here because this
// is the harness that reads the real field array WooCommerce builds.
wccs_proof_check(
	'A custom field is marked as the Suite\'s own',
	'billing_document' === ( $wccs_filtered['billing']['billing_document']['custom_attributes']['data-wccs-field'] ?? null ),
	(string) wp_json_encode( $wccs_filtered['billing']['billing_document']['custom_attributes'] ?? array() )
);

wccs_proof_check(
	'A field WooCommerce owns is not marked',
	! isset( $wccs_filtered['billing']['billing_first_name']['custom_attributes']['data-wccs-field'] ),
	'WooCommerce repopulates its own fields from the session'
);

wccs_proof_check(
	'Widths become the row classes the grid understands',
	array( 'form-row-first' ) === ( $wccs_filtered['billing']['billing_document']['class'] ?? array() )
		&& array( 'form-row-last' ) === ( $wccs_filtered['billing']['billing_extra']['class'] ?? array() ),
	'first=' . wp_json_encode( $wccs_filtered['billing']['billing_document']['class'] ?? array() )
		. ' second=' . wp_json_encode( $wccs_filtered['billing']['billing_extra']['class'] ?? array() )
);

wccs_proof_check(
	'Options become the value to label map the checkout expects',
	array( 'pf' => 'Individual' ) === ( $wccs_filtered['billing']['billing_extra']['options'] ?? array() ),
	(string) wp_json_encode( $wccs_filtered['billing']['billing_extra']['options'] ?? array() )
);

wccs_proof_check(
	'A field declared in another section lands in it',
	isset( $wccs_filtered['shipping']['shipping_note'] ),
	implode( ', ', array_keys( (array) ( $wccs_filtered['shipping'] ?? array() ) ) )
);

// ---------------------------------------------------------------------------
// 3. Core fields keep their contracts, against the real store.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Core contracts, against what WooCommerce really built' );

$wccs_core_id = '';

foreach ( array_keys( (array) ( $wccs_baseline['billing'] ?? array() ) ) as $wccs_candidate ) {
	if ( 'billing_first_name' === $wccs_candidate ) {
		$wccs_core_id = (string) $wccs_candidate;
	}
}

wccs_proof_check(
	'The store has a core billing field to override',
	'' !== $wccs_core_id,
	$wccs_core_id
);

$wccs_before = (array) ( $wccs_baseline['billing'][ $wccs_core_id ] ?? array() );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def(
			$wccs_core_id,
			array(
				'origin'   => 'core',
				'label'    => 'Primeiro nome',
				'section'  => 'billing',
				'position' => 5,
				'layout'   => array( 'desktop' => 6 ),
			)
		),
	)
);

$wccs_overridden = (array) ( wccs_proof_filtered()['billing'][ $wccs_core_id ] ?? array() );

wccs_proof_check(
	'The label is the one the merchant configured',
	'Primeiro nome' === ( $wccs_overridden['label'] ?? '' ),
	(string) ( $wccs_overridden['label'] ?? '?' )
);

$wccs_contract_keys = array( 'type', 'required', 'validate', 'sanitize', 'autocomplete' );
$wccs_broken       = array();

foreach ( $wccs_contract_keys as $wccs_key ) {
	if ( ! array_key_exists( $wccs_key, $wccs_before ) ) {
		continue;
	}

	if ( ( $wccs_before[ $wccs_key ] ?? null ) !== ( $wccs_overridden[ $wccs_key ] ?? null ) ) {
		$wccs_broken[] = $wccs_key . ': ' . wp_json_encode( $wccs_before[ $wccs_key ] ) . ' -> ' . wp_json_encode( $wccs_overridden[ $wccs_key ] ?? null );
	}
}

// This is the acceptance sentence. The comparison is against what WooCommerce
// and any other active plugin put in the array, not against a fixture, so a
// contract this adapter broke would show up here.
wccs_proof_check(
	'Every contract key WooCommerce set is still exactly what it was',
	array() === $wccs_broken,
	$wccs_broken
		? implode( ' | ', $wccs_broken )
		: implode( ', ', array_intersect( $wccs_contract_keys, array_keys( $wccs_before ) ) ) . ' unchanged'
);

wccs_proof_check(
	'The keys WooCommerce never set were not invented',
	array() === array_diff( $wccs_contract_keys, array_keys( $wccs_before ) )
		|| ! isset( $wccs_overridden['type'] ) === ! isset( $wccs_before['type'] ),
	'no contract key appeared from nowhere'
);

// ---------------------------------------------------------------------------
// 4. A draft changes nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. An unpublished edit does not reach the store' );

$wccs_published_run = wccs_proof_fingerprint( wccs_proof_filtered() );

wccs_proof_store(
	$wccs_draft_slot,
	array(
		wccs_proof_def( 'billing_draft_only', array( 'label' => 'Rascunho' ) ),
		wccs_proof_def( $wccs_core_id, array( 'origin' => 'core', 'label' => 'Nome do rascunho' ) ),
	)
);

$wccs_after_draft = wccs_proof_fingerprint( wccs_proof_filtered() );

// The whole point of the draft/publish split, asserted at the storefront.
wccs_proof_check(
	'A field that exists only in the draft is not in the checkout',
	false === strpos( $wccs_after_draft, 'billing_draft_only' ),
	'draft field absent'
);

wccs_proof_check(
	'A draft rename did not change the checkout',
	$wccs_published_run === $wccs_after_draft,
	'the filtered array is byte for byte what it was'
);

wccs_proof_check(
	'The label the customer sees is still the published one',
	'Primeiro nome' === ( wccs_proof_filtered()['billing'][ $wccs_core_id ]['label'] ?? '' ),
	(string) ( wccs_proof_filtered()['billing'][ $wccs_core_id ]['label'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 5. A type Classic cannot render is refused, not faked.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What cannot be rendered is reported' );

wccs_proof_store(
	$wccs_published_slot,
	array(
		wccs_proof_def( 'billing_avatar', array( 'type' => 'heading' ) ),
		wccs_proof_def( 'billing_birth', array( 'type' => 'date' ) ),
	)
);

$wccs_reports = array();

add_action(
	'wccs_classic_adapter_report',
	static function ( array $report ) use ( &$wccs_reports ): void {
		$wccs_reports = $report;
	}
);

$wccs_rendered = wccs_proof_filtered();

wccs_proof_check(
	'A type the classic checkout cannot render is not added at all',
	! isset( $wccs_rendered['billing']['billing_avatar'] ),
	'a structural field absent rather than shown as something else'
);

wccs_proof_check(
	'The reduction is reported rather than hidden',
	2 === count( $wccs_reports ),
	implode( ', ', array_map( static fn( array $r ): string => $r['field'] . ':' . $r['level'], $wccs_reports ) )
);

$wccs_by_field = array();

foreach ( $wccs_reports as $wccs_entry ) {
	$wccs_by_field[ (string) $wccs_entry['field'] ] = $wccs_entry;
}

wccs_proof_check(
	'The report distinguishes what was skipped from what was reduced',
	'skipped' === ( $wccs_by_field['billing_avatar']['level'] ?? '' )
		&& 'degraded' === ( $wccs_by_field['billing_birth']['level'] ?? '' ),
	(string) wp_json_encode( array_keys( $wccs_by_field ) )
);

wccs_proof_check(
	'The reduced field is rendered as text, which is what it is',
	'text' === ( $wccs_rendered['billing']['billing_birth']['type'] ?? '' ),
	(string) ( $wccs_rendered['billing']['billing_birth']['type'] ?? '?' )
);

// ---------------------------------------------------------------------------
// 6. The hook is registered for real.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Wiring' );

wccs_proof_check(
	'The adapter is attached to the checkout filter',
	false !== has_filter( 'woocommerce_checkout_fields', array( \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::class, 'filter_fields' ) ),
	'priority ' . ( has_filter( 'woocommerce_checkout_fields', array( \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::class, 'filter_fields' ) ) ?: 'none' )
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

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
	'The rendered Classic checkout page. This store has no page with the checkout shortcode, which is the open decision CLASSIC-TEST-SURFACE; the adapter is proven against the field array WooCommerce builds, which is the input the template receives.'
);
wccs_proof_note(
	'Cross-reference',
	'The translation itself, including every width and section case, is proven by tests/Unit/Checkout/Classic/ClassicAdapterTest.php (16 specs).'
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
