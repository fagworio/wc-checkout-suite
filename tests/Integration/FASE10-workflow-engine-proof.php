<?php
/**
 * Fase 10 proof harness — the workflow engine runs a workflow once, and pays nothing.
 *
 * Task:   Fase 10 "Workflow Engine"
 * Gate:   "workflow totalmente idempotente sem payment action ainda."
 *
 * The gate has two halves and both of them are absences, which is what makes a harness the right
 * place to prove them: a status that moved once however many times the engine ran, and a payment
 * that was never touched. So this harness drives the engine with a real order and asks WooCommerce,
 * after every step, whether anything was paid.
 *
 * 1. **Entering happens once.** The engine is called twice for the same order — the shape of a
 *    retried request, a double hook and a second checkout event — and the order moves once, the
 *    audit log holds one entry, and the second call says why it did nothing.
 * 2. **A decision happens once**, and a **different** decision is a different decision: a store that
 *    rejected an order in error must be able to approve it afterwards.
 * 3. **The clock decides once**, whether it arrives by the scheduled event or by the sweep, and an
 *    order already decided is not expired.
 * 4. **No payment action.** No capture, no authorisation, no gateway: the report says so, and the
 *    order's own record says so.
 * 5. **What cannot be executed is refused by name**, so a workflow in this build cannot even ask for
 *    a reservation or a capture.
 * 6. The simulator answers without doing anything, which is §13.6's one hard rule.
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
 * The entries in an order's audit log.
 *
 * @param int $order_id Order identifier.
 * @return array<int, array<string, mixed>>
 */
function wccs_proof_log( int $order_id ): array {
	return ( new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository() )->log( $order_id );
}

/**
 * How many entries an order's log holds for one key.
 *
 * @param int    $order_id Order identifier.
 * @param string $key      Event key.
 * @return int
 */
function wccs_proof_count( int $order_id, string $key ): int {
	$count = 0;

	foreach ( wccs_proof_log( $order_id ) as $entry ) {
		if ( (string) ( $entry['key'] ?? '' ) === $key ) {
			++$count;
		}
	}

	return $count;
}

/**
 * A status identifier the store has, from the vocabulary the workflows name.
 *
 * @param string $id Identifier, or a WooCommerce one.
 * @return string
 */
function wccs_proof_status( string $id ): string {
	return $id;
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 10 proof — a workflow runs once, and pays nothing' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. The store creates the two states the workflow names.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Os estados que o workflow nomeia existem na loja' );

$wccs_proof_statuses = new \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository();

$wccs_proof_statuses->save(
	array(
		array(
			'id'         => 'analise_pendente',
			'label'      => 'Análise pendente',
			'prepayment' => true,
		),
		array(
			'id'         => 'reprovado',
			'label'      => 'Reprovado',
			'prepayment' => true,
		),
		array(
			'id'         => 'expirado',
			'label'      => 'Expirado',
			'prepayment' => true,
		),
		array(
			'id'         => 'aprovado',
			'label'      => 'Aprovado para cobrança',
			'prepayment' => true,
		),
	)
);

\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

wccs_proof_check(
	'The four states a workflow will move orders through are registered',
	isset( wc_get_order_statuses()['wc-analise_pendente'] )
		&& isset( wc_get_order_statuses()['wc-reprovado'] )
		&& isset( wc_get_order_statuses()['wc-expirado'] )
		&& isset( wc_get_order_statuses()['wc-aprovado'] ),
	'statuses=' . implode( ',', array_keys( wc_get_order_statuses() ) )
);

// ---------------------------------------------------------------------------
// 2. The workflow itself.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A automação da §13.2' );

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository();

