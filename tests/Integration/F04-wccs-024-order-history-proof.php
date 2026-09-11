<?php
/**
 * WCCS-024 proof harness — order value history.
 *
 * Task:   WCCS-024 "Implementar histórico de valores"
 * Phase:  F04
 * Accept: "Renomear/arquivar campo não torna pedido antigo ilegível."
 *
 * The proof places an order, then moves the schema underneath it three separate
 * ways — renaming every label, changing what an option means, retyping a field,
 * archiving a field and finally removing one altogether — and reads the order
 * again after each. The order is never written to again.
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
 * The options of a select, as declared.
 *
 * @param array<string, string> $options Value to label.
 * @return array<int, array<string, string>>
 */
function wccs_proof_options( array $options ): array {
	$declared = array();

	foreach ( $options as $value => $label ) {
		$declared[] = array(
			'value' => (string) $value,
			'label' => (string) $label,
		);
	}

	return $declared;
}

/**
 * Writes a document straight into a slot, bypassing the routes.
 *
 * @param string            $slot     Slot name.
 * @param array<int, mixed> $fields   Fields.
 * @param int               $revision Revision number.
 * @return void
 */
function wccs_proof_store( string $slot, array $fields, int $revision ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $slot ),
		(string) wp_json_encode(
			array(
				'revision'       => $revision,
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
 * The published definitions, as the storefront sees them.
 *
 * @return array<int, array<string, mixed>>
 */
function wccs_proof_published(): array {
	return \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();
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
 * Builds posted data from the current `$_POST` and the current form.
 *
 * @return array<string, mixed>
 */
function wccs_proof_posted(): array {
	$checkout = new WC_Checkout();

	return $checkout->get_posted_data();
}

/**
 * Places an order the way the checkout does, and returns it.
 *
 * @param array<string, mixed> $posted Posted values.
 * @return WC_Order|WP_Error
 */
function wccs_proof_place_order( array $posted ) {
	$order = wc_create_order( array( 'status' => 'pending' ) );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$order->set_billing_first_name( (string) ( $posted['billing_first_name'] ?? '' ) );

	do_action( 'woocommerce_checkout_create_order', $order, $posted );
	$order->save();

	return $order;
}

/**
 * Reads a stored value as it should be read.
 *
 * @param \WCCheckoutSuite\Domain\Orders\OrderFieldsService $service     Service.
 * @param WC_Order                                          $order       Order.
 * @param array<int, array<string, mixed>>                  $definitions Published definitions.
 * @return array<string, \WCCheckoutSuite\Domain\Orders\OrderFieldEntry>
 */
function wccs_proof_history( $service, WC_Order $order, array $definitions ): array {
	$by_id = array();

	foreach ( $service->history( $order, $definitions ) as $entry ) {
		$by_id[ $entry->id() ] = $entry;
	}

	return $by_id;
}

/**
 * A short description of one entry.
 *
 * @param \WCCheckoutSuite\Domain\Orders\OrderFieldEntry|null $entry Entry.
 * @return string
 */
function wccs_proof_entry( $entry ): string {
	if ( null === $entry ) {
		return '(no entry)';
	}

	return sprintf(
		'label=%s type=%s source=%s current=%s value=%s options=%s',
		$entry->label(),
		'' === $entry->type() ? '(unknown)' : $entry->type(),
		$entry->source(),
		$entry->is_current() ? 'yes' : 'no',
		wp_json_encode( $entry->value() ),
		wp_json_encode( $entry->options() )
	);
}

/**
 * The plugin's own meta rows on an order.
 *
 * @param int $id Order id.
 * @return int
 */
function wccs_proof_rows_by_key( int $id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that reading did not write.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key IN ('_wccs_fields','_wccs_schema_revision')",
			$id
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

/**
 * Rows in a table for one order id.
 *
 * @param string $table  Table name without prefix.
 * @param string $column Column holding the order id.
 * @param int    $id     Order id.
 * @return int
 */
function wccs_proof_rows( string $table, string $column, int $id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the store under test wrote where it claims to.
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", $id )
	);
}

$wccs_options_before = wccs_proof_option_count();
$wccs_order_ids      = array();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-024 proof — order value history' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_draft_slot     = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT;
$wccs_published_slot = \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED;
$wccs_service        = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );

// ---------------------------------------------------------------------------
// 1. The order is placed with the schema as it was.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. An order placed against the schema of the day' );

$wccs_original = array(
	wccs_proof_def(
		'wccs_size',
		array(
			'label'    => 'Size',
			'type'     => 'select',
			'settings' => array( 'options' => wccs_proof_options( array( 's' => 'Small', 'm' => 'Medium' ) ) ),
		)
	),
	wccs_proof_def(
		'wccs_cpf',
		array(
			'label'      => 'CPF',
			'type'       => 'text',
			'normalizer' => 'digits',
		)
	),
	wccs_proof_def( 'wccs_consent', array( 'label' => 'Consent', 'type' => 'checkbox' ) ),
);

wccs_proof_store( $wccs_published_slot, $wccs_original, 4 );

$_POST = array(
	'woocommerce-process-checkout-nonce' => 'forged-by-the-harness',
	'payment_method'                     => 'bacs',
	'billing_first_name'                 => 'Ana',
	'billing_last_name'                  => 'Silva',
	'billing_country'                    => 'BR',
	'billing_email'                      => 'ana@example.test',
	'wccs_size'                          => 'm',
	'wccs_cpf'                           => '123.456.789-09',
	'wccs_consent'                       => '1',
);

$wccs_posted = wccs_proof_posted();
$wccs_order  = wccs_proof_place_order( $wccs_posted );

if ( is_wp_error( $wccs_order ) ) {
	wccs_proof_check( 'Order placed', false, $wccs_order->get_error_message() );
} else {
	$wccs_order_id    = $wccs_order->get_id();
	$wccs_order_ids[] = $wccs_order_id;

	$wccs_payload = json_decode( (string) $wccs_order->get_meta( '_wccs_fields', true, 'edit' ), true );
	$wccs_snap    = $wccs_payload['snapshot'] ?? null;

	wccs_proof_check(
		'The payload carries a snapshot beside the values',
		is_array( $wccs_snap )
			&& isset( $wccs_snap['wccs_size'], $wccs_snap['wccs_cpf'], $wccs_snap['wccs_consent'] ),
		'keys=' . wp_json_encode( is_array( $wccs_snap ) ? array_keys( $wccs_snap ) : null )
	);

	wccs_proof_check(
		'The snapshot records the label and type the field had',
		'Size' === ( $wccs_snap['wccs_size']['label'] ?? null )
			&& 'select' === ( $wccs_snap['wccs_size']['type'] ?? null )
			&& 'CPF' === ( $wccs_snap['wccs_cpf']['label'] ?? null )
			&& 'text' === ( $wccs_snap['wccs_cpf']['type'] ?? null ),
		'size=' . wp_json_encode( $wccs_snap['wccs_size'] ?? null )
	);

	wccs_proof_check(
		'The snapshot records what an option key meant',
		'Medium' === ( $wccs_snap['wccs_size']['options']['m'] ?? null )
			&& 'Small' === ( $wccs_snap['wccs_size']['options']['s'] ?? null ),
		'options=' . wp_json_encode( $wccs_snap['wccs_size']['options'] ?? null )
	);

	wccs_proof_check(
		'Nothing that only affects future behaviour is recorded',
		! isset( $wccs_snap['wccs_size']['required'], $wccs_snap['wccs_size']['section'], $wccs_snap['wccs_size']['layout'] ),
		'keys per entry: ' . wp_json_encode( is_array( $wccs_snap['wccs_size'] ?? null ) ? array_keys( $wccs_snap['wccs_size'] ) : null )
	);

	$wccs_stored_before = (string) wc_get_order( $wccs_order_id )->get_meta( '_wccs_fields', true, 'edit' );
}

// ---------------------------------------------------------------------------
// 2. The schema moves on: every label renamed, an option relabelled, a retype.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Renamed, relabelled and retyped' );

$wccs_renamed = array(
	wccs_proof_def(
		'wccs_size',
		array(
			'label'    => 'Tamanho',
			'type'     => 'select',
			'settings' => array( 'options' => wccs_proof_options( array( 'p' => 'Pequeno', 'm' => 'Médio' ) ) ),
		)
	),
	wccs_proof_def(
		'wccs_cpf',
		array(
			'label' => 'Documento',
			'type'  => 'number',
		)
	),
	wccs_proof_def( 'wccs_consent', array( 'label' => 'Consentimento', 'type' => 'checkbox' ) ),
);

wccs_proof_store( $wccs_published_slot, $wccs_renamed, 5 );

if ( isset( $wccs_order_id ) ) {
	$wccs_after_rename = wccs_proof_history( $wccs_service, wc_get_order( $wccs_order_id ), wccs_proof_published() );

	wccs_proof_check(
		'A renamed field reads with the label it had when the order was placed',
		( $wccs_after_rename['wccs_size'] ?? null ) instanceof \WCCheckoutSuite\Domain\Orders\OrderFieldEntry
			&& 'Size' === $wccs_after_rename['wccs_size']->label()
			&& 'Consent' === ( $wccs_after_rename['wccs_consent']->label() ?? null ),
		wccs_proof_entry( $wccs_after_rename['wccs_size'] ?? null )
	);

	wccs_proof_check(
		'And it is not flagged as stale: only the label moved',
		true === ( $wccs_after_rename['wccs_size']->is_current() ?? null )
			&& \WCCheckoutSuite\Domain\Orders\OrderFieldEntry::SOURCE_SNAPSHOT === ( $wccs_after_rename['wccs_size']->source() ?? null ),
		'current=' . var_export( $wccs_after_rename['wccs_size']->is_current() ?? null, true )
	);

	wccs_proof_check(
		'The option the customer chose still reads with the label it had',
		'Medium' === ( $wccs_after_rename['wccs_size']->options()['m'] ?? null ),
		'options=' . wp_json_encode( $wccs_after_rename['wccs_size']->options() ?? null )
	);

	wccs_proof_check(
		'A retyped field keeps the type it was captured with',
		'text' === ( $wccs_after_rename['wccs_cpf']->type() ?? null ),
		wccs_proof_entry( $wccs_after_rename['wccs_cpf'] ?? null )
	);

	wccs_proof_check(
		'And the retype is reported rather than papered over',
		false === ( $wccs_after_rename['wccs_cpf']->is_current() ?? null ),
		'the schema now says number; the value was captured as text and is not reformatted'
	);

	wccs_proof_check(
		'The stored value itself is untouched by any of it',
		'12345678909' === ( $wccs_after_rename['wccs_cpf']->value() ?? null ),
		'value=' . wp_json_encode( $wccs_after_rename['wccs_cpf']->value() ?? null )
	);

	wccs_proof_check(
		'Publishing a schema that renames everything rewrote no order',
		$wccs_stored_before === (string) wc_get_order( $wccs_order_id )->get_meta( '_wccs_fields', true, 'edit' ),
		'the payload is byte for byte what it was before the schema moved'
	);
}

// ---------------------------------------------------------------------------
// 3. A field is archived.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A field archived out of the checkout' );

$wccs_archived = array(
	wccs_proof_def(
		'wccs_size',
		array(
			'label'    => 'Tamanho',
			'type'     => 'select',
			'settings' => array( 'options' => wccs_proof_options( array( 'p' => 'Pequeno', 'm' => 'Médio' ) ) ),
		)
	),
	wccs_proof_def(
		'wccs_cpf',
		array(
			'label' => 'Documento',
			'type'  => 'number',
		)
	),
	wccs_proof_def(
		'wccs_consent',
		array(
			'label'   => 'Consentimento',
			'type'    => 'checkbox',
			'enabled' => false,
		)
	),
);

wccs_proof_store( $wccs_published_slot, $wccs_archived, 6 );

if ( isset( $wccs_order_id ) ) {
	$_POST = array(
		'woocommerce-process-checkout-nonce' => 'forged-by-the-harness',
		'payment_method'                     => 'bacs',
		'billing_first_name'                 => 'Ana',
		'billing_last_name'                  => 'Silva',
		'billing_country'                    => 'BR',
		'billing_email'                      => 'ana@example.test',
		'wccs_size'                          => 'p',
		'wccs_cpf'                           => '999',
		'wccs_consent'                       => '1',
	);

	$wccs_form = wccs_proof_form();

	wccs_proof_check(
		'An archived field is off the checkout',
		! isset( $wccs_form['billing']['wccs_consent'] )
			&& isset( $wccs_form['billing']['wccs_size'] ),
		'billing fields=' . wp_json_encode( array_keys( (array) $wccs_form['billing'] ) )
	);

	$wccs_after_archive = wccs_proof_history( $wccs_service, wc_get_order( $wccs_order_id ), wccs_proof_published() );

	wccs_proof_check(
		'And the old order still reads the value it collected',
		true === ( $wccs_after_archive['wccs_consent']->value() ?? null ),
		wccs_proof_entry( $wccs_after_archive['wccs_consent'] ?? null )
	);

	wccs_proof_check(
		'With the label it had, not the one the archive carried',
		'Consent' === ( $wccs_after_archive['wccs_consent']->label() ?? null ),
		'label=' . (string) ( $wccs_after_archive['wccs_consent']->label() ?? '' )
	);

	wccs_proof_check(
		'The values API still returns it too',
		wc_get_order( $wccs_order_id ) instanceof WC_Order
			&& $wccs_service->read( wc_get_order( $wccs_order_id ) )->has( 'wccs_consent' ),
		'archiving changes what is rendered, not what was stored'
	);

	// A new order placed now must not collect the archived field at all.
	$wccs_new_order = wccs_proof_place_order( wccs_proof_posted() );

	if ( is_wp_error( $wccs_new_order ) ) {
		wccs_proof_check( 'Second order placed', false, $wccs_new_order->get_error_message() );
	} else {
		$wccs_order_ids[] = $wccs_new_order->get_id();

		wccs_proof_check(
			'A new order does not collect the archived field',
			! $wccs_service->read( $wccs_new_order )->has( 'wccs_consent' ),
			'stored=' . wp_json_encode( $wccs_service->read( $wccs_new_order )->ids() )
		);
	}
}

// ---------------------------------------------------------------------------
// 4. A field is removed from the schema entirely.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. A field removed from the schema' );

$wccs_removed = array(
	wccs_proof_def(
		'wccs_cpf',
		array(
			'label' => 'Documento',
			'type'  => 'number',
		)
	),
	wccs_proof_def(
		'wccs_consent',
		array(
			'label'   => 'Consentimento',
			'type'    => 'checkbox',
			'enabled' => false,
		)
	),
);

wccs_proof_store( $wccs_published_slot, $wccs_removed, 7 );

if ( isset( $wccs_order_id ) ) {
	$wccs_after_removal = wccs_proof_history( $wccs_service, wc_get_order( $wccs_order_id ), wccs_proof_published() );

	wccs_proof_check(
		'A field that no longer exists anywhere still reads',
		( $wccs_after_removal['wccs_size'] ?? null ) instanceof \WCCheckoutSuite\Domain\Orders\OrderFieldEntry,
		wccs_proof_entry( $wccs_after_removal['wccs_size'] ?? null )
	);

	wccs_proof_check(
		'With the label it had',
		'Size' === ( $wccs_after_removal['wccs_size']->label() ?? null )
			&& \WCCheckoutSuite\Domain\Orders\OrderFieldEntry::SOURCE_SNAPSHOT === ( $wccs_after_removal['wccs_size']->source() ?? null ),
		'label=' . (string) ( $wccs_after_removal['wccs_size']->label() ?? '' )
	);

	wccs_proof_check(
		'And the option it stored still reads as Medium',
		'Medium' === ( $wccs_after_removal['wccs_size']->options()['m'] ?? null )
			&& 'm' === ( $wccs_after_removal['wccs_size']->value() ?? null ),
		'value=' . wp_json_encode( $wccs_after_removal['wccs_size']->value() ?? null )
	);

	wccs_proof_check(
		'The removal is reported rather than hidden',
		false === ( $wccs_after_removal['wccs_size']->is_current() ?? null )
			&& 'select' === ( $wccs_after_removal['wccs_size']->type() ?? null ),
		'the type still comes from the snapshot, so a reader does not format it as text by default'
	);

	wccs_proof_check(
		'No value was dropped by any of the three schema moves',
		array( 'wccs_size', 'wccs_cpf', 'wccs_consent' ) === array_keys( $wccs_after_removal ),
		'entries=' . wp_json_encode( array_keys( $wccs_after_removal ) )
	);
}

// ---------------------------------------------------------------------------
// 5. An order with no snapshot degrades honestly.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. An order that remembers its values but not their names' );

$wccs_legacy_order = wc_create_order( array( 'status' => 'pending' ) );

if ( is_wp_error( $wccs_legacy_order ) ) {
	wccs_proof_check( 'Order created for the format-1 section', false, $wccs_legacy_order->get_error_message() );
} else {
	$wccs_legacy_id   = $wccs_legacy_order->get_id();
	$wccs_order_ids[] = $wccs_legacy_id;

	// A format-1 payload: values only, which is what this plugin wrote before the
	// snapshot existed.
	$wccs_legacy_order->update_meta_data( '_wccs_fields', '{ "format": 1, "values": { "wccs_ghost": "kept", "wccs_cpf": "42" } }' );
	$wccs_legacy_order->save();

	$wccs_legacy_fresh = wc_get_order( $wccs_legacy_id );

	wccs_proof_check(
		'A payload from the previous format is still readable',
		'readable' === $wccs_service->read_status( $wccs_legacy_fresh )['state'],
		'state=' . $wccs_service->read_status( $wccs_legacy_fresh )['state']
	);

	$wccs_legacy_history = wccs_proof_history( $wccs_service, $wccs_legacy_fresh, wccs_proof_published() );

	wccs_proof_check(
		'Its values are all there',
		array( 'wccs_ghost', 'wccs_cpf' ) === array_keys( $wccs_legacy_history ),
		'entries=' . wp_json_encode( array_keys( $wccs_legacy_history ) )
	);

	wccs_proof_check(
		'A field the current schema knows reads from the schema',
		\WCCheckoutSuite\Domain\Orders\OrderFieldEntry::SOURCE_SCHEMA === ( $wccs_legacy_history['wccs_cpf']->source() ?? null )
			&& 'Documento' === ( $wccs_legacy_history['wccs_cpf']->label() ?? null )
			&& 'number' === ( $wccs_legacy_history['wccs_cpf']->type() ?? null ),
		wccs_proof_entry( $wccs_legacy_history['wccs_cpf'] ?? null )
	);

	wccs_proof_check(
		'A value nothing can name is returned, not hidden',
		\WCCheckoutSuite\Domain\Orders\OrderFieldEntry::SOURCE_UNLABELLED === ( $wccs_legacy_history['wccs_ghost']->source() ?? null )
			&& 'wccs_ghost' === ( $wccs_legacy_history['wccs_ghost']->label() ?? null )
			&& 'kept' === ( $wccs_legacy_history['wccs_ghost']->value() ?? null ),
		wccs_proof_entry( $wccs_legacy_history['wccs_ghost'] ?? null )
	);

	wccs_proof_check(
		'And it says the type is unknown rather than guessing text',
		'' === ( $wccs_legacy_history['wccs_ghost']->type() ?? null )
			&& false === ( $wccs_legacy_history['wccs_ghost']->is_current() ?? null ),
		'type=' . var_export( $wccs_legacy_history['wccs_ghost']->type() ?? null, true )
	);

	wccs_proof_check(
		'History can be read with no schema at all',
		array( 'wccs_ghost', 'wccs_cpf' ) === array_keys( wccs_proof_history( $wccs_service, $wccs_legacy_fresh, array() ) ),
		'a caller that only wants history passes no definitions'
	);
}

// ---------------------------------------------------------------------------
// 6. Nothing was rewritten on the way.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. The order was read five times and written once' );

if ( isset( $wccs_order_id, $wccs_stored_before ) ) {
	wccs_proof_check(
		'Re-reading the order did not add or change a single meta row',
		$wccs_stored_before === (string) wc_get_order( $wccs_order_id )->get_meta( '_wccs_fields', true, 'edit' )
			&& 2 === wccs_proof_rows_by_key( $wccs_order_id ),
		'wccs meta rows=' . wccs_proof_rows_by_key( $wccs_order_id )
	);
}

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

$_POST = array();

foreach ( $wccs_order_ids as $wccs_cleanup_id ) {
	$wccs_cleanup_order = wc_get_order( $wccs_cleanup_id );

	if ( $wccs_cleanup_order instanceof WC_Order ) {
		$wccs_cleanup_order->delete( true );
	}
}

$wccs_residue = array();

foreach ( $wccs_order_ids as $wccs_cleanup_id ) {
	$wccs_residue[ $wccs_cleanup_id ] = wccs_proof_rows( 'wc_orders', 'id', $wccs_cleanup_id )
		+ wccs_proof_rows( 'wc_orders_meta', 'order_id', $wccs_cleanup_id )
		+ wccs_proof_rows( 'postmeta', 'post_id', $wccs_cleanup_id );
}

wccs_proof_check(
	'Every order this harness created was removed from every store',
	array() === array_filter( $wccs_residue ),
	'orders=' . count( $wccs_order_ids ) . ' residue=' . wp_json_encode( $wccs_residue )
);

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_draft_slot ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_published_slot ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'What "archived" means here',
	'Archiving is enabled=false in the published document, which is the model F03 established: the definition stays, the field stops being rendered, and CoreFieldGuard refuses it for a field WooCommerce owns. A custom field can also be removed from the document outright, which is the case section 4 covers.'
);

wccs_proof_note(
	'Not exercised here',
	'A migration that rewrites historical values. Section 13 requires dry-run, backup, a report and explicit confirmation for that, and nothing in this plugin rewrites an order: the proof asserts the stored payload is byte for byte unchanged after every schema move.'
);

wccs_proof_note(
	'Cross-reference',
	'The snapshot and its rebuild from storage are covered by tests/Unit/Domain/Orders/OrderFieldSnapshotTest.php (12 specs), and the shared option map by tests/Unit/Domain/Fields/FieldDefinitionTest.php (6 specs).'
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
