<?php
/**
 * Who may read an uploaded file.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Uploads;

/**
 * The decision to hand a stored file to somebody, in one place.
 *
 * A private upload is only private while one function decides who reads it. Section
 * 12 asks for a `DownloadPolicy` and section 20 for the rule behind it, and the rule
 * is short: a file may be read by the session that uploaded it, by the customer the
 * order belongs to, and by staff who can manage the store. Everyone else is refused,
 * and the refusal is the same whether the token exists or not — a policy that
 * answers "not yours" for a real token and "not found" for a made-up one is a policy
 * that tells a stranger which tokens exist.
 *
 * The session is checked before the order on purpose. An upload is bound to an order
 * when the order is placed, and until then the only thing that knows who owns it is
 * the session that uploaded it; a customer who has not finished checking out still
 * has to be able to see what they attached, and to remove it.
 *
 * @see ROADMAP.md sections 12 and 20
 * @see docs/adr/ADR-0002-private-upload-storage.md
 */
final class DownloadPolicy {

	/**
	 * Whether one request may read one file.
	 *
	 * @param array<string, mixed> $record  Upload record.
	 * @param array<string, mixed> $context Who is asking: `owner`, `user_id`, `can_manage`, `order_customer`.
	 * @return bool
	 */
	public static function allows( array $record, array $context ): bool {
		$owner = isset( $record['owner'] ) ? (string) $record['owner'] : '';

		if ( '' === $owner ) {
			return false;
		}

		$session = isset( $context['owner'] ) ? (string) $context['owner'] : '';

		if ( '' !== $session && hash_equals( $owner, $session ) ) {
			return true;
		}

		$user = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( $user > 0 && ! empty( $context['can_manage'] ) ) {
			return true;
		}

		// A document stored on the customer's profile has a permanent owner rather
		// than a checkout session. The user id is still checked from the server-side
		// record, never from the destination or the request body.
		if ( $user > 0 && hash_equals( $owner, 'customer:' . $user ) ) {
			return true;
		}

		// The customer the bound order belongs to. An upload that is not bound to an
		// order has no customer, which is why this is asked last and answers nothing
		// when the value is absent.
		$customer = isset( $context['order_customer'] ) ? (int) $context['order_customer'] : 0;

		return $user > 0 && $customer > 0 && $user === $customer;
	}

	/**
	 * The one answer a refusal gives.
	 *
	 * The same code and the same message for every reason, including a token that
	 * does not exist: telling them apart would let anyone enumerate which handles are
	 * real.
	 *
	 * @return array{code: string, message: string}
	 */
	public static function refusal(): array {
		return array(
			'code'    => 'not_allowed',
			'message' => __( 'That file is not available.', 'wc-checkoutsuite' ),
		);
	}
}