$wccs_proof_saved = $wccs_proof_repository->save(
	array(
		array(
			'id'                  => '',
			'name'                => 'Produtos químicos',
			'enabled'             => true,
			'priority'            => 10,
			'trigger'             => \WCCheckoutSuite\Domain\Workflow\Workflows::TRIGGER_CHECKOUT_SUBMITTED,
			// §13.2 step 1's own example, on the source the fixtures of Fase 8 answered for.
			'conditions'          => array(
				'source'   => 'cart_categories',
				'operator' => 'contains',
				// The slug the taxonomy itself uses: `contains` on a list is membership, so the
				// rule names the category the product is in and not a prefix of it.
				'value'    => 'quimicos-prova-10',
			),
			'initial_status'      => 'analise_pendente',
			'inventory_strategy'  => \WCCheckoutSuite\Domain\Workflow\Workflows::INVENTORY_NONE,
			'payment_strategy'    => \WCCheckoutSuite\Domain\Workflow\Workflows::PAYMENT_NONE,
			'communications'      => array(
				\WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_RECEIVED   => true,
				\WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_APPROVED   => true,
				\WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_REJECTED   => true,
				\WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_EXPIRED    => true,
				\WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_CORRECTION => true,
			),
			'transitions'         => array(
				// §13.4's chain is approve → PaymentAction → *if payment complete* →
				// Processing/Completed. There is no payment action in this build, so the approval
				// lands on a state that is not paid; `processing` is refused by the validator below,
				// which is §13.8's "nenhum status pago é aplicado antes do pagamento".
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE    => 'aprovado',
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_CORRECTION => 'analise_pendente',
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_REJECT     => 'reprovado',
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_EXPIRE     => 'expirado',
			),
			'expires_after_hours' => 72,
		),
	)
);

wccs_proof_check(
	'The workflow is accepted and the store assigned the identifier from its name',
	$wccs_proof_saved->is_valid()
		&& 'produtos_quimicos' === (string) ( ( new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository() )->raw()[0]['id'] ?? '' ),
	'codes=' . implode( ',', $wccs_proof_saved->error_codes() )
);

// ---------------------------------------------------------------------------
// 3. What the engine may not be asked to do.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. O que esta versão recusa por nome' );

// The payment strategies are checked on a definition of their own rather than by saving one: since
// the payment action service exists they are accepted, and a write that succeeded would replace the
// workflow the rest of this harness runs on.
foreach ( array( 'capture_after_approval', 'authorize_now', 'request_after_approval' ) as $wccs_proof_strategy ) {
	$wccs_proof_accepted = \WCCheckoutSuite\Domain\Workflow\WorkflowValidator::validate_one(
		\WCCheckoutSuite\Domain\Workflow\WorkflowDefinition::from_array(
			array(
				'id'               => 'com_pagamento',
				'name'             => 'Com ação de pagamento',
				'initial_status'   => 'analise_pendente',
				'payment_strategy' => $wccs_proof_strategy,
			)
		),
		\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::known_ids(),
		array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) )
	);

	wccs_proof_check(
		sprintf( 'A estratégia de pagamento «%s» é aceite, porque o serviço que a executa existe', $wccs_proof_strategy ),
		$wccs_proof_accepted->is_valid(),
		implode( ',', $wccs_proof_accepted->error_codes() )
	);
}

// The stock strategies are checked the same way, and for the same reason: since Fase 13 the
// reservation service exists, so they are accepted and a write that succeeded here would replace the
// workflow the rest of this harness runs on.
$wccs_proof_accepted_stock = \WCCheckoutSuite\Domain\Workflow\WorkflowValidator::validate_one(
	\WCCheckoutSuite\Domain\Workflow\WorkflowDefinition::from_array(
		array(
			'id'                 => 'com_estoque',
			'name'               => 'Com reserva',
			'initial_status'     => 'analise_pendente',
			'inventory_strategy' => 'until_decision',
		)
	),
	\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::known_ids(),
	array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) )
);

wccs_proof_check(
	'E a reserva de estoque também, porque o serviço que a executa existe desde a fase 13',
	$wccs_proof_accepted_stock->is_valid()
		&& ! in_array( 'inventory_strategy_not_available', $wccs_proof_accepted_stock->error_codes(), true ),
	implode( ',', $wccs_proof_accepted_stock->error_codes() )
);

wccs_proof_check(
	'E nenhuma destas verificações escreveu por cima do que está guardado',
	1 === count( $wccs_proof_repository->raw() )
		&& 'produtos_quimicos' === (string) ( $wccs_proof_repository->raw()[0]['id'] ?? '' ),
	'stored=' . count( $wccs_proof_repository->raw() )
);

$wccs_proof_unknown = $wccs_proof_repository->save(
	array(
		array(
			'id'             => 'estado_errado',
			'name'           => 'Estado que não existe',
			'initial_status' => 'nao_existe',
		),
	)
);

wccs_proof_check(
	'E um workflow que nomeia um estado que a loja não tem é recusado antes de um pedido o alcançar',
	! $wccs_proof_unknown->is_valid() && in_array( 'workflow_status_unknown', $wccs_proof_unknown->error_codes(), true ),
	implode( ',', $wccs_proof_unknown->error_codes() )
);

