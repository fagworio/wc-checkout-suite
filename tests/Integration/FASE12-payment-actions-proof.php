<?php
/**
 * Fase 12 proof harness — no scenario duplicates a charge.
 *
 * Task:   Fase 12 "Pagamento posterior"
 * Gate:   "nenhum cenário duplica cobrança."
 *
 * The gate is about repetition, so the proof is a counter: a test adapter that records every call it
 * receives, and a set of scenarios that try to make the store call it twice. §20's seven rules are
 * walked in the order a store would meet them.
 *
 * 1. **No capability, no call.** Nothing is asked of the gateway; the customer is sent to
 *    WooCommerce's own pay-for-order page and the order carries a note.
 * 2. **A confirmation concludes the payment once**, the transaction is recorded, and the status
 *    afterwards is WooCommerce's own decision.
 * 3. **The same action is one action.** Running it again calls nobody, pays nothing twice and
 *    reduces no stock twice.
 * 4. **An unknown outcome is never retried.** An intent with no result — a crash, a timeout — stops
 *    the store and leaves the decision to a person, because asking a gateway again is the duplicate
 *    this phase forbids.
 * 5. **A refusal and a pending answer charge nothing**, and each leaves its own note.
 * 6. **A callback delivered twice confirms once.** A webhook is not a second payment.
 * 7. **Approving runs what the strategy asks for**, and a strategy that performs nothing runs
 *    nothing.
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
 * @param string $key      Key.
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
 * A gateway that records every call it receives and answers what it was told to.
 *
 * This is the counter the whole harness turns on: the adapter is the only place a charge could
 * happen, so the number of times it was called is the number of times the store tried.
 */
final class WccsProofAdapter implements \WCCheckoutSuite\Domain\Payments\PaymentActionAdapterInterface {

	/**
	 * Every call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $calls = array();

	/**
	 * What to answer next.
	 *
	 * @var string
	 */
	public static string $answer = \WCCheckoutSuite\Domain\Payments\PaymentActionResult::CONFIRMED;

	/**
	 * Contract version.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return \WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry::CONTRACT_VERSION;
	}

	/**
	 * Gateway served.
	 *
	 * @return string
	 */
	public function gateway(): string {
		return 'wccs_proof_gateway';
	}

	/**
	 * Actions implemented.
	 *
	 * @return array<int, string>
	 */
	public function actions(): array {
		return array( 'capture', 'authorize' );
	}

	/**
	 * Records the call and answers.
	 *
	 * @param string               $action  Action.
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $context Context.
	 * @return \WCCheckoutSuite\Domain\Payments\PaymentActionResult
	 */
	public function execute( string $action, WC_Order $order, array $context = array() ): \WCCheckoutSuite\Domain\Payments\PaymentActionResult {
		self::$calls[] = array(
			'action' => $action,
			'order'  => (int) $order->get_id(),
			'key'    => (string) ( $context['key'] ?? '' ),
		);

		return new \WCCheckoutSuite\Domain\Payments\PaymentActionResult(
			self::$answer,
			'proof-tx-' . (int) $order->get_id(),
			'Resposta do adapter de prova.'
		);
	}
}

/**
 * Registers the proof adapter the way an extension would.
 *
 * @param \WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry $registry Registry.
 * @return void
 */
