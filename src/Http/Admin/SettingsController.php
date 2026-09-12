<?php
/**
 * Administrative settings and compatibility REST controller.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Payments\PaymentMatrix;
use WCCheckoutSuite\Domain\Payments\PaymentMode;
use WCCheckoutSuite\Domain\Settings\CheckoutSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The switch that turns the custom checkout on, and the state a merchant reads.
 *
 * One route with two verbs rather than two routes, because the read and the write are
 * the same question: what is this store's checkout mode, and what would change it. A
 * GET answers with the whole picture — the mode, the reason, the gateways that decided
 * it and how many of them were never homologated — and a POST changes one boolean and
 * answers with the same picture again, so the screen never has to guess what its own
 * write produced.
 *
 * **What this route may not do.** It cannot touch the schema. Turning the presentation
 * off preserves the editor, and that is the acceptance's first clause: the fields, their
 * document, their revision and their history are the merchant's work and not a
 * presentation setting's business. There is no code path here that can reach them —
 * which is a stronger statement than a promise, and the proof compares the stored
 * document byte for byte across a toggle.
 *
 * The compatibility half is a read: the matrix decides per gateway, and this reports
 * what it decided, including the gateways nobody has run. A diagnostics screen that
 * could *write* a homologation would be a screen where somebody can promise a gateway
 * into compatibility.
 *
 * @see \ROADMAP.md section 15
 */
final class SettingsController {

	/**
	 * Settings and compatibility state route.
	 */
	public const ROUTE_SETTINGS = '/settings';

	/**
	 * Every route, keyed by the name the admin client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'settings' => self::ROUTE_SETTINGS,
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
			self::ROUTE_SETTINGS,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'custom_checkout' => array(
							'type'        => 'boolean',
							'required'    => true,
							'description' => 'Whether this store wants the custom checkout presentation.',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error(
			'wccs_forbidden',
			__( 'You are not allowed to manage this store\'s checkout.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * The current mode, the reason for it, and the compatibility state behind it.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * Records the merchant's choice and answers with the state it produced.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		CheckoutSettings::set_enabled( (bool) $request->get_param( 'custom_checkout' ) );

		return new WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * Everything the settings and diagnostics screens read.
	 *
	 * The decisions are reported for every gateway the store has installed and not only
	 * for the ones it currently offers, because the question a merchant asks here is
	 * "what has been homologated on my store", and a disabled gateway is still a gateway
	 * they will enable.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$offered   = CheckoutSettings::offered_gateways();
		$decision  = CheckoutSettings::decision( $offered );
		$installed = array();
		$undecided = 0;

		if ( function_exists( 'WC' ) ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
					continue;
				}

				$gateway_decision = PaymentMatrix::decide_for( $gateway );

				if ( PaymentMode::UNDECIDED === $gateway_decision['mode'] ) {
					++$undecided;
				}

				$installed[] = array(
					'id'       => (string) $gateway->id,
					'title'    => method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : (string) $gateway->id,
					'version'  => isset( $gateway->version ) ? (string) $gateway->version : '',
					'enabled'  => method_exists( $gateway, 'is_available' ) ? (bool) $gateway->is_available() : false,
					'mode'     => $gateway_decision['mode'],
					'withheld' => $gateway_decision['withheld'],
					'tested'   => $gateway_decision['tested'],
					'reason'   => $gateway_decision['reason'],
				);
			}
		}

		return array(
			'custom_checkout' => CheckoutSettings::enabled(),
			'mode'            => $decision['mode'],
			'reason'          => $decision['reason'],
			'blocked_by'      => $decision['blocked_by'],
			'offered'         => array_map(
				static function ( $gateway ): string {
					return is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : '';
				},
				$offered
			),
			'gateways'        => $installed,
			'homologated'     => count( $installed ) - $undecided,
			'undecided'       => $undecided,
			'modes'           => PaymentMode::all(),
		);
	}
}
