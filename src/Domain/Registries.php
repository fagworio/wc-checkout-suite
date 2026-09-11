<?php
/**
 * Domain registry container.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain;

use WCCheckoutSuite\Domain\Checkout\CoreFields;
use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorRegistry;
use WCCheckoutSuite\Domain\Conditions\PermissiveConditionEvaluator;
use WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\BrazilianPresets;
use WCCheckoutSuite\Domain\Fields\CoreTypes;
use WCCheckoutSuite\Domain\Fields\DefinitionValidator;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\PresetRegistry;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;
use WCCheckoutSuite\Domain\Validation\BrazilianDocuments;
use WCCheckoutSuite\Domain\Validation\CoreProcessing;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;
use WCCheckoutSuite\Domain\Validation\RendererRegistry;
use WCCheckoutSuite\Domain\Validation\ValueProcessor;

/**
 * Owns the domain registries and lets extensions contribute to them.
 *
 * The core registers its own types through the same public API an external
 * plugin uses. There is no privileged path and no closed factory.
 *
 * @see \ROADMAP.md section 6
 */
final class Registries {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Field type registry.
	 *
	 * @var FieldTypeRegistry
	 */
	private FieldTypeRegistry $types;

	/**
	 * Preset registry.
	 *
	 * @var PresetRegistry
	 */
	private PresetRegistry $presets;

	/**
	 * Validator registry.
	 *
	 * @var ValidatorRegistry
	 */
	private ValidatorRegistry $validators;

	/**
	 * Normalizer registry.
	 *
	 * @var NormalizerRegistry
	 */
	private NormalizerRegistry $normalizers;

	/**
	 * Mask registry.
	 *
	 * @var MaskRegistry
	 */
	private MaskRegistry $masks;

	/**
	 * Renderer registry.
	 *
	 * @var RendererRegistry
	 */
	private RendererRegistry $renderers;

	/**
	 * Condition evaluator registry.
	 *
	 * @var ConditionEvaluatorRegistry
	 */
	private ConditionEvaluatorRegistry $conditions;

	/**
	 * Whether the registration hooks already ran.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Memoised identifiers of the WooCommerce core checkout fields.
	 *
	 * Reading the live list applies the `woocommerce_checkout_fields` filter,
	 * which other plugins hook, so it is read at most once per request.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $core_field_ids = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->types       = new FieldTypeRegistry();
		$this->presets     = new PresetRegistry();
		$this->validators  = new ValidatorRegistry();
		$this->normalizers = new NormalizerRegistry();
		$this->masks       = new MaskRegistry();
		$this->renderers   = new RendererRegistry();
		$this->conditions  = new ConditionEvaluatorRegistry();
	}

	/**
	 * Boots the registries once, firing the extension hooks.
	 *
	 * @return self
	 */
	public static function boot(): self {
		$instance = self::instance();

		if ( $instance->registered ) {
			return $instance;
		}

		CoreTypes::register_types( $instance->types );
		BrazilianPresets::register_presets( $instance->presets );
		CoreProcessing::register_normalizers( $instance->normalizers );
		CoreProcessing::register_masks( $instance->masks );
		BrazilianDocuments::register_normalizers( $instance->normalizers );
		BrazilianDocuments::register_masks( $instance->masks );
		BrazilianDocuments::register_validators( $instance->validators );
		$instance->conditions->register_evaluator( new PermissiveConditionEvaluator() );
		$instance->conditions->register_evaluator( new TreeConditionEvaluator() );

		$instance->registered = true;

		/**
		 * Registers field types.
		 *
		 * @since 0.1.0
		 *
		 * @param FieldTypeRegistry $types Field type registry.
		 */
		do_action( 'wccs_register_field_types', $instance->types );

		/**
		 * Registers field presets.
		 *
		 * @since 0.1.0
		 *
		 * @param PresetRegistry $presets Preset registry.
		 */
		do_action( 'wccs_register_presets', $instance->presets );

		/**
		 * Registers named validators.
		 *
		 * @since 0.1.0
		 *
		 * @param ValidatorRegistry $validators Validator registry.
		 */
		do_action( 'wccs_register_validators', $instance->validators );

		/**
		 * Registers named normalizers.
		 *
		 * This hook extends the list published in ROADMAP.md section 6, which
		 * names types, presets, validators, masks and renderers. A normalizer is
		 * a first-class key of a field definition (section 4), so it needs the
		 * same registration path as a validator.
		 *
		 * @since 0.1.0
		 *
		 * @param NormalizerRegistry $normalizers Normalizer registry.
		 */
		do_action( 'wccs_register_normalizers', $instance->normalizers );

		/**
		 * Registers declarative masks.
		 *
		 * @since 0.1.0
		 *
		 * @param MaskRegistry $masks Mask registry.
		 */
		do_action( 'wccs_register_masks', $instance->masks );

		/**
		 * Registers renderers per adapter.
		 *
		 * @since 0.1.0
		 *
		 * @param RendererRegistry $renderers Renderer registry.
		 */
		do_action( 'wccs_register_renderers', $instance->renderers );

		/**
		 * Registers condition evaluators.
		 *
		 * Extends the list in ROADMAP.md section 6 in the same way as the
		 * normalizer hook: the pipeline needs a versioned contract to ask, and
		 * F06 replaces the default implementation behind it.
		 *
		 * @since 0.1.0
		 *
		 * @param ConditionEvaluatorRegistry $conditions Condition evaluator registry.
		 */
		do_action( 'wccs_register_condition_evaluators', $instance->conditions );

		/**
		 * Fires after every registry has been populated.
		 *
		 * @since 0.1.0
		 *
		 * @param Registries $registries Registry container.
		 */
		do_action( 'wccs_registries_ready', $instance );

		return $instance;
	}

