<?php
/**
 * The order statuses route.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Statuses\OrderStatusRepository;
use WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reading and replacing the store's own order statuses.
 *
 * One route with two methods, like the settings screen: the list is what the registry would
 * register, and replacing it is the whole edit. A list and not one status at a time, because a
 * status is not an entity with its own address — it is a row in a list the merchant arranges, and
 * two requests that each changed one row would be two chances for the list to be half-written.
 *
 * The answer is validated by the same validator the store uses on the way in, so the screen cannot
 * save something the registry would refuse to register, and the refusal travels with the codes the
 * screen shows.
 *
 * @see \ROADMAP.md section 12
 */
final class StatusController {

	/**
	 * Path of the statuses route.
	 */
	public const ROUTE_STATUSES = '/statuses';

	/**
	 * The route names the administration client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'statuses' => self::ROUTE_STATUSES,
		);
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_STATUSES,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_statuses' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_statuses' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'statuses' => array(
							'type'        => 'array',
							'required'    => true,
							'description' => 'The whole list of the store\'s own order statuses.',
						),
						'revision' => array(
							'type'        => 'integer',
							'required'    => false,
							'description' => 'Revision read by the editor for compare-and-swap.',
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may manage this store's checkout.
	 *
	 * The same capability the rest of the administration asks for: a status decides where an order
	 * is recorded, and somebody who cannot configure the checkout cannot configure the states it
	 * leads to.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error(
			'wccs_forbidden',
			__( 'You are not allowed to manage this store\'s order statuses.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Every status the store has, the merchant's own first.
	 *
	 * The inventory is read after registering, so what the screen lists is what the storefront and
	 * the order screen actually have: a status the registry refused to register is listed with
	 * `registered: false` rather than being shown as if it were in service.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_statuses( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		OrderStatusRegistry::publish();

		$repository = new OrderStatusRepository();

		return new WP_REST_Response(
			array(
				'statuses' => OrderStatusRegistry::inventory(),
				// What WooCommerce considers paid, so the screen can show a merchant that a state
				// before payment is not in it — the §12.5 rule, visible where it is configured.
				'paid'     => array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) ),
				'revision' => $repository->revision(),
			),
			200
		);
	}

	/**
	 * Replaces the store's own statuses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_statuses( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$incoming   = $request->get_param( 'statuses' );
		$incoming   = is_array( $incoming ) ? $incoming : array();
		$repository = new OrderStatusRepository();
		$expected   = $request->get_param( 'revision' );

		if ( null !== $expected && (int) $expected !== $repository->revision() ) {
			return new WP_Error(
				'wccs_statuses_conflict',
				__( 'The statuses changed in another session. Reload them before saving.', 'wc-checkoutsuite' ),
				array(
					'status'   => 409,
					'revision' => $repository->revision(),
				)
			);
		}

		$result = $repository->save( $incoming );

		if ( ! $result->is_valid() ) {
			return new WP_Error(
				'wccs_invalid_statuses',
				__( 'These order statuses cannot be stored as they are.', 'wc-checkoutsuite' ),
				array(
					'status' => 400,
					'errors' => $result->errors(),
				)
			);
		}

		OrderStatusRegistry::publish();

		return new WP_REST_Response(
			array(
				'statuses' => OrderStatusRegistry::inventory(),
				'paid'     => array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) ),
				'revision' => $repository->revision(),
			),
			200
		);
	}
}
