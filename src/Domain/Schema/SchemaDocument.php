<?php
/**
 * Schema document.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

/**
 * One immutable revision of the field schema.
 *
 * The document is stored as JSON, never as a serialised PHP object: a stored
 * object would break as soon as a class is renamed or an extension disappears.
 *
 * @see \ROADMAP.md section 13
 */
final class SchemaDocument {

	/**
	 * Schema version this build writes.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param int                             $revision          Monotonic revision number.
	 * @param int                             $schema_version    Version of the document shape.
	 * @param string                          $updated_at        ISO-8601 timestamp.
	 * @param int                             $updated_by        User id that produced the revision.
	 * @param array<int, array<string,mixed>> $fields         Field definitions.
	 * @param array<int, array<string,mixed>> $sections       Section definitions.
	 * @param array<string, mixed>            $settings          Store-wide schema settings.
	 * @param array<int, array<string,mixed>> $migration_history Applied migrations.
	 * @param array<int, array<string,mixed>> $profiles          Checkout profiles.
	 */
	public function __construct(
		private int $revision,
		private int $schema_version,
		private string $updated_at,
		private int $updated_by,
		private array $fields,
		private array $sections,
		private array $settings,
		private array $migration_history,
		private array $profiles = array()
	) {
	}

	/**
	 * An empty document at revision zero.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self( 0, self::SCHEMA_VERSION, '', 0, array(), array(), array(), array() );
	}

	/**
	 * Builds a document from a plain array, filling defaults.
	 *
	 * @param array<string, mixed> $data Raw document.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['revision'] ) ? (int) $data['revision'] : 0,
			isset( $data['schema_version'] ) ? (int) $data['schema_version'] : self::SCHEMA_VERSION,
			isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '',
			isset( $data['updated_by'] ) ? (int) $data['updated_by'] : 0,
			isset( $data['fields'] ) && is_array( $data['fields'] ) ? array_values( $data['fields'] ) : array(),
			isset( $data['sections'] ) && is_array( $data['sections'] ) ? array_values( $data['sections'] ) : array(),
			isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			isset( $data['migration_history'] ) && is_array( $data['migration_history'] ) ? array_values( $data['migration_history'] ) : array(),
			isset( $data['profiles'] ) && is_array( $data['profiles'] ) ? array_values( $data['profiles'] ) : array()
		);
	}

	/**
	 * Current revision number.
	 *
	 * @return int
	 */
	public function revision(): int {
		return $this->revision;
	}

	/**
	 * Version of the document shape.
	 *
	 * @return int
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Field definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Section definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * Store-wide schema settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Applied migrations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function migration_history(): array {
		return $this->migration_history;
	}

	/**
	 * Checkout profiles.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function profiles(): array {
		return $this->profiles;
	}

	/**
	 * Returns the next revision of this document.
	 *
	 * @param int    $user_id   User producing the revision.
	 * @param string $timestamp ISO-8601 timestamp.
	 * @return self
	 */
	public function bumped( int $user_id, string $timestamp ): self {
		return new self(
			$this->revision + 1,
			$this->schema_version,
			$timestamp,
			$user_id,
			$this->fields,
			$this->sections,
			$this->settings,
			$this->migration_history,
			$this->profiles
		);
	}

	/**
	 * Returns a copy with different fields, leaving the revision untouched.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 * @return self
	 */
	public function with_fields( array $fields ): self {
		return new self( $this->revision, $this->schema_version, $this->updated_at, $this->updated_by, array_values( $fields ), $this->sections, $this->settings, $this->migration_history, $this->profiles );
	}

	/**
	 * Returns a copy with different sections, leaving the revision untouched.
	 *
	 * @param array<int, array<string, mixed>> $sections Section definitions.
	 * @return self
	 */
	public function with_sections( array $sections ): self {
		return new self( $this->revision, $this->schema_version, $this->updated_at, $this->updated_by, $this->fields, array_values( $sections ), $this->settings, $this->migration_history, $this->profiles );
	}

	/**
	 * Returns a copy with different store-wide settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return self
	 */
	public function with_settings( array $settings ): self {
		return new self( $this->revision, $this->schema_version, $this->updated_at, $this->updated_by, $this->fields, $this->sections, $settings, $this->migration_history, $this->profiles );
	}

	/**
	 * Returns a copy with different checkout profiles.
	 *
	 * @param array<int, array<string, mixed>> $profiles Checkout profiles.
	 * @return self
	 */
	public function with_profiles( array $profiles ): self {
		return new self( $this->revision, $this->schema_version, $this->updated_at, $this->updated_by, $this->fields, $this->sections, $this->settings, $this->migration_history, array_values( $profiles ) );
	}

	/**
	 * Returns a copy carrying a different migration history.
	 *
	 * @param array<int, array<string, mixed>> $history Migration history.
	 * @return self
	 */
	public function with_migration_history( array $history ): self {
		return new self( $this->revision, $this->schema_version, $this->updated_at, $this->updated_by, $this->fields, $this->sections, $this->settings, array_values( $history ), $this->profiles );
	}

	/**
	 * Exports the document in its canonical array shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'revision'          => $this->revision,
			'schema_version'    => $this->schema_version,
			'updated_at'        => $this->updated_at,
			'updated_by'        => $this->updated_by,
			'fields'            => $this->fields,
			'sections'          => $this->sections,
			'settings'          => $this->settings,
			'migration_history' => $this->migration_history,
			'profiles'          => $this->profiles,
		);
	}

	/**
	 * Stable content hash, used to make idempotent writes detectable.
	 *
	 * @return string
	 */
	public function hash(): string {
		return hash( 'sha256', (string) wp_json_encode( $this->to_array() ) );
	}
}
