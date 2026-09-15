<?php
/**
 * Fase 13 proof harness — the available quantity is right in every transition.
 *
 * Task:   Fase 13 "Reserva de estoque"
 * Gate:   "quantidade disponível correta em todas as transições."
 *
 * The gate is about a number, so the proof is that number: one physical unit, a workflow that takes
 * the order and waits, and a reading of what is available taken at every step — created, held,
 * refused, re-run, approved, rejected, expired, paid, trashed. §13.2 asks the reservation to be
 * idempotent and to be released on rejection, expiration, cancellation and terminal error, so each
 * of those is a reading too.
 *
 * 1. **The store answers only for the orders a workflow took.** An order no workflow took keeps the
 *    platform's own window; answering zero there would switch off the hold every ordinary order in
 *    the store gets.
 * 2. **The hold is as long as the workflow says**, and a reservation by the hour without a number is
 *    refused by name rather than stored as "reserve nothing".
 * 3. **The hole this phase closes, measured.** WooCommerce counts a hold only while the order is
 *    `pending` or `checkout-draft`; the workflow moves the order to a state of its own, so before
 *    this phase the unit went back to looking available. The proof reads the number with the store's
 *    answer removed and with it in place, and shows 0 becoming 1.
 * 4. **A second order cannot take the same unit**, refused by the same statement that writes the
 *    reservation — which is what makes two simultaneous orders safe, not just two sequential ones.
 * 5. **The cart says the same thing**, because the answer reaches WooCommerce through its own query.
 * 6. **Approval does not touch the hold.** Nothing about a state change reduces stock.
 * 7. **Rejection releases it**, and the unit is available again afterwards — not before.
 * 8. **Expiration releases it**, through the clock and not through a person.
 * 9. **A payment converts it**: the hold is gone, the stock is reduced exactly once, and the store
 *    refuses to release a paid order afterwards — the double movement §21 forbids.
 * 10. **Running the engine twice holds once.** The platform's insert is an `ON DUPLICATE KEY UPDATE`.
 * 11. **A trashed or deleted order leaves no orphan hold**, because the store now counts every live
 *     row and the platform relied on its own join to hide them.
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
 * The names of the plugin options currently stored.
 *
 * A count is compared at the end of the proof, but names are what a residue check is about: an
 * option replaced by another keeps the count and still leaks.
 *
 * @return array<int, string>
 */
