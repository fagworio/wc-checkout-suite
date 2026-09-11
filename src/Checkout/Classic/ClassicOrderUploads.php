<?php
/**
 * Binding the uploads a checkout submitted to the order it created.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Uploads\UploadRepository;
use WCCheckoutSuite\Domain\Uploads\UploadService;

/**
 * Ties submitted uploads to the order, once.
 *
 * The tokens reach the server the same way any other field does: the component keeps
 * them in a hidden input beside the file control, and the form submits them. That is
 * deliberate — a token is a handle, and a handle a browser can name is a handle a
 * browser can forge, which is exactly why the binding query also demands the owner.
 * A forged token belongs to nobody, so it binds nothing and the order is placed
 * without it.
 *
 * The binding runs on `woocommerce_checkout_create_order`, where the order exists and
 * the request is still in hand. Two submissions of the same form — a retry, a double
 * click, a restored tab — reach it twice, and the second call changes nothing,
 * because the query only matches rows still unbound. That is the whole point of
 * putting the guarantee in the statement instead of here.
 *
 * @see ROADMAP.md section 12
 */
final class ClassicOrderUploads {

	/**
	 * The suffix the hidden field's name carries.
	 *
	 * A field identifier and a suffix rather than the field's own name: the field
	 * carries no value of its own — the file is not in the form — so its token list
	 * gets a name that cannot collide with it.
	 */
	public const TOKENS_SUFFIX = '_wccs_tokens';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'bind' ), 20 );
	}

	/**
	 * Binds the submitted tokens to the order being created.
	 *
	 * @param mixed $order Order WooCommerce built from the checkout.
	 * @return void
	 */
	public static function bind( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tokens = self::submitted();

		if ( array() === $tokens ) {
			return;
		}

		$owner = UploadService::owner();

		if ( '' === $owner ) {
			return;
		}

		( new UploadRepository() )->bind( $tokens, $owner, (int) $order->get_id() );
	}

	/**
	 * The tokens the request carried.
	 *
	 * Read from `$_POST` because that is where a submitted form puts them, and
	 * filtered to the shape a token has: anything else is a value somebody typed,
	 * and passing it to the query would only widen what the query has to refuse.
	 *
	 * @return array<int, string>
	 */
	public static function submitted(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The checkout's own nonce was verified by WooCommerce before this hook; this reads a field of a request that has already been accepted.
		$posted = $_POST;

		if ( ! is_array( $posted ) ) {
			return array();
		}

		$tokens = array();

		foreach ( $posted as $key => $value ) {
			if ( ! is_string( $key ) || ! str_ends_with( $key, self::TOKENS_SUFFIX ) ) {
				continue;
			}

			foreach ( is_array( $value ) ? $value : array( $value ) as $token ) {
				if ( is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
					$tokens[] = $token;
				}
			}
		}

		return array_values( array_unique( $tokens ) );
	}
}
