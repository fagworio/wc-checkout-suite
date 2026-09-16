<?php
/**
 * The rules an upload has to satisfy.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * What an upload may be, decided without touching a file or a database.
 *
 * Section 12 lists the configuration an upload field carries — extensions, MIME,
 * maximum bytes, how many files, whether it is required — and every one of them is a
 * question about a value the server already has. Keeping the rules here, as a
 * function of what was submitted and what was declared, is what makes them testable
 * on their own: a limit that can only be checked by uploading something is a limit
 * nobody checks.
 *
 * Two of the decisions are worth stating because they are where an upload feature
 * usually gets it wrong:
 *
 * 1. **The declared extension is not evidence.** A file named `invoice.pdf` may be
 *    anything at all, and the browser's content type is a suggestion the client
 *    chooses. What counts is what the server detects, and when the declaration and
 *    the detection disagree the answer is no rather than a guess.
 * 2. **The quota is about the owner, not the file.** A limit per file stops one
 *    large upload and does nothing about a thousand small ones, which is the shape a
 *    storage abuse actually takes.
 *
 * @see ROADMAP.md section 12
 */
final class UploadRules {

	/**
	 * Default maximum size of one file, in bytes.
	 */
	public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * Default quota per owner, in bytes.
	 */
	public const DEFAULT_QUOTA_BYTES = 20 * 1024 * 1024;

	/**
	 * Types a store may accept, as MIME types the server detects.
	 *
	 * Deliberately short and deliberately not every type a browser knows: these are
	 * the documents a checkout asks a customer for, and an upload feature that
	 * accepts anything is a file host with an order attached.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_mimes(): array {
		return array(
			'image/jpeg',
			'image/png',
			'image/webp',
			'application/pdf',
			'text/plain',
		);
	}

	/**
	 * Validates a detected file against the limits.
	 *
	 * @param string             $mime               MIME type the server detected.
	 * @param int                $bytes              Size in bytes.
	 * @param int                $used               Bytes the owner has already stored.
	 * @param int                $max_bytes          Maximum for one file.
	 * @param int                $quota              Maximum for the owner.
	 * @param array<int, string> $allowed_extensions Extensions allowed by the field, empty for the store defaults.
	 * @return string Empty when it is acceptable, a stable code otherwise.
	 */
	public static function check( string $mime, int $bytes, int $used = 0, int $max_bytes = self::DEFAULT_MAX_BYTES, int $quota = self::DEFAULT_QUOTA_BYTES, array $allowed_extensions = array() ): string {
		if ( $bytes <= 0 ) {
			return 'empty_file';
		}

		if ( $bytes > $max_bytes ) {
			return 'file_too_large';
		}

		if ( ! in_array( $mime, self::allowed_mimes(), true ) ) {
			return 'mime_not_allowed';
		}

		if ( array() !== $allowed_extensions && ! in_array( self::extension_for_mime( $mime ), self::normalise_extensions( $allowed_extensions ), true ) ) {
			return 'mime_not_allowed';
		}

		if ( ( $used + $bytes ) > $quota ) {
			return 'quota_exceeded';
		}

		return '';
	}

	/**
	 * The extension represented by a server-detected MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function extension_for_mime( string $mime ): string {
		$extensions = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
		);

		return $extensions[ $mime ] ?? '';
	}

	/**
	 * Normalises the merchant's extension list without trusting a browser value.
	 *
	 * @param array<int, string> $extensions Extensions.
	 * @return array<int, string>
	 */
	private static function normalise_extensions( array $extensions ): array {
		$normalised = array();

		foreach ( $extensions as $extension ) {
			$extension = strtolower( ltrim( trim( (string) $extension ), '.' ) );

			if ( '' !== $extension ) {
				$normalised[] = $extension;
			}
		}

		return array_values( array_unique( $normalised ) );
	}

	/**
	 * The message a refusal is shown with.
	 *
	 * @param string $code Stable code.
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'empty_file':
				return __( 'The file is empty.', 'wc-checkoutsuite' );
			case 'bad_upload':
				return __( 'The file did not arrive complete. Try again.', 'wc-checkoutsuite' );
			case 'file_too_large':
				return __( 'The file is larger than this store accepts.', 'wc-checkoutsuite' );
			case 'mime_not_allowed':
				return __( 'This kind of file is not accepted here.', 'wc-checkoutsuite' );
			case 'quota_exceeded':
				return __( 'You have reached the amount of space this checkout gives you.', 'wc-checkoutsuite' );
			case 'too_many_files':
				return __( 'This field already has the maximum number of files.', 'wc-checkoutsuite' );
			case 'not_available':
				return __( 'Uploads are not available on this store.', 'wc-checkoutsuite' );
			case 'not_yours':
				return __( 'That upload belongs to another session.', 'wc-checkoutsuite' );
			case 'unknown_token':
				return __( 'That upload is not on file.', 'wc-checkoutsuite' );
		}

		return __( 'The file could not be accepted.', 'wc-checkoutsuite' );
	}
}
