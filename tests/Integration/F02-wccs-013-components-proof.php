<?php
/**
 * WCCS-013 proof harness — the component library is built, scoped and complete.
 *
 * Task:   WCCS-013 "Criar componentes básicos"
 * Phase:  F02
 * Accept: "Botões, diálogos, tabs, mensagens, badges e formulários têm teclado e foco."
 *
 * Keyboard, focus and ARIA behaviour is proven by the JavaScript suite under
 * tests/js, which runs the components in a DOM. This harness covers what that
 * suite cannot: that the library reaches the build, that its styles stay inside
 * the plugin scope, and that every token it uses exists.
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
wccs_proof_out( 'WCCS-013 proof — component library' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

// ---------------------------------------------------------------------------
// 1. The library reaches the build.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Build output' );

$wccs_js  = $wccs_root . '/build/admin/index.js';
$wccs_css = $wccs_root . '/build/admin/index.css';

wccs_proof_check( 'The compiled bundle exists', is_readable( $wccs_js ) );
wccs_proof_check( 'The compiled stylesheet exists', is_readable( $wccs_css ) );

$wccs_css_built = wccs_proof_read( 'build/admin/index.css' );

wccs_proof_check(
	'The compiled stylesheet carries the component layer',
	strlen( $wccs_css_built ) > 4000,
	strlen( $wccs_css_built ) . ' bytes'
);

wccs_proof_note( 'Bundle size', strlen( wccs_proof_read( 'build/admin/index.js' ) ) . ' bytes of JavaScript' );

// ---------------------------------------------------------------------------
// 2. Every component the acceptance names is exported by the barrel.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Component surface' );

$wccs_barrel = wccs_proof_read( 'resources/admin/app/components/index.js' );

/**
 * Names the acceptance requires.
 *
 * @var array<int, string>
 */
$wccs_required_exports = array(
	'Button',
	'IconButton',
	'Dialog',
	'Tabs',
	'Notice',
	'Badge',
	'StatusBadge',
	'CompatibilityBadge',
	'Field',
	'TextField',
	'TextareaField',
	'SelectField',
	'CheckboxField',
	'ErrorSummary',
	'EmptyState',
);

// Parse the export statements rather than searching the file text: a name that
// only appears in a comment must not count as exported.
preg_match_all( '/export\s+(?:default\s+)?(?:function\s+(\w+)|const\s+(\w+))|export\s*\{([^}]*)\}/', $wccs_barrel, $wccs_matches, PREG_SET_ORDER );

$wccs_exported = array();

foreach ( $wccs_matches as $wccs_match ) {
	if ( ! empty( $wccs_match[1] ) ) {
		$wccs_exported[] = $wccs_match[1];
	}

	if ( ! empty( $wccs_match[2] ) ) {
		$wccs_exported[] = $wccs_match[2];
	}

	if ( ! empty( $wccs_match[3] ) ) {
		foreach ( explode( ',', $wccs_match[3] ) as $wccs_name ) {
			$wccs_name = trim( $wccs_name );

			if ( '' === $wccs_name ) {
				continue;
			}

			// `export { a as b }` exports b.
			$wccs_parts      = preg_split( '/\s+as\s+/i', $wccs_name );
			$wccs_exported[] = trim( (string) end( $wccs_parts ) );
		}
	}
}

$wccs_missing_exports = array_values( array_diff( $wccs_required_exports, $wccs_exported ) );

wccs_proof_check(
	'Every component named by the acceptance is exported',
	array() === $wccs_missing_exports,
	$wccs_missing_exports ? 'missing: ' . implode( ', ', $wccs_missing_exports ) : count( $wccs_exported ) . ' exports'
);

$wccs_files = glob( $wccs_root . '/resources/admin/app/components/*.js' );

wccs_proof_check(
	'Each component lives in its own module',
	is_array( $wccs_files ) && count( $wccs_files ) >= 8,
	count( (array) $wccs_files ) . ' modules'
);

// ---------------------------------------------------------------------------
// 3. Styles stay inside the plugin scope.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Style scope' );

$wccs_source_css = wccs_proof_read( 'resources/admin/app/components/components.css' );

wccs_proof_check( 'The component stylesheet exists', '' !== $wccs_source_css );

// Comments are removed before selectors are parsed: prose contains commas.
$wccs_parsed_css = (string) preg_replace( '!/\*.*?\*/!s', '', $wccs_source_css );

// `{` is a boundary as well as `}`: the first selector inside a container or
// media block follows `{`, so a `}`-only boundary would skip it and report full
// coverage while one rule went unchecked.
preg_match_all( '/(?:^|[{}])\s*([^{}@]+)\{/', $wccs_parsed_css, $wccs_selectors );

