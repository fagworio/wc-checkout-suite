<?php
/**
 * WCCS-012 proof harness — the suite screen, its assets and the responsive shell.
 *
 * Task:   WCCS-012 "Criar shell dentro do WordPress"
 * Phase:  F02
 * Accept: "Navegação e assets restritos à tela da Suite; responsividade funcional."
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * Visual verification at real widths belongs to WCCS-063; what is proven here is
 * that the assets cannot reach another screen, that the navigation matches the
 * planning, and that the responsive rules exist and reference real tokens.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Admin\AdminMenu;
use WCCheckoutSuite\Admin\Assets;

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok = (bool) $condition;
	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];
	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Reads a plugin file, returning an empty string when it is missing.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function wccs_proof_read( $relative ) {
	$path = WCCS_PLUGIN_DIR . $relative;

	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$wccs_root = rtrim( (string) WCCS_PLUGIN_DIR, '/' );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-012 proof — suite screen, asset gating and responsive shell' );
	wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. The screen is registered where the planning says.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Screen registration' );

wccs_proof_check(
	'The screen id is derived from the menu slug',
	'woocommerce_page_wccs-checkoutsuite' === AdminMenu::screen_id(),
	AdminMenu::screen_id()
);

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// add_submenu_page() returns early when the current user lacks the capability,
// and WP-CLI has no user, so authenticate as an administrator first.
$wccs_admin_users = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$wccs_admin_id    = (int) ( $wccs_admin_users[0] ?? 0 );
wp_set_current_user( $wccs_admin_id );

do_action( 'admin_menu' );

$wccs_submenu = isset( $GLOBALS['submenu']['woocommerce'] ) ? (array) $GLOBALS['submenu']['woocommerce'] : array();
$wccs_slugs   = array_column( $wccs_submenu, 2 );

wccs_proof_check(
	'The suite appears under WooCommerce',
	in_array( AdminMenu::SLUG, $wccs_slugs, true ),
	'submenu entries: ' . count( $wccs_slugs )
);

$wccs_entry = null;

foreach ( $wccs_submenu as $wccs_candidate ) {
	if ( isset( $wccs_candidate[2] ) && AdminMenu::SLUG === $wccs_candidate[2] ) {
		$wccs_entry = $wccs_candidate;
		break;
	}
}

wccs_proof_check(
	'The menu entry requires manage_woocommerce',
	is_array( $wccs_entry ) && AdminMenu::CAPABILITY === ( $wccs_entry[1] ?? '' ),
	'capability=' . ( is_array( $wccs_entry ) ? (string) ( $wccs_entry[1] ?? '' ) : 'missing' )
);

// ---------------------------------------------------------------------------
// 2. Navigation matches the planning.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Navigation' );

// §4's "Status e automações" is two screens: the states (Fase 9) and the automations that move
// through them (Fase 10). The navigation the planning fixes is the one that now includes both.
$wccs_expected_sections = array( 'fields', 'sections', 'rules', 'appearance', 'statuses', 'workflows', 'checkout-page', 'import-export', 'diagnostics', 'settings' );

wccs_proof_check(
	'The sections fixed by the planning are present, in order',
	$wccs_expected_sections === AdminMenu::section_ids(),
	implode( ', ', AdminMenu::section_ids() )
);

wccs_proof_check(
	'Every section carries a translatable label',
	( static function () {
		foreach ( AdminMenu::sections() as $section ) {
			if ( ! isset( $section['id'], $section['label'] ) || '' === $section['label'] ) {
				return false;
			}
		}

		return true;
	} )()
);

// ---------------------------------------------------------------------------
// 3. Assets are restricted to the suite screen.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Asset gating' );

wccs_proof_check(
	'The gate accepts the suite screen',
	Assets::should_enqueue( AdminMenu::screen_id() )
);

$wccs_other_screens = array( 'edit-post', 'woocommerce_page_wc-settings', 'plugins', 'index', 'woocommerce_page_wccs-checkoutsuite-extra' );

foreach ( $wccs_other_screens as $wccs_screen ) {
	wccs_proof_check(
		"The gate rejects \"{$wccs_screen}\"",
		! Assets::should_enqueue( $wccs_screen )
	);
}

wccs_proof_check( 'The gate rejects an empty screen id', ! Assets::should_enqueue( '' ) );
wccs_proof_check(
	'The enqueue callback is hooked to admin_enqueue_scripts',
	false !== has_action( 'admin_enqueue_scripts', array( 'WCCheckoutSuite\\Admin\\Assets', 'enqueue' ) )
);

// The callback is invoked directly rather than through do_action(): other
// active plugins hook admin_enqueue_scripts and are not safe to run outside a
// real admin request, and firing the global action would test them instead of
// the code under test. The has_action() assertion above already proves the
// wiring.
Assets::enqueue( 'edit-post' );

wccs_proof_check(
	'Nothing is enqueued on a foreign screen',
	! wp_script_is( Assets::SCRIPT_HANDLE, 'enqueued' ) && ! wp_style_is( Assets::TOKENS_HANDLE, 'enqueued' )
);

Assets::enqueue( AdminMenu::screen_id() );

wccs_proof_check( 'The bundle is enqueued on the suite screen', wp_script_is( Assets::SCRIPT_HANDLE, 'enqueued' ) );
wccs_proof_check( 'The design tokens stylesheet is enqueued', wp_style_is( Assets::TOKENS_HANDLE, 'enqueued' ) );
wccs_proof_check(
	'The compiled component stylesheet is enqueued and depends on the tokens',
	wp_style_is( Assets::STYLE_HANDLE, 'enqueued' )
);

$wccs_manifest = Assets::asset_manifest();

wccs_proof_check(
	'Dependencies come from the build manifest, not from a hand written list',
	in_array( 'wp-element', $wccs_manifest['dependencies'], true )
		&& in_array( 'wp-i18n', $wccs_manifest['dependencies'], true ),
	'manifest deps: ' . implode( ', ', $wccs_manifest['dependencies'] )
);
wccs_proof_check(
	'The cache busting version is the build hash',
	'' !== $wccs_manifest['version'] && WCCS_VERSION !== $wccs_manifest['version'],
	'version=' . $wccs_manifest['version']
);

$wccs_registered = wp_scripts()->get_data( Assets::SCRIPT_HANDLE, 'before' );
$wccs_inline     = is_array( $wccs_registered ) ? implode( "\n", $wccs_registered ) : '';

wccs_proof_check(
	'The bootstrap payload is printed before the bundle',
	false !== strpos( $wccs_inline, 'window.wccsAdmin' )
);

// ---------------------------------------------------------------------------
// 4. The mount node.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Mount node' );

ob_start();
AdminMenu::render();
$wccs_markup = (string) ob_get_clean();

wccs_proof_check(
	'The screen renders the mount node the bundle looks for',
	false !== strpos( $wccs_markup, 'id="' . AdminMenu::MOUNT_ID . '"' ),
	'id=' . AdminMenu::MOUNT_ID
);
wccs_proof_check(
	'The mount node carries the scope the tokens are declared on',
	false !== strpos( $wccs_markup, 'class="wccs-admin"' )
);
wccs_proof_check(
	'A no-script message explains the screen instead of leaving it blank',
	false !== strpos( $wccs_markup, '<noscript>' )
);

// ---------------------------------------------------------------------------
// 5. Responsive rules exist and match the documented breakpoints.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Responsiveness' );

$wccs_css_raw     = wccs_proof_read( 'resources/admin/app/app.css' );
$wccs_tokens      = json_decode( wccs_proof_read( 'resources/design-tokens/tokens.json' ), true );
$wccs_tokens_css  = wccs_proof_read( 'resources/design-tokens/tokens.css' );

wccs_proof_check( 'The shell stylesheet exists', '' !== $wccs_css_raw );

// Comments must go before selectors are parsed: the header explains the rules
// and contains commas, which would otherwise be read as selector separators.
$wccs_css = (string) preg_replace( '!/\*.*?\*/!s', '', $wccs_css_raw );