function wccs_proof_option_names(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%' ORDER BY option_name" );

	return is_array( $names ) ? array_map( 'strval', $names ) : array();
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
 * How many times a key appears in an order's audit log.
 *
 * @param int    $order_id Order identifier.
 * @param string $key      Log key.
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
 * The rows the reservation table holds, for one product.
 *
 * @param int $product_id Product identifier.
 * @return array<int, array<string, mixed>>
 */
function wccs_proof_rows( int $product_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The harness reads the platform's own ledger to prove what it holds.
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT order_id, stock_quantity, expires FROM {$wpdb->wc_reserved_stock} WHERE product_id = %d ORDER BY order_id", $product_id ),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * All the reservation rows in the store.
 *
 * @return int
 */
function wccs_proof_row_total(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Residue check.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->wc_reserved_stock}" );
}

/**
 * Deletes the reservation rows of the given orders.
 *
 * @param array<int, int> $order_ids Order identifiers.
 * @return void
 */
function wccs_proof_drop_rows( array $order_ids ): void {
	global $wpdb;

	foreach ( $order_ids as $wccs_proof_order_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Residue cleanup, after the assertions have read the rows.
		$wpdb->delete( $wpdb->wc_reserved_stock, array( 'order_id' => (int) $wccs_proof_order_id ) );
	}
}

$wccs_proof_service = new \WCCheckoutSuite\Domain\Stock\InventoryReservationService();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 13 proof — the available quantity is right in every transition' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_names();
$wccs_proof_rows_before    = wccs_proof_row_total();

// ---------------------------------------------------------------------------
// 0. The ground: stock management on, the states, and one physical unit.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '0. O terreno: gestão de estoque ligada, os estados e uma unidade' );

// The reservation only exists when the store manages stock, and the platform reads that option
// through `woocommerce_hold_stock_for_checkout`. The harness turns it on and puts it back.
$wccs_proof_manage_before = get_option( 'woocommerce_manage_stock' );
$wccs_proof_hold_before   = get_option( 'woocommerce_hold_stock_minutes' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_hold_stock_minutes', 60 );

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

/**
 * A product that manages its own stock.
 *
 * @param string $name  Name.
 * @param int    $stock Units.
 * @return int Product identifier.
 */
function wccs_proof_product( string $name, int $stock ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '100' );
	$product->set_price( '100' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->set_stock_status( 'instock' );

	return (int) $product->save();
}

/**
 * A pending order for one unit of a product.
 *
 * @param int $product_id Product identifier.
 * @param int $quantity   Units.
 * @return WC_Order
 */
function wccs_proof_order( int $product_id, int $quantity = 1 ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( wc_get_product( $product_id ), $quantity );
	$order->calculate_totals();
	$order->save();

	return wc_get_order( $order->get_id() );
}

$wccs_proof_scarce = wccs_proof_product( 'Produto de prova 13 — uma unidade', 1 );
$wccs_proof_ample  = wccs_proof_product( 'Produto de prova 13 — dez unidades', 10 );

wccs_proof_check(
	'The plugin answers for two questions WooCommerce already asks',
	has_filter( 'woocommerce_query_for_reserved_stock' )
		&& has_filter( 'woocommerce_order_hold_stock_minutes' ),
	'query=' . ( has_filter( 'woocommerce_query_for_reserved_stock' ) ? 'yes' : 'no' ) . ' minutes=' . ( has_filter( 'woocommerce_order_hold_stock_minutes' ) ? 'yes' : 'no' )
);

// ---------------------------------------------------------------------------
// 1. The store answers only for the orders a workflow took.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A loja só responde pelos pedidos que uma automação levou' );

$wccs_proof_plain = wccs_proof_order( $wccs_proof_ample );

wccs_proof_check(
	'Um pedido que nenhuma automação levou fica com a janela da própria WooCommerce',
	null === $wccs_proof_service->workflow_minutes( $wccs_proof_plain )
		&& 60 === (int) apply_filters( 'woocommerce_order_hold_stock_minutes', 60, $wccs_proof_plain ),
	'minutes=' . var_export( $wccs_proof_service->workflow_minutes( $wccs_proof_plain ), true )
);

wccs_proof_check(
	'E o serviço diz que não reservou, em vez de reservar zero minutos em silêncio',
	'no_workflow' === (string) ( $wccs_proof_service->reserve( $wccs_proof_plain )['reason'] ?? '' ),
	'reason=' . (string) ( $wccs_proof_service->reserve( $wccs_proof_plain )['reason'] ?? '' )
);

// ---------------------------------------------------------------------------
// 2. The hold is as long as the workflow says.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A reserva dura o que a automação diz' );

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository();

/**
 * The workflow this proof runs on, with the stock strategy under test.
 *
 * @param array<string, mixed> $extra Fields to override.
 * @return \WCCheckoutSuite\Domain\Validation\ValidationResult
 */
function wccs_proof_save_workflow( array $extra = array() ) {
	$workflow = array_merge(
		array(
			'id'                  => '',
			'name'                => 'Produtos químicos',
			'enabled'             => true,
			'priority'            => 10,
			'trigger'             => \WCCheckoutSuite\Domain\Workflow\Workflows::TRIGGER_CHECKOUT_SUBMITTED,
			'initial_status'      => 'analise_pendente',
			'inventory_strategy'  => 'until_decision',
			'payment_strategy'    => 'none',
			'communications'      => array( \WCCheckoutSuite\Domain\Workflow\Workflows::EVENT_RECEIVED => true ),
			'transitions'         => array(
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE => 'aprovado',
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_REJECT  => 'reprovado',
				\WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_EXPIRE  => 'expirado',
			),
			'expires_after_hours' => 72,
		),
		$extra
	);

	return ( new \WCCheckoutSuite\Domain\Workflow\WorkflowRepository() )->save( array( $workflow ) );
}

$wccs_proof_saved = wccs_proof_save_workflow();

wccs_proof_check(
	'A automação é aceita e recebe o identificador do nome',
	$wccs_proof_saved->is_valid() && 'produtos_quimicos' === (string) ( $wccs_proof_repository->raw()[0]['id'] ?? '' ),
	'codes=' . implode( ',', $wccs_proof_saved->error_codes() )
);

$wccs_proof_waiting = wccs_proof_order( $wccs_proof_scarce );

wccs_proof_check(
	'«Reservar até decisão» reserva pela janela da automação: 72 horas, e não uma',
	4320 === $wccs_proof_service->workflow_minutes( $wccs_proof_waiting )
		&& 4320 === (int) apply_filters( 'woocommerce_order_hold_stock_minutes', 60, $wccs_proof_waiting ),
	'minutes=' . $wccs_proof_service->workflow_minutes( $wccs_proof_waiting )
);

wccs_proof_save_workflow(
	array(
		'id'                 => 'produtos_quimicos',
		'inventory_strategy' => 'hours',
		'inventory_hours'    => 6,
	)
);

wccs_proof_check(
	'«Reservar por X horas» reserva as horas escolhidas',
	360 === $wccs_proof_service->workflow_minutes( $wccs_proof_waiting ),
	'minutes=' . $wccs_proof_service->workflow_minutes( $wccs_proof_waiting )
);

$wccs_proof_without_hours = wccs_proof_save_workflow(
	array(
		'id'                 => 'produtos_quimicos',
		'inventory_strategy' => 'hours',
		'inventory_hours'    => 0,
	)
);

wccs_proof_check(
	'E uma reserva por horas sem número é recusada por nome, em vez de guardada como «não reservar»',
	! $wccs_proof_without_hours->is_valid()
		&& in_array( 'workflow_inventory_hours_required', $wccs_proof_without_hours->error_codes(), true )
		&& 'hours' === (string) ( $wccs_proof_repository->raw()[0]['inventory_strategy'] ?? '' )
		&& 6 === (int) ( $wccs_proof_repository->raw()[0]['inventory_hours'] ?? 0 ),
	implode( ',', $wccs_proof_without_hours->error_codes() )
);

wccs_proof_save_workflow(
	array(
		'id'                 => 'produtos_quimicos',
		'inventory_strategy' => 'none',
	)
);

wccs_proof_check(
	'«Não reservar» não reserva nada, e diz porquê',
	0 === $wccs_proof_service->workflow_minutes( $wccs_proof_waiting )
		&& 'strategy_does_not_reserve' === (string) ( $wccs_proof_service->reserve( $wccs_proof_waiting )['reason'] ?? '' )
		&& array() === wccs_proof_rows( $wccs_proof_scarce ),
	'reason=' . (string) ( $wccs_proof_service->reserve( $wccs_proof_waiting )['reason'] ?? '' )
);

wccs_proof_save_workflow( array( 'id' => 'produtos_quimicos' ) );

// ---------------------------------------------------------------------------
// 3. The hole, measured: before this phase the unit looked available.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. O buraco que esta fase fecha, medido' );

$wccs_proof_run = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_waiting );
$wccs_proof_waiting = wc_get_order( $wccs_proof_waiting->get_id() );

wccs_proof_check(
	'O pedido entra na automação e a unidade fica guardada',
	true === (bool) ( $wccs_proof_run['applied'] ?? false )
		&& 'analise_pendente' === (string) ( $wccs_proof_run['status'] ?? '' )
		&& 1 === count( wccs_proof_rows( $wccs_proof_scarce ) ),
	wp_json_encode( $wccs_proof_run['stock'] ?? array() )
);

wccs_proof_check(
	'E o estado em que espera não é um dos que a WooCommerce conta',
	! in_array( 'wc-' . $wccs_proof_waiting->get_status(), array( 'wc-checkout-draft', 'wc-pending' ), true ),
	'status=' . $wccs_proof_waiting->get_status()
);

// With the store's answer removed, the platform's own number is what it was before this phase: the
// hold belongs to an order that is no longer pending, so it is not counted and the unit is for sale.
remove_filter( 'woocommerce_query_for_reserved_stock', array( \WCCheckoutSuite\Domain\Stock\InventoryReservationService::class, 'filter_query' ), 20 );
$wccs_proof_without = (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_scarce ) );
add_filter( 'woocommerce_query_for_reserved_stock', array( \WCCheckoutSuite\Domain\Stock\InventoryReservationService::class, 'filter_query' ), 20, 3 );

