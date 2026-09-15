<?php
/**
 * The fields one customer section shows, and what a submission of them means.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Customers;

use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;

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
	 * A document is on the list now that the customer store exists for it: the file belongs
	 * to the customer, is found by customer and field, and does not expire with a cart
	 * ({@see \WCCheckoutSuite\Domain\Uploads\UploadService::accept_for_customer()}). Heading
	 * and hidden fields stay out: they represent no value of the customer's own.
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
		'file',
	);

	/**
	 * The entries one section shows on one surface, in the order the links set.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @param string                           $destination Destination key of the surface.
	 * @param string                           $section_id  Container identifier.
	 * @return array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> Renderable entries.
	 */
	public static function entries( array $definitions, string $destination, string $section_id ): array {
		$entries = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$field = FieldDefinition::from_array( $raw );

			if ( ! $field->is_enabled() || ! in_array( $field->type(), self::RENDERABLE_TYPES, true ) ) {
				continue;
			}

			// One entry per binding: the same field may be used twice on the same page,
			// with its own title, its own order and its own editable decision (§3.3).
			foreach ( $field->bindings_for( $destination ) as $binding ) {
				if ( ! $binding->is_visible() || $binding->container_id() !== $section_id ) {
					continue;
				}

				$entries[] = array(
					'field'    => $field,
					'title'    => $binding->title_for( $field->label() ),
					'position' => $binding->has_position()
						? $binding->position()
						: $field->position(),
					'binding'  => $binding,
				);
			}
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				$by_position = $a['position'] <=> $b['position'];

				if ( 0 !== $by_position ) {
					return $by_position;
				}

				return $a['binding']->id() <=> $b['binding']->id();
			}
		);

		return $entries;
	}

	/**
	 * Whether the use of a field on this surface lets the value be written.
	 *
	 * @param array{field: FieldDefinition, title: string, position: int, binding: FieldBinding} $entry Entry.
	 * @return bool
	 */
	public static function entry_writable( array $entry ): bool {
		return $entry['binding']->is_editable();
	}

	/**
	 * Whether any use of a field on this surface lets the value be written.
	 *
	 * @param FieldDefinition $field       Field definition.
	 * @param string          $destination Destination key.
	 * @return bool
	 */
	public static function writable( FieldDefinition $field, string $destination ): bool {
		foreach ( $field->bindings_for( $destination ) as $binding ) {
			if ( $binding->is_editable() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates one submission and returns what may be written.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> $entries     Rendered entries.
	 * @param string                                                                                         $destination Destination key of the surface.
	 * @param array<string,mixed>                                                                            $values      Current customer values.
	 * @param array<string,mixed>                                                                            $posted      Sanitized submitted values.
	 * @param array<int,string>                                                                              $errors      Validation errors, by reference.
	 * @return array<string,mixed> Canonical updates.
	 */
	public static function submission( array $entries, string $destination, array $values, array $posted, array &$errors ): array {
		$updates   = array();
		$canonical = array();
		$seen      = array();

		foreach ( $entries as $entry ) {
			$field = $entry['field'];

			if ( ! self::entry_writable( $entry ) ) {
				continue;
			}

			if ( isset( $seen[ $field->id() ] ) ) {
				// The same field used twice: it is one value, validated and written once.
				continue;
			}

			$seen[ $field->id() ] = true;

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

	/**
	 * Whether a field is a document on a customer surface.
	 *
	 * The type decides, through the registry, exactly as the checkout's file permissions do:
	 * a type that declares it stores a file is a document, and one this plugin has never
	 * heard of is not guessed at.
	 *
	 * @param FieldDefinition $field Field.
	 * @return bool
	 */
	public static function is_document( FieldDefinition $field ): bool {
		return FilePermissions::is_file( $field );
	}

	/**
	 * How a customer's document reads on screen.
	 *
	 * A document is not a stored value: it is a row in the uploads table, found by customer
	 * and field. This is the sentence both surfaces show — the name the customer recognises,
	 * with the size, or the fact that nothing has been sent yet.
	 *
	 * @param array<string, mixed>|null $record Upload record, or null when there is none.
	 * @return string
	 */
	public static function document_label( ?array $record ): string {
		if ( null === $record ) {
			return __( 'Nenhum documento enviado.', 'wc-checkoutsuite' );
		}

		$name = isset( $record['file_name'] ) ? (string) $record['file_name'] : '';
		$size = isset( $record['byte_size'] ) ? (int) $record['byte_size'] : 0;

		if ( '' === $name ) {
			return __( 'Documento enviado.', 'wc-checkoutsuite' );
		}

		return sprintf(
			/* translators: 1: file name, 2: human readable size. */
			__( '%1$s (%2$s)', 'wc-checkoutsuite' ),
			$name,
			size_format( max( 0, $size ) )
		);
	}
}
