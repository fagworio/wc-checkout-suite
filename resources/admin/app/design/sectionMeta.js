/**
 * The words and the glyphs the design gives each checkout location.
 *
 * The prototype draws five sections — Contato, Cobrança, Entrega, Pedido, Conta —
 * and each one carries two names: a short one for the tab and a longer one for the
 * panel's heading, plus a line saying what belongs there. Those are design copy, not
 * data, so they live here rather than in the document.
 *
 * A section the merchant creates has no entry: it falls back to its own title and
 * its own description, because the merchant wrote them, and inventing design copy
 * for a section nobody designed would be putting words in their mouth.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

/**
 * Design copy per checkout location.
 *
 * @type {Record<string, {label: string, title: string, description: string, icon: string}>}
 */
export const SECTION_DESIGN = {
	contact: {
		label: __( 'Contato', 'wc-checkoutsuite' ),
		title: __( 'Como falar com o cliente', 'wc-checkoutsuite' ),
		description: __(
			'E-mail e informações de contato.',
			'wc-checkoutsuite'
		),
		icon: 'mail',
	},
	billing: {
		label: __( 'Cobrança', 'wc-checkoutsuite' ),
		title: __( 'Dados de cobrança', 'wc-checkoutsuite' ),
		description: __(
			'Informações do cliente e documentos.',
			'wc-checkoutsuite'
		),
		icon: 'user',
	},
	shipping: {
		label: __( 'Entrega', 'wc-checkoutsuite' ),
		title: __( 'Endereço de entrega', 'wc-checkoutsuite' ),
		description: __(
			'Campos para endereçamento e envio.',
			'wc-checkoutsuite'
		),
		icon: 'location',
	},
	order: {
		label: __( 'Pedido', 'wc-checkoutsuite' ),
		title: __( 'Detalhes do pedido', 'wc-checkoutsuite' ),
		description: __(
			'Instruções, arquivos e informações adicionais.',
			'wc-checkoutsuite'
		),
		icon: 'file',
	},
	account: {
		label: __( 'Conta', 'wc-checkoutsuite' ),
		title: __( 'Preferências da conta', 'wc-checkoutsuite' ),
		description: __(
			'Campos complementares. Senhas ficam no fluxo nativo.',
			'wc-checkoutsuite'
		),
		icon: 'lock',
	},
};

/**
 * The design's copy for a section, or the section's own words.
 *
 * The location decides which entry applies, not the identifier: a merchant is free
 * to rename a section, and the tab should still call it what they called it.
 *
 * The distinction that matters is whether the merchant declared the section. The five
 * locations exist in every store whether or not anyone wrote them down — they are the
 * concepts the checkout already has — and for those the design's words apply. A section
 * someone created carries the words they typed, and the design does not overrule them.
 *
 * @param {{id: string, title?: string, description?: string, location?: string}} section  Section.
 * @param {boolean}                                                               declared Whether the document declares it.
 * @return {{label: string, title: string, description: string, icon: string}} Copy.
 */
export function sectionCopy( section, declared = true ) {
	const design = SECTION_DESIGN[ section.location ?? section.id ] ?? null;

	if ( ! declared && design ) {
		return design;
	}

	return {
		// A merchant-defined title is the section's public name. The logical
		// location remains metadata and must not replace it in tabs or selectors.
		label: section.title?.trim() || ( design ? design.label : section.id ),
		title: section.title || design?.title || section.id,
		description: section.description || design?.description || '',
		icon: design ? design.icon : 'fields',
	};
}