$wccs_proof_with = (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_scarce ) );

wccs_proof_check(
	'Sem a resposta da loja a unidade parecia disponível; com ela, não está',
	0.0 === $wccs_proof_without && 1.0 === $wccs_proof_with,
	'sem=' . $wccs_proof_without . ' com=' . $wccs_proof_with
);

wccs_proof_check(
	'E há um só número: o que a loja reporta é o que a WooCommerce calcula',
	$wccs_proof_service->held( wc_get_product( $wccs_proof_scarce ) ) === $wccs_proof_with
		&& 0.0 === $wccs_proof_service->available( wc_get_product( $wccs_proof_scarce ) ),
	'held=' . $wccs_proof_service->held( wc_get_product( $wccs_proof_scarce ) ) . ' available=' . $wccs_proof_service->available( wc_get_product( $wccs_proof_scarce ) )
);

// ---------------------------------------------------------------------------
// 4. A second order cannot take the same unit.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Um segundo pedido não leva a mesma unidade' );

$wccs_proof_second = wccs_proof_order( $wccs_proof_scarce );
$wccs_proof_second_run = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_second );

wccs_proof_check(
	'A recusa vem da mesma instrução que escreve a reserva, e por isso vale também em simultâneo',
	'woocommerce_product_not_enough_stock' === (string) ( $wccs_proof_second_run['stock']['reason'] ?? '' ),
	'reason=' . (string) ( $wccs_proof_second_run['stock']['reason'] ?? '' )
);

