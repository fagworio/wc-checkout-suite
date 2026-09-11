<?php
/**
 * The upload endpoint.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Checkout;

use WCCheckoutSuite\Domain\Uploads\UploadRules;
use WCCheckoutSuite\Domain\Uploads\UploadService;
use WCCheckoutSuite\Http\Admin\SchemaController;

/**
 * Accepts and hands back the files a checkout asked for.
 *
 * One route, three verbs' worth of work: `POST` accepts a file, `GET` answers whether
 * a token is still the caller's, and `DELETE` removes one. All three answer the same
 * fixed shape — `status`, `code`, `message`, and a `token` when there is one — so a
 * client has one thing to parse and the endpoint cannot accidentally answer with a
 * file's contents.
 *
 * The nonce is checked the way the validation endpoint checks it, and for the same
 * reason: this is a public route that accepts a document from a browser, and a
 * request that cannot prove it came from the page is a request from somewhere else.
 * That is a CSRF guard and not an authorization — the authorization is the owner,
 * which the session supplies and the browser never sees.
 *
 * @see ROADMAP.md section 12
 */
final class UploadController {

	/**
	 * Route for uploads.
	 */
	public const ROUTE_UPLOADS = '/uploads';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_UPLOADS,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Accepts one file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->nonce( $request );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->answer( 'refused', 'bad_nonce', __( 'This page is out of date. Reload the checkout and try again.', 'wc-checkoutsuite' ), 403 );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		$field = (string) $request->get_param( 'field' );

		// The boundary is the only place that can know a temporary path really came
		// from an upload, so it is checked here and nowhere else. A path that is not
		// one is refused rather than inspected.
		if ( is_array( $file ) && isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $this->answer( 'refused', 'bad_upload', UploadRules::message( 'bad_upload' ), 422 );
		}

		$result = ( new UploadService() )->accept( $file, $field );

		if ( '' !== $result['code'] ) {
			$status = 'not_available' === $result['code'] ? 503 : 422;

			return $this->answer( 'refused', $result['code'], $result['message'], $status );
		}

		return $this->answer( 'accepted', '', '', 201, $result['token'] );
	}

	/**
	 * Answers whether a token is still the caller's.
	 *
	 * The answer never carries the file: reading the contents is a decision the
	 * download policy owns (WCCS-044), and an endpoint that returned the bytes would
	 * be a second download path with none of that policy on it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->nonce( $request );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->answer( 'refused', 'bad_nonce', __( 'This page is out of date. Reload the checkout and try again.', 'wc-checkoutsuite' ), 403 );
		}

		$token = (string) $request->get_param( 'token' );
		$owner = UploadService::owner();

		$found = ( new UploadService() )->find( $token, $owner );

		if ( '' !== $found['code'] ) {
			return $this->answer( 'refused', $found['code'], $found['message'], 404 );
		}

		$record = is_array( $found['record'] ) ? $found['record'] : array();

		return $this->answer(
			'found',
			'',
			'',
			200,
			$token,
			array(
				'file_name' => (string) ( $record['file_name'] ?? '' ),
				'mime_type' => (string) ( $record['mime_type'] ?? '' ),
				'byte_size' => (int) ( $record['byte_size'] ?? 0 ),
			)
		);
	}

	/**
	 * Removes one file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function remove( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->nonce( $request );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->answer( 'refused', 'bad_nonce', __( 'This page is out of date. Reload the checkout and try again.', 'wc-checkoutsuite' ), 403 );
		}

		$token = (string) $request->get_param( 'token' );
		$owner = UploadService::owner();

		$code = ( new UploadService() )->remove( $token, $owner );

		if ( '' !== $code ) {
			return $this->answer( 'refused', $code, UploadRules::message( $code ), 404 );
		}

		return $this->answer( 'removed', '', '', 200 );
	}

	/**
	 * The nonce, from wherever the client put it.
	 *
	 * The header first, because a multipart body and a custom header do not collide,
	 * and the body as a fallback for a client that cannot set one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function nonce( \WP_REST_Request $request ): string {
		$header = $request->get_header( 'X-WP-Nonce' );

		if ( is_string( $header ) && '' !== $header ) {
			return $header;
		}

		return (string) $request->get_param( 'nonce' );
	}

	/**
	 * One answer shape for every outcome.
	 *
	 * @param string               $status  `accepted`, `found`, `removed` or `refused`.
	 * @param string               $code    Stable code.
	 * @param string               $message Message for the customer.
	 * @param int                  $http    HTTP status.
	 * @param string               $token   Token, when there is one.
	 * @param array<string, mixed> $extra   Extra fields.
	 * @return \WP_REST_Response
	 */
	private function answer( string $status, string $code, string $message, int $http, string $token = '', array $extra = array() ): \WP_REST_Response {
		$body = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
			'token'   => $token,
		);

		foreach ( $extra as $key => $value ) {
			$body[ $key ] = $value;
		}

		$response = new \WP_REST_Response( $body, $http );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
