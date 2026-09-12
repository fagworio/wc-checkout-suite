<?php
/**
 * The personal data export for the checkout fields.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Privacy;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\OrderFieldEntry;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WC_Order;

/**
 * Answers "what do you hold about me" for this plugin.
 *
 * WordPress's own privacy tools ask every plugin the same two questions, and this is the
 * first of them. What it returns is decided by the vocabulary the merchant configured and
 * not by a list written here:
 *
 * - **Personal and sensitive values are the person's data** and are exported: the storage
 *   vocabulary says so in its own words — `personal` "identifies a person", `sensitive` is
 *   a document that needs extra care.
 * - **A value marked `public` is not** — the vocabulary defines it as identifying nobody on
 *   its own — so it is left out, and the export does not become a copy of the order.
 * - **A document travels as the fact of it.** The stored value is a token, which is an
 *   address inside the store rather than the document; exporting the address would put an
 *   internal handle into a file the person keeps forever. What the person is told is that a
 *   document was provided with that order, and the store's own retention decides how long
 *   it is kept.
 *
 * Labels travel as the order recorded them, so an export of an old order says what that
 * order said.
 */
final class OrderFieldsExporter {

	/**
	 * Identifier of the exporter, and the group in the export file.
	 */
	public const ID = 'wc-checkoutsuite';

	/**
	 * Registers the exporter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add' ) );
	}

	/**
	 * Adds this plugin's exporter to the list the tools read.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Checkout fields', 'wc-checkoutsuite' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * The data this plugin holds about one address.
	 *
	 * @param string $email Email the request came from.
	 * @param int    $page  Page, unused: a person's orders fit in one answer, and a
	 *                      paginated privacy export is a person who never sees their data.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		unset( $page );

		$definitions = PublishedDocument::read()->fields();
		$personal    = self::personal_fields( $definitions );
		$service     = new OrderFieldsService();
		$data        = array();

		foreach ( DataSubjectOrders::for_email( $email ) as $order ) {
			$items = array();

			foreach ( $service->history( $order, $definitions ) as $entry ) {
				if ( ! isset( $personal[ $entry->id() ] ) ) {
					continue;
				}

				$items[] = array(
					'name'  => $entry->label(),
					'value' => self::value( $entry, $personal[ $entry->id() ] ),
				);
			}

			if ( array() === $items ) {
				// An order with none of this plugin's personal data is not this plugin's
				// business, and an empty group per order would make the export file read
				// as though the store held something under every order number.
				continue;
			}

			$data[] = array(
				'group_id'    => self::ID . '-order-' . $order->get_id(),
				/* translators: %s: order number. */
				'group_label' => sprintf( __( 'Checkout fields (order %s)', 'wc-checkoutsuite' ), $order->get_order_number() ),
				'item_id'     => self::ID . '-' . $order->get_id(),
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * The fields whose values are the person's data.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, array<string, mixed>> Definitions, keyed by identifier.
	 */
	public static function personal_fields( array $definitions ): array {
		$personal = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id ) {
				continue;
			}

			$stored      = $definition->to_array();
			$storage     = isset( $stored['storage'] ) && is_array( $stored['storage'] ) ? $stored['storage'] : array();
			$sensitivity = isset( $storage['sensitivity'] ) ? (string) $storage['sensitivity'] : '';

			if ( ! in_array( $sensitivity, array( 'personal', 'sensitive' ), true ) ) {
				continue;
			}

			$personal[ $id ] = $stored;
		}

		return $personal;
	}

	/**
	 * What the export says for one value.
	 *
	 * @param OrderFieldEntry      $entry      Entry.
	 * @param array<string, mixed> $definition Definition.
	 * @return string
	 */
	private static function value( OrderFieldEntry $entry, array $definition ): string {
		if ( 'file' === (string) ( $definition['type'] ?? '' ) ) {
			return __( 'A document was provided with this order. Ask the store for a copy.', 'wc-checkoutsuite' );
		}

		$value = $entry->value();

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wc-checkoutsuite' ) : __( 'No', 'wc-checkoutsuite' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