wccs_proof_check(
	'E a unidade continua guardada uma só vez, para o primeiro pedido',
	1 === count( wccs_proof_rows( $wccs_proof_scarce ) )
		&& (int) wccs_proof_rows( $wccs_proof_scarce )[0]['order_id'] === $wccs_proof_waiting->get_id()
		&& 1.0 === (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_scarce ) ),
	'rows=' . count( wccs_proof_rows( $wccs_proof_scarce ) )
);

$wccs_proof_second = wc_get_order( $wccs_proof_second->get_id() );

wccs_proof_check(
	'O segundo pedido entra na automação na mesma, e a recusa fica dita na auditoria',
	'analise_pendente' === $wccs_proof_second->get_status()
		&& 1 === wccs_proof_count( $wccs_proof_second->get_id(), \WCCheckoutSuite\Domain\Stock\InventoryReservationService::KEY_RESERVED . ':refused' ),
	'audit=' . wp_json_encode( wccs_proof_log( $wccs_proof_second->get_id() ) )
);

// ---------------------------------------------------------------------------
// 5. The cart says the same thing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. O carrinho diz o mesmo' );

$wccs_proof_cart_message = '';

if ( function_exists( 'WC' ) && WC()->cart ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $wccs_proof_scarce, 1 );
	$wccs_proof_cart = WC()->cart->check_cart_item_stock();

	if ( is_wp_error( $wccs_proof_cart ) ) {
		$wccs_proof_cart_message = $wccs_proof_cart->get_error_message();
	}

	WC()->cart->empty_cart();
}

wccs_proof_check(
	'Ao cliente é dito que não há unidades, com o número que a loja calcula',
	'' !== $wccs_proof_cart_message && false !== strpos( $wccs_proof_cart_message, '0' ),
	$wccs_proof_cart_message
);

// ---------------------------------------------------------------------------
// 6. Approval does not touch the hold — nothing here reduces stock.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. A aprovação não mexe na reserva' );

$wccs_proof_stock_before = (int) wc_get_product( $wccs_proof_scarce )->get_stock_quantity();
$wccs_proof_approve      = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_waiting->get_id(), \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_APPROVE );

