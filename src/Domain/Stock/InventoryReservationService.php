<?php
/**
 * Holding stock while an order waits.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Stock;

use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStockException;
use WC_Order;
use WC_Product;
use WCCheckoutSuite\Domain\Workflow\WorkflowDefinition;
use WCCheckoutSuite\Domain\Workflow\WorkflowEngine;
use WCCheckoutSuite\Domain\Workflow\WorkflowEvaluator;
use WCCheckoutSuite\Domain\Workflow\WorkflowRepository;
use WCCheckoutSuite\Domain\Workflow\Workflows;

/**
 * The stock reservation of §21, built on the platform's own reservation.
 *
 * **The spike first, because it changed the design.** WooCommerce 4.1+ already reserves stock for an
 * order at checkout: `wc_reserve_stock_for_order()` writes a row into `{prefix}wc_reserved_stock`
 * with an expiry, availability is `stock − held`, and two orders competing for the last unit are
 * serialised by the pessimistic insert that does the arithmetic and the write in one statement. So
 * this phase does not need a second ledger: §21's "nunca atualizar estoque via SQL direto" is not an
 * obstacle here but the obvious design — the platform's rows are the ledger and its API is the only
 * writer.
 *
 * The spike also found the hole that shapes the rest. `ReserveStock::get_query_for_reserved_stock()`
 * counts a hold **only while the order is `wc-pending` or `wc-checkout-draft`**:
 *
 * ```sql
 * WHERE orders.status IN ( 'wc-checkout-draft', 'wc-pending' ) AND expires > NOW()
 * ```
 *
 * A workflow that moves an order to `análise pendente` therefore keeps its row and stops being
 * counted: the unit looks available again and the next customer can buy it. That is the overselling
 * this phase exists to prevent, and moving the order back to `pending` is not a fix — the whole point
 * of the workflow is that the customer sees a different state.
 *
 * **One value, one authority (ADR-0001), so the fix is to feed the platform's number rather than to
 * compete with it.** `woocommerce_query_for_reserved_stock` is the filter the platform applies to
 * that query — and `get_query_for_reserved_stock()` is used by *both* readers of the number:
 * `get_reserved_stock()`, which the cart, the Store API, the quantity limits and
 * `wc_get_held_stock_quantity()` all ask, and the availability clause of
 * `reserve_stock_for_product()`'s insert, which is the actual guard against two orders taking the
 * same unit. Answering that one query answers every question at once, including under concurrency.
 *
 * What the filter returns is the same query **minus the status clause**, and the reasoning is short:
 * the platform excludes holds whose order is no longer pending because for it such a hold is either
 * converted into a real reduction or was released — and in both of those cases the row is *deleted*
 * (`woocommerce_payment_complete`, `woocommerce_order_status_cancelled`,
 * `woocommerce_order_status_completed` all release it). A row that still exists and has not expired
 * is therefore a live hold whatever state its order is in, which is exactly what §21 asks the store
 * to keep. Dropping the join that carried the clause is the whole of the change.
 *
 * The store therefore answers no availability question of its own: `held()` and `available()` below
 * read the platform's number, and exist so screens and diagnostics can report it. Release happens
 * **once**, except that a paid order is never released: after payment the platform has already
 * converted the hold and reduced the real stock, and touching it again would be the double movement
 * §21 forbids.
 *
 * **The second finding of the spike, and the reason `reserve()` checks before it writes.**
 * WooCommerce 11.1.0 writes the hold with `INSERT … ON DUPLICATE KEY UPDATE expires, stock_quantity`
 * and treats a falsy return as a failure — but MySQL reports **zero affected rows for an
 * `ON DUPLICATE KEY UPDATE` that changes nothing**, and zero is falsy. Asking for the same window
 * twice inside the same second therefore raises `woocommerce_product_not_enough_stock`, and the
 * platform's own `catch` *releases the order's holds* before rethrowing. A store whose checkout
 * already holds stock and whose workflow then asks for the same window would lose the hold and read
 * a false refusal.
 *
 * So `reserve()` first asks whether the order already holds what it needs for as long as it needs
 * (`already_holds()`), which is §13.2's "toda reserva precisa ser idempotente" with a concrete
 * reason behind it, and only writes when something is actually missing. When the order did hold
 * something and the write still failed, it retries once: the platform's catch has already deleted the
 * rows, so the retry is a set of plain inserts and cannot repeat the trap — and a genuine shortage
 * fails the retry too, which is why the retry can never invent a hold.
 *
 * @see \ROADMAP.md section 21
 */
