<?php
/**
 * Administrative catalogue REST controller.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Checkout\CoreFields;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Sections\SectionLocations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Publishes what the field picker needs to draw itself.
 *
 * Two routes rather than one, deliberately. The type catalogue is read from
 * registries this plugin owns and cannot fail; the core field inventory depends
 * on WooCommerce and on whatever other plugins hook its checkout fields filter.
 * Merging them would let an unavailable WooCommerce list take the type picker
 * down with it, which is the opposite of what a merchant needs when something is
 * wrong with their checkout.
 *
 * Both routes are reads. The picker never writes: creating, editing, duplicating
 * and archiving happen in the draft, and the draft is saved through the existing
 * schema route with its compare-and-swap revision. Adding per-operation write
 * endpoints would have meant four more revision guards to keep correct, for no
 * behaviour the draft route does not already provide.
 *
 * @see \ROADMAP.md sections 6, 7 and 19
 */
final class CatalogController {

	/**
	 * Field type catalogue route.
	 */
	public const ROUTE_FIELD_TYPES = '/field-types';

	/**
	 * WooCommerce core field inventory route.
	 */
	public const ROUTE_CORE_FIELDS = '/core-fields';

	/**
	 * Every route, keyed by the name the admin client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'fieldTypes' => self::ROUTE_FIELD_TYPES,
			'coreFields' => self::ROUTE_CORE_FIELDS,
		);
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_FIELD_TYPES,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_field_types' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_CORE_FIELDS,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_core_fields' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
			__( 'You are not allowed to manage the checkout schema.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns everything the admin builds its field interface from.
	 *
	 * Field types grouped by category, the presets, the masks a field may be
	 * configured with, and the vocabulary of storage scopes, sensitivity levels,
	 * audiences and hidden-value policies. All of it is read from the registries
	 * and from {@see DefinitionVocabulary}, which the validator reads too, so the
	 * inspector cannot offer a choice the server would refuse.
	 *
	 * The list comes from the registry rather than from a constant, so a type a
	 * third-party plugin registers through `wccs_register_field_types` appears in
	 * the picker with no change here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_field_types( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$registries = Registries::instance();
		$catalogue  = $registries->types()->catalogue();

		// Presets are what a merchant actually looks for: they want "CPF", not
		// "text with a mask". A preset is offered only when it is enabled and its
		// underlying type is registered, because a preset built on a type that is
		// not there cannot produce a valid definition.
		$orphaned = $registries->presets()->orphaned( $registries->types() );
		$presets  = array();

		foreach ( $registries->presets()->presets() as $key => $preset ) {
			if ( ! $preset->is_enabled() || in_array( (string) $key, $orphaned, true ) ) {
				continue;
			}

			$presets[] = $preset->to_array();
		}

		$catalogue['presets'] = $presets;

		// The mask catalogue and the vocabulary of a definition. Both are read from
		// the same registries and the same class the validator uses, so the
		// inspector cannot offer a choice the server would refuse.
		$catalogue['masks']      = $registries->masks()->to_array();
		$catalogue['vocabulary'] = DefinitionVocabulary::to_array();

		// The logical places a section may occupy. Published from the same class
		// the validator reads, so the inspector cannot offer a location the server
		// would refuse.
		$catalogue['sectionLocations'] = SectionLocations::all();

		return new WP_REST_Response( $catalogue, 200 );
	}

	/**
	 * Returns the WooCommerce core field inventory.
	 *
	 * An unavailable inventory is a 200 with `available: false` and a reason, not
	 * an error: "WooCommerce is not active" is information the picker should show
	 * calmly, and a 5xx would push it into a generic failure state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_core_fields( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( ( new CoreFields() )->catalogue(), 200 );
	}
}