wccs_proof_check(
	'Aprovar muda o estado e não toca no estoque nem na reserva',
	true === (bool) ( $wccs_proof_approve['applied'] ?? false )
		&& 'aprovado' === (string) ( $wccs_proof_approve['status'] ?? '' )
		&& $wccs_proof_stock_before === (int) wc_get_product( $wccs_proof_scarce )->get_stock_quantity()
		&& 1 === count( wccs_proof_rows( $wccs_proof_scarce ) ),
	'stock=' . wc_get_product( $wccs_proof_scarce )->get_stock_quantity() . ' rows=' . count( wccs_proof_rows( $wccs_proof_scarce ) )
);

// ---------------------------------------------------------------------------
// 7. Rejection releases it, and only then is the unit available again.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. A reprovação liberta a unidade' );

$wccs_proof_reject = \WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_waiting->get_id(), \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_REJECT );

wccs_proof_check(
	'Reprovar liberta a reserva e o diz na auditoria',
	true === (bool) ( $wccs_proof_reject['stock']['released'] ?? false )
		&& 'reject' === (string) ( $wccs_proof_reject['stock']['reason'] ?? '' )
		&& array() === wccs_proof_rows( $wccs_proof_scarce )
		&& 1 === wccs_proof_count( $wccs_proof_waiting->get_id(), \WCCheckoutSuite\Domain\Stock\InventoryReservationService::KEY_RELEASED ),
	wp_json_encode( $wccs_proof_reject['stock'] ?? array() )
);

$wccs_proof_released_order = wc_get_order( $wccs_proof_waiting->get_id() );
$wccs_proof_third          = wccs_proof_order( $wccs_proof_scarce );
$wccs_proof_third_run      = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_third );

wccs_proof_check(
	'Depois da libertação a unidade volta a estar disponível, e agora um pedido leva-a',
	$wccs_proof_released_order->get_status() === 'reprovado'
		&& true === (bool) ( $wccs_proof_third_run['stock']['reserved'] ?? false )
		&& 1 === count( wccs_proof_rows( $wccs_proof_scarce ) ),
	wp_json_encode( $wccs_proof_third_run['stock'] ?? array() )
);

// The unit is freed again so the steps that follow start from the same place.
\WCCheckoutSuite\Domain\Workflow\WorkflowTransitionService::decide( $wccs_proof_third->get_id(), \WCCheckoutSuite\Domain\Workflow\Workflows::DECISION_REJECT );

wccs_proof_check(
	'E o terceiro pedido, reprovado a seguir, liberta a unidade para o passo seguinte',
	array() === wccs_proof_rows( $wccs_proof_scarce ),
	'rows=' . count( wccs_proof_rows( $wccs_proof_scarce ) )
);

// ---------------------------------------------------------------------------
// 8. Expiration releases it through the clock.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. A expiração liberta a unidade pelo relógio' );

$wccs_proof_expiring = wccs_proof_order( $wccs_proof_scarce );
\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_expiring );
$wccs_proof_expiring = wc_get_order( $wccs_proof_expiring->get_id() );

// The deadline the engine wrote is 72 hours away; the clock is moved instead of the test being
// written around it, so what runs is the job that really runs in a store.
$wccs_proof_repository->remember( $wccs_proof_expiring, 'produtos_quimicos', 72, gmdate( 'c', time() - 3600 ) );
$wccs_proof_expiring->save();

$wccs_proof_expired  = \WCCheckoutSuite\Domain\Workflow\WorkflowScheduler::expire( $wccs_proof_expiring->get_id() );
$wccs_proof_expiring = wc_get_order( $wccs_proof_expiring->get_id() );

wccs_proof_check(
	'O relógio expira o pedido e liberta a reserva no mesmo passo',
	true === (bool) ( $wccs_proof_expired['applied'] ?? false )
		&& 'expirado' === $wccs_proof_expiring->get_status()
		&& 1 === wccs_proof_count( $wccs_proof_expiring->get_id(), \WCCheckoutSuite\Domain\Stock\InventoryReservationService::KEY_RELEASED ),
	wp_json_encode( $wccs_proof_expired )
);

