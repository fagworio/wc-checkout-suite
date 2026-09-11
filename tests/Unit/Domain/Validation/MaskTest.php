<?php
/**
 * Mask declaration tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Validation;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Validation\Mask;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;

/**
 * Covers the guarantee that a mask is data and never executable code.
 */
final class MaskTest extends TestCase {

	/**
	 * A pattern mask is declarative.
	 *
	 * @return void
	 */
	public function test_pattern_mask_is_declarative(): void {
		$mask = new Mask( 'br.cep', '00000-000', 1, array( 'br' ) );

		self::assertTrue( $mask->is_declarative() );
		self::assertSame( 1, $mask->version() );
	}

	/**
	 * A configuration array of allowed keys is declarative.
	 *
	 * @return void
	 */
	public function test_configuration_array_of_allowed_keys_is_declarative(): void {
		$mask = new Mask(
			'numeric',
			array(
				'type'    => 'pattern',
				'pattern' => '0',
				'lazy'    => true,
			)
		);

		self::assertTrue( $mask->is_declarative() );
	}

	/**
	 * A format written with parentheses is declarative.
	 *
	 * Parentheses are literal characters in a written format — the Brazilian
	 * telephone mask is `(00) 00000-0000` — and a guard that reads them as
	 * code-shaped makes a correct definition impossible to register. The two
	 * definitions below that *are* code still fail, which is what the guard is
	 * for.
	 *
	 * @return void
	 */
	public function test_a_format_with_parentheses_is_declarative(): void {
		self::assertTrue( ( new Mask( 'br.phone.mobile', '(00) 00000-0000' ) )->is_declarative() );
		self::assertTrue( ( new Mask( 'br.phone.landline', '(00) 0000-0000' ) )->is_declarative() );
	}

	/**
	 * A definition that looks like code is not declarative.
	 *
	 * @return void
	 */
	public function test_code_like_definition_is_not_declarative(): void {
		self::assertFalse( ( new Mask( 'evil', 'javascript:alert(1);' ) )->is_declarative() );
		self::assertFalse( ( new Mask( 'evil', '() => 1' ) )->is_declarative() );
		self::assertFalse( ( new Mask( 'evil', 'function(){}' ) )->is_declarative() );
	}

	/**
	 * An unexpected configuration key is not declarative.
	 *
	 * @return void
	 */
	public function test_unexpected_configuration_key_is_not_declarative(): void {
		self::assertFalse(
			( new Mask(
				'evil',
				array(
					'type'       => 'pattern',
					'onComplete' => 'x',
				)
			) )->is_declarative()
		);
	}

	/**
	 * Nesting deeper than the allowed depth is refused.
	 *
	 * @return void
	 */
	public function test_deep_nesting_is_refused(): void {
		$nested = array( 'blocks' => array( array( 'blocks' => array( array( 'blocks' => array( array( 'blocks' => array( array( 'blocks' => array() ) ) ) ) ) ) ) ) );

		self::assertFalse( ( new Mask( 'deep', $nested ) )->is_declarative() );
	}

	/**
	 * The registry refuses a non-declarative mask and reports why.
	 *
	 * @return void
	 */
	public function test_registry_refuses_non_declarative_mask(): void {
		$registry = new MaskRegistry();

		$accepted = $registry->register_mask( new Mask( 'evil', 'javascript:alert(1);' ), 'evil-plugin' );

		self::assertFalse( $accepted );
		self::assertFalse( $registry->has( 'evil' ) );
		self::assertSame( 'non_declarative_mask', $registry->diagnostics()[0]['code'] );
	}

	/**
	 * The exported payload contains data only.
	 *
	 * @return void
	 */
	public function test_export_contains_data_only(): void {
		$registry = new MaskRegistry();
		$registry->register_mask(
			new Mask(
				'numeric',
				array(
					'type'    => 'pattern',
					'pattern' => '0',
				)
			)
		);

		$encoded = (string) wp_json_encode( $registry->to_array() );

		self::assertStringNotContainsString( 'function', $encoded );
		self::assertStringContainsString( 'numeric', $encoded );
	}
}
