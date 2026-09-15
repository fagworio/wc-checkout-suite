<?php
/**
 * Customer surface resolution tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Customers;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Customers\CustomerSectionFields;
use WCCheckoutSuite\Domain\Registries;

/**
 * Covers how a customer surface reads a container: which uses of which fields it shows,
 * under which title and order, which of them accept a value, and what a submission means
 * when the same field is used twice.
 */
final class CustomerSectionFieldsTest extends TestCase {

	/**
	 * Boots the registries, because a submission is validated by the shared processor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Registries::boot();
	}

	/**
	 * One field with its uses.
	 *
	 * @param array<int, array<string, mixed>> $bindings Bindings.
	 * @param array<string, mixed>             $extra    Extra definition keys.
	 * @return array<string, mixed>
	 */
	private function field( array $bindings, array $extra = array() ): array {
		return array_merge(
			array(
				'id'           => 'registro',
				'origin'       => 'custom',
				'type'         => 'text',
				'label'        => 'Registro',
				'section'      => 'dados',
				'position'     => 7,
				'enabled'      => true,
				'required'     => false,
				'destinations' => \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations(),
				'bindings'     => $bindings,
			),
			$extra
		);
	}

	/**
	 * A use of the field in a container.
	 *
	 * @param string               $container Container.
	 * @param string               $title     Title.
	 * @param int|null             $position  Position.
	 * @param bool                 $editable  Whether it accepts a value.
	 * @param array<string, mixed> $extra     Extra keys.
	 * @return array<string, mixed>
	 */
	private function binding( string $container, string $title, ?int $position, bool $editable, array $extra = array() ): array {
		return array_merge(
			array(
				'field_id'       => 'registro',
				'container_id'   => $container,
				'destination'    => 'customer_account',
				'label_override' => $title,
				'position'       => $position,
				'visible'        => true,
				'editable'       => $editable,
			),
			$extra
		);
	}

