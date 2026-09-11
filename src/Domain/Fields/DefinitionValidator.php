<?php
/**
 * Definition validation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

use WCCheckoutSuite\Domain\Conditions\ConditionValidator;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;

/**
 * Validates a field definition against the registered types and presets.
 *
 * Nothing reaches the checkout without passing here. A definition that names an
 * unknown type, an unknown validator or a setting the type never declared is
 * rejected with a stable code instead of being stored and failing later.
 *
 * @see \ROADMAP.md sections 4, 6 and 20
 */
final class DefinitionValidator {

	/**
	 * Constructor.
	 *
	 * @param FieldTypeRegistry       $types          Field type registry.
	 * @param PresetRegistry          $presets        Preset registry.
	 * @param ValidatorRegistry       $validators     Validator registry.
	 * @param NormalizerRegistry|null $normalizers    Normalizer registry, when available.
	 * @param array<int, string>      $core_field_ids Identifiers of the fields WooCommerce owns.
	 * @param MaskRegistry|null       $masks          Mask registry, when available.
	 */
	public function __construct(
		private FieldTypeRegistry $types,
		private PresetRegistry $presets,
		private ValidatorRegistry $validators,
		private ?NormalizerRegistry $normalizers = null,
		private array $core_field_ids = array(),
		private ?MaskRegistry $masks = null
	) {
	}

	/**
	 * Validates a raw definition array.
	 *
	 * @param array<string, mixed> $data Raw definition.
	 * @return ValidationResult
	 */
	public function validate_array( array $data ): ValidationResult {
		return $this->validate( FieldDefinition::from_array( $data ) );
	}

	/**
	 * Validates the conditions a definition declares.
	 *
	 * The shape, the operator, the source and the type of the comparison value are
	 * all decidable from the rule alone, so they are checked here, on every write
	 * and not only at publication. Whether a rule names a field that exists, and
	 * whether two fields depend on each other, needs the rest of the document and
	 * is checked where the document is.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return ValidationResult
	 */
	private function validate_conditions( FieldDefinition $definition ): ValidationResult {
		return ConditionValidator::validate_rules(
			$definition->id(),
			$definition->to_array()['conditions']
		);
	}

