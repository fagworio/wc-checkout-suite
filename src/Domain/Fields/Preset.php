<?php
/**
 * Field preset value object.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * A named starting point built on top of a registered field type.
 *
 * A preset is data, never code: the merchant-facing "create a preset" flow may
 * combine a type with a mask, options, validation and appearance, but it can
 * never paste executable PHP or JavaScript.
 *
 * @see \ROADMAP.md section 6
 */
final class Preset {

	/**
	 * Constructor.
	 *
	 * @param string               $key         Stable preset key, e.g. `br.cnpj`.
	 * @param string               $label       Translatable label.
	 * @param string               $type        Key of the field type it is built on.
	 * @param array<string, mixed> $defaults    Default field definition values.
	 * @param array<string, mixed> $settings    Default per-type settings.
	 * @param string               $group       Grouping used by the admin picker.
	 * @param bool                 $enabled     Whether the preset is offered by default.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private string $type,
		private array $defaults = array(),
		private array $settings = array(),
		private string $group = 'general',
		private bool $enabled = true
	) {
	}

	/**
	 * Stable preset key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Translatable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Key of the underlying field type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Default field definition values.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return $this->defaults;
	}

	/**
	 * Default per-type settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Grouping used by the admin picker.
	 *
	 * @return string
	 */
	public function group(): string {
		return $this->group;
	}

	/**
	 * Whether the preset is offered by default.
	 *
	 * Presets declared optional in the planning ship disabled and are enabled
	 * deliberately by the merchant.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Exports the preset as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'type'     => $this->type,
			'defaults' => $this->defaults,
			'settings' => $this->settings,
			'group'    => $this->group,
			'enabled'  => $this->enabled,
		);
	}
}
