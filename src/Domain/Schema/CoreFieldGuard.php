<?php
/**
 * Protection of the fields WooCommerce owns.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Schema;

use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Refuses a stored schema that would destroy a field the store depends on.
 *
 * The suite exists to let a merchant change core checkout fields, but only
 * selectively: shipping, tax, payment and other plugins all read those fields, so
 * removing one, disabling it or changing what it stores breaks the checkout in
 * ways that surface as a lost order rather than as an error. ROADMAP.md section 7
 * states the rule — a structurally required field "não pode ser removido sem
 * diagnóstico do impacto" — and this class is where that rule is enforced.
 *
 * Enforcement lives here, on the comparison between two documents, and not in the
 * admin: hiding a button is not protection. Any client, including one written
 * later, reaches storage through the repository, and the repository asks this
 * class before writing.
 *
 * Two design choices worth stating:
 *
 * 1. **The baseline is the document being replaced.** A field is protected
 *    because it was already stored as `core`, not because a hard-coded list says
 *    it should be. That keeps the rule independent of WooCommerce's version and
 *    of whatever other plugins register, and it means a field the Suite never
 *    adopted cannot be "protected" out of nowhere.
 *
 * 2. **`origin` itself is immutable.** Without that, protection is theatre: a
 *    client would relabel the field as `custom` and then delete it freely.
 *
 * What this class deliberately does *not* decide is whether an id that belongs to
 * WooCommerce may be declared `custom` in the first place. That is a property of a
 * single definition and is checked by {@see DefinitionValidator}, which can see
 * the live core field list.
 *
 * @see \ROADMAP.md sections 4, 7 and 20
 * @see \docs\adr\ADR-0001-storage-authority.md
 */
final class CoreFieldGuard {

	/**
	 * Whether a raw definition declares the core origin.
	 *
	 * @param mixed $field Raw definition.
	 * @return bool
	 */
	private static function is_core( mixed $field ): bool {
		return is_array( $field ) && 'core' === ( $field['origin'] ?? '' );
	}

	/**
	 * Identifier of a raw definition, or an empty string.
	 *
	 * @param mixed $field Raw definition.
	 * @return string
	 */
	private static function id_of( mixed $field ): string {
		return is_array( $field ) && isset( $field['id'] ) ? (string) $field['id'] : '';
	}

	/**
	 * Indexes a raw field list by identifier, preserving the first entry.
	 *
	 * @param array<int, mixed> $fields Raw fields.
	 * @return array<string, mixed>
	 */
	private static function index( array $fields ): array {
		$indexed = array();

		foreach ( $fields as $field ) {
			$id = self::id_of( $field );

			if ( '' === $id || array_key_exists( $id, $indexed ) ) {
				continue;
			}

			$indexed[ $id ] = $field;
		}

		return $indexed;
	}

	/**
	 * Checks a proposed field list against the list it would replace.
	 *
	 * @param array<int, mixed> $baseline Raw fields currently stored.
	 * @param array<int, mixed> $proposed Raw fields about to be stored.
	 * @return ValidationResult
	 */
	public function guard( array $baseline, array $proposed ): ValidationResult {
		$result = ValidationResult::valid();

		$seen       = array();
		$by_id      = array();
		$duplicated = array();

		foreach ( $proposed as $field ) {
			$id = self::id_of( $field );

			if ( '' !== $id ) {
				if ( isset( $seen[ $id ] ) ) {
					$duplicated[ $id ] = true;
				}

				$seen[ $id ] = true;
			}

			if ( '' !== $id && ! array_key_exists( $id, $by_id ) ) {
				$by_id[ $id ] = $field;
			}
		}

		foreach ( array_keys( $duplicated ) as $id ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'duplicate_field_id',
					sprintf(
						/* translators: %s: field id */
						__( 'The field id "%s" appears more than once. Identifiers must be unique.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		// Nothing else can be decided reliably while identifiers collide: the
		// guard could match the wrong entry and report protection that is not
		// actually in force.
		if ( array() !== $duplicated ) {
			return $result;
		}

		$baseline_by_id = self::index( $baseline );

		foreach ( $baseline_by_id as $id => $before ) {
			if ( ! self::is_core( $before ) ) {
				continue;
			}

			if ( ! array_key_exists( $id, $by_id ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'core_field_removed',
						sprintf(
							/* translators: %s: field id */
							__( 'The field "%s" belongs to WooCommerce and cannot be removed. Disable it or change how it is presented instead.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'field' => $id )
					)
				);

				continue;
			}

			$after = $by_id[ $id ];

			if ( ! self::is_core( $after ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'core_field_origin_changed',
						sprintf(
							/* translators: %s: field id */
							__( 'The field "%s" belongs to WooCommerce and cannot be re-declared as a custom field.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'field' => $id )
					)
				);

				continue;
			}

			$result = $result->merge( $this->compare( $id, $before, $after ) );
		}

		return $result;
	}

	/**
	 * Compares one protected field with its stored form.
	 *
	 * Only the parts that carry structural meaning are compared. Everything a
	 * merchant legitimately customises — label, description, position, width,
	 * section, settings and conditions — is left alone on purpose, because
	 * refusing those would make the feature useless.
	 *
	 * @param string $id     Field id.
	 * @param mixed  $before Stored definition.
	 * @param mixed  $after  Proposed definition.
	 * @return ValidationResult
	 */
	private function compare( string $id, mixed $before, mixed $after ): ValidationResult {
		$result = ValidationResult::valid();

		$before_array = is_array( $before ) ? $before : array();
		$after_array  = is_array( $after ) ? $after : array();

		$type_before = isset( $before_array['type'] ) ? (string) $before_array['type'] : '';
		$type_after  = isset( $after_array['type'] ) ? (string) $after_array['type'] : '';

		if ( $type_before !== $type_after ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'core_field_type_changed',
					sprintf(
						/* translators: 1: field id, 2: current type, 3: requested type */
						__( 'The field "%1$s" belongs to WooCommerce and stores a value as "%2$s". Changing it to "%3$s" would change what the store persists.', 'wc-checkoutsuite' ),
						$id,
						$type_before,
						$type_after
					),
					array(
						'field'    => $id,
						'expected' => $type_before,
						'given'    => $type_after,
					)
				)
			);
		}

		$integration_before = isset( $before_array['integration_id'] ) ? (string) $before_array['integration_id'] : '';
		$integration_after  = isset( $after_array['integration_id'] ) ? (string) $after_array['integration_id'] : '';

		if ( $integration_before !== $integration_after ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'core_field_identifier_changed',
					sprintf(
						/* translators: %s: field id */
						__( 'The integration key of "%s" cannot change: other code and stored orders refer to it.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		$enabled_before = ! isset( $before_array['enabled'] ) || (bool) $before_array['enabled'];
		$enabled_after  = ! isset( $after_array['enabled'] ) || (bool) $after_array['enabled'];

		if ( $enabled_before && ! $enabled_after ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'core_field_disabled',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" belongs to WooCommerce and cannot be archived. Shipping, tax and payment read it.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		$required_before = isset( $before_array['required'] ) && (bool) $before_array['required'];
		$required_after  = isset( $after_array['required'] ) && (bool) $after_array['required'];

		if ( $required_before && ! $required_after ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'core_field_requirement_relaxed',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" is required by WooCommerce. Making it optional needs an impact assessment, which this version does not provide.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		return $result;
	}
}
