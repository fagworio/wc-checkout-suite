<?php
/**
 * Native condition compiler tests.
 *
 * The compiler's job is to say which rules fit in the checkout's own rule
 * language, and the interesting half of that job is the refusal: a rule that cannot
 * be translated has to come back with a reason, and a rule that is only *partly*
 * translatable has to come back untranslated, because translating the part that
 * fits produces a rule that means something else.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Conditions;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Conditions\NativeConditionCompiler;

/**
 * Compilation of the declarative tree into JSON Schema.
 */
final class NativeConditionCompilerTest extends TestCase {

	/**
	 * The compiler.
	 *
	 * @var NativeConditionCompiler
	 */
	private NativeConditionCompiler $compiler;

	/**
	 * Sets up the compiler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->compiler = new NativeConditionCompiler();
	}

	/**
	 * A definition with one rule.
	 *
	 * @param array<string, mixed> $visible Visibility rule.
	 * @return array<string, mixed>
	 */
	private function definition( array $visible ): array {
		return array( 'conditions' => array( 'visible' => $visible ) );
	}

	/**
	 * A field with no rule has nothing to compile.
	 *
	 * Not a failure: an always-visible field has no decision for the native
	 * mechanism to make, and reporting it as "not compiled" would fill the panel
	 * with warnings about fields that are working as intended.
	 *
	 * @return void
	 */
	public function test_a_field_without_a_rule_compiles_to_nothing_and_says_nothing(): void {
		$compiled = $this->compiler->compile( array( 'conditions' => array() ) );

		self::assertFalse( $compiled->is_compiled() );
		self::assertSame( array(), $compiled->notes() );
	}

