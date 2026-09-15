<?php
/**
 * Fase 9 proof harness — a status is a state, and it does not pay anything.
 *
 * Task:   Fase 9 "Status personalizados"
 * Gate:   "status não altera pagamento sem workflow."
 *
 * The unit suite proves the model has nothing a gateway could read. What a unit test cannot show is
 * whether a real store, with a real order, keeps that promise — so this harness creates a status,
 * registers it, puts an order in it, and asks WooCommerce whether anything was paid. §12.7's user
 * test is walked in order, because it is the acceptance the section was written as:
 *
 * 1. create `Análise pendente`;
 * 2. show it to the customer;
 * 3. save it;
 * 4. create a test order;
 * 5. put the order in it;
 * 6. confirm WooCommerce does **not** consider the order paid;
 * 7. rename the label;
 * 8. confirm the order is still in the same internal status.
 *
 * Beside the user test, and because a promise is only worth what it refuses: an inactive status is
 * not registered at all, an identifier WooCommerce owns is refused, a pre-payment status is
 * removed from the paid list even when something else adds it, and the review state that predates
 * this screen is migrated with the identifier the orders are already recorded in.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce loaded.
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
 * The stored status list, as the repository reads it.
 *
 * @return array<int, array<string, mixed>>
 */
function wccs_proof_stored(): array {
	return ( new \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository() )->raw();
}

/**
 * Replaces the stored list through the repository, so it is validated.
 *
 * @param array<int, array<string, mixed>> $statuses Statuses.
 * @return \WCCheckoutSuite\Domain\Fields\ValidationResult
 */
function wccs_proof_save( array $statuses ) {
	return ( new \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository() )->save( $statuses );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 9 proof — a status is a state, and it does not pay anything' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. §12.7 steps 1 to 3: create it, show it to the customer, save it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Criar "Análise pendente", mostrar ao cliente, salvar' );

$wccs_proof_created = wccs_proof_save(
	array(
		array(
			// The screen sends the name and leaves the identifier empty: the store assigns it, so
			// the identity rule lives once — the table of accents, the length that fits beside
			// `wc-` and the numbering of a collision are all the server's.
			'id'             => '',
			'label'          => 'Análise pendente',
			'customer_label' => 'Estamos a analisar o seu pedido',
			'colour'         => '#FF59C0',
			'active'         => true,
			'show_customer'  => true,
			'show_emails'    => true,
			'manual'         => true,
			'prepayment'     => true,
			'description'    => 'Aguardando documentos do cliente para validação da compra.',
		),
	)
);

wccs_proof_check(
	'The store accepts the status a merchant configured',
	$wccs_proof_created->is_valid(),
	'codes=' . implode( ',', $wccs_proof_created->error_codes() ) . ' id=' . $wccs_proof_id
);

$wccs_proof_id = (string) ( wccs_proof_stored()[0]['id'] ?? '' );

wccs_proof_check(
	'The store assigns a readable identifier, and it is not the label',
	'analise_pendente' === $wccs_proof_id,
	'id=' . $wccs_proof_id
);

$wccs_proof_registry = \WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();
$wccs_proof_known    = wc_get_order_statuses();

wccs_proof_check(
	'And it is registered with WooCommerce under the merchant own words',
	in_array( $wccs_proof_id, $wccs_proof_registry, true )
		&& 'Análise pendente' === ( $wccs_proof_known[ 'wc-' . $wccs_proof_id ] ?? '' ),
	'known=' . ( $wccs_proof_known[ 'wc-' . $wccs_proof_id ] ?? '(none)' )
);

wccs_proof_check(
	'The customer is told what the merchant wrote for them, and not the internal name',
	'Estamos a analisar o seu pedido' === \WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::customer_label( $wccs_proof_id, 'fallback' ),
	'label=' . \WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::customer_label( $wccs_proof_id, 'fallback' )
);

// ---------------------------------------------------------------------------
// 2. §12.7 steps 4 to 6: a test order in it, and nothing paid.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Criar pedido teste, aplicar o status, confirmar que o Woo não o considera pago' );

