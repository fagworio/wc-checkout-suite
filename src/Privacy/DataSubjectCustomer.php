<?php
/**
 * The customer account a privacy request is about.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Privacy;

/**
 * Finds the account a person's request covers.
 *
 * A value collected on a My Account page belongs to the customer and not to any order,
 * so the order search — however thorough — never finds it. Both halves of a request ask
 * here as well, and the answer is the same `WP_User` for both: the export has to hand
 * over what the store keeps, and the erasure has to remove it, from the same definition
 * of who the person is.
 *
 * Only an address that is a real account is matched. A request from an address the store
 * has no account for is not an account question, and inventing one would report data the
 * store does not hold.
 */
final class DataSubjectCustomer {

	/**
	 * The account a request for this address is about, when there is one.
	 *
	 * @param string $email Email address the request came from.
	 * @return \WP_User|null Account, or null.
	 */
	public static function for_email( string $email ): ?\WP_User {
		$email = trim( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		return $user instanceof \WP_User ? $user : null;
	}
}