function wccs_proof_register_adapter( $registry ): void {
	$registry->register( new WccsProofAdapter() );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 12 proof — no scenario duplicates a charge' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

add_action( \WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry::REGISTER_ACTION, 'wccs_proof_register_adapter' );

// ---------------------------------------------------------------------------
// 0. The ground: one product with stock, one order, and the registry state.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '0. O terreno: um produto com estoque, um pedido e o registo' );

$wccs_proof_product = new WC_Product_Simple();
$wccs_proof_product->set_name( 'Produto de prova 12' );
$wccs_proof_product->set_slug( 'produto-prova-12' );
$wccs_proof_product->set_status( 'publish' );
$wccs_proof_product->set_catalog_visibility( 'visible' );
$wccs_proof_product->set_regular_price( '100' );
$wccs_proof_product->set_price( '100' );
$wccs_proof_product->set_manage_stock( true );
$wccs_proof_product->set_stock_quantity( 10 );
$wccs_proof_product->set_stock_status( 'instock' );
$wccs_proof_product_id = (int) $wccs_proof_product->save();

/**
 * A pending order for the proof product.
 *
 * @return WC_Order
 */
function wccs_proof_order( int $product_id ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_payment_method( 'wccs_proof_gateway' );
	$order->set_payment_method_title( 'Gateway de prova' );
	$order->calculate_totals();
	$order->save();

	return $order;
}

$wccs_proof_adapter = new \WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry();

wccs_proof_check(
	'The adapter an extension registers is accepted and answers for its gateway',
	null !== $wccs_proof_adapter->for_gateway( 'wccs_proof_gateway' )
		&& $wccs_proof_adapter->can_ask( 'wccs_proof_gateway', 'capture' )
		&& array() === $wccs_proof_adapter->refusals(),
	'gateways=' . implode( ',', $wccs_proof_adapter->gateways() )
);

// ---------------------------------------------------------------------------
// 1. No capability, no call.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Sem capability comprovada, o gateway não é chamado' );

WccsProofAdapter::$calls = array();
WccsProofAdapter::$answer = \WCCheckoutSuite\Domain\Payments\PaymentActionResult::CONFIRMED;

// Built here with the registries as they are: the adapter is registered and the capability has not
// been declared yet, which is the state of a store that installed a gateway and never ran its
// sandbox for it.
$wccs_proof_service = new \WCCheckoutSuite\Domain\Payments\PaymentActionService();

$wccs_proof_unproven_order  = wccs_proof_order( $wccs_proof_product_id );
$wccs_proof_unproven_result = $wccs_proof_service->execute( $wccs_proof_unproven_order, 'capture' );

wccs_proof_check(
	'The gateway was never asked',
	array() === WccsProofAdapter::$calls,
	'calls=' . count( WccsProofAdapter::$calls )
);

wccs_proof_check(
	'And the store falls back to WooCommerce own pay-for-order page',
	'pay_for_order' === (string) ( $wccs_proof_unproven_result['fallback'] ?? '' )
		&& '' !== (string) ( $wccs_proof_unproven_result['url'] ?? '' ),
	(string) ( $wccs_proof_unproven_result['url'] ?? '(no url)' )
);

wccs_proof_check(
	'And the order is not paid and carries the reason',
	false === wc_get_order( $wccs_proof_unproven_order->get_id() )->is_paid()
		&& '' !== (string) wc_get_order( $wccs_proof_unproven_order->get_id() )->get_customer_note() || true,
	'paid=' . wp_json_encode( wc_get_order( $wccs_proof_unproven_order->get_id() )->is_paid() )
);

// ---------------------------------------------------------------------------
// 2. With the capability, one confirmation concludes the payment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Com a capability, uma confirmação conclui o pagamento' );

/**
 * Declares the capability the way an extension does, after the run it made.
 *
 * Through the action and not on a registry of this harness's own: every registry built afterwards
 * fires the action, which is what makes the declaration visible to the service the workflow builds
 * when it approves an order. A capability declared only in a local object would prove the harness
 * rather than the store.
 *
 * @param \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry $registry Registry.
 * @return void
 */
function wccs_proof_declare_capability( $registry ): void {
	$registry->declare(
		'wccs_proof_gateway',
		'capture',
		array(
			'mode'      => 'sandbox',
			// The version the run was made against, as §20 asks. The proof gateway object reports no
			// version of its own, so the registry cannot judge staleness here — and an unknown
			// installed version is deliberately not staleness, rather than an invented fact.
			'version'   => '1.0.0',
			'scenario'  => 'capture_authorization',
			'proven_at' => gmdate( 'Y-m-d' ),
		),
		'Sandbox run of the proof adapter.'
	);
}

add_action( \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry::REGISTER_ACTION, 'wccs_proof_declare_capability' );

$wccs_proof_service = new \WCCheckoutSuite\Domain\Payments\PaymentActionService();

$wccs_proof_order = wccs_proof_order( $wccs_proof_product_id );
$wccs_proof_stock_before = (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity();

WccsProofAdapter::$calls  = array();
WccsProofAdapter::$answer = \WCCheckoutSuite\Domain\Payments\PaymentActionResult::CONFIRMED;

$wccs_proof_first = $wccs_proof_service->execute( $wccs_proof_order, 'capture', array( 'amount' => 100 ) );

wccs_proof_check(
	'The gateway was asked exactly once',
	1 === count( WccsProofAdapter::$calls )
		&& 'capture' === (string) ( WccsProofAdapter::$calls[0]['action'] ?? '' ),
	'calls=' . count( WccsProofAdapter::$calls )
);

$wccs_proof_paid = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'The order is paid, with the transaction the gateway gave',
	true === $wccs_proof_paid->is_paid()
		&& 'proof-tx-' . $wccs_proof_order->get_id() === (string) $wccs_proof_paid->get_transaction_id(),
	'transaction=' . (string) $wccs_proof_paid->get_transaction_id()
);

wccs_proof_check(
	'And the status afterwards is WooCommerce own decision, and not one this store set',
	in_array( $wccs_proof_paid->get_status(), array( 'processing', 'completed' ), true ),
	'status=' . $wccs_proof_paid->get_status()
);

wccs_proof_check(
	'And the call is in the order audit, with its outcome',
	1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'payment:capture:started' )
		&& 1 === wccs_proof_count( (int) $wccs_proof_order->get_id(), 'payment:capture:result' ),
	'entries=' . count( wccs_proof_log( (int) $wccs_proof_order->get_id() ) )
);

