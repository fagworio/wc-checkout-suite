<?php
/**
 * Core normalizer and mask registration.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Validation\Normalizers\GenericNormalizer;

/**
 * Registers the normalizer primitives and the declarative mask primitives.
 *
 * Scope note: this registers infrastructure only. The Brazilian documents —
 * CPF, CNPJ numeric and alphanumeric, RG, CEP, landline and mobile phone — and
 * their exact masks are delivered by WCCS-026 (presets), WCCS-027 (mask
 * integration) and WCCS-028 (validators), and are deliberately absent here so
 * that nothing claims validation it cannot yet perform.
 *
 * No renderer is registered either. A renderer is a claim that an adapter can
 * actually present a type; those claims are made in F04 (Classic) and F07
 * (Blocks) when the renderers exist.
 *
 * @see \ROADMAP.md sections 5, 6, 9 and 26
 */
final class CoreProcessing {

	/**
	 * Registers the normalizer primitives.
	 *
	 * @param NormalizerRegistry $normalizers Normalizer registry.
	 * @return void
	 */
	public static function register_normalizers( NormalizerRegistry $normalizers ): void {
		$normalizers->register_normalizer( new GenericNormalizer( 'trim', 'trim' ) );
		$normalizers->register_normalizer( new GenericNormalizer( 'digits', 'digits' ) );
		$normalizers->register_normalizer( new GenericNormalizer( 'uppercase', 'uppercase' ) );
		$normalizers->register_normalizer( new GenericNormalizer( 'lowercase', 'lowercase' ) );
		$normalizers->register_normalizer( new GenericNormalizer( 'single_spaces', 'single_spaces' ) );
	}

	/**
	 * Registers the declarative mask primitives.
	 *
	 * @param MaskRegistry $masks Mask registry.
	 * @return void
	 */
	public static function register_masks( MaskRegistry $masks ): void {
		$masks->register_mask(
			new Mask(
				'numeric',
				array(
					'type'    => 'pattern',
					'pattern' => '0',
					'lazy'    => true,
				),
				1,
				array( 'generic' )
			)
		);

		$masks->register_mask(
			new Mask(
				'alphanumeric',
				array(
					'type'    => 'pattern',
					'pattern' => '*',
					'lazy'    => true,
				),
				1,
				array( 'generic' )
			)
		);
	}
}
