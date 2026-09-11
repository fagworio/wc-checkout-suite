<?php
/**
 * Writes the Suite's field values when a classic checkout creates an order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WC_Order;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;

/**
 * Persists the values a classic checkout validated, and nothing else.
 *
 * The hook is `woocommerce_checkout_create_order`, which WooCommerce fires after
 * the order object exists and before it saves it. Adding the meta there means the
 * values are written by the same `save()` that writes the order, in the same
 * transaction, instead of by a second write that can fail on its own.
 *
 * The values come from the validation pass rather than from the posted array.
 * That is not a shortcut: it is the only way this class can know the difference
 * between an unchecked box and an empty text field, and it is what makes the
 * value on the order the value that was validated.
 *
 * @see ROADMAP.md section 13
 * @see \docs/adr/ADR-0001-storage-authority.md
 */
final class ClassicOrderFields {

	/**
	 * Constructor.
	 *
	 * @param ClassicValidation $validation The submission this request validated.
	 */
	public function __construct( private ClassicValidation $validation ) {
	}

	/**
	 * Registers the persistence hook.
	 *
	 * The validation instance is passed in rather than built here, so a checkout
	 * has one submission and one outcome from start to finish.
	 *
	 * @param ClassicValidation $validation Instance that ran the validation hooks.
	 * @return void
	 */
	public static function register( ClassicValidation $validation ): void {
		add_action( 'woocommerce_checkout_create_order', array( new self( $validation ), 'persist' ), 20 );
	}

	/**
	 * Stores the validated values on the order WooCommerce is creating.
	 *
	 * Typed loosely and checked: the action is public, and refusing to write to
	 * something that is not an order is better than a fatal error in the middle of
	 * someone else's checkout.
	 *
	 * @param mixed $order Order WooCommerce built from the checkout.
	 * @return void
	 */
	public function persist( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$values = $this->validation->storable_values();

		if ( array() === $values ) {
			// An order that has just been created cannot carry Suite values that
			// need clearing, so a store with no Suite fields — or a checkout where
			// every field was hidden — does not read this order's meta at all.
			return;
		}

		$document = PublishedDocument::read();

		( new OrderFieldsService() )->write(
			$order,
			$values,
			$document->fields(),
			$document->revision()
		);
	}
}
