<?php
/**
 * One automation: what starts it, where it puts the order, and where it can go from there.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WCCheckoutSuite\Domain\Support\Slug;

/**
 * A workflow, as §3.8 defines it and §13 draws it.
 *
 * The definition is **declarative**, like every other definition in this plugin: it says what the
 * store should do, and the engine is what does it. That split is what makes the same workflow
 * simulable without executing anything (§13.6) and what makes an audit entry a record of a decision
 * rather than a log line somebody printed.
 *
 * Three properties are worth stating rather than reading off the constructor.
 *
 * 1. **`initial_status` is where an order waits.** It is a status identifier, not a label: renaming
 *    a state must not change which state a workflow puts an order in (§12.6), and a label is
 *    mutable by design.
 * 2. **The strategies are declared and the store refuses what it cannot execute.** §13.2 offers
 *    stock and payment strategies whose execution belongs to the stock phase and to the gateway
 *    capability registry; this build accepts `none` and refuses the rest **by name**
 *    ({@see WorkflowValidator}), so a workflow cannot promise a reservation nothing makes or a
 *    capture nothing performs.
 * 3. **A transition is a decision, not a payment.** §13.4's "aprovar" moves the order and records
 *    that it was approved; what pays is a payment action with a gateway capability, and that is a
 *    later phase's business — and the gate of this one.
 *
 * @see \ROADMAP.md sections 3.8, 13
 */
final class WorkflowDefinition {

	/**
	 * Constructor.
	 *
	 * @param string                $id                   Permanent identifier.
	 * @param string                $name                 Name the merchant gave it.
	 * @param bool                  $enabled              Whether it may run at all.
	 * @param int                   $priority             Higher is considered first.
	 * @param string                $trigger              Moment that starts it.
	 * @param array<string, mixed>  $conditions           Rule tree that decides whether it runs.
	 * @param string                $initial_status       Status an order waits in.
	 * @param string                $inventory_strategy   What to do with stock.
	 * @param int                   $inventory_hours      How long a stock reservation lasts, when the strategy is `hours`.
	 * @param string                $payment_strategy     What to do about payment.
	 * @param array<string, bool>   $communications       Event key to whether the customer is told.
	 * @param array<string, string> $transitions         Decision key to the status it moves the order to.
	 * @param int                   $expires_after_hours  Hours without a decision before it expires, or 0.
	 * @param array<int, string>    $fallbacks            What to do when a strategy cannot be executed.
	 * @param array<string, mixed>  $extra                Keys this build does not know.
	 */
	public function __construct(
		private string $id = '',
		private string $name = '',
		private bool $enabled = true,
		private int $priority = 0,
		private string $trigger = Workflows::TRIGGER_CHECKOUT_SUBMITTED,
		private array $conditions = array(),
		private string $initial_status = '',
		private string $inventory_strategy = Workflows::INVENTORY_NONE,
		private int $inventory_hours = 0,
		private string $payment_strategy = Workflows::PAYMENT_NONE,
		private array $communications = array(),
		private array $transitions = array(),
		private int $expires_after_hours = 0,
		private array $fallbacks = array(),
		private array $extra = array()
	) {
	}

	/**
	 * Builds one workflow from a stored array.
	 *
	 * @param array<string, mixed> $data Raw workflow.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$known = array(
			'id',
			'name',
			'enabled',
			'priority',
			'trigger',
			'conditions',
			'initial_status',
			'inventory_strategy',
			'inventory_hours',
			'payment_strategy',
			'communications',
			'transitions',
			'expires_after_hours',
			'fallbacks',
		);

		$communications = array();
		$transitions    = array();

		foreach ( isset( $data['communications'] ) && is_array( $data['communications'] ) ? $data['communications'] : array() as $event => $told ) {
			$communications[ (string) $event ] = (bool) $told;
		}

		foreach ( isset( $data['transitions'] ) && is_array( $data['transitions'] ) ? $data['transitions'] : array() as $decision => $status ) {
			$transitions[ (string) $decision ] = (string) $status;
		}

		return new self(
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['name'] ) ? (string) $data['name'] : '',
			! isset( $data['enabled'] ) || (bool) $data['enabled'],
			isset( $data['priority'] ) && is_numeric( $data['priority'] ) ? (int) $data['priority'] : 0,
			isset( $data['trigger'] ) ? (string) $data['trigger'] : Workflows::TRIGGER_CHECKOUT_SUBMITTED,
			isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? $data['conditions'] : array(),
			isset( $data['initial_status'] ) ? (string) $data['initial_status'] : '',
			isset( $data['inventory_strategy'] ) ? (string) $data['inventory_strategy'] : Workflows::INVENTORY_NONE,
			isset( $data['inventory_hours'] ) && is_numeric( $data['inventory_hours'] ) ? (int) $data['inventory_hours'] : 0,
			isset( $data['payment_strategy'] ) ? (string) $data['payment_strategy'] : Workflows::PAYMENT_NONE,
			$communications,
			$transitions,
			isset( $data['expires_after_hours'] ) && is_numeric( $data['expires_after_hours'] ) ? (int) $data['expires_after_hours'] : 0,
			isset( $data['fallbacks'] ) && is_array( $data['fallbacks'] ) ? array_values( array_map( 'strval', $data['fallbacks'] ) ) : array(),
			array_diff_key( $data, array_flip( $known ) )
		);
	}

	/**
	 * The identifier a new workflow gets from its name.
	 *
	 * @param string             $name  Name the merchant typed.
	 * @param array<int, string> $taken Identifiers already in use.
	 * @return string
	 */
	public static function unique_id( string $name, array $taken = array() ): string {
		$base = Slug::key( $name, 'workflow' );

		if ( ! in_array( $base, $taken, true ) ) {
			return $base;
		}

		$suffix = 2;

		while ( in_array( $base . '_' . $suffix, $taken, true ) ) {
			++$suffix;
		}

		return $base . '_' . $suffix;
	}

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether it may run.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Priority: higher is considered first.
	 *
	 * @return int
	 */
	public function priority(): int {
		return $this->priority;
	}

