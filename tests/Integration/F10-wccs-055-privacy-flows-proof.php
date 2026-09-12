<?php
/**
 * WCCS-055 proof harness — the data-subject flows and the policy.
 *
 * Task:   WCCS-055 "Implementar exportação/eliminação de dados"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Fluxos por titular e política da loja testados, com retenção explicável."
 *
 * The flows are exercised through WordPress's own privacy API rather than by calling the
 * callbacks directly, because what is being asserted is that the store's existing tools
 * answer for this plugin — an exporter registered on a filter nobody reads is an exporter
 * that does not exist for the person asking.
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
 * A field definition.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
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
			'validators'     => array(),
		),
		$changes
	);
}

/**
 * Publishes a document.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 55,
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
 * An order for one address.
 *
 * @param string                           $email       Billing address.
 * @param array<string, mixed>             $values      Values.
 * @param array<int, array<string, mixed>> $definitions Definitions.
 * @param int                              $customer_id Customer, when there is one.
 * @return WC_Order
 */
function wccs_proof_order( string $email, array $values, array $definitions, int $customer_id = 0 ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'completed' );
	$order->set_billing_email( $email );

	if ( $customer_id > 0 ) {
		$order->set_customer_id( $customer_id );
	}

	$order->save();

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $order, $values, $definitions, 55 );
	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * Runs one exporter registered on WordPress's filter, by its identifier.
 *
 * @param string $id    Exporter identifier.
 * @param string $email Address.
 * @return array<string, mixed>
 */
function wccs_proof_export( string $id, string $email ): array {
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

	if ( ! isset( $exporters[ $id ]['callback'] ) || ! is_callable( $exporters[ $id ]['callback'] ) ) {
		return array();
	}

	$result = call_user_func( $exporters[ $id ]['callback'], $email, 1 );

	return is_array( $result ) ? $result : array();
}

/**
 * Runs one eraser registered on WordPress's filter, by its identifier.
 *
 * @param string $id    Eraser identifier.
 * @param string $email Address.
 * @return array<string, mixed>
 */
function wccs_proof_erase( string $id, string $email ): array {
	$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

	if ( ! isset( $erasers[ $id ]['callback'] ) || ! is_callable( $erasers[ $id ]['callback'] ) ) {
		return array();
	}

	$result = call_user_func( $erasers[ $id ]['callback'], $email, 1 );

	return is_array( $result ) ? $result : array();
}

/**
 * Every value the export returned, as one string.
 *
 * @param array<string, mixed> $export Export result.
 * @return string
 */
function wccs_proof_export_text( array $export ): string {
	$text = '';

	foreach ( (array) ( $export['data'] ?? array() ) as $group ) {
		foreach ( (array) ( $group['data'] ?? array() ) as $item ) {
			$text .= ( $item['name'] ?? '' ) . ': ' . ( $item['value'] ?? '' ) . "\n";
		}
	}

	return $text;
}

$wccs_exporter = 'WCCheckoutSuite\\Privacy\\OrderFieldsExporter';
$wccs_eraser   = 'WCCheckoutSuite\\Privacy\\OrderFieldsEraser';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-055 proof — export, erasure and the policy' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. The store's own tools answer for this plugin.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The flows are where a person looks for them' );

$wccs_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$wccs_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

wccs_proof_check(
	'The exporter and the eraser are registered on WordPress\'s own filters',
	isset( $wccs_exporters[ $wccs_exporter::ID ]['callback'] )
		&& isset( $wccs_erasers[ $wccs_eraser::ID ]['callback'] ),
	'exporters=' . count( $wccs_exporters ) . ' erasers=' . count( $wccs_erasers )
);

wccs_proof_check(
	'And each names itself for the person reading the report',
	'' !== (string) ( $wccs_exporters[ $wccs_exporter::ID ]['exporter_friendly_name'] ?? '' )
		&& '' !== (string) ( $wccs_erasers[ $wccs_eraser::ID ]['eraser_friendly_name'] ?? '' )
);

