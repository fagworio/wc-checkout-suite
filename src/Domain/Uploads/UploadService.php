<?php
/**
 * Accepting an upload, and knowing whose it is.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * The one place an upload is accepted.
 *
 * It answers three questions in an order that matters: may this store hold a private
 * file at all, what is this file really, and does this owner still have room. The
 * first is the gate from WCCS-041 and it comes first on purpose — a store whose
 * directory is public should not spend a byte finding out whether the file was
 * acceptable, and a refusal that names the environment is more useful than one that
 * names the file.
 *
 * **Ownership is a session, derived on the server.** The owner identifier is opaque,
 * generated once per session and kept in the session itself, so two browsers get two
 * owners and neither can name the other's. It is deliberately not the customer id:
 * a guest has no customer id and a logged-in customer may check out from two
 * devices, and an identifier that two checkouts share is an identifier that lets one
 * of them read the other's documents.
 *
 * @see ROADMAP.md section 12
 */
final class UploadService {

	/**
	 * Session key the owner identifier is kept under.
	 */
	public const OWNER_KEY = 'wccs_upload_owner';

	/**
	 * How long a temporary upload lives, in seconds.
	 */
	public const TTL = DAY_IN_SECONDS;

	/**
	 * The repository.
	 *
	 * @var UploadRepository
	 */
	private UploadRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param UploadRepository|null $repository Repository.
	 */
	public function __construct( ?UploadRepository $repository = null ) {
		$this->repository = $repository ?? new UploadRepository();
	}

	/**
	 * The owner identifier for this session, created on first use.
	 *
	 * @return string Empty when there is no session to keep it in.
	 */
	public static function owner(): string {
		// `function_exists( 'WC' )` is true from the moment WooCommerce's plugin file
		// is loaded, which is well before it has built its session — the mistake this
		// project has already paid for once. The session is asked for through the
		// same guard the condition context uses.
		$session = function_exists( 'WC' ) ? WC()->session : null;

		if ( ! $session instanceof \WC_Session ) {
			return '';
		}

		$owner = (string) $session->get( self::OWNER_KEY, '' );

		if ( '' !== $owner ) {
			return $owner;
		}

		$owner = bin2hex( random_bytes( 16 ) );
		$session->set( self::OWNER_KEY, $owner );

		return $owner;
	}

