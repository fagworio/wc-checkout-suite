<?php
/**
 * Preset registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Holds every registered field preset.
 */
final class PresetRegistry extends AbstractRegistry {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'field preset';
	}

	/**
	 * Registers a preset.
	 *
	 * @param Preset $preset Preset.
	 * @param string $source Origin, for diagnostics.
	 * @return bool
	 */
	public function register_preset( Preset $preset, string $source = 'core' ): bool {
		return $this->register( $preset->key(), $preset, $source );
	}

	/**
	 * Returns a preset or null.
	 *
	 * @param string $key Preset key.
	 * @return Preset|null
	 */
	public function preset( string $key ): ?Preset {
		$preset = $this->get( $key );

		return $preset instanceof Preset ? $preset : null;
	}

	/**
	 * All presets, keyed by key.
	 *
	 * @return array<string, Preset>
	 */
	public function presets(): array {
		/**
		 * Registered presets, keyed by preset key.
		 *
		 * @var array<string, Preset>
		 */
		$presets = array_filter(
			$this->all(),
			static function ( $preset ): bool {
				return $preset instanceof Preset;
			}
		);

		return $presets;
	}

	/**
	 * Presets grouped for the admin picker.
	 *
	 * @param bool $enabled_only Whether to skip presets that ship disabled.
	 * @return array<string, array<string, Preset>>
	 */
	public function grouped( bool $enabled_only = false ): array {
		$grouped = array();

		foreach ( $this->presets() as $key => $preset ) {
			if ( $enabled_only && ! $preset->is_enabled() ) {
				continue;
			}

			$grouped[ $preset->group() ][ $key ] = $preset;
		}

		return $grouped;
	}

	/**
	 * Whether every preset points at a registered field type.
	 *
	 * A preset built on a type that is no longer registered must surface as a
	 * diagnostic rather than as a broken picker entry.
	 *
	 * @param FieldTypeRegistry $types Field type registry.
	 * @return array<int, string> Keys of presets whose type is missing.
	 */
	public function orphaned( FieldTypeRegistry $types ): array {
		$orphaned = array();

		foreach ( $this->presets() as $key => $preset ) {
			if ( ! $types->has( $preset->type() ) ) {
				$orphaned[] = $key;
			}
		}

		return $orphaned;
	}
}
