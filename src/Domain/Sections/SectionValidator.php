<?php
/**
 * Section validation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Sections;

use WCCheckoutSuite\Domain\Approval\ApprovalFlow;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Validates the sections of a document and the references fields make to them.
 *
 * Two gaps are closed here. Sections were stored without ever being validated, so
 * a document could carry a section with no title or two sections sharing an id.
 * And a field's `section` was never checked against anything, so it could point at
 * a section that does not exist — which on a checkout means a field that is
 * configured, saved, and never rendered anywhere.
 *
 * The valid targets are the declared sections plus the domain locations. The five
 * locations are always valid because section 4 fixes them as domain concepts, and
 * because an adopted WooCommerce field carries one of them; everything else has to
 * be declared in the same document.
 *
 * The methods are static, mirroring {@see \WCCheckoutSuite\Domain\Fields\SchemaValidator}:
 * there is no collaborator to inject, so an instance would only add a way to
 * forget to construct it.
 *
 * @see ROADMAP.md sections 4 and 20
 */
final class SectionValidator {

	/**
	 * Validates a section definition on its own.
	 *
	 * @param SectionDefinition $section Section.
	 * @return ValidationResult
	 */
	public static function validate( SectionDefinition $section ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $section->id();

		if ( '' === $id ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_missing_id',
					__( 'A section requires an id.', 'wc-checkoutsuite' )
				)
			);
		} elseif ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_]*$/', $id ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_invalid_id',
					sprintf(
						/* translators: %s: section id */
						__( 'The section id "%s" must use lowercase letters, digits and underscores only.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'section' => $id )
				)
			);
		}

		if ( '' === $section->title() ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_missing_title',
					sprintf(
						/* translators: %s: section id */
						__( 'The section "%s" requires a title.', 'wc-checkoutsuite' ),
						'' === $id ? '(unnamed)' : $id
					),
					array( 'section' => $id )
				)
			);
		}

		if ( mb_strlen( $section->description() ) > 500 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_description_too_long',
					sprintf(
						/* translators: %s: section id */
						__( 'The description of "%s" must be at most 500 characters long.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'section' => $id )
				)
			);
		}

		if ( $section->position() < 0 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_invalid_position',
					sprintf(
						/* translators: %s: section id */
						__( 'The position of "%s" cannot be negative.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'section' => $id )
				)
			);
		}

		// The areas a section is offered in. A section offered nowhere is a section
		// nobody can choose; an area that does not exist is a promise of a panel that
		// will never be drawn.
		$areas = $section->areas();

		if ( array() === $areas ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_without_area',
					sprintf(
						/* translators: %s: section id */
						__( 'The section "%s" must be offered in at least one area.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'section' => $id )
				)
			);
		}

		foreach ( $areas as $area ) {
			if ( ! in_array( (string) $area, DefinitionVocabulary::section_area_values(), true ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'section_unknown_area',
						sprintf(
							/* translators: 1: area key, 2: section id */
							__( 'The area "%1$s" is not one a section can be offered in (section "%2$s").', 'wc-checkoutsuite' ),
							(string) $area,
							$id
						),
						array( 'section' => $id )
					)
				);
			}
		}

		if ( ! SectionLocations::has( $section->location() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'section_unknown_location',
					sprintf(
						/* translators: 1: section id, 2: comma separated list of locations */
						__( 'The location of "%1$s" must be one of: %2$s.', 'wc-checkoutsuite' ),
						$id,
						implode( ', ', SectionLocations::values() )
					),
					array( 'section' => $id )
				)
			);
		}

		return $result;
	}

	/**
	 * Validates the section list of a document.
	 *
	 * @param array<int, mixed> $sections Raw section list.
	 * @return ValidationResult
	 */
	public static function validate_sections( array $sections ): ValidationResult {
		$result = ValidationResult::valid();
		$seen   = array();

		foreach ( $sections as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_section_entry',
						sprintf(
							/* translators: %d: index of the entry */
							__( 'The section at index %d is not a definition.', 'wc-checkoutsuite' ),
							(int) $index
						),
						array( 'index' => (int) $index )
					)
				);

				continue;
			}

			$section = SectionDefinition::from_array( $raw );
			$id      = $section->id();

			if ( '' !== $id && isset( $seen[ $id ] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'duplicate_section_id',
						sprintf(
							/* translators: %s: section id */
							__( 'The section id "%s" appears more than once.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'section' => $id )
					)
				);
			}

			if ( '' !== $id ) {
				$seen[ $id ] = true;
			}

			$result = $result->merge( self::validate( $section ) );
		}

		return $result;
	}

	/**
	 * Validates that every field points at a section that exists.
	 *
	 * @param array<int, mixed> $sections Raw section list.
	 * @param array<int, mixed> $fields   Raw field list.
	 * @return ValidationResult
	 */
	public static function validate_references( array $sections, array $fields ): ValidationResult {
		$result = ValidationResult::valid();
		$known  = SectionLocations::values();
		$collection_sections = array_fill_keys( $known, true );

		foreach ( $sections as $raw ) {
			if ( is_array( $raw ) && isset( $raw['id'] ) ) {
				$known[] = (string) $raw['id'];

				if ( in_array( 'checkout', (array) ( $raw['areas'] ?? array( 'checkout' ) ), true ) ) {
					$collection_sections[ (string) $raw['id'] ] = true;
				}
			}
		}

		$known = array_values( array_unique( $known ) );

		foreach ( $fields as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			// Resolved through the definition, not read from the raw array. An
			// omitted `section` means "order" to FieldDefinition, and a check that
			// read the raw key would call the same document valid in one place and
			// invalid in another.
			$definition = FieldDefinition::from_array( $raw_field );
			$field_id   = $definition->id();
			$section    = (string) $definition->to_array()['section'];

			if ( '' !== $section && in_array( $section, $known, true ) ) {
				continue;
			}

			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_section',
					sprintf(
						/* translators: 1: field id, 2: section id */
						__( 'The field "%1$s" belongs to the section "%2$s", which this document does not declare.', 'wc-checkoutsuite' ),
						'' === $field_id ? '(unnamed)' : $field_id,
						'' === $section ? '(empty)' : $section
					),
					array(
						'field'   => $field_id,
						'section' => $section,
					)
				)
			);
		}

		// The section each destination link points at is checked here, where the
		// document's sections are known. A link to a section that does not exist, or
		// that is not offered in that destination's area, is configuration the
		// merchant believes is in place and which would insert nothing anywhere.
		$offered = array();

		foreach ( $sections as $raw_section ) {
			if ( ! is_array( $raw_section ) || ! isset( $raw_section['id'] ) ) {
				continue;
			}

			$definition = SectionDefinition::from_array( $raw_section );

			$offered[ $definition->id() ] = $definition->areas();
		}

		foreach ( $fields as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw_field );

			foreach ( $definition->destinations() as $destination => $link ) {
				$section = isset( $link['section'] ) ? (string) $link['section'] : '';

				if ( '' === $section ) {
					continue;
				}

				if ( ! isset( $offered[ $section ] ) || ! in_array( (string) $destination, $offered[ $section ], true ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'destination_section_not_offered',
							sprintf(
								/* translators: 1: destination key, 2: section id, 3: field id */
								__( 'The section "%2$s" is not offered in the area "%1$s" (field "%3$s").', 'wc-checkoutsuite' ),
								(string) $destination,
								$section,
								$definition->id()
							),
							array(
								'field'       => $definition->id(),
								'destination' => (string) $destination,
								'section'     => $section,
							)
						)
					);
				}
			}

			// The approval flow names an area and a section too, and they are checked
			// the same way for the same reason: a review that happens in a section the
			// store does not offer there is a review nobody will see.
			$flow = ApprovalFlow::of( $definition );

			if ( $flow->enabled() ) {
				$area    = $flow->area();
				$section = $flow->section();

				if ( '' !== $section && ( ! isset( $offered[ $section ] ) || ! in_array( $area, $offered[ $section ], true ) ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'approval_section_not_offered',
							sprintf(
								/* translators: 1: area key, 2: section id, 3: field id */
								__( 'The approval review of "%3$s" happens in the section "%2$s", which is not offered in the area "%1$s".', 'wc-checkoutsuite' ),
								$area,
								$section,
								$definition->id()
							),
							array(
								'field'   => $definition->id(),
								'area'    => $area,
								'section' => $section,
							)
						)
					);
				}
			}
		}

		return $result;
	}
}
