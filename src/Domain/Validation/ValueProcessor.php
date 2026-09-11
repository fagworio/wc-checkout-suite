<?php
/**
 * Field value processing pipeline.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorRegistry;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\ValidationResult;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;

/**
 * Runs one field value through normalization, conditions and validation.
 *
 * The pipeline is adapter agnostic on purpose: the same instance answers for a
 * Classic submission, a Blocks submission and a value recompiled from an order.
 * Nothing here reads the request, the DOM or WC_Order.
 *
 * Order of operations follows the planning: normalize, evaluate visibility,
 * apply the hidden-value policy, then validate structure, value and named
 * validators. Requiredness is a property of the definition, not of the type, so
 * it is enforced here and never by the type.
 *
 * @see \ROADMAP.md sections 10 and 11
 */
final class ValueProcessor {

	/**
	 * Constructor.
	 *
	 * @param FieldTypeRegistry          $types      Field type registry.
	 * @param ValidatorRegistry          $validators Validator registry.
	 * @param NormalizerRegistry         $normalizers Normalizer registry.
	 * @param ConditionEvaluatorRegistry $conditions Condition evaluator registry.
	 */
	public function __construct(
		private FieldTypeRegistry $types,
		private ValidatorRegistry $validators,
		private NormalizerRegistry $normalizers,
		private ConditionEvaluatorRegistry $conditions
	) {
	}

	/**
	 * Processes one value.
	 *
	 * @param FieldDefinition $definition Field definition.
	 * @param mixed           $value      Raw value.
	 * @param FieldContext    $context    Trusted context.
	 * @return ProcessedValue
	 */
	public function process( FieldDefinition $definition, mixed $value, FieldContext $context ): ProcessedValue {
		$type = $this->types->type( $definition->type() );

		if ( null === $type ) {
			return new ProcessedValue(
				null,
				ValidationResult::invalid(
					'unknown_type',
					sprintf( 'The field type "%s" is not registered.', $definition->type() ),
					array( 'type' => $definition->type() )
				),
				false,
				true
			);
		}

		// Settings travel with the context so the type can honour them without
		// changing the published contract.
		$context = $context->with_settings( $definition->settings() );

		$canonical = $type->normalize( $value, $context );

		$normalizer = $definition->to_array()['normalizer'];

		if ( is_string( $normalizer ) && '' !== $normalizer ) {
			$canonical = $this->normalizers->run( $normalizer, $canonical, $context );
		}

		$visible = $this->is_visible( $definition, $context );

		if ( ! $visible && 'discard' === $definition->to_array()['hidden_value_policy'] ) {
			// A hidden field stores nothing and never raises a required error.
			return new ProcessedValue( null, ValidationResult::valid(), false, true );
		}

		$result = $type->validate( $canonical, $context );

		foreach ( $definition->validators() as $reference ) {
			$key = is_array( $reference ) && isset( $reference['key'] ) ? (string) $reference['key'] : '';

			if ( '' !== $key ) {
				$result = $result->merge( $this->validators->run( $key, $canonical, $context ) );
			}
		}

		if ( $definition->is_required() && $visible && $this->is_absent( $canonical ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'required',
					sprintf(
						/* translators: %s: field label */
						__( '%s is a required field.', 'wc-checkoutsuite' ),
						$definition->label()
					),
					array( 'field' => $definition->id() )
				)
			);
		}

		return new ProcessedValue( $canonical, $result, $visible, false );
	}

	/**
	 * Whether the field is visible under the current context.
	 *
	 * @param FieldDefinition $definition Field definition.
	 * @param FieldContext    $context    Trusted context.
	 * @return bool
	 */
	private function is_visible( FieldDefinition $definition, FieldContext $context ): bool {
		$conditions = $definition->to_array()['conditions'];

		if ( ! is_array( $conditions ) ) {
			return true;
		}

		$rules = isset( $conditions['visible'] ) && is_array( $conditions['visible'] ) ? $conditions['visible'] : array();

		if ( array() === $rules ) {
			return true;
		}

		// A definition may name the evaluator it was written against, so a store
		// can keep working with a pinned engine while a newer one is introduced.
		$named     = isset( $conditions['evaluator'] ) && is_string( $conditions['evaluator'] ) ? $conditions['evaluator'] : '';
		$evaluator = '' !== $named ? $this->conditions->evaluator( $named ) : null;

		if ( null === $evaluator ) {
			$evaluator = $this->conditions->active();
		}

		return $evaluator->evaluate( $rules, $context );
	}

	/**
	 * Whether a canonical value counts as absent for requiredness.
	 *
	 * `0`, `'0'` and `false` are real values; an empty list is not.
	 *
	 * @param mixed $value Canonical value.
	 * @return bool
	 */
	private function is_absent( mixed $value ): bool {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return is_array( $value ) && array() === $value;
	}
}
