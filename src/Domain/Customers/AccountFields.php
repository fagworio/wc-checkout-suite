<?php
/**
 * WooCommerce My Account field inventory.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Customers;

/**
 * Describes the fields WooCommerce renders on the native account-details page.
 *
 * WooCommerce does not expose the edit-account template through the checkout
 * field API. The template is the source of truth for this page: these entries
 * keep its identifiers, order, required contract and password group intact.
 * They are the default inventory. A field is copied into the WCCS document only as a
 * lazy override when a merchant edits or hides it; the native value and save handler
 * remain owned by WooCommerce.
 */
final class AccountFields {

	/**
	 * Returns the native account field inventory.
	 *
	 * @return array{available: bool, reason: string, sections: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}
	 */
	public function catalogue(): array {
		if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
			return $this->unavailable( __( 'WooCommerce is not active, so its account fields cannot be listed.', 'wc-checkoutsuite' ) );
		}

		if ( function_exists( 'did_action' ) && ! did_action( 'woocommerce_init' ) ) {
			return $this->unavailable( __( 'WooCommerce has not finished starting, so its account fields cannot be listed yet.', 'wc-checkoutsuite' ) );
		}

		$menu = wc_get_account_menu_items();

		if ( ! is_array( $menu ) || ! array_key_exists( 'edit-account', $menu ) ) {
			return array(
				'available' => true,
				'reason'    => '',
				'sections'  => array(),
				'fields'    => array(),
			);
		}

		$fields = array(
			$this->field( 'account_first_name', $this->woo_label( 'First name' ), 'text', true, 10, array( 'form-row-first' ) ),
			$this->field( 'account_last_name', $this->woo_label( 'Last name' ), 'text', true, 20, array( 'form-row-last' ) ),
			$this->field( 'account_display_name', $this->woo_label( 'Display name' ), 'text', true, 30, array( 'form-row-wide' ), $this->woo_label( 'This will be how your name will be displayed in the account section' ) ),
			$this->field( 'account_email', $this->woo_label( 'Email address' ), 'email', true, 40, array( 'form-row-wide' ) ),
			$this->field( 'password_current', $this->woo_label( 'Current password (leave blank to leave unchanged)' ), 'password', false, 50, array( 'form-row-wide' ), '', 'password' ),
			$this->field( 'password_1', $this->woo_label( 'New password (leave blank to leave unchanged)' ), 'password', false, 60, array( 'form-row-wide' ), '', 'password' ),
			$this->field( 'password_2', $this->woo_label( 'Confirm new password' ), 'password', false, 70, array( 'form-row-wide' ), '', 'password' ),
		);

		$inventory = array(
			'available' => true,
			'reason'    => '',
			'sections'  => array(
				array(
					'key'    => 'edit-account',
					'label'  => (string) $menu['edit-account'],
					'fields' => $fields,
				),
			),
			'fields'    => $fields,
		);

		/**
		 * Filters the native WooCommerce account field inventory.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $inventory Inventory.
		 */
		return apply_filters( 'wccs_account_fields_inventory', $inventory );
	}

	/**
	 * Creates one entry using the native template contract.
	 *
	 * @param string             $id          Field identifier.
	 * @param string             $label       Native label.
	 * @param string             $native_type Native input type.
	 * @param bool               $required    Whether WooCommerce requires it.
	 * @param int                $priority    Native template order.
	 * @param array<int, string> $classes     Native row classes.
	 * @param string             $description Native help text.
	 * @param string             $group       Native field group.
	 * @return array<string, mixed>
	 */
	private function field( string $id, string $label, string $native_type, bool $required, int $priority, array $classes, string $description = '', string $group = 'details' ): array {
		return array(
			'id'                => $id,
			'section'           => 'edit-account',
			'label'             => $label,
			'description'       => $description,
			'type'              => 'password' === $native_type ? 'text' : $native_type,
			'nativeType'        => $native_type,
			'typeRemapped'      => 'password' === $native_type,
			'required'          => $required,
			'priority'          => $priority,
			'classes'           => $classes,
			'layout'            => $this->layout_from_classes( $classes ),
			'protected'         => true,
			'group'             => $group,
			'source'            => 'woocommerce_account',
			'collectionSurface' => 'my_account',
		);
	}

	/**
	 * Converts WooCommerce's row classes to the editor grid.
	 *
	 * @param array<int, string> $classes Native row classes.
	 * @return array<string, int>
	 */
	private function layout_from_classes( array $classes ): array {
		$width = in_array( 'form-row-first', $classes, true ) || in_array( 'form-row-last', $classes, true ) ? 6 : 12;

		return array(
			'desktop' => $width,
			'tablet'  => $width,
			'mobile'  => 12,
		);
	}

	/**
	 * Translates a string with WooCommerce's loaded text domain.
	 *
	 * @param string $text Source string from the native template.
	 * @return string Translated string.
	 */
	private function woo_label( string $text ): string {
		return (string) translate( $text, 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- The label belongs to WooCommerce's native template.
	}

	/**
	 * Unavailable inventory.
	 *
	 * @param string $reason Reason.
	 * @return array{available: bool, reason: string, sections: array<int, mixed>, fields: array<int, mixed>}
	 */
	private function unavailable( string $reason ): array {
		return array(
			'available' => false,
			'reason'    => $reason,
			'sections'  => array(),
			'fields'    => array(),
		);
	}
}
