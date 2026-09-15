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
	 * Endpoint slugs WooCommerce owns.
	 *
	 * @var array<int,string>
	 */
	private const RESERVED_ACCOUNT_SLUGS = array(
		'dashboard',
		'orders',
		'downloads',
		'edit-address',
		'payment-methods',
		'edit-account',
		'customer-logout',
	);

	/**
	 * Icons the account renderer currently understands.
	 *
	 * @var array<int,string>
	 */
	private const ACCOUNT_ICONS = array( 'user', 'fields', 'file', 'mail' );

	/**
	 * The area served by the customer's own page in My Account.
	 */
	private const ACCOUNT_AREA = 'customer_account';

	/**
	 * The destinations whose values belong to the customer.
	 *
	 * Both are surfaces the customer's own data is read from or written to, with no
	 * order in hand, so a field linked into either has to store on the customer.
	 *
	 * @var array<int,string>
	 */
	private const CUSTOMER_SURFACES = array( 'customer_account', 'admin_customer' );

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

		$presentation = $section->presentation();
		if ( isset( $presentation['show_title'] ) && ! is_bool( $presentation['show_title'] ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_section_title_visibility',
					__( 'Whether a section title is shown must be true or false.', 'wc-checkoutsuite' ),
					array( 'section' => $id )
				)
			);
		}

		if ( $section->is_offered_in( self::ACCOUNT_AREA ) ) {
			$result = $result->merge( self::validate_account_presentation( $section ) );
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
	 * Ensures an account section can become one safe WooCommerce endpoint.
	 *
	 * @param SectionDefinition $section Section offered in My Account.
	 * @return ValidationResult
	 */
	private static function validate_account_presentation( SectionDefinition $section ): ValidationResult {
		$result   = ValidationResult::valid();
		$id       = $section->id();
		$account  = $section->account();
		$slug     = isset( $account['slug'] ) ? trim( (string) $account['slug'] ) : '';
		$label    = isset( $account['menu_label'] ) ? trim( (string) $account['menu_label'] ) : '';
		$icon     = isset( $account['icon'] ) ? (string) $account['icon'] : '';
		$mode     = isset( $account['mode'] ) ? (string) $account['mode'] : 'edit';
		$position = isset( $account['position'] ) ? $account['position'] : 0;

		if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) || in_array( $slug, self::RESERVED_ACCOUNT_SLUGS, true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_account_endpoint_slug',
					sprintf(
						/* translators: %s: section id */
						__( 'The My Account endpoint for section "%s" must use a unique lowercase slug and cannot replace a WooCommerce page.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'section' => $id )
				)
			);
		}

		if ( '' === $label || mb_strlen( $label ) > 100 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_account_menu_label',
					__( 'A My Account section needs a menu label of at most 100 characters.', 'wc-checkoutsuite' ),
					array( 'section' => $id )
				)
			);
		}

		if ( ! in_array( $icon, self::ACCOUNT_ICONS, true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_account_icon',
					__( 'A My Account section must use one of the supported icons.', 'wc-checkoutsuite' ),
					array( 'section' => $id )
				)
			);
		}

		if ( ! in_array( $mode, array( 'edit', 'view' ), true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_account_mode',
					__( 'A My Account section must be editable or read-only.', 'wc-checkoutsuite' ),
					array( 'section' => $id )
				)
			);
		}

		if ( ( ! is_int( $position ) && ! ctype_digit( (string) $position ) ) || (int) $position < 0 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_account_menu_position',
					__( 'The My Account menu position cannot be negative.', 'wc-checkoutsuite' ),
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
		$result        = ValidationResult::valid();
		$seen          = array();
		$account_slugs = array();

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

			if ( $section->is_offered_in( self::ACCOUNT_AREA ) ) {
				$account = $section->account();
				$slug    = isset( $account['slug'] ) ? trim( (string) $account['slug'] ) : '';

				if ( '' !== $slug && isset( $account_slugs[ $slug ] ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'duplicate_account_endpoint_slug',
							sprintf(
								/* translators: %s: endpoint slug */
								__( 'The My Account endpoint slug "%s" is used by more than one section.', 'wc-checkoutsuite' ),
								$slug
							),
							array( 'section' => $id )
						)
					);
				}

				if ( '' !== $slug ) {
					$account_slugs[ $slug ] = true;
				}
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
		$result              = ValidationResult::valid();
		$known               = SectionLocations::values();
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
		$offered  = array();
		$declared = array();

		foreach ( $sections as $raw_section ) {
			if ( ! is_array( $raw_section ) || ! isset( $raw_section['id'] ) ) {
				continue;
			}

			$definition = SectionDefinition::from_array( $raw_section );

			$offered[ $definition->id() ]  = $definition->areas();
			$declared[ $definition->id() ] = $definition;
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

			// A customer surface reads and writes the customer's own data, and it does
			// so with no order in hand. A field linked into one of them therefore has to
			// store on the customer: the page or the panel would otherwise offer a form
			// that saves to a scope nothing provides, which is configuration the merchant
			// believes is in place and which does nothing.
			foreach ( self::CUSTOMER_SURFACES as $surface ) {
				$link   = $definition->destinations()[ $surface ] ?? array();
				$target = isset( $link['section'] ) ? (string) $link['section'] : '';

				if ( empty( $link['enabled'] ) || '' === $target || ! isset( $declared[ $target ] ) ) {
					continue;
				}

				if ( 'customer' === (string) ( $definition->to_array()['storage']['scope'] ?? '' ) ) {
					continue;
				}

				$result = $result->merge(
					ValidationResult::invalid(
						'account_section_requires_customer_storage',
						sprintf(
							/* translators: 1: destination key, 2: section id, 3: field id */
							__( 'The surface "%1$s" writes to the customer, so the field "%3$s" linked into the section "%2$s" must store its value on the customer.', 'wc-checkoutsuite' ),
							$surface,
							$target,
							$definition->id()
						),
						array(
							'field'       => $definition->id(),
							'destination' => $surface,
							'section'     => $target,
						)
					)
				);
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