	/**
	 * The moment that starts it.
	 *
	 * @return string
	 */
	public function trigger(): string {
		return $this->trigger;
	}

	/**
	 * The rule that decides whether it runs.
	 *
	 * @return array<string, mixed>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * Whether it runs for every order its trigger reaches.
	 *
	 * @return bool
	 */
	public function is_unconditional(): bool {
		return array() === $this->conditions;
	}

	/**
	 * The status an order waits in.
	 *
	 * @return string
	 */
	public function initial_status(): string {
		return $this->initial_status;
	}

	/**
	 * What to do about stock.
	 *
	 * @return string
	 */
	public function inventory_strategy(): string {
		return $this->inventory_strategy;
	}

	/**
	 * How long a stock reservation lasts.
	 *
	 * @return int
	 */
	public function inventory_hours(): int {
		return $this->inventory_hours;
	}

	/**
	 * What to do about payment.
	 *
	 * @return string
	 */
	public function payment_strategy(): string {
		return $this->payment_strategy;
	}

	/**
	 * Whether the customer is told about one event.
	 *
	 * @param string $event Event key.
	 * @return bool
	 */
	public function tells( string $event ): bool {
		return ! empty( $this->communications[ $event ] );
	}

	/**
	 * Every communication, event to whether the customer is told.
	 *
	 * @return array<string, bool>
	 */
	public function communications(): array {
		return $this->communications;
	}

	/**
	 * The status a decision moves the order to, or an empty string.
	 *
	 * @param string $decision Decision key.
	 * @return string
	 */
	public function target_for( string $decision ): string {
		return (string) ( $this->transitions[ $decision ] ?? '' );
	}

	/**
	 * Every transition, decision to status.
	 *
	 * @return array<string, string>
	 */
	public function transitions(): array {
		return $this->transitions;
	}

	/**
	 * Whether a decision is one this workflow answers.
	 *
	 * @param string $decision Decision key.
	 * @return bool
	 */
	public function answers( string $decision ): bool {
		return '' !== $this->target_for( $decision );
	}

	/**
	 * Hours without a decision before it expires, or zero for never.
	 *
	 * @return int
	 */
	public function expires_after_hours(): int {
		return $this->expires_after_hours;
	}

	/**
	 * Whether the clock can move an order out of this workflow's state.
	 *
	 * @return bool
	 */
	public function expires(): bool {
		return $this->expires_after_hours > 0 && $this->answers( Workflows::DECISION_EXPIRE );
	}

	/**
	 * What to do when a strategy cannot be executed.
	 *
	 * @return array<int, string>
	 */
	public function fallbacks(): array {
		return $this->fallbacks;
	}

	/**
	 * Exports the workflow as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array_merge(
			array(
				'id'                  => $this->id,
				'name'                => $this->name,
				'enabled'             => $this->enabled,
				'priority'            => $this->priority,
				'trigger'             => $this->trigger,
				'conditions'          => $this->conditions,
				'initial_status'      => $this->initial_status,
				'inventory_strategy'  => $this->inventory_strategy,
				'inventory_hours'     => $this->inventory_hours,
				'payment_strategy'    => $this->payment_strategy,
				'communications'      => $this->communications,
				'transitions'         => $this->transitions,
				'expires_after_hours' => $this->expires_after_hours,
				'fallbacks'           => $this->fallbacks,
			),
			$this->extra
		);
	}
}