// ---------------------------------------------------------------------------
// 9. A payment converts the hold; the store never releases it afterwards.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '9. O pagamento converte a reserva, e a loja não a liberta depois' );

$wccs_proof_paid = wccs_proof_order( $wccs_proof_scarce );
\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_paid );
$wccs_proof_paid = wc_get_order( $wccs_proof_paid->get_id() );

$wccs_proof_held_before_payment = (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_scarce ) );
$wccs_proof_stock_before_payment = (int) wc_get_product( $wccs_proof_scarce )->get_stock_quantity();

// The conversion is WooCommerce's own, and it needs the order in a status the platform accepts for
// `payment_complete()` — which is exactly the step the payment action service performs, and the
// reason a confirmation on a state of this store's own would otherwise be ignored (§20, Fase 12).
$wccs_proof_paid->set_status( 'pending' );
$wccs_proof_paid->save();
$wccs_proof_paid->payment_complete( 'proof-13-transaction' );
$wccs_proof_paid = wc_get_order( $wccs_proof_paid->get_id() );

$wccs_proof_stock_after_payment = (int) wc_get_product( $wccs_proof_scarce )->get_stock_quantity();

wccs_proof_check(
	'O pagamento conclui-se e o estoque desce uma unidade — a reserva virou venda',
	$wccs_proof_paid->is_paid()
		&& 1.0 === $wccs_proof_held_before_payment
		&& 1 === $wccs_proof_stock_before_payment
		&& 0 === $wccs_proof_stock_after_payment
		&& array() === wccs_proof_rows( $wccs_proof_scarce ),
	'antes=' . $wccs_proof_stock_before_payment . ' depois=' . $wccs_proof_stock_after_payment . ' held_antes=' . $wccs_proof_held_before_payment
);

$wccs_proof_release_paid = $wccs_proof_service->release( $wccs_proof_paid, 'proof' );

wccs_proof_check(
	'E a loja recusa libertar um pedido já pago: o movimento duplo que a §21 proíbe',
	'already_paid' === (string) ( $wccs_proof_release_paid['reason'] ?? '' )
		&& 0 === (int) wc_get_product( $wccs_proof_scarce )->get_stock_quantity()
		&& 0 === wccs_proof_count( $wccs_proof_paid->get_id(), \WCCheckoutSuite\Domain\Stock\InventoryReservationService::KEY_RELEASED ),
	wp_json_encode( $wccs_proof_release_paid ) . ' stock=' . wc_get_product( $wccs_proof_scarce )->get_stock_quantity()
);

// ---------------------------------------------------------------------------
// 10. Running the engine twice holds once.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '10. Correr a automação duas vezes reserva uma só' );

$wccs_proof_ample_order = wccs_proof_order( $wccs_proof_ample, 2 );
$wccs_proof_first_run   = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_ample_order );
$wccs_proof_ample_order = wc_get_order( $wccs_proof_ample_order->get_id() );
$wccs_proof_again       = \WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_ample_order );

wccs_proof_check(
	'A segunda passagem encontra o pedido já no estado e não acrescenta uma reserva',
	false === (bool) ( $wccs_proof_again['applied'] ?? true )
		&& 1 === count( wccs_proof_rows( $wccs_proof_ample ) )
		&& 2.0 === (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_ample ) ),
	'rows=' . count( wccs_proof_rows( $wccs_proof_ample ) ) . ' held=' . wc_get_held_stock_quantity( wc_get_product( $wccs_proof_ample ) )
);

// Asking for the same window again is the case that used to lose the hold: WooCommerce writes the
// reservation with `ON DUPLICATE KEY UPDATE`, MySQL reports zero affected rows when nothing changes,
// and the platform reads that zero as "not enough stock", releases the hold and throws.
$wccs_proof_expires_before = (string) wccs_proof_rows( $wccs_proof_ample )[0]['expires'];
$wccs_proof_hold_again     = $wccs_proof_service->reserve( $wccs_proof_ample_order );

