<?php
/**
 * The order statuses this store runs, registered with WooCommerce.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Statuses;

use WCCheckoutSuite\Domain\Approval\ReviewStatus;

/**
 * Publishes the custom statuses and answers questions about them.
 *
 * Three things live here, and each exists because the alternative is a store that cannot say where
 * an order is.
 *
 * 1. **Registration.** §12.6: register only statuses that are complete, stable and enabled. An
 *    inactive status is not registered at all — it is not a status with a label nobody sees, it is
 *    a status that does not exist, and orders cannot be recorded in a state the store does not
 *    have. The key is `wc-<id>`, and the identifier is permanent, so the state an order is in does
 *    not change when its name does.
 * 2. **The migration of the review state.** The approval flows configured before this screen
 *    existed derived their state from the field the document names, and orders already sit in it.
 *    Those states are added to the list with the identifier they already have, and then registered
 *    from the list — one registration path instead of two.
 * 3. **The payment guard.** §12.4: a status is not a command. The registry never adds anything to
 *    WooCommerce's paid statuses, and it **removes** its own pre-payment statuses from that list if
 *    anything else ever puts them there. §12.5's "Análise pendente deve permanecer como não pago"
 *    is therefore a property the store keeps, not a promise the merchant is asked to respect.
 *
 * @see \ROADMAP.md sections 12.4, 12.5 and 12.6
 */
final class OrderStatusRegistry {

