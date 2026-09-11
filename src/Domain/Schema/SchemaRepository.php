<?php
/**
 * Schema repository with compare-and-swap writes.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use InvalidArgumentException;
use WCCheckoutSuite\Domain\Fields\DefinitionValidator;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Conditions\ConditionValidator;
use WCCheckoutSuite\Domain\Sections\SectionValidator;

/**
 * Stores the draft, the published schema and the publication history.
 *
 * Every write is a compare-and-swap. Reading a revision and then writing it back
 * without an atomic guard is exactly the lost-update bug the planning forbids,
 * so the write goes through a single conditional SQL statement and a zero row
 * count is reported as a conflict rather than being ignored.
 *
 * Options are created with autoload disabled: the schema is read on the admin
 * screen and on checkout, never on every request.
 *
 * @see \ROADMAP.md section 13
 */
final class SchemaRepository {

	/**
	 * Draft slot name.
	 */
	public const SLOT_DRAFT = 'draft';

	/**
	 * Published slot name.
	 */
	public const SLOT_PUBLISHED = 'published';

	/**
	 * Option prefix shared by every slot.
	 */
	private const OPTION_PREFIX = 'wccs_schema_';

	/**
	 * Option holding the publication history.
	 */
	private const HISTORY_OPTION = 'wccs_schema_revisions';

	/**
	 * Default number of snapshots kept.
	 */
	public const DEFAULT_HISTORY_LIMIT = 20;

	/**
	 * Constructor.
	 *
	 * @param DefinitionValidator $validator     Validator used when publishing.
	 * @param CoreFieldGuard      $guard         Protection for the fields WooCommerce owns.
	 * @param int                 $history_limit How many snapshots to keep.
	 */
	public function __construct(
		private DefinitionValidator $validator,
		private CoreFieldGuard $guard,
		private int $history_limit = self::DEFAULT_HISTORY_LIMIT
	) {
	}

	/**
	 * Reads one slot.
	 *
	 * @param string $slot Slot name.
	 * @return SchemaDocument
	 */
	public function read( string $slot ): SchemaDocument {
		$raw = $this->raw( self::option_for( $slot ) );

		return null === $raw ? SchemaDocument::empty() : $this->decode( $raw );
	}

	/**
	 * Reports whether a slot can be read, without pretending it is empty.
	 *
	 * {@see self::read()} answers an unreadable document with an empty one, which
	 * is right for every caller that only wants a document and wrong for the one
	 * that has to tell the merchant what happened. A schema written by a newer
	 * build showed up as "no fields yet": the fields exist and are invisible, and
	 * the two situations ask for opposite actions.
	 *
	 * @param string $slot Slot name.
	 * @return array{state: string, stored_version: int|null}
	 */
	public function read_status( string $slot ): array {
		$raw = $this->raw( self::option_for( $slot ) );

		if ( null === $raw ) {
			return array(
				'state'          => 'absent',
				'stored_version' => null,
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'state'          => 'corrupt',
				'stored_version' => null,
			);
		}

		$version = isset( $decoded['schema_version'] ) ? (int) $decoded['schema_version'] : null;

		if ( null !== $version && $version > SchemaDocument::SCHEMA_VERSION ) {
			return array(
				'state'          => 'unsupported_version',
				'stored_version' => $version,
			);
		}

		return array(
			'state'          => 'readable',
			'stored_version' => $version,
		);
	}

	/**
	 * Writes one slot under a compare-and-swap guard.
	 *
	 * @param string         $slot              Slot name.
	 * @param SchemaDocument $document          Document to store.
	 * @param int|null       $expected_revision Revision the caller read; null disables the guard.
	 * @return WriteResult
	 */
	public function write( string $slot, SchemaDocument $document, ?int $expected_revision = null ): WriteResult {
		$option      = self::option_for( $slot );
		$current_raw = $this->raw( $option );
		$current     = null === $current_raw ? SchemaDocument::empty() : $this->decode( $current_raw );

		if ( null !== $expected_revision && $current->revision() !== $expected_revision ) {
			return WriteResult::conflict( $current->revision() );
		}

		// Core protection is checked on every write, including a draft write. A
		// rule enforced only at publish time would be no rule at all: the draft
		// is what publish copies, so a document that has already lost a core
		// field would simply be published later.
		$protection = $this->guard->guard( $current->fields(), $document->fields() );

		if ( ! $protection->is_valid() ) {
			return WriteResult::invalid( $protection->errors() );
		}

		// The origin rule is applied here as well as at publication. A draft that
		// claims a WooCommerce identifier as a custom field is not work in
		// progress; it is the first half of a bypass, and letting it sit in the
		// draft would only move the refusal to a later moment.
		$origins = $this->check_origins( $document );

		if ( ! $origins->is_valid() ) {
			return WriteResult::invalid( $origins->errors() );
		}

		// Section integrity is the third structural rule a draft obeys. A field
		// pointing at a section the document does not declare is not work in
		// progress: it is a field that would be configured, saved and never
		// rendered anywhere.
		$sections = $this->check_sections( $document );

		if ( ! $sections->is_valid() ) {
			return WriteResult::invalid( $sections->errors() );
		}

		$new_raw = (string) wp_json_encode( $document->to_array() );

		if ( $this->cas_write( $option, $new_raw, $current_raw ) ) {
			return WriteResult::ok( $document->revision() );
		}

		$fresh = $this->raw( $option );

		return WriteResult::conflict( null === $fresh ? 0 : $this->decode( $fresh )->revision() );
	}

