<?php
/**
 * Value pipeline tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Validation;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorRegistry;
use WCCheckoutSuite\Domain\Conditions\PermissiveConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\CoreTypes;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;
use WCCheckoutSuite\Domain\Validation\CoreProcessing;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;
use WCCheckoutSuite\Domain\Validation\ValueProcessor;
use WCCheckoutSuite\Tests\Unit\Stubs\HidingEvaluator;

/**
 * Covers the adapter-agnostic pipeline built in WCCS-009.
 */
final class ValueProcessorTest extends TestCase {

	/**
	 * Pipeline under test.
	 *
	 * @var ValueProcessor
	 */
	private ValueProcessor $processor;

	/**
	 * Builds a pipeline over fresh registries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$types = new FieldTypeRegistry();
		CoreTypes::register_types( $types );

		$normalizers = new NormalizerRegistry();
		CoreProcessing::register_normalizers( $normalizers );

		$conditions = new ConditionEvaluatorRegistry();
		$conditions->register_evaluator( new PermissiveConditionEvaluator() );
		$conditions->register_evaluator( new HidingEvaluator() );

		$this->processor = new ValueProcessor( $types, new ValidatorRegistry(), $normalizers, $conditions );
	}

	/**
	 * Builds a definition.
	 *
	 * @param array<string, mixed> $override Overrides.
	 * @return FieldDefinition
	 */
	private function definition( array $override = array() ): FieldDefinition {
		return FieldDefinition::from_array(
			array_merge(
				array(
					'id'         => 'billing_document',
					'type'       => 'text',
					'label'      => 'Document',
					'required'   => true,
					'settings'   => array( 'maxLength' => 20 ),
					'normalizer' => 'digits',
				),
				$override
			)
		);
	}

	/**
	 * The named normalizer runs before validation.
	 *
	 * @return void
	 */
	public function test_named_normalizer_runs_before_validation(): void {
		$processed = $this->processor->process( $this->definition(), '123.456.789-01', new FieldContext() );

		self::assertSame( '12345678901', $processed->value() );
		self::assertTrue( $processed->result()->is_valid() );
		self::assertTrue( $processed->is_storable() );
	}

	/**
	 * Requiredness belongs to the definition, not to the field type.
	 *
	 * @return void
	 */
	public function test_required_is_enforced_by_the_definition(): void {
		$required = $this->processor->process( $this->definition(), '', new FieldContext() );
		$optional = $this->processor->process( $this->definition( array( 'required' => false ) ), '', new FieldContext() );

		self::assertContains( 'required', $required->result()->error_codes() );
		self::assertFalse( $required->is_storable() );
		self::assertTrue( $optional->result()->is_valid() );
	}

	/**
	 * Zero is a real value and satisfies requiredness.
	 *
	 * @return void
	 */
	public function test_zero_satisfies_requiredness(): void {
		$processed = $this->processor->process(
			$this->definition(
				array(
					'id'         => 'quantity',
					'type'       => 'number',
					'label'      => 'Quantity',
					'normalizer' => null,
				)
			),
			'0',
			new FieldContext()
		);

		self::assertSame( 0, $processed->value() );
		self::assertTrue( $processed->result()->is_valid(), implode( ',', $processed->result()->error_codes() ) );
	}

	/**
	 * A checkbox turns an absent submission into a stored false.
	 *
	 * @return void
	 */
	public function test_checkbox_stores_false(): void {
		$processed = $this->processor->process(
			$this->definition(
				array(
					'id'         => 'consent',
					'type'       => 'checkbox',
					'label'      => 'Consent',
					'normalizer' => null,
				)
			),
			'',
			new FieldContext()
		);

		self::assertFalse( $processed->value() );
		self::assertTrue( $processed->is_storable() );
	}

	/**
	 * A hidden field discards its value and raises no required error.
	 *
	 * @return void
	 */
	public function test_hidden_field_discards_its_value(): void {
		$processed = $this->processor->process(
			$this->definition(
				array(
					'conditions' => array(
						'evaluator' => 'test.never',
						'visible'   => array(
							'all' => array(
								array(
									'source'   => 'field',
									'path'     => 'person_type',
									'operator' => 'equals',
									'value'    => 'pj',
								),
							),
						),
					),
				)
			),
			'12345678901',
			new FieldContext()
		);

		self::assertTrue( $processed->is_discarded() );
		self::assertNull( $processed->value() );
		self::assertTrue( $processed->result()->is_valid(), 'a hidden field never raises required' );
		self::assertFalse( $processed->is_storable(), 'residual data is never persisted' );
	}

