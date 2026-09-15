<?php
/**
 * Trusted condition context for a checkout request.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Builds the trusted context a condition rule is evaluated against.
 *
 * Section 11 requires the server to recompute conditions "com contexto confiável
 * do carrinho e do cliente", and this is where that context comes from: WooCommerce's
 * own session and customer objects, never the request body. Every entry is a value
 * the server holds, so a rule cannot be turned off by editing a form field.
 *
 * What two of the sources hold was not decided by the planning, which names them
 * without saying what is in them, so the choice is recorded here and asserted in
 * the proof rather than left implicit:
 *
 * - `cart_items` holds the **product identifiers** of the cart, as text. An
 *   identifier is stable and is what WooCommerce itself keys a product by, while a
 *   name changes and is translated. A rule that wants to name a product by
 *   something friendlier is a product decision, and it belongs with the picker that
 *   would have to offer it.
 * - `cart_categories` holds the **slugs** of the categories the cart's products
 *   belong to, because that is the stable form the taxonomy itself uses.
 *
 * Both are text lists, which is what the `contains` operator searches and what the
 * published source type says.
 *
 * It was born in the classic adapter and moved here when the Blocks checkout needed
 * the same entries: what the customer's country is, what is in their cart and
 * whether they are logged in are the same questions in both checkouts, and two
 * implementations of them would disagree the first time one was edited.
 *
 * @see \ROADMAP.md section 11
 */
final class CheckoutConditionContext {

	/**
	 * Every entry this builder can supply, in one place.
	 *
	 * Declared rather than implied so that a source added to the vocabulary without
	 * an answer here is visible as a gap: {@see self::missing()} reports exactly
	 * that, and the proof asserts it is empty.
	 *
	 * @return array<int, string>
	 */
	public static function supplied(): array {
		return array(
			'country',
			'state',
			'shipping_method',
			'payment_method',
			'customer_logged_in',
			'cart_items',
			'cart_categories',
			'cart_tags',
			'cart_total',
			'cart_virtual',
			'cart_downloadable',
			'cart_quantity',
			'cart_subtotal',
			'user_role',
		);
	}

