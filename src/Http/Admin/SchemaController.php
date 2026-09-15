<?php
/**
 * Administrative schema REST controller.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Checkout\AdapterCapabilities;
use WCCheckoutSuite\Domain\Checkout\CapabilityResolver;
use WCCheckoutSuite\Domain\Checkout\CoreFields;
use WCCheckoutSuite\Domain\Schema\PublishIncompatibilities;
use WCCheckoutSuite\Domain\Schema\SchemaDiff;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Domain\Schema\WriteResult;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the draft, publication and revision endpoints.
 *
 * The repository reports outcomes; this layer is the only place that maps them
 * to HTTP. A lost race becomes 409 and an invalid schema becomes 422, so the
 * admin client has something actionable to show.
 *
 * @see \ROADMAP.md section 19
 */
final class SchemaController {

	/**
	 * Draft route.
	 */
	public const ROUTE_DRAFT = '/schema/draft';

	/**
	 * Active schema route used by the normal editor flow.
	 */
	public const ROUTE_ACTIVE = '/schema/active';

	/**
	 * Publication route.
	 */
	public const ROUTE_PUBLISH = '/schema/publish';

	/**
	 * Revision list route.
	 */
	public const ROUTE_REVISIONS = '/schema/revisions';

	/**
	 * Restore route.
	 */
	public const ROUTE_RESTORE = '/schema/restore';

	/**
	 * Publication report route.
	 */
	public const ROUTE_DIFF = '/schema/diff';

	/**
	 * Every route, keyed by the name the admin client uses.
	 *
	 * Single source of truth: the controller registers these paths and the
	 * bootstrap hands the same map to the browser, so a rename cannot leave the
	 * client pointing at a route that no longer exists.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'draft'     => self::ROUTE_DRAFT,
			'active'    => self::ROUTE_ACTIVE,
			'publish'   => self::ROUTE_PUBLISH,
			'revisions' => self::ROUTE_REVISIONS,
			'restore'   => self::ROUTE_RESTORE,
			'diff'      => self::ROUTE_DIFF,
		);
	}

	/**
	 * Constructor.
	 *
	 * @param SchemaRepository $repository Schema repository.
	 */
	public function __construct(
		private SchemaRepository $repository
	) {
	}

