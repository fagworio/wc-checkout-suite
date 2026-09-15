<?php
/**
 * Administrative menu and screen.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin;

/**
 * Registers the plugin screen inside WooCommerce.
 *
 * The screen is a submenu of `woocommerce`, so it appears where a store owner
 * already looks for checkout settings. The screen id WordPress derives from it
 * is the single value that decides whether the plugin assets are loaded at all.
 *
 * @see \ROADMAP.md section 16
 */
final class AdminMenu {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'wccs-checkoutsuite';

	/**
	 * Capability required to see and use the screen.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * DOM id of the element the admin application mounts into.
	 */
	public const MOUNT_ID = 'wccs-admin-root';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Adds the submenu page.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WC CheckoutSuite', 'wc-checkoutsuite' ),
			__( 'WC CheckoutSuite', 'wc-checkoutsuite' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Screen id WordPress derives from the menu slug.
	 *
	 * Kept as a method rather than a literal so the asset gate and the menu
	 * registration can never disagree.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		return 'woocommerce_page_' . self::SLUG;
	}

	/**
	 * Navigation sections of the suite, in display order.
	 *
	 * The list is the one fixed by the planning. Labels are translatable; the
	 * identifiers are stable English keys that never change once published.
	 *
	 * @see \ROADMAP.md section 16
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public static function sections(): array {
		return array(
			array(
				'id'    => 'fields',
				'label' => __( 'Fields', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'sections',
				'label' => __( 'Sections', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'rules',
				'label' => __( 'Rules', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'appearance',
				'label' => __( 'Appearance', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'statuses',
				'label' => __( 'Order statuses', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'checkout-page',
				'label' => __( 'Checkout page', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'import-export',
				'label' => __( 'Import / Export', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'diagnostics',
				'label' => __( 'Diagnostics', 'wc-checkoutsuite' ),
			),
			array(
				'id'    => 'settings',
				'label' => __( 'Settings', 'wc-checkoutsuite' ),
			),
		);
	}

	/**
	 * Identifiers of the navigation sections.
	 *
	 * @return array<int, string>
	 */
	public static function section_ids(): array {
		return array_column( self::sections(), 'id' );
	}

	/**
	 * Renders the screen.
	 *
	 * Only the mount node is printed. Everything inside it is drawn by the
	 * administration application, so a failure in the bundle degrades to an
	 * empty screen instead of a broken page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage checkout fields.', 'wc-checkoutsuite' ),
				esc_html__( 'Forbidden', 'wc-checkoutsuite' ),
				array( 'response' => 403 )
			);
		}

		printf(
			'<div class="wrap"><div id="%1$s" class="wccs-admin"><noscript><p>%2$s</p></noscript></div></div>',
			esc_attr( self::MOUNT_ID ),
			esc_html__( 'The WC CheckoutSuite administration interface requires JavaScript.', 'wc-checkoutsuite' )
		);
	}
}