	/**
	 * Sources the vocabulary has that this builder does not answer for.
	 *
	 * `field` is the one source a builder cannot answer: it is not context, it is
	 * whatever the submission carried for another field, and only the caller that
	 * holds the submission can supply it.
	 *
	 * @return array<int, string>
	 */
	public static function missing(): array {
		$missing = array();

		foreach ( Sources::all() as $key => $source ) {
			if ( $source->is_reference() ) {
				continue;
			}

			if ( ! in_array( $key, self::supplied(), true ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Builds the context.
	 *
	 * @param array<string, mixed> $fields  Canonical values of the fields already
	 *                                      processed in this submission.
	 * @param string               $adapter Checkout the rule is being asked for. The entries are the
	 *                                      same in both — a cart total is a cart total — and the
	 *                                      adapter travels so that a rule written for one checkout
	 *                                      can be told which one it is answering.
	 * @return FieldContext
	 */
	public function context( array $fields = array(), string $adapter = 'classic' ): FieldContext {
		return new FieldContext(
			array(
				'country'            => $this->country(),
				'state'              => $this->state(),
				'shipping_method'    => $this->chosen( 'chosen_shipping_methods' ),
				'payment_method'     => $this->chosen( 'chosen_payment_method' ),
				'customer_logged_in' => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'cart_items'         => $this->product_ids(),
				'cart_categories'    => $this->taxonomy_slugs( 'product_cat' ),
				'cart_tags'          => $this->taxonomy_slugs( 'product_tag' ),
				'cart_total'         => $this->cart_total(),
				'cart_virtual'       => $this->every_product( 'is_virtual' ),
				'cart_downloadable'  => $this->every_product( 'is_downloadable' ),
				'cart_quantity'      => $this->cart_quantity(),
				'cart_subtotal'      => $this->cart_subtotal(),
				'user_role'          => $this->user_roles(),
				'fields'             => $fields,
			),
			$adapter
		);
	}

	/**
	 * The billing country, falling back to the shipping one.
	 *
	 * Billing first because that is the address the customer is filling in, and an
	 * empty string when neither has been chosen: "no country yet" is a real state,
	 * and answering with the store's default would make a rule about the customer's
	 * country match before the customer had one.
	 *
	 * @return string
	 */
	private function country(): string {
		$customer = $this->customer();

		if ( null === $customer ) {
			return '';
		}

		$billing = (string) $customer->get_billing_country();

		return '' !== $billing ? $billing : (string) $customer->get_shipping_country();
	}

	/**
	 * The billing state, falling back to the shipping one.
	 *
	 * @return string
	 */
	private function state(): string {
		$customer = $this->customer();

		if ( null === $customer ) {
			return '';
		}

		$billing = (string) $customer->get_billing_state();

		return '' !== $billing ? $billing : (string) $customer->get_shipping_state();
	}

	/**
	 * One of the choices WooCommerce keeps in the session.
	 *
	 * The first shipping method is the one the customer chose: WooCommerce stores
	 * the package selections, and a single-package checkout has exactly one.
	 *
	 * @param string $key Session key.
	 * @return string
	 */
	private function chosen( string $key ): string {
		$session = $this->session();

		if ( null === $session ) {
			return '';
		}

		$value = $this->session_value( $session, $key );

		if ( is_array( $value ) ) {
			$first = reset( $value );

			return is_string( $first ) ? $first : '';
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Reads one entry from the session store.
	 *
	 * The store holds whatever was put in it, and what was put in it can come from
	 * an extension, so this returns mixed on purpose: the type the session declares
	 * is not a promise about a session another plugin wrote, and the caller has to
	 * look at what it got.
	 *
	 * @param \WC_Session $session Session.
	 * @param string      $key     Session key.
	 * @return mixed
	 */
	private function session_value( \WC_Session $session, string $key ): mixed {
		return $session->get( $key, '' );
	}

	/**
	 * Product identifiers in the cart, as text.
	 *
	 * @return array<int, string>
	 */
	private function product_ids(): array {
		$cart = $this->cart();

		if ( null === $cart ) {
			return array();
		}

		$ids = array();

		foreach ( $cart->get_cart() as $item ) {
			$id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

			if ( $id > 0 ) {
				$ids[] = (string) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The cart total as a number.
	 *
	 * The number rather than the formatted price, because a rule compares it with
	 * a number: the formatted string carries the currency and the store's decimal
	 * separators, and comparing those as text is how "greater than 100" starts
	 * being wrong in a store that writes 1.000,00.
	 *
	 * @return float
	 */
	private function cart_total(): float {
		$cart = $this->cart();

		return null === $cart ? 0.0 : (float) $cart->get_total( 'edit' );
	}

	/**
	 * Slugs of one taxonomy, across the products in the cart.
	 *
	 * Categories and tags are the same question asked of two taxonomies, so they are the same
	 * walk: the slug is the stable form the taxonomy itself uses, and a name would be translated
	 * and edited. An unknown taxonomy answers with nothing rather than with an error — a store
	 * whose product tags were removed still has a checkout.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<int, string>
	 */
	private function taxonomy_slugs( string $taxonomy ): array {
		$cart = $this->cart();

		if ( null === $cart || ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}

		$slugs = array();

		foreach ( $cart->get_cart() as $item ) {
			$id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$terms = wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Whether every product in the cart has one property.
	 *
	 * **Every**, and the label published with the source says so. The archetypal rule reading
	 * this is "a checkout that asks no address, because nothing here has to be shipped", which is
	 * a statement about all the items; "some item is virtual" is a different question, and an
	 * empty cart answers `false` for both readings rather than claiming a property nothing has.
	 *
	 * @param string $method Product method: `is_virtual` or `is_downloadable`.
	 * @return bool
	 */
	private function every_product( string $method ): bool {
		$cart = $this->cart();

		if ( null === $cart || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$seen = false;

		foreach ( $cart->get_cart() as $item ) {
			$id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product ) {
				// A product that cannot be read cannot be claimed to be virtual: the answer that
				// hides an address the customer needs is the dangerous one.
				return false;
			}

			if ( ! $product->{$method}() ) {
				return false;
			}

			$seen = true;
		}

		return $seen;
	}

	/**
	 * How many items the cart holds, counting quantities.
	 *
	 * @return int
	 */
	private function cart_quantity(): int {
		$cart = $this->cart();

		return null === $cart ? 0 : (int) $cart->get_cart_contents_count();
	}

	/**
	 * The cart subtotal, before shipping and fees.
	 *
	 * A number for the same reason the total is one, and the *subtotal* rather than the total
	 * because a rule about "how much the customer is buying" must not change its answer when the
	 * shipping method does.
	 *
	 * @return float
	 */
	private function cart_subtotal(): float {
		$cart = $this->cart();

		return null === $cart ? 0.0 : (float) $cart->get_subtotal();
	}

	/**
	 * The roles of the signed-in user.
	 *
	 * A list, because a user can hold more than one role and a rule about staff is a rule about
	 * one entry of the list. A guest has none, which is what "is empty" answers.
	 *
	 * @return array<int, string>
	 */
	private function user_roles(): array {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}

		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User || 0 === (int) $user->ID ) {
			return array();
		}

		return array_values( array_map( 'strval', (array) $user->roles ) );
	}

	/**
	 * Whether WooCommerce is present and has finished starting.
	 *
	 * `function_exists( 'WC' )` alone is not enough, and the project has already
	 * paid for learning that: the function is defined from the moment the plugin
	 * file is loaded, well before WooCommerce builds its customer, session and cart
	 * objects, and reading one of them then was a fatal error on the storefront.
	 * Both checks are here, and the second is the one that means anything.
	 *
	 * @return bool
	 */
	private function ready(): bool {
		return function_exists( 'WC' ) && did_action( 'woocommerce_init' ) > 0;
	}

	/**
	 * WooCommerce's customer object, when there is one.
	 *
	 * @return \WC_Customer|null
	 */
	private function customer(): ?\WC_Customer {
		return $this->ready() ? WC()->customer : null;
	}

	/**
	 * WooCommerce's session, when there is one.
	 *
	 * @return \WC_Session|null
	 */
	private function session(): ?\WC_Session {
		return $this->ready() ? WC()->session : null;
	}

	/**
	 * WooCommerce's cart, when there is one.
	 *
	 * @return \WC_Cart|null
	 */
	private function cart(): ?\WC_Cart {
		return $this->ready() ? WC()->cart : null;
	}
}
