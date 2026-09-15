<?php
/**
 * Whether a set of order statuses is one the store can keep.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Statuses;

use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * The rules a stored order status has to satisfy.
 *
 * Every rule here is about the store still being able to answer "which state is this order in?"
 * after a merchant has configured their own states. §12.6 says what that costs: register only
 * statuses that are complete, stable and enabled, and never let a mutable label become the
 * identity of a state, because renaming would then create a second one.
 *
 * What is deliberately **not** here is a payment rule. There is no field to refuse, because the
 * status model has none: §12.4 puts the payment decision in a workflow transition that an
 * authorised payment action performs, and a status that could carry a charge would be the
 * accidental-charge path the section exists to close.
 *
 * @see \ROADMAP.md sections 12.3, 12.4 and 12.6
 */
final class StatusValidator {

	/**
	 * Validates a whole list.
	 *
	 * @param array<int, mixed> $statuses Raw statuses.
	 * @return ValidationResult
	 */
	public static function validate_all( array $statuses ): ValidationResult {
		$result = ValidationResult::valid();
		$seen   = array();

		foreach ( $statuses as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_status_entry',
						sprintf(
							/* translators: %d: index of the entry */
							__( 'The status at index %d is not a status.', 'wc-checkoutsuite' ),
							(int) $index
						),
						array( 'index' => (int) $index )
					)
				);

				continue;
			}

			$status = OrderStatus::from_array( $raw );
			$id     = $status->id();

			if ( '' === $id ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'status_id_required',
						__( 'An order status needs an identifier: it is what the orders in it are recorded as, and a label that changes cannot be it.', 'wc-checkoutsuite' ),
						array( 'index' => (int) $index )
					)
				);
			} elseif ( isset( $seen[ $id ] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'duplicate_status_id',
						sprintf(
							/* translators: %s: status identifier */
							__( 'The status identifier "%s" appears more than once.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'status' => $id )
					)
				);
			} else {
				$seen[ $id ] = true;
			}

			$result = $result->merge( self::validate_one( $status ) );
		}

		return $result;
	}

	/**
	 * Validates one status.
	 *
	 * @param OrderStatus $status Status.
	 * @return ValidationResult
	 */
	public static function validate_one( OrderStatus $status ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $status->id();

		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $id ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'status_id_not_a_slug',
					sprintf(
						/* translators: %s: status identifier */
						__( 'The status identifier "%s" has to be a slug of lowercase letters, digits, dashes and underscores: it is a key, not a sentence.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'status' => $id )
				)
			);
		}

		if ( strlen( $id ) > OrderStatus::MAX_ID ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'status_id_too_long',
					sprintf(
						/* translators: 1: status identifier, 2: longest accepted */
						__( 'The status identifier "%1$s" is longer than %2$d characters, which is what fits beside the prefix WooCommerce writes in front of it.', 'wc-checkoutsuite' ),
						$id,
						OrderStatus::MAX_ID
					),
					array( 'status' => $id )
				)
			);
		}

		if ( OrderStatus::is_reserved( $id ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'status_id_reserved',
					sprintf(
						/* translators: %s: status identifier */
						__( 'The identifier "%s" belongs to a status WooCommerce already has, and a custom status cannot take its place.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'status' => $id )
				)
			);
		}

		if ( '' === trim( $status->label() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'status_label_required',
					__( 'An order status needs a name: it is what the merchant and the staff see in the order list.', 'wc-checkoutsuite' ),
					array( 'status' => $id )
				)
			);
		}

		if ( '' !== $status->colour() && ! preg_match( '/^#[0-9a-fA-F]{6}$/', $status->colour() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'status_colour_not_hex',
					sprintf(
						/* translators: %s: colour the merchant typed */
						__( 'The colour "%s" is not a six digit hex colour such as #1F6FEB.', 'wc-checkoutsuite' ),
						$status->colour()
					),
					array( 'status' => $id )
				)
			);
		}

		return $result;
	}

	/**
	 * Refuses the one thing a status must never be.
	 *
	 * §12.5: a state an order waits in before payment must stay **unpaid**. The store does not
	 * decide that from the status, and this is where a configuration that tried to would be caught
	 * rather than trusted: the report says which statuses declare themselves pre-payment, so the
	 * registry can keep them out of WooCommerce's paid list and a proof can hold it to it.
	 *
	 * @param array<int, mixed> $statuses Raw statuses.
	 * @return array<int, string> Identifiers that declare themselves pre-payment.
	 */
	public static function prepayment_ids( array $statuses ): array {
		$ids = array();

		foreach ( $statuses as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$status = OrderStatus::from_array( $raw );

			if ( $status->is_prepayment() && '' !== $status->id() ) {
				$ids[] = $status->id();
			}
		}

		return $ids;
	}
}