	/**
	 * Accepts a file.
	 *
	 * The file arrives as an array because that is what PHP hands a request handler:
	 * a partially-filled one is a refused request rather than an error, since a
	 * browser that lost a field mid-upload is an ordinary thing and not a bug here.
	 *
	 * @param array<string, mixed>|null $file     One entry of `$_FILES`.
	 * @param string                    $field_id Field the upload belongs to.
	 * @param array<string, mixed>      $settings Resolved settings of the field.
	 * @return array{token: string, code: string, message: string} Token on success, a refusal otherwise.
	 */
	public function accept( ?array $file, string $field_id, array $settings = array() ): array {
		if ( ! UploadsEnvironment::enabled() ) {
			return $this->refuse( 'not_available', UploadsEnvironment::reason() );
		}

		$owner = self::owner();

		if ( '' === $owner ) {
			return $this->refuse( 'not_available', __( 'This checkout has no session to attach an upload to.', 'wc-checkoutsuite' ) );
		}

		// What is checked here is what the file *is*; whether PHP really uploaded it
		// is a property of the request and is checked at the boundary that has one
		// (UploadController). Keeping that check out of the service is what lets the
		// rules be exercised without a browser, and it is not a weakening: a path that
		// did not come from an upload cannot reach this method through the route.
		$path = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( null === $file || '' === $path || ! is_readable( $path ) || ! isset( $file['size'] ) ) {
			return $this->refuse( 'empty_file', UploadRules::message( 'empty_file' ) );
		}

		$bytes = (int) $file['size'];
		$mime  = self::detect( (string) $file['tmp_name'] );

		$code = UploadRules::check(
			$mime,
			$bytes,
			$this->repository->used_bytes( $owner ),
			self::max_bytes( $settings ),
			UploadRules::DEFAULT_QUOTA_BYTES,
			self::allowed_extensions( $settings )
		);

		if ( '' !== $code ) {
			return $this->refuse( $code, UploadRules::message( $code ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the temporary file PHP just received; it is a local path, not a URL.
		$contents = file_get_contents( (string) $file['tmp_name'] );

		if ( ! is_string( $contents ) ) {
			return $this->refuse( 'empty_file', UploadRules::message( 'empty_file' ) );
		}

		$stored = PrivateStorage::put( $contents, self::suffix( $mime ) );

		if ( null === $stored ) {
			return $this->refuse( 'not_available', __( 'The store could not write the file.', 'wc-checkoutsuite' ) );
		}

		$token = $this->repository->insert(
			array(
				'owner'      => $owner,
				'field_id'   => $field_id,
				'file_name'  => self::safe_name( (string) ( $file['name'] ?? '' ) ),
				'mime_type'  => $mime,
				'byte_size'  => $bytes,
				'path'       => (string) $stored['path'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
			)
		);

		if ( '' === $token ) {
			PrivateStorage::delete( (string) $stored['path'] );

			return $this->refuse( 'not_available', __( 'The store could not record the file.', 'wc-checkoutsuite' ) );
		}

		return array(
			'token'   => $token,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * Reads one upload the owner is allowed to read.
	 *
	 * @param string $token Token.
	 * @param string $owner Owner.
	 * @return array{record: array<string, mixed>|null, code: string, message: string}
	 */
	public function find( string $token, string $owner ): array {
		$record = $this->repository->find( $token, $owner );

		if ( null !== $record ) {
			return array(
				'record'  => $record,
				'code'    => '',
				'message' => '',
			);
		}

		$code = $this->repository->exists( $token ) ? 'not_yours' : 'unknown_token';

		return array(
			'record'  => null,
			'code'    => $code,
			'message' => UploadRules::message( $code ),
		);
	}

	/**
	 * Removes one upload the owner is allowed to remove.
	 *
	 * @param string $token Token.
	 * @param string $owner Owner.
	 * @return string Empty on success, a stable code otherwise.
	 */
	public function remove( string $token, string $owner ): string {
		$found = $this->find( $token, $owner );

		if ( '' !== $found['code'] ) {
			return $found['code'];
		}

		$record = $this->repository->delete( $token, $owner );

		if ( null !== $record ) {
			PrivateStorage::delete( (string) $record['path'] );
		}

		return '';
	}

	/**
	 * The owner identifier of a customer's own documents.
	 *
	 * A checkout upload belongs to a **session** on purpose: two checkouts must not see each
	 * other's documents. A document on the customer's own profile is the opposite case — it
	 * belongs to the customer, and a customer who signs in on another device has to find it
	 * again. So the identifier is derived from the user, and the row also records the user id,
	 * which is what the account page looks the file up by.
	 *
	 * @param int $user_id Customer.
	 * @return string Empty when there is no customer to attach the file to.
	 */
	public static function customer_owner( int $user_id ): string {
		return $user_id > 0 ? 'customer:' . $user_id : '';
	}

	/**
	 * Accepts a file a customer keeps on their own profile.
	 *
	 * The checks are the same three, in the same order, as {@see self::accept()}: whether the
	 * store may hold private files at all, what the file really is, and whether its owner has
	 * room. What differs is the life of the result — a customer document does not expire, so
	 * no expiry is written, and the row says whose it is.
	 *
	 * @param array<string, mixed>|null $file     One entry of `$_FILES`.
	 * @param string                    $field_id Field the upload belongs to.
	 * @param int                       $user_id  Customer.
	 * @param array<string, mixed>      $settings Resolved settings of the field.
	 * @return array{token: string, code: string, message: string} Token on success, a refusal otherwise.
	 */
	public function accept_for_customer( ?array $file, string $field_id, int $user_id, array $settings = array() ): array {
		if ( ! UploadsEnvironment::enabled() ) {
			return $this->refuse( 'not_available', UploadsEnvironment::reason() );
		}

		$owner = self::customer_owner( $user_id );

		if ( '' === $owner ) {
			return $this->refuse( 'not_available', __( 'There is no customer to attach this document to.', 'wc-checkoutsuite' ) );
		}

		$previous = $this->repository->for_user( $user_id, $field_id );
		$used     = $this->repository->used_bytes( $owner );

		// Replacing a document should consume the space of the new version, not the
		// space of both versions. The old row is removed only after the new row is
		// recorded successfully, so a failed replacement never loses the current file.
		if ( is_array( $previous ) ) {
			$used = max( 0, $used - (int) ( $previous['byte_size'] ?? 0 ) );
		}

		$path = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( null === $file || '' === $path || ! is_readable( $path ) || ! isset( $file['size'] ) ) {
			return $this->refuse( 'empty_file', UploadRules::message( 'empty_file' ) );
		}

		$bytes = (int) $file['size'];
		$mime  = self::detect( (string) $file['tmp_name'] );

		$code = UploadRules::check(
			$mime,
			$bytes,
			$used,
			self::max_bytes( $settings ),
			UploadRules::DEFAULT_QUOTA_BYTES,
			self::allowed_extensions( $settings )
		);

		if ( '' !== $code ) {
			return $this->refuse( $code, UploadRules::message( $code ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the temporary file PHP just received; it is a local path, not a URL.
		$contents = file_get_contents( (string) $file['tmp_name'] );

		if ( ! is_string( $contents ) ) {
			return $this->refuse( 'empty_file', UploadRules::message( 'empty_file' ) );
		}

		$stored = PrivateStorage::put( $contents, self::suffix( $mime ) );

		if ( null === $stored ) {
			return $this->refuse( 'not_available', __( 'The store could not write the file.', 'wc-checkoutsuite' ) );
		}

		$token = $this->repository->insert(
			array(
				'owner'      => $owner,
				'field_id'   => $field_id,
				'file_name'  => self::safe_name( (string) ( $file['name'] ?? '' ) ),
				'mime_type'  => $mime,
				'byte_size'  => $bytes,
				'path'       => (string) $stored['path'],
				'user_id'    => $user_id,
				// No expiry: the reason to keep it is the customer, not a checkout.
				'expires_at' => null,
			)
		);

		if ( '' === $token ) {
			PrivateStorage::delete( (string) $stored['path'] );

			return $this->refuse( 'not_available', __( 'The store could not record the file.', 'wc-checkoutsuite' ) );
		}

		if ( is_array( $previous ) ) {
			$old = $this->repository->delete( (string) ( $previous['token'] ?? '' ), $owner );

			if ( is_array( $old ) ) {
				PrivateStorage::delete( (string) ( $old['path'] ?? '' ) );
			}
		}

		return array(
			'token'   => $token,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * The document a customer currently keeps for one field.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $field_id Field.
	 * @return array<string, mixed>|null
	 */
	public function for_customer_field( int $user_id, string $field_id ): ?array {
		return $this->repository->for_user( $user_id, $field_id );
	}

	/**
	 * Removes one document of a customer's own, when it is theirs.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id Customer.
	 * @return string Empty on success, a stable code otherwise.
	 */
	public function remove_for_customer( string $token, int $user_id ): string {
		$owner = self::customer_owner( $user_id );

		if ( '' === $owner ) {
			return 'not_yours';
		}

		return $this->remove( $token, $owner );
	}

	/**
	 * What the server says the file is.
	 *
	 * `finfo` rather than the browser's content type, and rather than the extension:
	 * a client chooses both of those, and the only opinion worth acting on is the one
	 * the server forms from the bytes.
	 *
	 * @param string $path Temporary path.
	 * @return string MIME type, or an empty string when it cannot be determined.
	 */
	public static function detect( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Resolves the per-field maximum, falling back to the store default.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @return int
	 */
	private static function max_bytes( array $settings ): int {
		$max = isset( $settings['maxBytes'] ) ? (int) $settings['maxBytes'] : UploadRules::DEFAULT_MAX_BYTES;

		return $max > 0 ? $max : UploadRules::DEFAULT_MAX_BYTES;
	}

	/**
	 * Resolves the per-field extension allow-list.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @return array<int, string>
	 */
	private static function allowed_extensions( array $settings ): array {
		if ( ! isset( $settings['allowedExtensions'] ) || ! is_array( $settings['allowedExtensions'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $settings['allowedExtensions'] ) ) );
	}

	/**
	 * The suffix a stored file gets, from the type the server detected.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function suffix( string $mime ): string {
		$map = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
		);

		return $map[ $mime ] ?? 'bin';
	}

	/**
	 * The name to show back to the customer.
	 *
	 * Kept because a customer needs to recognise their own file, and stripped of
	 * everything a path needs: the stored name is generated, so this one is a label
	 * and never a location.
	 *
	 * @param string $name Submitted name.
	 * @return string
	 */
	private static function safe_name( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = preg_replace( '/[^A-Za-z0-9._ -]/', '', $name );

		return mb_substr( (string) $name, 0, 190 );
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Message.
	 * @return array{token: string, code: string, message: string}
	 */
	private function refuse( string $code, string $message ): array {
		return array(
			'token'   => '',
			'code'    => $code,
			'message' => $message,
		);
	}
}
