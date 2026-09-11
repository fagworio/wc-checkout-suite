<?php
/**
 * The published schema, as the storefront reads it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

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
}