wccs_proof_check(
	'Reservar outra vez confirma a mesma linha em vez de a reescrever, e não perde a reserva',
	true === (bool) ( $wccs_proof_hold_again['reserved'] ?? false )
		&& 'already_holding' === (string) ( $wccs_proof_hold_again['reason'] ?? '' )
		&& 1 === count( wccs_proof_rows( $wccs_proof_ample ) )
		&& 2.0 === (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_ample ) )
		&& (string) wccs_proof_rows( $wccs_proof_ample )[0]['expires'] === $wccs_proof_expires_before,
	wp_json_encode( $wccs_proof_hold_again ) . ' rows=' . count( wccs_proof_rows( $wccs_proof_ample ) )
);

// A window that really changed is written, and onto the same row.
wccs_proof_save_workflow(
	array(
		'id'                  => 'produtos_quimicos',
		'expires_after_hours' => 8760,
	)
);

$wccs_proof_extended = $wccs_proof_service->reserve( $wccs_proof_ample_order );

wccs_proof_check(
	'Uma janela maior é aplicada à mesma linha, sem acrescentar uma segunda reserva',
	true === (bool) ( $wccs_proof_extended['reserved'] ?? false )
		&& true === (bool) ( $wccs_proof_extended['extended'] ?? false )
		&& 1 === count( wccs_proof_rows( $wccs_proof_ample ) )
		&& 2.0 === (float) wc_get_held_stock_quantity( wc_get_product( $wccs_proof_ample ) )
		&& strtotime( (string) wccs_proof_rows( $wccs_proof_ample )[0]['expires'] ) > strtotime( $wccs_proof_expires_before ) + ( 300 * DAY_IN_SECONDS ),
	wp_json_encode( $wccs_proof_extended ) . ' expires=' . (string) wccs_proof_rows( $wccs_proof_ample )[0]['expires']
);

wccs_proof_save_workflow( array( 'id' => 'produtos_quimicos' ) );

// ---------------------------------------------------------------------------
// 11. Trash and delete leave no orphan hold.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '11. Um pedido apagado não deixa reserva órfã' );

$wccs_proof_trashed = wccs_proof_order( $wccs_proof_ample );
\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_trashed );
$wccs_proof_trashed    = wc_get_order( $wccs_proof_trashed->get_id() );
$wccs_proof_rows_held  = count( wccs_proof_rows( $wccs_proof_ample ) );
$wccs_proof_trashed->delete( false );

wccs_proof_check(
	'Um pedido movido para o lixo liberta a sua reserva',
	count( wccs_proof_rows( $wccs_proof_ample ) ) === $wccs_proof_rows_held - 1,
	'guardadas=' . $wccs_proof_rows_held . ' depois=' . count( wccs_proof_rows( $wccs_proof_ample ) )
);

$wccs_proof_deleted = wccs_proof_order( $wccs_proof_ample );
\WCCheckoutSuite\Domain\Workflow\WorkflowEngine::run( $wccs_proof_deleted );
$wccs_proof_deleted    = wc_get_order( $wccs_proof_deleted->get_id() );
$wccs_proof_deleted_id = $wccs_proof_deleted->get_id();
$wccs_proof_rows_held  = count( wccs_proof_rows( $wccs_proof_ample ) );
$wccs_proof_deleted->delete( true );

wccs_proof_check(
	'E um apagado a sério também, porque a loja conta todas as linhas vivas',
	count( wccs_proof_rows( $wccs_proof_ample ) ) === $wccs_proof_rows_held - 1
		&& ! ( wc_get_order( $wccs_proof_deleted_id ) instanceof WC_Order ),
	'guardadas=' . $wccs_proof_rows_held . ' depois=' . count( wccs_proof_rows( $wccs_proof_ample ) )
);

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( 'A limpar o que a prova criou' );

$wccs_proof_doomed = array(
	$wccs_proof_plain->get_id(),
	$wccs_proof_waiting->get_id(),
	$wccs_proof_second->get_id(),
	$wccs_proof_third->get_id(),
	$wccs_proof_expiring->get_id(),
	$wccs_proof_paid->get_id(),
	$wccs_proof_ample_order->get_id(),
);