final class InventoryReservationService {

	/**
	 * Log key recording that stock was held for an order.
	 */
	public const KEY_RESERVED = 'stock:reserved';

	/**
	 * Log key recording that a hold was released.
	 */
	public const KEY_RELEASED = 'stock:released';

	/**
	 * How long a strategy with no number of its own holds stock.
	 *
	 * A workflow that says "reserve until a decision" and gives no hours is read as holding for the
	 * platform's own default window, which is what a store that never configured one already gets.
	 */
	public const DEFAULT_MINUTES = 60;

	/**
	 * Constructor.
	 *
	 * @param WorkflowRepository|null $log The order's audit log.
	 */
	public function __construct( private ?WorkflowRepository $log = null ) {
		$this->log = $log ?? new WorkflowRepository();
	}

	/**
	 * Registers the two filters the reservation is answered through.
	 *
	 * Two, and both are questions WooCommerce already asks: how long to hold for an order, and how
	 * much of a product is being held. The second is the one that matters — it is what the cart, the
	 * Store API and the platform's own insert read.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_order_hold_stock_minutes', array( self::class, 'filter_hold_minutes' ), 20, 2 );
		add_filter( 'woocommerce_query_for_reserved_stock', array( self::class, 'filter_query' ), 20, 3 );
		// Two lifecycle events the platform does not release on, and the reason it gets away with
		// that: its own query joins the orders table, so a trashed or deleted order's row stops being
		// counted by accident. Ours counts every live row on purpose (see the class docblock), so it
		// has to delete these rows itself — otherwise a deleted order would hold a unit until the
		// workflow's window ran out, which for a 72-hour wait is three days of a blocked product.
		add_action( 'woocommerce_trash_order', array( self::class, 'release_orphan' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'release_orphan' ), 10, 2 );
	}

	/**
	 * Deletes the hold of an order that is being trashed or deleted.
	 *
	 * Not routed through `release()`: that method refuses a paid order and records a decision in the
	 * audit, and neither applies — this is not a decision about an order, it is the order ceasing to
	 * exist, and its audit goes with it. What it must do is leave no row counting against stock.
	 *
	 * @param mixed      $order_id Order identifier.
	 * @param mixed|null $order    Order, when the event offers one.
	 * @return void
	 */
	public static function release_orphan( $order_id, $order = null ): void {
		$found = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $found instanceof WC_Order ) {
			return;
		}