	/**
	 * Hook the registration runs on, after WooCommerce is up and before the admin reads the list.
	 */
	public const HOOK = 'init';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Priority 5, beside the review state it migrates: a status has to exist before the order
		// screen asks what statuses there are.
		add_action( self::HOOK, array( self::class, 'run' ), 5 );
		add_filter( 'woocommerce_order_is_paid_statuses', array( self::class, 'guard_paid_statuses' ), 20 );
	}

	/**
	 * Hook callback.
	 *
	 * Separate from {@see self::publish()} because an action callback returns nothing: the answer
	 * publish() gives is for the callers that ask for it — the screen and the proofs — and not
	 * something WordPress would know what to do with.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::publish();
	}

	/**
	 * Publishes the statuses the store configured.
	 *
	 * Idempotent, and safe to call twice: `register_post_status` overwrites what it registered
	 * before, and the filter below replaces a label with the same one. The proof harnesses call it
	 * directly, which is why it does not rely on the hook having run.
	 *
	 * It reads the option and nothing else. Reading the published document is what the migration
	 * needs, and a schema read on every request to register a status would be paying for the
	 * checkout on every admin page; the migration therefore lives in {@see self::migrate()}, which
	 * the review state calls where it already has the document in hand.
	 *
	 * @return array<int, string> Identifiers that are registered.
	 */
	public static function publish(): array {
		$repository = new OrderStatusRepository();
		$registered = array();

		foreach ( $repository->all() as $status ) {
			if ( ! $status->is_active() || '' === $status->id() ) {
				continue;
			}

			self::register_one( $status );
			$registered[] = $status->id();
		}

		if ( array() !== $registered ) {
			// The list itself is built once and replaced wholesale: a status that was deactivated
			// since the last request must disappear from the order screen, and adding to whatever
			// WooCommerce had would leave it there.
			add_filter(
				'wc_order_statuses',
				static function ( $statuses ) use ( $repository ): array {
					$statuses = is_array( $statuses ) ? $statuses : array();

					foreach ( $repository->all() as $status ) {
						if ( ! $status->is_active() || '' === $status->id() ) {
							continue;
						}

						$statuses[ $status->key() ] = $status->label();
					}

					return $statuses;
				},
				20
			);
		}

		return $registered;
	}

	/**
	 * Adds the states the configured approval flows ask for to the list.
	 *
	 * §12's "review status legado": the flows derived their state from the field the document names,
	 * and orders are already recorded in it. They are added with the identifier they already have —
	 * so the new screen shows them, the merchant can name and colour them, and every order stays in
	 * the same internal status (§12.6, §12.7 step 8).
	 *
	 * Called with the document already read, because the caller has it: the review state reads the
	 * published schema to decide whether to watch the checkout at all.
	 *
	 * @param array<string, string> $statuses Identifier to label, as the flows name them.
	 * @return array<int, string> Identifiers that were added.
	 */
	public static function migrate( array $statuses ): array {
		if ( array() === $statuses ) {
			return array();
		}

		$added = ( new OrderStatusRepository() )->ensure( $statuses );

		if ( array() !== $added ) {
			self::publish();
		}

		return $added;
	}

	/**
	 * Registers one status with WordPress.
	 *
	 * @param OrderStatus $status Status.
	 * @return void
	 */
	private static function register_one( OrderStatus $status ): void {
		register_post_status(
			$status->key(),
			array(
				'label'                     => $status->label(),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop(
					'Orders in this status (%s)',
					'Orders in this status (%s)',
					'wc-checkoutsuite'
				),
			)
		);
	}

	/**
	 * Keeps a pre-payment status out of WooCommerce's paid list.
	 *
	 * Called on `woocommerce_order_is_paid_statuses`, where WooCommerce asks what counts as paid.
	 * The filter only ever **removes**: adding to this list is what §12.4 forbids, and a status the
	 * store marked as a state before payment is removed even if something else added it.
	 *
	 * @param mixed $statuses Statuses WooCommerce considers paid.
	 * @return array<int, string>
	 */
	public static function guard_paid_statuses( $statuses ): array {
		$statuses = is_array( $statuses ) ? array_values( array_map( 'strval', $statuses ) ) : array();

		foreach ( ( new OrderStatusRepository() )->all() as $status ) {
			if ( ! $status->is_prepayment() ) {
				continue;
			}

			$statuses = array_values(
				array_filter(
					$statuses,
					static fn( string $key ): bool => $status->id() !== $key && $status->key() !== $key
				)
			);
		}

		return $statuses;
	}

	/**
	 * The status an order is in, when it is one of ours.
	 *
	 * @param mixed $order Order or order identifier.
	 * @return OrderStatus|null
	 */
	public static function for_order( $order ): ?OrderStatus {
		$key = '';

		if ( is_object( $order ) && method_exists( $order, 'get_status' ) ) {
			$key = (string) $order->get_status();
		} elseif ( is_string( $order ) ) {
			$key = (string) preg_replace( '/^wc-/', '', $order );
		}

		if ( '' === $key ) {
			return null;
		}

		return ( new OrderStatusRepository() )->find( (string) preg_replace( '/^wc-/', '', $key ) );
	}

	/**
	 * What the customer is told their order is doing.
	 *
	 * The customer label when the status is one of ours and the merchant asked for it to be shown,
	 * and WooCommerce's own label otherwise. Reading it here rather than in each surface is what
	 * keeps the account page, the thank-you page and the e-mails saying the same thing.
	 *
	 * @param string $key   Status key, with or without the `wc-` prefix.
	 * @param string $label Label WooCommerce would use.
	 * @return string
	 */
	public static function customer_label( string $key, string $label = '' ): string {
		$status = ( new OrderStatusRepository() )->find( (string) preg_replace( '/^wc-/', '', $key ) );

		if ( null === $status ) {
			return $label;
		}

		return $status->shows_customer() ? $status->customer_label() : $label;
	}

	/**
	 * The identifiers of every status the store has.
	 *
	 * What a caller validating a definition that names a status needs: the whole floor, the
	 * merchant's own states and WooCommerce's, because a workflow may legitimately move an order to
	 * `processing` and may legitimately move it to a state the merchant created.
	 *
	 * @return array<int, string>
	 */
	public static function known_ids(): array {
		return array_values(
			array_filter(
				array_map(
					static fn( array $entry ): string => (string) ( $entry['id'] ?? '' ),
					self::inventory()
				)
			)
		);
	}

	/**
	 * The statuses the store has, with the ones WooCommerce owns.
	 *
	 * What the screen lists: the merchant's own states first, each with whether it is registered,
	 * and then WooCommerce's, which are shown so the merchant can see the whole floor and cannot
	 * take one of them over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function inventory(): array {
		$ours  = array();
		$known = array();

		foreach ( ( new OrderStatusRepository() )->all() as $status ) {
			$ours[]                  = array_merge(
				$status->to_array(),
				array(
					'custom'     => true,
					'registered' => $status->is_active(),
				)
			);
			$known[ $status->key() ] = true;
		}

		$core = array();

		foreach ( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() as $key => $label ) {
			$key = (string) $key;

			if ( isset( $known[ $key ] ) ) {
				continue;
			}

			$core[] = array(
				'id'             => (string) preg_replace( '/^wc-/', '', $key ),
				'label'          => (string) $label,
				'customer_label' => '',
				'colour'         => '',
				'active'         => true,
				'show_customer'  => true,
				'show_emails'    => true,
				'manual'         => true,
				/**
				 * WooCommerce's own paid statuses answer "paid" — that is the platform's decision and
				 * not this plugin's — and `pending` is the state before any payment at all.
				 */
				'prepayment'     => in_array( (string) preg_replace( '/^wc-/', '', $key ), array( 'pending' ), true ),
				'description'    => '',
				'custom'         => false,
				'registered'     => true,
			);
		}

		return array_merge( $ours, $core );
	}
}