	/**
	 * Returns the shared instance, creating it without firing hooks.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Field type registry.
	 *
	 * @return FieldTypeRegistry
	 */
	public function types(): FieldTypeRegistry {
		return $this->types;
	}

	/**
	 * Preset registry.
	 *
	 * @return PresetRegistry
	 */
	public function presets(): PresetRegistry {
		return $this->presets;
	}

	/**
	 * Validator registry.
	 *
	 * @return ValidatorRegistry
	 */
	public function validators(): ValidatorRegistry {
		return $this->validators;
	}

	/**
	 * Normalizer registry.
	 *
	 * @return NormalizerRegistry
	 */
	public function normalizers(): NormalizerRegistry {
		return $this->normalizers;
	}

	/**
	 * Mask registry.
	 *
	 * @return MaskRegistry
	 */
	public function masks(): MaskRegistry {
		return $this->masks;
	}

	/**
	 * Renderer registry.
	 *
	 * @return RendererRegistry
	 */
	public function renderers(): RendererRegistry {
		return $this->renderers;
	}

	/**
	 * Condition evaluator registry.
	 *
	 * @return ConditionEvaluatorRegistry
	 */
	public function conditions(): ConditionEvaluatorRegistry {
		return $this->conditions;
	}

	/**
	 * Identifiers of the checkout fields WooCommerce owns.
	 *
	 * Empty when WooCommerce is not active, which is the honest answer: with no
	 * WooCommerce there are no core fields to protect.
	 *
	 * @return array<int, string>
	 */
	public function core_field_ids(): array {
		if ( null !== $this->core_field_ids ) {
			return $this->core_field_ids;
		}

		$core = new CoreFields();

		if ( ! $core->available() ) {
			// Deliberately not cached. An empty list here would silently switch
			// off the rule that a WooCommerce id cannot be re-declared as a
			// custom field, and the read failing early in the request is not
			// evidence that the store has no core fields.
			return array();
		}

		$this->core_field_ids = $core->ids();

		return $this->core_field_ids;
	}

	/**
	 * Validator for field definitions.
	 *
	 * @return DefinitionValidator
	 */
	public function definition_validator(): DefinitionValidator {
		return new DefinitionValidator(
			$this->types,
			$this->presets,
			$this->validators,
			$this->normalizers,
			$this->core_field_ids(),
			$this->masks
		);
	}

	/**
	 * The adapter-agnostic value pipeline.
	 *
	 * @return ValueProcessor
	 */
	public function value_processor(): ValueProcessor {
		return new ValueProcessor( $this->types, $this->validators, $this->normalizers, $this->conditions );
	}

	/**
	 * Problems recorded while registering.
	 *
	 * A duplicate key, an incompatible contract version or a preset pointing at
	 * a missing type must be visible in the admin instead of silently producing
	 * a broken configuration.
	 *
	 * @return array<int, array{code: string, key: string, message: string}>
	 */
	public function diagnostics(): array {
		$diagnostics = array_merge(
			$this->types->diagnostics(),
			$this->presets->diagnostics(),
			$this->validators->diagnostics(),
			$this->normalizers->diagnostics(),
			$this->masks->diagnostics(),
			$this->renderers->diagnostics(),
			$this->conditions->diagnostics()
		);

		foreach ( $this->presets->orphaned( $this->types ) as $preset_key ) {
			$diagnostics[] = array(
				'code'    => 'preset_without_type',
				'key'     => $preset_key,
				'message' => sprintf(
					'The preset "%s" is built on a field type that is not registered and will be hidden.',
					$preset_key
				),
			);
		}

		return $diagnostics;
	}
}
