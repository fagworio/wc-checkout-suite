<?php
/**
 * Export, preview and import of the schema file.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Domain\Schema\SchemaTransfer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Three routes and one rule: the preview and the import do the same work, and only the
 * second writes.
 *
 * The export is a read of the published document, so it needs nothing beyond the
 * capability that reads the editor. The preview writes nothing at all — it is the same
 * inspection the import performs, returned instead of applied — and the import writes into
 * the **draft** with the compare-and-swap revision the preview was made for, which is what
 * makes a file that arrives during somebody else's edit a conflict rather than an
 * overwrite.
 *
 * @see \ROADMAP.md sections 4 and 16
 */
final class TransferController {

	/**
	 * The export route.
	 */
	public const ROUTE_EXPORT = '/schema/export';

	/**
	 * The preview route.
	 */
	public const ROUTE_PREVIEW = '/schema/import/preview';

	/**
	 * The import route.
	 */
	public const ROUTE_IMPORT = '/schema/import';

	/**
	 * Every route, keyed by the name a client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'schemaExport'  => self::ROUTE_EXPORT,
			'schemaPreview' => self::ROUTE_PREVIEW,
			'schemaImport'  => self::ROUTE_IMPORT,
		);
	}

	/**
	 * The repository.
	 *
	 * @var SchemaRepository
	 */
	private SchemaRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param SchemaRepository $repository Repository.
	 */
	public function __construct( SchemaRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$manage = array( $this, 'can_manage' );

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_EXPORT,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $manage,
			)
		);

		$payload = array(
			'payload'           => array(
				'type'        => 'string',
				'required'    => true,
				'description' => 'The file contents, as exported.',
			),
			'expected_revision' => array(
				'type'        => 'integer',
				'default'     => 0,
				'description' => 'The draft revision the preview was made for.',
			),
		);

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_PREVIEW,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $manage,
				'args'                => array(
					'payload' => $payload['payload'],
				),
			)
		);

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_IMPORT,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $manage,
				'args'                => $payload,
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
			__( 'You are not allowed to manage this store\'s checkout fields.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * The published document, as a file.
	 *
	 * @return WP_REST_Response
	 */
	public function export(): WP_REST_Response {
		$document = $this->repository->read( SchemaRepository::SLOT_PUBLISHED );
		$envelope = SchemaTransfer::export( $document );

		return new WP_REST_Response(
			array(
				'filename' => 'wc-checkoutsuite-schema-r' . $document->revision() . '.json',
				'contents' => SchemaTransfer::encode( $envelope ),
				'limits'   => SchemaTransfer::limits(),
			),
			200
		);
	}

	/**
	 * What the file would change, without changing it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$report = $this->report( (string) $request->get_param( 'payload' ) );

		unset( $report['document'] );

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * What the file changes, having been previewed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response {
		$report = $this->report( (string) $request->get_param( 'payload' ) );

		if ( ! $report['ok'] ) {
			return new WP_REST_Response( $report, 200 );
		}

		$result = $this->repository->write(
			SchemaRepository::SLOT_DRAFT,
			$report['document'],
			(int) $request->get_param( 'expected_revision' )
		);

		$report['written']  = $result->is_ok();
		$report['conflict'] = $result->is_conflict();
		$report['status']   = $result->status();
		$report['errors']   = array_merge( $report['errors'], $result->errors() );

		unset( $report['document'] );

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * The inspection both routes read.
	 *
	 * The file is validated the way every other write is validated — the same definition
	 * validator the editor and the publish route use — so a file cannot become a way to
	 * store a document the interface would refuse.
	 *
	 * @param string $payload File contents.
	 * @return array<string, mixed>
	 */
	private function report( string $payload ): array {
		$current = $this->repository->read( SchemaRepository::SLOT_DRAFT );
		$report  = SchemaTransfer::inspect( $payload, $current );

		$report['limits'] = SchemaTransfer::limits();

		if ( $report['document'] instanceof \WCCheckoutSuite\Domain\Schema\SchemaDocument ) {
			$validation = $this->repository->validate( $report['document'] );

			if ( ! $validation->is_valid() ) {
				$report['ok']     = false;
				$report['errors'] = array_merge( $report['errors'], $validation->errors() );
			}
		}

		// The document stays in the report: the import writes it, and the preview removes it
		// before answering. Removing it here — which the first version did — left the import
		// holding a null where the document should be, and the defect was invisible until the
		// proof drove the route rather than the class.
		return $report;
	}
}
