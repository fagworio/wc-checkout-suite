<?php
/**
 * Schema migrations.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use InvalidArgumentException;

/**
 * Brings a stored document up to the schema version this build understands.
 *
 * A document written by a newer build is refused instead of being read with the
 * wrong assumptions. Stored definitions keep a `schema_version` and a migration
 * history so a change of shape is a recorded operation, never a silent guess.
 *
 * @see \ROADMAP.md section 13
 */
final class SchemaMigrations {

	/**
	 * Schema version this build writes.
	 */
	public const CURRENT_VERSION = SchemaDocument::SCHEMA_VERSION;

	/**
	 * Whether a stored schema version can be read by this build.
	 *
	 * @param int $version Stored version.
	 * @return bool
	 */
	public static function is_supported( int $version ): bool {
		return $version >= 1 && $version <= self::CURRENT_VERSION;
	}

	/**
	 * Migrates a raw document to the current schema version.
	 *
	 * @param array<string, mixed> $document  Raw document.
	 * @param string               $timestamp ISO-8601 timestamp of the migration.
	 * @return array<string, mixed> Migrated document.
	 * @throws InvalidArgumentException When the document was written by a newer build.
	 */
	public static function migrate( array $document, string $timestamp = '' ): array {
		$timestamp = '' !== $timestamp ? $timestamp : gmdate( 'c' );
		$version   = isset( $document['schema_version'] ) ? (int) $document['schema_version'] : 0;

		if ( $version > self::CURRENT_VERSION ) {
			throw new InvalidArgumentException(
				sprintf(
					'The stored schema version %1$s is newer than the version %2$s this build supports.',
					esc_html( (string) $version ),
					esc_html( (string) self::CURRENT_VERSION )
				)
			);
		}

		$history = isset( $document['migration_history'] ) && is_array( $document['migration_history'] )
			? array_values( $document['migration_history'] )
			: array();

		if ( 0 === $version ) {
			// Documents written before the version stamp existed.
			$history[] = array(
				'step' => 'stamp_missing_version',
				'from' => 0,
				'to'   => 1,
				'at'   => $timestamp,
			);
			$version   = 1;
		}

		// Future shape changes are applied here, one step at a time, so the
		// history records exactly what happened to the stored data.
		for ( $step = $version + 1; $step <= self::CURRENT_VERSION; $step++ ) {
			$history[] = array(
				'step' => 'upgrade',
				'from' => $step - 1,
				'to'   => $step,
				'at'   => $timestamp,
			);
		}

		$document['schema_version']    = self::CURRENT_VERSION;
		$document['migration_history'] = $history;

		return $document;
	}
}