$wccs_proof_order = wc_create_order( array( 'status' => 'pending' ) );
$wccs_proof_order = $wccs_proof_order instanceof WC_Order ? $wccs_proof_order : null;

if ( null === $wccs_proof_order ) {
	fwrite( STDERR, "Could not create the order this harness needs.\n" );
	exit( 1 );
}

$wccs_proof_order->set_status( 'wc-' . $wccs_proof_id );
$wccs_proof_order->save();

$wccs_proof_reloaded = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'The order is in the state the merchant created',
	$wccs_proof_id === $wccs_proof_reloaded->get_status(),
	'status=' . $wccs_proof_reloaded->get_status()
);

wccs_proof_check(
	'And WooCommerce does not consider it paid',
	false === $wccs_proof_reloaded->is_paid() && null === $wccs_proof_reloaded->get_date_paid(),
	'paid=' . wp_json_encode( $wccs_proof_reloaded->is_paid() )
);

wccs_proof_check(
	'And it is not in any state WooCommerce treats as paid, which is what a pre-payment state is',
	! $wccs_proof_reloaded->has_status( wc_get_is_paid_statuses() ) && ! $wccs_proof_reloaded->has_status( array( 'processing', 'completed' ) ),
	'status=' . $wccs_proof_reloaded->get_status()
);

wccs_proof_check(
	'And nothing was written to it that a payment would have written',
	'' === (string) $wccs_proof_reloaded->get_transaction_id() && null === $wccs_proof_reloaded->get_date_paid(),
	'transaction=' . (string) $wccs_proof_reloaded->get_transaction_id()
);

// §12.5 in the strong form: not only is this status not added to the paid list, a pre-payment
// status is **taken out** of it, so a third party that put it there cannot make the store consider
// an order in it paid.
add_filter(
	'woocommerce_order_is_paid_statuses',
	static function ( $statuses ) use ( $wccs_proof_id ) {
		$statuses   = is_array( $statuses ) ? $statuses : array();
		$statuses[] = $wccs_proof_id;
		$statuses[] = 'wc-' . $wccs_proof_id;

		return $statuses;
	},
	5
);

$wccs_proof_paid = wc_get_is_paid_statuses();

wccs_proof_check(
	'And a pre-payment status is kept out of WooCommerce paid list even when something adds it',
	! in_array( $wccs_proof_id, $wccs_proof_paid, true )
		&& ! in_array( 'wc-' . $wccs_proof_id, $wccs_proof_paid, true ),
	'paid=' . implode( ',', $wccs_proof_paid )
);

wccs_proof_check(
	'And WooCommerce own paid statuses are untouched by the guard',
	in_array( 'processing', $wccs_proof_paid, true ) && in_array( 'completed', $wccs_proof_paid, true ),
	'paid=' . implode( ',', $wccs_proof_paid )
);

wccs_proof_check(
	'The order is still not paid, with the status declared as pre-payment',
	false === wc_get_order( $wccs_proof_order->get_id() )->is_paid()
);

// ---------------------------------------------------------------------------
// 3. §12.7 steps 7 and 8: rename the label, the status does not move.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Renomear o label; confirmar que os pedidos continuam no mesmo status interno' );

$wccs_proof_stored = wccs_proof_stored();
$wccs_proof_stored[0]['label'] = 'Em análise';

$wccs_proof_renamed = wccs_proof_save( $wccs_proof_stored );

wccs_proof_check(
	'The renamed configuration is accepted',
	$wccs_proof_renamed->is_valid(),
	'codes=' . implode( ',', $wccs_proof_renamed->error_codes() )
);

\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

$wccs_proof_after_rename = wc_get_order_statuses();

wccs_proof_check(
	'The label changed',
	'Em análise' === ( $wccs_proof_after_rename[ 'wc-' . $wccs_proof_id ] ?? '' ),
	'label=' . ( $wccs_proof_after_rename[ 'wc-' . $wccs_proof_id ] ?? '(none)' )
);

