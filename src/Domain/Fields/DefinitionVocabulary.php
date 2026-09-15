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
	 * Where a stored answer may be shown, from ROADMAP.md section 4.
	 *
	 * This replaces the flat audience map the definition used to carry: a destination
	 * decides its own section, title, order and actions, and starts disabled.
	 *
	 * @return array<int, array{value: string, label: string, description: string, actions: array<int, string>}>
	 */
	public static function destinations(): array {
		return array(
			array(
				'value'       => 'admin_order',
				'label'       => __( 'Order screen, for staff', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown to staff when they open the order.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view', 'download', 'approve', 'resubmit' ),
			),
			array(
				'value'       => 'customer_order',
				'label'       => __( 'Order screen, for the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown to the customer who placed the order.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view', 'download', 'resubmit' ),
			),
			array(
				'value'       => 'order_received',
				'label'       => __( 'Order received page', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown on the page the customer sees right after paying.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view', 'download' ),
			),
			array(
				'value'       => 'customer_email',
				'label'       => __( 'Emails to the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'Included in the order emails the customer receives.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view', 'download' ),
			),
			array(
				'value'       => 'admin_email',
				'label'       => __( 'Emails to the store', 'wc-checkoutsuite' ),
				'description' => __(
					'Included in the order emails the store receives.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view', 'download' ),
			),
			array(
				'value'       => 'customer_account',
				'label'       => __( 'My Account page, for the customer', 'wc-checkoutsuite' ),
				'description' => __(
					'The customer\'s own page inside My Account, outside any order. A section offered here and given an account presentation becomes its own authenticated page that the customer fills in.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view' ),
			),
			array(
				'value'       => 'admin_customer',
				'label'       => __( 'Customer profile, for staff', 'wc-checkoutsuite' ),
				'description' => __(
					'Shown to staff on the customer\'s own profile screen, outside any order. The values belong to the customer, not to an order.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view' ),
			),
			array(
				'value'       => 'public_api',
				'label'       => __( 'Store API and webhooks', 'wc-checkoutsuite' ),
				'description' => __(
					'Exposed outside the site. Off unless something else needs it.',
					'wc-checkoutsuite'
				),
				'actions'     => array( 'show_metadata', 'view' ),
			),
		);
	}

	/**
	 * The areas a section may be offered in, from ROADMAP.md section 4.
	 *
	 * Checkout is where a section is filled, while the destinations — including the
	 * standalone My Account page — are where values may be shown afterwards. The
	 * public API is not one of them: it is a projection of values, not a place a
	 * panel is inserted, and offering a section there would promise an interface
	 * that does not exist.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function section_areas(): array {
		$areas = array(
			array(
				'value'       => 'checkout',
				'label'       => __( 'Checkout', 'wc-checkoutsuite' ),
				'description' => __(
					'Where the customer fills the fields in.',
					'wc-checkoutsuite'
				),
			),
		);

		foreach ( self::destinations() as $destination ) {
			if ( 'public_api' === $destination['value'] ) {
				continue;
			}

			$areas[] = array(
				'value'       => $destination['value'],
				'label'       => $destination['label'],
				'description' => $destination['description'],
			);
		}

		return $areas;
	}

	/**
	 * Valid section area keys.
	 *
	 * @return array<int, string>
	 */
	public static function section_area_values(): array {
		return self::values( self::section_areas() );
	}

	/**
	 * Valid destination keys.
	 *
	 * @return array<int, string>
	 */
	public static function destination_values(): array {
		return self::values( self::destinations() );
	}

	/**
	 * What a destination may be allowed to do with the answer.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function destination_actions(): array {
		return array(
			array(
				'value'       => 'show_metadata',
				'label'       => __( 'Show the file name and details', 'wc-checkoutsuite' ),
				'description' => __( 'Name, size and when it was sent.', 'wc-checkoutsuite' ),
			),
			array(
				'value'       => 'view',
				'label'       => __( 'Open it', 'wc-checkoutsuite' ),
				'description' => __( 'Read the file without taking a copy.', 'wc-checkoutsuite' ),
			),
			array(
				'value'       => 'download',
				'label'       => __( 'Download it', 'wc-checkoutsuite' ),
				'description' => __( 'Take a copy of the file.', 'wc-checkoutsuite' ),
			),
			array(
				'value'       => 'approve',
				'label'       => __( 'Review and approve', 'wc-checkoutsuite' ),
				'description' => __(
					'Only for staff: decide whether the document is accepted.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'resubmit',
				'label'       => __( 'Send a new version', 'wc-checkoutsuite' ),
				'description' => __(
					'Replace the file that was sent before.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Valid action keys.
	 *
	 * @return array<int, string>
	 */
	public static function destination_action_values(): array {
		return self::values( self::destination_actions() );
	}

	/**
	 * The actions one destination may be allowed to perform.
	 *
	 * @param string $destination Destination key.
	 * @return array<int, string> Allowed actions, or an empty array for an unknown key.
	 */
	public static function actions_for_destination( string $destination ): array {
		foreach ( self::destinations() as $entry ) {
			if ( $entry['value'] === $destination ) {
				return $entry['actions'];
			}
		}

		return array();
	}

	/**
	 * The retired destination whose stored links are ambiguous.
	 *
	 * `customer_profile` meant two different surfaces at once — the customer's own
	 * page in My Account, and the panel staff read on the customer's profile — and a
	 * stored link cannot say which one the merchant meant. `my_account` is the other
	 * key an intermediate version wrote for the first of them.
	 *
	 * A stored configuration is not reinterpreted on the store's behalf: a link with
	 * either key is refused with its own code, and the editor asks which surface was
	 * meant before it rewrites anything.
	 *
	 * @var array<int, string>
	 */
	public const AMBIGUOUS_DESTINATIONS = array( 'customer_profile', 'my_account' );

	/**
	 * The destinations that replace one ambiguous destination.
	 *
	 * @param string $destination Destination key found in a stored definition.
	 * @return array<int, string> Replacements, or an empty array when the key is not ambiguous.
	 */
	public static function replacements_for_ambiguous_destination( string $destination ): array {
		return in_array( $destination, self::AMBIGUOUS_DESTINATIONS, true )
			? array( 'customer_account', 'admin_customer' )
			: array();
	}

	/**
	 * Every destination, disabled.
	 *
	 * Destinations start disabled on purpose: publishing a field to the checkout does
	 * not put it on the order screen, in an e-mail or anywhere else. Without explicit
	 * configuration there is no additional output.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function default_destinations(): array {
		$destinations = array();

		foreach ( self::destination_values() as $key ) {
			$destinations[ $key ] = array( 'enabled' => false );
		}

		return $destinations;
	}

	/**
	 * Migrates the flat audience map a stored document may still carry.
	 *
	 * An audience that was on becomes an enabled destination with no section, title,
	 * order or action of its own; an audience that was off stays disabled. Only the
	 * legacy map is migrated — a definition carrying neither map has no destination
	 * enabled, which is the rule the roadmap states.
	 *
	 * @param array<string, mixed> $visibility Legacy map.
	 * @return array<string, array<string, mixed>>
	 */
	public static function destinations_from_visibility( array $visibility ): array {
		$destinations = self::default_destinations();

		foreach ( $visibility as $key => $allowed ) {
			// What the legacy map held is carried through as it is, not interpreted:
			// an audience the closed list does not know, and a value that is not
			// true or false, both reach the validator, which refuses them. A
			// migration that coerced them would turn a malformed stored document
			// into a valid one and hide the defect that produced it.
			$destinations[ (string) $key ] = array( 'enabled' => $allowed );
		}

		return $destinations;
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
	 * Everything the admin needs to draw the inspector's choices.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array(): array {
		return array(
			'storageScopes'        => self::storage_scopes(),
			'storageSensitivities' => self::storage_sensitivities(),
			'destinations'         => self::destinations(),
			'destinationActions'   => self::destination_actions(),
			'sectionAreas'         => self::section_areas(),
			'hiddenValuePolicies'  => self::hidden_value_policies(),
		);
	}
}
