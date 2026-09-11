/**
 * Adapter capability matrix shown by the preview.
 *
 * The vocabulary is ROADMAP.md section 8: native, Suite component, limited and
 * unsupported — each attached to a specific reason, because a level without a
 * reason is an assertion nobody can check.
 *
 * The entries record what was verified against the installed stack
 * (WooCommerce 11.1.0) rather than what the planning assumed. Where the two
 * disagree, the verified behaviour wins and the divergence is stated here:
 *
 * - The additional-fields API registers `text`, `select` and `checkbox`. The
 *   planning also listed `date`, which WooCommerce 11.1.0 does not offer.
 * - That API is not a binary upload channel, so uploads are a Suite component
 *   that keeps the file private and puts a token on the order.
 *
 * Keeping the matrix in one module means the preview and, later, the settings
 * screen read the same statements instead of drifting apart.
 */

import { __ } from '@wordpress/i18n';

export default {
	classic: [
		{
			level: 'native',
			label: __( 'Suite field types', 'wc-checkoutsuite' ),
			reason: __(
				'Rendered through the official checkout field filter.',
				'wc-checkoutsuite'
			),
		},
		{
			level: 'suite',
			label: __( 'File upload', 'wc-checkoutsuite' ),
			reason: __(
				'A Suite component stores the file privately and keeps a token on the order.',
				'wc-checkoutsuite'
			),
		},
		{
			level: 'limited',
			label: __( 'Core field reordering', 'wc-checkoutsuite' ),
			reason: __(
				'Dependencies of shipping, tax and payment must be assessed before a core field moves.',
				'wc-checkoutsuite'
			),
		},
	],
	blocks: [
		{
			level: 'native',
			label: __( 'Text, select and checkbox', 'wc-checkoutsuite' ),
			reason: __(
				'Offered by the additional-fields API in WooCommerce 11.1.0.',
				'wc-checkoutsuite'
			),
		},
		{
			level: 'limited',
			label: __( 'Date field', 'wc-checkoutsuite' ),
			reason: __(
				'The planning lists a date type, but WooCommerce 11.1.0 registers only text, select and checkbox.',
				'wc-checkoutsuite'
			),
		},
		{
			level: 'limited',
			label: __( 'Document masks', 'wc-checkoutsuite' ),
			reason: __(
				'Validation is possible; masking depends on a supported extension point.',
				'wc-checkoutsuite'
			),
		},
		{
			level: 'unsupported',
			label: __( 'File upload', 'wc-checkoutsuite' ),
			reason: __(
				'The additional-fields API is not a binary upload channel.',
				'wc-checkoutsuite'
			),
		},
	],
};
