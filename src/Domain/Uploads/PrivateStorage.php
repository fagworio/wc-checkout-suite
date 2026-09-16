<?php
/**
 * Private storage for uploaded files.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * Keeps uploaded files where the web server cannot serve them, and proves it.
 *
 * Section 12 requires an upload to have "visibilidade" and a download to go through
 * a policy, which is only true if the file is not reachable by asking for it. That
 * rules out the uploads directory: `wp-content/uploads` is published by design, and
 * every protection for a file inside it — a random name, an `.htaccess` — is either
 * obscurity or a rule the server may not be reading. The store is therefore a
 * sibling of `uploads`, not a child of it, and this class is what decides whether
 * that is enough.
 *
 * Three guards, in order of how much they can be trusted:
 *
 * 1. **The location.** Nothing under this directory is inside a path the web server
 *    is configured to serve, which is the only guard that does not depend on a
 *    configuration file being honoured.
 * 2. **The denial files.** `index.php`, `.htaccess` and `web.config` are written so
 *    the three common servers refuse the directory even when it is inside a served
 *    path — an Apache that ignores nothing, an IIS that honours `web.config`, and
 *    PHP itself for a request that reaches `index.php`.
 * 3. **The probe.** The guards are a claim; the probe is the check. A file is
 *    written and asked for over HTTP, and anything other than a refusal means the
 *    directory is public — in which case the feature says so and stays off, because
 *    a private upload feature that is not private is worse than no upload feature.
 *
 * @see ROADMAP.md section 12
 * @see docs/adr/ADR-0002-private-upload-storage.md
 */
final class PrivateStorage {

	/**
	 * Directory name, beside `uploads` rather than inside it.
	 */
	public const DIRECTORY = 'wc-checkoutsuite-private';

	/**
	 * The absolute directory, created on demand.
	 *
	 * @return string Empty string when the location cannot be resolved.
	 */
	public static function directory(): string {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}

		// The default must be outside the document root. A denial file is useful
		// defence in depth, but nginx can serve an existing file before PHP ever
		// gets a chance to execute index.php. Sites that need a custom location may
		// provide one explicitly, but the built-in path is safe by construction.
		if ( defined( 'WCCS_PRIVATE_UPLOAD_DIR' ) && is_string( WCCS_PRIVATE_UPLOAD_DIR ) && '' !== trim( WCCS_PRIVATE_UPLOAD_DIR ) ) {
			return untrailingslashit( (string) WCCS_PRIVATE_UPLOAD_DIR );
		}

		$document_root = defined( 'ABSPATH' ) ? dirname( untrailingslashit( (string) ABSPATH ) ) : '';

