<?php
/**
 * Blocks presentation tests.
 *
 * The acceptance has two clauses and this class is about the second half of each.
 * The layout is a property of a stylesheet, so it is read as a stylesheet: spaced by
 * `gap` and never by a margin between siblings, sized from tokens that exist, with
 * the focus ring kept. The regions are the ones the platform gives this plugin, so
 * every selector is qualified by the wrapper its own components render.
 *
 * The clause that matters most here is the one about what this file may *not* touch.
 * The Blocks checkout is WooCommerce's page: the field blocks, the order summary and
 * the payment area are drawn by the platform, and a plugin that restyled them would
 * be styling markup it does not own and was not promised. That is asserted as an
 * absence — no selector reaches a WooCommerce class, and none names payment — which
 * is a stronger statement than any list of things this file does style.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Blocks;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Blocks\BlocksRenderer;

/**
 * The Blocks presentation stylesheet.
 */
final class BlocksPresentationTest extends TestCase {

	/**
	 * The class every component of this plugin renders.
	 */
	private const SCOPE = 'wccs-blocks-field';

	/**
	 * The stylesheet.
	 *
	 * @var string
	 */
	private string $css = '';

	/**
	 * Reads a file this plugin ships.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function shipped( string $relative ): string {
		$path = dirname( __DIR__, 4 ) . '/' . $relative;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin; the sniff targets remote URLs.
		$raw = is_readable( $path ) ? file_get_contents( $path ) : '';

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Reads the stylesheet once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->css = $this->shipped( BlocksRenderer::STYLE_FILE );

		self::assertNotSame( '', $this->css, 'the Blocks presentation is readable and not empty' );
	}

	/**
	 * The rules, without the comments that explain them.
	 *
	 * The comments quote the failure modes this file avoids — the word payment
	 * appears in the header — so a test that searched the whole file would find the
	 * very thing it is looking for in the prose.
	 *
	 * @return string
	 */
	private function rules(): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $this->css );
	}

	/**
	 * Every selector is qualified by the region this plugin renders.
	 *
	 * @return void
	 */
	public function test_every_selector_is_scoped_to_the_region(): void {
		$unscoped  = array();
		$selectors = array();

		preg_match_all( '/([^{}]+)\{/', $this->rules(), $matches );

		foreach ( $matches[1] as $selector ) {
			$selector = trim( $selector );

			if ( '' === $selector || str_starts_with( $selector, '@' ) ) {
				continue;
			}

			foreach ( explode( ',', $selector ) as $one ) {
				$selectors[] = trim( $one );
			}
		}

		foreach ( $selectors as $one ) {
			if ( '' === $one ) {
				continue;
			}

			if ( ! str_contains( $one, '.' . self::SCOPE ) ) {
				$unscoped[] = $one;
			}
		}

		self::assertSame( array(), $unscoped, 'a rule outside the region can reach WooCommerce markup this plugin does not own' );
		self::assertGreaterThan( 10, count( $selectors ) );
	}

	/**
	 * Nothing inside the region name anything WooCommerce drew.
	 *
	 * The Blocks checkout marks its own work with `wc-block` and `woocommerce`
	 * class names. Reaching one of them from here would be restyling a field block
	 * or a payment component — which is the acceptance's "não clona campos nem
	 * componentes de pagamento" read as what this file may do.
	 *
	 * @return void
	 */
	public function test_no_selector_reaches_a_woocommerce_class(): void {
		$reached = array();

		preg_match_all( '/([^{}]+)\{/', $this->rules(), $matches );

		foreach ( $matches[1] as $selector ) {
			if ( preg_match( '/\.(wc-block|woocommerce|wp-block)/i', $selector, $found ) ) {
				$reached[] = $found[0];
			}
		}

		self::assertSame( array(), $reached, 'the Blocks page belongs to WooCommerce; this file styles the region it was given' );
	}

	/**
	 * Nothing in it is about payment.
	 *
	 * The payment area of the Blocks checkout is the platform's, and this plugin
	 * collects no card data of its own. A stylesheet that named a payment class
	 * would be the first half of taking that over.
	 *
	 * @return void
	 */
	public function test_nothing_in_it_is_about_payment(): void {
		self::assertDoesNotMatchRegularExpression(
			'/payment|card|cvc|cvv|credit/i',
			$this->rules(),
			'no rule may name the payment area or a card field'
		);
	}

	/**
	 * The spacing comes from `gap`, and no rule spaces siblings with a margin.
	 *
	 * @return void
	 */
	public function test_no_rule_spaces_siblings_with_a_margin(): void {
		preg_match_all( '/margin[a-z-]*\s*:\s*([^;}]+)/', $this->rules(), $matches );

		$not_zero = array();

		foreach ( $matches[1] as $value ) {
			if ( '0' !== trim( $value ) ) {
				$not_zero[] = trim( $value );
			}
		}

		self::assertSame( array(), $not_zero, 'the spacing comes from gap, not from a margin' );
		self::assertGreaterThan( 0, count( $matches[1] ) );

		self::assertMatchesRegularExpression(
			'/\.wccs-blocks-field\s*\{[^}]*gap:\s*var\(--wccs-checkout-field-gap\)/s',
			$this->rules()
		);
	}

	/**
	 * The width of the region is asked of the region, not of the window.
	 *
	 * The same checkout places these fields in a wide column and in a narrow order
	 * sidebar, so a media query would answer a question about the wrong element.
	 *
	 * @return void
	 */
	public function test_it_responds_to_the_region_and_not_the_window(): void {
		$css = $this->rules();

		self::assertStringContainsString( 'container-type: inline-size', $css );
		self::assertMatchesRegularExpression( '/@container\s*\(max-width:\s*360px\)/', $css );
		self::assertDoesNotMatchRegularExpression( '/@media/', $css, 'the region is the container, so the viewport is not consulted' );
	}

	/**
	 * The focus ring is never removed.
	 *
	 * @return void
	 */
	public function test_the_focus_ring_is_never_removed(): void {
		self::assertDoesNotMatchRegularExpression( '/outline:\s*(none|0)/', $this->rules() );
		self::assertStringContainsString( 'var(--wccs-focus-ring)', $this->css );
	}

	/**
	 * Every token the stylesheet reads exists in the tokens file.
	 *
	 * @return void
	 */
	public function test_every_token_it_reads_exists(): void {
		$tokens = $this->shipped( 'resources/design-tokens/tokens.css' );

		self::assertNotSame( '', $tokens );

		preg_match_all( '/var\((--wccs-[a-z0-9-]+)/', $this->rules(), $matches );
		preg_match_all( '/(--wccs-[a-z0-9-]+)\s*:/', $tokens, $declared );

		$declared = array_unique( $declared[1] );
		$missing  = array();

		foreach ( array_unique( $matches[1] ) as $used ) {
			// The one the presentation defines for itself is its own.
			if ( '--wccs-checkout-field-gap' === $used ) {
				continue;
			}

			if ( ! in_array( $used, $declared, true ) ) {
				$missing[] = $used;
			}
		}

		self::assertSame( array(), $missing );
	}

	/**
	 * The tokens are declared on the scope this presentation renders.
	 *
	 * A scope the stylesheet reads variables on and no file declares them on is a
	 * presentation that renders with its fallbacks — and on the Blocks checkout the
	 * classic scope is not on the page, so it cannot lend them.
	 *
	 * @return void
	 */
	public function test_the_tokens_are_declared_on_this_scope(): void {
		$tokens = $this->shipped( 'resources/design-tokens/tokens.css' );

		preg_match_all( '/([^{}]+)\{/', (string) preg_replace( '#/\*.*?\*/#s', '', $tokens ), $matches );

		$scopes = array();

		foreach ( $matches[1] as $selector ) {
			foreach ( explode( ',', $selector ) as $one ) {
				$scopes[] = trim( $one );
			}
		}

		self::assertContains( '.' . self::SCOPE, $scopes, 'the tokens are declared on the region this file renders' );
	}

	/**
	 * The class every rule is written under is the one the components render.
	 *
	 * Read out of the bundle's own source, because a stylesheet scoped to a class
	 * nothing renders is a stylesheet that never applies — the defect WCCS-046 found
	 * in the classic presentation, one checkout over.
	 *
	 * @return void
	 */
	public function test_the_scope_is_the_class_the_components_render(): void {
		$fields = $this->shipped( 'resources/blocks/fields.js' );

		self::assertNotSame( '', $fields );
		self::assertMatchesRegularExpression(
			'/className=["\']' . preg_quote( self::SCOPE, '/' ) . '["\']/',
			$fields,
			'the wrapper the components render carries the class every rule here is written under'
		);
	}
}