	/**
	 * Applies the section rules to a document.
	 *
	 * @param SchemaDocument $document Document to check.
	 * @return ValidationResult
	 */
	private function check_sections( SchemaDocument $document ): ValidationResult {
		return SectionValidator::validate_sections( $document->sections() )->merge(
			SectionValidator::validate_references( $document->sections(), $document->fields() )
		);
	}

	/**
	 * Applies the origin rule to every field of a document.
	 *
	 * This is the only rule from full validation that a draft write enforces.
	 * Everything else is deliberately left alone so a half-finished field can be
	 * saved and continued later.
	 *
	 * @param SchemaDocument $document Document to check.
	 * @return ValidationResult
	 */
	private function check_origins( SchemaDocument $document ): ValidationResult {
		$result = ValidationResult::valid();

		foreach ( $document->fields() as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			$result = $result->merge(
				$this->validator->validate_origin( FieldDefinition::from_array( $raw_field ) )
			);
		}

		return $result;
	}

	/**
	 * Publishes a draft: full validation, then an atomic replacement.
	 *
	 * History is appended after the swap succeeds. The swap is what makes the
	 * published schema correct; a failed history append cannot corrupt it.
	 *
	 * @param SchemaDocument $draft                 Draft to publish.
	 * @param int|null       $expected_draft_revision Revision of the draft the caller edited.
	 * @param int            $user_id               User publishing.
	 * @return WriteResult
	 */
	public function publish( SchemaDocument $draft, ?int $expected_draft_revision, int $user_id ): WriteResult {
		if ( null !== $expected_draft_revision && $draft->revision() !== $expected_draft_revision ) {
			return WriteResult::conflict( $draft->revision() );
		}

		$validation = $this->validate( $draft );

		if ( ! $validation->is_valid() ) {
			return WriteResult::invalid( $validation->errors() );
		}

		$published = $this->read( self::SLOT_PUBLISHED );

		// The baseline at publish time is the published document, because that
		// is the state the store is actually running. The draft was already
		// guarded against its own previous revision, so this second comparison
		// closes the case of a core field that reached the draft by a path that
		// predates the rule.
		$protection = $this->guard->guard( $published->fields(), $draft->fields() );

		if ( ! $protection->is_valid() ) {
			return WriteResult::invalid( $protection->errors() );
		}

		$timestamp = gmdate( 'c' );

		$document = new SchemaDocument(
			$published->revision() + 1,
			$draft->schema_version(),
			$timestamp,
			$user_id,
			$draft->fields(),
			$draft->sections(),
			$draft->settings(),
			$draft->migration_history()
		);

		$result = $this->write( self::SLOT_PUBLISHED, $document, $published->revision() );

		if ( $result->is_ok() ) {
			$this->append_history( $document, $user_id, $timestamp );
		}

		return $result;
	}

	/**
	 * Publication history, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function revisions(): array {
		$raw = get_option( self::HISTORY_OPTION );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Republishes a previous revision as a new revision.
	 *
	 * History is never rewritten: restoring creates a new entry and leaves the
	 * original snapshot in place.
	 *
	 * @param int $revision Revision to restore.
	 * @param int $user_id  User restoring.
	 * @return WriteResult
	 */
	public function restore( int $revision, int $user_id ): WriteResult {
		$snapshot = null;

		foreach ( $this->revisions() as $entry ) {
			if ( isset( $entry['revision'] ) && (int) $entry['revision'] === $revision ) {
				$snapshot = $entry;
				break;
			}
		}

		if ( null === $snapshot || ! isset( $snapshot['document'] ) || ! is_array( $snapshot['document'] ) ) {
			return WriteResult::invalid(
				array(
					array(
						'code'    => 'unknown_revision',
						'message' => sprintf( 'The revision %d does not exist in the publication history.', $revision ),
						'context' => array( 'revision' => $revision ),
					),
				)
			);
		}

		$document = SchemaDocument::from_array( $snapshot['document'] );

		return $this->publish( $document, null, $user_id );
	}

