<?php
/**
 * Blocks adapter: the published schema as native checkout fields.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Blocks;

use WCCheckoutSuite\Domain\Conditions\NativeConditionCompiler;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;

/**
 * Translates the published document into the fields the Blocks checkout registers.
 *
 * The classic adapter answers "what does WooCommerce's field array become"; this
 * one answers a narrower and stricter question: **what can the Blocks checkout be
 * asked to render natively, and what cannot it be told**. The difference matters
 * because the additional-fields API is a closed set. In the installed WooCommerce
 * 11.1.0 the supported types are exactly `text`, `select` and `checkbox`, read
 * from a private array with no filter, and everything else is refused by
 * WooCommerce itself — with a `_doing_it_wrong` and a field that is not registered.
 *
 * So the adapter is a pure function over the document, like its classic
 * counterpart, and it reports as much as it translates. Three policies decide what
 * it will and will not claim:
 *
 * 1. **A type is used only when it is native.** `date` is named by ROADMAP.md
 *    section 8 and is not in the list; it is reported as needing the controlled
 *    components of WCCS-037 rather than registered as text and discovered by a
 *    customer. The same goes for every other type with no native counterpart.
 *
 * 2. **A location is resolved, never guessed.** Billing and shipping are one
 *    `address` location in the Blocks checkout; contact and account are `contact`;
 *    order is `order`. A field whose section cannot be resolved is reported rather
 *    than defaulted to `order`, because a field that quietly lands in the wrong
 *    place is a field that quietly collects the wrong data — the same two-sources-
 *    for-one-fact mistake WCCS-018 had to fix.
 *
 * 3. **The storage the platform performs has to be the storage that was
 *    declared.** A native additional field is persisted on the order. A definition
 *    that declares `none` asked not to be stored, and one that declares `customer`
 *    asked for somewhere the platform does not write, so neither is registered.
 *    Registering them would make the store keep something the merchant said not to
 *    keep — ADR-0001's rule that a field has exactly one authority.
 *
 * What the conditions compiler produces is used here rather than described: a
 * representable visibility rule becomes the field's `required` *and* its `hidden`,
 * which is how the native mechanism expresses "required exactly when it is shown".
 * A rule that cannot be compiled is reported and the field is registered without
 * it, because ADR-0008 says an incompatibility warns and never blocks.
 *
 * @see \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::register_checkout_field()
 * @see \ROADMAP.md sections 8 and 11
 * @see docs/adr/ADR-0001-storage-authority.md
 */
final class BlocksAdapter {

	/**
	 * The field types the additional-fields API accepts.
	 *
	 * Mirrored from `CheckoutFields::$supported_field_types` in the installed
	 * WooCommerce, which is private and unfiltered — there is no API to ask, so the
	 * only honest options are to declare the list and to prove it against the
	 * platform, which the proof does by registering each one.
	 */
	public const NATIVE_TYPES = array( 'text', 'select', 'checkbox' );

	/**
	 * Suite field types and the native type each one becomes.
	 *
	 * Deliberately partial. A type that is not a key here has no native
	 * counterpart, and pretending otherwise would be the interface claiming a
	 * capability the platform does not have — which the phase gate forbids in as
	 * many words.
	 */
	private const TYPE_MAP = array(
		'text'     => 'text',
		'select'   => 'select',
		'checkbox' => 'checkbox',
	);

	/**
	 * Types that need a controlled component, with the task that delivers it.
	 */
	private const CONTROLLED = array(
		'date'           => 'WCCS-037',
		'time'           => 'WCCS-037',
		'datetime'       => 'WCCS-037',
		'textarea'       => 'WCCS-037',
		'radio'          => 'WCCS-037',
		'multiselect'    => 'WCCS-037',
		'checkbox-group' => 'WCCS-037',
	);

	/**
	 * Section locations and the Blocks location each one occupies.
	 */
	private const LOCATION_MAP = array(
		'billing'  => 'address',
		'shipping' => 'address',
		'contact'  => 'contact',
		'account'  => 'contact',
		'order'    => 'order',
	);

	/**
	 * Whether the platform can be asked to render native fields at all.
	 *
	 * Two checks, and the second is the one that means something: the function
	 * exists as soon as WooCommerce's Blocks package is loaded, while the checkout
	 * fields service only exists once `woocommerce_blocks_loaded` has run. The
	 * registration function defers itself when that has not happened, so a caller
	 * that registers too early is not wrong — but a caller that *reports* on what it
	 * registered before the service exists would be reporting on nothing.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	/**
	 * The native type a Suite type becomes, or an empty string.
	 *
	 * @param string $type Suite field type.
	 * @return string
	 */
	public static function native_type( string $type ): string {
		return self::TYPE_MAP[ $type ] ?? '';
	}