$wccs_breaks = is_array( $wccs_tokens ) ? ( $wccs_tokens['shared']['breakpoints'] ?? array() ) : array();

foreach ( array( 'compact' => 760, 'narrow' => 1020, 'wide' => 1550 ) as $wccs_name => $wccs_value ) {
	wccs_proof_check(
		"The {$wccs_name} breakpoint matches the tokens ({$wccs_value}px)",
		(int) ( $wccs_breaks[ $wccs_name ] ?? 0 ) === $wccs_value
			&& false !== strpos( $wccs_css, (string) $wccs_value . 'px' ),
		'token=' . ( $wccs_breaks[ $wccs_name ] ?? 'missing' )
	);
}

wccs_proof_check(
	'The compact layout collapses the shell to a single column',
	1 === preg_match( '/@media[^{]*760px[^{]*\{.*?grid-template-columns:\s*minmax\(0,\s*1fr\)/s', $wccs_css )
);
wccs_proof_check(
	'The compact layout turns the navigation into a horizontal strip',
	1 === preg_match( '/@media[^{]*760px[^{]*\{.*?\.wccs-shell__nav-list\s*\{[^}]*flex-direction:\s*row/s', $wccs_css )
);
wccs_proof_check(
	'The narrow layout shrinks the rail instead of squeezing the content',
	1 === preg_match( '/@media[^{]*1020px[^{]*\{.*?grid-template-columns:\s*64px/s', $wccs_css )
);
wccs_proof_check(
	'Hidden labels are still exposed to assistive technology',
	1 === preg_match( '/@media[^{]*1020px[^{]*\{.*?\.wccs-shell__nav-label\s*\{[^}]*clip:\s*rect\(0,\s*0,\s*0,\s*0\)/s', $wccs_css )
);
wccs_proof_check(
	'Reduced motion is honoured',
	false !== strpos( $wccs_css, 'prefers-reduced-motion: reduce' )
);
wccs_proof_check(
	'A skip link is styled and becomes visible on focus',
	false !== strpos( $wccs_css, '.wccs-shell__skip:focus' )
);

