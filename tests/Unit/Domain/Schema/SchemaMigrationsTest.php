<?php
/**
 * Schema migration tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaMigrations;

/**
 * Covers version stamping and the refusal of documents from a newer build.
 */
final class SchemaMigrationsTest extends TestCase {

	/**
	 * A document without a version is stamped and the step is recorded.
	 *
	 * @return void
	 */
	public function test_missing_version_is_stamped_and_recorded(): void {
		$migrated = SchemaMigrations::migrate( array( 'fields' => array() ), '2026-09-11T00:00:00+00:00' );

		self::assertSame( SchemaMigrations::CURRENT_VERSION, $migrated['schema_version'] );
		self::assertSame( 'stamp_missing_version', $migrated['migration_history'][0]['step'] );
		self::assertSame( '2026-09-11T00:00:00+00:00', $migrated['migration_history'][0]['at'] );
	}

	/**
	 * A document already at the current version is left alone and records nothing.
	 *
	 * @return void
	 */
	public function test_current_version_records_no_step(): void {
		$migrated = SchemaMigrations::migrate( array( 'schema_version' => SchemaMigrations::CURRENT_VERSION ) );

		self::assertSame( array(), $migrated['migration_history'] );
	}

	/**
	 * A document from a newer build is refused instead of being misread.
	 *
	 * @return void
	 */
	public function test_newer_version_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		SchemaMigrations::migrate( array( 'schema_version' => SchemaMigrations::CURRENT_VERSION + 1 ) );
	}

	/**
	 * The supported range is bounded on both sides.
	 *
	 * @return void
	 */
	public function test_supported_range_is_bounded(): void {
		self::assertFalse( SchemaMigrations::is_supported( 0 ), 'version zero means "stamp me", not "supported"' );
		self::assertTrue( SchemaMigrations::is_supported( 1 ) );
		self::assertFalse( SchemaMigrations::is_supported( SchemaMigrations::CURRENT_VERSION + 1 ) );
	}

	/**
	 * Document defaults keep the canonical shape.
	 *
	 * @return void
	 */
	public function test_document_defaults_fill_the_canonical_shape(): void {
		$document = SchemaDocument::from_array( array( 'fields' => array( array( 'id' => 'a' ) ) ) )->to_array();

		self::assertSame( 0, $document['revision'] );
		self::assertSame( SchemaDocument::SCHEMA_VERSION, $document['schema_version'] );
		self::assertSame( array(), $document['sections'] );
		self::assertCount( 1, $document['fields'] );
	}

	/**
	 * A round trip through arrays is lossless.
	 *
	 * @return void
	 */
	public function test_round_trip_is_lossless(): void {
		$original = SchemaDocument::empty()
			->with_fields(
				array(
					array(
						'id'   => 'billing_document',
						'type' => 'text',
					),
				)
			)
			->with_settings( array( 'layout' => 'two-column' ) )
			->bumped( 7, '2026-09-11T00:00:00+00:00' )
			->to_array();

		self::assertSame( $original, SchemaDocument::from_array( $original )->to_array() );
	}
}