wccs_proof_publish(
	array(
		wccs_proof_field( 'document', array( 'type' => 'text' ) ),
		wccs_proof_field(
			'consent',
			array(
				'storage' => array(
					'scope'       => 'order',
					'sensitivity' => 'public',
				),
			)
		),
		wccs_proof_field(
			'attachment',
			array(
				'type'    => 'file',
				'storage' => array(
					'scope'       => 'order',
					'sensitivity' => 'sensitive',
				),
			)
		),
	)
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_personal = $wccs_exporter::personal_fields( $wccs_definitions );

wccs_proof_check(
	'The vocabulary decides what is personal data, and the two flows read the same decision',
	array( 'wccs_document', 'wccs_attachment' ) === array_keys( $wccs_personal ),
	'a value the vocabulary calls "not personal" is nobody\'s data to request: ' . wp_json_encode( array_keys( $wccs_personal ) )
);

// ---------------------------------------------------------------------------
// 2. The export.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the person is given' );

$wccs_email = 'wccs_proof_subject_' . wp_rand( 1000, 9999 ) . '@example.test';

$wccs_user = wp_insert_user(
	array(
		'user_login' => 'wccs_proof_subject_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => $wccs_email,
		'role'       => 'customer',
	)
);

$wccs_token = str_repeat( 'b', 64 );

$wccs_guest_order = wccs_proof_order(
	$wccs_email,
	array(
		'wccs_document'   => '11144477735',
		'wccs_consent'    => 'yes',
		'wccs_attachment' => $wccs_token,
	),
	$wccs_definitions
);

// A second order, placed while logged in, with a different billing address: the person is
// one person and the orders are two, which is the case a search by address alone misses.
$wccs_account_order = wccs_proof_order(
	'other-' . $wccs_email,
	array( 'wccs_document' => '52998224725' ),
	$wccs_definitions,
	(int) $wccs_user
);

$wccs_export = wccs_proof_export( $wccs_exporter::ID, $wccs_email );
$wccs_exported = wccs_proof_export_text( $wccs_export );

wccs_proof_check(
	'The export finds the orders placed as a guest and the ones placed with the account',
	count( (array) ( $wccs_export['data'] ?? array() ) ) >= 2,
	'groups=' . count( (array) ( $wccs_export['data'] ?? array() ) )
);

wccs_proof_check(
	'And carries the personal values the store holds',
	str_contains( $wccs_exported, '11144477735' ) && str_contains( $wccs_exported, '52998224725' ),
	'exported=' . strlen( $wccs_exported ) . ' bytes'
);

wccs_proof_check(
	'And does not carry a value the vocabulary calls not personal',
	! str_contains( $wccs_exported, 'Label consent' ),
	'the export is the person\'s data, not a copy of the order'
);

wccs_proof_check(
	'And names a document without handing over its address',
	! str_contains( $wccs_exported, $wccs_token )
		&& str_contains( $wccs_exported, 'A document was provided with this order' ),
	'the token is an address inside the store, and it does not travel into a file the person keeps'
);

wccs_proof_check(
	'And the export is finished in one pass rather than paginated',
	true === ( $wccs_export['done'] ?? false )
);

wccs_proof_check(
	'And somebody else\'s address gets nothing',
	'' === wccs_proof_export_text( wccs_proof_export( $wccs_exporter::ID, 'nobody-' . $wccs_email ) )
);

// ---------------------------------------------------------------------------
// 3. The erasure.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. What is erased, and what is kept' );

$wccs_before_erase = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )
	->read( wc_get_order( $wccs_guest_order->get_id() ) );

wccs_proof_check(
	'The order carries the personal value before the request',
	$wccs_before_erase->has( 'wccs_document' )
);

$wccs_erasure = wccs_proof_erase( $wccs_eraser::ID, $wccs_email );

wccs_proof_check(
	'The erasure reports that it removed something',
	true === ( $wccs_erasure['items_removed'] ?? false ),
	'messages=' . count( (array) ( $wccs_erasure['messages'] ?? array() ) )
);

$wccs_after_erase = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )
	->read( wc_get_order( $wccs_guest_order->get_id() ) );

wccs_proof_check(
	'The personal value is gone from the order',
	! $wccs_after_erase->has( 'wccs_document' ),
	'state=' . ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read_status( wc_get_order( $wccs_guest_order->get_id() ) )['state']
);

