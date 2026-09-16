<?php
/**
 * Administrative assets.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin;

use WCCheckoutSuite\Admin\Routes;
use WCCheckoutSuite\Checkout\Blocks\BlocksRenderer;

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

		$version = self::asset_version();
		$tokens  = 'resources/design-tokens/tokens.css';

		if ( is_readable( WCCS_PLUGIN_DIR . $tokens ) ) {
			wp_enqueue_style(
				self::TOKENS_HANDLE,
				WCCS_PLUGIN_URL . $tokens,
				array(),
				$version
			);
		}

		$asset = self::asset_manifest();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WCCS_PLUGIN_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$version,
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
				$version
			);
		}
	}

	/**
	 * Reads the build manifest produced by the bundler.
	 *
	 * Dependencies come from the build manifest. The version is environment-aware:
	 * development uses the newest asset modification time so a rebuilt bundle is
	 * fetched immediately, while production uses the plugin version and does not
	 * create a new cache key on every request.
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
			'version'      => self::asset_version(),
		);
	}

	/**
	 * Returns the cache key for the admin assets.
	 *
	 * `WCCS_DEV_MODE`, `WP_ENVIRONMENT_TYPE=local` or `development` are explicit
	 * development switches. `WP_DEBUG` remains a fallback for older local
	 * installations that do not define the environment type. Development requests
	 * deliberately receive the current timestamp so an already-open browser tab
	 * cannot keep an older bundle after a rebuild. Production remains stable on
	 * the plugin version below.
	 *
	 * @return string
	 */
	private static function asset_version(): string {
		$environment = function_exists( 'wp_get_environment_type' )
			? (string) wp_get_environment_type()
			: '';
		$development = ( defined( 'WCCS_DEV_MODE' ) && WCCS_DEV_MODE )
			|| in_array( $environment, array( 'local', 'development' ), true )
			|| ( '' === $environment && defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( ! $development ) {
			return (string) WCCS_VERSION;
		}

		return 'dev-' . time();
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
			'version'      => WCCS_VERSION,
			'mountId'      => AdminMenu::MOUNT_ID,
			'sections'     => AdminMenu::sections(),
			// The shell's column states the store it is configuring and the version
			// doing the configuring, which is the pair a support conversation needs.
			'siteName'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'rest'         => array(
				'root'      => esc_url_raw( rest_url() ),
				'namespace' => WCCS_REST_NAMESPACE,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'routes'    => Routes::all(),
			),
			// Where the merchant can look at what was just saved. They are read here,
			// from the store, rather than assembled in the browser: the checkout page,
			// the account page and an account endpoint are all WooCommerce's own URLs,
			// and a screen that built them itself would be a second opinion about where
			// the store keeps its pages.
			'urls'         => self::surface_urls(),
			// The account editor mirrors WooCommerce's real navigation. It is read from
			// `wc_get_account_menu_items()` instead of a browser-side list, so extensions,
			// renamed endpoints and the store's own account configuration remain visible.
			'accountMenu'  => self::account_menu(),
			// Which checkout the store runs, read from the store (§6.2, §6.7): what a
			// native field may be changed into depends on it, and the merchant should not
			// have to tell the screen what their own checkout is.
			'checkoutMode' => BlocksRenderer::store_checkout_mode(),
		);
	}

	/**
	 * The current WooCommerce My Account menu for the editor and its preview.
	 *
	 * WooCommerce owns the endpoint list and applies its public
	 * `woocommerce_account_menu_items` filter here. WCCS additions therefore appear
	 * beside native entries without the admin inventing a second account menu.
	 *
	 * @return array<int, array{id: string, label: string, url: string, logout: bool, custom: bool, children?: array<int, array{id: string, label: string, url: string}>}>
	 */
	private static function account_menu(): array {
		if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
			return array();
		}

		$items = wc_get_account_menu_items();

		if ( ! is_array( $items ) ) {
			return array();
		}

		$native_endpoints = array(
			'dashboard',
			'orders',
			'downloads',
			'edit-address',
			'payment-methods',
			'edit-account',
			'customer-logout',
		);
		$menu             = array();
		foreach ( $items as $endpoint => $label ) {
			$endpoint = sanitize_key( (string) $endpoint );
			if ( '' === $endpoint ) {
				continue;
			}

			$logout = 'customer-logout' === $endpoint;
			$url    = '';
			if ( $logout && function_exists( 'wc_logout_url' ) ) {
				$url = wc_logout_url( wc_get_page_permalink( 'myaccount' ) );
			} elseif ( function_exists( 'wc_get_account_endpoint_url' ) ) {
				$url = wc_get_account_endpoint_url( $endpoint );
			}

			$entry = array(
				'id'     => $endpoint,
				'label'  => wp_strip_all_tags( (string) $label ),
				'url'    => esc_url_raw( (string) $url ),
				'logout' => $logout,
				'custom' => ! in_array( $endpoint, $native_endpoints, true ),
			);

			// WooCommerce renders these as two real child routes inside the
			// edit-address endpoint. Keep the URLs generated by WooCommerce so a
			// translated or customised endpoint remains correct in the notice.
			if ( 'edit-address' === $endpoint && function_exists( 'wc_get_endpoint_url' ) ) {
				$entry['children'] = array(
					array(
						'id'    => 'billing',
						'label' => __( 'Cobrança', 'wc-checkoutsuite' ),
						'url'   => esc_url_raw( wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ) ),
					),
					array(
						'id'    => 'shipping',
						'label' => __( 'Entrega', 'wc-checkoutsuite' ),
						'url'   => esc_url_raw( wc_get_endpoint_url( 'edit-address', 'shipping', wc_get_page_permalink( 'myaccount' ) ) ),
					),
				);
			}

			$menu[] = $entry;
		}

		return $menu;
	}

	/**
	 * The storefront addresses the editor links to.
	 *
	 * The endpoint is the one the *published* document configures, because that is the
	 * page the store is running right now: a link into a page that only exists in the
	 * draft would send the merchant to a 404 and make the save look broken.
	 *
	 * @return array<string, string>
	 */
	private static function surface_urls(): array {
		$urls = array(
			'checkout' => '',
			'account'  => '',
		);

		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return $urls;
		}

		$urls['checkout'] = esc_url_raw( wc_get_checkout_url() );
		$urls['account']  = esc_url_raw( wc_get_page_permalink( 'myaccount' ) );

		foreach ( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$section = \WCCheckoutSuite\Domain\Sections\SectionDefinition::from_array( $raw );
			$account = $section->account();
			$slug    = isset( $account['slug'] ) ? sanitize_title( (string) $account['slug'] ) : '';

			if ( '' === $slug || ! $section->is_offered_in( 'customer_account' ) ) {
				continue;
			}

			$urls['account'] = esc_url_raw( wc_get_account_endpoint_url( $slug ) );

			break;
		}

		return $urls;
	}
}
