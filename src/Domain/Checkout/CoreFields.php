<?php
/**
 * WooCommerce core checkout field inventory.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;

/**
 * Reads the checkout fields WooCommerce itself owns.
 *
 * The list is read live through `woocommerce_checkout_fields` rather than being
 * hard-coded, because a hard-coded list would be wrong twice over: another
 * plugin can add or remove core fields, and WooCommerce itself changes them
 * between versions. Reading the filter is what makes the admin show what the
 * checkout actually has.
 *
 * Nothing here is stored. A core field enters the schema only when a merchant
 * changes something about it, and even then the Suite stores an override rather
 * than a copy — the value keeps living in the WooCommerce field, as ADR-0001
 * requires.
 *
 * Every read is allowed to fail. If WooCommerce is absent, or the checkout
 * object cannot be built, the inventory reports itself unavailable with a reason
 * instead of throwing or silently returning an empty list that would look like
 * "this store has no core fields".
 */
final class CoreFields {

	/**
	 * Memoised checkout fields.
	 *
	 * `get_checkout_fields()` applies a filter every time it is called, and
	 * other plugins hook that filter. Reading it once per request keeps the
	 * inventory stable and stops a third-party callback from running several
	 * times just because the admin asked a second question.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $memo = null;

	/**
	 * Whether the read was already attempted.
	 *
	 * @var bool
	 */
	private bool $read = false;

