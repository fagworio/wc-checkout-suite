<?php
/**
 * The condition source vocabulary.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * Every source a rule may read, and nothing else.
 *
 * The list is section 11's: another field, country, state, whether the customer is
 * logged in, what is in the cart, its categories, what it costs, the chosen
 * shipping method and the chosen payment method. Nothing beyond it is invented
 * here, because a source that nothing can supply is a rule that can never be
 * answered — and section 11 says an unavailable source gets an explicit block
 * rather than a hopeful declaration.
 *
 * Where each one comes from is `scope`, and it is the answer to the block: a
 * server source cannot be evaluated live in the browser, and the server recomputes
 * it with trusted context.
 *
 * @see \ROADMAP.md section 11
 */
final class Sources {

	/**
	 * Every source, keyed by its stable key.
	 *
	 * @return array<string, Source>
	 */
	public static function all(): array {
		$sources = array(
			// The value of another field, which is the one source whose type is
			// decided by something else. The validator resolves it.
			new Source( 'field', __( 'Another field', 'wc-checkoutsuite' ), 'mixed', Source::SCOPE_CLIENT, true ),

			new Source( 'country', __( 'Country', 'wc-checkoutsuite' ), Operator::TYPE_STRING, Source::SCOPE_CLIENT ),
			new Source( 'state', __( 'State', 'wc-checkoutsuite' ), Operator::TYPE_STRING, Source::SCOPE_CLIENT ),
			new Source( 'shipping_method', __( 'Shipping method', 'wc-checkoutsuite' ), Operator::TYPE_STRING, Source::SCOPE_CLIENT ),
			new Source( 'payment_method', __( 'Payment method', 'wc-checkoutsuite' ), Operator::TYPE_STRING, Source::SCOPE_CLIENT ),

			// Only the server knows these, and it recomputes them rather than
			// trusting anything the browser sends.
			new Source( 'customer_logged_in', __( 'Customer is logged in', 'wc-checkoutsuite' ), Operator::TYPE_BOOLEAN, Source::SCOPE_SERVER ),
			new Source( 'cart_items', __( 'Products in the cart', 'wc-checkoutsuite' ), Operator::TYPE_LIST, Source::SCOPE_SERVER ),
			new Source( 'cart_categories', __( 'Product categories in the cart', 'wc-checkoutsuite' ), Operator::TYPE_LIST, Source::SCOPE_SERVER ),
			new Source( 'cart_tags', __( 'Product tags in the cart', 'wc-checkoutsuite' ), Operator::TYPE_LIST, Source::SCOPE_SERVER ),
			new Source( 'cart_total', __( 'Cart total', 'wc-checkoutsuite' ), Operator::TYPE_NUMBER, Source::SCOPE_SERVER ),

			// The rest of §6.8's list. The two product properties are read as a statement about
			// **every** item — "nothing in this cart has to be shipped" is what a checkout
			// without an address is for — and the label says so rather than leaving the merchant
			// to guess which of the two quantities a boolean meant. `user_role` lists the roles,
			// so a rule about staff is a rule about one entry of a list.
			new Source( 'cart_virtual', __( 'Every product in the cart is virtual', 'wc-checkoutsuite' ), Operator::TYPE_BOOLEAN, Source::SCOPE_SERVER ),
			new Source( 'cart_downloadable', __( 'Every product in the cart is downloadable', 'wc-checkoutsuite' ), Operator::TYPE_BOOLEAN, Source::SCOPE_SERVER ),
			new Source( 'cart_quantity', __( 'Items in the cart', 'wc-checkoutsuite' ), Operator::TYPE_NUMBER, Source::SCOPE_SERVER ),
			new Source( 'cart_subtotal', __( 'Cart subtotal, before shipping', 'wc-checkoutsuite' ), Operator::TYPE_NUMBER, Source::SCOPE_SERVER ),
			new Source( 'user_role', __( 'Role of the signed-in user', 'wc-checkoutsuite' ), Operator::TYPE_LIST, Source::SCOPE_SERVER ),
		);

		$catalogue = array();

		foreach ( $sources as $source ) {
			$catalogue[ $source->key() ] = $source;
		}

		return $catalogue;
	}

	/**
	 * One source, or null.
	 *
	 * @param string $key Source key.
	 * @return Source|null
	 */
	public static function get( string $key ): ?Source {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether a source exists.
	 *
	 * @param string $key Source key.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return null !== self::get( $key );
	}

	/**
	 * Sources the browser can answer for.
	 *
	 * @return array<int, string>
	 */
	public static function client_keys(): array {
		return array_keys(
			array_filter(
				self::all(),
				static function ( Source $source ): bool {
					return ! $source->is_server_only();
				}
			)
		);
	}

	/**
	 * Sources only the server can answer for.
	 *
	 * @return array<int, string>
	 */
	public static function server_keys(): array {
		return array_keys(
			array_filter(
				self::all(),
				static function ( Source $source ): bool {
					return $source->is_server_only();
				}
			)
		);
	}

	/**
	 * The vocabulary as the editor reads it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_array(): array {
		return array_values(
			array_map(
				static function ( Source $source ): array {
					return $source->to_array();
				},
				self::all()
			)
		);
	}
}
