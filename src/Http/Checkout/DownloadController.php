<?php
/**
 * Handing a stored file to somebody who may read it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Checkout;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Uploads\DownloadPolicy;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;
use WCCheckoutSuite\Domain\Uploads\PrivateStorage;
use WCCheckoutSuite\Domain\Uploads\UploadRepository;
use WCCheckoutSuite\Domain\Uploads\UploadService;
use WCCheckoutSuite\Http\Admin\SchemaController;

/**
 * The single door a private file leaves through.
 *
 * Every read goes through the policy, and the file is streamed rather than handed to
 * the web server: the whole point of the storage is that the server cannot serve it,
 * so the plugin has to, and that is the only place the policy can be applied. The
 * response carries `nosniff` and an attachment disposition, because a document a
 * customer uploaded is not a page and must never be interpreted as one.
 *
 * A refusal is one answer for every reason — a token that does not exist, one that
 * belongs to somebody else, one whose order is not this customer's — because
 * distinguishing them would let a stranger find out which tokens are real.
 *
 * @see ROADMAP.md sections 12 and 20
 * @see docs/adr/ADR-0002-private-upload-storage.md
 */
final class DownloadController {

	/**
	 * Route for a download.
	 */
	public const ROUTE_DOWNLOAD = '/uploads/(?P<token>[a-f0-9]{64})/download';

	/**
	 * The destinations only staff may ask from.
	 *
	 * @var array<int, string>
	 */
	private const STAFF_DESTINATIONS = array( 'admin_order', 'admin_email', 'admin_customer' );

	/**
	 * The destinations the customer of the order may ask from.
	 *
	 * @var array<int, string>
	 */
	private const CUSTOMER_DESTINATIONS = array(
		'customer_order',
		'customer_account',
		'order_received',
		'customer_email',
	);

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_DOWNLOAD,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array(
						'type'     => 'string',
						'required' => true,
					),
					// The surface the link was drawn on. It restricts rather than
					// grants: claiming a staff surface still requires being staff, and
					// a surface whose link does not allow the action refuses the bytes
					// however the request is dressed up.
					'destination' => array(
						'type'    => 'string',
						'default' => 'customer_order',
					),
				),
			)
		);
	}

	/**
	 * Whether the surface the request came from may hand this file over.
	 *
	 * The definition is read the way every other surface reads it — through the
	 * published document — and the action asked for is `download`, which is the only
	 * thing this route does. A destination that is not linked, or a link that does not
	 * allow downloading, refuses the bytes.
	 *
	 * Two things are decided before the link is even consulted. The destination is an
	 * argument the client sends, so it cannot be the authority on its own: a staff
	 * surface is for staff, and a customer surface for the customer of that order. And
	 * a field the store no longer declares has no link to consult — its files belong to
	 * orders that were placed while it existed, and taking a field out of the
	 * configuration does not make the history unreadable to the people who own it. The
	 * identity policy above has already answered who may read.
	 *
	 * @param array<string, mixed> $record      Upload record.
	 * @param string               $destination Destination key.
	 * @param array<string, mixed> $context     Who is asking, as `context()` reads it.
	 * @return bool
	 */
	private static function surface_allows( array $record, string $destination, array $context ): bool {
		if ( ! self::claimable( $destination, $context ) ) {
			return false;
		}

		$field_id = isset( $record['field_id'] ) ? (string) $record['field_id'] : '';

		if ( '' === $field_id ) {
			return false;
		}

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );

			if ( $definition->id() !== $field_id ) {
				continue;
			}

			return FilePermissions::allows( $definition, $destination, 'download' );
		}

		return true;
	}

	/**
	 * Whether this requester may speak for this destination at all.
	 *
	 * A field may offer a download on the order screen for staff and withhold it from
	 * the customer; without this, the customer could name the staff surface and inherit
	 * what it allows. One destination does not lend its permissions to another, so the
	 * claim is checked against the role that is asking, not against the string alone.
	 *
	 * The integration surface is not a browser's to claim: it is answered by the REST
	 * projections, with their own authentication, and never through this door.
	 *
	 * @param string               $destination Destination key.
	 * @param array<string, mixed> $context     Who is asking.
	 * @return bool
	 */
	private static function claimable( string $destination, array $context ): bool {
		if ( in_array( $destination, self::STAFF_DESTINATIONS, true ) ) {
			return ! empty( $context['can_manage'] );
		}

		return in_array( $destination, self::CUSTOMER_DESTINATIONS, true );
	}

	/**
	 * Answers with the file, or with the one refusal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function download( \WP_REST_Request $request ): \WP_REST_Response {
		$token       = (string) $request->get_param( 'token' );
		$destination = (string) $request->get_param( 'destination' );
		$owner       = UploadService::owner();
		$found       = ( new UploadRepository() )->find_any( $token );
		$refusal     = DownloadPolicy::refusal();

		$context = null === $found ? array() : self::context( $found, $owner );
		$allowed = null !== $found && DownloadPolicy::allows( $found, $context );

		// Who may read the file is one question; whether the surface it is asked from
		// may hand it over is another. Hiding a link is not a permission: without this,
		// a file the merchant chose not to offer for download would still be served to
		// anyone holding the token.
		if ( $allowed ) {
			$allowed = self::surface_allows( $found, $destination, $context );
		}

		if ( ! $allowed ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'refused',
					'code'    => $refusal['code'],
					'message' => $refusal['message'],
				),
				404
			);
		}

		$path = PrivateStorage::resolve( (string) ( $found['path'] ?? '' ) );

		if ( '' === $path || ! is_readable( $path ) ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'refused',
					'code'    => $refusal['code'],
					'message' => $refusal['message'],
				),
				404
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a private file from disk, not a URL; the policy has already decided this request may have it.
		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return new \WP_REST_Response( array( 'status' => 'refused' ) + $refusal, 404 );
		}

		$response = new \WP_REST_Response( $contents, 200 );

		$response->header( 'Content-Type', (string) ( $found['mime_type'] ?? 'application/octet-stream' ) );
		$response->header( 'Content-Disposition', 'attachment; filename="' . self::filename( (string) ( $found['file_name'] ?? 'document' ) ) . '"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * Who is asking, as the policy reads it.
	 *
	 * @param array<string, mixed> $record Upload record.
	 * @param string               $owner  Session owner identifier.
	 * @return array{owner: string, user_id: int, can_manage: bool, order_customer: int}
	 */
	private function context( array $record, string $owner ): array {
		$order_id = (int) ( $record['order_id'] ?? 0 );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		return array(
			'owner'          => $owner,
			'user_id'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'can_manage'     => function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ),
			'order_customer' => $order instanceof \WC_Order ? (int) $order->get_customer_id() : 0,
		);
	}

	/**
	 * A file name a browser will accept.
	 *
	 * @param string $name Stored name.
	 * @return string
	 */
	private function filename( string $name ): string {
		$name = basename( str_replace( array( '\\', '"' ), array( '/', '' ), $name ) );

		return '' === $name ? 'document' : $name;
	}
}
