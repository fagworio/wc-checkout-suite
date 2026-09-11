<?php
/**
 * Renderer registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Fields\AbstractRegistry;

/**
 * Holds the renderers available to each adapter.
 *
 * A type without a renderer for a given adapter is reported as unsupported
 * rather than being silently rendered by something generic. Silent fallbacks are
 * how a checkout ends up with a control nobody designed.
 */
final class RendererRegistry extends AbstractRegistry {

	/**
	 * Adapters this build knows about.
	 *
	 * @var array<int, string>
	 */
	public const ADAPTERS = array( 'classic', 'blocks', 'admin' );

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
		return 'renderer';
	}

	/**
	 * Registers a renderer.
	 *
	 * @param RendererInterface $renderer Renderer.
	 * @param string            $source   Origin, for diagnostics.
	 * @return bool
	 */
	public function register_renderer( RendererInterface $renderer, string $source = 'core' ): bool {
		$unknown = array_diff( $renderer->adapters(), self::ADAPTERS );

		if ( array() !== $unknown ) {
			$this->add_diagnostic(
				'unknown_adapter',
				$renderer->key(),
				sprintf(
					'The renderer "%1$s" from %2$s declares unknown adapters: %3$s.',
					$renderer->key(),
					$source,
					implode( ', ', $unknown )
				)
			);

			return false;
		}

		return $this->register( $renderer->key(), $renderer, $source );
	}

	/**
	 * Returns a renderer or null.
	 *
	 * @param string $key Renderer key.
	 * @return RendererInterface|null
	 */
	public function renderer( string $key ): ?RendererInterface {
		$renderer = $this->get( $key );

		return $renderer instanceof RendererInterface ? $renderer : null;
	}

	/**
	 * Whether a renderer serves an adapter.
	 *
	 * This is what the capability matrix reads to decide between "native",
	 * "suite component" and "unsupported".
	 *
	 * @param string $key     Renderer key.
	 * @param string $adapter Adapter name.
	 * @return bool
	 */
	public function supports( string $key, string $adapter ): bool {
		$renderer = $this->renderer( $key );

		return null !== $renderer && in_array( $adapter, $renderer->adapters(), true );
	}

	/**
	 * Keys of every renderer serving an adapter.
	 *
	 * @param string $adapter Adapter name.
	 * @return array<int, string>
	 */
	public function keys_for_adapter( string $adapter ): array {
		$keys = array();

		foreach ( $this->all() as $key => $renderer ) {
			if ( $renderer instanceof RendererInterface && in_array( $adapter, $renderer->adapters(), true ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
}
