<?php
/**
 * Classic submission tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Classic;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Classic\ClassicSubmission;
use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorRegistry;
use WCCheckoutSuite\Domain\Conditions\PermissiveConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\CoreTypes;
use WCCheckoutSuite\Domain\Fields\FieldTypeRegistry;
use WCCheckoutSuite\Domain\Fields\ValidatorRegistry;
use WCCheckoutSuite\Domain\Validation\CoreProcessing;
use WCCheckoutSuite\Domain\Validation\NormalizerRegistry;
use WCCheckoutSuite\Domain\Validation\ValueProcessor;
use WCCheckoutSuite\Tests\Unit\Stubs\HidingEvaluator;

/**
 * Covers what a submitted checkout value becomes, and what is said about it.
 *
 * The two rules worth the most here are the ones where the obvious
 * implementation is wrong: carrying a boolean back into WooCommerce's posted
 * data would silently disable its required check for a checkbox, and reporting
 * requiredness from this layer would duplicate WooCommerce's own message or
 * reject a customer for a fieldset WooCommerce deliberately skipped.
 */
final class ClassicSubmissionTest extends TestCase {

	/**
	 * Submission under test.
	 *
	 * @var ClassicSubmission
	 */
	private ClassicSubmission $submission;

	/**
	 * Builds a submission over fresh registries.
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

		$processor = new ValueProcessor( $types, new ValidatorRegistry(), $normalizers, $conditions );

		$this->submission = new ClassicSubmission( $processor );
	}

	/**
	 * Builds one raw definition with documented defaults.
	 *
	 * @param array<string, mixed> $overrides Definition entries to override.
	 * @return array<string, mixed>
	 */
	private function definition( array $overrides ): array {
		return array_merge(
			array(
				'id'     => 'wccs_field',
				'type'   => 'text',
				'label'  => 'Document',
				'origin' => 'custom',
			),
			$overrides
		);
	}

	/**
	 * Runs the submission and returns the values it would carry back.
	 *
	 * @param array<string, mixed>             $data        Posted values.
	 * @param array<int, array<string, mixed>> $definitions Definitions.
	 * @return array<string, mixed>
	 */
	private function values( array $data, array $definitions ): array {
		return $this->submission->normalize( $data, $definitions )['values'];
	}

	/**
	 * Runs the submission and returns the errors it would report.
	 *
	 * @param array<string, mixed>             $data        Posted values.
	 * @param array<int, array<string, mixed>> $definitions Definitions.
	 * @return array<int, array{field: string, code: string, message: string}>
	 */
	private function errors( array $data, array $definitions ): array {
		$outcome = $this->submission->normalize( $data, $definitions );

		return $this->submission->errors( $outcome['results'] );
	}

	/**
	 * A declared normalizer is what the store ends up acting on.
	 *
	 * @return void
	 */
	public function test_a_declared_normalizer_decides_the_value_the_store_acts_on(): void {
		$values = $this->values(
			array( 'wccs_cpf' => '123.456.789-09' ),
			array(
				$this->definition(
					array(
						'id'         => 'wccs_cpf',
						'normalizer' => 'digits',
					)
				),
			)
		);

		$this->assertSame( '12345678909', $values['wccs_cpf'] );
	}

	/**
	 * A boolean is never carried back into WooCommerce's posted data.
	 *
	 * WooCommerce represents an unchecked box as the empty string and decides
	 * requiredness with a strict comparison against it. Writing `false` there
	 * would make a required consent box pass while unchecked, which is the exact
	 * forged request this task exists to stop.
	 *
	 * @return void
	 */
	public function test_an_unchecked_box_stays_empty_in_the_posted_data(): void {
		$definitions = array(
			$this->definition(
				array(
					'id'       => 'wccs_consent',
					'type'     => 'checkbox',
					'required' => true,
				)
			),
		);

		$outcome = $this->submission->normalize( array( 'wccs_consent' => '' ), $definitions );

		// The pipeline did normalize it: the canonical value is a boolean.
		$this->assertFalse( $outcome['results']['wccs_consent']->value() );

		// What travels back to WooCommerce is still its own "unchecked".
		$this->assertSame( '', $outcome['values']['wccs_consent'] );
	}

	/**
	 * A checked box keeps the value WooCommerce built.
	 *
	 * @return void
	 */
	public function test_a_checked_box_keeps_the_value_woocommerce_built(): void {
		$values = $this->values(
			array( 'wccs_consent' => '1' ),
			array(
				$this->definition(
					array(
						'id'   => 'wccs_consent',
						'type' => 'checkbox',
					)
				),
			)
		);

		$this->assertSame( '1', $values['wccs_consent'] );
	}

	/**
	 * A number is not carried back either, for the same reason.
	 *
	 * @return void
	 */
	public function test_a_number_is_normalized_but_not_carried_back(): void {
		$definitions = array(
			$this->definition(
				array(
					'id'   => 'wccs_qty',
					'type' => 'number',
				)
			),
		);

		$outcome = $this->submission->normalize( array( 'wccs_qty' => '42' ), $definitions );

		$this->assertSame( 42, $outcome['results']['wccs_qty']->value() );
		$this->assertSame( '42', $outcome['values']['wccs_qty'] );
	}

