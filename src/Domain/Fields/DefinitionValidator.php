<?php
/**
 * Definition validation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

use WCCheckoutSuite\Domain\Approval\ApprovalFlow;
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
		$stores_file   = isset( $supports['file'] ) && true === $supports['file'];

		// One snapshot rather than five accessor calls: the definition exposes no
		// accessor for these, and asking it repeatedly would build the same array
		// again for every question.
		$raw                = $definition->to_array();
		$mask               = is_array( $raw['mask'] ) ? $raw['mask'] : null;
		$storage            = $raw['storage'];
		$destinations       = is_array( $raw['destinations'] ) ? $raw['destinations'] : array();
		$approval           = is_array( $raw['approval'] ) ? $raw['approval'] : null;
		$hidden_policy      = (string) $raw['hidden_value_policy'];
		$collection_surface = isset( $raw['collection_surface'] ) ? (string) $raw['collection_surface'] : 'checkout';

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

		// Which ways a customer's value flows (§10.4). Both directions are decisions about
		// collection, so a field that is not collected at the checkout has no flow to decide:
		// there is no checkout form for the value to arrive from or start in.
		$sync = $definition->sync();

		if ( ( $sync['to_checkout'] || $sync['from_checkout'] ) && 'checkout' !== $collection_surface ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'sync_requires_checkout_collection',
					sprintf(
						/* translators: %s: field id */
						__( 'The flows of "%s" are decisions about the checkout, and this field is collected on the customer\'s own page.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		if ( $sync['to_checkout'] && ! $stores_value ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'sync_requires_value',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" stores no value, so there is nothing to prefill the checkout with.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		// A direction the runtime does not perform is refused rather than accepted and ignored.
		// Accepting it would store a promise — a document that says the value is written back —
		// that nothing carries out, which is worse than not offering it: the merchant would have
		// no way to tell the difference between a flow nobody used and a flow that never ran.
		if ( $sync['from_checkout'] ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'sync_direction_not_available',
					sprintf(
						/* translators: %s: field id */
						__( 'The flow checkout → perfil is not carried out yet, so the field "%s" is refused instead of storing a promise nothing keeps.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

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

		if ( ! in_array( $collection_surface, array( 'checkout', 'my_account' ), true ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'unknown_collection_surface',
					sprintf(
						/* translators: %s: field id */
						__( 'The collection surface of "%s" must be checkout or My Account.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		} elseif ( 'my_account' === $collection_surface && 'customer' !== $scope ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'account_collection_requires_customer_storage',
					sprintf(
						/* translators: %s: field id */
						__( 'The My Account field "%s" must store its value on the customer.', 'wc-checkoutsuite' ),
						$id
					),
					array( 'field' => $id )
				)
			);
		}

		// Destinations. Each one decides its own section, title, order and actions, so
		// each one is checked on its own: an unknown destination, an action it may not
		// be allowed to perform, or a malformed entry is refused rather than dropped.
		$result = $result->merge(
			$this->validate_destinations( $id, $destinations, $stores_value, $stores_file, $definition->is_canonical() )
		);

		// And then the uses, which are the authority the map above is derived from. A
		// document written before the split has no list of its own: its map *is* the list,
		// and it was just validated as one.
		if ( $definition->is_canonical() ) {
			$result = $result->merge( $this->validate_bindings( $definition, $stores_file ) );
		}

		// A link into a customer surface that says how the field behaves there must say
		// something that surface can carry out. Whether the *value* may live with the
		// customer is a question about the section the link points at, and it is asked
		// where both parts are known: {@see SectionValidator::validate_references()}.
		// Asking it here would refuse a link whose section the document has not been
		// consulted for, which is how a configuration that is fine in the document
		// becomes invalid in isolation.
		foreach ( self::customer_surfaces() as $surface ) {
			$surface_link = $destinations[ $surface ] ?? null;

			if ( ! is_array( $surface_link ) || empty( $surface_link['enabled'] ) || ! isset( $surface_link['mode'] ) ) {
				continue;
			}

			if ( ! in_array( (string) $surface_link['mode'], array( 'edit', 'view' ), true ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_account_field_mode',
						__( 'A field on a customer surface must be editable or read-only.', 'wc-checkoutsuite' ),
						array(
							'field'       => $id,
							'destination' => $surface,
						)
					)
				);
			}
		}

		// The approval flow is separate from the destinations on purpose: a
		// destination never enables it, and enabling it without the area it needs is
		// configuration the plugin reports instead of completing in silence.
		$result = $result->merge( $this->validate_approval( $id, $approval ) );

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
	 * The destinations whose values belong to the customer rather than to an order.
	 *
	 * They share the two rules that only make sense together: the field has to store on
	 * the customer, because neither surface has an order to write to, and the link may
	 * say the customer edits the value or only reads it.
	 *
	 * @return array<int, string>
	 */
	public static function customer_surfaces(): array {
		return array( 'customer_account', 'admin_customer_profile' );
	}

	/**
	 * Validates the destinations of a field.
	 *
	 * The actions are the file's: showing a name, opening it, taking a copy, approving
	 * it and sending a new version are decisions about a document, so a type that
	 * stores no file has none to declare. Refusing them keeps the tab honest — the
	 * interface offers what the type supports, and the store accepts what it offers.
	 *
	 * @param string               $id            Field identifier.
	 * @param array<string, mixed> $destinations  Destination map.
	 * @param bool                 $stores_value  Whether the type stores a value.
	 * @param bool                 $stores_file   Whether the type stores a file.
	 * @param bool                 $per_use       Whether the uses are validated on their own.
	 * @return ValidationResult
	 */
	private function validate_destinations( string $id, array $destinations, bool $stores_value, bool $stores_file, bool $per_use = false ): ValidationResult {
		$result = ValidationResult::valid();

		foreach ( $destinations as $key => $entry ) {
			$key = (string) $key;

			$replacements = DefinitionVocabulary::replacements_for_ambiguous_destination( $key );

			if ( array() !== $replacements ) {
				// Two surfaces were one, and only the merchant can say which one a
				// stored link meant. Refusing it by name is what lets the editor ask;
				// choosing for them would either publish the customer's own page to
				// staff or hide it from them without anybody deciding.
				$result = $result->merge(
					ValidationResult::invalid(
						'ambiguous_destination',
						sprintf(
							/* translators: 1: destination key, 2: field id, 3: comma separated destination keys */
							__( 'The destination "%1$s" was split into two and the field "%2$s" has to say which one it means: %3$s.', 'wc-checkoutsuite' ),
							$key,
							$id,
							implode( ', ', $replacements )
						),
						array(
							'field'        => $id,
							'destination'  => $key,
							'replacements' => $replacements,
						)
					)
				);

				continue;
			}

			if ( ! in_array( $key, DefinitionVocabulary::destination_values(), true ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'unknown_destination',
						sprintf(
							/* translators: 1: destination key, 2: field id */
							__( 'The destination "%1$s" is not one a field can be shown in (field "%2$s").', 'wc-checkoutsuite' ),
							$key,
							$id
						),
						array( 'field' => $id )
					)
				);

				continue;
			}

			if ( ! is_array( $entry ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_destination',
						sprintf(
							/* translators: 1: destination key, 2: field id */
							__( 'The destination "%1$s" must be a map (field "%2$s").', 'wc-checkoutsuite' ),
							$key,
							$id
						),
						array( 'field' => $id )
					)
				);

				continue;
			}

			if ( isset( $entry['enabled'] ) && ! is_bool( $entry['enabled'] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_destination',
						sprintf(
							/* translators: 1: destination key, 2: field id */
							__( 'Whether "%1$s" shows the field must be true or false (field "%2$s").', 'wc-checkoutsuite' ),
							$key,
							$id
						),
						array( 'field' => $id )
					)
				);
			}

			// The actions belong to the use, not to the destination: a field used twice in
			// one destination may be allowed to approve in the first and only look in the
			// second, and the map keeps one of them. When the uses are validated on their
			// own, this projection is not asked a question it cannot answer — the same
			// configuration is refused with the same code, use by use, by
			// {@see self::validate_bindings()}. What the map still decides on its own is an
			// entry no use justifies: a link that is off decides nothing, actions included.
			if ( isset( $entry['actions'] ) && ! $per_use ) {
				$allowed = DefinitionVocabulary::actions_for_destination( $key );
				$actions = is_array( $entry['actions'] ) ? $entry['actions'] : array( $entry['actions'] );

				if ( ! $stores_file && array() !== $actions ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'actions_not_supported',
							sprintf(
								/* translators: 1: destination key, 2: field id */
								__( 'The destination "%1$s" cannot be given file actions, because the field "%2$s" stores no file.', 'wc-checkoutsuite' ),
								$key,
								$id
							),
							array( 'field' => $id )
						)
					);
				}

				foreach ( $actions as $action ) {
					if ( ! in_array( (string) $action, $allowed, true ) ) {
						$result = $result->merge(
							ValidationResult::invalid(
								'invalid_destination_action',
								sprintf(
									/* translators: 1: action key, 2: destination key, 3: field id */
									__( 'The action "%1$s" is not one "%2$s" can perform (field "%3$s").', 'wc-checkoutsuite' ),
									(string) $action,
									$key,
									$id
								),
								array( 'field' => $id )
							)
						);
					}
				}
			}

			// A field that stores nothing cannot be shown outside the site: there is
			// no value to expose, and saying it is exposed would be a promise the
			// store cannot keep.
			if ( ! $stores_value && 'public_api' === $key && ! empty( $entry['enabled'] ) ) {
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

		return $result;
	}

	/**
	 * Validates the uses of a field, one by one.
	 *
	 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.3 makes one
	 * definition usable several times, and each use carries what belongs to it: which
	 * destination it appears in, whether it is visible or writable there, in which container,
	 * in which order, with which permissions. The destination map cannot answer for it — a map
	 * holds one entry per destination — so a second use in the same destination was a place
	 * where configuration the merchant believes is in place could say nothing at all.
	 *
	 * Questions about the destination *key* are answered where the map is validated, because
	 * the map is the projection that reaches every surface and a key the closed list does not
	 * know has to be refused by the same code it was refused by before. What is checked here
	 * is what only the use knows: that it names the field it is listed under, that it has an
	 * identifier of its own, what it may do with the value, and where it orders itself.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @param bool            $stores_file Whether the type stores a file.
	 * @return ValidationResult
	 */
	private function validate_bindings( FieldDefinition $definition, bool $stores_file ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $definition->id();

		foreach ( $definition->raw_bindings() as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_binding',
						sprintf(
							/* translators: 1: field id, 2: index of the use */
							__( 'The use %2$d of the field "%1$s" must be a map.', 'wc-checkoutsuite' ),
							'' === $id ? '(unnamed)' : $id,
							(int) $index
						),
						array(
							'field' => $id,
							'index' => (int) $index,
						)
					)
				);
			}
		}

		$seen = array();

		foreach ( $definition->bindings() as $binding ) {
			$destination = $binding->destination();
			$field_id    = $binding->field_id();
			$identifier  = $binding->id();

			// A use sits inside one field's list, so saying which field it belongs to is
			// either redundant or a second answer to the same question. The empty string is
			// accepted as "the field this list belongs to"; anything else has to agree.
			if ( '' !== $field_id && $field_id !== $id ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'binding_field_mismatch',
						sprintf(
							/* translators: 1: use identifier, 2: field id of the use, 3: field id it is listed under */
							__( 'The use "%1$s" says it belongs to the field "%2$s", but it is listed under "%3$s".', 'wc-checkoutsuite' ),
							$identifier,
							$field_id,
							$id
						),
						array(
							'field'    => $id,
							'binding'  => $identifier,
							'declared' => $field_id,
						)
					)
				);
			}

			if ( isset( $seen[ $identifier ] ) ) {
				// A surface asks by identifier: two uses under one name would make the
				// answer depend on which one was read first.
				$result = $result->merge(
					ValidationResult::invalid(
						'duplicate_binding_id',
						sprintf(
							/* translators: 1: use identifier, 2: field id */
							__( 'The field "%2$s" uses the identifier "%1$s" more than once, so two different uses would answer to the same name.', 'wc-checkoutsuite' ),
							$identifier,
							$id
						),
						array(
							'field'   => $id,
							'binding' => $identifier,
						)
					)
				);
			}

			$seen[ $identifier ] = true;

			$permissions = $binding->permissions();

			if ( ! $stores_file && array() !== $permissions ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'actions_not_supported',
						sprintf(
							/* translators: 1: destination key, 2: field id */
							__( 'The destination "%1$s" cannot be given file actions, because the field "%2$s" stores no file.', 'wc-checkoutsuite' ),
							$destination,
							$id
						),
						array(
							'field'       => $id,
							'destination' => $destination,
						)
					)
				);
			}

			$performable = DefinitionVocabulary::actions_for_destination( $destination );

			foreach ( $permissions as $action ) {
				if ( ! in_array( (string) $action, $performable, true ) ) {
					$result = $result->merge(
						ValidationResult::invalid(
							'invalid_destination_action',
							sprintf(
								/* translators: 1: action key, 2: destination key, 3: field id */
								__( 'The action "%1$s" is not one "%2$s" can perform (field "%3$s").', 'wc-checkoutsuite' ),
								(string) $action,
								$destination,
								$id
							),
							array(
								'field'       => $id,
								'destination' => $destination,
								'binding'     => $identifier,
							)
						)
					);
				}
			}

			if ( $binding->has_position() && $binding->position() < 0 ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_binding_position',
						sprintf(
							/* translators: 1: use identifier, 2: field id */
							__( 'The order of the use "%1$s" of the field "%2$s" cannot be negative.', 'wc-checkoutsuite' ),
							$identifier,
							$id
						),
						array(
							'field'   => $id,
							'binding' => $identifier,
						)
					)
				);
			}
		}

		return $result;
	}

	/**
	 * Validates the optional approval flow.
	 *
	 * Approving means holding an order back, so the flow is only complete when it says
	 * where the review happens, in which section, and which state the order waits in.
	 * An incomplete flow is refused: the plugin points at what is missing instead of
	 * creating links, permissions or a state in silence.
	 *
	 * The rule itself lives in {@see ApprovalFlow}, because the runtime asks the same
	 * question before it holds anything — one rule, asked at the two moments it matters.
	 *
	 * @param string                    $id       Field identifier.
	 * @param array<string, mixed>|null $approval Approval configuration, or null.
	 * @return ValidationResult
	 */
	private function validate_approval( string $id, ?array $approval ): ValidationResult {
		$flow = new ApprovalFlow( $approval, $id );

		if ( ! $flow->enabled() ) {
			return ValidationResult::valid();
		}

		$missing = $flow->missing();

		if ( array() === $missing ) {
			return ValidationResult::valid();
		}

		return ValidationResult::invalid(
			'approval_incomplete',
			sprintf(
				/* translators: 1: field id, 2: comma separated list of missing keys */
				__( 'The approval flow of "%1$s" needs: %2$s.', 'wc-checkoutsuite' ),
				$id,
				implode( ', ', $missing )
			),
			array(
				'field'   => $id,
				'missing' => $missing,
			)
		);
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
	public function validate_links( FieldDefinition $definition ): ValidationResult {
		$raw      = $definition->to_array();
		$type     = $this->types->get( $definition->type() );
		$supports = null !== $type ? $type->supports() : array();
		$stores   = isset( $supports['value'] ) && true === $supports['value'];
		$files    = isset( $supports['file'] ) && true === $supports['file'];

		$result = $this->validate_destinations(
			$definition->id(),
			is_array( $raw['destinations'] ) ? $raw['destinations'] : array(),
			$stores,
			$files,
			$definition->is_canonical()
		);

		if ( $definition->is_canonical() ) {
			$result = $result->merge( $this->validate_bindings( $definition, $files ) );
		}

		return $result->merge(
			$this->validate_approval(
				$definition->id(),
				is_array( $raw['approval'] ) ? $raw['approval'] : null
			)
		);
	}

	/**
	 * Validates the origin rule of a definition.
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
