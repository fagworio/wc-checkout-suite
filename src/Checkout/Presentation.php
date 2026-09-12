<?php
/**
 * The presentation both checkouts are given.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout;

/**
 * Delivers a checkout stylesheet together with the tokens it reads.
 *
 * The classic checkout and the Blocks checkout are two presentations of the same
 * design, written as two files because the markup they style is not the same markup.
 * What they share is the rule this class exists to hold in one place: the tokens are
 * enqueued and declared as a dependency of the stylesheet, so the custom properties
 * a file reads exist before it reads them.
 *
 * The dependency is not a formality. A stylesheet that loaded before the variables
 * it uses renders with its fallbacks — the values are all there, the layout is
 * nearly right, and nobody notices until a merchant changes a token and nothing
 * moves. Declaring the dependency is what makes the order the server's decision
 * rather than the browser's.
 *
 * Both files are served from `resources/` rather than from the build, so the
 * presentation does not depend on `npm run build` having run. A file that arrives
 * with the JavaScript is a layout the customer does not get until the bundle has
 * executed, and on a slow connection that is a checkout arranged differently from
 * the one the merchant approved.
 */
final class Presentation {

	/**
	 * Handle of the design tokens the presentations read.
	 */
	public const TOKENS_HANDLE = 'wccs-design-tokens';

	/**
	 * The tokens' file, relative to the plugin root.
	 */
	public const TOKENS_FILE = 'resources/design-tokens/tokens.css';

	/**
	 * Enqueues one stylesheet with the tokens as its declared dependency.
	 *
	 * Returns whether the stylesheet was enqueued, which is also whether the file is
	 * there: a handle pointing at a file that does not exist puts a 404 in the
	 * checkout and changes nothing else, so it is not enqueued at all.
	 *
	 * @param string $handle   Style handle.
	 * @param string $relative Path relative to the plugin root.
	 * @return bool
	 */
	public static function enqueue( string $handle, string $relative ): bool {
		if ( ! self::is_readable( $relative ) ) {
			return false;
		}

		$dependencies = array();

		if ( self::enqueue_tokens() ) {
			$dependencies[] = self::TOKENS_HANDLE;
		}

		wp_enqueue_style( $handle, WCCS_PLUGIN_URL . $relative, $dependencies, WCCS_VERSION );

		return true;
	}

	/**
	 * Enqueues the tokens once, for whichever presentation asks first.
	 *
	 * The two checkouts are mutually exclusive on a given request, so this is about
	 * a store that somehow enqueues both — a shortcode in a template, a page that is
	 * both — and about a second call being a no-op rather than a second registration
	 * with the same handle.
	 *
	 * @return bool Whether the tokens are on the request.
	 */
	public static function enqueue_tokens(): bool {
		if ( ! self::is_readable( self::TOKENS_FILE ) ) {
			return false;
		}

		if ( wp_style_is( self::TOKENS_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::TOKENS_HANDLE );

			return true;
		}

		wp_enqueue_style( self::TOKENS_HANDLE, WCCS_PLUGIN_URL . self::TOKENS_FILE, array(), WCCS_VERSION );

		return true;
	}

	/**
	 * Whether a file this class delivers exists.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return bool
	 */
	private static function is_readable( string $relative ): bool {
		return is_readable( WCCS_PLUGIN_DIR . $relative );
	}
}
