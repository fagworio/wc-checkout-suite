<?php
/**
 * Vocabulary of a stored field definition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * The closed sets a field definition may refer to.
 *
 * This exists so the admin and the validator cannot disagree. Publishing a list
 * of scopes from one place and validating against a different list would produce
 * the worst kind of bug: an option the inspector offers and the server refuses,
 * or worse, a value the server accepts and nothing implements.
 *
 * The lists here are therefore the only source. The validator reads the values
 * from them and the catalogue publishes the same arrays, labels included.
 *
 * @see \ROADMAP.md sections 4 and 5
 */
final class DefinitionVocabulary {

	/**
	 * Policy applied when a hidden field still receives a value.
	 */
	public const DEFAULT_HIDDEN_VALUE_POLICY = 'discard';

	/**
	 * Storage scopes, with the label and explanation the inspector shows.
	 *
	 * ROADMAP.md section 4 is explicit that this is not a free choice: "um
	 * registro nativo que persiste automaticamente no cliente não pode apresentar
	 * no admin a opção 'somente pedido' como se fosse equivalente". The inspector
	 * honours that by treating the scope of a WooCommerce-owned field as a fact
	 * to report rather than a value to pick.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function storage_scopes(): array {
		return array(
			array(
				'value'       => 'order',
				'label'       => __( 'With the order', 'wc-checkoutsuite' ),
				'description' => __(
					'The value is written to the order and follows it through refunds and exports.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'customer',
				'label'       => __( 'On the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'The value is remembered for the customer and offered again on the next order.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'none',
				'label'       => __( 'Not stored', 'wc-checkoutsuite' ),
				'description' => __(
					'The value is used during checkout and then discarded.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Sensitivity levels, with the label the inspector shows.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function storage_sensitivities(): array {
		return array(
			array(
				'value'       => 'public',
				'label'       => __( 'Not personal', 'wc-checkoutsuite' ),
				'description' => __(
					'The value identifies nobody on its own.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'personal',
				'label'       => __( 'Personal', 'wc-checkoutsuite' ),
				'description' => __(
					'The value identifies a person and is removed from exports unless asked for.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'sensitive',
				'label'       => __( 'Sensitive', 'wc-checkoutsuite' ),
				'description' => __(
					'The value is a document or other data that needs extra care, such as a CPF or CNPJ.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Where a stored value may be shown, from ROADMAP.md section 4.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function visibility_keys(): array {
		return array(
			array(
				'value'       => 'admin_order',
				'label'       => __( 'Order screen, for staff', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown to staff when they open the order.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'customer_order',
				'label'       => __( 'Order screen, for the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown to the customer in their account.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'customer_email',
				'label'       => __( 'Emails to the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'Included in the order emails the customer receives.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'admin_email',
				'label'       => __( 'Emails to the store', 'wc-checkoutsuite' ),
				'description' => __(
					'Included in the order emails the store receives.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'public_api',
				'label'       => __( 'Store API and webhooks', 'wc-checkoutsuite' ),
				'description' => __(
					'Exposed outside the site. Off unless something else needs it.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * What happens to a value when a condition hides its field.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function hidden_value_policies(): array {
		return array(
			array(
				'value'       => 'discard',
				'label'       => __( 'Discard it', 'wc-checkoutsuite' ),
				'description' => __(
					'A value submitted while the field is hidden is refused rather than kept.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'preserve',
				'label'       => __( 'Keep what was already saved', 'wc-checkoutsuite' ),
				'description' => __(
					'An existing value survives the field becoming hidden.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Plain values of a vocabulary list.
	 *
	 * Derived from the labelled lists rather than written a second time, so a
	 * value can never be valid for the validator and missing from the inspector.
	 *
	 * @param array<int, array{value: string}> $entries Labelled entries.
	 * @return array<int, string>
	 */
	private static function values( array $entries ): array {
		return array_values( array_map( static fn( array $entry ): string => $entry['value'], $entries ) );
	}

	/**
	 * Valid storage scopes.
	 *
	 * @return array<int, string>
	 */
	public static function storage_scope_values(): array {
		return self::values( self::storage_scopes() );
	}

	/**
	 * Valid sensitivity levels.
	 *
	 * @return array<int, string>
	 */
	public static function storage_sensitivity_values(): array {
		return self::values( self::storage_sensitivities() );
	}

	/**
	 * Valid visibility keys.
	 *
	 * @return array<int, string>
	 */
	public static function visibility_key_values(): array {
		return self::values( self::visibility_keys() );
	}

	/**
	 * Valid hidden value policies.
	 *
	 * @return array<int, string>
	 */
	public static function hidden_value_policy_values(): array {
		return self::values( self::hidden_value_policies() );
	}

	/**
	 * Default storage for a field of a given type.
	 *
	 * The default has to follow the type: a heading stores nothing, so telling it
	 * to keep its value with the order would be a claim the checkout cannot
	 * honour, and the validator would refuse it.
	 *
	 * @param bool $stores_value Whether the type stores a value.
	 * @return array{scope: string, sensitivity: string}
	 */
	public static function default_storage( bool $stores_value ): array {
		return array(
			'scope'       => $stores_value ? 'order' : 'none',
			'sensitivity' => 'personal',
		);
	}

	/**
	 * Default visibility for a field of a given type.
	 *
	 * @param bool $stores_value Whether the type stores a value.
	 * @return array<string, bool>
	 */
	public static function default_visibility( bool $stores_value ): array {
		return array(
			'admin_order'    => $stores_value,
			'customer_order' => false,
			'customer_email' => false,
			'admin_email'    => false,
			'public_api'     => false,
		);
	}

	/**
	 * Everything the admin needs to draw the inspector's choices.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array(): array {
		return array(
			'storageScopes'        => self::storage_scopes(),
			'storageSensitivities' => self::storage_sensitivities(),
			'visibilityKeys'       => self::visibility_keys(),
			'hiddenValuePolicies'  => self::hidden_value_policies(),
		);
	}
}
