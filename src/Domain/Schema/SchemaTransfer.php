<?php
/**
 * Exporting and importing the published document as a file.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

/**
 * The versioned envelope, its limits, its preview and its application.
 *
 * Five decisions, and the acceptance names every one of them.
 *
 * 1. **The file is versioned, and a version nobody knows is refused.** The envelope
 *    carries the format identifier, its version and the plugin that wrote it. An import
 *    that read a newer file as though it were this one would apply half of it and report
 *    success, which is the failure mode a version number exists to prevent.
 * 2. **There are limits, and they are checked before anything is parsed into a document.**
 *    A file is bytes from outside; a store that imported whatever arrived would be one
 *    upload away from a document nobody can render. The limits are stated here so the
 *    refusal names the number it exceeded.
 * 3. **A preview and an application are the same code read twice.** The preview validates,
 *    diffs and reports conflicts without writing; the import does exactly the same work and
 *    then writes. Two paths that decided separately would disagree about what the file
 *    means, and the disagreement would show up as a change the merchant did not see.
 * 4. **An import is a draft, and the conflict is the revision.** What arrives is written
 *    into the draft through the same compare-and-swap the editor uses, against the revision
 *    the preview was made for. A file that arrives while somebody else has published in the
 *    meantime is a conflict to report, never a silent overwrite — section 4 puts an
 *    accidental overwrite next to a lost sale, and the repository already refuses it.
 * 5. **Nothing but the document travels.** No token, no nonce, no option value, no path,
 *    no environment state: a file that leaves the store is the merchant's own configuration
 *    and nothing else about the store, and the proof asserts the absence of all of it.
 *
 * Rollback is not a feature of this class and does not need to be: an import is a draft,
 * nothing reaches the checkout until it is published, and every publication keeps a
 * revision that `SchemaRepository::restore()` brings back. The proof walks that path
 * rather than inventing a second undo.
 */
final class SchemaTransfer {

	/**
	 * Identifier of the file format.
	 */
	public const FORMAT = 'wc-checkoutsuite/schema';

	/**
	 * Version of the file format this class writes and reads.
	 */
	public const VERSION = 1;

	/**
	 * Largest file this class will read, in bytes.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Most fields a file may carry.
	 */
	public const MAX_FIELDS = 500;

	/**
	 * Most sections a file may carry.
	 */
	public const MAX_SECTIONS = 50;

	/**
	 * Most checkout profiles a file may carry.
	 */
	public const MAX_PROFILES = 20;

	/**
	 * Builds the export envelope.
	 *
	 * @param SchemaDocument $document Document to export.
	 * @return array<string, mixed>
	 */
	public static function export( SchemaDocument $document ): array {
		$array = $document->to_array();

		// The revision travels as information and never as authority: an imported file is
		// a draft, and the revision it was exported at is what the preview compares
		// against to report a conflict.
		return array(
			'format'        => self::FORMAT,
			'version'       => self::VERSION,
			'exported_at'   => gmdate( 'c' ),
			'exported_from' => array(
				'revision'       => $document->revision(),
				'schema_version' => $document->schema_version(),
				'plugin'         => defined( 'WCCS_VERSION' ) ? (string) WCCS_VERSION : '',
			),
			'schema'        => array(
				'schema_version' => $array['schema_version'] ?? 1,
				'fields'         => $array['fields'] ?? array(),
				'sections'       => $array['sections'] ?? array(),
				'settings'       => $array['settings'] ?? array(),
				'profiles'       => $array['profiles'] ?? array(),
			),
		);
	}

