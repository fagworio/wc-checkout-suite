<?php
/**
 * The workflows a store runs, and what they did to which order.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WC_Order;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry;

/**
 * Where the workflows live, and where the record of what they did lives.
 *
 * The definitions are an option, beside the order statuses and for the same reasons: a workflow is
 * not part of the checkout composition, it has its own screen (§13), and restoring an earlier
 * schema revision must not delete the automations an order is halfway through.
 *
 * The **audit log is order meta**, and that is a decision rather than a shortcut. What the engine
 * needs is not a report somebody reads once; it is the fact that answers "did this already happen
 * to this order?", and that fact belongs to the order: it travels with the order in an export, it
 * is deleted when the order is, and it is read in the same query an order screen already makes.
 * A table would be faster to search and would also be a second place where an order's history
 * lives, which is what the plugin has refused everywhere else.
 *
 * The log is append-only. An entry is written once and never edited: an audit that could be
 * rewritten is not an audit.
 *
 * @see \ROADMAP.md sections 13.4, 13.7
 */
final class WorkflowRepository {

	/**
	 * Option holding the workflow definitions.
	 */
	public const OPTION = 'wccs_workflows';

	/** Option holding the compare-and-swap revision. */
	public const REVISION_OPTION = 'wccs_workflows_revision';

	/**
	 * Current revision of the stored list.
	 *
	 * @return int Revision.
	 */
	public function revision(): int {
		return max( 0, (int) get_option( self::REVISION_OPTION, 0 ) );
	}

	/**
	 * Order meta holding the audit log.
	 */
	public const META_LOG = '_wccs_workflow_log';

	/**
	 * Order meta holding the workflow that took the order, and when it has to decide by.
	 */
	public const META_STATE = '_wccs_workflow_state';

	/**
	 * Every stored workflow, in the order the merchant arranged them.
	 *
	 * @return array<int, WorkflowDefinition>
	 */
	public function all(): array {
		return array_values(
			array_map(
				static fn( array $raw ): WorkflowDefinition => WorkflowDefinition::from_array( $raw ),
				$this->raw()
			)
		);
	}

	/**
	 * The stored list, as arrays, exactly as it was written.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function raw(): array {
		$stored = get_option( self::OPTION );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );

		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	/**
	 * One workflow, or null.
	 *
	 * @param string $id Identifier.
	 * @return WorkflowDefinition|null
	 */
	public function find( string $id ): ?WorkflowDefinition {
		foreach ( $this->all() as $workflow ) {
			if ( $workflow->id() === $id ) {
				return $workflow;
			}
		}

		return null;
	}

	/**
	 * Replaces the list.
	 *
	 * @param array<int, mixed>       $workflows Raw workflows.
	 * @param array<int, string>|null $statuses  Status identifiers the store has; read from the
	 *                                           registry when omitted, because a workflow that
	 *                                           names a status the store does not have is refused
	 *                                           here rather than at the moment an order checks out.
	 * @return ValidationResult
	 */
	public function save( array $workflows, ?array $statuses = null ): ValidationResult {
		$workflows = $this->identify( array_values( array_filter( $workflows, 'is_array' ) ) );
		$result    = WorkflowValidator::validate_all( $workflows, $statuses ?? OrderStatusRegistry::known_ids() );

		if ( ! $result->is_valid() ) {
			return $result;
		}

		update_option( self::OPTION, (string) wp_json_encode( $workflows ), false );
		update_option( self::REVISION_OPTION, $this->revision() + 1, false );

		return $result;
	}

	/**
	 * The audit log of one order.
	 *
	 * @param int $order_id Order identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function log( int $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$stored = $order->get_meta( self::META_LOG );

		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );

			return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
		}

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Whether something has already happened to an order.
	 *
	 * The question the engine asks before every effect, and the whole of the idempotency: a repeated
	 * webhook, a retried request, a second status change that fires the same hook, and an operator
	 * pressing the button twice are all the same event as far as this is concerned, because they
	 * arrive with the same key.
	 *
	 * @param int    $order_id Order identifier.
	 * @param string $key      Event key.
	 * @return bool
	 */
	public function has( int $order_id, string $key ): bool {
		foreach ( $this->log( $order_id ) as $entry ) {
			if ( (string) ( $entry['key'] ?? '' ) === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Appends one entry to an order's log.
	 *
	 * Written **before** the effect it records, which is what makes a crash halfway harmless: an
	 * entry with no effect behind it is a missing transition somebody can repeat by hand, while an
	 * effect with no entry behind it is a capture that happens twice.
	 *
	 * @param WC_Order             $order Order.
	 * @param string               $key   Event key: what happened, uniquely.
	 * @param array<string, mixed> $entry Entry.
	 * @return bool Whether it was written, which is false when it was already there.
	 */
	public function append( WC_Order $order, string $key, array $entry = array() ): bool {
		$log = $this->log( (int) $order->get_id() );

		foreach ( $log as $existing ) {
			if ( (string) ( $existing['key'] ?? '' ) === $key ) {
				return false;
			}
		}

		$log[] = array_merge(
			array(
				'key'  => $key,
				'at'   => gmdate( 'c' ),
				'user' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
			$entry
		);

		$order->update_meta_data( self::META_LOG, (string) wp_json_encode( $log ) );

		return true;
	}

	/**
	 * The workflow an order is in, and when it has to be decided by.
	 *
	 * @param int $order_id Order identifier.
	 * @return array<string, mixed>
	 */
	public function state( int $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$stored = $order->get_meta( self::META_STATE );

		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Records the workflow an order entered, and when it must be decided by.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $id       Workflow identifier.
	 * @param int      $hours    Hours before it expires, or zero.
	 * @param string   $deadline ISO-8601 moment it expires at, or an empty string.
	 * @return void
	 */
	public function remember( WC_Order $order, string $id, int $hours, string $deadline = '' ): void {
		$order->update_meta_data(
			self::META_STATE,
			(string) wp_json_encode(
				array(
					'workflow' => $id,
					'hours'    => $hours,
					'deadline' => $deadline,
					'entered'  => gmdate( 'c' ),
				)
			)
		);
	}

	/**
	 * Gives an identifier to every workflow that does not have one yet.
	 *
	 * The same division of labour the order statuses use: the screen sends the name, and the store
	 * assigns the key once, so the identity rule lives in one place.
	 *
	 * @param array<int, array<string, mixed>> $workflows Raw workflows.
	 * @return array<int, array<string, mixed>>
	 */
	private function identify( array $workflows ): array {
		$taken = array();

		foreach ( $workflows as $raw ) {
			if ( '' !== (string) ( $raw['id'] ?? '' ) ) {
				$taken[] = (string) $raw['id'];
			}
		}

		foreach ( $workflows as $index => $raw ) {
			if ( '' !== (string) ( $raw['id'] ?? '' ) ) {
				continue;
			}

			$name = trim( (string) ( $raw['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$assigned = WorkflowDefinition::unique_id( $name, $taken );

			$workflows[ $index ]['id'] = $assigned;
			$taken[]                   = $assigned;
		}

		return $workflows;
	}
}