$wccs_proof_paid = $wccs_proof_repository->save(
	array(
		array(
			'id'             => 'pago_sem_pagar',
			'name'           => 'Aprovar para um estado pago',
			'initial_status' => 'analise_pendente',
			'transitions'    => array(
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE => 'processing',
			),
		),
	)
);

wccs_proof_check(
	'E um workflow que move um pedido para um estado que a WooCommerce considera pago é recusado, porque nenhum pagamento aconteceu (§13.8)',
	! $wccs_proof_paid->is_valid()
		&& in_array( 'workflow_paid_status_without_payment', $wccs_proof_paid->error_codes(), true ),
	implode( ',', $wccs_proof_paid->error_codes() )
);

// ---------------------------------------------------------------------------
// 4. An order enters the workflow, once.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Um pedido entra na automação — uma vez' );

$wccs_proof_term = wp_insert_term( 'Químicos (prova 10)', 'product_cat', array( 'slug' => 'quimicos-prova-10' ) );

if ( is_wp_error( $wccs_proof_term ) ) {
	fwrite( STDERR, 'Could not create the category: ' . $wccs_proof_term->get_error_message() . "\n" );
	exit( 1 );
}

$wccs_proof_product = new WC_Product_Simple();
$wccs_proof_product->set_name( 'Produto químico (prova 10)' );
$wccs_proof_product->set_slug( 'produto-quimico-prova-10' );
$wccs_proof_product->set_status( 'publish' );
$wccs_proof_product->set_catalog_visibility( 'visible' );
$wccs_proof_product->set_regular_price( '100' );
$wccs_proof_product->set_price( '100' );
$wccs_proof_product->set_category_ids( array( (int) $wccs_proof_term['term_id'] ) );
$wccs_proof_product_id = (int) $wccs_proof_product->save();

$wccs_proof_order = wc_create_order( array( 'status' => 'pending' ) );
$wccs_proof_order = $wccs_proof_order instanceof WC_Order ? $wccs_proof_order : null;

if ( null === $wccs_proof_order ) {
	fwrite( STDERR, "Could not create the order this harness needs.\n" );
	exit( 1 );
}

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $wccs_proof_product_id );
WC()->cart->calculate_totals();

$wccs_proof_first = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_order );

wccs_proof_check(
	'The engine matches the workflow by the cart the order came from',
	'produtos_quimicos' === (string) ( $wccs_proof_first['workflow'] ?? '' ) && ! empty( $wccs_proof_first['applied'] ),
	'workflow=' . ( $wccs_proof_first['workflow'] ?? '(none)' ) . ' reason=' . ( $wccs_proof_first['reason'] ?? '' )
);

$wccs_proof_reloaded = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'And the order waits in the state the workflow named',
	'analise_pendente' === $wccs_proof_reloaded->get_status(),
	'status=' . $wccs_proof_reloaded->get_status()
);

wccs_proof_check(
	'And the audit log holds exactly one entry for it',
	1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:entered' ),
	'entries=' . count( wccs_proof_log( (int) $wccs_proof_order->get_id() ) )
);

$wccs_proof_second = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_order );
$wccs_proof_reloaded = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'And a second creation event — a retry, a double hook — does nothing',
	false === ( $wccs_proof_second['applied'] ?? true )
		&& 'already_entered' === ( $wccs_proof_second['reason'] ?? '' )
		&& 'analise_pendente' === $wccs_proof_reloaded->get_status(),
	'reason=' . ( $wccs_proof_second['reason'] ?? '' ) . ' status=' . $wccs_proof_reloaded->get_status()
);

wccs_proof_check(
	'And the log still holds one entry, not two',
	1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:entered' )
);

$wccs_proof_third  = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_order );
$wccs_proof_fourth = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_order );

wccs_proof_check(
	'And running it five times in total still moved the order once',
	false === ( $wccs_proof_third['applied'] ?? true )
		&& false === ( $wccs_proof_fourth['applied'] ?? true )
		&& 1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:entered' )
);

// ---------------------------------------------------------------------------
// 5. And it was not paid.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. E não foi pago nada' );

$wccs_proof_reloaded = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'The order is not paid, and has no payment date',
	false === $wccs_proof_reloaded->is_paid() && null === $wccs_proof_reloaded->get_date_paid(),
	'paid=' . wp_json_encode( $wccs_proof_reloaded->is_paid() )
);

wccs_proof_check(
	'And no transaction was recorded against it',
	'' === (string) $wccs_proof_reloaded->get_transaction_id(),
	'transaction=' . (string) $wccs_proof_reloaded->get_transaction_id()
);

