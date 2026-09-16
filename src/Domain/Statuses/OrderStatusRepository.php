<?php
/**
 * Where the store's own order statuses live.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Statuses;

use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * The stored list of custom order statuses.
 *
 * An option and not the schema document, and the reason is what the two things are. The document is
 * the **checkout composition**: what the customer is asked, in which container, under which rule.
 * An order status is not part of that composition — it is a state an order enters after the
 * checkout, it belongs to the workflow vocabulary (§3.8 uses it as `initial_status` and in
 * transitions), and §12 gives it a screen of its own under "Status e automações" (§4). Storing it
 * in the document would also mean a restore of an earlier schema revision silently deleted the
 * states orders are recorded in, which is the one thing §12.6 asks never to happen.
 *
 * What it does share with the document is the shape of the answer: a write says whether it was
 * accepted and why not, and the caller decides what to do about it.
 *
 * @see \ROADMAP.md sections 12, 12.6
 */
final class OrderStatusRepository {

	/**
	 * Option holding the list.
	 */
	public const OPTION = 'wccs_order_statuses';

	/** Option holding the compare-and-swap revision. */
	public const REVISION_OPTION = 'wccs_order_statuses_revision';

	/**
	 * Current revision of the stored list.
	 *
	 * @return int Revision.
	 */
	public function revision(): int {
		return max( 0, (int) get_option( self::REVISION_OPTION, 0 ) );
	}

	/**
	 * Every stored status, in the order the merchant arranged them.
	 *
	 * @return array<int, OrderStatus>
	 */
	public function all(): array {
		return array_values(
			array_map(
				static fn( array $raw ): OrderStatus => OrderStatus::from_array( $raw ),
				$this->raw()
			)
		);
	}

	/**
	 * The stored list, as arrays, exactly as it was written.
	 *
	 * Read raw on purpose: a status is written back with the keys this build does not know, so the
	 * reader has to start from the stored shape rather than from the shape this build exports.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function raw(): array {
		$stored = get_option( self::OPTION );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( $decoded, 'is_array' ) );
	}

	/**
	 * One stored status, or null.
	 *
	 * @param string $id Identifier.
	 * @return OrderStatus|null
	 */
	public function find( string $id ): ?OrderStatus {
		foreach ( $this->all() as $status ) {
			if ( $status->id() === $id ) {
				return $status;
			}
		}

		return null;
	}

	/**
	 * Replaces the list.
	 *
	 * @param array<int, mixed> $statuses Raw statuses.
	 * @return ValidationResult
	 */
	public function save( array $statuses ): ValidationResult {
		$statuses = $this->identify( array_values( array_filter( $statuses, 'is_array' ) ) );
		$result   = StatusValidator::validate_all( $statuses );

		if ( ! $result->is_valid() ) {
			return $result;
		}

		update_option( self::OPTION, (string) wp_json_encode( $statuses ), false );
		update_option( self::REVISION_OPTION, $this->revision() + 1, false );

		return $result;
	}

	/**
	 * Gives an identifier to every entry that does not have one yet.
	 *
	 * The screen sends the name and leaves the identifier empty, and the store assigns it — here,
	 * once. That is deliberate: the identifier becomes a post status key that orders are recorded
	 * in, and an identity rule implemented twice (once in the browser that proposes it, once in the
	 * server that stores it) is a rule that would eventually propose one key and store another. The
	 * table of accents, the length that fits beside `wc-`, and the numbering of a collision all
	 * live in {@see OrderStatus::unique_id()} and nowhere else.
	 *
	 * An entry that already has an identifier keeps it, however much its label has changed: that is
	 * §12.6, and it is what keeps a renamed state the same state.
	 *
	 * @param array<int, array<string, mixed>> $statuses Raw statuses.
	 * @return array<int, array<string, mixed>>
	 */
	private function identify( array $statuses ): array {
		$taken = array();

		foreach ( $statuses as $raw ) {
			$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

			if ( '' !== $id ) {
				$taken[] = $id;
			}
		}

		foreach ( $statuses as $index => $raw ) {
			$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

			if ( '' !== $id ) {
				continue;
			}

			$label = isset( $raw['label'] ) ? trim( (string) $raw['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$assigned = OrderStatus::unique_id( $label, $taken );

			$statuses[ $index ]['id'] = $assigned;
			$taken[]                  = $assigned;
		}

		return $statuses;
	}

	/**
	 * Makes sure every named status exists, without touching the ones that do.
	 *
	 * This is the migration of §12's "review status legado": the approval flows configured before
	 * this screen existed derived their state from the field the document names, and orders are
	 * already recorded in it. Those states are added to the list with **the identifier they already
	 * have**, so a store that upgrades finds its state on the new screen, can rename it, colour it
	 * and decide whether the customer sees it — and every order stays in the same internal status
	 * (§12.6, §12.7 step 8).
	 *
	 * It only adds. A status the merchant has since renamed or coloured is left exactly as it is,
	 * and a status the merchant deleted stays deleted: an upgrade is not a reason to undo a
	 * decision.
	 *
	 * @param array<string, string> $statuses Identifier to label.
	 * @return array<int, string> Identifiers that were added.
	 */
	public function ensure( array $statuses ): array {
		$stored = $this->raw();
		$known  = array();

		foreach ( $stored as $raw ) {
			$known[ (string) ( $raw['id'] ?? '' ) ] = true;
		}

		$added = array();

		foreach ( $statuses as $id => $label ) {
			$id = (string) $id;

			if ( '' === $id || isset( $known[ $id ] ) ) {
				continue;
			}

			$stored[] = array(
				'id'             => $id,
				'label'          => (string) $label,
				'customer_label' => '',
				'colour'         => '',
				'active'         => true,
				'show_customer'  => true,
				'show_emails'    => true,
				'manual'         => true,
				// A state an order waits in while its documents are reviewed is a state before
				// payment, and §12.5 says it stays that way.
				'prepayment'     => true,
				'description'    => '',
			);

			$known[ $id ] = true;
			$added[]      = $id;
		}

		if ( array() !== $added ) {
			$this->save( $stored );
		}

		return $added;
	}
}
