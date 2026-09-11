<?php
/**
 * Blocks checkout: validation and persistence of the controlled fields.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Blocks;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WCCheckoutSuite\Domain\Registries;

/**
 * Validates what the Blocks checkout submitted, and stores what passed.
 *
 * One hook does both, and that is the point: `woocommerce_store_api_checkout_update_order_from_request`
 * fires with the order and the request together, at exactly the moment WooCommerce
 * is building the order it is about to charge for. Throwing from it makes the
 * Checkout Block refuse to continue, which is what "an error blocks payment" means
 * in this checkout — so validation and persistence cannot drift apart, because the
 * same call decides both.
 *
 * Three decisions:
 *
 * 1. **A value that is refused blocks the order, and says which field.** The first
 *    error becomes a `RouteException` carrying the field identifier, the rule's own
 *    message and a 400. The alternative — reporting the error and storing the order
 *    anyway — is an order the store accepted and cannot honour.
 *
 * 2. **The pipeline is the same one the classic checkout runs.** Normalization,
 *    visibility, the hidden-value policy, the type's own rules and the named
 *    validators all come from `ValueProcessor`, with the trusted context read from
 *    WooCommerce's objects. A rule cannot mean one thing in one checkout and
 *    another in the other.
 *
 * 3. **A retry stores the same thing, not the same thing twice.** The write is a
 *    meta update of one payload keyed by the published revision, so running it
 *    again with the same values leaves one row and one payload — which is what a
 *    customer pressing "place order" twice, or a client retrying a timed-out
 *    request, actually does. Nothing here appends.
 *
 * @see \ROADMAP.md sections 10 and 11
 * @see docs/api/checkout-extension-points.md
 */
final class BlocksValidation {

	/**
	 * Hook the checkout route fires with the order and the request.
	 */
	public const HOOK = 'woocommerce_store_api_checkout_update_order_from_request';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'apply' ), 10, 2 );
	}

	/**
	 * Validates the submitted values and stores them.
	 *
	 * @param mixed $order   Order WooCommerce is building.
	 * @param mixed $request Store API request.
	 * @return void
	 *
	 * @throws RouteException When a value is refused, so the order is not placed.
	 */
	public static function apply( mixed $order, mixed $request ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$definitions = StoreApiExtension::definitions();

		if ( array() === $definitions ) {
			return;
		}

		$submitted = StoreApiExtension::submitted( $request );

		if ( array() === $submitted ) {
			// Nothing was sent for this namespace. That is not a failure: a checkout
			// with no controlled field on the page, or one where every such field is
			// hidden, submits nothing.
			return;
		}

		$processor = Registries::instance()->value_processor();
		$context   = ( new CheckoutConditionContext() )->context();
		$values    = array();
		$errors    = array();

		foreach ( $definitions as $id => $raw ) {
			$definition = FieldDefinition::from_array( $raw );

			if ( ! array_key_exists( $id, $submitted ) ) {
				continue;
			}

			$processed = $processor->process( $definition, $submitted[ $id ], $context );

			foreach ( $processed->result()->errors() as $error ) {
				$code = (string) $error['code'];

				// Requiredness is the layer's own question in the classic checkout,
				// because WooCommerce decides which fieldsets a request covers. Here
				// the request is the whole checkout, so a missing required value is
				// refused like any other.
				$errors[] = array(
					'field'   => $id,
					'code'    => $code,
					'message' => (string) $error['message'],
				);
			}

			if ( $processed->is_storable() ) {
				$values[ $id ] = $processed->value();
			}
		}

		if ( array() !== $errors ) {
			$first = $errors[0];

			// The message is escaped the way WooCommerce escapes its own route
			// errors: it is rendered into the checkout page, it comes from a
			// translated string with a merchant-written field label substituted into
			// it, and a label is not a place to find out that escaping was somebody
			// else's job. The code is prefixed so a caller can tell this plugin's
			// refusal from WooCommerce's, and the field identifier travels as data
			// because the Checkout Block renders the message against that field.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the code and the field identifier are machine-readable and escaping them would change the contract the Checkout Block matches on.
			throw new RouteException(
				'wc-checkoutsuite_' . $first['code'],
				esc_html( $first['message'] ),
				400,
				array( 'field' => $first['field'] )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		( new OrderFieldsService() )->write(
			$order,
			$values,
			array_values( $definitions ),
			PublishedDocument::read()->revision()
		);
	}
}
