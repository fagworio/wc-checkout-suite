<?php
/**
 * Mask declaration.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

/**
 * A declarative input mask.
 *
 * The definition is data — a pattern or a small configuration array compatible
 * with the mask library — and never executable code. The merchant-facing preset
 * editor must not become a way to paste JavaScript into the checkout.
 *
 * @see \ROADMAP.md sections 6 and 9
 */
final class Mask {

	/**
	 * Configuration keys a declarative mask may use.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_KEYS = array(
		'type',
		'pattern',
		'placeholderChar',
		'lazy',
		'blocks',
		'definitions',
		'options',
		'min',
		'max',
		'scale',
		'thousandsSeparator',
		'radix',
		'mapToRadix',
		'mask',
		'enum',
		'flags',
		'normalize',
	);

	/**
	 * Characters that never appear in a mask pattern.
	 *
	 * A coarse guard against a definition that looks like code, kept because it
	 * catches the shapes that matter — `alert(1);`, `() => 1`, `function(){}`,
	 * `<script>` — without needing to understand any language.
	 *
	 * Parentheses are **not** in the list. They are literal characters in a
	 * written format, which is what a mask pattern is: the Brazilian telephone
	 * mask is `(00) 00000-0000`, and rejecting it as code-shaped would make a
	 * correct definition impossible to register. The definitions are data consumed
	 * by the mask library and are never evaluated, so the parentheses carry no
	 * meaning beyond the ones a person sees.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_PATTERN_CHARS = array( ';', '{', '}', '<', '>', '\\', '`', '$', '=' );

	/**
	 * Constructor.
	 *
	 * @param string              $key        Stable key, e.g. `br.cnpj`.
	 * @param string|array<mixed> $definition Declarative pattern or configuration.
	 * @param int                 $version    Version of the mask definition.
	 * @param array<int, string>  $applies_to Scope hints, e.g. `br` or a type key.
	 */
	public function __construct(
		private string $key,
		private string|array $definition,
		private int $version = 1,
		private array $applies_to = array()
	) {
	}

	/**
	 * Stable mask key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Version of the mask definition.
	 *
	 * A field definition stores the mask version it was configured against, so a
	 * later change can be detected instead of silently altering stored values.
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Declarative definition.
	 *
	 * @return string|array<mixed>
	 */
	public function definition(): string|array {
		return $this->definition;
	}

	/**
	 * Scope hints.
	 *
	 * @return array<int, string>
	 */
	public function applies_to(): array {
		return $this->applies_to;
	}

	/**
	 * Whether the definition is data only, with no executable code.
	 *
	 * @return bool
	 */
	public function is_declarative(): bool {
		return $this->definition_is_declarative( $this->definition );
	}

	/**
	 * Exports the mask as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'        => $this->key,
			'version'    => $this->version,
			'definition' => $this->definition,
			'applies_to' => $this->applies_to,
		);
	}

	/**
	 * Recursively checks that a definition contains data only.
	 *
	 * @param mixed $value Value to inspect.
	 * @param int   $depth Current recursion depth.
	 * @return bool
	 */
	private function definition_is_declarative( mixed $value, int $depth = 0 ): bool {
		if ( $depth > 5 ) {
			return false;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
					return false;
				}

				if ( ! $this->definition_is_declarative( $item, $depth + 1 ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_string( $value ) ) {
			foreach ( self::FORBIDDEN_PATTERN_CHARS as $char ) {
				if ( str_contains( $value, $char ) ) {
					return false;
				}
			}

			return true;
		}

		return is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value;
	}
}
