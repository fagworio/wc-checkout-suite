<?php
/**
 * WCCS-015 proof harness — the visual preview is honest about what it shows.
 *
 * Task:   WCCS-015 "Criar preview visual"
 * Phase:  F02
 * Accept: "Desktop/tablet/mobile identificados como prévia; nenhum dado real necessário."
 *
 * The acceptance is easy to satisfy dishonestly. Narrowing a wrapper and calling
 * it "mobile" would satisfy the wording while showing the desktop layout, and a
 * preview that quietly read real orders would still look correct. This harness
 * therefore checks the two mechanisms that make the acceptance true:
 *
 * 1. The frame is a query container, so previewed content reflows with the frame
 *    instead of with the browser window. Without `container-type`, the device
 *    selector would be decorative.
 * 2. The frame introduced no server route, so there is nothing for it to read
 *    real data from.
 *
 * Behaviour in the DOM — that the device selector actually switches, that the
 * compatibility matrix follows the adapter, that no request is made — is proven
 * by tests/js/components/PreviewFrame.test.js, which can run the component. Both
 * halves are required; neither is sufficient.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

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
	$ok                                  = (bool) $condition;
	$GLOBALS['wccs_proof']['checks'][]   = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
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

/**
 * Extracts the declaration body of the first rule matching a selector.
 *
 * Parsing the rule rather than searching the whole file is what makes the
 * containment assertion mean something: the property has to be declared on the
 * surface itself, not merely present somewhere in the stylesheet.
 *
 * @param string $css      Stylesheet with comments removed.
 * @param string $selector Literal selector to locate.
 * @return string Declaration body, or an empty string when absent.
 */
function wccs_proof_rule_body( $css, $selector ) {
	$position = strpos( $css, $selector . ' {' );

	if ( false === $position ) {
		return '';
	}

	$open  = strpos( $css, '{', $position );
	$close = strpos( $css, '}', (int) $open );

	if ( false === $open || false === $close ) {
		return '';
	}

	return substr( $css, $open + 1, $close - $open - 1 );
}

$wccs_root = rtrim( (string) WCCS_PLUGIN_DIR, '/' );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-015 proof — visual preview' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. The preview reaches the shipped build.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Build output' );

$wccs_built_js  = wccs_proof_read( 'build/admin/index.js' );
$wccs_built_css = wccs_proof_read( 'build/admin/index.css' );

wccs_proof_check(
	'The compiled bundle exists',
	'' !== $wccs_built_js && '' !== $wccs_built_css,
	sprintf( '%d bytes js, %d bytes css', strlen( $wccs_built_js ), strlen( $wccs_built_css ) )
);

// The preview lives in the Appearance section, so it must be in the bundle the
// admin actually loads, not only in the sources.
wccs_proof_check(
	'The preview markup reached the bundle',
	false !== strpos( $wccs_built_js, 'wccs-preview__surface' )
		&& false !== strpos( $wccs_built_js, 'wccs-preview__stage' ),
	'preview classes present in the minified script'
);

wccs_proof_check(
	'The preview stylesheet reached the bundle',
	false !== strpos( $wccs_built_css, 'wccs-preview__surface' )
		&& false !== strpos( $wccs_built_css, 'wccs-segmented__option' ),
	'preview and segmented rules present in the minified stylesheet'
);

// The strings are the ones a person reads; if the frame were dropped from the
// entry point they would not be in the bundle at all.
wccs_proof_check(
	'The synthetic-data statement is in the shipped script',
	false !== strpos( $wccs_built_js, 'never touches the live checkout' ),
	'statement present'
);

// ---------------------------------------------------------------------------
// 2. The device selector is a mechanism, not a decoration.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Container-query mechanism' );

$wccs_source_css = wccs_proof_read( 'resources/admin/app/components/components.css' );
$wccs_parsed_css = (string) preg_replace( '!/\*.*?\*/!s', '', $wccs_source_css );

$wccs_surface_rule = wccs_proof_rule_body( $wccs_parsed_css, '.wccs-admin .wccs-preview__surface' );

wccs_proof_check(
	'The preview surface establishes a query container',
	false !== strpos( $wccs_surface_rule, 'container-type: inline-size' ),
	'container-type: inline-size declared on the surface'
);

wccs_proof_check(
	'The container is named, so queries cannot match an unrelated ancestor',
	false !== strpos( $wccs_surface_rule, 'container-name: wccs-preview' ),
	'container-name: wccs-preview'
);

wccs_proof_check(
	'The surface takes its width from the selected device class',
	false !== strpos( $wccs_surface_rule, 'inline-size: var(--wccs-preview-width' ),
	'inline-size driven by --wccs-preview-width'
);

// This is the assertion that separates a real preview from a narrowed div: the
// previewed content must actually query the container.
preg_match_all( '/@container\s+wccs-preview\s*\(/', $wccs_parsed_css, $wccs_container_queries );