	/**
	 * Validates the surfaces a definition declares beyond its type settings.
	 *
	 * ROADMAP.md section 428 requires the inspector to show only the properties a
	 * field actually supports. Enforcing the same limits here is what turns that
	 * from a promise the interface makes into a rule the store keeps: a mask on a
	 * type that cannot be masked, a storage scope for a field that stores nothing
	 * or a value exposed through the Store API for a field that has no value are
	 * all refused with a stable code.
	 *
	 * @param FieldDefinition      $definition Definition.
	 * @param array<string, mixed> $supports   Capabilities the type declares.
	 * @return ValidationResult
	 */
	private function validate_surfaces( FieldDefinition $definition, array $supports ): ValidationResult {
		$result        = ValidationResult::valid();
		$id            = $definition->id();
		$stores_value  = isset( $supports['value'] ) && true === $supports['value'];
		$can_be_masked = isset( $supports['maskable'] ) && true === $supports['maskable'];

		// One snapshot rather than five accessor calls: the definition exposes no
		// accessor for these, and asking it repeatedly would build the same array
		// again for every question.
		$raw           = $definition->to_array();
		$mask          = is_array( $raw['mask'] ) ? $raw['mask'] : null;
		$storage       = $raw['storage'];
		$visibility    = $raw['visibility'];
		$hidden_policy = (string) $raw['hidden_value_policy'];

		// The mask.
		if ( null !== $mask && array() !== $mask ) {
			if ( ! $can_be_masked ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'mask_not_supported',
						sprintf(
							/* translators: %s: field type key */
							__( 'The field type "%s" cannot be masked.', 'wc-checkoutsuite' ),
							$definition->type()
						),
						array( 'field' => $id )
					)
				);
			} else {
				$result = $result->merge( $this->validate_mask( $id, $mask ) );
			}
		}

		// Storage.
		$scope       = is_array( $storage ) && isset( $storage['scope'] ) ? (string) $storage['scope'] : '';
		$sensitivity = is_array( $storage ) && isset( $storage['sensitivity'] ) ? (string) $storage['sensitivity'] : '';

		if ( ! in_array( $scope, DefinitionVocabulary::storage_scope_values(), true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_storage_scope',
					sprintf(
						/* translators: 1: field id, 2: comma separated list of scopes */
						__( 'The storage scope of "%1$s" must be one of: %2$s.', 'wc-checkoutsuite' ),
						$id,
						implode( ', ', DefinitionVocabulary::storage_scope_values() )
					),
					array( 'field' => $id )
				)
			);
		} elseif ( ! $stores_value && 'none' !== $scope ) {
			// A heading stores nothing. Telling it to keep its value with the order
			// is a claim the checkout cannot honour.
			$result = $result->merge(
				ValidationResult::invalid(
					'storage_scope_requires_value',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" stores no value, so its storage scope has to be "none".', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		if ( ! in_array( $sensitivity, DefinitionVocabulary::storage_sensitivity_values(), true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_storage_sensitivity',
					sprintf(
						/* translators: 1: field id, 2: comma separated list of levels */
						__( 'The sensitivity of "%1$s" must be one of: %2$s.', 'wc-checkoutsuite' ),
						$id,
						implode( ', ', DefinitionVocabulary::storage_sensitivity_values() )
					),
					array( 'field' => $id )
				)
			);
		}

		// Visibility. Unknown keys are refused rather than ignored: silently
		// dropping one would let a client believe it had configured an exposure it
		// never configured.
		if ( ! is_array( $visibility ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_visibility',
					__( 'The visibility of a field must be a map of audience to true or false.', 'wc-checkoutsuite' ),
					array( 'field' => $id )
				)
			);
		} else {
			foreach ( $visibility as $audience => $allowed ) {
				if ( ! in_array( (string) $audience, DefinitionVocabulary::visibility_key_values(), true ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'unknown_visibility_audience',
							sprintf(
								/* translators: 1: audience key, 2: field id */
								__( 'The audience "%1$s" is not one a field can be exposed to (field "%2$s").', 'wc-checkoutsuite' ),
								(string) $audience,
								$id
							),
							array( 'field' => $id )
						)
					);
				} elseif ( ! is_bool( $allowed ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'invalid_visibility_value',
							sprintf(
								/* translators: 1: audience key, 2: field id */
								__( 'The audience "%1$s" must be true or false (field "%2$s").', 'wc-checkoutsuite' ),
								(string) $audience,
								$id
							),
							array( 'field' => $id )
						)
					);
				}
			}

			if ( ! $stores_value && ! empty( $visibility['public_api'] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'visibility_exposes_missing_value',
						sprintf(
							/* translators: %s: field id */
							__( 'The field "%s" stores no value, so it cannot be exposed through the Store API.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'field' => $id )
					)
				);
			}
		}

		if ( ! in_array( $hidden_policy, DefinitionVocabulary::hidden_value_policy_values(), true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_hidden_value_policy',
					sprintf(
						/* translators: 1: field id, 2: comma separated list of policies */
						__( 'What happens to a hidden value of "%1$s" must be one of: %2$s.', 'wc-checkoutsuite' ),
						$id,
						implode( ', ', DefinitionVocabulary::hidden_value_policy_values() )
					),
					array( 'field' => $id )
				)
			);
		}

		return $result;
	}

	/**
	 * Validates a mask reference.
	 *
	 * The definition stores the version it was configured against, so a mask whose
	 * definition changed is detected instead of silently altering what the field
	 * accepts. Requiring the version to match is what makes that stored version
	 * mean something.
	 *
	 * @param string               $id   Field identifier.
	 * @param array<string, mixed> $mask Mask reference.
	 * @return ValidationResult
	 */
	private function validate_mask( string $id, array $mask ): ValidationResult {
		$result = ValidationResult::valid();
		$key    = isset( $mask['key'] ) ? (string) $mask['key'] : '';

		if ( '' === $key ) {
			return ValidationResult::invalid(
				'mask_key_missing',
				sprintf(
					/* translators: %s: field id */
					__( 'The mask configured on "%s" has no key.', 'wc-checkoutsuite' ),
					$id
				),
				array( 'field' => $id )
			);
		}

		if ( null === $this->masks ) {
			return $result;
		}

		$registered = $this->masks->mask( $key );

		if ( null === $registered ) {
			return ValidationResult::invalid(
				'unknown_mask',
				sprintf(
					/* translators: 1: mask key, 2: field id */
					__( 'The mask "%1$s" configured on "%2$s" is not registered.', 'wc-checkoutsuite' ),
					$key,
					$id
				),
				array( 'field' => $id )
			);
		}

		$configured = isset( $mask['version'] ) ? (int) $mask['version'] : 0;

		if ( $configured !== $registered->version() ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'mask_version_stale',
					sprintf(
						/* translators: 1: field id, 2: configured version, 3: current version */
						__( 'The mask on "%1$s" was configured against version %2$d, but version %3$d is registered. Re-apply the mask to accept the current one.', 'wc-checkoutsuite' ),
						$id,
						$configured,
						$registered->version()
					),
					array( 'field' => $id )
				)
			);
		}

		return $result;
	}

	/**
	 * Validates the origin a definition claims.
	 *
	 * Kept separate from {@see self::validate()} because the repository applies
	 * this rule on every draft write as well as at publication. {@see CoreFieldGuard}
	 * protects a field because it is *stored* as core, so a definition that
	 * claimed a WooCommerce identifier as a custom field of its own would be a way
	 * around the guard — and being able to park that in a draft and publish it
	 * later is the same hole one step removed.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return ValidationResult
	 */
	public function validate_origin( FieldDefinition $definition ): ValidationResult {
		$id = $definition->id();

		if ( '' === $id || ! in_array( $id, $this->core_field_ids, true ) ) {
			return ValidationResult::valid();
		}

		if ( 'core' === $definition->to_array()['origin'] ) {
			return ValidationResult::valid();
		}

		return ValidationResult::invalid(
			'core_field_origin_required',
			sprintf(
				/* translators: %s: field id */
				__( 'The id "%s" belongs to a WooCommerce checkout field. A definition using it must declare the core origin.', 'wc-checkoutsuite' ),
				$id
			),
			array( 'field' => $id )
		);
	}

	/**
	 * Validates a definition.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return ValidationResult
	 */
	public function validate( FieldDefinition $definition ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $definition->id();

		if ( '' === $id ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'missing_id',
					__( 'A field definition requires an id.', 'wc-checkoutsuite' )
				)
			);
		} elseif ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_]*$/', $id ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_id',
					sprintf(
						/* translators: %s: field id */
						__( 'The field id "%s" must use lowercase letters, digits and underscores only.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'id' => $id )
				)
			);
		}

		if ( '' === $definition->label() ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'missing_label',
					__( 'A field definition requires a label.', 'wc-checkoutsuite' )
				)
			);
		}

		$result = $result->merge( $this->validate_origin( $definition ) );

		$type = $this->types->type( $definition->type() );

		if ( null === $type ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_type',
					sprintf(
						/* translators: %s: field type key */
						__( 'The field type "%s" is not registered.', 'wc-checkoutsuite' ),
						$definition->type()
					),
					array( 'type' => $definition->type() )
				)
			);
		} else {
			$result = $result->merge(
				SchemaValidator::validate( $definition->settings(), $type->settingsSchema() )
			);

			if ( ! isset( $type->supports()['value'] ) || ! $type->supports()['value'] ) {
				if ( array() !== $definition->validators() ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'validators_not_supported',
							sprintf(
								/* translators: %s: field type key */
								__( 'The field type "%s" stores no value and cannot carry validators.', 'wc-checkoutsuite' ),
								$definition->type()
							),
							array( 'type' => $definition->type() )
						)
					);
				}
			}
		}

		$preset_key = $definition->preset();

		if ( null !== $preset_key && '' !== $preset_key ) {
			$preset = $this->presets->preset( $preset_key );

			if ( null === $preset ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'unknown_preset',
						sprintf(
							/* translators: %s: preset key */
							__( 'The preset "%s" is not registered.', 'wc-checkoutsuite' ),
							$preset_key
						),
						array( 'preset' => $preset_key )
					)
				);
			} elseif ( $preset->type() !== $definition->type() ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'preset_type_mismatch',
						sprintf(
							/* translators: 1: preset key, 2: preset type, 3: field type */
							__( 'The preset "%1$s" is built on type "%2$s" but the field declares "%3$s".', 'wc-checkoutsuite' ),
							$preset_key,
							$preset->type(),
							$definition->type()
						),
						array( 'preset' => $preset_key )
					)
				);
			}
		}

		foreach ( $definition->validators() as $reference ) {
			$key = is_array( $reference ) && isset( $reference['key'] ) ? (string) $reference['key'] : '';

			if ( '' === $key || ! $this->validators->has( $key ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'unknown_validator',
						sprintf(
							/* translators: %s: validator key */
							__( 'The validator "%s" is not registered.', 'wc-checkoutsuite' ),
							'' === $key ? '(empty)' : $key
						),
						array( 'validator' => $key )
					)
				);
			}
		}

		$normalizer_key = $definition->to_array()['normalizer'];

		if ( null !== $this->normalizers && is_string( $normalizer_key ) && '' !== $normalizer_key && ! $this->normalizers->has( $normalizer_key ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_normalizer',
					sprintf(
						/* translators: %s: normalizer key */
						__( 'The normalizer "%s" is not registered.', 'wc-checkoutsuite' ),
						$normalizer_key
					),
					array( 'normalizer' => $normalizer_key )
				)
			);
		}

		if ( mb_strlen( $definition->description() ) > 500 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'description_too_long',
					__( 'The field description must be at most 500 characters long.', 'wc-checkoutsuite' ),
					array( 'field' => $id )
				)
			);
		}

		$result = $result->merge( $this->validate_surfaces( $definition, null !== $type ? $type->supports() : array() ) );

		if ( $definition->position() < 0 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'invalid_position',
					__( 'The field position cannot be negative.', 'wc-checkoutsuite' )
				)
			);
		}

		foreach ( $definition->layout() as $viewport => $width ) {
			if ( $width < 1 || $width > 12 ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_layout_width',
						sprintf(
							/* translators: 1: viewport name, 2: width */
							__( 'The %1$s width must be between 1 and 12 columns; %2$d was given.', 'wc-checkoutsuite' ),
							(string) $viewport,
							$width
						),
						array( 'viewport' => (string) $viewport )
					)
				);
			}
		}

		$result = $result->merge( $this->validate_conditions( $definition ) );

		return $result;
	}
}