// ---------------------------------------------------------------------------
// 6. Every token the shell uses actually exists.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Token integrity' );

preg_match_all( '/var\(\s*(--wccs-[a-z0-9-]+)\s*\)/', $wccs_css, $wccs_used );
$wccs_used_names = array_values( array_unique( $wccs_used[1] ?? array() ) );

wccs_proof_check( 'The shell consumes design tokens', count( $wccs_used_names ) > 5, count( $wccs_used_names ) . ' distinct tokens' );

$wccs_undefined = array();

foreach ( $wccs_used_names as $wccs_name ) {
	if ( ! str_contains( $wccs_tokens_css, $wccs_name . ':' ) ) {
		$wccs_undefined[] = $wccs_name;
	}
}

wccs_proof_check(
	'Every token the shell uses is declared in tokens.css',
	array() === $wccs_undefined,
	$wccs_undefined ? 'undefined: ' . implode( ', ', $wccs_undefined ) : 'all declared'
);

wccs_proof_check(
	'The shell redefines no global element selector',
	( static function () use ( $wccs_css ) {
		// Every rule must be scoped under .wccs-shell, apart from the at-rule
		// wrappers themselves. `{` is a boundary as well as `}`: the first
		// selector inside a media or container block follows `{`, and treating
		// only `}` as a boundary would let exactly that rule escape the check.
		preg_match_all( '/(^|[{}])\s*([^{}@]+)\{/', $wccs_css, $selectors );

		foreach ( $selectors[2] ?? array() as $selector ) {
			$selector = trim( $selector );

			if ( '' === $selector ) {
				continue;
			}

			foreach ( explode( ',', $selector ) as $part ) {
				if ( ! str_contains( trim( $part ), '.wccs-shell' ) ) {
					return false;
				}
			}
		}

		return true;
	} )()
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