	/**
	 * Two uses in one container come out twice, each with its own decision.
	 *
	 * @return void
	 */
	public function test_one_field_used_twice_in_a_container_is_rendered_twice(): void {
		$definitions = array(
			$this->field(
				array(
					$this->binding( 'dados', 'Registro atual', 20, true ),
					$this->binding( 'dados', 'Registro conferido', 10, false ),
				)
			),
		);

		$entries = CustomerSectionFields::entries( $definitions, 'customer_account', 'dados' );

		self::assertCount( 2, $entries );
		self::assertSame( 'Registro conferido', $entries[0]['title'], 'the use order decides' );
		self::assertSame( 10, $entries[0]['position'] );
		self::assertFalse( CustomerSectionFields::entry_writable( $entries[0] ) );
		self::assertSame( 'Registro atual', $entries[1]['title'] );
		self::assertTrue( CustomerSectionFields::entry_writable( $entries[1] ) );
		self::assertTrue(
			CustomerSectionFields::writable(
				\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $definitions[0] ),
				'customer_account'
			),
			'one use accepting a value is enough for the field to be writable somewhere'
		);
	}

	/**
	 * A use belongs to its own container: another container does not see it.
	 *
	 * @return void
	 */
	public function test_only_the_uses_of_this_container_are_returned(): void {
		$definitions = array(
			$this->field(
				array(
					$this->binding( 'dados', 'Registro', 10, true ),
					$this->binding( 'documentos', 'Registro anexo', 10, true ),
				)
			),
		);

		self::assertCount( 1, CustomerSectionFields::entries( $definitions, 'customer_account', 'dados' ) );
		self::assertCount( 1, CustomerSectionFields::entries( $definitions, 'customer_account', 'documentos' ) );
		self::assertSame( array(), CustomerSectionFields::entries( $definitions, 'customer_account', 'outro' ) );
	}

	/**
	 * A use that does not configure a position keeps the field's own order.
	 *
	 * @return void
	 */
	public function test_a_use_without_a_position_keeps_the_fields_own(): void {
		$definitions = array(
			$this->field( array( $this->binding( 'dados', 'Registro', null, true ) ) ),
		);

		$entries = CustomerSectionFields::entries( $definitions, 'customer_account', 'dados' );

		self::assertSame( 7, $entries[0]['position'] );
	}

	/**
	 * A document that still stores a destination map is read the same way.
	 *
	 * @return void
	 */
	public function test_a_stored_link_is_read_as_one_use(): void {
		$destinations                     = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations();
		$destinations['customer_account'] = array(
			'enabled'  => true,
			'section'  => 'dados',
			'title'    => 'Registro do cliente',
			'position' => 30,
			'mode'     => 'edit',
		);

		$field = $this->field( array() );
		unset( $field['bindings'] );
		$field['destinations'] = $destinations;

		$entries = CustomerSectionFields::entries( array( $field ), 'customer_account', 'dados' );

		self::assertCount( 1, $entries );
		self::assertSame( 'Registro do cliente', $entries[0]['title'] );
		self::assertSame( 30, $entries[0]['position'] );
		self::assertTrue( CustomerSectionFields::entry_writable( $entries[0] ) );
	}

	/**
	 * A field used twice is one value: validated once and written once.
	 *
	 * @return void
	 */
	public function test_a_submission_writes_a_field_used_twice_once(): void {
		$definitions = array(
			$this->field(
				array(
					$this->binding( 'dados', 'Registro atual', 20, true ),
					$this->binding( 'dados', 'Registro conferido', 10, false ),
				)
			),
		);

		$entries = CustomerSectionFields::entries( $definitions, 'customer_account', 'dados' );
		$errors  = array();
		$updates = CustomerSectionFields::submission(
			$entries,
			'customer_account',
			array(),
			array( 'registro' => 'ABC123' ),
			$errors
		);

		self::assertSame( array(), $errors );
		self::assertSame( array( 'registro' => 'ABC123' ), $updates );
	}

	/**
	 * A use that is read-only does not accept a value, and does not erase one either.
	 *
	 * @return void
	 */
	public function test_a_read_only_use_accepts_nothing(): void {
		$definitions = array(
			$this->field( array( $this->binding( 'dados', 'Registro', 10, false ) ) ),
		);

		$entries = CustomerSectionFields::entries( $definitions, 'customer_account', 'dados' );
		$errors  = array();
		$updates = CustomerSectionFields::submission(
			$entries,
			'customer_account',
			array( 'registro' => 'antigo' ),
			array( 'registro' => 'novo' ),
			$errors
		);

		self::assertSame( array(), $updates );
		self::assertSame( array(), $errors );
	}

	/**
	 * A document is renderable on a customer surface, and reads as the file the customer sent.
	 *
	 * The document is not a value: it is a row the customer's own store keeps, found by
	 * customer and field. The surface still has to know it is one, because a file is drawn and
	 * submitted differently from a value — and the label is the sentence both surfaces show.
	 *
	 * @return void
	 */
	public function test_a_document_is_renderable_and_reads_as_a_file(): void {
		$document         = $this->field( array( $this->binding( 'dados', 'Contrato', 10, true ) ) );
		$document['type'] = 'file';

		$entries = CustomerSectionFields::entries( array( $document ), 'customer_account', 'dados' );

		self::assertCount( 1, $entries, 'a document is one of the types a customer surface collects' );
		self::assertTrue( CustomerSectionFields::is_document( $entries[0]['field'] ) );

		// A value the customer stored is not a document, and a document is never read as one.
		$text = $this->field( array( $this->binding( 'dados', 'Registro', 10, true ) ) );

		self::assertFalse( CustomerSectionFields::is_document( \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $text ) ) );
	}

	/**
	 * The sentence a customer reads about their document.
	 *
	 * @return void
	 */
	public function test_a_document_label_names_the_file_and_its_size(): void {
		self::assertSame(
			'Nenhum documento enviado.',
			CustomerSectionFields::document_label( null )
		);

		self::assertSame(
			'contrato-social.pdf (48 B)',
			CustomerSectionFields::document_label(
				array(
					'file_name' => 'contrato-social.pdf',
					'byte_size' => 48,
				)
			)
		);

		// A row whose name is gone still says a document was sent, rather than showing nothing.
		self::assertSame(
			'Documento enviado.',
			CustomerSectionFields::document_label(
				array(
					'file_name' => '',
					'byte_size' => 0,
				)
			)
		);
	}
}