	/**
	 * Discard is what happens when nothing says otherwise.
	 *
	 * Section 11 makes `discard` the default rather than one of two equal options,
	 * so a definition that never mentions the policy has to behave as if it had:
	 * a field the customer cannot see stores nothing and cannot refuse the order,
	 * even when it is required and even when what was submitted looks valid.
	 *
	 * @return void
	 */
	public function test_the_default_hidden_policy_is_discard(): void {
		$processed = $this->processor->process(
			$this->definition(
				array(
					'conditions' => array(
						'evaluator' => 'test.never',
						'visible'   => array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
					),
				)
			),
			'12345678901',
			new FieldContext()
		);

		self::assertFalse( $processed->is_visible() );
		self::assertTrue( $processed->is_discarded() );
		self::assertNull( $processed->value() );
		self::assertTrue( $processed->result()->is_valid(), 'a hidden required field raises no required error' );
		self::assertFalse( $processed->is_storable() );
	}

	/**
	 * Preserve keeps what the store would accept.
	 *
	 * @return void
	 */
	public function test_preserve_keeps_a_value_the_store_accepts(): void {
		$processed = $this->processor->process(
			$this->definition(
				array(
					'hidden_value_policy' => 'preserve',
					'conditions'          => array(
						'evaluator' => 'test.never',
						'visible'   => array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
					),
				)
			),
			'123.456.789-01',
			new FieldContext()
		);

		self::assertFalse( $processed->is_visible() );
		self::assertFalse( $processed->is_discarded(), 'the value reaches the store' );
		self::assertSame( '12345678901', $processed->value() );
		self::assertTrue( $processed->is_storable() );
		self::assertTrue( $processed->result()->is_valid(), 'and nothing is reported about a field nobody can see' );
	}

	/**
	 * Preserve does not keep what the store would refuse.
	 *
	 * A value that fails its own rules cannot be repaired by the customer, because
	 * the field is not on the form; reporting the refusal would block the order
	 * behind an error with no field to attach it to. It is dropped instead, and the
	 * store does not keep something it would have refused.
	 *
	 * @return void
	 */
	public function test_preserve_drops_a_value_the_store_would_refuse(): void {
		$validators = new ValidatorRegistry();
		$types      = new FieldTypeRegistry();
		CoreTypes::register_types( $types );

		$normalizers = new NormalizerRegistry();
		CoreProcessing::register_normalizers( $normalizers );

		$conditions = new ConditionEvaluatorRegistry();
		$conditions->register_evaluator( new PermissiveConditionEvaluator() );
		$conditions->register_evaluator( new HidingEvaluator() );

		// A length rule the value breaks, so the refusal comes from the pipeline
		// rather than from a validator key that may not be registered here.
		$processor = new ValueProcessor( $types, $validators, $normalizers, $conditions );

		$processed = $processor->process(
			$this->definition(
				array(
					'hidden_value_policy' => 'preserve',
					'settings'            => array( 'maxLength' => 5 ),
					'conditions'          => array(
						'evaluator' => 'test.never',
						'visible'   => array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
					),
				)
			),
			'12345678901',
			new FieldContext()
		);

		self::assertTrue( $processed->is_discarded() );
		self::assertNull( $processed->value() );
		self::assertTrue( $processed->result()->is_valid(), 'no error for a field nobody can see' );

		// The same value with the same settings, visible: the refusal is real, and
		// it is the visibility that suppresses it rather than the value being fine.
		$visible = $processor->process(
			$this->definition(
				array(
					'settings' => array( 'maxLength' => 5 ),
				)
			),
			'12345678901',
			new FieldContext()
		);

		self::assertFalse( $visible->result()->is_valid() );
		self::assertNotSame( array(), $visible->result()->error_codes() );
	}

	/**
	 * An unregistered type fails closed.
	 *
	 * @return void
	 */
	public function test_unregistered_type_fails_closed(): void {
		$processed = $this->processor->process( $this->definition( array( 'type' => 'nope' ) ), 'x', new FieldContext() );

		self::assertContains( 'unknown_type', $processed->result()->error_codes() );
		self::assertFalse( $processed->is_storable() );
	}

	/**
	 * Every adapter produces the same outcome for the same input.
	 *
	 * @return void
	 */
	public function test_every_adapter_produces_the_same_outcome(): void {
		$values = array();
		$valids = array();

		foreach ( array( 'classic', 'blocks', 'order' ) as $adapter ) {
			$processed = $this->processor->process( $this->definition(), '123.456.789-01', new FieldContext( array(), $adapter ) );

			$values[ $adapter ] = $processed->value();
			$valids[ $adapter ] = $processed->result()->is_valid();
		}

		self::assertCount( 1, array_unique( $values, SORT_REGULAR ) );
		self::assertCount( 1, array_unique( $valids ) );
	}
}