	/**
	 * How a field type is rendered in the Blocks checkout.
	 *
	 * Three modes and no fourth, because "we will work it out later" is how a type
	 * ends up with no renderer and nobody noticing:
	 *
	 * - `native` — the platform's own additional-field API renders it;
	 * - `controlled` — this plugin renders it with its own component (WCCS-037);
	 * - `restricted` — it has no renderer, and the merchant is told why.
	 *
	 * The phase gate asks for exactly this classification over every non-file type
	 * of v1, and the integration proof walks the registry and refuses to let any
	 * registered type stay unclassified: a type nobody classified is a type nobody
	 * decided about.
	 *
	 * @param string $type Suite field type.
	 * @return string One of `native`, `controlled` or `restricted`.
	 */
	public static function mode( string $type ): string {
		if ( '' !== self::native_type( $type ) ) {
			return 'native';
		}

		if ( isset( self::CONTROLLED[ $type ] ) ) {
			return 'controlled';
		}

		// A type another plugin contributed and that declares which control renders it is
		// rendered by this plugin's own bundle — the same answer a controlled type gets,
		// because that is what it is: a component of ours drawing a control that exists.
		if ( '' !== \WCCheckoutSuite\Domain\Registries::instance()->types()->control( $type ) ) {
			return 'controlled';
		}

		return 'restricted';
	}

	/**
	 * How one definition is rendered, which is not always how its type is.
	 *
	 * The exception is the mask. A mask is part of what the merchant configured, and
	 * the native text field cannot carry one: a document typed into it would be
	 * stored unformatted and refused by the server, which is a field that looks
	 * right and cannot be completed correctly. So a masked text is rendered by this
	 * plugin even though its type is native — the one case where the type does not
	 * decide, and the reason this is a function of the definition rather than of the
	 * type.
	 *
	 * @param array<string, mixed> $definition Stored definition.
	 * @return string One of `native`, `controlled` or `restricted`.
	 */
	public static function mode_for( array $definition ): string {
		$type = isset( $definition['type'] ) ? (string) $definition['type'] : '';
		$mode = self::mode( $type );

		if ( 'native' !== $mode || 'text' !== $type ) {
			return $mode;
		}

		$mask = isset( $definition['mask'] ) && is_array( $definition['mask'] ) ? $definition['mask'] : array();

		return isset( $mask['key'] ) && '' !== (string) $mask['key'] ? 'controlled' : 'native';
	}

	/**
	 * Why a type is rendered the way it is.
	 *
	 * @param string $type Suite field type.
	 * @return string
	 */
	public static function reason( string $type ): string {
		if ( 'native' === self::mode( $type ) ) {
			return sprintf( 'Rendered by the Blocks additional-fields API as a %s field.', self::native_type( $type ) );
		}

		if ( 'controlled' === self::mode( $type ) ) {
			return sprintf( 'The Blocks checkout has no native %s field, so this plugin renders it (WCCS-037).', $type );
		}

		return sprintf( 'The Blocks checkout has no %s field and this plugin does not render one yet.', $type );
	}

	/**
	 * Every type this adapter renders with its own component.
	 *
	 * @return array<int, string>
	 */
	public static function controlled_types(): array {
		return array_keys( self::CONTROLLED );
	}

	/**
	 * The Blocks location a section location occupies, or an empty string.
	 *
	 * @param string $location Section location.
	 * @return string
	 */
	public static function native_location( string $location ): string {
		return self::LOCATION_MAP[ $location ] ?? '';
	}

	/**
	 * Whether the native storage is the storage the definition declared.
	 *
	 * @param array<string, mixed> $definition Definition.
	 * @return bool
	 */
	public static function storage_is_representable( array $definition ): bool {
		$storage = isset( $definition['storage'] ) && is_array( $definition['storage'] ) ? $definition['storage'] : array();
		$scope   = isset( $storage['scope'] ) ? (string) $storage['scope'] : '';

		return 'order' === $scope;
	}

