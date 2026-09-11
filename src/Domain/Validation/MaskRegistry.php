<?php
/**
 * Mask registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Fields\AbstractRegistry;

/**
 * Holds the declarative masks the product ships and extensions may add.
 *
 * A mask whose definition looks like executable code is refused: the
 * configuration path must never become code injection.
 */
final class MaskRegistry extends AbstractRegistry {

	/**
	 * Human readable kind of item, used in diagnostics.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'mask';
	}

	/**
	 * Registers a mask.
	 *
	 * @param Mask   $mask   Mask.
	 * @param string $source Origin, for diagnostics.
	 * @return bool
	 */
	public function register_mask( Mask $mask, string $source = 'core' ): bool {
		if ( ! $mask->is_declarative() ) {
			$this->add_diagnostic(
				'non_declarative_mask',
				$mask->key(),
				sprintf(
					'The mask "%1$s" from %2$s contains executable-looking content and was rejected. Masks must be declarative data.',
					$mask->key(),
					$source
				)
			);

			return false;
		}

		return $this->register( $mask->key(), $mask, $source );
	}

	/**
	 * Returns a mask or null.
	 *
	 * @param string $key Mask key.
	 * @return Mask|null
	 */
	public function mask( string $key ): ?Mask {
		$mask = $this->get( $key );

		return $mask instanceof Mask ? $mask : null;
	}

	/**
	 * Exports every registered mask as an array for the admin client.
	 *
	 * Only declarative data crosses to the browser; there is no code to execute.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function to_array(): array {
		$masks = array();

		foreach ( $this->all() as $mask ) {
			if ( $mask instanceof Mask ) {
				$masks[] = $mask->to_array();
			}
		}

		return $masks;
	}
}
