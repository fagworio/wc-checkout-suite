<?php
/**
 * The opt-in that decides whether the custom checkout is presented.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Settings;

use WCCheckoutSuite\Domain\Payments\PaymentMatrix;
use WCCheckoutSuite\Domain\Payments\PaymentMode;

/**
 * Whether this store asks for the presentation, and when it must step aside.
 *
 * ROADMAP.md section 15 opens with the rule this class implements:
 *
 * > `Enable Custom Checkout` é opt-in e vem desligado. A desativação restaura a
 * > apresentação original preservando o editor de campos.
 *
 * Two sentences and both decide the design. **Opt-in and off by default**: a plugin
 * that changes a store's checkout the moment it is activated has taken a decision that
 * belongs to the merchant, and the merchant cannot see what they are choosing until
 * they have seen the editor. **The editor is preserved**: the switch governs the
 * *presentation* and nothing else — not the fields, not the schema, not the validation.
 * Turning it off is turning off a stylesheet and two components, never a feature of the
 * product, and the proof asserts exactly that by comparing the stored document before
 * and after.
 *
 * And the second sentence of the section, which is the other half:
 *
 * > Uma incompatibilidade não autoriza trocar silenciosamente a engine da página.
 *
 * So an incompatibility does not change engines either — it makes this plugin step
 * aside. A gateway the record marks `unavailable` is a gateway this presentation must
 * not offer, and the safe return is the store's own checkout as WooCommerce renders it,
 * with the reason recorded rather than swallowed.
 *
 * The decision is a value object's business and not a template's: it is asked by the
 * classic checkout, by the Blocks checkout and by the diagnostics screen, and three
 * callers deciding for themselves is three answers.
 */
final class CheckoutSettings {

	/**
	 * The option holding the merchant's choice.
	 */
	public const OPTION = 'wccs_custom_checkout';

	/**
	 * This plugin presents the checkout.
	 */
	public const MODE_CUSTOM = 'custom';

	/**
	 * WooCommerce presents the checkout; this plugin contributes fields and nothing else.
	 */
	public const MODE_STORE = 'store';

	/**
	 * Whether the merchant has opted in.
	 *
	 * Read as a strict `yes`: anything else — an absent option, a stray value, a
	 * half-written one — is off. The default of a switch that changes a store's
	 * checkout has to be the one that changes nothing.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Records the merchant's choice.
	 *
	 * Nothing else is written. In particular the schema is not touched: the document,
	 * its revision and its history are the merchant's work, and a switch that rewrote
	 * any of it would be a switch that can lose it.
	 *
	 * @param bool $enabled Whether the custom presentation is wanted.
	 * @return void
	 */
	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? 'yes' : 'no', false );
	}

	/**
	 * Whether this plugin may present the checkout on this request.
	 *
	 * @param array<int, object> $gateways The gateways the page will offer.
	 * @return array{mode: string, reason: string, blocked_by: array<int, string>}
	 */
	public static function decision( array $gateways = array() ): array {
		if ( ! self::enabled() ) {
			return array(
				'mode'       => self::MODE_STORE,
				'reason'     => __( 'The custom checkout is not enabled on this store.', 'wc-checkoutsuite' ),
				'blocked_by' => array(),
			);
		}

		$blocked = array();

		foreach ( $gateways as $gateway ) {
			if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
				continue;
			}

			$decision = PaymentMatrix::decide_for( $gateway );

			if ( PaymentMode::UNAVAILABLE === $decision['mode'] ) {
				$blocked[] = (string) $gateway->id;
			}
		}

		if ( array() !== $blocked ) {
			// The safe return: the store's own checkout, whole, with the reason naming
			// what caused it. Stepping aside is not a failure the customer sees — it is
			// the presentation deciding it has no business being there.
			return array(
				'mode'       => self::MODE_STORE,
				'reason'     => sprintf(
					/* translators: %s: comma separated list of payment gateway identifiers. */
					__( 'The custom checkout steps aside because the gateway homologation record marks these gateways unavailable: %s.', 'wc-checkoutsuite' ),
					implode( ', ', $blocked )
				),
				'blocked_by' => $blocked,
			);
		}

		return array(
			'mode'       => self::MODE_CUSTOM,
			'reason'     => __( 'The custom checkout is enabled and no offered gateway blocks it.', 'wc-checkoutsuite' ),
			'blocked_by' => array(),
		);
	}

	/**
	 * The short answer, for the gates that only need it.
	 *
	 * @param array<int, object> $gateways The gateways the page will offer.
	 * @return bool
	 */
	public static function presents( array $gateways = array() ): bool {
		return self::MODE_CUSTOM === self::decision( $gateways )['mode'];
	}

	/**
	 * The gateways the checkout will offer on this request.
	 *
	 * Read here rather than at each call site so the classic checkout, the Blocks
	 * checkout and the diagnostics screen all decide about the same list — and so a
	 * request without WooCommerce loaded answers about an empty list instead of
	 * failing.
	 *
	 * @return array<int, object>
	 */
	public static function offered_gateways(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		// WooCommerce casts the filter's answer to an array itself, so this is the list
		// the checkout would render and not a second opinion about it.
		return array_values( WC()->payment_gateways()->get_available_payment_gateways() );
	}
}