	/**
	 * A rejected value is reported with the field it belongs to.
	 *
	 * @return void
	 */
	public function test_a_rejected_value_is_reported_against_its_field(): void {
		$errors = $this->errors(
			array( 'wccs_qty' => 'not a number' ),
			array(
				$this->definition(
					array(
						'id'    => 'wccs_qty',
						'type'  => 'number',
						'label' => 'Quantity',
					)
				),
			)
		);

		$this->assertCount( 1, $errors );
		$this->assertSame( 'wccs_qty', $errors[0]['field'] );
		$this->assertSame( 'not_a_number', $errors[0]['code'] );
		$this->assertNotSame( '', $errors[0]['message'] );
	}

	/**
	 * What the customer typed survives a rejection.
	 *
	 * @return void
	 */
	public function test_a_rejected_value_is_not_replaced_by_a_normalized_one(): void {
		$values = $this->values(
			array( 'wccs_site' => '  not a url  ' ),
			array(
				$this->definition(
					array(
						'id'   => 'wccs_site',
						'type' => 'url',
					)
				),
			)
		);

		$this->assertSame( '  not a url  ', $values['wccs_site'] );
	}

	/**
	 * An off-list choice is refused, whatever the browser was told was allowed.
	 *
	 * @return void
	 */
	public function test_a_choice_outside_the_declared_options_is_refused(): void {
		$definition = $this->definition(
			array(
				'id'       => 'wccs_size',
				'type'     => 'select',
				'settings' => array(
					'options' => array(
						array(
							'value' => 's',
							'label' => 'Small',
						),
						array(
							'value' => 'm',
							'label' => 'Medium',
						),
					),
				),
			)
		);

		$this->assertSame( array(), $this->errors( array( 'wccs_size' => 'm' ), array( $definition ) ) );

		$errors = $this->errors( array( 'wccs_size' => 'xxl' ), array( $definition ) );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'invalid_choice', $errors[0]['code'] );
		$this->assertSame( 'wccs_size', $errors[0]['field'] );
	}

	/**
	 * Requiredness is computed and then not reported.
	 *
	 * The assertion that matters is the pair: the pipeline found the error, and
	 * this layer declined to turn it into a second message about a field
	 * WooCommerce is already reporting on.
	 *
	 * @return void
	 */
	public function test_requiredness_is_computed_but_left_to_woocommerce(): void {
		$definitions = array(
			$this->definition(
				array(
					'id'       => 'wccs_doc',
					'required' => true,
				)
			),
		);

		$outcome = $this->submission->normalize( array( 'wccs_doc' => '' ), $definitions );

		$this->assertContains(
			'required',
			$outcome['results']['wccs_doc']->result()->error_codes()
		);

		$this->assertSame( array(), $this->submission->errors( $outcome['results'] ) );
	}

	/**
	 * A field the form did not carry is not examined at all.
	 *
	 * A file upload has no classic rendering, so the adapter leaves it off the
	 * checkout. Validating it anyway would let an unrenderable required field
	 * block a customer forever, and would let a forged request speak for a field
	 * that does not exist.
	 *
	 * @return void
	 */
	public function test_a_field_the_form_did_not_carry_is_not_examined(): void {
		$definitions = array(
			$this->definition(
				array(
					'id'       => 'wccs_attachment',
					'type'     => 'file',
					'required' => true,
				)
			),
		);

		$outcome = $this->submission->normalize( array( 'billing_first_name' => 'Ana' ), $definitions );

		$this->assertSame( array(), $outcome['results'] );
		$this->assertSame( array(), $this->submission->errors( $outcome['results'] ) );
	}

	/**
	 * A definition that is not an array is skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_a_malformed_definition_is_skipped(): void {
		$values = $this->values(
			array( 'wccs_doc' => 'abc' ),
			array( 'not a definition', $this->definition( array( 'id' => 'wccs_doc' ) ) )
		);

		$this->assertSame( 'abc', $values['wccs_doc'] );
	}

	/**
	 * A field hidden by a rule keeps nothing.
	 *
	 * @return void
	 */
	public function test_a_hidden_field_keeps_no_value(): void {
		$definition = $this->definition(
			array(
				'id'         => 'wccs_extra',
				'conditions' => array(
					'evaluator' => 'test.never',
					'visible'   => array(
						array(
							'field'    => 'billing_country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
					),
				),
			)
		);

		$outcome = $this->submission->normalize( array( 'wccs_extra' => 'residual' ), array( $definition ) );

		$this->assertTrue( $outcome['results']['wccs_extra']->is_discarded() );
		$this->assertSame( '', $outcome['values']['wccs_extra'] );
	}

	/**
	 * The pipeline judges the normalized value, not the raw one.
	 *
	 * This is why the wiring normalizes once and validates against that same
	 * outcome instead of running the pipeline again on the normalized value: the
	 * two runs would agree here, but a value whose normalization shortens it
	 * would be judged twice under two different lengths.
	 *
	 * @return void
	 */
	public function test_the_judged_value_is_the_normalized_one(): void {
		$definition = $this->definition(
			array(
				'id'         => 'wccs_code',
				'normalizer' => 'single_spaces',
				'settings'   => array( 'maxLength' => 5 ),
			)
		);

		$this->assertSame(
			array(),
			$this->errors( array( 'wccs_code' => 'a    b' ), array( $definition ) )
		);
	}
}
