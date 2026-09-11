<?php
/**
 * The Brazilian documents: their normalizers and their masks.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Validation\Normalizers\BrazilianDocumentNormalizer;

/**
 * Registers the declarative layer of the Brazilian documents.
 *
 * Two registries are filled here and they answer different questions.
 *
 * The **normalizers** are the storage policy: what the value is once it stops
 * being what the customer typed. ADR-0003 fixes that policy — a string end to
 * end, letters kept in uppercase, leading zeros never truncated, only recognised
 * punctuation removed — and names `br.cnpj` as the single point authorised to
 * transform a document value.
 *
 * The **masks** are the typing aid, and nothing else. They are declared here as
 * data and applied by the checkout bundle in WCCS-027; a mask never validates
 * anything, and the value it helps produce is still normalized and checked on the
 * server.
 *
 * What is deliberately absent is validation. Check digits are WCCS-028's contract,
 * fixed against the official sources and by fixtures, and no preset here claims
 * one. A CPF field created today normalizes correctly and accepts a wrong
 * document, which is honest about what exists; the alternative — a validator that
 * looks like it checks and does not — is worse.
 *
 * @see \ROADMAP.md sections 5 and 9
 * @see \docs/adr/ADR-0003-cnpj-alphanumeric.md
 */
final class BrazilianDocuments {

	/**
	 * Registers the normalizers of the Brazilian documents.
	 *
	 * @param NormalizerRegistry $normalizers Normalizer registry.
	 * @return void
	 */
	public static function register_normalizers( NormalizerRegistry $normalizers ): void {
		$normalizers->register_normalizer(
			new BrazilianDocumentNormalizer( 'br.cpf', BrazilianDocumentNormalizer::MODE_PUNCTUATION )
		);

		// Uppercase: a lowercase CNPJ and an uppercase one are the same company,
		// and ADR-0003 fixes the stored form as uppercase.
		$normalizers->register_normalizer(
			new BrazilianDocumentNormalizer( 'br.cnpj', BrazilianDocumentNormalizer::MODE_PUNCTUATION_UPPERCASE )
		);

		$normalizers->register_normalizer(
			new BrazilianDocumentNormalizer( 'br.cep', BrazilianDocumentNormalizer::MODE_PUNCTUATION )
		);

		// Phone keeps its own key even though it removes the same punctuation
		// today: the Brazilian numbering plan can change without the postcode
		// rule changing, and a shared key would make that one edit for two
		// documents.
		$normalizers->register_normalizer(
			new BrazilianDocumentNormalizer( 'br.phone', BrazilianDocumentNormalizer::MODE_PUNCTUATION )
		);
	}

	/**
	 * Registers the masks the Brazilian presets are typed with.
	 *
	 * The CNPJ mask has twelve wildcard positions and two digit positions, which
	 * is ADR-0003's structure — twelve alphanumeric positions and two check
	 * digits — and is also what makes one mask serve both accepted formats
	 * instead of forcing the merchant to choose a numeric field that would
	 * truncate an alphanumeric document.
	 *
	 * RG has no mask on purpose. Its format depends on the issuing state and on
	 * the type of document, and inventing a national one was rejected in
	 * ADR-0003.
	 *
	 * @param MaskRegistry $masks Mask registry.
	 * @return void
	 */
	public static function register_masks( MaskRegistry $masks ): void {
		$masks->register_mask( new Mask( 'br.cpf', '000.000.000-00', 1, array( 'document' ) ) );
		$masks->register_mask( new Mask( 'br.cnpj', '**.***.***/****-00', 1, array( 'document' ) ) );
		$masks->register_mask( new Mask( 'br.cep', '00000-000', 1, array( 'postcode' ) ) );
		$masks->register_mask( new Mask( 'br.phone.landline', '(00) 0000-0000', 1, array( 'phone' ) ) );
		$masks->register_mask( new Mask( 'br.phone.mobile', '(00) 00000-0000', 1, array( 'phone' ) ) );
	}
}
