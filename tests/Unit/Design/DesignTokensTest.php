<?php
/**
 * Design token tests.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Support\Contrast;
use WCCheckoutSuite\Support\DesignTokens;

/**
 * The gate behind the WCCS-011 acceptance: tokens exist for both themes, and no
 * documented pair has a failing contrast ratio.
 *
 * It also fails when the JSON and the CSS drift apart, and when a colour token
 * is added without a contrast decision, so the palette cannot quietly grow an
 * unverified colour.
 */
final class DesignTokensTest extends TestCase {

	/**
	 * Parsed CSS custom properties, keyed by theme.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $css = array();

	/**
	 * Parses the CSS once for the whole class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		DesignTokens::flush();

		$path = DesignTokens::path( DesignTokens::CSS_FILE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local stylesheet shipped with the plugin; the sniff targets remote URLs.
		$css = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		// Remove comments first: the header explains token names and would
		// otherwise be mistaken for declarations.
		$css = (string) preg_replace( '!/\*.*?\*/!s', '', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER );

		$blocks = array(
			'light' => array(),
			'dark'  => array(),
		);

		foreach ( $rules as $rule ) {
			$theme = str_contains( $rule[1], 'dark' ) ? 'dark' : 'light';

			preg_match_all( '/(--wccs-[a-z0-9-]+)\s*:\s*([^;]+);/', $rule[2], $declarations, PREG_SET_ORDER );

			foreach ( $declarations as $declaration ) {
				$blocks[ $theme ][ $declaration[1] ] = trim( $declaration[2] );
			}
		}

