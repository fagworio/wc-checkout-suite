<?php
/**
 * The personal data erasure for the checkout fields.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Privacy;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;

/**
 * Answers "forget me" for this plugin, and says what it kept and why.
 *
 * The second question WordPress's privacy tools ask, and the one where a plugin can be
 * both too eager and too quiet. Three decisions:
 *
 * 1. **It erases the values and not the order.** An order is the store's record of a sale
 *    — its totals, its tax, its gateway reference — and section 14 says as much about the
 *    neighbouring case: "reembolso/cancelamento não deve apagar automaticamente dados
 *    necessários ao histórico". The values this plugin added to that record are the
 *    person's data and go; the record that a purchase happened stays.
 * 2. **It says what it kept, and why.** `items_retained` is set with a message naming the
 *    reason, because a data-subject flow that silently keeps something is a flow nobody
 *    can explain to the person who asked — and "retenção explicável" is the acceptance.
 * 3. **A document is named, not deleted from here.** The file of an upload bound to an
 *    existing order is part of the order's record while the order exists, and the store's
 *    own retention removes it with the order (WCCS-045). The erasure reports that, names
 *    the order, and leaves the decision where it was made.
 *
 * What is erased is what the export would have given: the same definition of personal
 * data, from the same vocabulary, so a person cannot be told their data is one thing when
 * they ask for it and another when they ask for it to be gone.
 */
final class OrderFieldsEraser {

	/**
	 * Identifier of the eraser.
	 */
	public const ID = 'wc-checkoutsuite';

	/**
	 * Registers the eraser.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add' ) );
	}

	/**
	 * Adds this plugin's eraser to the list the tools read.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Checkout fields', 'wc-checkoutsuite' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Erases what this plugin holds about one address.
	 *
	 * @param string $email Email the request came from.
	 * @param int    $page  Page, unused: every order a person has is handled in one pass,
	 *                      because an erasure that stopped half way would leave the person
	 *                      believing the other half was done.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$definitions = PublishedDocument::read()->fields();
		$personal    = OrderFieldsExporter::personal_fields( $definitions );
		$service     = new OrderFieldsService();
		$orders      = DataSubjectOrders::for_email( $email );

		$removed   = 0;
		$documents = array();
		$touched   = array();

		foreach ( $orders as $order ) {
			$values = $service->read( $order );
			$kept   = array();

			foreach ( $values->ids() as $id ) {
				if ( ! isset( $personal[ $id ] ) ) {
					// A value that is not personal data is not this request's business.
					$kept[ $id ] = $values->get( $id );

					continue;
				}

				if ( 'file' === (string) ( $personal[ $id ]['type'] ?? '' ) ) {
					$documents[] = $order->get_order_number();
				}

				++$removed;
			}

			if ( $removed > 0 || array() !== $kept ) {
				$service->write( $order, $kept, $definitions, PublishedDocument::read()->revision() );
				$order->save();
				$touched[] = $order->get_order_number();
			}
		}

		$messages = array();

		if ( $removed > 0 ) {
			$messages[] = sprintf(
				/* translators: 1: number of values, 2: number of orders. */
				_n(
					'%1$d checkout value was erased from %2$d order.',
					'%1$d checkout values were erased from %2$d orders.',
					$removed,
					'wc-checkoutsuite'
				),
				$removed,
				count( $touched )
			);
		}

		$retained = array() !== $orders;

		if ( $retained ) {
			$messages[] = __( 'The orders themselves are kept: they are the store\'s record of the purchase, and section 14 of the planning states that a refund or a cancellation does not automatically delete what the history needs. The store decides when an order is deleted.', 'wc-checkoutsuite' );
		}

		if ( array() !== $documents ) {
			$messages[] = sprintf(
				/* translators: %s: comma separated order numbers. */
				__( 'A document attached to order %s is kept while that order exists and is removed with it by the store\'s own upload retention; ask the store to delete the order if the document must go now.', 'wc-checkoutsuite' ),
				implode( ', ', array_unique( $documents ) )
			);
		}

		if ( array() === $messages ) {
			$messages[] = __( 'This store holds no checkout value about that address.', 'wc-checkoutsuite' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained || array() !== $documents,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
