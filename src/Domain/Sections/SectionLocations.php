<?php
/**
 * Logical locations a section may occupy.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Sections;

/**
 * The domain concepts a section can sit in.
 *
 * ROADMAP.md section 4 names Billing, Shipping, Contact, Account and Order as
 * domain concepts and immediately adds the warning that matters: they "não
 * correspondem automaticamente a slots idênticos em todos os checkouts". A
 * location is therefore a *logical* place, not a slot. The adapters decide where
 * a location actually lands, and the classic checkout, the block checkout and an
 * order screen will each answer differently.
 *
 * Keeping the distinction in the type — `location` rather than `slot` — is what
 * stops a later phase from treating the value as something it can insert at
 * directly.
 *
 * @see ROADMAP.md sections 4 and 428
 */
final class SectionLocations {

	/**
	 * The locations of version 1.0, in display order, with the explanation the
	 * admin shows.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function all(): array {
		return array(
			array(
				'value'       => 'billing',
				'label'       => __( 'Billing', 'wc-checkoutsuite' ),
				'description' => __(
					'Alongside the billing address.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'shipping',
				'label'       => __( 'Shipping', 'wc-checkoutsuite' ),
				'description' => __(
					'Alongside the shipping address, when the order is shipped.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'contact',
				'label'       => __( 'Contact', 'wc-checkoutsuite' ),
				'description' => __(
					'With the details used to reach the customer.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'account',
				'label'       => __( 'Account', 'wc-checkoutsuite' ),
				'description' => __(
					'With the sign-in and account creation fields.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'order',
				'label'       => __( 'Order', 'wc-checkoutsuite' ),
				'description' => __(
					'With the order notes, away from the addresses.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Valid location values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_values( array_map( static fn( array $entry ): string => $entry['value'], self::all() ) );
	}

	/**
	 * Whether a value is a location.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function has( string $value ): bool {
		return in_array( $value, self::values(), true );
	}

	/**
	 * Label of a location, falling back to the raw value.
	 *
	 * @param string $value Location value.
	 * @return string
	 */
	public static function label( string $value ): string {
		foreach ( self::all() as $entry ) {
			if ( $entry['value'] === $value ) {
				return $entry['label'];
			}
		}

		return $value;
	}
}
