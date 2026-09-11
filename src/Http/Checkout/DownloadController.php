<?php
/**
 * Handing a stored file to somebody who may read it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Checkout;

use WCCheckoutSuite\Domain\Uploads\DownloadPolicy;
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
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Answers with the file, or with the one refusal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function download( \WP_REST_Request $request ): \WP_REST_Response {
		$token   = (string) $request->get_param( 'token' );
		$owner   = UploadService::owner();
		$found   = ( new UploadRepository() )->find_any( $token );
		$refusal = DownloadPolicy::refusal();

		$allowed = null !== $found && DownloadPolicy::allows( $found, self::context( $found, $owner ) );

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