	/**
	 * Encodes an envelope as the file a store downloads.
	 *
	 * @param array<string, mixed> $envelope Envelope.
	 * @return string
	 */
	public static function encode( array $envelope ): string {
		$json = wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Reads a file and reports what it is, without writing anything.
	 *
	 * @param string|array<string, mixed> $payload File contents, or an already decoded envelope.
	 * @param SchemaDocument              $current Document the import would be applied over.
	 * @return array<string, mixed> Report.
	 */
	public static function inspect( $payload, SchemaDocument $current ): array {
		$report = array(
			'ok'       => false,
			'format'   => '',
			'version'  => 0,
			'errors'   => array(),
			'warnings' => array(),
			'document' => null,
			'diff'     => array(),
			'limits'   => self::limits(),
			'conflict' => false,
		);

		$envelope = $payload;

		if ( is_string( $payload ) ) {
			$bytes = strlen( $payload );

			if ( $bytes > self::MAX_BYTES ) {
				$report['errors'][] = self::error(
					'file_too_large',
					sprintf(
						/* translators: 1: size of the file in bytes, 2: largest accepted size in bytes. */
						__( 'The file is %1$d bytes and the largest accepted is %2$d.', 'wc-checkoutsuite' ),
						$bytes,
						self::MAX_BYTES
					)
				);

				return $report;
			}

			$decoded = json_decode( $payload, true );

			if ( ! is_array( $decoded ) ) {
				$report['errors'][] = self::error( 'not_json', __( 'The file is not valid JSON.', 'wc-checkoutsuite' ) );

				return $report;
			}

			$envelope = $decoded;
		}

		if ( ! is_array( $envelope ) ) {
			$report['errors'][] = self::error( 'not_an_object', __( 'The file does not contain an object.', 'wc-checkoutsuite' ) );

			return $report;
		}

		$report['format']  = isset( $envelope['format'] ) ? (string) $envelope['format'] : '';
		$report['version'] = isset( $envelope['version'] ) ? (int) $envelope['version'] : 0;

		if ( self::FORMAT !== $report['format'] ) {
			$report['errors'][] = self::error(
				'not_our_file',
				__( 'The file was not written by this plugin.', 'wc-checkoutsuite' )
			);

			return $report;
		}

		// An older file this plugin can still read would be migrated here; a newer one is
		// refused, because reading it as though it were this version applies the parts it
		// happens to share and reports success for the rest.
		if ( $report['version'] > self::VERSION ) {
			$report['errors'][] = self::error(
				'version_too_new',
				sprintf(
					/* translators: 1: version of the file, 2: version this store understands. */
					__( 'The file is version %1$d and this store understands version %2$d.', 'wc-checkoutsuite' ),
					$report['version'],
					self::VERSION
				)
			);

			return $report;
		}

		$schema = isset( $envelope['schema'] ) && is_array( $envelope['schema'] ) ? $envelope['schema'] : array();

		$fields   = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
		$sections = isset( $schema['sections'] ) && is_array( $schema['sections'] ) ? $schema['sections'] : array();
		$profiles = isset( $schema['profiles'] ) && is_array( $schema['profiles'] ) ? array_values( $schema['profiles'] ) : array();

		if ( count( $fields ) > self::MAX_FIELDS || count( $sections ) > self::MAX_SECTIONS || count( $profiles ) > self::MAX_PROFILES ) {
			$report['errors'][] = self::error(
				'too_many_things',
				sprintf(
					/* translators: 1: field count, 2: largest accepted, 3: section count, 4: largest accepted section count, 5: profile count, 6: largest accepted profile count. */
					__( 'The file carries %1$d fields, %3$d sections and %5$d checkouts; the limits are %2$d, %4$d and %6$d.', 'wc-checkoutsuite' ),
					count( $fields ),
					self::MAX_FIELDS,
					count( $sections ),
					self::MAX_SECTIONS,
					count( $profiles ),
					self::MAX_PROFILES
				)
			);

			return $report;
		}

		$document = SchemaDocument::from_array(
			array(
				'revision'       => 0,
				'schema_version' => isset( $schema['schema_version'] ) ? (int) $schema['schema_version'] : 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 0,
				'fields'         => $fields,
				'sections'       => $sections,
				'settings'       => isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : array(),
				'profiles'       => $profiles,
			)
		);

		$report['document'] = $document;
		$report['diff']     = SchemaDiff::between( $current, $document );

		// The conflict is a fact about the store and not about the file: the revision the
		// file was exported from is compared with the one the draft is at now, and a draft
		// that has moved is a draft somebody edited since.
		$exported_at = isset( $envelope['exported_from']['revision'] ) ? (int) $envelope['exported_from']['revision'] : 0;

		$report['conflict'] = $exported_at > 0 && $exported_at !== $current->revision();

		if ( $report['conflict'] ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: revision the file came from, 2: revision the store holds now. */
				__( 'The file came from revision %1$d and this store is at revision %2$d, so somebody has edited since. The preview shows what would change; nothing has been written.', 'wc-checkoutsuite' ),
				$exported_at,
				$current->revision()
			);
		}

		$report['ok'] = array() === $report['errors'];

		return $report;
	}

	/**
	 * The limits, for a screen that has to state them.
	 *
	 * @return array<string, int>
	 */
	public static function limits(): array {
		return array(
			'bytes'    => self::MAX_BYTES,
			'fields'   => self::MAX_FIELDS,
			'sections' => self::MAX_SECTIONS,
			'profiles' => self::MAX_PROFILES,
		);
	}

	/**
	 * A refusal, in the shape the rest of the administration reports one.
	 *
	 * @param string $code    Stable code.
	 * @param string $message What happened, in words.
	 * @return array{code: string, message: string}
	 */
	private static function error( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
