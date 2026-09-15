<?php
/**
 * The published schema, as the storefront reads it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Checkout\CheckoutProfileResolver;
use WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

/**
 * Reads the published document for the storefront.
 *
 * Two things live here rather than in each caller.
 *
 * The first is the slot. The draft is written by the admin, is full of
 * half-finished work, and reaching it from the storefront would mean a merchant
 * editing a label changes what a customer sees before pressing publish — which
 * is the promise the whole draft/publish split exists to keep. A single reader
 * that can only name `SLOT_PUBLISHED` is the smallest way to keep that true.
 *
 * The second is when the repository is built. Its definition validator needs the
 * list of checkout fields WooCommerce owns, and that list cannot be read at
 * `plugins_loaded`: WooCommerce builds its countries object later, and reading
 * the fields before that is a fatal error rather than an exception.
 *
 * @see ROADMAP.md section 7
 */
final class PublishedDocument {

	/**
	 * Reads the published schema document.
	 *
	 * Built at the point of use rather than held in a property or a static, so
	 * the timing is correct by construction: every caller is a hook that fires
	 * long after WooCommerce has finished starting.
	 *
	 * Deliberately not memoised. The document is an option, so the read is a
	 * cache hit, and a memo would return a stale document to any caller that
	 * publishes and reads again inside one process — which is exactly what the
	 * integration proofs do.
	 *
	 * @return SchemaDocument
	 */
	public static function read(): SchemaDocument {
		$repository = new SchemaRepository(
			Registries::instance()->definition_validator(),
			new CoreFieldGuard()
		);

		return $repository->read( SchemaRepository::SLOT_PUBLISHED );
	}

	/**
	 * Reads the published schema as one cart is served by it.
	 *
	 * Section 1844 puts profile selection **before** the effective schema is assembled, so this is
	 * the reader every cart-scoped surface uses: it resolves the profile against the trusted cart
	 * context the server holds (§11) and returns the composition that follows.
	 *
	 * What it is not is a different document. The fields are the published ones, the store's own
	 * composition is still the answer when no profile matches, and a store that never wrote a
	 * profile pays one `count()` for the call. `read()` stays for every reader that is not serving a
	 * cart — the order panel, the emails, the admin screens — because those are not the checkout and
	 * a profile has nothing to say about them.
	 *
	 * @param string $adapter Checkout asking.
	 * @return SchemaDocument
	 */
	public static function for_cart( string $adapter = 'classic' ): SchemaDocument {
		$document = self::read();

		if ( array() === $document->profiles() ) {
			return $document;
		}

		$context = ( new CheckoutConditionContext() )->context( array(), $adapter );
		$profile = CheckoutProfileResolver::resolve( $document->profiles(), $context );

		return CheckoutProfileResolver::compose( $document, $profile );
	}
}
