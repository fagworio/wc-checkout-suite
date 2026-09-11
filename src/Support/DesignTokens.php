<?php
/**
 * Design token access.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Support;

/**
 * Reads the design tokens that the admin and the checkout share.
 *
 * `resources/design-tokens/tokens.json` is the canonical source. The matching
 * CSS custom properties are a second copy by necessity, so a unit test compares
 * the two and fails when they drift.
 *
 * @see \ROADMAP.md sections 17 and 18
 */
final class DesignTokens {

	/**
	 * Path of the canonical token file, relative to the plugin root.
	 */
	public const FILE = 'resources/design-tokens/tokens.json';

	/**
	 * Path of the generated CSS custom properties, relative to the plugin root.
	 */
	public const CSS_FILE = 'resources/design-tokens/tokens.css';

	/**
	 * Loaded tokens, cached per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Absolute path of a plugin file.
	 *
	 * Falls back to the location of this class when the plugin constant is not
	 * defined, which is the case in the unit suite: the tokens must be testable
	 * without booting WordPress.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	public static function path( string $relative = '' ): string {
		$root = defined( 'WCCS_PLUGIN_DIR' )
			? rtrim( (string) WCCS_PLUGIN_DIR, '/' )
			: dirname( __DIR__, 2 );

		return '' === $relative ? $root : $root . '/' . ltrim( $relative, '/' );
	}

	/**
	 * All tokens.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the token file is missing or unreadable.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$path = self::path( self::FILE );

		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException(
				sprintf( 'The design token file "%s" is not readable.', esc_html( $path ) )
			);
		}

		$decoded = wp_json_file_decode( $path, array( 'associative' => true ) );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				sprintf( 'The design token file "%s" is not valid JSON.', esc_html( $path ) )
			);
		}

		self::$cache = $decoded;

		return self::$cache;
	}

	/**
	 * One theme, or an empty array when it does not exist.
	 *
	 * @param string $theme Theme name, `light` or `dark`.
	 * @return array<string, mixed>
	 */
	public static function theme( string $theme ): array {
		$themes = self::all()['themes'] ?? array();

		return is_array( $themes ) && isset( $themes[ $theme ] ) && is_array( $themes[ $theme ] )
			? $themes[ $theme ]
			: array();
	}

	/**
	 * Colour tokens of a theme.
	 *
	 * @param string $theme Theme name.
	 * @return array<string, string>
	 */
	public static function colors( string $theme ): array {
		$colors = self::theme( $theme )['color'] ?? array();

		return is_array( $colors ) ? $colors : array();
	}

	/**
	 * One colour token.
	 *
	 * @param string $theme Theme name.
	 * @param string $token Token name, using underscores.
	 * @return string|null
	 */
	public static function color( string $theme, string $token ): ?string {
		$colors = self::colors( $theme );

		return isset( $colors[ $token ] ) ? (string) $colors[ $token ] : null;
	}

	/**
	 * Theme independent tokens.
	 *
	 * @return array<string, mixed>
	 */
	public static function shared(): array {
		$shared = self::all()['shared'] ?? array();

		return is_array( $shared ) ? $shared : array();
	}

	/**
	 * Documented contrast requirements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function contrast_requirements(): array {
		$requirements = self::all()['contrast']['requirements'] ?? array();

		return is_array( $requirements ) ? array_values( $requirements ) : array();
	}

	/**
	 * Colour tokens that are deliberately exempt from a contrast threshold.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function contrast_exemptions(): array {
		$exempt = self::all()['contrast']['exempt'] ?? array();

		return is_array( $exempt ) ? array_values( $exempt ) : array();
	}

	/**
	 * CSS custom property name of a token.
	 *
	 * Colours are the primary tokens and carry no group segment, so the colour
	 * `brand_hover` becomes `--wccs-brand-hover` rather than
	 * `--wccs-color-brand-hover`. Every other group keeps its segment.
	 *
	 * @param string $group Token group, e.g. `color`, `radius`, `size`.
	 * @param string $token Token name using underscores.
	 * @return string
	 */
	public static function css_variable( string $group, string $token ): string {
		$name = 'color' === $group ? $token : $group . '_' . $token;

		return '--wccs-' . str_replace( '_', '-', $name );
	}

	/**
	 * Drops the cached tokens. Only useful in tests.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
