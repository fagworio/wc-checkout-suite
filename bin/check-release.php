<?php
/**
 * Checks a built release ZIP before it is published.
 *
 * The package is the thing a merchant installs, and every property it has to have is
 * checkable before it leaves this machine. This script checks the ones that are facts
 * about the archive rather than opinions about it:
 *
 *   1. **It is complete.** Every file the plugin reads at runtime is inside, by name —
 *      the main file, the uninstall policy, the two compiled bundles and the admin one,
 *      the stylesheets PHP serves, the design tokens, the gateway homologation record and
 *      the translation template.
 *   2. **It installs without build tools.** No `vendor/`, no `node_modules/`, no
 *      `composer.json`, no `package.json`: the runtime autoloader in the main file is
 *      what resolves every class, and the compiled assets are already there.
 *   3. **The autoloader can resolve everything it ships.** Each class in `src/` is mapped
 *      from its namespace to the path the runtime autoloader would look for, and the file
 *      is asserted to exist at exactly that path. This is the check that a rename or a
 *      mis-cased directory cannot pass.
 *   4. **Nothing that exists only to develop is inside.** Tests, the roadmap, the
 *      examples, the tooling configuration, the caches, the editor metadata.
 *   5. **Every PHP file parses.** Read through PHP's own tokenizer, so a syntax error in a
 *      file no test happens to load is caught here rather than on the store.
 *
 * Usage:
 *   php bin/check-release.php dist/wc-checkoutsuite-1.0.0-rc.1.zip
 *
 * Exit code 0 means every check passed and the archive is publishable.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

$wccs_arguments = array_slice( $argv, 1 );

if ( array() === $wccs_arguments ) {
	fwrite( STDERR, "Usage: php bin/check-release.php <archive.zip>\n" );
	exit( 2 );
}

$wccs_archive = $wccs_arguments[0];

if ( ! is_readable( $wccs_archive ) ) {
	fwrite( STDERR, "No such archive: {$wccs_archive}\n" );
	exit( 2 );
}

$wccs_prefix = 'wc-checkout-suite/';

/**
 * Files that must be inside the package.
 *
 * @return array<int, string>
 */
function wccs_check_required(): array {
	return array(
		'wc-checkoutsuite.php',
		'uninstall.php',
		'readme.txt',
		'CHANGELOG.md',
		'LICENSE',
		'src/Plugin.php',
		'build/admin/index.js',
		'build/admin/index.asset.php',
		'build/admin/index.css',
		'build/checkout/index.js',
		'build/checkout/index.asset.php',
		'build/blocks/index.js',
		'build/blocks/index.asset.php',
		'resources/checkout/presentation.css',
		'resources/blocks/presentation.css',
		'resources/design-tokens/tokens.css',
		'resources/design-tokens/tokens.json',
		'resources/payments/homologation.json',
		'languages/wc-checkoutsuite.pot',
	);
}

/**
 * Paths that must never be inside a release.
 *
 * Named as fragments so a nested copy is caught too: a `tests/` at the root and a
 * `src/tests/` are the same mistake.
 *
 * @return array<int, string>
 */
function wccs_check_forbidden(): array {
	return array(
		'/tests/',
		'/roadmap/',
		'/examples/',
		'/node_modules/',
		'/vendor/',
		'/.git/',
		'/.github/',
		'/dist/',
		'/bin/',
		'/.wccs-scratch/',
		'/docs/validation/',
		'/docs/adr/',
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'phpunit.xml.dist',
		'phpcs.xml.dist',
		'phpstan.neon.dist',
		'jest.config.js',
		'tsconfig.json',
		'.gitattributes',
		'.gitignore',
		'.phpcs-cache',
		'.phpunit.result.cache',
	);
}

$wccs_zip    = new ZipArchive();
$wccs_opened = $wccs_zip->open( $wccs_archive );

if ( true !== $wccs_opened ) {
	fwrite( STDERR, "Could not open {$wccs_archive}.\n" );
	exit( 2 );
}

$wccs_entries = array();
$wccs_failures = array();
$wccs_notes    = array();

for ( $wccs_index = 0; $wccs_index < $wccs_zip->numFiles; $wccs_index++ ) {
	$wccs_name = (string) $wccs_zip->getNameIndex( $wccs_index );

	// Every entry lives under the plugin folder WordPress creates from the archive.
	if ( 0 !== strpos( $wccs_name, $wccs_prefix ) ) {
		$wccs_failures[] = "Entry outside the plugin folder: {$wccs_name}";
		continue;
	}

	$wccs_relative = substr( $wccs_name, strlen( $wccs_prefix ) );

	if ( '' === $wccs_relative || str_ends_with( $wccs_relative, '/' ) ) {
		continue;
	}

	$wccs_entries[ $wccs_relative ] = $wccs_zip->getFromIndex( $wccs_index );
}