wccs_proof_check(
	'Previewed content queries the container and reflows with it',
	count( $wccs_container_queries[0] ?? array() ) >= 2,
	count( $wccs_container_queries[0] ?? array() ) . ' @container query blocks'
);

wccs_proof_check(
	'The narrowest container query matches the compact breakpoint token',
	1 === preg_match( '/@container\s+wccs-preview\s*\(max-width:\s*760px\)/', $wccs_parsed_css ),
	'container query at 760px, the same threshold as --wccs-breakpoint-compact'
);

// A wider-than-admin device has to scroll, and centring a scroll container
// clips its start edge: the left of the preview becomes unreachable. The stage
// stays at flex-start and the surface centres itself with auto margins, which
// resolve to zero as soon as the content overflows.
$wccs_stage_rule = wccs_proof_rule_body( $wccs_parsed_css, '.wccs-admin .wccs-preview__stage' );

wccs_proof_check(
	'The stage scrolls when the device is wider than the admin screen',
	false !== strpos( $wccs_stage_rule, 'overflow-x: auto' ),
	'overflow-x: auto'
);

wccs_proof_check(
	'The scroll container is not centred, so its start edge stays reachable',
	false === strpos( $wccs_stage_rule, 'justify-content: center' )
		&& false !== strpos( $wccs_stage_rule, 'justify-content: flex-start' ),
	'flex-start on the stage, margin-inline: auto on the surface'
);

wccs_proof_check(
	'The surface centres itself only while it fits',
	false !== strpos( $wccs_surface_rule, 'margin-inline: auto' ),
	'margin-inline: auto'
);

// ---------------------------------------------------------------------------
// 3. The three device classes are wired to token widths, without drift.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Device classes and widths' );

$wccs_tokens_json = json_decode( (string) wccs_proof_read( 'resources/design-tokens/tokens.json' ), true );
$wccs_tokens_css  = wccs_proof_read( 'resources/design-tokens/tokens.css' );

$wccs_devices = array(
	'desktop' => 'preview_desktop',
	'tablet'  => 'preview_tablet',
	'mobile'  => 'preview_mobile',
);

$wccs_missing_json = array();
$wccs_missing_css  = array();
$wccs_mismatched   = array();
$wccs_unwired      = array();

foreach ( $wccs_devices as $wccs_device => $wccs_token ) {
	$wccs_json_value = $wccs_tokens_json['shared']['size'][ $wccs_token ] ?? null;

	if ( ! is_int( $wccs_json_value ) || $wccs_json_value <= 0 ) {
		$wccs_missing_json[] = $wccs_token;
	}

	$wccs_css_var = '--wccs-size-' . str_replace( '_', '-', $wccs_token );

	if ( 1 !== preg_match( '/' . preg_quote( $wccs_css_var, '/' ) . ':\s*(\d+)px/', $wccs_tokens_css, $wccs_css_match ) ) {
		$wccs_missing_css[] = $wccs_css_var;
	} elseif ( is_int( $wccs_json_value ) && (int) $wccs_css_match[1] !== $wccs_json_value ) {
		// The JSON is canonical, so a disagreement is drift in the CSS.
		$wccs_mismatched[] = sprintf( '%s css:%spx json:%d', $wccs_css_var, $wccs_css_match[1], $wccs_json_value );
	}

	// Each device class must set the width the surface reads.
	$wccs_selector = '.wccs-admin .wccs-preview__surface[data-viewport="' . $wccs_device . '"]';
	$wccs_body     = wccs_proof_rule_body( $wccs_parsed_css, $wccs_selector );

	if ( false === strpos( $wccs_body, '--wccs-preview-width: var(' . $wccs_css_var ) ) {
		$wccs_unwired[] = $wccs_device;
	}
}

wccs_proof_check(
	'The canonical token file declares a width for every device class',
	array() === $wccs_missing_json,
	$wccs_missing_json ? 'missing: ' . implode( ', ', $wccs_missing_json ) : 'all three declared'
);

wccs_proof_check(
	'The stylesheet declares the same three widths',
	array() === $wccs_missing_css,
	$wccs_missing_css ? 'missing: ' . implode( ', ', $wccs_missing_css ) : 'all three declared'
);

wccs_proof_check(
	'Token JSON and CSS agree, so the preview cannot drift from the design system',
	array() === $wccs_mismatched,
	$wccs_mismatched ? implode( ' | ', $wccs_mismatched ) : 'values match'
);

wccs_proof_check(
	'Every device class selects its width through the surface',
	array() === $wccs_unwired,
	$wccs_unwired ? 'unwired: ' . implode( ', ', $wccs_unwired ) : 'desktop, tablet and mobile wired'
);

