<?php
/**
 * Moves one order's Blocks-native values into the Suite's own storage, and reports both.
 *
 * This exists because the user-level observation found a **gap between two halves of the
 * plugin**, and the gap has to be shown rather than asserted in prose:
 *
 * - A Suite field the Blocks checkout can render natively is registered through
 *   WooCommerce's additional-fields API, and **WooCommerce** persists it — the order
 *   carries `_wc_billing/wc-checkoutsuite/<field>`, exactly as section 13's table says it
 *   should ("Additional field nativo Blocks: helpers e política da API WooCommerce").
 * - The Suite's own storage — `_wccs_fields`, which every projection reads through
 *   `OrderFieldsService` — never receives those values, because the Store API extension
 *   carries only the fields **this plugin draws itself**.
 *
 * So on a store where the field was captured natively, the value is on the order and no
 * Suite surface shows it. This script prints both halves for one order (the evidence), and
 * then puts the native values into the Suite payload **through the same call the order
 * screen makes when staff save the order** (`OrderFieldsService::write`), so the other
 * observations in this run can look at the areas without depending on the gap.
 *
 * It is a fixture, not a fix: the fix belongs to whoever owns the native persistence path.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/f14-place-suite-values.php <order-id>
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wccs_order_id = isset( $args[0] ) ? (int) $args[0] : 0;
$wccs_order    = $wccs_order_id > 0 ? wc_get_order( $wccs_order_id ) : null;

if ( ! $wccs_order instanceof WC_Order ) {
	echo 'no order ' . $wccs_order_id . PHP_EOL;
	exit( 1 );
}

$wccs_document    = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();
$wccs_definitions = $wccs_document->fields();
$wccs_service     = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();

// What the Suite's own storage already holds.
$wccs_before = $wccs_service->read( $wccs_order )->all();

// What WooCommerce persisted for the fields it rendered itself, by location prefix.
$wccs_prefixes = array( '_wc_billing/', '_wc_shipping/', '_wc_other/' );
$wccs_native   = array();

foreach ( $wccs_definitions as $wccs_raw ) {
	if ( ! is_array( $wccs_raw ) ) {
		continue;
	}

	$wccs_id = isset( $wccs_raw['id'] ) ? (string) $wccs_raw['id'] : '';

	if ( '' === $wccs_id ) {
		continue;
	}

	// WooCommerce stores a registered additional field under the identifier it was
	// registered with, which is the integration identifier (`namespace/name`), not the
	// definition's own id.
	$wccs_registered = isset( $wccs_raw['integration_id'] ) && '' !== (string) $wccs_raw['integration_id']
		? (string) $wccs_raw['integration_id']
		: 'wc-checkoutsuite/' . $wccs_id;

	foreach ( $wccs_prefixes as $wccs_prefix ) {
		$wccs_value = $wccs_order->get_meta( $wccs_prefix . $wccs_registered, true, 'edit' );

		if ( '' !== $wccs_value && null !== $wccs_value && array() !== $wccs_value ) {
			$wccs_native[ $wccs_id ] = $wccs_value;

			break;
		}
	}
}

echo 'order=' . $wccs_order_id . PHP_EOL;
echo 'suite payload before=' . wp_json_encode( $wccs_before ) . PHP_EOL;
echo 'woocommerce native meta=' . wp_json_encode( $wccs_native ) . PHP_EOL;

$wccs_values = array_merge( $wccs_before, $wccs_native );

if ( $wccs_values === $wccs_before ) {
	echo 'nothing to move: the Suite payload already has everything WooCommerce stored' . PHP_EOL;
} else {
	// The call the order screen makes when staff save the order: the Suite's own storage,
	// with the snapshot of the labels the order is read back with.
	$wccs_service->write( $wccs_order, $wccs_values, $wccs_definitions, $wccs_document->revision() );
	$wccs_order->save();

	echo 'suite payload after=' . wp_json_encode( $wccs_service->read( wc_get_order( $wccs_order_id ) )->all() ) . PHP_EOL;
}

// The hold the checkout hook would have applied, applied here by the same class. At the
// real checkout it did not happen either, for the same reason the payload was empty: the
// approval flow reads the Suite's values, and the value was never there.
$wccs_held = \WCCheckoutSuite\Domain\Approval\ReviewStatus::apply( wc_get_order( $wccs_order_id ) );

echo 'approval hold applied=' . ( $wccs_held ? 'yes' : 'no' )
	. ' status=' . wc_get_order( $wccs_order_id )->get_status() . PHP_EOL;