		self::$css = $blocks;
	}

	/**
	 * The token file loads and carries the expected top level sections.
	 *
	 * @return void
	 */
	public function test_token_file_has_the_expected_sections(): void {
		$tokens = DesignTokens::all();

		foreach ( array( 'version', 'wcag_target', 'themes', 'shared', 'contrast' ) as $key ) {
			self::assertArrayHasKey( $key, $tokens );
		}

		self::assertSame( '2.2 AA', $tokens['wcag_target'] );
	}

	/**
	 * Both themes exist and declare exactly the same colour token names.
	 *
	 * @return void
	 */
	public function test_both_themes_declare_the_same_colour_tokens(): void {
		$light = array_keys( DesignTokens::colors( 'light' ) );
		$dark  = array_keys( DesignTokens::colors( 'dark' ) );

		sort( $light );
		sort( $dark );

		self::assertNotEmpty( $light );
		self::assertSame( $light, $dark, 'a colour added to one theme must be added to the other' );
	}

	/**
	 * Every colour is an opaque six-digit hex value.
	 *
	 * @return void
	 */
	public function test_every_colour_is_opaque_hex(): void {
		foreach ( array( 'light', 'dark' ) as $theme ) {
			foreach ( DesignTokens::colors( $theme ) as $token => $value ) {
				self::assertTrue(
					Contrast::is_hex( $value ),
					sprintf( '%s.%s must be an opaque six-digit hex colour, got "%s"', $theme, $token, $value )
				);
			}
		}
	}

	/**
	 * No documented pair fails its threshold.
	 *
	 * @return void
	 */
	public function test_no_documented_pair_fails_its_threshold(): void {
		$requirements = DesignTokens::contrast_requirements();

		self::assertNotEmpty( $requirements );

		$failures = array();
		$tightest = array(
			'margin' => 22.0,
			'id'     => '',
			'note'   => '',
		);

		foreach ( $requirements as $requirement ) {
			$theme      = (string) $requirement['theme'];
			$foreground = DesignTokens::color( $theme, (string) $requirement['foreground'] );
			$background = DesignTokens::color( $theme, (string) $requirement['background'] );
			$minimum    = (float) $requirement['min'];

			self::assertNotNull( $foreground, sprintf( 'unknown foreground token in %s', $requirement['id'] ) );
			self::assertNotNull( $background, sprintf( 'unknown background token in %s', $requirement['id'] ) );

			$ratio = Contrast::ratio( $foreground, $background );

			if ( $ratio < $minimum ) {
				$failures[] = sprintf(
					'%s: %s on %s is %.2f:1, below the required %.1f:1',
					$requirement['id'],
					$foreground,
					$background,
					$ratio,
					$minimum
				);
			}

			// The meaningful metric is the margin over each pair's own threshold:
			// a UI boundary at 3.24:1 is comfortable against its 3.0 floor while
			// being far below a text pair's 4.5 floor.
			$margin = round( $ratio - $minimum, 2 );

			if ( $margin < $tightest['margin'] ) {
				$tightest = array(
					'margin' => $margin,
					'id'     => (string) $requirement['id'],
					'note'   => sprintf( '%.2f:1 against a %.1f:1 floor', $ratio, $minimum ),
				);
			}
		}

		self::assertSame( array(), $failures, implode( "\n", $failures ) );

		// Pins the thinnest margin, so a future token tweak that leaves a pair
		// barely passing is reported as a deliberate decision rather than a
		// surprise. The tightest pair today is recorded in the failure message.
		self::assertGreaterThanOrEqual(
			0.2,
			$tightest['margin'],
			sprintf( 'the tightest pair is %s (%s), leaving only %.2f of margin', $tightest['id'], $tightest['note'], $tightest['margin'] )
		);
	}

	/**
	 * Every colour token has a contrast decision: required or explicitly exempt.
	 *
	 * A token counts as covered when it appears on either side of a requirement,
	 * because a background is just as much a colour decision as a foreground.
	 *
	 * @return void
	 */
	public function test_every_colour_token_has_a_contrast_decision(): void {
		$covered = array();

		foreach ( DesignTokens::contrast_requirements() as $requirement ) {
			$covered[] = $requirement['theme'] . '.' . $requirement['foreground'];
			$covered[] = $requirement['theme'] . '.' . $requirement['background'];
		}

		$exempt = array();

		foreach ( DesignTokens::contrast_exemptions() as $exemption ) {
			$exempt[] = (string) $exemption['token'];
		}

		$uncovered = array();

		foreach ( array( 'light', 'dark' ) as $theme ) {
			foreach ( array_keys( DesignTokens::colors( $theme ) ) as $token ) {
				$is_tested = in_array( $theme . '.' . $token, $covered, true );
				$is_exempt = in_array( $token, $exempt, true );

				if ( ! $is_tested && ! $is_exempt ) {
					$uncovered[] = $theme . '.' . $token;
				}
			}
		}

		self::assertSame(
			array(),
			$uncovered,
			'a colour token needs either a contrast requirement or a documented exemption: ' . implode( ', ', $uncovered )
		);
	}

	/**
	 * The CSS declares every colour token with the value the JSON states.
	 *
	 * @return void
	 */
	public function test_css_matches_the_json_colours(): void {
		foreach ( array( 'light', 'dark' ) as $theme ) {
			foreach ( DesignTokens::colors( $theme ) as $token => $value ) {
				$variable = DesignTokens::css_variable( 'color', $token );

				self::assertArrayHasKey(
					$variable,
					self::$css[ $theme ],
					sprintf( '%s is missing from the %s CSS block', $variable, $theme )
				);
				self::assertSame(
					$value,
					self::$css[ $theme ][ $variable ],
					sprintf( '%s disagrees between JSON and CSS in the %s theme', $variable, $theme )
				);
			}
		}
	}

	/**
	 * The CSS publishes the shared numeric tokens the components rely on.
	 *
	 * @return void
	 */
	public function test_css_publishes_the_shared_tokens(): void {
		$shared = DesignTokens::shared();

		$expected = array(
			'--wccs-radius-field'             => $shared['radius']['field'] . 'px',
			'--wccs-radius-card'              => $shared['radius']['card'] . 'px',
			'--wccs-radius-dialog'            => $shared['radius']['dialog'] . 'px',
			'--wccs-size-field-height'        => $shared['size']['field_height'] . 'px',
			'--wccs-size-touch-target'        => $shared['size']['touch_target'] . 'px',
			'--wccs-size-focus-outline-width' => $shared['size']['focus_outline_width'] . 'px',
			'--wccs-text-body-admin'          => $shared['typography']['size']['body_admin'] . 'px',
			'--wccs-text-body-checkout'       => $shared['typography']['size']['body_checkout'] . 'px',
			'--wccs-text-help'                => $shared['typography']['size']['help'] . 'px',
			'--wccs-weight-bold'              => (string) $shared['typography']['weight']['bold'],
			'--wccs-leading-body'             => (string) $shared['typography']['line_height']['body'],
			'--wccs-motion-fast'              => $shared['motion']['fast'],
		);

		foreach ( $expected as $variable => $value ) {
			self::assertArrayHasKey( $variable, self::$css['light'], $variable . ' is not published in CSS' );
			self::assertSame( $value, self::$css['light'][ $variable ], $variable . ' disagrees with the JSON' );
		}

		foreach ( $shared['spacing']['scale'] as $step ) {
			self::assertArrayHasKey( '--wccs-space-' . $step, self::$css['light'] );
		}
	}

	/**
	 * The planning's own floor is present: field and action heights, and the
	 * system font stack, none of which may be quietly dropped.
	 *
	 * @return void
	 */
	public function test_planned_ergonomics_are_preserved(): void {
		$shared = DesignTokens::shared();

		self::assertSame( 48, $shared['size']['field_height'] );
		self::assertSame( 44, $shared['size']['touch_target'] );
		self::assertGreaterThanOrEqual( 44, $shared['size']['action_height_min'] );
		self::assertLessThanOrEqual( 48, $shared['size']['action_height_max'] );
		self::assertStringContainsString( 'system-ui', $shared['typography']['family_sans'] );
		self::assertSame( array( 4, 8, 12, 16, 24, 32, 48 ), $shared['spacing']['scale'] );
	}
}
