<?php
/**
 * Versioned field type stub.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Stubs;

use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Field type that declares a chosen contract version, used to exercise the
 * registry's version guard.
 */
final class VersionedStubType extends AbstractFieldType {

	/**
	 * Constructor.
	 *
	 * @param string $key     Type key.
	 * @param string $version Contract version it declares.
	 */
	public function __construct(
		private string $key,
		private string $version
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Stub';
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return $this->version;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value   Value to validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		unset( $value, $context );

		return ValidationResult::valid();
	}
}
