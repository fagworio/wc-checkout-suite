<?php
/**
 * Field definition tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Fields;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Covers the definition accessors that more than one layer reads.
 *
 * `options()` is the one that matters: the adapter that renders a select, the
 * checker that decides whether a submitted key is allowed and the snapshot that
 * remembers what a key meant all ask the same question. Three readers of one
 * setting is three chances to disagree, so the shape is pinned here and the
 * three callers share it.
 */
final class FieldDefinitionTest extends TestCase {

	/**
	 * Builds a definition.
	 *
	 * @param array<string, mixed> $changes Values to override.
	 * @return FieldDefinition
	 */
	private function definition( array $changes = array() ): FieldDefinition {
		return FieldDefinition::from_array(
			array_merge(
				array(
					'id'    => 'wccs_size',
					'type'  => 'select',
					'label' => 'Size',
				),
				$changes
			)
		);
	}

	/**
	 * Declared options become a value-to-label map.
	 *
	 * @return void
	 */
	public function test_declared_options_become_a_map(): void {
		$definition = $this->definition(
			array(
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

		$this->assertSame(
			array(
				's' => 'Small',
				'm' => 'Medium',
			),
			$definition->options()
		);
	}

	/**
	 * A bare string is its own label, the way the choice type already reads it.
	 *
	 * @return void
	 */
	public function test_a_bare_string_option_is_its_own_label(): void {
		$definition = $this->definition( array( 'settings' => array( 'options' => array( 's', 'm' ) ) ) );

		$this->assertSame(
			array(
				's' => 's',
				'm' => 'm',
			),
			$definition->options()
		);
	}

	/**
	 * A label that is missing falls back to the value.
	 *
	 * @return void
	 */
	public function test_an_option_without_a_label_falls_back_to_its_value(): void {
		$definition = $this->definition( array( 'settings' => array( 'options' => array( array( 'value' => 's' ) ) ) ) );

		$this->assertSame( array( 's' => 's' ), $definition->options() );
	}

	/**
	 * A type that declares no options has none.
	 *
	 * @return void
	 */
	public function test_a_type_without_options_has_none(): void {
		$this->assertSame(
			array(),
			$this->definition(
				array(
					'type'     => 'text',
					'settings' => array(),
				)
			)->options()
		);
	}

	/**
	 * Settings that are not a list of options are ignored, not guessed at.
	 *
	 * @return void
	 */
	public function test_settings_that_are_not_options_are_ignored(): void {
		$this->assertSame( array(), $this->definition( array( 'settings' => array( 'options' => 's,m' ) ) )->options() );
		$this->assertSame( array(), $this->definition( array( 'settings' => array( 'options' => array( 42, null ) ) ) )->options() );
	}

	/**
	 * An omitted origin is `custom`.
	 *
	 * The model is the only place that knows this, which is why the origin is
	 * read through it rather than from the raw array.
	 *
	 * @return void
	 */
	public function test_an_omitted_origin_is_custom(): void {
		$this->assertSame( 'custom', $this->definition()->origin() );
		$this->assertSame( 'core', $this->definition( array( 'origin' => 'core' ) )->origin() );
	}
}