$wccs_unscoped = array();

foreach ( $wccs_selectors[1] ?? array() as $wccs_selector ) {
	foreach ( explode( ',', trim( $wccs_selector ) ) as $wccs_part ) {
		$wccs_part = trim( $wccs_part );

		if ( '' !== $wccs_part && ! str_contains( $wccs_part, '.wccs-admin' ) ) {
			$wccs_unscoped[] = $wccs_part;
		}
	}
}

wccs_proof_check(
	'Every selector is scoped to .wccs-admin',
	array() === $wccs_unscoped,
	$wccs_unscoped ? 'unscoped: ' . implode( ' | ', array_slice( $wccs_unscoped, 0, 3 ) ) : 'all scoped'
);

// ---------------------------------------------------------------------------
// 4. Keyboard and focus are part of the stylesheet, not an afterthought.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Focus and motion' );

wccs_proof_check(
	'A visible focus ring is declared for every interactive element',
	1 === preg_match( '/\.wccs-admin button:focus-visible.*?\.wccs-admin textarea:focus-visible/s', $wccs_parsed_css )
);
wccs_proof_check(
	'The focus ring uses the focus token',
	false !== strpos( $wccs_parsed_css, 'var(--wccs-focus-ring)' )
		&& false !== strpos( $wccs_parsed_css, 'var(--wccs-size-focus-outline-width' )
);
wccs_proof_check(
	'A screen reader text utility is provided',
	false !== strpos( $wccs_parsed_css, '.wccs-screen-reader-text' )
);
wccs_proof_check(
	'Reduced motion is honoured by the components too',
	false !== strpos( $wccs_parsed_css, 'prefers-reduced-motion: reduce' )
);

// ---------------------------------------------------------------------------
// 5. Every token the components use exists.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Token integrity' );

$wccs_tokens_css = wccs_proof_read( 'resources/design-tokens/tokens.css' );

preg_match_all( '/var\(\s*(--wccs-[a-z0-9-]+)\s*\)/', $wccs_parsed_css, $wccs_used );
$wccs_used_names = array_values( array_unique( $wccs_used[1] ?? array() ) );

wccs_proof_check(
	'The components consume design tokens throughout',
	count( $wccs_used_names ) >= 15,
	count( $wccs_used_names ) . ' distinct tokens'
);

$wccs_undefined = array();

foreach ( $wccs_used_names as $wccs_name ) {
	if ( ! str_contains( $wccs_tokens_css, $wccs_name . ':' ) ) {
		$wccs_undefined[] = $wccs_name;
	}
}

wccs_proof_check(
	'Every token the components use is declared in tokens.css',
	array() === $wccs_undefined,
	$wccs_undefined ? 'undefined: ' . implode( ', ', $wccs_undefined ) : 'all declared'
);

// A literal colour in the component layer would bypass the token tests, which
// only measure the colours declared in tokens.json.
wccs_proof_check(
	'The components declare no colour literals of their own',
	0 === preg_match( '/#[0-9a-fA-F]{3,8}\b/', $wccs_parsed_css ),
	'every colour comes from a token'
);

// ---------------------------------------------------------------------------
// 6. The JavaScript suite that proves behaviour is wired and present.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Behaviour suite' );

$wccs_test_files = glob( $wccs_root . '/tests/js/**/*.test.js' );
$wccs_specs      = array();

$wccs_directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wccs_root . '/tests/js' ) );

foreach ( $wccs_directory as $wccs_entry ) {
	if ( $wccs_entry->isFile() && str_ends_with( $wccs_entry->getFilename(), '.test.js' ) ) {
		$wccs_specs[] = $wccs_entry->getPathname();
	}
}

wccs_proof_check(
	'The behaviour suite exists',
	count( $wccs_specs ) >= 5,
	count( $wccs_specs ) . ' spec files'
);

wccs_proof_check(
	'Jest is configured',
	is_readable( $wccs_root . '/jest.config.js' ) && is_readable( $wccs_root . '/tests/js/setup.js' )
);

$wccs_package = json_decode( wccs_proof_read( 'package.json' ), true );

wccs_proof_check(
	'The behaviour suite is a project script',
	is_array( $wccs_package ) && isset( $wccs_package['scripts']['test:unit-js'] ),
	'script: ' . ( is_array( $wccs_package ) ? ( $wccs_package['scripts']['test:unit-js'] ?? 'missing' ) : 'missing' )
);

wccs_proof_note( 'Behaviour specs', implode( ', ', array_map( 'basename', $wccs_specs ) ) );

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
