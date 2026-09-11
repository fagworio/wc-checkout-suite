<?php
/**
 * Normalizer registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Fields\AbstractRegistry;
use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Holds the named normalizers a field definition may reference.
 *
 * Registering a normalizer with an incompatible contract version is refused, so
 * an extension built against a future contract cannot load silently and corrupt
 * stored values.
 */
final class NormalizerRegistry extends AbstractRegistry {

	/**
	 * Contract version this registry accepts.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * Human readable kind of item, used in diagnostics.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'normalizer';
	}

	/**
	 * Registers a normalizer.
	 *
	 * @param NormalizerInterface $normalizer Normalizer.
	 * @param string              $source     Origin, for diagnostics.
	 * @return bool
	 */
	public function register_normalizer( NormalizerInterface $normalizer, string $source = 'core' ): bool {
		return $this->register( $normalizer->key(), $normalizer, $source );
	}

	/**
	 * Returns a normalizer or null.
	 *
	 * @param string $key Normalizer key.
	 * @return NormalizerInterface|null
	 */
	public function normalizer( string $key ): ?NormalizerInterface {
		$normalizer = $this->get( $key );

		return $normalizer instanceof NormalizerInterface ? $normalizer : null;
	}

	/**
	 * Runs a normalizer by key.
	 *
	 * An unknown key leaves the value untouched: normalization is a refinement,
	 * and the definition validator is what refuses an unknown key at
	 * configuration time.
	 *
	 * @param string       $key     Normalizer key.
	 * @param mixed        $value   Value.
	 * @param FieldContext $context Context.
	 * @return mixed
	 */
	public function run( string $key, mixed $value, FieldContext $context ): mixed {
		$normalizer = $this->normalizer( $key );

		if ( null === $normalizer ) {
			return $value;
		}

		return $normalizer->normalize( $value, $context );
	}
}
