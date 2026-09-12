<?php
/**
 * Classic presentation tests.
 *
 * The acceptance has two clauses and both are properties of a stylesheet, which is
 * why they are checked by reading it as a stylesheet rather than by opening a
 * browser: the layout is spaced by `gap` and not by margins between siblings, and
 * every value it uses is a token that exists.
 *
 * The first clause is the defect section 17 records — `.field + .field {
 * margin-top: 9px; }` misaligning the second element inside a grid — and it is
 * asserted as an absence, because a rule that set those margins to zero would still
 * be a rule that had them.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Classic;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Classic\ClassicAssets;

/**
 * The presentation stylesheet.
 */
final class ClassicPresentationTest extends TestCase {

	/**
	 * The stylesheet.
	 *
	 * @var string
	 */
	private string $css = '';

	/**
	 * Reads a file this plugin ships.
	 *
	 * The sniff suppressed here exists to keep a request from reaching out to a URL
	 * it was handed. Every path this test reads is a local, versioned file that sits
	 * beside the code reading it, and the two other tests in this class read their
	 * inputs the same way rather than repeating the suppression.
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

		$this->css = $this->shipped( 'resources/checkout/presentation.css' );

		self::assertNotSame( '', $this->css, 'the presentation stylesheet is readable and not empty' );
	}

	/**
	 * The rules, without the comments that explain them.
	 *
	 * The comments quote the defect this file avoids — `margin-top: 9px` appears in
	 * the header — so a test that searched the file would find the very thing it is
	 * looking for in the prose.
	 *
	 * @return string
	 */
	private function rules(): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $this->css );
	}

	/**
	 * Every selector is scoped to the checkout.
	 *
	 * @return void
	 */
	public function test_every_selector_is_scoped_to_the_checkout(): void {
		$css       = $this->rules();
		$unscoped  = array();
		$selectors = array();

		preg_match_all( '/([^{}]+)\{/', $css, $matches );

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

			if ( ! str_contains( $one, '.' . ClassicAssets::SCOPE_CLASS ) ) {
				$unscoped[] = $one;
			}
		}

		self::assertSame( array(), $unscoped, 'a rule outside the scope can reach another page' );
		self::assertGreaterThan( 20, count( $selectors ) );
	}

	/**
	 * Nothing sets a margin between sibling fields.
	 *
	 * This is the clause the acceptance is about, asserted as an absence.
	 *
	 * @return void
	 */
	public function test_no_rule_spaces_siblings_with_a_margin(): void {
		$css = $this->rules();

		// No spacing is expressed as a margin anywhere: every spacing value in this
		// file is a `gap`. The values are read in PHP rather than matched by a
		// pattern, because a pattern that tried to allow `margin: 0` kept finding it
		// in the whitespace before the zero.
		preg_match_all( '/margin[a-z-]*\s*:\s*([^;}]+)/', $css, $matches );

		$not_zero = array();

		foreach ( $matches[1] as $value ) {
			if ( '0' !== trim( $value ) ) {
				$not_zero[] = trim( $value );
			}
		}

		self::assertSame( array(), $not_zero, 'the spacing comes from gap, not from a margin' );
		self::assertGreaterThan( 0, count( $matches[1] ) );
	}

	/**
	 * The spacing comes from `gap`, on the container and on the wrappers.
	 *
	 * @return void
	 */
	public function test_the_spacing_is_a_gap_on_the_container(): void {
		$css = $this->rules();

		self::assertMatchesRegularExpression(
			'/\.wccs-checkout\s+\.woocommerce-billing-fields__field-wrapper[^{}]*\{[^}]*gap:\s*var\(--wccs-checkout-gap\)/s',
			$css
		);

		self::assertMatchesRegularExpression(
			'/\.wccs-checkout\s+form\.checkout\s*\{[^}]*gap:\s*var\(--wccs-checkout-gap\)/s',
			$css
		);

		self::assertStringContainsString( '--wccs-checkout-gap: var(--wccs-space-16)', $css );
	}

	/**
	 * The width classes the adapter emits are the ones the stylesheet interprets.
	 *
	 * WCCS-021 emits `wccs-col-3` and `wccs-col-4` for the widths the classic grid
	 * has no class for, and said the stylesheet that interprets them would be this
	 * one. If the two lists ever disagree, a field is emitted with a class nothing
	 * reads — which is a field that silently takes the full width.
	 *
	 * @return void
	 */
	public function test_the_width_classes_are_the_ones_the_adapter_emits(): void {
		$css = $this->rules();

		self::assertStringContainsString( '.wccs-checkout .wccs-col-4', $css );
		self::assertStringContainsString( '.wccs-checkout .wccs-col-3', $css );
		self::assertStringContainsString( 'grid-column: span 12', $css );

		$adapter = $this->shipped( 'src/Checkout/Classic/ClassicAdapter.php' );

		self::assertStringContainsString( 'wccs-col-', $adapter, 'the adapter still emits the classes this file styles' );
	}

	/**
	 * Every token the stylesheet reads exists in the tokens file.
	 *
	 * A stylesheet that used a variable nobody defines renders with its fallback and
	 * looks almost right, which is the kind of difference that survives review.
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
			// The two the presentation defines for itself are its own.
			if ( in_array( $used, array( '--wccs-checkout-gap', '--wccs-checkout-field-gap' ), true ) ) {
				continue;
			}

			if ( ! in_array( $used, $declared, true ) ) {
				$missing[] = $used;
			}
		}

		self::assertSame( array(), $missing );
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
	 * The layout has a small-screen rule of its own.
	 *
	 * @return void
	 */
	public function test_the_small_screen_layout_is_one_column(): void {
		$css = $this->rules();

		self::assertMatchesRegularExpression( '/@media\s*\(max-width:\s*599px\)/', $css );
		self::assertMatchesRegularExpression(
			'/@media\s*\(max-width:\s*599px\)\s*\{[^@]*\.wccs-col-3\s*\{[^}]*span 12/s',
			$css,
			'below the narrow breakpoint a three column field is a full width one'
		);
	}

	/**
	 * The scope class goes on the checkout and nowhere else.
	 *
	 * The stylesheet is written under the scope, so this decides which requests the
	 * presentation can reach at all. It is the same gate that enqueues the file: a
	 * checkout given one and not the other is a checkout handed a stylesheet that
	 * does nothing, and the two are deliberately one decision.
	 *
	 * @return void
	 */
	public function test_the_scope_class_marks_the_checkout_and_nothing_else(): void {
		$base = array( 'home', 'woocommerce', 'woocommerce-checkout' );

		self::assertContains( ClassicAssets::SCOPE_CLASS, ClassicAssets::add_scope( $base, true, true ) );
		self::assertSame( $base, ClassicAssets::add_scope( $base, false, true ), 'another page is left as the theme left it' );
		self::assertSame( $base, ClassicAssets::add_scope( $base, true, false ), 'a checkout with nothing to render is not marked' );
	}

	/**
	 * The class is added once, however many times the filter runs.
	 *
	 * @return void
	 */
	public function test_the_scope_class_is_not_added_twice(): void {
		$once  = ClassicAssets::add_scope( array( 'home' ), true, true );
		$twice = ClassicAssets::add_scope( $once, true, true );

		self::assertSame( $once, $twice );
		self::assertCount( 1, array_keys( $twice, ClassicAssets::SCOPE_CLASS, true ) );
	}

	/**
	 * The stylesheet is written under that same class.
	 *
	 * Both halves are asserted against the constant rather than against the string,
	 * so a rename that moved only one of them fails here instead of leaving the
	 * presentation scoped to a class nothing adds.
	 *
	 * @return void
	 */
	public function test_the_stylesheet_is_written_under_that_scope(): void {
		self::assertStringContainsString( '.' . ClassicAssets::SCOPE_CLASS . ' ', $this->rules() );
	}

	/**
	 * The disclosure honours `[hidden]`, and the names on both sides agree.
	 *
	 * `display: flex` on an element whose visibility is an attribute is how a control
	 * ends up on a page it was hidden from, so the rule states it explicitly. And the
	 * two names this file reads — the attribute the payment frame puts on a gateway's
	 * panel and the class the summary control carries — are read out of the modules
	 * that write them, so a rename that moved only one side fails here instead of
	 * leaving a rule that styles nothing.
	 *
	 * @return void
	 */
	public function test_the_disclosure_honours_hidden_and_the_names_agree(): void {
		$css = $this->rules();

		self::assertMatchesRegularExpression(
			'/\.wccs-summary__toggle\[hidden\]\s*\{[^}]*display:\s*none/s',
			$css
		);

		self::assertStringContainsString(
			'data-wccs-payment-panel',
			$this->shipped( 'resources/checkout/payment.js' ),
			'the attribute this file styles is the one the payment frame writes'
		);

		self::assertStringContainsString(
			"'wccs-summary__toggle'",
			$this->shipped( 'resources/checkout/summary.js' ),
			'the class this file styles is the one the summary control carries'
		);

		self::assertStringContainsString( '.wccs-summary__toggle:focus-visible', $css );
	}

	/**
	 * No template is shipped, which is what keeps every hook in place.
	 *
	 * @return void
	 */
	public function test_no_woocommerce_template_is_overridden(): void {
		$candidates = array(
			dirname( __DIR__, 4 ) . '/templates',
			dirname( __DIR__, 4 ) . '/woocommerce',
		);

		foreach ( $candidates as $path ) {
			self::assertDirectoryDoesNotExist( $path, 'a template override would take the hooks away from whoever renders them' );
		}
	}
}