// 1. Complete.
$wccs_missing = array();

foreach ( wccs_check_required() as $wccs_required ) {
	if ( ! array_key_exists( $wccs_required, $wccs_entries ) ) {
		$wccs_missing[] = $wccs_required;
	}
}

if ( array() !== $wccs_missing ) {
	$wccs_failures[] = 'Missing from the package: ' . implode( ', ', $wccs_missing );
}

// 2 and 4. Nothing that exists to develop, and no build tooling.
foreach ( array_keys( $wccs_entries ) as $wccs_relative ) {
	foreach ( wccs_check_forbidden() as $wccs_forbidden ) {
		$wccs_hit = str_starts_with( $wccs_forbidden, '/' )
			? str_contains( '/' . $wccs_relative, $wccs_forbidden )
			: ( $wccs_relative === $wccs_forbidden || str_ends_with( $wccs_relative, '/' . $wccs_forbidden ) );

		if ( $wccs_hit ) {
			$wccs_failures[] = "Development file in the package: {$wccs_relative} (matched {$wccs_forbidden})";
		}
	}
}

// 3. The autoloader resolves every class it ships.
$wccs_classes = 0;

foreach ( $wccs_entries as $wccs_relative => $wccs_contents ) {
	if ( ! str_starts_with( $wccs_relative, 'src/' ) || ! str_ends_with( $wccs_relative, '.php' ) ) {
		continue;
	}

	if ( 1 !== preg_match( '/^namespace\s+([^;]+);/m', (string) $wccs_contents, $wccs_namespace ) ) {
		continue;
	}

	if ( 1 !== preg_match( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', (string) $wccs_contents, $wccs_class ) ) {
		continue;
	}

	++$wccs_classes;

	$wccs_expected = 'src/' . str_replace( '\\', '/', trim( $wccs_namespace[1], '\\' ) );
	$wccs_expected = preg_replace( '#^src/WCCheckoutSuite#', 'src', $wccs_expected );
	$wccs_expected = $wccs_expected . '/' . $wccs_class[1] . '.php';

	if ( $wccs_expected !== $wccs_relative ) {
		$wccs_failures[] = "Autoloader path mismatch: {$wccs_relative} declares {$wccs_namespace[1]}\\{$wccs_class[1]} which resolves to {$wccs_expected}";
	}
}

$wccs_notes[] = "Classes mapped through the runtime autoloader: {$wccs_classes}";

// 5. Every PHP file parses, read by PHP's own tokenizer.
$wccs_php = 0;

foreach ( $wccs_entries as $wccs_relative => $wccs_contents ) {
	if ( ! str_ends_with( $wccs_relative, '.php' ) ) {
		continue;
	}

	++$wccs_php;

	try {
		token_get_all( (string) $wccs_contents, TOKEN_PARSE );
	} catch ( Throwable $wccs_error ) {
		$wccs_failures[] = "Syntax error in {$wccs_relative}: " . $wccs_error->getMessage();
	}
}

$wccs_notes[] = "PHP files parsed: {$wccs_php}";

// The version in the archive's folder name, the header and the constant.
$wccs_main = (string) ( $wccs_entries['wc-checkoutsuite.php'] ?? '' );

preg_match( '/^ \* Version:\s*(\S+)$/m', $wccs_main, $wccs_header );
preg_match( "/define\( 'WCCS_VERSION', '([^']+)' \)/", $wccs_main, $wccs_constant );

if ( ( $wccs_header[1] ?? '' ) !== ( $wccs_constant[1] ?? '' ) ) {
	$wccs_failures[] = 'The header version and WCCS_VERSION disagree inside the package.';
}

if ( ! str_contains( basename( $wccs_archive ), (string) ( $wccs_header[1] ?? '' ) ) ) {
	$wccs_failures[] = 'The archive name does not carry the version the package declares.';
}

$wccs_notes[] = 'Version: ' . ( $wccs_header[1] ?? '(none)' );
$wccs_notes[] = 'Files: ' . count( $wccs_entries ) . ', ' . round( (int) filesize( $wccs_archive ) / 1024, 1 ) . ' KB';
$wccs_notes[] = 'SHA-256: ' . hash_file( 'sha256', $wccs_archive );

$wccs_zip->close();

echo "Release check: {$wccs_archive}\n";

foreach ( $wccs_notes as $wccs_note ) {
	echo "  {$wccs_note}\n";
}

if ( array() === $wccs_failures ) {
	echo "  PASS  The package is complete, self-contained and parses.\n";
	exit( 0 );
}

foreach ( $wccs_failures as $wccs_failure ) {
	echo "  FAIL  {$wccs_failure}\n";
}

echo 'RESULT: ' . count( $wccs_failures ) . " problems\n";

exit( 1 );