	/**
	 * Validates every field in a document.
	 *
	 * @param SchemaDocument $document Document.
	 * @return ValidationResult
	 */
	public function validate( SchemaDocument $document ): ValidationResult {
		$result = ValidationResult::valid();

		$result = $result->merge( SectionValidator::validate_sections( $document->sections() ) );
		$result = $result->merge( SectionValidator::validate_references( $document->sections(), $document->fields() ) );

		// Conditions are the one thing a field declares about another field, and
		// the questions that raises — does the field exist, can this operator read
		// what it holds, do two fields depend on each other — can only be answered
		// with the whole document in hand.
		$result = $result->merge( ConditionValidator::validate_document( $document->fields() ) );

		foreach ( $document->fields() as $index => $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_field_entry',
						sprintf( 'The field at index %d is not a definition.', (int) $index ),
						array( 'index' => (int) $index )
					)
				);
				continue;
			}

			$field_result = $this->validator->validate( FieldDefinition::from_array( $raw_field ) );

			foreach ( $field_result->errors() as $error ) {
				$context          = $error['context'];
				$context['field'] = isset( $raw_field['id'] ) ? (string) $raw_field['id'] : (string) $index;

				$result = $result->merge(
					ValidationResult::invalid( (string) $error['code'], (string) $error['message'], $context )
				);
			}
		}

		return $result;
	}

	/**
	 * Option name of a slot.
	 *
	 * @param string $slot Slot name.
	 * @return string
	 */
	public static function option_for( string $slot ): string {
		return self::OPTION_PREFIX . $slot;
	}

	/**
	 * Reads the raw stored value, bypassing the object cache.
	 *
	 * The compare-and-swap needs the exact stored string, so it must not read a
	 * cached copy.
	 *
	 * @param string $option Option name.
	 * @return string|null
	 */
	private function raw( string $option ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw read is required to compare-and-swap on the stored value.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Writes a slot only if it still holds the expected raw value.
	 *
	 * @param string      $option       Option name.
	 * @param string      $new_raw      Value to store.
	 * @param string|null $expected_raw Value the caller read, or null when creating the option.
	 * @return bool True when the write was applied.
	 */
	private function cas_write( string $option, string $new_raw, ?string $expected_raw ): bool {
		if ( null === $expected_raw ) {
			// add_option fails atomically when the option already exists.
			return (bool) add_option( $option, $new_raw, '', false );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional single-statement write is the compare-and-swap guard.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				$option,
				$expected_raw
			)
		);

		wp_cache_delete( $option, 'options' );

		if ( 1 === (int) $updated ) {
			return true;
		}

		// Zero rows means either a lost race or a write that changed nothing.
		return $this->raw( $option ) === $new_raw;
	}

	/**
	 * Decodes a stored document, tolerating corrupted data.
	 *
	 * @param string $raw Stored JSON.
	 * @return SchemaDocument
	 */
	private function decode( string $raw ): SchemaDocument {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return SchemaDocument::empty();
		}

		try {
			$decoded = SchemaMigrations::migrate( $decoded );
		} catch ( InvalidArgumentException $exception ) {
			// A document from a newer build is not read with the wrong shape.
			return SchemaDocument::empty();
		}

		return SchemaDocument::from_array( $decoded );
	}

	/**
	 * Appends a snapshot to the publication history.
	 *
	 * @param SchemaDocument $document  Published document.
	 * @param int            $user_id   User publishing.
	 * @param string         $timestamp ISO-8601 timestamp.
	 * @return void
	 */
	private function append_history( SchemaDocument $document, int $user_id, string $timestamp ): void {
		$history = $this->revisions();

		array_unshift(
			$history,
			array(
				'revision'     => $document->revision(),
				'published_at' => $timestamp,
				'published_by' => $user_id,
				'hash'         => $document->hash(),
				'document'     => $document->to_array(),
			)
		);

		if ( $this->history_limit > 0 && count( $history ) > $this->history_limit ) {
			$history = array_slice( $history, 0, $this->history_limit );
		}

		update_option( self::HISTORY_OPTION, (string) wp_json_encode( $history ), false );
	}
}