wccs_proof_check(
	'And the identifier did not, which is what keeps the order where it was',
	isset( $wccs_proof_after_rename[ 'wc-' . $wccs_proof_id ] )
		&& $wccs_proof_id === wc_get_order( $wccs_proof_order->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_proof_order->get_id() )->get_status()
);

// ---------------------------------------------------------------------------
// 4. What the store refuses.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. O que a loja recusa' );

$wccs_proof_reserved = wccs_proof_save( array( array( 'id' => 'processing', 'label' => 'Processando' ) ) );

wccs_proof_check(
	'A status that would take a state WooCommerce owns is refused',
	! $wccs_proof_reserved->is_valid()
		&& in_array( 'status_id_reserved', $wccs_proof_reserved->error_codes(), true ),
	implode( ',', $wccs_proof_reserved->error_codes() )
);

$wccs_proof_long = wccs_proof_save( array( array( 'id' => 'um_identificador_longo_demais', 'label' => 'Longo' ) ) );

wccs_proof_check(
	'And so is an identifier too long for the column the state is recorded in',
	! $wccs_proof_long->is_valid()
		&& in_array( 'status_id_too_long', $wccs_proof_long->error_codes(), true ),
	implode( ',', $wccs_proof_long->error_codes() )
);

$wccs_proof_refused = wccs_proof_stored();

wccs_proof_check(
	'And a refused write changed nothing in the store',
	1 === count( $wccs_proof_refused ) && $wccs_proof_id === (string) $wccs_proof_refused[0]['id'],
	'stored=' . count( $wccs_proof_refused )
);

// ---------------------------------------------------------------------------
// 5. An inactive status does not exist.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Um status inativo não é registado' );

$wccs_proof_off = wccs_proof_stored();
$wccs_proof_off[0]['active'] = false;

wccs_proof_save( $wccs_proof_off );
\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

wccs_proof_check(
	'An inactive status is not in the list WooCommerce offers',
	! isset( wc_get_order_statuses()[ 'wc-' . $wccs_proof_id ] ),
	'known=' . wp_json_encode( array_keys( wc_get_order_statuses() ) )
);

$wccs_proof_on = wccs_proof_stored();
$wccs_proof_on[0]['active'] = true;

wccs_proof_save( $wccs_proof_on );
\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

wccs_proof_check(
	'And it comes back when the merchant turns it on again',
	isset( wc_get_order_statuses()[ 'wc-' . $wccs_proof_id ] )
);

// ---------------------------------------------------------------------------
// 6. The review state that predates this screen is migrated.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. O status de revisão anterior a este ecrã é migrado com o id que os pedidos já têm' );

$wccs_proof_flow_field = array(
	'id'             => 'wc-checkoutsuite/licenca',
	'integration_id' => 'wc-checkoutsuite/licenca',
	'origin'         => 'custom',
	'type'           => 'file',
	'label'          => 'Licença',
	'section'        => 'documentacao',
	'enabled'        => true,
	'required'       => false,
	'position'       => 10,
	'storage'        => array(
		'scope'       => 'order',
		'sensitivity' => 'personal',
	),
	'destinations'   => \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations(),
	'approval'       => array(
		'require_review'   => true,
		'area'             => 'admin_order',
		'section'          => 'documentos_enviados',
		'status'           => 'Pendente de aprovação',
		'allow_correction' => true,
		'allow_resubmit'   => true,
		'show_status'      => true,
	),
);

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_proof_written = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision' => 1,
			'fields'   => array( $wccs_proof_flow_field ),
			'sections' => array(
				array(
					'id'       => 'documentacao',
					'title'    => 'Documentação',
					'position' => 10,
					'location' => 'order',
					'areas'    => array( 'checkout' ),
				),
				array(
					'id'       => 'documentos_enviados',
					'title'    => 'Documentos enviados',
					'position' => 20,
					'location' => 'order',
					'areas'    => array( 'admin_order' ),
				),
			),
		)
	),
	0
);

wccs_proof_check(
	'A document with a complete approval flow is accepted',
	$wccs_proof_written->is_ok(),
	'codes=' . implode( ',', array_map( static fn( $error ) => (string) $error['code'], $wccs_proof_written->errors() ) )
);

