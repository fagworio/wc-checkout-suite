<?php
/**
 * The integration API: an order's checkout values, to an authenticated caller.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Integration;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WC_Order;

/**
 * Publishes the fields a store chose to expose, to callers it authenticated.
 *
 * ROADMAP.md section 14 states the whole contract in one sentence:
 *
 * > REST de integrações: schema documentado, tipos, autenticação, permissão por
 * > pedido/campo e paginação onde couber. Não expor automaticamente todos os order metas.
 *
 * Each clause is a decision here rather than an aspiration:
 *
 * - **Schema documented and typed.** Every route declares its `args` with a type, and the
 *   identifier is validated before it reaches a query. A contract a caller cannot read is
 *   a contract that changes without anyone noticing.
 * - **Authenticated, and authorized twice.** Authentication is WordPress's — a cookie
 *   with a nonce or an application password, both of which end in a logged-in user — and
 *   the capability is the store's. Then the order is checked: a caller who may read this
 *   store's orders but not *this* order is refused, because "may read orders" and "may
 *   read this customer's order" are different sentences.
 * - **Permission per field.** A field travels only when it declares `public_api`. The
 *   vocabulary defaults that key to false, so a field is private until a merchant says
 *   otherwise, and the proof asserts that the default is the one that exposes nothing.
 * - **Never every order meta.** The response is built from the Suite's own published
 *   fields and the order's own payload. It does not enumerate meta, it does not include
 *   meta keys, and a value the store keeps for another surface is not in it.
 * - **Paginated where it applies.** The collection is paged with a bounded page size and
 *   reports the total, because an endpoint that returns everything is an endpoint that
 *   times out on the store that needs it most.
 *
 * Nothing personal leaves through the *public* Store API, and that is a property of where
 * this class is registered rather than of what it filters: the Store API's own routes are
 * WooCommerce's, this plugin adds no route to them, and the only extension it makes there
 * is the type contract the Blocks checkout validates against — a shape, not a value.
 */
final class OrderFieldsController {

	/**
	 * The collection route.
	 */
	public const ROUTE_ORDERS = '/integration/orders';

	/**
	 * The single-order route.
	 */
	public const ROUTE_ORDER = '/integration/orders/(?P<order_id>[\d]+)';

	/**
	 * Default page size.
	 */
	public const PER_PAGE = 20;

	/**
	 * Largest page size a caller may ask for.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Every route, keyed by the name a client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'integrationOrders' => self::ROUTE_ORDERS,
			'integrationOrder'  => self::ROUTE_ORDER,
		);
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_ORDERS,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'can_read_orders' ),
				'args'                => array(
					'page'     => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => 'Which page of orders to return.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => self::PER_PAGE,
						'minimum'     => 1,
						'maximum'     => self::MAX_PER_PAGE,
						'description' => 'How many orders one page holds.',
					),
					'status'   => array(
						'type'        => 'string',
						'default'     => 'any',
						'description' => 'Order status to filter by, or "any".',
					),
				),
			)
		);

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_ORDER,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'can_read_order' ),
				'args'                => array(
					'order_id' => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => 'Identifier of the order to read.',
					),
				),
			)
		);
	}

	/**
	 * Permission for the collection.
	 *
	 * @return bool|WP_Error
	 */
	public function can_read_orders(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return $this->refusal( 'wccs_unauthenticated' );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			return $this->refusal( 'wccs_forbidden' );
		}

		return true;
	}

	/**
	 * Permission for one order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_read_order( WP_REST_Request $request ): bool|WP_Error {
		$collection = $this->can_read_orders();

		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$order = wc_get_order( (int) $request->get_param( 'order_id' ) );

		// An order that does not exist and an order that is not this caller's are answered
		// with the same refusal, deliberately. Two codes would let anybody who may read
		// orders count the store's orders by asking for identifiers and watching which
		// answer comes back — a caller learns "no" either way, and learns nothing else.
		if ( ! $order instanceof WC_Order ) {
			return $this->refusal( 'wccs_order_not_allowed' );
		}

		// The second authorization, and the one that is about a person rather than about a
		// role: this caller may read orders, and may they read *this* one.
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return $this->refusal( 'wccs_order_not_allowed' );
		}

		return true;
	}

	/**
	 * A refusal that says nothing about what exists.
	 *
	 * One shape for every reason: a caller who is not allowed to read an order is not told
	 * whether the order exists, because the difference between "no" and "no such thing" is
	 * an enumeration oracle for anybody who can count.
	 *
	 * @param string $code Stable code.
	 * @return WP_Error
	 */
	private function refusal( string $code ): WP_Error {
		return new WP_Error(
			$code,
			__( 'You are not allowed to read this store\'s orders.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * One page of orders, with the fields the store exposes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_orders( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$status   = (string) $request->get_param( 'status' );

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
			'paginate' => true,
		);

		if ( '' !== $status && 'any' !== $status ) {
			$args['status'] = $status;
		} else {
			$args['status'] = array_keys( wc_get_order_statuses() );
		}

		$result = wc_get_orders( $args );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : array();
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );

		$items = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
				// A caller may read orders and not every order, and this is where that
				// stops being a sentence and becomes a list that does not contain them.
				continue;
			}

			$items[] = $this->project( $order );
		}

		$response = new WP_REST_Response(
			array(
				'orders' => $items,
				'page'   => $page,
				'total'  => $total,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	/**
	 * One order, with the fields the store exposes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_order( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( (int) $request->get_param( 'order_id' ) );

		return new WP_REST_Response( $this->project( $order ), 200 );
	}

	/**
	 * The projection of one order.
	 *
	 * Built from the published fields and the order's own payload — never from the order's
	 * meta, which is what "não expor automaticamente todos os order metas" asks for: a
	 * response assembled by enumerating meta would publish whatever another plugin, or a
	 * later version of this one, happened to store there.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private function project( WC_Order $order ): array {
		$definitions = PublishedDocument::read()->fields();
		$exposed     = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || ! $definition->is_enabled() ) {
				continue;
			}

			$stored     = $definition->to_array();
			$visibility = isset( $stored['visibility'] ) && is_array( $stored['visibility'] ) ? $stored['visibility'] : array();

			// Off unless the merchant said otherwise, which is the vocabulary's own
			// default: a field is private until somebody decides it is not.
			if ( empty( $visibility['public_api'] ) ) {
				continue;
			}

			$exposed[ $id ] = array(
				'label' => $definition->label(),
				'type'  => $definition->type(),
			);
		}

		if ( array() === $exposed ) {
			return array(
				'id'     => $order->get_id(),
				'fields' => array(),
			);
		}

		$values = ( new OrderFieldsService() )->read( $order );
		$fields = array();

		foreach ( $exposed as $id => $meta ) {
			if ( ! $values->has( $id ) ) {
				// A field the order does not carry is absent rather than null: a caller
				// cannot tell "the customer left it blank" from "the field did not exist"
				// if both are null, and the difference is the whole reason the snapshot
				// travels with the order.
				continue;
			}

			$fields[] = array(
				'id'    => $id,
				'label' => $meta['label'],
				'type'  => $meta['type'],
				'value' => $values->get( $id ),
			);
		}

		return array(
			'id'     => $order->get_id(),
			'fields' => $fields,
		);
	}
}