	/**
	 * Why the last read failed, when it did.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * Suite type used for each WooCommerce field type.
	 *
	 * `password` is the one real loss: WooCommerce has an account-password field
	 * and the Suite v1 ships no password type, so it maps to `text` and the
	 * inventory says the mapping happened rather than presenting the field as if
	 * it were a plain text input.
	 */
	private const TYPE_MAP = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'select'   => 'select',
		'country'  => 'country',
		'state'    => 'state',
		'email'    => 'email',
		'tel'      => 'tel',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'password' => 'text',
	);

	/**
	 * Translatable labels of the sections WooCommerce ships.
	 *
	 * WooCommerce exposes no public API for these labels — its templates
	 * hard-code them — so they are declared here and fall back to a humanised
	 * section key for anything else.
	 *
	 * @return array<string, string>
	 */
	private static function section_labels(): array {
		return array(
			'billing'  => __( 'Billing', 'wc-checkoutsuite' ),
			'shipping' => __( 'Shipping', 'wc-checkoutsuite' ),
			'account'  => __( 'Account', 'wc-checkoutsuite' ),
			'order'    => __( 'Order', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * Whether the core field list can be read at all.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return null !== $this->checkout_fields();
	}

	/**
	 * The full inventory.
	 *
	 * @return array{available: bool, reason: string, sections: array<int, array{key: string, label: string, fields: array<int, array<string, mixed>>}>, fields: array<int, array<string, mixed>>}
	 */
	public function catalogue(): array {
		$raw = $this->checkout_fields();

		if ( null === $raw ) {
			return array(
				'available' => false,
				'reason'    => $this->unavailable_reason(),
				'sections'  => array(),
				'fields'    => array(),
			);
		}

		$sections = array();
		$flat     = array();

		foreach ( $raw as $section_key => $fields ) {
			$section_key = (string) $section_key;

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$entries = array();

			foreach ( $fields as $field_id => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				if ( self::is_contributed( $field ) ) {
					// This plugin's own field, read back from the filter it hooks.
					// The inventory is "what WooCommerce owns", and a field this
					// plugin added to the checkout is not WooCommerce's — counting
					// it here is what made a published custom field refuse to be
					// saved a second time, and what would have shown it to the
					// merchant as a field of the platform.
					continue;
				}

				$entry = $this->describe( (string) $field_id, $section_key, $field );

				$entries[] = $entry;
				$flat[]    = $entry;
			}

			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return $a['priority'] <=> $b['priority'];
				}
			);

			$labels = self::section_labels();

			$sections[] = array(
				'key'    => $section_key,
				'label'  => $labels[ $section_key ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $section_key ) ),
				'fields' => $entries,
			);
		}

		/**
		 * Filters the core checkout field inventory shown in the admin.
		 *
		 * The inventory is a report of what exists, not a configuration. Use it
		 * to hide a field from the picker; removing a field here does not remove
		 * it from the checkout.
		 *
		 * @since 1.0.0
		 *
		 * @param array{available: bool, reason: string, sections: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>} $inventory Inventory.
		 */
		return apply_filters(
			'wccs_core_fields_inventory',
			array(
				'available' => true,
				'reason'    => '',
				'sections'  => $sections,
				'fields'    => $flat,
			)
		);
	}

	/**
	 * Whether a core field with this identifier exists.
	 *
	 * @param string $id Field identifier, e.g. `billing_first_name`.
	 * @return bool
	 */
	public function has( string $id ): bool {
		foreach ( $this->catalogue()['fields'] as $field ) {
			if ( ( $field['id'] ?? '' ) === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Identifiers of every core field.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_values(
			array_filter(
				array_map(
					static function ( array $field ): string {
						return (string) ( $field['id'] ?? '' );
					},
					$this->catalogue()['fields']
				)
			)
		);
	}

	/**
	 * Whether a checkout field was added by this plugin.
	 *
	 * The classic adapter marks every custom field it adds with
	 * {@see \WCCheckoutSuite\Checkout\Classic\ClassicAdapter::FIELD_ATTRIBUTE} and
	 * adds them through `woocommerce_checkout_fields` — the same filter this
	 * inventory reads. So the answer to "what does WooCommerce own?" arrives with
	 * this plugin's own fields mixed into it, and the marker is what tells the two
	 * apart. A field WooCommerce owns is never marked: the adapter deliberately
	 * marks only the fields it creates itself.
	 *
	 * @param array<string, mixed> $field Raw field arguments.
	 * @return bool
	 */
	private static function is_contributed( array $field ): bool {
		$attributes = isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] )
			? $field['custom_attributes']
			: array();

		return isset( $attributes[ ClassicAdapter::FIELD_ATTRIBUTE ] );
	}

	/**
	 * Describes one WooCommerce field.
	 *
	 * @param string               $id         Field identifier.
	 * @param string               $section    WooCommerce section key.
	 * @param array<string, mixed> $field      Raw field arguments.
	 * @return array<string, mixed>
	 */
	private function describe( string $id, string $section, array $field ): array {
		$native_type = isset( $field['type'] ) && '' !== (string) $field['type'] ? (string) $field['type'] : 'text';
		$suite_type  = self::TYPE_MAP[ $native_type ] ?? 'text';

		$classes = array();

		if ( isset( $field['class'] ) && is_array( $field['class'] ) ) {
			$classes = array_values( array_map( 'strval', $field['class'] ) );
		}

		return array(
			'id'           => $id,
			'section'      => $section,
			'label'        => isset( $field['label'] ) ? (string) $field['label'] : $id,
			'type'         => $suite_type,
			'nativeType'   => $native_type,
			'typeRemapped' => $suite_type !== $native_type,
			'required'     => isset( $field['required'] ) && (bool) $field['required'],
			'priority'     => isset( $field['priority'] ) ? (int) $field['priority'] : 0,
			'classes'      => $classes,
			'layout'       => self::layout_from_classes( $classes ),
			'protected'    => true,
		);
	}

	/**
	 * Suggested column widths from the WooCommerce row classes.
	 *
	 * WooCommerce lays a field out with `form-row-first` / `form-row-last`
	 * (half width) or `form-row-wide` (full width). Translating those into the
	 * Suite's 12-column layout means adopting a core field does not silently
	 * change how wide it is.
	 *
	 * @param array<int, string> $classes Row classes.
	 * @return array<string, int>
	 */
	private static function layout_from_classes( array $classes ): array {
		$width = 12;

		if ( in_array( 'form-row-first', $classes, true ) || in_array( 'form-row-last', $classes, true ) ) {
			$width = 6;
		}

		return array(
			'desktop' => $width,
			'tablet'  => $width,
			'mobile'  => 12,
		);
	}

	/**
	 * Reads the filtered checkout fields, or null when impossible.
	 *
	 * @return array<string, mixed>|null
	 */
	private function checkout_fields(): ?array {
		if ( $this->read ) {
			return $this->memo;
		}

		$this->read   = true;
		$this->memo   = $this->read_checkout_fields();
		$this->reason = null === $this->memo ? $this->reason : '';

		return $this->memo;
	}

	/**
	 * Performs the read, converting every failure into an absent inventory.
	 *
	 * `WC_Checkout::get_checkout_fields()` is not safe at any moment: it reads
	 * `WC()->countries`, which WooCommerce assigns during its own initialisation.
	 * Calling it earlier — during `plugins_loaded`, for instance — is a fatal
	 * error, not an exception, so the timing has to be checked rather than the
	 * call merely wrapped.
	 *
	 * The filter it applies also runs third-party callbacks, which can throw. An
	 * inventory that cannot be read is reported as unavailable with a reason; it
	 * must never take the admin screen, or the whole site, down with it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read_checkout_fields(): ?array {
		if ( ! function_exists( 'WC' ) ) {
			$this->reason = __( 'WooCommerce is not active, so its checkout fields cannot be listed.', 'wc-checkoutsuite' );

			return null;
		}

		// `function_exists( 'WC' )` is true from the moment the plugin file is
		// loaded, which is well before WooCommerce builds its countries object.
		// This action is the signal that the objects actually exist.
		if ( ! did_action( 'woocommerce_init' ) ) {
			$this->reason = __( 'WooCommerce has not finished starting, so its checkout fields cannot be listed yet.', 'wc-checkoutsuite' );

			return null;
		}

		try {
			$checkout = WC()->checkout();

			if ( ! is_object( $checkout ) || ! method_exists( $checkout, 'get_checkout_fields' ) ) {
				$this->reason = __( 'The WooCommerce checkout object could not be built, so its fields cannot be listed.', 'wc-checkoutsuite' );

				return null;
			}

			// `get_checkout_fields()` is declared to return an array, so no
			// runtime type check is repeated here: guarding against a type the
			// contract already guarantees is dead code.
			return $checkout->get_checkout_fields();
		} catch ( \Throwable $error ) {
			$this->reason = sprintf(
				/* translators: %s: error message */
				__( 'Reading the WooCommerce checkout fields failed: %s', 'wc-checkoutsuite' ),
				$error->getMessage()
			);

			return null;
		}
	}

	/**
	 * Why the inventory is unavailable.
	 *
	 * @return string
	 */
	private function unavailable_reason(): string {
		// Forces the read so the reason reflects what actually happened rather
		// than a guess made from `function_exists`.
		$this->checkout_fields();

		return '' !== $this->reason
			? $this->reason
			: __( 'The WooCommerce checkout fields are unavailable.', 'wc-checkoutsuite' );
	}
}
