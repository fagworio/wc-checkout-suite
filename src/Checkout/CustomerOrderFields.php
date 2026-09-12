<?php
/**
 * The checkout fields on the customer's own order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\OrderFieldEntry;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WC_Order;

/**
 * Shows the customer the values their own order carries, and only those.
 *
 * ROADMAP.md section 14 puts the whole rule in one line:
 *
 * > Cliente: detalhe do pedido, thank-you e Minha Conta somente conforme política do
 * > campo. Separar valor do pedido de preferência atual do perfil; editar perfil não
 * > reescreve pedido passado.
 *
 * **One hook, and it is WooCommerce's.** `woocommerce_order_details_after_order_table`
 * is fired from `templates/order/order-details.php`, the template the thank-you page and
 * the My Account order view both render. The panel does not register a route, a shortcode
 * or a page: it draws where the store has already decided this order may be seen by this
 * person, and its job is the field policy on top of that — not a second access check that
 * would be either redundant or, worse, the only one.
 *
 * **The policy is the field's, and its absence is a refusal.** A value is shown when the
 * field declares `customer_order`. A field that was renamed, archived or deleted since
 * the order was placed has no policy left to read, and a policy that cannot be read is
 * not an approval: it is left out. That is the direction the failure has to fall, because
 * the other direction shows a customer a document the merchant stopped showing.
 *
 * **The order is a snapshot and the profile is current.** Every value here comes from the
 * order — `OrderFieldsService::history()` reads the payload the order carries, and the
 * label comes from what was recorded with it. Nothing in this class reads the customer's
 * meta, the session or the published document for a *value*: if the order captured
 * nothing, the customer sees nothing, even when their account holds something today.
 * Section 14's sentence is about editing in one direction and about showing in the other,
 * and this is the other: a past order is not a view of the present account.
 */
final class CustomerOrderFields {

	/**
	 * The template hook the thank-you page and My Account both fire.
	 */
	public const HOOK = 'woocommerce_order_details_after_order_table';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'render' ), 10, 1 );
	}

	/**
	 * The fields the customer may be shown, keyed by identifier.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, array<string, mixed>> Allowed definitions.
	 */
	public static function visible( array $definitions ): array {
		$visible = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || ! $definition->is_enabled() ) {
				continue;
			}

			$stored = $definition->to_array();

			// The link, not a copy of it: a destination is enabled per field, and the
			// model is the only place that knows.
			if ( ! $definition->shows_in( 'customer_order' ) ) {
				continue;
			}

			$visible[ $id ] = $stored;
		}

		return $visible;
	}

	/**
	 * The entries this order may show this customer.
	 *
	 * @param WC_Order                         $order       Order.
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<int, OrderFieldEntry> Entries, in the order the values were stored.
	 */
	public static function entries( WC_Order $order, array $definitions ): array {
		$visible = self::visible( $definitions );
		$shown   = array();

		foreach ( ( new OrderFieldsService() )->history( $order, $definitions ) as $entry ) {
			// The entry's own identifier decides, not its label: a field renamed since
			// the order was placed is the same field, and one that no longer exists has
			// no policy left to allow it.
			if ( ! isset( $visible[ $entry->id() ] ) ) {
				continue;
			}

			$shown[] = $entry;
		}

		return $shown;
	}

	/**
	 * Draws the panel.
	 *
	 * @param mixed $order Order the template is rendering.
	 * @return void
	 */
	public static function render( $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$entries = self::entries( $order, PublishedDocument::read()->fields() );

		if ( array() === $entries ) {
			// Nothing to show is shown as nothing: a heading with no rows tells the
			// customer that something was withheld, which is information they were not
			// given and the store did not choose to give.
			return;
		}

		echo '<section class="wccs-customer-fields">';
		printf(
			'<h2 class="wccs-customer-fields__title">%s</h2>',
			esc_html__( 'Checkout information', 'wc-checkoutsuite' )
		);
		echo '<dl class="wccs-customer-fields__list">';

		foreach ( $entries as $entry ) {
			printf(
				'<dt class="wccs-customer-fields__label">%s</dt><dd class="wccs-customer-fields__value">%s</dd>',
				esc_html( $entry->label() ),
				esc_html( self::display( $entry->value() ) )
			);
		}

		echo '</dl></section>';
	}

	/**
	 * A value as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display( $value ): string {
		if ( is_array( $value ) ) {
			$labels = array();

			foreach ( $value as $one ) {
				if ( is_scalar( $one ) ) {
					$labels[] = (string) $one;
				}
			}

			return implode( ', ', $labels );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wc-checkoutsuite' ) : __( 'No', 'wc-checkoutsuite' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