wccs_proof_check(
	'And the state it waits in is not one WooCommerce considers paid',
	! $wccs_proof_reloaded->has_status( wc_get_is_paid_statuses() ),
	'paid_statuses=' . implode( ',', wc_get_is_paid_statuses() )
);

wccs_proof_check(
	'And the engine reported the strategy it was not asked to execute',
	'none' === (string) ( $wccs_proof_first['deferred']['payment'] ?? '' )
		&& 'none' === (string) ( $wccs_proof_first['deferred']['inventory'] ?? '' ),
	'deferred=' . wp_json_encode( $wccs_proof_first['deferred'] ?? null )
);

// ---------------------------------------------------------------------------
// 6. A decision happens once, and a different decision is a different decision.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Uma decisão acontece uma vez' );

$wccs_proof_decision = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_order, \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE );

wccs_proof_check(
	'Aprovar move o pedido para o estado que o workflow nomeou, e não para um estado pago',
	! empty( $wccs_proof_decision['applied'] )
		&& 'aprovado' === wc_get_order( $wccs_proof_order->get_id() )->get_status()
		&& ! wc_get_order( $wccs_proof_order->get_id() )->has_status( wc_get_is_paid_statuses() ),
	'status=' . wc_get_order( $wccs_proof_order->get_id() )->get_status()
);

wccs_proof_check(
	'And the decision is recorded once',
	1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:approve' )
);

wccs_proof_check(
	'And the decision still paid nothing',
	false === wc_get_order( $wccs_proof_order->get_id() )->is_paid()
);

$wccs_proof_again = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_order, \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE );

wccs_proof_check(
	'Approving again, which is what a double click is, does nothing',
	false === ( $wccs_proof_again['applied'] ?? true )
		&& 'already_decided' === ( $wccs_proof_again['reason'] ?? '' )
		&& 1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:approve' ),
	'reason=' . ( $wccs_proof_again['reason'] ?? '' )
);

$wccs_proof_rejected = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_order, \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_REJECT );

wccs_proof_check(
	'And a different decision is a different decision: an order approved by mistake can be rejected',
	! empty( $wccs_proof_rejected['applied'] )
		&& 'reprovado' === wc_get_order( $wccs_proof_order->get_id() )->get_status()
		&& 1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:reject' ),
	'status=' . wc_get_order( $wccs_proof_order->get_id() )->get_status()
);

$wccs_proof_unconfigured = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_order, 'nao_existe' );

wccs_proof_check(
	'And a decision the workflow does not answer is refused with a reason',
	'decision_not_configured' === ( $wccs_proof_unconfigured['reason'] ?? '' ),
	'reason=' . ( $wccs_proof_unconfigured['reason'] ?? '' )
);

wccs_proof_check(
	'And every decision reports that it touched no payment',
	'none' === (string) ( $wccs_proof_decision['payment'] ?? '' )
		&& 'none' === (string) ( $wccs_proof_rejected['payment'] ?? '' )
);

// ---------------------------------------------------------------------------
// 7. The clock, and the sweep behind it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. O relógio decide uma vez, e a ronda atrás dele também' );

$wccs_proof_waiting = wc_create_order( array( 'status' => 'pending' ) );
$wccs_proof_waiting = $wccs_proof_waiting instanceof WC_Order ? $wccs_proof_waiting : null;

if ( null === $wccs_proof_waiting ) {
	fwrite( STDERR, "Could not create the second order this harness needs.\n" );
	exit( 1 );
}

\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_waiting );

wccs_proof_check(
	'A second order enters the same workflow',
	'analise_pendente' === wc_get_order( $wccs_proof_waiting->get_id() )->get_status()
);

wccs_proof_check(
	'And the store scheduled the moment it expires',
	false !== wp_next_scheduled( \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::HOOK_EXPIRE, array( (int) $wccs_proof_waiting->get_id() ) ),
	''
);

$wccs_proof_early = \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::expire( $wccs_proof_waiting );

wccs_proof_check(
	'And expiring it before its deadline does nothing',
	false === ( $wccs_proof_early['applied'] ?? true ) && 'not_due' === ( $wccs_proof_early['reason'] ?? '' ),
	'reason=' . ( $wccs_proof_early['reason'] ?? '' )
);

// The deadline is moved into the past the way a clock moving forward would: the state the engine
// wrote is rewritten, and the question is asked again.
$wccs_proof_state = ( new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository() )->state( (int) $wccs_proof_waiting->get_id() );
$wccs_proof_state['deadline'] = gmdate( 'c', time() - 60 );

