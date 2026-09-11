<?php
/**
 * The hooks the storefront half of this plugin registers.
 *
 * Four harnesses assert that this list is *exactly* what the plugin adds, because an
 * unnoticed hook on a storefront request is an unnoticed cost and an unnoticed
 * behaviour. Keeping the list in one place is what makes that assertion maintainable
 * rather than something four files each have to remember in the same commit — which
 * is what happened twice, once for WCCS-043 and once for WCCS-044.
 *
 * @package WCCheckoutSuite
 */

if ( ! function_exists( 'wccs_proof_expected_hooks' ) ) {
	/**
	 * Every hook the storefront half registers, as `tag@priority:method`.
	 *
	 * @return array<int, string>
	 */
	function wccs_proof_expected_hooks(): array {
		return array(
			'woocommerce_after_checkout_validation@20:collect_errors',
			// WCCS-044: the uploads a checkout submitted, bound to the order.
			'woocommerce_checkout_create_order@20:bind',
			'woocommerce_checkout_create_order@20:persist',
			'woocommerce_checkout_fields@20:filter_fields',
			'woocommerce_checkout_posted_data@20:normalize_posted_data',
			// WCCS-043: the classic checkout has no file type, and this is the
			// documented filter an unknown type reaches.
			'woocommerce_form_field_file@10:render',
			'wp_enqueue_scripts@10:enqueue',
		);
	}
}