$wccs_proof_legacy = \WCCheckoutSuite\Domain\Approval\ReviewStatus::statuses( array( $wccs_proof_flow_field ) );
$wccs_proof_legacy_id = (string) array_key_first( $wccs_proof_legacy );

\WCCheckoutSuite\Domain\Approval\ReviewStatus::publish();

$wccs_proof_migrated = ( new \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository() )->find( $wccs_proof_legacy_id );

wccs_proof_check(
	'The legacy review state appears in the store own list, under the identifier the orders already hold',
	null !== $wccs_proof_migrated && $wccs_proof_legacy_id === $wccs_proof_migrated->id(),
	'id=' . $wccs_proof_legacy_id
);

wccs_proof_check(
	'And it is registered, so an order in it is in a state the store still has',
	isset( wc_get_order_statuses()[ 'wc-' . $wccs_proof_legacy_id ] ),
	'label=' . ( wc_get_order_statuses()[ 'wc-' . $wccs_proof_legacy_id ] ?? '(none)' )
);

wccs_proof_check(
	'And it is marked as a state before payment, which is what a review state is',
	null !== $wccs_proof_migrated && $wccs_proof_migrated->is_prepayment()
);

$wccs_proof_with_order = wc_create_order( array( 'status' => 'pending' ) );
$wccs_proof_with_order->set_status( 'wc-' . $wccs_proof_legacy_id );
$wccs_proof_with_order->save();

$wccs_proof_legacy_stored = wccs_proof_stored();

foreach ( $wccs_proof_legacy_stored as $wccs_proof_index => $wccs_proof_entry ) {
	if ( $wccs_proof_legacy_id === (string) ( $wccs_proof_entry['id'] ?? '' ) ) {
		$wccs_proof_legacy_stored[ $wccs_proof_index ]['label'] = 'A rever documentos';
	}
}

wccs_proof_save( $wccs_proof_legacy_stored );
\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

wccs_proof_check(
	'Renaming the migrated state leaves the order in it',
	'A rever documentos' === ( wc_get_order_statuses()[ 'wc-' . $wccs_proof_legacy_id ] ?? '' )
		&& $wccs_proof_legacy_id === wc_get_order( $wccs_proof_with_order->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_proof_with_order->get_id() )->get_status()
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

// HPOS: an order is not a post, so it is deleted through its own API. Deleting the post
// instead would leave the order in the orders table and the harness with residue.
$wccs_proof_order->delete( true );
$wccs_proof_with_order->delete( true );

delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_proof_options_after === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . $wccs_proof_options_after
);

wccs_proof_check(
	'The orders the proof created are gone',
	! ( wc_get_order( $wccs_proof_order->get_id() ) instanceof WC_Order )
		&& ! ( wc_get_order( $wccs_proof_with_order->get_id() ) instanceof WC_Order ),
	''
);

wccs_proof_note(
	'Why the status carries no payment field',
	'§12.4: um status customizado não é por si só um comando de cobrança. The model has a name, a colour, a label for the customer, who sees it, who may move an order into it, and whether it is a state before payment — and nothing a gateway reads. A charge happens when a workflow transition runs an authorised payment action (§3.8, Fase 10/11), which is why a status changed by hand, by an import or by a webhook cannot charge anything.'
);

wccs_proof_note(
	'Why the status list is an option and not the schema document',
	'A status is not part of the checkout composition: it is a state an order enters after the checkout, it belongs to the workflow vocabulary, and §12 gives it its own screen. It is also durable on purpose — an order recorded in a state keeps being in a registered state even if the flow that named it is turned off, and restoring an earlier schema revision must not delete the states the orders are in.'
);

wccs_proof_note(
	'What the customer label is for',
	'§12.3 asks for a label for the customer beside the name. The account page, the thank-you page and the e-mails read it through one accessor, so a store that calls a state "Análise pendente" internally and "Estamos a analisar o seu pedido" to the customer says one thing on each side and never the wrong one.'
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