		return '' !== $document_root
			? $document_root . '/' . self::DIRECTORY
			: rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/' . self::DIRECTORY;
	}

	/**
	 * Whether the directory exists and is writable, creating it when it is missing.
	 *
	 * @return bool
	 */
	public static function prepare(): bool {
		$directory = self::directory();

		if ( '' === $directory ) {
			return false;
		}

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		self::write_guards( $directory );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem needs credentials on some hosts, and a storage provider that asked for them could not run at all.
		return is_writable( $directory );
	}

	/**
	 * Writes the files that make a server refuse the directory.
	 *
	 * Written on every preparation rather than only at creation, because their
	 * absence is exactly what an operator's cleanup or an aggressive deploy would
	 * leave behind, and a guard that was there yesterday is not a guard today.
	 *
	 * @param string $directory Directory.
	 * @return void
	 */
	private static function write_guards( string $directory ): void {
		$index = $directory . '/index.php';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A directory guard written into a plugin-owned directory; WP_Filesystem would require credentials on some hosts.
			file_put_contents( $index, "<?php\n// Nothing to see here.\n" );
		}

		$htaccess = $directory . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
			file_put_contents(
				$htaccess,
				"# Deny every request to this directory. See ADR-0002.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}

		$web_config = $directory . '/web.config';

		if ( ! file_exists( $web_config ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
			file_put_contents(
				$web_config,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n"
			);
		}
	}

	/**
	 * The public address a file in this directory would have.
	 *
	 * Returned so it can be probed and so the probe can be reported: this address is
	 * the one a browser would ask for, and the whole point is that nothing answers
	 * it with the file.
	 *
	 * @param string $name File name.
	 * @return string Empty string when no address can be derived.
	 */
	public static function url_for( string $name ): string {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'WP_CONTENT_URL' ) ) {
			return '';
		}

		$name = ltrim( $name, '/' );

		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return '';
		}

		return rtrim( (string) WP_CONTENT_URL, '/' ) . '/' . self::DIRECTORY . '/' . $name;
	}

	/**
	 * Whether the directory is unreachable over HTTP, as observed.
	 *
	 * A file with nothing in it is written, asked for, and deleted. What counts as a
	 * refusal is anything that is not the file: a 403, a 404, a redirect to a login,
	 * or an empty body. What does not count is a 200 carrying the contents, which is
	 * the one answer that means the directory is public.
	 *
	 * @return array{protected: bool, status: int, reason: string}
	 */
	public static function probe(): array {
		if ( ! self::prepare() ) {
			return array(
				'protected' => false,
				'status'    => 0,
				'reason'    => __( 'The private upload directory could not be created or is not writable.', 'wc-checkoutsuite' ),
			);
		}

		$name = 'probe-' . wp_generate_password( 12, false, false ) . '.txt';
		$path = self::directory() . '/' . $name;
		$body = 'wccs-probe-' . wp_generate_password( 16, false, false );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See the guard files above.
		if ( false === file_put_contents( $path, $body ) ) {
			return array(
				'protected' => false,
				'status'    => 0,
				'reason'    => __( 'The private upload directory did not accept a file.', 'wc-checkoutsuite' ),
			);
		}

		$url      = self::url_for( $name );
		$response = '' === $url ? null : wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the probe file this method just wrote.
		unlink( $path );

		if ( '' === $url ) {
			return array(
				'protected' => false,
				'status'    => 0,
				'reason'    => __( 'The address of the private directory could not be derived, so its privacy cannot be checked.', 'wc-checkoutsuite' ),
			);
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'protected' => false,
				'status'    => 0,
				'reason'    => sprintf(
					/* translators: %s: error message. */
					__( 'The private directory could not be reached over HTTP (%s), so whether it is public is unknown.', 'wc-checkoutsuite' ),
					$response->get_error_message()
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$served = wp_remote_retrieve_body( $response );

		if ( 200 === $status && false !== strpos( (string) $served, $body ) ) {
			return array(
				'protected' => false,
				'status'    => $status,
				'reason'    => __( 'A file placed in the private directory was served over HTTP, so this environment does not protect it.', 'wc-checkoutsuite' ),
			);
		}

		return array(
			'protected' => true,
			'status'    => $status,
			'reason'    => '',
		);
	}

	/**
	 * Writes a file and answers with the path it was written to.
	 *
	 * The name is generated rather than taken from the caller: a file name that
	 * arrives from a browser is a path traversal waiting to happen, and the name the
	 * customer's file had is stored in the table where it can be shown back without
	 * ever being a path.
	 *
	 * @param string $contents Contents.
	 * @param string $suffix   Suffix, without the dot.
	 * @return array{path: string, name: string}|null Null when it could not be stored.
	 */
	public static function put( string $contents, string $suffix = 'bin' ): ?array {
		if ( ! self::prepare() ) {
			return null;
		}

		$suffix = preg_replace( '/[^a-z0-9]/i', '', $suffix );
		$name   = wp_generate_password( 32, false, false ) . ( '' !== $suffix ? '.' . strtolower( (string) $suffix ) : '' );
		$path   = self::directory() . '/' . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The storage this class owns.
		if ( false === file_put_contents( $path, $contents ) ) {
			return null;
		}

		return array(
			'path' => $path,
			'name' => $name,
		);
	}

	/**
	 * The absolute path of a stored file, refusing anything that is not one.
	 *
	 * The stored path is checked against the directory rather than trusted: a row
	 * written by an older build, or by anything that can write to the table, must not
	 * be able to make a download read `/etc/passwd`.
	 *
	 * @param string $path Stored path.
	 * @return string Empty string when it does not belong to this directory.
	 */
	public static function resolve( string $path ): string {
		$directory = self::directory();

		if ( '' === $directory || '' === $path ) {
			return '';
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return '';
		}

		$base = realpath( $directory );

		if ( false === $base || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $path Stored path.
	 * @return bool
	 */
	public static function delete( string $path ): bool {
		$real = self::resolve( $path );

		if ( '' === $real || ! is_file( $real ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a file this class stored.
		return unlink( $real );
	}
}
