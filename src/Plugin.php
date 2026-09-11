<?php
/**
 * Plugin bootstrap.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite;

use WCCheckoutSuite\Admin\AdminMenu;
use WCCheckoutSuite\Admin\Assets;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Checkout\Classic\ClassicCheckout;
use WCCheckoutSuite\Checkout\Classic\ClassicOrderFields;
use WCCheckoutSuite\Checkout\Classic\ClassicValidation;
use WCCheckoutSuite\Http\Admin\CatalogController;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Support\Requirements;

/**
 * Entry point of the plugin.
 *
 * Responsibilities are deliberately narrow at this stage: verify that the
 * environment can run the plugin, refuse to load anything else when it cannot,
 * and expose a single action hook (`wccs_booted`) that modules attach to later.
 */
final class Plugin {

	/**
	 * Whether the plugin finished booting successfully.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Requirements that were not met during this request.
	 *
	 * @var array<int, array{key: string, label: string, required: string, observed: string, reason: string}>
	 */
	private static array $unmet = array();

	/**
	 * Boots the plugin.
	 *
	 * Never throws and never touches WooCommerce classes: when a requirement is
	 * missing the plugin stays inactive and explains itself.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		$unmet = self::unmet_requirements();

		if ( array() !== $unmet ) {
			self::$unmet = $unmet;
			add_action( 'admin_notices', array( __CLASS__, 'render_requirements_notice' ) );
			return;
		}

		// Requirements are satisfied now; clear a stale activation record.
		if ( false !== get_option( WCCS_OPTION_REQUIREMENTS ) ) {
			delete_option( WCCS_OPTION_REQUIREMENTS );
		}

		self::$booted = true;

		// Populate the domain registries and let extensions contribute to them.
		// Core types go through the same public API an external plugin uses.
		Registries::boot();

		// Administrative schema endpoints. The repository reports outcomes; the
		// controller is the only layer that turns them into HTTP statuses.
		//
		// The repository is built inside the callback rather than here on
		// purpose. Its definition validator needs the list of checkout fields
		// WooCommerce owns, and that list cannot be read at `plugins_loaded`:
		// WooCommerce builds its countries object later, and reading the fields
		// before that is a fatal error rather than an exception. `rest_api_init`
		// runs well after WooCommerce has finished starting.
		add_action(
			'rest_api_init',
			static function (): void {
				$controller = new SchemaController(
					new SchemaRepository(
						Registries::instance()->definition_validator(),
						new CoreFieldGuard()
					)
				);

				$controller->register_routes();
			}
		);

		// Read-only catalogue the admin picker draws from: the registered field
		// types and the checkout fields WooCommerce owns. Both are reads, so
		// neither adds a path that can change stored state.
		$catalog_controller = new CatalogController();

		add_action( 'rest_api_init', array( $catalog_controller, 'register_routes' ) );

		// Storefront side: the published schema applied to the classic checkout.
		//
		// Registered unconditionally for the same reason as the admin hooks: the
		// WooCommerce filter it listens to only fires on a checkout request. The
		// adapter reads the published document only, so an unpublished edit cannot
		// reach a customer.
		ClassicCheckout::register();

		// Storefront side: normalization and validation of what was submitted.
		//
		// The two halves are registered together because they are the same
		// decision seen from two ends of one request: the value that is validated
		// is the value that was normalized, and splitting the registration would
		// invite a caller to install one without the other.
		$validation = ClassicValidation::register();

		// Storefront side: the validated values written onto the order.
		//
		// Handed the instance that ran the validation, so what lands on the order
		// is what was validated rather than a second reading of the request.
		ClassicOrderFields::register( $validation );

		// Administrative screen and its assets.
		//
		// The hooks are registered unconditionally: `admin_menu` and
		// `admin_enqueue_scripts` never fire outside wp-admin, so a storefront
		// request pays nothing for them. The gate that actually matters is the
		// per-screen check in Assets::should_enqueue(), which is what keeps the
		// bundle off every other admin screen. Guarding registration with
		// is_admin() instead would buy nothing and would make the wiring
		// impossible to exercise from WP-CLI.
		AdminMenu::register();
		Assets::register();

		/**
		 * Fires once the plugin has verified its requirements and is active.
		 *
		 * Modules register their hooks here. Nothing is loaded before this point,
		 * so a site without WooCommerce pays no cost beyond this class.
		 *
		 * @since 0.1.0
		 */
		do_action( 'wccs_booted' );
	}

	/**
	 * Activation callback.
	 *
	 * Activation always succeeds: blocking it would leave the merchant with an
	 * opaque error. When a requirement is missing the reason is recorded so the
	 * admin notice can explain it.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$unmet = self::unmet_requirements();

		if ( array() !== $unmet ) {
			update_option( WCCS_OPTION_REQUIREMENTS, $unmet, false );
			return;
		}

		delete_option( WCCS_OPTION_REQUIREMENTS );
	}

	/**
	 * Deactivation callback.
	 *
	 * Deliberately does nothing destructive. The merchant's data is preserved;
	 * what uninstall removes is a separate, explicit policy (WCCS-068).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally empty.
	}

	/**
	 * Requirements that are not satisfied by the current environment.
	 *
	 * @return array<int, array{key: string, label: string, required: string, observed: string, reason: string}>
	 */
	public static function unmet_requirements(): array {
		return Requirements::evaluate( self::observed_versions(), self::required_versions() );
	}

	/**
	 * Minimum versions this build requires.
	 *
	 * @return array<string, string>
	 */
	public static function required_versions(): array {
		return array(
			'php'         => WCCS_MIN_PHP,
			'wordpress'   => WCCS_MIN_WP,
			'woocommerce' => WCCS_MIN_WC,
		);
	}

	/**
	 * Versions observed in the current environment.
	 *
	 * WooCommerce is read from its version constant only; no WooCommerce class
	 * is instantiated, so an absent WooCommerce is reported instead of fatal.
	 *
	 * @return array<string, string>
	 */
	public static function observed_versions(): array {
		return array(
			'php'         => PHP_VERSION,
			'wordpress'   => (string) get_bloginfo( 'version' ),
			'woocommerce' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
		);
	}

	/**
	 * Whether the plugin booted successfully in this request.
	 *
	 * @return bool
	 */
	public static function is_booted(): bool {
		return self::$booted;
	}

	/**
	 * Requirements recorded at activation time.
	 *
	 * @return array<int, array{key: string, label: string, required: string, observed: string, reason: string}>
	 */
	public static function recorded_unmet_requirements(): array {
		$stored = get_option( WCCS_OPTION_REQUIREMENTS );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		// Rebuild every entry from scratch: the option is read back from the
		// database, so its shape cannot be trusted.
		$recorded = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( ! isset( $entry['key'], $entry['label'], $entry['required'], $entry['reason'] ) ) {
				continue;
			}

			$recorded[] = array(
				'key'      => (string) $entry['key'],
				'label'    => (string) $entry['label'],
				'required' => (string) $entry['required'],
				'observed' => isset( $entry['observed'] ) ? (string) $entry['observed'] : '',
				'reason'   => (string) $entry['reason'],
			);
		}

		return $recorded;
	}

	/**
	 * Renders the admin notice explaining why the plugin is inactive.
	 *
	 * @return void
	 */
	public static function render_requirements_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$unmet = array() !== self::$unmet ? self::$unmet : self::recorded_unmet_requirements();

		if ( array() === $unmet ) {
			return;
		}

		$lines = array();

		foreach ( $unmet as $entry ) {
			$lines[] = Requirements::describe( $entry );
		}

		$message = sprintf(
			/* translators: 1: plugin name, 2: list of missing requirements */
			esc_html__( '%1$s is inactive. %2$s', 'wc-checkoutsuite' ),
			'<strong>WC CheckoutSuite</strong>',
			esc_html( implode( ' ', $lines ) )
		);

		if ( function_exists( 'wp_admin_notice' ) ) {
			wp_admin_notice(
				$message,
				array(
					'type'        => 'error',
					'dismissible' => true,
				)
			);
			return;
		}

		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}
