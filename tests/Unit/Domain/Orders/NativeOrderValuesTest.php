<?php
/**
 * Two authorities, one answer.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Orders;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Orders\NativeOrderValues;
use WCCheckoutSuite\Domain\Orders\OrderFieldValues;

/**
 * The values the platform stored, read where the Suite has nothing to say.
 *
 * Section 13 gives a natively rendered additional field one authority — WooCommerce's own
 * API — and the Suite's projections read it rather than keeping a second copy. What is under
 * test here is the rule that makes that safe: this storage wins wherever it has an answer,
 * and the platform fills in only where it does not.
 */
final class NativeOrderValuesTest extends TestCase {

	/**
	 * The Suite's own answer is not overwritten by the platform's.
	 *
	 * @return void
	 */
	public function test_this_storage_wins_where_it_answers(): void {
		$merged = NativeOrderValues::merge(
			array( 'documento_fiscal' => 'do pedido' ),
			array( 'documento_fiscal' => 'da plataforma' )
		);

		self::assertSame( 'do pedido', $merged['documento_fiscal'] );
	}

	/**
	 * An answer the Suite does not have is taken from the platform.
	 *
	 * @return void
	 */
	public function test_the_platform_fills_what_this_storage_does_not_have(): void {
		$merged = NativeOrderValues::merge(
			array( 'observacoes_entrega' => 'Deixar na portaria' ),
			array(
				'documento_fiscal' => '123.456.789-09',
				'codigo_retirada'  => 'RET-42',
			)
		);

		self::assertSame( 'Deixar na portaria', $merged['observacoes_entrega'] );
		self::assertSame( '123.456.789-09', $merged['documento_fiscal'] );
		self::assertSame( 'RET-42', $merged['codigo_retirada'] );
	}

	/**
	 * A key this storage carries but did not answer with is filled too — an empty string is
	 * how a payload says "nothing was answered", and it is the case the store was in.
	 *
	 * @return void
	 */
	public function test_a_key_without_an_answer_is_filled(): void {
		$merged = NativeOrderValues::merge(
			array(
				'observacoes_entrega' => '',
				'anexos'              => array(),
				'aceite'              => false,
				'nada'                => null,
			),
			array(
				'observacoes_entrega' => 'da plataforma',
				'anexos'              => array( 'token' ),
				'aceite'              => true,
				'nada'                => 'algo',
			)
		);

		self::assertSame( 'da plataforma', $merged['observacoes_entrega'] );
		self::assertSame( array( 'token' ), $merged['anexos'] );
		self::assertTrue( $merged['aceite'] );
		self::assertSame( 'algo', $merged['nada'] );
	}

	/**
	 * `0` and `'0'` are answers, and the platform does not get to replace them. It is the
	 * same rule the approval flow uses, from the same place.
	 *
	 * @return void
	 */
	public function test_zero_is_an_answer(): void {
		$merged = NativeOrderValues::merge(
			array(
				'quantidade' => 0,
				'codigo'     => '0',
			),
			array(
				'quantidade' => 5,
				'codigo'     => 'outro',
			)
		);

		self::assertSame( 0, $merged['quantidade'] );
		self::assertSame( '0', $merged['codigo'] );
	}

	/**
	 * The reader only fills; it never drops what this storage holds.
	 *
	 * @return void
	 */
	public function test_nothing_of_this_storage_is_lost(): void {
		$own = array(
			'a' => 'um',
			'b' => 'dois',
		);

		$merged = NativeOrderValues::merge( $own, array( 'c' => 'três' ) );

		self::assertSame( array( 'a', 'b', 'c' ), array_keys( $merged ) );
	}

	/**
	 * The rule for "an answer" is one rule, and it is the one the approval flow reads.
	 *
	 * @return void
	 */
	public function test_the_rule_for_an_answer_is_shared(): void {
		self::assertTrue( OrderFieldValues::is_answer( 'texto' ) );
		self::assertTrue( OrderFieldValues::is_answer( '0' ) );
		self::assertTrue( OrderFieldValues::is_answer( array( 'token' ) ) );
		self::assertTrue( OrderFieldValues::is_answer( 0 ) );

		self::assertFalse( OrderFieldValues::is_answer( '' ) );
		self::assertFalse( OrderFieldValues::is_answer( '   ' ) );
		self::assertFalse( OrderFieldValues::is_answer( array() ) );
		self::assertFalse( OrderFieldValues::is_answer( null ) );
		self::assertFalse( OrderFieldValues::is_answer( false ) );
	}
}
