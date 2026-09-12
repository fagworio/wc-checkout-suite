<?php
/**
 * Administrative assets.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin;

use WCCheckoutSuite\Admin\Routes;

/**
 * Loads the admin bundle on the suite screen and nowhere else.
 *
 * The planning is explicit that nothing may be added to the catalogue or to
 * unrelated admin screens, so the gate is a single comparison against the
 * screen id the menu derives. Everything else in wp-admin stays untouched, and
 * no other screen pays for this plugin's CSS or JavaScript.
 *
 * @see \ROADMAP.md sections 16 and 21
 */
final class Assets {

	/**
	 * Handle of the administration application.
	 */
	public const SCRIPT_HANDLE = 'wccs-admin';

	/**
	 * Handle of the compiled component styles.
	 */
	public const STYLE_HANDLE = 'wccs-admin';

	/**
	 * Handle of the design tokens stylesheet.
	 */
	public const TOKENS_HANDLE = 'wccs-tokens';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the assets belong on a screen.
	 *
	 * Extracted so the decision is testable without an admin request: the gate
	 * is a plain comparison, and a mistake here would leak the plugin into every
	 * admin page.
	 *
	 * @param string $screen_id Screen id.
	 * @return bool
	 */
	public static function should_enqueue( string $screen_id ): bool {
		return '' !== $screen_id && AdminMenu::screen_id() === $screen_id;
	}

	/**
	 * Enqueues the assets when the current screen is the suite screen.
	 *
	 * @param string $screen_id Current screen id.
	 * @return void
	 */
	public static function enqueue( string $screen_id = '' ): void {
		if ( ! self::should_enqueue( $screen_id ) ) {
			return;
		}

		$tokens = 'resources/design-tokens/tokens.css';

		if ( is_readable( WCCS_PLUGIN_DIR . $tokens ) ) {
			wp_enqueue_style(
				self::TOKENS_HANDLE,
				WCCS_PLUGIN_URL . $tokens,
				array(),
				WCCS_VERSION
			);
		}

		$asset = self::asset_manifest();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WCCS_PLUGIN_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			WCCS_TEXT_DOMAIN,
			WCCS_PLUGIN_DIR . 'languages'
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wccsAdmin = ' . wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);

		$stylesheet = 'build/admin/index.css';

		if ( is_readable( WCCS_PLUGIN_DIR . $stylesheet ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				WCCS_PLUGIN_URL . $stylesheet,
				array( self::TOKENS_HANDLE ),
				$asset['version']
			);
		}
	}

	/**
	 * Reads the build manifest produced by the bundler.
	 *
	 * Dependencies and the cache-busting version come from the build, never from
	 * a hand maintained list that would drift the first time an import changes.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	public static function asset_manifest(): array {
		$defaults = array(
			'dependencies' => array(),
			'version'      => WCCS_VERSION,
		);

		$path = WCCS_PLUGIN_DIR . 'build/admin/index.asset.php';

		if ( ! is_readable( $path ) ) {
			return $defaults;
		}

		$manifest = require $path;

		if ( ! is_array( $manifest ) ) {
			return $defaults;
		}

		return array(
			'dependencies' => isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] )
				? array_values( array_map( 'strval', $manifest['dependencies'] ) )
				: array(),
			'version'      => isset( $manifest['version'] ) ? (string) $manifest['version'] : WCCS_VERSION,
		);
	}

	/**
	 * Data the application needs before it can render.
	 *
	 * The REST namespace, the route map and the nonce come from the server so the
	 * client never hardcodes a path that could be renamed here. The nonce is the
	 * standard `wp_rest` nonce, which protects against CSRF and is not an
	 * authorisation: the capability check on each route is what authorises.
	 *
	 * @see \ROADMAP.md sections 19 and 20
	 *
	 * @return array<string, mixed>
	 */
	public static function bootstrap_data(): array {
		return array(
			'version'  => WCCS_VERSION,
			'mountId'  => AdminMenu::MOUNT_ID,
			'sections' => AdminMenu::sections(),
			// The shell's column states the store it is configuring and the version
			// doing the configuring, which is the pair a support conversation needs.
			'siteName' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'rest'     => array(
				'root'      => esc_url_raw( rest_url() ),
				'namespace' => WCCS_REST_NAMESPACE,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'routes'    => Routes::all(),
			),
		);
	}
}
