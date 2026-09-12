<?php
/**
 * Uninstall policy.
 *
 * What this file decides is what deleting the plugin removes, and the answer is
 * deliberately narrow: **nothing that belongs to a customer, and nothing the merchant
 * configured.** ROADMAP.md section 27 is explicit — "Desativar o plugin não significa
 * apagar dados; uninstall explicita o que é removido" — and section 20 adds that a
 * historical order is not deleted by an uninstall.
 *
 * So this file cleans up what is only meaningful while the plugin is installed, and
 * leaves everything else in place:
 *
 *   - **Removed**: the scheduled retention event, so a deleted plugin leaves no job on
 *     the calendar; the cached environment verdict of the privacy directory, which is a
 *     measurement and not a setting; and the plugin's own transients.
 *   - **Kept, on purpose**: the schema (draft, published and the publication history),
 *     the merchant's opt-in, the order fields stored on orders, and the private upload
 *     files recorded in `wccs_uploads`. Every one of them is either the merchant's work
 *     or a customer's data, and a plugin's uninstall is not the place to destroy either.
 *
 * A store that really wants the configuration gone has a deliberate path for it: the
 * export of the schema from the Suite screen, which is what makes the deletion a
 * decision with a backup rather than an accident. The manual says so as well
 * (`docs/operations/merchant-guide.md`).
 *
 * @package WCCheckoutSuite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * The option names the uninstall removes.
 *
 * Named as literals on purpose: WordPress runs an uninstall with the plugin **not**
 * loaded, so no class of this plugin exists to read a constant from. Each one mirrors a
 * constant elsewhere in the tree — `UploadsEnvironment::STATE_OPTION` — and the comment
 * beside it says which, because a copied string with no owner is a string nobody updates.
 *
 * Kept as a list rather than as a `LIKE 'wccs_%'` query: a wildcard delete would take the
 * schema and the merchant's opt-in with it, and the whole point of this file is that it
 * does not.
 *
 * @var array<int, string>
 */
$wccs_uninstall_options = array(
	'wccs_uploads_privacy', // UploadsEnvironment::STATE_OPTION.
);

foreach ( $wccs_uninstall_options as $wccs_uninstall_option ) {
	delete_option( $wccs_uninstall_option );
}

// The plugin's own transients. WordPress suffixes transient names, so the wildcard is the
// only way to find them, and it is safe here because a transient is by definition
// disposable.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall has no cache to consult and no API that lists transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wccs_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wccs_' ) . '%'
	)
);

// The scheduled retention sweep. A deleted plugin must not leave a job behind.
wp_clear_scheduled_hook( 'wccs_uploads_cleanup' );
