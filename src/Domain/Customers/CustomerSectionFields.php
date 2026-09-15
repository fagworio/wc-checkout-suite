<?php
/**
 * The fields one customer section shows, and what a submission of them means.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Customers;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;

/**
 * The shape a customer surface reads a section in, shared by every one of them.
 *
 * Two surfaces collect the customer's own data — the page inside My Account and the
 * panel staff read on the customer's profile — and they have to answer the same
 * questions the same way: which fields does this section show, under which title and
 * in which order, which of them may be written, and what does a submitted form mean?
 *
 * The answers are the *link* the merchant configured for that destination, not the
 * field's own position or label, and the validation is the same `ValueProcessor` the
 * checkout runs with the same definitions. A second implementation of either would
 * disagree with the first the day one of them was edited, and the disagreement would
 * be invisible until a customer's own text came back different from what they typed.
 *
 * @see ROADMAP.md section 14
 */
final class CustomerSectionFields {

	/**
	 * The types a customer surface can collect.
	 *
	 * File upload has its own private-media lifecycle and heading/hidden fields do not
	 * represent a customer-owned value. They stay out until that lifecycle exists for
	 * the customer store too, rather than being drawn as a control that cannot save.
	 *
	 * @var array<int,string>
	 */
	public const RENDERABLE_TYPES = array(
		'text',
		'textarea',
		'email',
		'tel',
		'url',
		'number',
		'date',
		'time',
		'select',
		'radio',
		'checkbox',
	);

	/**
	 * The entries one section shows on one surface, in the order the links set.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @param string                           $destination Destination key of the surface.
	 * @param string                           $section_id  Section identifier.
	 * @return array<int, array{field: FieldDefinition, title: string, position: int}> Renderable entries.
	 */
	public static function entries( array $definitions, string $destination, string $section_id ): array {
		$entries = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$field = FieldDefinition::from_array( $raw );
			$link  = $field->destinations()[ $destination ] ?? array();

			if (
				! $field->is_enabled() ||
				! in_array( $field->type(), self::RENDERABLE_TYPES, true ) ||
				empty( $link['enabled'] ) ||
				(string) ( $link['section'] ?? '' ) !== $section_id
			) {
				continue;
			}

			$title = isset( $link['title'] ) && '' !== trim( (string) $link['title'] )
				? (string) $link['title']
				: $field->label();

			$position = isset( $link['position'] ) && is_numeric( $link['position'] )
				? (int) $link['position']
				: $field->position();

			$entries[] = array(
				'field'    => $field,
				'title'    => $title,
				'position' => $position,
			);
		}

		usort( $entries, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return $entries;
	}

	/**
	 * Whether a link into this surface lets the value be written.
	 *
	 * @param FieldDefinition $field       Field definition.
	 * @param string          $destination Destination key.
	 * @return bool
	 */
	public static function writable( FieldDefinition $field, string $destination ): bool {
		$link = $field->destinations()[ $destination ] ?? array();

		return 'edit' === ( $link['mode'] ?? 'edit' );
	}

	/**
	 * Validates one submission and returns what may be written.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int}> $entries     Rendered entries.
	 * @param string                                                                  $destination Destination key of the surface.
	 * @param array<string,mixed>                                                     $values      Current customer values.
	 * @param array<string,mixed>                                                     $posted      Sanitized submitted values.
	 * @param array<int,string>                                                       $errors      Validation errors, by reference.
	 * @return array<string,mixed> Canonical updates.
	 */
	public static function submission( array $entries, string $destination, array $values, array $posted, array &$errors ): array {
		$updates   = array();
		$canonical = array();

		foreach ( $entries as $entry ) {
			$field = $entry['field'];

			if ( ! self::writable( $field, $destination ) ) {
				continue;
			}

			// The surface renders every writable field, so a key that never reached the
			// request was not part of this submission: it is left alone rather than read
			// as empty and used to erase what the customer had stored. The one exception
			// is the checkbox, where the browser's own convention is that an absent box
			// means unchecked.
			$is_checkbox = 'checkbox' === $field->type();

			if ( ! $is_checkbox && ! array_key_exists( $field->id(), $posted ) ) {
				continue;
			}

			$processed = Registries::instance()->value_processor()->process(
				$field,
				$posted[ $field->id() ] ?? false,
				new FieldContext(
					array(
						'customer_logged_in' => true,
						'fields'             => array_merge( $values, $canonical ),
					),
					'account'
				)
			);

			if ( ! $processed->result()->is_valid() ) {
				foreach ( $processed->result()->errors() as $error ) {
					$errors[] = (string) $error['message'];
				}

				continue;
			}

			$updates[ $field->id() ]   = $processed->value();
			$canonical[ $field->id() ] = $processed->value();
		}

		return $updates;
	}

	/**
	 * Renders one value as a readable string for a read-only surface.
	 *
	 * @param FieldDefinition $field Field definition.
	 * @param mixed           $value Stored customer value.
	 * @return string Display value.
	 */
	public static function display_value( FieldDefinition $field, mixed $value ): string {
		if ( 'checkbox' === $field->type() ) {
			return ! empty( $value )
				? __( 'Yes', 'wc-checkoutsuite' )
				: __( 'No', 'wc-checkoutsuite' );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
