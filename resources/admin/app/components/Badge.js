/**
 * Badges.
 *
 * Badges in this product always state something in words. A badge that only
 * changes colour would be unreadable to anyone who cannot distinguish it, and
 * the compatibility badge in particular exists to replace a promise with an
 * explicit statement of what is and is not supported.
 */

import { __ } from '@wordpress/i18n';

/**
 * Generic badge.
 *
 * @param {Object} props          Component properties.
 * @param {string} props.tone     `neutral`, `brand`, `success`, `warning` or `danger`.
 * @param {*}      props.children Badge content.
 */
export function Badge( { tone = 'neutral', children } ) {
	return (
		<span className={ `wccs-badge wccs-badge--${ tone }` }>
			{ children }
		</span>
	);
}

/**
 * Labels for the field status.
 *
 * @type {Record<string, string>}
 */
const STATUS_LABELS = {
	active: __( 'Active', 'wc-checkoutsuite' ),
	inactive: __( 'Inactive', 'wc-checkoutsuite' ),
	draft: __( 'Draft', 'wc-checkoutsuite' ),
	published: __( 'Published', 'wc-checkoutsuite' ),
	changed: __( 'Unsaved changes', 'wc-checkoutsuite' ),
	archived: __( 'Archived', 'wc-checkoutsuite' ),
	error: __( 'Error', 'wc-checkoutsuite' ),
};

/**
 * Tones paired with each field status.
 *
 * @type {Record<string, string>}
 */
const STATUS_TONES = {
	active: 'success',
	published: 'success',
	inactive: 'neutral',
	archived: 'neutral',
	draft: 'warning',
	changed: 'warning',
	error: 'danger',
};

/**
 * Status badge.
 *
 * @param {Object} props        Component properties.
 * @param {string} props.status Status identifier.
 */
export function StatusBadge( { status } ) {
	return (
		<Badge tone={ STATUS_TONES[ status ] ?? 'neutral' }>
			{ STATUS_LABELS[ status ] ?? status }
		</Badge>
	);
}

/**
 * Labels for the adapter compatibility matrix, from ROADMAP.md section 8.
 *
 * @type {Record<string, string>}
 */
const COMPATIBILITY_LABELS = {
	native: __( 'Native', 'wc-checkoutsuite' ),
	suite: __( 'Suite component', 'wc-checkoutsuite' ),
	limited: __( 'Limited', 'wc-checkoutsuite' ),
	unsupported: __( 'Unsupported', 'wc-checkoutsuite' ),
};

/**
 * Tones for the compatibility matrix.
 *
 * @type {Record<string, string>}
 */
const COMPATIBILITY_TONES = {
	native: 'success',
	suite: 'brand',
	limited: 'warning',
	unsupported: 'neutral',
};

/**
 * Compatibility badge.
 *
 * The reason is not optional decoration: the planning requires the admin to show
 * "Nativo", "Componente da Suite", "Limitado" or "Não suportado" **with a
 * specific reason**, so the label alone is never presented as the whole answer.
 *
 * @param {Object} props          Component properties.
 * @param {string} props.level    `native`, `suite`, `limited` or `unsupported`.
 * @param {string} [props.reason] Why the level applies.
 */
export function CompatibilityBadge( { level, reason } ) {
	return (
		<span className="wccs-compatibility">
			<Badge tone={ COMPATIBILITY_TONES[ level ] ?? 'neutral' }>
				{ COMPATIBILITY_LABELS[ level ] ?? level }
			</Badge>
			{ reason ? (
				<span className="wccs-compatibility__reason">{ reason }</span>
			) : null }
		</span>
	);
}