wc_get_order( $wccs_proof_waiting->get_id() )->update_meta_data(
	\WCCheckoutSuite\Domain\Workflow\WorkflowRepository::META_STATE,
	(string) wp_json_encode( $wccs_proof_state )
);
wc_get_order( $wccs_proof_waiting->get_id() )->save();

$wccs_proof_expired = \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::expire( $wccs_proof_waiting );

wccs_proof_check(
	'And after the deadline it expires into the state the workflow named',
	! empty( $wccs_proof_expired['applied'] )
		&& 'expirado' === wc_get_order( $wccs_proof_waiting->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_proof_waiting->get_id() )->get_status()
);

$wccs_proof_twice = \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::expire( $wccs_proof_waiting );

wccs_proof_check(
	'And expiring it again does nothing, and the log holds one entry',
	false === ( $wccs_proof_twice['applied'] ?? true )
		&& 1 === wccs_proof_count( (int) $wccs_proof_waiting->get_id(), 'produtos_quimicos:expire' ),
	'reason=' . ( $wccs_proof_twice['reason'] ?? '' )
);

$wccs_proof_sweep = \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::sweep();

wccs_proof_check(
	'And a sweep run over the same store expires nobody twice',
	array() === (array) ( $wccs_proof_sweep['expired'] ?? array( 'not-empty' ) ),
	'checked=' . (int) ( $wccs_proof_sweep['checked'] ?? 0 ) . ' expired=' . implode( ',', (array) ( $wccs_proof_sweep['expired'] ?? array() ) )
);

wccs_proof_check(
	'And the order that was already decided is not expired by the clock',
	'reprovado' === wc_get_order( $wccs_proof_order->get_id() )->get_status()
		&& 0 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'produtos_quimicos:expire' ),
	'status=' . wc_get_order( $wccs_proof_order->get_id() )->get_status()
);

// ---------------------------------------------------------------------------
// 8. The simulator answers, and changes nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. O simulador responde sem fazer nada (§13.6)' );

$wccs_proof_simulation = \WCCheckoutSuite\Domain\Workflow\WorkflowSimulator::run(
	$wccs_proof_repository->raw(),
	\WCCheckoutSuite\Domain\Workflow\Workflows::TRIGGER_CHECKOUT_SUBMITTED,
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'cart_categories' => array( 'quimicos-prova-10' ) ), 'workflow' ),
	array( 'category' => 'quimicos-prova-10' )
);

wccs_proof_check(
	'The simulation names the workflow that would run',
	! empty( $wccs_proof_simulation['matched'] )
		&& 'produtos_quimicos' === (string) ( $wccs_proof_simulation['workflow'] ?? '' ),
	'matched=' . wp_json_encode( $wccs_proof_simulation['matched'] ?? null )
);

wccs_proof_check(
	'And the state the order would wait in, by name',
	'Análise pendente' === (string) ( $wccs_proof_simulation['initial']['label'] ?? '' ),
	'initial=' . ( $wccs_proof_simulation['initial']['label'] ?? '' )
);

wccs_proof_check(
	'And it says what happens first, and when the clock decides',
	false !== strpos( (string) ( $wccs_proof_simulation['next'] ?? '' ), 'Análise pendente' )
		&& 72 === (int) ( $wccs_proof_simulation['expires'] ?? 0 ),
	'next=' . ( $wccs_proof_simulation['next'] ?? '' )
);

wccs_proof_check(
	'And it tells the merchant whether each strategy can be carried out today',
	true === ( $wccs_proof_simulation['payment']['executable'] ?? null )
		&& true === ( $wccs_proof_simulation['inventory']['executable'] ?? null )
);

wccs_proof_check(
	'And it names every decision the workflow answers, with what the customer would be told',
	4 === count( (array) $wccs_proof_simulation['transitions'] )
		&& '' !== (string) ( $wccs_proof_simulation['transitions'][0]['event'] ?? '' ),
	'transitions=' . count( (array) $wccs_proof_simulation['transitions'] )
);

$wccs_proof_before_simulation = wccs_proof_log( (int) $wccs_proof_order->get_id() );

\WCCheckoutSuite\Domain\Workflow\WorkflowSimulator::run(
	$wccs_proof_repository->raw(),
	\WCCheckoutSuite\Domain\Workflow\Workflows::TRIGGER_CHECKOUT_SUBMITTED,
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'cart_categories' => array( 'quimicos-prova-10' ) ), 'workflow' )
);

