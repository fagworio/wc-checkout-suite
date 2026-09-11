<?php
/**
 * Order field snapshot tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Orders;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Orders\OrderFieldSnapshot;

/**
 * Covers the record that keeps an old order readable.
 *
 * Two halves, and the second is the one that is easy to get wrong. Building the
 * snapshot is a lookup; rebuilding it out of storage is a parse of JSON that this
 * build did not necessarily write, and a snapshot that trusts its input would
 * propagate whatever it found into the reading of a merchant's order.
 */
final class OrderFieldSnapshotTest extends TestCase {

	/**
	 * Builds one raw definition.
	 *
	 * @param string               $id      Identifier.
	 * @param array<string, mixed> $changes Values to override.
	 * @return array<string, mixed>
	 */
	private function definition( string $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'     => $id,
				'type'   => 'text',
				'label'  => 'Label ' . $id,
				'origin' => 'custom',
			),
			$changes
		);
	}

	/**
	 * Only the fields that were asked for are recorded.
	 *
	 * @return void
	 */
	public function test_only_the_requested_fields_are_recorded(): void {
		$snapshot = OrderFieldSnapshot::from_definitions(
			array( 'wccs_cpf' ),
			array(
				$this->definition(
					'wccs_cpf',
					array(
						'label' => 'CPF',
						'type'  => 'text',
					)
				),
				$this->definition( 'wccs_other' ),
			)
		);

		$this->assertSame( array( 'wccs_cpf' ), array_keys( $snapshot->all() ) );
		$this->assertSame( 'CPF', $snapshot->entry( 'wccs_cpf' )['label'] );
		$this->assertSame( 'text', $snapshot->entry( 'wccs_cpf' )['type'] );
		$this->assertNull( $snapshot->entry( 'wccs_other' ) );
	}

	/**
	 * The option labels travel with the value that used them.
	 *
	 * @return void
	 */
	public function test_option_labels_are_recorded(): void {
		$snapshot = OrderFieldSnapshot::from_definitions(
			array( 'wccs_size' ),
			array(
				$this->definition(
					'wccs_size',
					array(
						'type'     => 'select',
						'settings' => array(
							'options' => array(
								array(
									'value' => 'm',
									'label' => 'Medium',
								),
							),
						),
					)
				),
			)
		);

		$this->assertSame( array( 'm' => 'Medium' ), $snapshot->entry( 'wccs_size' )['options'] );
	}

	/**
	 * A field with no options records an empty map, not a missing key.
	 *
	 * @return void
	 */
	public function test_a_field_without_options_records_an_empty_map(): void {
		$snapshot = OrderFieldSnapshot::from_definitions( array( 'wccs_cpf' ), array( $this->definition( 'wccs_cpf' ) ) );

		$this->assertSame( array(), $snapshot->entry( 'wccs_cpf' )['options'] );
	}

	/**
	 * A definition that lost its label still records the field.
	 *
	 * The identifier is the fallback the reader already has, so recording an
	 * empty label is honest rather than a reason to leave the field out.
	 *
	 * @return void
	 */
	public function test_a_definition_with_an_empty_label_is_still_recorded(): void {
		$snapshot = OrderFieldSnapshot::from_definitions(
			array( 'wccs_cpf' ),
			array( $this->definition( 'wccs_cpf', array( 'label' => '' ) ) )
		);

		$this->assertSame( '', $snapshot->entry( 'wccs_cpf' )['label'] );
	}

	/**
	 * A malformed definition is skipped.
	 *
	 * @return void
	 */
	public function test_a_malformed_definition_is_skipped(): void {
		$snapshot = OrderFieldSnapshot::from_definitions(
			array( 'wccs_cpf', 'wccs_other' ),
			array( 'not a definition', $this->definition( 'wccs_cpf' ) )
		);

		$this->assertSame( array( 'wccs_cpf' ), array_keys( $snapshot->all() ) );
	}

	/**
	 * The first definition of an identifier wins.
	 *
	 * @return void
	 */
	public function test_the_first_definition_of_an_identifier_wins(): void {
		$snapshot = OrderFieldSnapshot::from_definitions(
			array( 'wccs_cpf' ),
			array(
				$this->definition( 'wccs_cpf', array( 'label' => 'First' ) ),
				$this->definition( 'wccs_cpf', array( 'label' => 'Second' ) ),
			)
		);

		$this->assertSame( 'First', $snapshot->entry( 'wccs_cpf' )['label'] );
	}

	/**
	 * Nothing asked for, nothing recorded.
	 *
	 * @return void
	 */
	public function test_nothing_asked_for_records_nothing(): void {
		$this->assertTrue( OrderFieldSnapshot::from_definitions( array(), array( $this->definition( 'wccs_cpf' ) ) )->is_empty() );
		$this->assertTrue( OrderFieldSnapshot::none()->is_empty() );
		$this->assertSame( array(), OrderFieldSnapshot::none()->all() );
	}

	/**
	 * A stored snapshot rebuilds with its entries intact.
	 *
	 * @return void
	 */
	public function test_a_stored_snapshot_rebuilds(): void {
		$snapshot = OrderFieldSnapshot::from_array(
			array(
				'wccs_size' => array(
					'label'   => 'Size',
					'type'    => 'select',
					'options' => array( 'm' => 'Medium' ),
				),
			)
		);

		$this->assertSame( 'Size', $snapshot->entry( 'wccs_size' )['label'] );
		$this->assertSame( 'select', $snapshot->entry( 'wccs_size' )['type'] );
		$this->assertSame( array( 'm' => 'Medium' ), $snapshot->entry( 'wccs_size' )['options'] );
	}

	/**
	 * An entry the store cannot vouch for is dropped, not guessed at.
	 *
	 * @return void
	 */
	public function test_entries_that_are_not_entries_are_dropped(): void {
		$snapshot = OrderFieldSnapshot::from_array(
			array(
				'wccs_ok'      => array(
					'label' => 'Ok',
					'type'  => 'text',
				),
				'wccs_scalar'  => 'not an entry',
				'wccs_nolabel' => array( 'type' => 'text' ),
				'wccs_notype'  => array( 'label' => 'No type' ),
				'wccs_badtype' => array(
					'label' => 'Bad',
					'type'  => 7,
				),
				7              => array(
					'label' => 'Numeric key',
					'type'  => 'text',
				),
			)
		);

		$this->assertSame( array( 'wccs_ok' ), array_keys( $snapshot->all() ) );
	}

	/**
	 * An options map that is not a map of strings becomes empty.
	 *
	 * @return void
	 */
	public function test_options_that_are_not_strings_are_dropped(): void {
		$snapshot = OrderFieldSnapshot::from_array(
			array(
				'wccs_size' => array(
					'label'   => 'Size',
					'type'    => 'select',
					'options' => array(
						'm' => 'Medium',
						'x' => array( 'nested' ),
						3   => 'numeric key',
					),
				),
			)
		);

		$this->assertSame( array( 'm' => 'Medium' ), $snapshot->entry( 'wccs_size' )['options'] );
	}

	/**
	 * A missing or malformed options key is an empty map, not a failure.
	 *
	 * @return void
	 */
	public function test_a_missing_options_key_is_an_empty_map(): void {
		$snapshot = OrderFieldSnapshot::from_array(
			array(
				'wccs_size' => array(
					'label'   => 'Size',
					'type'    => 'select',
					'options' => 'not a map',
				),
			)
		);

		$this->assertSame( array(), $snapshot->entry( 'wccs_size' )['options'] );
	}

	/**
	 * A snapshot that is not an array at all remembers nothing.
	 *
	 * This is the format-1 case: the payload has values and no snapshot.
	 *
	 * @return void
	 */
	public function test_a_snapshot_that_is_not_an_array_remembers_nothing(): void {
		$this->assertTrue( OrderFieldSnapshot::from_array( null )->is_empty() );
		$this->assertTrue( OrderFieldSnapshot::from_array( 'not a snapshot' )->is_empty() );
		$this->assertTrue( OrderFieldSnapshot::from_array( array() )->is_empty() );
	}
}
