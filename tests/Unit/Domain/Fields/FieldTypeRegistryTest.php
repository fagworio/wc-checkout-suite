<?php
/**
 * Registry behaviour tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Tests\Unit\Stubs\VersionedStubType;

/**
 * Covers registration, collision handling and the contract version guard.
 */
final class FieldTypeRegistryTest extends TestCase {

	/**
	 * A registered type is retrievable and exposes its label.
	 *
	 * @return void
	 */
	public function test_registered_type_is_retrievable(): void {
		$registry = new FieldTypeRegistry();
		$registry->register_type( new VersionedStubType( 'stub', '1.0' ) );

		self::assertTrue( $registry->has( 'stub' ) );
		self::assertInstanceOf( VersionedStubType::class, $registry->type( 'stub' ) );
		self::assertSame( array( 'stub' => 'Stub' ), $registry->labels() );
	}

	/**
	 * An unknown key yields null rather than an error.
	 *
	 * @return void
	 */
	public function test_unknown_key_yields_null(): void {
		$registry = new FieldTypeRegistry();

		self::assertNull( $registry->type( 'missing' ) );
	}

	/**
	 * Registering the same key twice is refused and reported.
	 *
	 * @return void
	 */
	public function test_duplicate_key_is_refused_and_reported(): void {
		$registry = new FieldTypeRegistry();
		$registry->register_type( new VersionedStubType( 'stub', '1.0' ), 'first' );

		$accepted = $registry->register_type( new VersionedStubType( 'stub', '1.0' ), 'second' );

		self::assertFalse( $accepted );
		self::assertTrue( $registry->has_diagnostics() );
		self::assertSame( 'duplicate_key', $registry->diagnostics()[0]['code'] );
		self::assertSame( 'first', $registry->source_of( 'stub' ), 'the first registration is kept' );
	}

	/**
	 * A type declaring a different major contract version is refused.
	 *
	 * @return void
	 */
	public function test_incompatible_contract_version_is_refused(): void {
		$registry = new FieldTypeRegistry();

		$accepted = $registry->register_type( new VersionedStubType( 'future', '2.0' ) );

		self::assertFalse( $accepted );
		self::assertFalse( $registry->has( 'future' ) );
		self::assertSame( 'incompatible_contract_version', $registry->diagnostics()[0]['code'] );
	}

	/**
	 * A newer minor version of the same major is accepted.
	 *
	 * @return void
	 */
	public function test_newer_minor_of_the_same_major_is_accepted(): void {
		$registry = new FieldTypeRegistry();

		self::assertTrue( $registry->register_type( new VersionedStubType( 'compatible', '1.7' ) ) );
	}

	/**
	 * An empty key is refused.
	 *
	 * @return void
	 */
	public function test_empty_key_is_refused(): void {
		$registry = new FieldTypeRegistry();

		self::assertFalse( $registry->register_type( new VersionedStubType( '', '1.0' ) ) );
		self::assertSame( 'empty_key', $registry->diagnostics()[0]['code'] );
	}
}