	/**
	 * REST namespace.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return defined( 'WCCS_REST_NAMESPACE' ) ? (string) WCCS_REST_NAMESPACE : 'wc-checkoutsuite/v1';
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_DRAFT,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_draft' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_draft' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'schema'            => array(
							'type'     => 'object',
							'required' => true,
						),
						'expected_revision' => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_PUBLISH,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'expected_revision' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_ACTIVE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_active' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_REVISIONS,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_revisions' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_DIFF,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_diff' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::rest_namespace(),
			self::ROUTE_RESTORE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'revision' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Reports what publishing would change.
	 *
	 * Three separate answers, because they mean three different things: what is
	 * different, whether the schema is valid, and whether a checkout would honour
	 * it as written. It is a read — nothing here publishes anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_diff( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$published  = $this->repository->read( SchemaRepository::SLOT_PUBLISHED );
		$draft      = $this->repository->read( SchemaRepository::SLOT_DRAFT );
		$validation = $this->repository->validate( $draft );

		return new WP_REST_Response(
			array(
				'diff'              => SchemaDiff::between( $published, $draft ),
				'validation'        => array(
					'valid'  => $validation->is_valid(),
					'errors' => $validation->errors(),
				),
				'incompatibilities' => PublishIncompatibilities::check( $draft->fields(), new CoreFields() ),
				// What each checkout does with each field, rather than what is
				// wrong with it. A limit the merchant can see before publishing is a
				// decision; the same limit met at publication is an obstacle.
				'capabilities'      => CapabilityResolver::resolve( $draft->fields(), $draft->sections(), new CoreFields() ),
				'adapters'          => AdapterCapabilities::adapters(),
				// Whether each slot could actually be read. Without this an
				// unreadable document is indistinguishable from an empty one.
				'storage'           => array(
					'draft'     => $this->repository->read_status( SchemaRepository::SLOT_DRAFT ),
					'published' => $this->repository->read_status( SchemaRepository::SLOT_PUBLISHED ),
				),
			),
			200
		);
	}

	/**
	 * Permission callback.
	 *
	 * Nonces protect against CSRF; they never replace authorisation, so the
	 * capability is what actually decides.
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
	 * Returns the current draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_draft( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( $this->repository->read( SchemaRepository::SLOT_DRAFT )->to_array(), 200 );
	}

	/**
	 * Returns the configuration currently used by the checkout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_active( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( $this->repository->read( SchemaRepository::SLOT_PUBLISHED )->to_array(), 200 );
	}

	/**
	 * Replaces the draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_draft( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$incoming = SchemaDocument::from_array( (array) $request->get_param( 'schema' ) );
		$expected = $this->expected_revision( $request );

		// The revision is owned by the server, never by the request body.
		// The client supplies content only; the stored revision is what the
		// compare-and-swap is checked against.
		$current = $this->repository->read( SchemaRepository::SLOT_DRAFT );

		$document = $current
			->with_fields( $incoming->fields() )
			->with_sections( $incoming->sections() )
			->with_settings( $incoming->settings() )
			->bumped( get_current_user_id(), gmdate( 'c' ) );

		// Saving a draft never validates against the published schema and never
		// touches what the storefront is currently serving.
		$result = $this->repository->write( SchemaRepository::SLOT_DRAFT, $document, $expected );

		return $this->respond( $result );
	}

	/**
	 * Publishes the current draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$draft    = $this->repository->read( SchemaRepository::SLOT_DRAFT );
		$expected = $this->expected_revision( $request );

		$result = $this->repository->publish( $draft, $expected, get_current_user_id() );

		return $this->respond( $result );
	}

	/**
	 * Returns the publication history without the stored documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_revisions( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$entries = array();

		foreach ( $this->repository->revisions() as $entry ) {
			$entries[] = array(
				'revision'     => isset( $entry['revision'] ) ? (int) $entry['revision'] : 0,
				'published_at' => isset( $entry['published_at'] ) ? (string) $entry['published_at'] : '',
				'published_by' => isset( $entry['published_by'] ) ? (int) $entry['published_by'] : 0,
				'hash'         => isset( $entry['hash'] ) ? (string) $entry['hash'] : '',
			);
		}

		return new WP_REST_Response( array( 'revisions' => $entries ), 200 );
	}

	/**
	 * Republishes a previous revision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->repository->restore( (int) $request->get_param( 'revision' ), get_current_user_id() );

		return $this->respond( $result );
	}

	/**
	 * Reads the expected revision from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|null
	 */
	private function expected_revision( WP_REST_Request $request ): ?int {
		$value = $request->get_param( 'expected_revision' );

		return null === $value ? null : (int) $value;
	}

	/**
	 * Maps a repository outcome to an HTTP response.
	 *
	 * @param WriteResult $result Repository outcome.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( WriteResult $result ): WP_REST_Response|WP_Error {
		if ( $result->is_ok() ) {
			return new WP_REST_Response(
				array(
					'status'   => WriteResult::STATUS_OK,
					'revision' => $result->revision(),
				),
				200
			);
		}

		if ( $result->is_conflict() ) {
			return new WP_Error(
				'wccs_revision_conflict',
				__( 'Another administrator changed this configuration. Reload it and review the differences before saving again.', 'wc-checkoutsuite' ),
				array(
					'status'           => 409,
					'current_revision' => $result->revision(),
				)
			);
		}

		return new WP_Error(
			'wccs_invalid_schema',
			__( 'The schema was not published because it contains invalid definitions.', 'wc-checkoutsuite' ),
			array(
				'status' => 422,
				'errors' => $result->errors(),
			)
		);
	}
}
