<?php
/**
 * Field type categories used by the admin picker.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Grouping of field types for the picker.
 *
 * A category is declared when a type is registered rather than by the type
 * itself. That is deliberate: {@see FieldTypeInterface} is a published contract,
 * and adding a method to it would break every third-party implementation that
 * already exists. A registration argument is additive — an extension that knows
 * nothing about categories keeps working and lands in {@see self::GENERAL}.
 *
 * A category key is a plain string so an extension can invent one. An unknown
 * key is not an error: it gets a readable label derived from the key, so a
 * third-party category still appears in the picker instead of being dropped.
 *
 * @see \ROADMAP.md sections 6 and 19
 */
final class FieldCategory {

	/**
	 * Category used when a registration declares none.
	 */
	public const GENERAL = 'general';

	/**
	 * Display order of the categories shipped with the plugin.
	 *
	 * Any category not listed here is appended after these, in key order, so an
	 * extension's category is never hidden.
	 *
	 * @return array<int, string>
	 */
	public static function order(): array {
		return array( 'text', 'choice', 'datetime', 'number', 'address', 'upload', 'layout', self::GENERAL );
	}

	/**
	 * Translatable label of a category.
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function label( string $key ): string {
		$labels = array(
			'text'     => __( 'Text and contact', 'wc-checkoutsuite' ),
			'choice'   => __( 'Choice', 'wc-checkoutsuite' ),
			'datetime' => __( 'Date and time', 'wc-checkoutsuite' ),
			'number'   => __( 'Numbers', 'wc-checkoutsuite' ),
			'address'  => __( 'Address', 'wc-checkoutsuite' ),
			'upload'   => __( 'Files', 'wc-checkoutsuite' ),
			'layout'   => __( 'Content and layout', 'wc-checkoutsuite' ),
			'general'  => __( 'Other', 'wc-checkoutsuite' ),
		);

		/**
		 * Labels of the field type categories.
		 *
		 * @param array<string, string> $labels Category key to label.
		 */
		$labels = apply_filters( 'wccs_field_category_labels', $labels );

		if ( isset( $labels[ $key ] ) && '' !== (string) $labels[ $key ] ) {
			return (string) $labels[ $key ];
		}

		// An unknown category still needs a name a person can read, otherwise a
		// third-party type would appear under a blank heading.
		return ucfirst( str_replace( array( '-', '_' ), ' ', $key ) );
	}

	/**
	 * Sort weight of a category, for a stable display order.
	 *
	 * @param string $key Category key.
	 * @return int
	 */
	public static function weight( string $key ): int {
		$position = array_search( $key, self::order(), true );

		return false === $position ? count( self::order() ) : (int) $position;
	}
}