wccs_proof_check(
	'And the order itself is still there, with what is not this plugin\'s to erase',
	wc_get_order( $wccs_guest_order->get_id() ) instanceof WC_Order
		&& true === ( $wccs_erasure['items_retained'] ?? false ),
	'the store\'s record of the purchase is not a value this plugin added'
);

wccs_proof_check(
	'And a value that is not personal data is left where it was',
	$wccs_after_erase->get( 'wccs_consent' ) === 'yes',
	'consent: ' . wp_json_encode( $wccs_after_erase->get( 'wccs_consent' ) )
);

$wccs_account_after = ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )
	->read( wc_get_order( $wccs_account_order->get_id() ) );

wccs_proof_check(
	'And the order placed with the account is erased too, though its address differs',
	! $wccs_account_after->has( 'wccs_document' ),
	'a search by address alone would have left this one behind'
);

// The explanation, which is the acceptance's third clause.
$wccs_messages = implode( ' ', array_map( 'strval', (array) ( $wccs_erasure['messages'] ?? array() ) ) );

wccs_proof_check(
	'The report says what was erased, in numbers',
	str_contains( $wccs_messages, 'checkout value' ),
	'messages=' . substr( $wccs_messages, 0, 120 )
);

wccs_proof_check(
	'And says what is kept and why, so the retention is explainable',
	str_contains( $wccs_messages, 'record of the purchase' )
		&& str_contains( $wccs_messages, 'upload retention' ),
	'the person is told the order stays and the document goes with it'
);

wccs_proof_check(
	'And a second request finds nothing left to remove',
	false === ( wccs_proof_erase( $wccs_eraser::ID, $wccs_email )['items_removed'] ?? true ),
	'running it twice is safe, which is what a person retrying the tool needs'
);

wp_delete_user( (int) $wccs_user );

// ---------------------------------------------------------------------------
// 4. The policy the store is offered.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Política da loja' );

$wccs_policy = 'WCCheckoutSuite\\Privacy\\PrivacyPolicy';
$wccs_text   = $wccs_policy::text();

wccs_proof_check(
	'The policy is registered with the store\'s own privacy tools',
	false !== has_action( 'admin_init', array( $wccs_policy, 'suggest' ) )
);

wccs_proof_check(
	'It states where a value is shown, from the vocabulary rather than from a list',
	str_contains( $wccs_text, 'Where a value is shown is a separate decision' )
		&& str_contains( $wccs_text, esc_html( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::destinations()[0]['description'] ) ),
	'bytes=' . strlen( $wccs_text )
);

wccs_proof_check(
	'And what each kind of value means, also from the vocabulary',
	str_contains( $wccs_text, 'The value identifies nobody on its own.' )
		&& str_contains( $wccs_text, 'needs extra care' )
);

wccs_proof_check(
	'And the retention, with the numbers the code actually uses',
	str_contains( $wccs_text, (string) (int) ( \WCCheckoutSuite\Domain\Uploads\UploadService::TTL / HOUR_IN_SECONDS ) )
		&& str_contains( $wccs_text, 'kept while that order exists' ),
	'a policy that quoted a different number would be the document nobody updates'
);

wccs_proof_check(
	'And it promises no conformity it cannot keep',
	str_contains( $wccs_text, 'not legal advice' )
		&& ! str_contains( strtolower( $wccs_text ), 'gdpr compliant' )
		&& ! str_contains( strtolower( $wccs_text ), 'lgpd compliant' ),
	'section 14: "não uma promessa genérica de conformidade"'
);

wccs_proof_note(
	'Why the two flows read the same definition of personal data',
	'A person who is told their data is one thing when they ask for a copy and another when they ask for it to be gone has been told two different things by one store. Both callbacks read the storage sensitivity vocabulary, so the set exported and the set erased cannot drift apart.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No request was made from the store\'s privacy screens: the callbacks are reached through the filters WordPress reads, which is the same path the tools take, but the screens themselves, the confirmation e-mail and the exported file are not exercised. Exercising them end to end is the phase\'s audit.'
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before
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
