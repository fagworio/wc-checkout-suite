<?php
/**
 * The seam between the store and a gateway.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

use WC_Order;

/**
 * What a gateway integration has to implement to be asked to act.
 *
 * This is the seam and not an integration. §20 says what the store may ask a gateway to do and when
 * it may ask; **how** a particular gateway does it is knowledge that only exists beside the gateway
 * — which endpoint, which fields, which identifier comes back — and this plugin ships none of it,
 * because a gateway integration written without the gateway to run it against is a guess with a
 * class name.
 *
 * So an adapter is registered by whoever has the gateway in front of them
 * ({@see PaymentAdapterRegistry}), and the contract is small on purpose: name the gateway, name the
 * actions you have proven, and perform one of them. Everything that makes those calls safe — the
 * single call per action, the audit, the fallback, the decision about whether a payment is complete
 * — belongs to the service, so that no adapter can get it wrong.
 *
 * The `$context` a call receives carries what the caller knows and the adapter may need: the
 * idempotency key the action is recorded under, the amount, and whatever the caller was given.
 *
 * @see \ROADMAP.md sections 13.7, 20
 */
interface PaymentActionAdapterInterface {

	/**
	 * Version of this contract the adapter implements.
	 *
	 * Versioned like every other contract in this plugin, so a caller can refuse an adapter written
	 * against a shape it does not know rather than call it and hope.
	 *
	 * @return string
	 */
	public function contract_version(): string;

	/**
	 * The gateway this adapter serves, as WooCommerce reports it.
	 *
	 * @return string
	 */
	public function gateway(): string;

	/**
	 * The actions this adapter has proven it can perform.
	 *
	 * Read as a claim, and used only to decide whether calling it is worth trying: what an interface
	 * may *offer* comes from the capability registry, which demands evidence. An adapter that lists
	 * an action here and cannot perform it answers `unsupported`, and the store falls back.
	 *
	 * @return array<int, string>
	 */
	public function actions(): array;

	/**
	 * Performs one action.
	 *
	 * Called **once** per action per order by the service, which records the call before making it.
	 * An adapter must therefore not be written to be retried: if it cannot say whether something
	 * happened, it says `pending`, and the store waits for a human rather than asking again.
	 *
	 * @param string               $action  Action key.
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $context Idempotency key, amount and whatever the caller knows.
	 * @return PaymentActionResult
	 */
	public function execute( string $action, WC_Order $order, array $context = array() ): PaymentActionResult;
}