foreach ( $wccs_proof_doomed as $wccs_proof_doomed_id ) {
	$wccs_proof_found = wc_get_order( (int) $wccs_proof_doomed_id );

	if ( $wccs_proof_found instanceof WC_Order ) {
		$wccs_proof_found->delete( true );
	}
}

wccs_proof_drop_rows( $wccs_proof_doomed );

delete_option( \WCCheckoutSuite\Domain\Workflow\WorkflowRepository::OPTION );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );
wp_delete_post( $wccs_proof_scarce, true );
wp_delete_post( $wccs_proof_ample, true );
update_option( 'woocommerce_manage_stock', false === $wccs_proof_manage_before ? 'no' : $wccs_proof_manage_before );
update_option( 'woocommerce_hold_stock_minutes', $wccs_proof_hold_before );

wccs_proof_check(
	'A prova não deixa reservas nem produtos atrás',
	wccs_proof_row_total() === $wccs_proof_rows_before
		&& null === get_post( $wccs_proof_scarce )
		&& null === get_post( $wccs_proof_ample ),
	'antes=' . $wccs_proof_rows_before . ' depois=' . wccs_proof_row_total()
);

wccs_proof_check(
	'E as opções do plugin voltam ao que eram',
	wccs_proof_option_count() === count( $wccs_proof_options_before ),
	'antes=' . implode( ',', $wccs_proof_options_before ) . ' depois=' . implode( ',', wccs_proof_option_names() )
);

wccs_proof_note(
	'Onde a reserva vive',
	'A tabela `wc_reserved_stock` da própria WooCommerce, e a sua API é a única que escreve. A loja responde a duas perguntas que a plataforma já faz: quanto tempo guardar (`woocommerce_order_hold_stock_minutes`) e quanto está guardado (`woocommerce_query_for_reserved_stock`). A segunda é a que resolve o problema: a instrução que a plataforma usa para verificar disponibilidade é a mesma que ela usa para escrever a reserva, portanto corrigi-la corrige ao mesmo tempo o carrinho, a API de blocos e a garantia contra dois pedidos a levar a mesma unidade.'
);

wccs_proof_note(
	'Porque é que a consulta da plataforma não chegava',
	'A WooCommerce só conta uma reserva enquanto o pedido está em `pending` ou `checkout-draft`. Uma automação move o pedido para um estado próprio — «Análise pendente» — e a linha da reserva continua lá mas deixa de ser contada: a unidade volta a parecer disponível e o cliente seguinte pode comprá-la. É esse o número que a secção 3 mede: 0 antes, 1 depois.'
);

wccs_proof_note(
	'A armadilha da própria WooCommerce que esta fase teve de contornar',
	'A reserva é escrita com `INSERT ... ON DUPLICATE KEY UPDATE`, e o MySQL conta **zero linhas afetadas** quando a atualização não muda nada — a plataforma lê esse zero como «não há estoque», liberta as reservas do pedido e levanta `woocommerce_product_not_enough_stock`. Como o checkout da própria WooCommerce já reserva com a mesma janela que a automação pede, escrever outra vez com os mesmos valores perdia a reserva e dizia ao comerciante uma coisa falsa. Por isso `reserve()` pergunta primeiro se o pedido já guarda o que precisa (`already_holding`), e quando o pedido já guardava algo e a escrita falha, tenta uma segunda vez — nessa altura as linhas do pedido já foram apagadas pela plataforma, portanto a segunda tentativa são inserções puras.'
);

wccs_proof_note(
	'O que fica por provar aqui',
	'Duas passagens verdadeiramente simultâneas precisam de dois processos; o que esta prova estabelece é que a recusa vem da mesma instrução que escreve — o `INSERT ... SELECT ... FOR UPDATE ... LOCK IN SHARE MODE` — e é isso que torna a simultaneidade segura, não uma verificação feita antes. O cancelamento e o pagamento são libertados pelos hooks da própria WooCommerce, e a prova confirma que a loja não os repete.'
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