		if ( function_exists( 'wc_release_stock_for_order' ) ) {
			wc_release_stock_for_order( $found );
		}
	}

	/**
	 * How long stock should be held for an order, in minutes, or null when no workflow took it.
	 *
	 * Read from the workflow that takes the order: `não reservar` is zero, which WooCommerce reads as
	 * "do not reserve at all", and a workflow that waits 72 hours reserves for 72 hours rather than
	 * for the platform's one hour — the difference between holding a unit for a decision and losing it
	 * to the next customer while the decision is being made.
	 *
	 * Null is not the same answer as zero, and the difference is the whole of this method: an order
	 * no workflow took is none of this plugin's business, and the platform's own answer stands.
	 * Answering zero there would switch off the hold WooCommerce takes for every ordinary order in
	 * the store — a regression dressed as a reservation.
	 *
	 * @param WC_Order $order Order.
	 * @return int|null
	 */
	public function workflow_minutes( WC_Order $order ): ?int {
		$workflow = $this->workflow_for( $order );

		if ( null === $workflow ) {
			return null;
		}

		$strategy = $workflow->inventory_strategy();

		if ( Workflows::INVENTORY_NONE === $strategy || ! Workflows::can_execute( 'inventory', $strategy ) ) {
			return 0;
		}

		if ( 'hours' === $strategy ) {
			return max( 0, $workflow->inventory_hours() ) * 60;
		}

		// `until_decision`: the workflow's own clock when it has one, and the platform's default
		// window when it does not.
		return $workflow->expires() ? $workflow->expires_after_hours() * 60 : self::DEFAULT_MINUTES;
	}

	/**
	 * How long this plugin holds stock for an order, in minutes; zero when it holds nothing.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public function hold_minutes( WC_Order $order ): int {
		return $this->workflow_minutes( $order ) ?? 0;
	}

	/**
	 * Filter callback for the platform's hold duration.
	 *
	 * An order with no workflow keeps the platform's own number, because the store has no opinion
	 * about it — see `workflow_minutes()`.
	 *
	 * @param mixed $minutes Minutes the platform was going to hold for.
	 * @param mixed $order   Order.
	 * @return int
	 */
	public static function filter_hold_minutes( $minutes, $order ): int {
		$found = WorkflowEngine::order( $order );

		if ( null === $found ) {
			return (int) $minutes;
		}

		return ( new self() )->workflow_minutes( $found ) ?? (int) $minutes;
	}

	/**
	 * The query that answers how much of a product is being held.
	 *
	 * The platform's own query with the order-status clause removed, for the reason the class
	 * documents at length: a reservation row that still exists and has not expired is a live hold,
	 * because the platform deletes the row in every case where it would not be one. Returning our
	 * text rather than editing theirs is deliberate — there is no pattern to match, so this cannot
	 * silently stop working when WooCommerce rewords its SQL. The clause that is removed is the only
	 * use of the join, so the join goes with it.
	 *
	 * The result is interpolated into the platform's prepared statements on both paths, so the two
	 * values are substituted here through `prepare()` exactly as the platform substitutes them: the
	 * integers are the filter's own arguments and are never user input.
	 *
	 * @param string $query            The platform's query, unused by design.
	 * @param mixed  $product_id       Product whose holds are being counted.
	 * @param mixed  $exclude_order_id Order whose own hold is left out of the count.
	 * @return string
	 */
	public static function filter_query( $query, $product_id, $exclude_order_id = 0 ): string {
		/**
		 * The WordPress database handle, typed for static analysis.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$original = (string) $query;

		if ( ! isset( $wpdb->wc_reserved_stock ) || '' === (string) $wpdb->wc_reserved_stock ) {
			// Before the platform has defined its tables, which is the one moment its query is the
			// only thing that can run.
			return $original;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the platform's own query interpolates this table the same way, and the values below go through prepare().
		$sql = $wpdb->prepare(
			"
			SELECT COALESCE( SUM( stock_table.`stock_quantity` ), 0 ) FROM {$wpdb->wc_reserved_stock} stock_table
			WHERE stock_table.`expires` > NOW()
			AND stock_table.`product_id` = %d
			AND stock_table.`order_id` != %d
			",
			(int) $product_id,
			(int) $exclude_order_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (string) $sql;
	}

	/**
	 * Holds stock for an order, once, for the length its workflow asks for.
	 *
	 * The platform's own `ReserveStock` does the work: it does the availability arithmetic and the
	 * insert in one pessimistic statement, so a second order cannot take a unit this one is holding.
	 * What this method adds around it is the idempotency — it asks first whether the order already
	 * holds what it needs (`already_holds()`), and only writes what is missing. That is not a
	 * refinement: rewriting a hold the platform took with identical values raises a false refusal and
	 * makes the platform delete the hold, which the class docblock describes in full.
	 *
	 * When there is not enough stock the platform refuses and this says so; the order still enters
	 * the workflow, and the merchant learns from the note.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed> Report.
	 */
	public function reserve( WC_Order $order ): array {
		$read     = $this->workflow_minutes( $order );
		$minutes  = $read ?? self::DEFAULT_MINUTES;
		$order_id = (int) $order->get_id();

		if ( null === $read ) {
			return array(
				'order'    => $order_id,
				'reserved' => false,
				'minutes'  => $minutes,
				'reason'   => 'no_workflow',
			);
		}

		if ( $minutes <= 0 ) {
			return array(
				'order'    => $order_id,
				'reserved' => false,
				'minutes'  => 0,
				'reason'   => 'strategy_does_not_reserve',
			);
		}

		if ( array() === $this->held_quantities( $order ) ) {
			return array(
				'order'    => $order_id,
				'reserved' => false,
				'minutes'  => $minutes,
				'reason'   => 'nothing_to_hold',
			);
		}

		if ( $this->already_holds( $order, $minutes ) ) {
			// §13.2's idempotency, and the guard against the platform's false refusal: the hold is
			// already there, so there is nothing to write and no reason to ask for it again.
			return array(
				'order'    => $order_id,
				'reserved' => true,
				'minutes'  => $minutes,
				'extended' => true,
				'reason'   => 'already_holding',
			);
		}

		if ( ! class_exists( ReserveStock::class ) ) {
			return array(
				'order'    => $order_id,
				'reserved' => false,
				'minutes'  => $minutes,
				'reason'   => 'platform_has_no_reservation',
			);
		}

		$before = $this->log->has( $order_id, self::KEY_RESERVED );
		$rows   = $this->hold_rows( $order );
		$stock  = new ReserveStock();

		try {
			// The platform's API, with the window this order needs. Nothing here writes SQL, and
			// nothing here decides availability: the platform's insert both decides and serialises.
			$stock->reserve_stock_for_order( $order, $minutes );
		} catch ( ReserveStockException $exception ) {
			// The order held something before this call, so the refusal may be the trap rather than
			// a shortage: `reserve_stock_for_order()` has already deleted those rows, which makes a
			// second attempt a set of plain inserts. A real shortage fails it too.
			if ( array() !== $rows && 'woocommerce_product_not_enough_stock' === (string) $exception->getErrorCode() ) {
				try {
					$stock->reserve_stock_for_order( $order, $minutes );

					return $this->record( $order, $minutes, $before, 'rewritten' );
				} catch ( ReserveStockException $again ) {
					$exception = $again;
				}
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: what the platform said */
					__( 'Stock could not be held for this order: %s', 'wc-checkoutsuite' ),
					$exception->getMessage()
				)
			);

			$this->log->append(
				$order,
				self::KEY_RESERVED . ':refused',
				array(
					'minutes' => $minutes,
					'reason'  => (string) $exception->getErrorCode(),
				)
			);
			$order->save();

			return array(
				'order'    => $order_id,
				'reserved' => false,
				'minutes'  => $minutes,
				'reason'   => (string) $exception->getErrorCode(),
			);
		}

		return $this->record( $order, $minutes, $before, 'held' );
	}

	/**
	 * Writes the audit entry for a hold that was taken, and reports it.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $minutes Minutes held for.
	 * @param bool     $before  Whether the order already had a hold recorded.
	 * @param string   $how     What the audit should say happened.
	 * @return array<string, mixed> Report.
	 */
	private function record( WC_Order $order, int $minutes, bool $before, string $how ): array {
		$this->log->append(
			$order,
			self::KEY_RESERVED,
			array(
				'minutes' => $minutes,
				'how'     => $how,
				// Recorded so the audit says what was held and for how long, without reading the
				// platform's table on every screen that shows an order.
				'held'    => $this->held_quantities( $order ),
			)
		);
		$order->save();

		return array(
			'order'    => (int) $order->get_id(),
			'reserved' => true,
			'minutes'  => $minutes,
			// A second call extends or confirms the same row, it never adds one.
			'extended' => $before,
			'reason'   => $how,
		);
	}

	/**
	 * The reservation rows an order has, keyed by product.
	 *
	 * Read straight from the platform's table because that is the ledger, and read with the remaining
	 * minutes computed by the database so the comparison to the wanted window never mixes two clocks.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	private function hold_rows( WC_Order $order ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The platform's ledger is the only place a hold exists, and nothing caches it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, stock_quantity, TIMESTAMPDIFF( MINUTE, NOW(), expires ) AS remaining FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d",
				(int) $order->get_id()
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$held = array();

		foreach ( $rows as $row ) {
			$held[ (int) $row['product_id'] ] = $row;
		}

		return $held;
	}

	/**
	 * Whether the order already holds every unit it needs, for as long as it needs.
	 *
	 * The window is compared with a minute of tolerance on purpose: the hold the platform takes at
	 * checkout was written seconds ago with the same window this workflow asks for, and rewriting it
	 * with identical values is the trap the class docblock describes. A tolerance of a minute means
	 * the store only writes when the hold is genuinely short.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $minutes Minutes the hold should last.
	 * @return bool
	 */
	private function already_holds( WC_Order $order, int $minutes ): bool {
		$needed = $this->held_quantities( $order );
		$rows   = $this->hold_rows( $order );

		if ( count( $rows ) < count( $needed ) ) {
			return false;
		}

		$floor = max( 1, $minutes - 1 );

		foreach ( $needed as $product_id => $quantity ) {
			$row = $rows[ $product_id ] ?? null;

			if ( null === $row ) {
				return false;
			}

			if ( (float) $row['stock_quantity'] < $quantity ) {
				return false;
			}

			if ( (int) $row['remaining'] < $floor ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Releases the hold, unless the order is paid.
	 *
	 * §21 asks for a release on rejection, expiration, cancellation and terminal error, and forbids
	 * one after the payment is concluded. Cancellation and payment are the platform's own hooks (it
	 * releases on both, and on completion) — so what this adds is the release for the two transitions
	 * the platform has never heard of: our rejection and our expiration, plus the guard that refuses
	 * to touch a paid order at all.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $why   Why it is being released, for the audit.
	 * @return array<string, mixed> Report.
	 */
	public function release( WC_Order $order, string $why = '' ): array {
		$order_id = (int) $order->get_id();

		if ( $order->is_paid() || null !== $order->get_date_paid() ) {
			return array(
				'order'    => $order_id,
				'released' => false,
				'reason'   => 'already_paid',
			);
		}

		if ( $this->log->has( $order_id, self::KEY_RELEASED ) ) {
			return array(
				'order'    => $order_id,
				'released' => false,
				'reason'   => 'already_released',
			);
		}

		if ( function_exists( 'wc_release_stock_for_order' ) ) {
			wc_release_stock_for_order( $order );
		}

		$this->log->append(
			$order,
			self::KEY_RELEASED,
			array(
				'reason' => $why,
			)
		);
		$order->save();

		return array(
			'order'    => $order_id,
			'released' => true,
			'reason'   => $why,
		);
	}

	/**
	 * What the physical hold is for an order, per product.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, float>
	 */
	public function held_quantities( WC_Order $order ): array {
		$held = array();

		foreach ( $order->get_items() as $item ) {
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$held[ (int) $product->get_stock_managed_by_id() ] = (float) $item->get_quantity();
		}

		return $held;
	}

	/**
	 * How much of a product is being held, as the platform counts it.
	 *
	 * The platform's own accessor, deliberately: with the query filtered above, this is the same
	 * number the cart, the Store API and the insert clause use, so a screen showing it shows the
	 * truth rather than a second opinion (ADR-0001).
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public function held( WC_Product $product ): float {
		if ( ! class_exists( ReserveStock::class ) ) {
			return 0.0;
		}

		return (float) ( new ReserveStock() )->get_reserved_stock( $product );
	}

	/**
	 * What is available to sell: the stock minus the holds, never below zero.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public function available( WC_Product $product ): float {
		if ( ! $product->managing_stock() ) {
			return 0.0;
		}

		return max( 0.0, (float) $product->get_stock_quantity() - $this->held( $product ) );
	}

	/**
	 * Whether the store is holding stock for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_holding( WC_Order $order ): bool {
		if ( $this->log->has( (int) $order->get_id(), self::KEY_RELEASED ) ) {
			return false;
		}

		// The ledger, not the audit: the hold the platform takes at checkout is a hold this store
		// relies on, and it was never written by this class.
		foreach ( $this->hold_rows( $order ) as $row ) {
			if ( (int) $row['remaining'] > 0 && (float) $row['stock_quantity'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What an order's reservation is, for a screen or a diagnostic.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public function status( WC_Order $order ): array {
		$workflow = $this->workflow_for( $order );

		return array(
			'order'      => (int) $order->get_id(),
			'workflow'   => null === $workflow ? '' : $workflow->id(),
			'strategy'   => null === $workflow ? '' : $workflow->inventory_strategy(),
			'minutes'    => $this->hold_minutes( $order ),
			'holding'    => $this->is_holding( $order ),
			'released'   => $this->log->has( (int) $order->get_id(), self::KEY_RELEASED ),
			'paid'       => $order->is_paid(),
			'quantities' => $this->held_quantities( $order ),
		);
	}

	/**
	 * The workflow that took an order, or null.
	 *
	 * Read from the order's own state first — that is the workflow that actually ran — and resolved
	 * from the rules only when the order has not entered one yet, which is the moment the platform
	 * asks how long to hold for.
	 *
	 * @param WC_Order $order Order.
	 * @return WorkflowDefinition|null
	 */
	private function workflow_for( WC_Order $order ): ?WorkflowDefinition {
		$repository = new WorkflowRepository();
		$state      = $repository->state( (int) $order->get_id() );
		$id         = (string) ( $state['workflow'] ?? '' );

		if ( '' !== $id ) {
			return $repository->find( $id );
		}

		return WorkflowEvaluator::resolve(
			$repository->raw(),
			Workflows::TRIGGER_CHECKOUT_SUBMITTED,
			WorkflowEngine::context( $order )
		);
	}
}
