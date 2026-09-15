<?php
/**
 * What a gateway action answered.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * The outcome of one action on a gateway.
 *
 * §20 rule 4 is the reason this is a value and not a boolean: *"Somente confirmação real de pagamento
 * chama/recebe `payment_complete()`."* A gateway can answer four different things, and only one of
 * them is a payment:
 *
 * - `confirmed` — the gateway says the money moved, and only this calls `payment_complete()`.
 * - `pending` — the gateway accepted the request and has not decided. The order waits, and nothing
 *   concludes anything about it.
 * - `refused` — the gateway said no. The order is told, and nothing was charged.
 * - `unsupported` — this adapter cannot perform this action, and the store falls back.
 *
 * The fourth is not an error: it is the answer that sends the customer to the pay-for-order page,
 * which §13.2 step 4 asks for by name. Folding it into `refused` would tell a customer their payment
 * was rejected when the truth is that the store never asked.
 *
 * @see \ROADMAP.md section 20
 */
final class PaymentActionResult {

	/**
	 * The gateway confirmed the payment.
	 */
	public const CONFIRMED = 'confirmed';

	/**
	 * The gateway accepted the request and has not decided.
	 */
	public const PENDING = 'pending';

	/**
	 * The gateway refused.
	 */
	public const REFUSED = 'refused';

	/**
	 * The adapter cannot perform the action.
	 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * Every outcome, so a reader of the audit can enumerate them.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array( self::CONFIRMED, self::PENDING, self::REFUSED, self::UNSUPPORTED );
	}

	/**
	 * Constructor.
	 *
	 * @param string               $status         One of the four outcomes.
	 * @param string               $transaction_id Identifier the gateway gave, when it gave one.
	 * @param string               $message        What the gateway or the adapter said.
	 * @param array<string, mixed> $meta Anything the adapter wants recorded.
	 */
	public function __construct(
		private string $status = self::UNSUPPORTED,
		private string $transaction_id = '',
		private string $message = '',
		private array $meta = array()
	) {
	}

	/**
	 * Builds one result from an array an adapter returned.
	 *
	 * A status outside the vocabulary is read as `unsupported` rather than trusted: an adapter that
	 * answers something the store does not know has not confirmed a payment, and the direction of
	 * that mistake has to be the safe one.
	 *
	 * @param array<string, mixed> $data Raw result.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$status = isset( $data['status'] ) ? (string) $data['status'] : '';

		return new self(
			in_array( $status, self::statuses(), true ) ? $status : self::UNSUPPORTED,
			isset( $data['transaction_id'] ) ? (string) $data['transaction_id'] : '',
			isset( $data['message'] ) ? (string) $data['message'] : '',
			isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array()
		);
	}

	/**
	 * The outcome.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Identifier the gateway gave.
	 *
	 * @return string
	 */
	public function transaction_id(): string {
		return $this->transaction_id;
	}

	/**
	 * What was said.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Anything else the adapter wanted recorded.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Whether the gateway confirmed the payment.
	 *
	 * The one question §20 rule 4 turns on.
	 *
	 * @return bool
	 */
	public function is_confirmed(): bool {
		return self::CONFIRMED === $this->status;
	}

	/**
	 * Whether the store has to wait.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return self::PENDING === $this->status;
	}

	/**
	 * Exports the result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'status'         => $this->status,
			'transaction_id' => $this->transaction_id,
			'message'        => $this->message,
			'meta'           => $this->meta,
		);
	}
}