wccs_proof_note(
	'Preview widths',
	implode(
		', ',
		array_map(
			static function ( $device, $token ) use ( $wccs_tokens_json ) {
				return $device . ' ' . ( $wccs_tokens_json['shared']['size'][ $token ] ?? '?' ) . 'px';
			},
			array_keys( $wccs_devices ),
			array_values( $wccs_devices )
		)
	)
);

// ---------------------------------------------------------------------------
// 4. The preview is labelled as a preview, and the shell exposes it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Labelling and reachability' );

wccs_proof_check(
	'The frame is persistently labelled as a preview',
	false !== strpos( $wccs_built_js, 'Preview' ),
	'badge wording present in the bundle'
);

$wccs_source_js = wccs_proof_read( 'resources/admin/app/components/PreviewFrame.js' );

wccs_proof_check(
	'The frame requires a compatibility matrix keyed by adapter',
	false !== strpos( $wccs_source_js, 'capabilities[ adapter ]' ),
	'entries resolved per adapter, never assumed'
);

$wccs_capabilities = wccs_proof_read( 'resources/admin/app/previewCapabilities.js' );

// The matrix exists so an adapter's limits cannot vanish. A level without a
// reason would be exactly that failure in miniature.
preg_match_all( '/reason:\s*__\(/', $wccs_capabilities, $wccs_reasons );
preg_match_all( '/level:\s*\'/', $wccs_capabilities, $wccs_levels );

wccs_proof_check(
	'Every capability level carries a reason',
	count( $wccs_reasons[0] ?? array() ) === count( $wccs_levels[0] ?? array() )
		&& count( $wccs_levels[0] ?? array() ) >= 6,
	sprintf( '%d levels, %d reasons', count( $wccs_levels[0] ?? array() ), count( $wccs_reasons[0] ?? array() ) )
);

$wccs_unknown_levels = array();

foreach ( preg_match_all( "/level:\s*'([a-z]+)'/", $wccs_capabilities, $wccs_level_matches ) ? $wccs_level_matches[1] : array() as $wccs_level ) {
	if ( ! in_array( $wccs_level, array( 'native', 'suite', 'limited', 'unsupported' ), true ) ) {
		$wccs_unknown_levels[] = $wccs_level;
	}
}

wccs_proof_check(
	'Every level is one of the four the planning names',
	array() === $wccs_unknown_levels,
	$wccs_unknown_levels ? 'unknown: ' . implode( ', ', $wccs_unknown_levels ) : 'native, suite, limited, unsupported'
);

$wccs_shell = wccs_proof_read( 'resources/admin/app/AppShell.js' );

wccs_proof_check(
	'The Appearance section renders the preview',
	false !== strpos( $wccs_shell, "'appearance'" ) && false !== strpos( $wccs_shell, 'PreviewFrame' ),
	'preview mounted from the shell'
);

// ---------------------------------------------------------------------------
// 5. The preview added no server surface, so it has nothing real to read.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. No new data surface' );

$wccs_routes = \WCCheckoutSuite\Http\Admin\SchemaController::routes();

wccs_proof_check(
	'The preview introduced no REST route',
	! in_array( 'preview', array_keys( $wccs_routes ), true )
		&& ! in_array( 'render', array_keys( $wccs_routes ), true ),
	'route keys: ' . implode( ', ', array_keys( $wccs_routes ) )
);

// The original assertion pinned the count at four, which was the honest way to
// say "the preview added no route" while four was all there was. F03 has since
// added routes legitimately — the publication report and the picker catalogue —
// so a count no longer expresses the intent.
//
// What the intent actually is, and what is asserted now: the schema controller
// publishes exactly the paths it declares as constants, and none of them renders
// the preview on the server. A count says nothing about where a fifth route came
// from; this does.
$wccs_declared = array(
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT,
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_PUBLISH,
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_REVISIONS,
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_RESTORE,
	\WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DIFF,
);

$wccs_actual = array_values( \WCCheckoutSuite\Http\Admin\SchemaController::routes() );

sort( $wccs_declared );
sort( $wccs_actual );

wccs_proof_check(
	'The schema controller publishes exactly the routes it declares',
	$wccs_declared === $wccs_actual,
	implode( ', ', $wccs_actual )
);

wccs_proof_check(
	'None of them renders the preview on the server',
	false === strpos( implode( ',', $wccs_actual ), 'preview' )
		&& false === strpos( implode( ',', $wccs_actual ), 'render' ),
	'schema routes only'
);

// ---------------------------------------------------------------------------
// 6. The environment was left as it was found.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

global $wpdb;

$wccs_leftovers = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'"
);

wccs_proof_check(
	'The preview created no stored option',
	array() === $wccs_leftovers,
	$wccs_leftovers ? 'found: ' . implode( ', ', $wccs_leftovers ) : 'no wccs_* options'
);

wccs_proof_note(
	'Cross-reference',
	'DOM behaviour is proven by tests/js/components/PreviewFrame.test.js (13 specs, including that no request is made).'
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