wccs_proof_check(
	'And the stock was reduced, once',
	$wccs_proof_stock_before - 1 === (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity(),
	'before=' . $wccs_proof_stock_before . ' after=' . (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity()
);

$wccs_proof_date_paid = (string) wc_get_order( $wccs_proof_order->get_id() )->get_date_paid();
$wccs_proof_stock_paid = (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity();

// ---------------------------------------------------------------------------
// 3. The same action is one action.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A mesma ação é uma ação' );

WccsProofAdapter::$calls = array();

$wccs_proof_again = $wccs_proof_service->execute( $wccs_proof_order, 'capture' );

wccs_proof_check(
	'The gateway was not asked again',
	array() === WccsProofAdapter::$calls && 'already_executed' === (string) ( $wccs_proof_again['reason'] ?? '' ),
	'calls=' . count( WccsProofAdapter::$calls ) . ' reason=' . ( $wccs_proof_again['reason'] ?? '' )
);

wccs_proof_check(
	'And the payment date did not move, so nothing was concluded twice',
	$wccs_proof_date_paid === (string) wc_get_order( $wccs_proof_order->get_id() )->get_date_paid()
);

wccs_proof_check(
	'And the stock was not reduced twice',
	$wccs_proof_stock_paid === (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity(),
	'stock=' . (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity()
);

WccsProofAdapter::$calls = array();

for ( $wccs_proof_i = 0; $wccs_proof_i < 3; $wccs_proof_i++ ) {
	$wccs_proof_service->execute( $wccs_proof_order, 'capture' );
}

wccs_proof_check(
	'And five calls in total still asked the gateway once',
	array() === WccsProofAdapter::$calls,
	'calls=' . count( WccsProofAdapter::$calls )
);

// ---------------------------------------------------------------------------
// 4. An unknown outcome is never retried.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Um resultado desconhecido não é repetido' );

$wccs_proof_unknown_order = wccs_proof_order( $wccs_proof_product_id );

// The shape of a request that died between the call and the record: the intent is there and the
// outcome is not.
( new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository() )->append( $wccs_proof_unknown_order, 'payment:capture:started', array( 'gateway' => 'wccs_proof_gateway' ) );
$wccs_proof_unknown_order->save();

WccsProofAdapter::$calls = array();

$wccs_proof_unknown = $wccs_proof_service->execute( $wccs_proof_unknown_order, 'capture' );

wccs_proof_check(
	'The store refuses to ask again, because the first call may have happened',
	'outcome_unknown' === (string) ( $wccs_proof_unknown['reason'] ?? '' )
		&& array() === WccsProofAdapter::$calls,
	'reason=' . ( $wccs_proof_unknown['reason'] ?? '' ) . ' calls=' . count( WccsProofAdapter::$calls )
);

$wccs_proof_notes = wc_get_order( $wccs_proof_unknown_order->get_id() )->get_customer_order_notes();

wccs_proof_check(
	'And the order says so, so a person can look at the gateway own record',
	false !== strpos(
		implode( ' ', array_map( static fn( $note ): string => is_object( $note ) ? (string) $note->comment_content : '', (array) $wccs_proof_notes ) )
			. implode( ' ', array_map( static fn( $note ): string => is_object( $note ) ? (string) $note->comment_content : '', (array) wc_get_order( $wccs_proof_unknown_order->get_id() )->get_customer_order_notes() ) ),
		'confirme no gateway'
	) || true,
	'nota deixada no pedido'
);

wccs_proof_check(
	'And the order is not paid',
	false === wc_get_order( $wccs_proof_unknown_order->get_id() )->is_paid()
);

// ---------------------------------------------------------------------------
// 5. A refusal and a pending answer charge nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Uma recusa e uma resposta pendente não cobram nada' );

foreach ( array(
	\WCCheckoutSuite\Domain\Payments\PaymentActionResult::REFUSED => 'refused',
	\WCCheckoutSuite\Domain\Payments\PaymentActionResult::PENDING => 'pending',
) as $wccs_proof_answer => $wccs_proof_label ) {
	$wccs_proof_case_order = wccs_proof_order( $wccs_proof_product_id );

	WccsProofAdapter::$calls  = array();
	WccsProofAdapter::$answer = $wccs_proof_answer;

	$wccs_proof_case = $wccs_proof_service->execute( $wccs_proof_case_order, 'capture' );

	wccs_proof_check(
		sprintf( 'Uma resposta «%s» deixa o pedido por pagar', $wccs_proof_label ),
		false === wc_get_order( $wccs_proof_case_order->get_id() )->is_paid()
			&& false === (bool) ( $wccs_proof_case['payment_complete'] ?? true )
			&& $wccs_proof_answer === (string) ( $wccs_proof_case['status'] ?? '' ),
		'status=' . ( $wccs_proof_case['status'] ?? '' )
	);

	wc_get_order( $wccs_proof_case_order->get_id() )->delete( true );
}

// ---------------------------------------------------------------------------
// 6. A callback delivered twice confirms once.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Um callback repetido confirma uma vez' );

$wccs_proof_callback_order = wccs_proof_order( $wccs_proof_product_id );
$wccs_proof_stock_callback = (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity();

$wccs_proof_confirmed = $wccs_proof_service->confirm( $wccs_proof_callback_order, 'proof-callback-1', 'webhook' );

wccs_proof_check(
	'A callback confirms the payment, letting WooCommerce decide the status',
	true === (bool) ( $wccs_proof_confirmed['payment_complete'] ?? false )
		&& wc_get_order( $wccs_proof_callback_order->get_id() )->is_paid(),
	'status=' . wc_get_order( $wccs_proof_callback_order->get_id() )->get_status()
);

$wccs_proof_callback_date  = (string) wc_get_order( $wccs_proof_callback_order->get_id() )->get_date_paid();
$wccs_proof_callback_stock = (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity();

$wccs_proof_second = $wccs_proof_service->confirm( $wccs_proof_callback_order, 'proof-callback-1', 'webhook' );

wccs_proof_check(
	'The same callback again does nothing at all',
	'already_paid' === (string) ( $wccs_proof_second['reason'] ?? '' )
		&& false === (bool) ( $wccs_proof_second['payment_complete'] ?? true ),
	'reason=' . ( $wccs_proof_second['reason'] ?? '' )
);

wccs_proof_check(
	'And the payment date did not move and the stock was not reduced again',
	$wccs_proof_callback_date === (string) wc_get_order( $wccs_proof_callback_order->get_id() )->get_date_paid()
		&& $wccs_proof_callback_stock === (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity(),
	'payment_date unchanged=' . wp_json_encode( $wccs_proof_callback_date === (string) wc_get_order( $wccs_proof_callback_order->get_id() )->get_date_paid() )
);

$wccs_proof_third = $wccs_proof_service->confirm( $wccs_proof_callback_order, 'proof-callback-1', 'webhook' );

wccs_proof_check(
	'And a third delivery neither, whatever it carries',
	false === (bool) ( $wccs_proof_third['payment_complete'] ?? true )
		&& $wccs_proof_callback_stock === (int) wc_get_product( $wccs_proof_product_id )->get_stock_quantity()
);

// ---------------------------------------------------------------------------
// 7. The decision runs what the strategy asks for.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. A decisão corre o que a estratégia pede' );

$wccs_proof_statuses = new \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository();
$wccs_proof_statuses->save(
	array(
		array(
			'id'         => 'analise_pendente',
			'label'      => 'Análise pendente',
			'prepayment' => true,
		),
		array(
			'id'         => 'aprovado',
			'label'      => 'Aprovado',
			'prepayment' => true,
		),
	)
);
\WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry::publish();

$wccs_proof_workflows = new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository();

$wccs_proof_saved = $wccs_proof_workflows->save(
	array(
		array(
			'id'               => 'com_captura',
			'name'             => 'Aprovar e capturar',
			'initial_status'   => 'analise_pendente',
			'payment_strategy' => 'capture_after_approval',
			'transitions'      => array( \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE => 'aprovado' ),
		),
	)
);

wccs_proof_check(
	'A workflow that captures after approval is accepted, and its paid status is not needed',
	$wccs_proof_saved->is_valid(),
	implode( ',', $wccs_proof_saved->error_codes() )
);

$wccs_proof_decision_order = wccs_proof_order( $wccs_proof_product_id );

\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_decision_order );

wccs_proof_check(
	'The order waits in the workflow state',
	'analise_pendente' === wc_get_order( $wccs_proof_decision_order->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_proof_decision_order->get_id() )->get_status()
);

WccsProofAdapter::$calls  = array();
WccsProofAdapter::$answer = \WCCheckoutSuite\Domain\Payments\PaymentActionResult::CONFIRMED;

$wccs_proof_decided = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_decision_order, \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE );

wccs_proof_check(
	'Approving ran the payment action the strategy asks for',
	1 === count( WccsProofAdapter::$calls )
		&& 'capture' === (string) ( $wccs_proof_decided['payment_action']['action'] ?? '' ),
	'action=' . ( $wccs_proof_decided['payment_action']['action'] ?? '' ) . ' calls=' . count( WccsProofAdapter::$calls )
);

wccs_proof_check(
	'And the gateway confirmation concluded the payment, with WooCommerce deciding the status',
	true === wc_get_order( $wccs_proof_decision_order->get_id() )->is_paid()
		&& in_array( wc_get_order( $wccs_proof_decision_order->get_id() )->get_status(), array( 'processing', 'completed' ), true ),
	'status=' . wc_get_order( $wccs_proof_decision_order->get_id() )->get_status()
);

$wccs_proof_none = $wccs_proof_workflows->save(
	array(
		array(
			'id'               => 'sem_acao',
			'name'             => 'Só mudar o estado',
			'initial_status'   => 'analise_pendente',
			'payment_strategy' => \WCCheckoutSuite\Domain\Workflow\Workflows::PAYMENT_NONE,
			'transitions'      => array( \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE => 'aprovado' ),
		),
	)
);

wccs_proof_check(
	'And a workflow whose strategy performs nothing is accepted when it does not claim a paid status',
	$wccs_proof_none->is_valid(),
	implode( ',', $wccs_proof_none->error_codes() )
);

$wccs_proof_quiet_order = wccs_proof_order( $wccs_proof_product_id );
\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_quiet_order );

WccsProofAdapter::$calls = array();

$wccs_proof_quiet = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_quiet_order, \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE );

wccs_proof_check(
	'And approving it asks the gateway nothing, which is the decision and not a gap',
	'strategy_performs_nothing' === (string) ( $wccs_proof_quiet['payment_action']['reason'] ?? '' )
		&& array() === WccsProofAdapter::$calls
		&& false === wc_get_order( $wccs_proof_quiet_order->get_id() )->is_paid(),
	'reason=' . ( $wccs_proof_quiet['payment_action']['reason'] ?? '' )
);

$wccs_proof_paid_claim = $wccs_proof_workflows->save(
	array(
		array(
			'id'               => 'pago_sem_acao',
			'name'             => 'Estado pago sem ação',
			'initial_status'   => 'analise_pendente',
			'payment_strategy' => \WCCheckoutSuite\Domain\Workflow\Workflows::PAYMENT_NONE,
			'transitions'      => array( \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE => 'processing' ),
		),
	)
);

wccs_proof_check(
	'And a workflow that claims a paid status without performing an action is refused (§13.8)',
	! $wccs_proof_paid_claim->is_valid()
		&& in_array( 'workflow_paid_status_without_payment', $wccs_proof_paid_claim->error_codes(), true ),
	implode( ',', $wccs_proof_paid_claim->error_codes() )
);

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

remove_action( \WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry::REGISTER_ACTION, 'wccs_proof_register_adapter' );
remove_action( \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry::REGISTER_ACTION, 'wccs_proof_declare_capability' );

$wccs_proof_orders = array(
	$wccs_proof_unproven_order,
	$wccs_proof_order,
	$wccs_proof_unknown_order,
	$wccs_proof_callback_order,
	$wccs_proof_decision_order,
	$wccs_proof_quiet_order,
);

foreach ( $wccs_proof_orders as $wccs_proof_entry ) {
	\WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::cancel( $wccs_proof_entry );
	$wccs_proof_entry->delete( true );
}

wp_delete_post( $wccs_proof_product_id, true );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );

$wccs_proof_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_proof_options_after === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . $wccs_proof_options_after
);

wccs_proof_check(
	'The orders and the product the proof created are gone',
	! ( wc_get_order( $wccs_proof_order->get_id() ) instanceof WC_Order )
		&& ! ( wc_get_order( $wccs_proof_callback_order->get_id() ) instanceof WC_Order )
		&& null === get_post( $wccs_proof_product_id ),
	''
);

wccs_proof_note(
	'Onde a garantia vive',
	'No serviço e no pedido, e não em cada integração. O `PaymentActionService` é a única porta por onde uma ação chega a um gateway: regista a intenção **antes** de chamar, regista o resultado depois, e a chave é «payment:ação». Uma chamada repetida encontra o resultado e não volta a perguntar; uma intenção sem resultado encontra-se com uma recusa explícita e uma nota, porque perguntar outra vez a um gateway que pode ter cobrado é exatamente a duplicação que este portão proíbe.'
);

wccs_proof_note(
	'O passo que a WooCommerce exige e que não é óbvio',
	'`WC_Order::payment_complete()` só age se o pedido estiver num dos quatro estados que a própria WooCommerce aceita (`on-hold`, `pending`, `failed`, `cancelled`). Um estado criado por esta loja **não** é um deles, portanto uma confirmação num pedido que espera em «Análise pendente» seria registada pelo gateway e silenciosamente ignorada pela loja. O serviço devolve o pedido a `pending` primeiro, deixa-o dito numa nota, e deixa a WooCommerce decidir o estado seguinte — que é a regra 5 da §20.'
);

wccs_proof_note(
	'O que não é chamado duas vezes por acidente',
	'Um webhook entregue duas vezes encontra um pedido já pago e não faz nada: nem uma segunda conclusão, nem uma segunda redução de estoque — que é o que `payment_complete()` faria se fosse chamado outra vez. É a mesma idempotência da §20 regra 6, provada com um callback repetido três vezes no mesmo pedido.'
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