wccs_proof_check(
	'And simulating leaves no trace: nothing was written and no order moved',
	$wccs_proof_before_simulation === wccs_proof_log( (int) $wccs_proof_order->get_id() )
		&& 'reprovado' === wc_get_order( $wccs_proof_order->get_id() )->get_status()
);

$wccs_proof_none = \WCCheckoutSuite\Domain\Workflow\WorkflowSimulator::run(
	$wccs_proof_repository->raw(),
	\WCCheckoutSuite\Domain\Workflow\Workflows::TRIGGER_CHECKOUT_SUBMITTED,
	new \WCCheckoutSuite\Domain\Fields\FieldContext( array( 'cart_categories' => array( 'livros' ) ), 'workflow' )
);

wccs_proof_check(
	'And a scenario nothing matches says so, instead of showing an empty panel',
	false === ( $wccs_proof_none['matched'] ?? true ) && '' !== (string) ( $wccs_proof_none['next'] ?? '' ),
	'next=' . ( $wccs_proof_none['next'] ?? '' )
);

// ---------------------------------------------------------------------------
// 9. An order no workflow claims keeps WooCommerce's own process.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '9. Uma loja sem automação não muda nada' );

$wccs_proof_plain = wc_create_order( array( 'status' => 'pending' ) );

WC()->cart->empty_cart();
WC()->cart->calculate_totals();

$wccs_proof_unclaimed = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_plain );

wccs_proof_check(
	'An order the workflow does not match keeps the status WooCommerce gave it',
	'no_workflow' === (string) ( $wccs_proof_unclaimed['reason'] ?? '' )
		&& 'pending' === wc_get_order( $wccs_proof_plain->get_id() )->get_status()
		&& array() === wccs_proof_log( (int) $wccs_proof_plain->get_id() ),
	'reason=' . ( $wccs_proof_unclaimed['reason'] ?? '' ) . ' status=' . wc_get_order( $wccs_proof_plain->get_id() )->get_status()
);

// ---------------------------------------------------------------------------
// 10. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '10. Environment' );

WC()->cart->empty_cart();

$wccs_proof_orders = array( $wccs_proof_order, $wccs_proof_waiting, $wccs_proof_plain );

foreach ( $wccs_proof_orders as $wccs_proof_entry ) {
	\WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::cancel( $wccs_proof_entry );
	$wccs_proof_entry->delete( true );
}

wp_delete_post( $wccs_proof_product_id, true );
wp_delete_term( (int) $wccs_proof_term['term_id'], 'product_cat' );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );

$wccs_proof_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_proof_options_after === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . $wccs_proof_options_after
);

wccs_proof_check(
	'The orders, the product and the category the proof created are gone',
	! ( wc_get_order( $wccs_proof_order->get_id() ) instanceof WC_Order )
		&& ! ( wc_get_order( $wccs_proof_waiting->get_id() ) instanceof WC_Order )
		&& ! ( wc_get_order( $wccs_proof_plain->get_id() ) instanceof WC_Order )
		&& null === get_post( $wccs_proof_product_id ),
	''
);

wccs_proof_note(
	'Where the idempotency lives',
	'No log do pedido, e não na memória do pedido que corre: cada efeito é escrito na meta do pedido **antes** de acontecer, e a chave é «workflow:o que aconteceu». Um webhook repetido, um pedido repetido e um botão premido duas vezes chegam com a mesma chave e o segundo chega encontra a entrada. A garantia sobrevive a um reinício, a uma limpeza de cache e a um segundo worker, porque quem a guarda é o pedido.'
);

wccs_proof_note(
	'O que a expiração não faz',
	'§13.5 pede, além do estado, libertar a reserva de estoque e anular a autorização. Nenhuma das duas existe nesta versão — a primeira é a fase da reserva e a segunda depende do registo de capabilities do gateway — e inventá-las aqui seria inventar o que as próximas duas fases existem para construir. O relógio muda o estado e regista-o, uma vez.'
);

wccs_proof_note(
	'Porque o motor corre onde corre',
	'Os dois momentos em que a WooCommerce cria um pedido — o checkout clássico e o da Store API — são o que §13.2 chama «checkout enviado», e são antes de um gateway ter tido a sua palavra. É isso que permite a um pedido ficar à espera: o estado muda antes de alguém tentar cobrar. Intercetar o pagamento é a fase seguinte, e é por isso que o gate desta diz «sem payment action ainda».'
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