	/**
	 * Translates the document into registrations, and reports what it could not.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @param array<int, array<string, mixed>> $sections    Published sections.
	 * @param int                              $decimals    Currency decimals, for the condition compiler.
	 * @return array{registrations: array<int, array<string, mixed>>, report: array<int, array{field: string, code: string, reason: string}>}
	 */
	public function apply( array $definitions, array $sections, int $decimals = 2 ): array {
		$locations     = $this->locations( $sections );
		$registrations = array();
		$report        = array();

		// One field identifier may be registered once: the platform refuses a second
		// registration of the same id, so the translation has to be the only place
		// that decides, and it decides by working through the document once.
		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

			if ( '' === $id ) {
				continue;
			}

			if ( isset( $raw['enabled'] ) && false === $raw['enabled'] ) {
				continue;
			}

			$built = $this->build( $raw, $locations, $decimals );

			if ( isset( $built['registration'] ) ) {
				$registrations[] = $built['registration'];
			}

			foreach ( $built['report'] as $entry ) {
				$report[] = $entry;
			}
		}

		return array(
			'registrations' => $registrations,
			'report'        => $report,
		);
	}

	/**
	 * Builds one registration, or the reasons there is none.
	 *
	 * @param array<string, mixed>  $definition Definition.
	 * @param array<string, string> $locations  Section id to location.
	 * @param int                   $decimals   Currency decimals.
	 * @return array{registration?: array<string, mixed>, report: array<int, array{field: string, code: string, reason: string}>}
	 */
	private function build( array $definition, array $locations, int $decimals ): array {
		$id   = (string) $definition['id'];
		$type = isset( $definition['type'] ) ? (string) $definition['type'] : '';
		$note = array();

		// A native type can still require a controlled component when the
		// definition carries behaviour the additional-fields API cannot express,
		// such as a mask. Keep the native and controlled paths mutually exclusive.
		if ( 'controlled' === self::mode_for( $definition ) ) {
			return array(
				'report' => array(
					$this->entry( $id, 'needs_controlled_component', self::reason( $type ) ),
				),
			);
		}

		$native_type = self::native_type( $type );

		if ( '' === $native_type ) {
			$code = 'no_native_type';

			if ( 'controlled' === self::mode( $type ) ) {
				$code = 'needs_controlled_component';
			}

			return array(
				'report' => array(
					$this->entry( $id, $code, self::reason( $type ) ),
				),
			);
		}

		if ( ! self::storage_is_representable( $definition ) ) {
			$storage = isset( $definition['storage'] ) && is_array( $definition['storage'] ) ? $definition['storage'] : array();

			return array(
				'report' => array(
					$this->entry(
						$id,
						'storage_not_native',
						sprintf(
							'A native field stores its value on the order, and this field declares the "%s" scope.',
							isset( $storage['scope'] ) ? (string) $storage['scope'] : '(none)'
						)
					),
				),
			);
		}

		$section  = isset( $definition['section'] ) ? (string) $definition['section'] : '';
		$location = '' !== $section && isset( $locations[ $section ] ) ? $locations[ $section ] : '';

		if ( '' === $location ) {
			return array(
				'report' => array(
					$this->entry(
						$id,
						'unknown_location',
						sprintf( 'The section "%s" is not in the published document, so where this field belongs is not known.', $section )
					),
				),
			);
		}

		$registration = array(
			'id'                         => $this->integration_id( $definition ),
			'label'                      => isset( $definition['label'] ) ? (string) $definition['label'] : $id,
			'location'                   => $location,
			'type'                       => $native_type,
			// WooCommerce prints an additional field it knows about on the order
			// confirmation and on the order details in the account, with its own label,
			// unless it is told not to. That display answers to nothing this plugin
			// configured: a field the merchant linked to one area — or to none — would
			// appear on both pages anyway, which is the silent insertion section 26
			// forbids and the links tab promises cannot happen. Whether a value is shown,
			// where, under which title and in which order is the merchant's decision per
			// destination, and the Suite's own projections are what carry it out.
			'show_in_order_confirmation' => false,
		);

		if ( 'select' === $native_type ) {
			$options = $this->options( $definition );

			if ( array() === $options ) {
				return array(
					'report' => array(
						$this->entry( $id, 'select_without_options', 'A select field with no options has nothing to choose from.' ),
					),
				);
			}

			$registration['options'] = $options;
		}

		$registration['required'] = ! empty( $definition['required'] );

		[ $rule, $condition_notes ] = $this->condition( $definition, $decimals );

		foreach ( $condition_notes as $reason ) {
			$note[] = $this->entry( $id, 'condition_not_native', $reason );
		}

		if ( null !== $rule ) {
			// Visibility and requiredness are independent. An optional field remains
			// optional while visible; only a required field gets the conditional
			// schema that makes it required when shown.
			if ( ! empty( $definition['required'] ) ) {
				$registration['required'] = $rule;
			}

			$registration['hidden'] = array( 'not' => $rule );
		}

		return array(
			'registration' => $registration,
			'report'       => $note,
		);
	}

	/**
	 * The visibility rule as the native keywords, when it can be compiled.
	 *
	 * @param array<string, mixed> $definition Definition.
	 * @param int                  $decimals   Currency decimals.
	 * @return array{0: array<string, mixed>|null, 1: array<int, string>}
	 */
	private function condition( array $definition, int $decimals ): array {
		$conditions = isset( $definition['conditions'] ) && is_array( $definition['conditions'] ) ? $definition['conditions'] : array();
		$visible    = isset( $conditions['visible'] ) && is_array( $conditions['visible'] ) ? $conditions['visible'] : array();

		if ( array() === $visible ) {
			return array( null, array() );
		}

		$compiled = ( new NativeConditionCompiler() )->compile( $definition, $decimals );

		if ( $compiled->is_compiled() ) {
			return array( $compiled->schema(), array() );
		}

		$reasons = array();

		foreach ( $compiled->notes() as $note ) {
			$reasons[] = sprintf(
				'The condition on "%s" cannot be expressed natively (%s), so the field is registered without it and the server keeps deciding.',
				$note['source'],
				$note['reason']
			);
		}

		return array( null, $reasons );
	}

	/**
	 * The identifier the platform knows the field by.
	 *
	 * The integration id, because a Blocks field id has to be `namespace/name` and
	 * the namespace is what keeps this store's fields apart from every other
	 * plugin's. Falling back to a prefixed identifier keeps a definition that was
	 * written without one registrable instead of silently skipped.
	 *
	 * @param array<string, mixed> $definition Definition.
	 * @return string
	 */
	private function integration_id( array $definition ): string {
		$integration = isset( $definition['integration_id'] ) ? (string) $definition['integration_id'] : '';

		if ( '' !== $integration && str_contains( $integration, '/' ) ) {
			return $integration;
		}

		return 'wc-checkoutsuite/' . (string) $definition['id'];
	}

	/**
	 * The options a select field offers.
	 *
	 * Only the two keys the platform reads: a value the store keys a choice by and
	 * the label the customer reads.
	 *
	 * @param array<string, mixed> $definition Definition.
	 * @return array<int, array{value: string, label: string}>
	 */
	private function options( array $definition ): array {
		$settings = isset( $definition['settings'] ) && is_array( $definition['settings'] ) ? $definition['settings'] : array();
		$declared = isset( $settings['options'] ) && is_array( $settings['options'] ) ? $settings['options'] : array();
		$options  = array();

		foreach ( $declared as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
				continue;
			}

			$options[] = array(
				'value' => (string) $option['value'],
				'label' => isset( $option['label'] ) ? (string) $option['label'] : (string) $option['value'],
			);
		}

		return $options;
	}

	/**
	 * Where each published section puts its fields.
	 *
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return array<string, string>
	 */
	private function locations( array $sections ): array {
		$locations = array();

		foreach ( $sections as $raw ) {
			if ( ! is_array( $raw ) || ! isset( $raw['id'] ) ) {
				continue;
			}

			$location = isset( $raw['location'] ) ? (string) $raw['location'] : '';

			if ( '' !== $location && '' !== self::native_location( $location ) ) {
				$locations[ (string) $raw['id'] ] = self::native_location( $location );
			}
		}

		return $locations;
	}

	/**
	 * One report entry.
	 *
	 * @param string $field  Field identifier.
	 * @param string $code   Stable code.
	 * @param string $reason What happened, in words.
	 * @return array{field: string, code: string, reason: string}
	 */
	private function entry( string $field, string $code, string $reason ): array {
		return array(
			'field'  => $field,
			'code'   => $code,
			'reason' => $reason,
		);
	}

	/**
	 * The storage scopes this adapter can register.
	 *
	 * Published so the admin can mark a scope as native or not before a merchant
	 * writes a field into it, rather than discovering it at registration time.
	 *
	 * @return array<int, string>
	 */
	public static function native_storage_scopes(): array {
		return array_values(
			array_filter(
				DefinitionVocabulary::storage_scope_values(),
				static function ( string $scope ): bool {
					return 'order' === $scope;
				}
			)
		);
	}
}