	/**
	 * A comparison over a real document path is compiled.
	 *
	 * @return void
	 */
	public function test_a_country_comparison_compiles_to_the_document_path(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'BR',
				)
			)
		);

		self::assertTrue( $compiled->is_compiled() );
		self::assertSame(
			array(
				'customer' => array(
					'properties' => array(
						'billing_address' => array(
							'properties' => array(
								'country' => array( 'const' => 'BR' ),
							),
						),
					),
				),
			),
			$compiled->schema()
		);
		self::assertSame( array(), $compiled->notes() );
	}

	/**
	 * A three segment path is nested to its end.
	 *
	 * @return void
	 */
	public function test_the_cart_total_is_nested_under_its_own_key(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'cart_total',
					'operator' => 'greater_than',
					'value'    => 100,
				)
			)
		);

		self::assertSame(
			array(
				'cart' => array(
					'properties' => array(
						'totals' => array(
							'properties' => array(
								'total_price' => array( 'exclusiveMinimum' => 10000 ),
							),
						),
					),
				),
			),
			$compiled->schema(),
			'a price in the store currency becomes the minor units the document publishes'
		);
	}

	/**
	 * Product identifiers are compared as the integers the document holds.
	 *
	 * @return void
	 */
	public function test_a_cart_item_is_compared_as_an_integer(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'cart_items',
					'operator' => 'contains',
					'value'    => '12',
				)
			)
		);

		self::assertSame(
			array(
				'cart' => array(
					'properties' => array(
						'items' => array(
							'contains' => array( 'const' => 12 ),
						),
					),
				),
			),
			$compiled->schema()
		);
	}

	/**
	 * A belonging test becomes an enum.
	 *
	 * @return void
	 */
	public function test_membership_becomes_an_enumeration(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'country',
					'operator' => 'in',
					'value'    => array( 'BR', 'PT' ),
				)
			)
		);

		self::assertSame(
			array(
				'customer' => array(
					'properties' => array(
						'billing_address' => array(
							'properties' => array(
								'country' => array( 'enum' => array( 'BR', 'PT' ) ),
							),
						),
					),
				),
			),
			$compiled->schema()
		);
	}

	/**
	 * Emptiness is expressed in the terms of the shape, and only where it exists.
	 *
	 * @return void
	 */
	public function test_emptiness_follows_the_shape_of_what_it_is_asked_about(): void {
		$text = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'state',
					'operator' => 'is_empty',
				)
			)
		);

		$list = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'cart_items',
					'operator' => 'is_empty',
				)
			)
		);

		$boolean = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'customer_logged_in',
					'operator' => 'is_empty',
				)
			)
		);

		self::assertSame(
			array( 'maxLength' => 0 ),
			$text->schema()['customer']['properties']['billing_address']['properties']['state']
		);
		self::assertSame(
			array( 'maxItems' => 0 ),
			$list->schema()['cart']['properties']['items']
		);
		self::assertFalse( $boolean->is_compiled(), 'a boolean is never empty, and JSON Schema cannot say so' );
		self::assertSame( 'customer_logged_in', $boolean->notes()[0]['source'] );
	}

	/**
	 * A source the document does not carry is refused with that as the reason.
	 *
	 * @return void
	 */
	public function test_a_source_the_document_does_not_carry_is_refused(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'cart_categories',
					'operator' => 'contains',
					'value'    => 'books',
				)
			)
		);

		self::assertFalse( $compiled->is_compiled() );
		self::assertCount( 1, $compiled->notes() );
		self::assertSame( 'cart_categories', $compiled->notes()[0]['source'] );
		self::assertStringContainsString( 'categories', $compiled->notes()[0]['reason'] );
	}

	/**
	 * A reference to another field is refused, because its path is not ours to pick.
	 *
	 * @return void
	 */
	public function test_a_reference_to_another_field_is_refused(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'field',
					'field'    => 'wccs_person_type',
					'operator' => 'equals',
					'value'    => 'pj',
				)
			)
		);

		self::assertFalse( $compiled->is_compiled() );
		self::assertStringContainsString( 'location', $compiled->notes()[0]['reason'] );
	}

	/**
	 * A rule that is only partly translatable is not translated at all.
	 *
	 * This is the decision the acceptance is about. Compiling the half that fits
	 * would turn `all` into something weaker and `any` into something broader, so
	 * the same document would hide different fields in two checkouts — and nothing
	 * in either of them would say so.
	 *
	 * @return void
	 */
	public function test_a_partly_representable_rule_is_not_compiled_at_all(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'all' => array(
						array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
						array(
							'source'   => 'payment_method',
							'operator' => 'equals',
							'value'    => 'bacs',
						),
					),
				)
			)
		);

		self::assertFalse( $compiled->is_compiled() );
		self::assertSame( 'payment_method', $compiled->notes()[0]['source'] );
	}

	/**
	 * Groups are translated to their JSON Schema counterparts.
	 *
	 * @return void
	 */
	public function test_groups_become_all_of_and_any_of(): void {
		$all = $this->compiler->compile(
			$this->definition(
				array(
					'all' => array(
						array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
						array(
							'source'   => 'cart_items',
							'operator' => 'contains',
							'value'    => 12,
						),
					),
				)
			)
		);

		$any = $this->compiler->compile(
			$this->definition(
				array(
					'any' => array(
						array(
							'source'   => 'country',
							'operator' => 'equals',
							'value'    => 'BR',
						),
					),
				)
			)
		);

		self::assertArrayHasKey( 'allOf', $all->schema() );
		self::assertCount( 2, $all->schema()['allOf'] );
		self::assertArrayHasKey( 'anyOf', $any->schema() );
	}

	/**
	 * An empty group is refused rather than compiled to a group that matches.
	 *
	 * @return void
	 */
	public function test_an_empty_group_is_refused(): void {
		$compiled = $this->compiler->compile( $this->definition( array( 'all' => array() ) ) );

		self::assertFalse( $compiled->is_compiled() );
		self::assertSame( 'allOf', $compiled->notes()[0]['source'] );
	}

	/**
	 * A comparison with no operator is refused, and says which source it was about.
	 *
	 * @return void
	 */
	public function test_a_comparison_without_an_operator_is_refused(): void {
		$compiled = $this->compiler->compile( $this->definition( array( 'source' => 'country' ) ) );

		self::assertFalse( $compiled->is_compiled() );
		self::assertSame( 'country', $compiled->notes()[0]['source'] );
		self::assertStringContainsString( 'operator', $compiled->notes()[0]['reason'] );
	}

	/**
	 * A node that is neither a group nor a source is refused.
	 *
	 * @return void
	 */
	public function test_a_node_that_is_neither_a_group_nor_a_source_is_refused(): void {
		$compiled = $this->compiler->compile( $this->definition( array( 'nonsense' => true ) ) );

		self::assertFalse( $compiled->is_compiled() );
		self::assertSame( 'rule', $compiled->notes()[0]['source'] );
	}

	/**
	 * A comparison over a shape that cannot express it is refused.
	 *
	 * @return void
	 */
	public function test_a_comparison_the_shape_cannot_express_is_refused(): void {
		$compiled = $this->compiler->compile(
			$this->definition(
				array(
					'source'   => 'country',
					'operator' => 'greater_than',
					'value'    => 5,
				)
			)
		);

		self::assertFalse( $compiled->is_compiled() );
		self::assertStringContainsString( 'cannot be expressed', $compiled->notes()[0]['reason'] );
	}
}
