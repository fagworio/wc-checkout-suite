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
use WCCheckoutSuite\Checkout\Blocks\BlocksCheckout;
use WCCheckoutSuite\Checkout\Blocks\BlocksRenderer;
use WCCheckoutSuite\Checkout\Blocks\BlocksValidation;
use WCCheckoutSuite\Checkout\Blocks\StoreApiExtension;
use WCCheckoutSuite\Domain\Uploads\UploadsRetention;
use WCCheckoutSuite\Domain\Uploads\UploadsTable;
use WCCheckoutSuite\Http\Checkout\DownloadController;
use WCCheckoutSuite\Http\Checkout\UploadController;
use WCCheckoutSuite\Checkout\Classic\ClassicAssets;
use WCCheckoutSuite\Checkout\Classic\ClassicCheckout;
use WCCheckoutSuite\Checkout\Classic\ClassicUploads;
use WCCheckoutSuite\Checkout\Classic\ClassicOrderFields;
use WCCheckoutSuite\Checkout\Classic\ClassicOrderUploads;
use WCCheckoutSuite\Checkout\Classic\ClassicValidation;
use WCCheckoutSuite\Http\Admin\CatalogController;
use WCCheckoutSuite\Admin\Orders\OrderFieldsPanel;
use WCCheckoutSuite\Checkout\CustomerOrderFields;
use WCCheckoutSuite\Checkout\OrderEmailFields;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Http\Admin\SettingsController;
use WCCheckoutSuite\Http\Integration\OrderFieldsController;
use WCCheckoutSuite\Privacy\OrderFieldsEraser;
use WCCheckoutSuite\Privacy\OrderFieldsExporter;
use WCCheckoutSuite\Privacy\PrivacyPolicy;
use WCCheckoutSuite\Http\Checkout\ValidationController;
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

		// The uploads table is ensured on every boot, not only on activation: a
		// plugin updated over the files never runs the activation hook, and a new
		// build with an old table would otherwise be discovered one failed upload at
		// a time.
		UploadsTable::maybe_install();

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

		// The opt-in and the compatibility state. The route can change one boolean and
		// nothing else: the schema, its revision and its history are not reachable from
		// here, which is what makes "turning it off preserves the editor" a property of
		// the code rather than a promise about it.
		// The integration API. A different audience from the administration: a caller
		// authenticated by WordPress and authorized per order, reading only the fields the
		// merchant chose to expose. Registered in the plugin's own namespace and never on
		// the Store API's, which is what keeps personal data out of the public surface.
		$integration_controller = new OrderFieldsController();

		add_action( 'rest_api_init', array( $integration_controller, 'register_routes' ) );

		$settings_controller = new SettingsController();

		add_action( 'rest_api_init', array( $settings_controller, 'register_routes' ) );

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

		// Checkout side: the remote validation endpoint.
		//
		// Registered inside `rest_api_init` for the same reason the administration
		// controller is: it reads the published document, and the repository it
		// reads through needs WooCommerce to have finished starting.
		add_action(
			'rest_api_init',
			static function (): void {
				( new ValidationController() )->register_routes();
			}
		);

		// Storefront side: the published schema applied to the Blocks checkout.
		//
		// Registered unconditionally, and hooked on `woocommerce_blocks_loaded` so
		// the registration happens after WooCommerce has built its checkout fields.
		// The adapter reads the published document only, like the classic one, so an
		// unpublished edit cannot reach a customer.
		BlocksCheckout::register();

		// Storefront side: the fields the Blocks checkout cannot render natively,
		// rendered by this plugin's own components. Registered next to the native
		// adapter because they are the two halves of one decision, and the gate that
		// decides whether the bundle belongs on the request lives in the class.
		BlocksRenderer::register();

		// Blocks checkout: the namespace the controlled values travel under, and the
		// validation that decides whether the order may be placed. Registered
		// together because the namespace exists for the validation: a payload nobody
		// checks would be a payload nobody should trust.
		StoreApiExtension::register();
		BlocksValidation::register();

		// Checkout side: the upload endpoint.
		//
		// Registered inside `rest_api_init` for the same reason the other two
		// controllers are, and its own gate decides whether it accepts anything: a
		// store whose private directory is not protected refuses every upload with
		// that as the reason.
		add_action(
			'rest_api_init',
			static function (): void {
				( new UploadController() )->register_routes();
				( new DownloadController() )->register_routes();
			}
		);

		// Storefront side: the checkout bundle and its component lifecycle.
		//
		// The gate is in the class, not here: it checks the screen and whether the
		// published schema has anything the classic checkout can render, so a
		// storefront request for any other page pays nothing.
		ClassicAssets::register();

		// Storefront side: the file field, which the classic checkout has no type
		// for. Registered next to the adapter because it is the adapter's rendering
		// half for that one type.
		ClassicUploads::register();

		// Storefront side: the uploads a checkout submitted, bound to the order it
		// created. Registered with the other order writers because it is one.
		ClassicOrderUploads::register();

		// Retention: the uploads nobody claimed, and the ones whose order is gone.
		// Registered with the storefront half because the job runs on a request of
		// its own, not on a customer's.
		UploadsRetention::register();

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

		// The order editor. Registered on both the legacy posts screen and the HPOS
		// orders screen, through the hook WooCommerce fires for each, and it reads and
		// writes only through the order CRUD — which is what makes "identical on both
		// backends" a property of the storage path rather than a promise about it.
		OrderFieldsPanel::register();

		// The privacy tools. The two flows a person can start — a copy of their data and
		// its erasure — and the policy text the store is offered, all registered on
		// WordPress's own hooks so the store's existing tools answer for this plugin too.
		OrderFieldsExporter::register();
		OrderFieldsEraser::register();
		PrivacyPolicy::register();

		// The customer's own view of the order. Registered on the template hook the
		// thank-you page and My Account both fire, so the panel appears where the store
		// has already decided this order may be seen by this person — the policy this
		// plugin adds is per field, not a second access check.
		CustomerOrderFields::register();

		// The order e-mails. The hook every order e-mail template fires carries which
		// audience the message is for and which format part is being rendered, so the two
		// projections — customer or store, HTML or text — are decided from what the
		// platform hands over rather than from a setting that could disagree with it.
		OrderEmailFields::register();
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

		// The uploads table is created here so a fresh activation has it before
		// anything can try to write to it. It is also ensured on every boot, because
		// a plugin update does not run this hook and a new build with an old table
		// would be discovered one failed upload at a time.
		UploadsTable::maybe_install();
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
