<?php
/**
 * Builds the release ZIP.
 *
 * The package has one job: install on a store where nobody runs Composer or npm. That
 * decides its contents, and the decision is written down here rather than in a CI
 * configuration nobody reads:
 *
 *   - **`src/` ships as source.** The plugin carries its own PSR-4 autoloader in the main
 *     file (ROADMAP.md section 18), so the released package needs no generated
 *     autoloader, no `vendor/` and no Composer.
 *   - **`build/` ships compiled.** The bundles are produced by `npm run build` and are
 *     not versioned, so the tree that goes into the ZIP is the one on disk — this script
 *     refuses to build when a bundle is missing, because a package without compiled
 *     assets installs and then does nothing.
 *   - **`resources/` ships its assets, not its sources.** Five files in it are read at
 *     runtime by PHP — the two presentation stylesheets, the design tokens (the CSS and
 *     the JSON the editor reads) and the gateway homologation record — and everything
 *     else in that tree is the source of JavaScript that already ships compiled in
 *     `build/`. Shipping the sources would double the package and give the store a second
 *     copy of code that never runs there, so the rule is by kind: stylesheets, records
 *     and binary assets ship, scripts do not.
 *   - **Everything that exists only to develop does not ship**: tests, the roadmap, the
 *     examples, the tooling configuration, the lock files, the editor metadata and the
 *     validation records. They are the project's memory, not the merchant's plugin.
 *   - **The release records do not ship either**, and for a reason worth writing down: a
 *     release record states the archive's own SHA-256, so a record inside the archive
 *     would be a document about a file that changing the document changes. The record
 *     lives in the repository, next to the package it describes, and the package ships
 *     the manual, the functional matrix, the licensing policy and the threat model — the
 *     documents a store reads after installing.
 *
 * Usage:
 *   php bin/build-release.php [--output=<path>]
 *
 * Exit code is 0 only when the ZIP was written and every required runtime file is inside
 * it. The file list is printed so a release can be compared against the previous one.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

$wccs_root = dirname( __DIR__ );

/**
 * Reads the version from the plugin header.
 *
 * The header is the single source of truth: a ZIP whose name disagrees with the version
 * WordPress reports is a support ticket waiting to happen. The constant in the same file
 * is checked against it rather than trusted separately.
 *
 * @param string $main_file Main plugin file.
 * @return string
 */
function wccs_release_version( string $main_file ): string {
	$contents = (string) file_get_contents( $main_file );

	if ( 1 !== preg_match( '/^ \* Version:\s*(\S+)$/m', $contents, $matches ) ) {
		fwrite( STDERR, "The plugin header carries no Version line.\n" );
		exit( 1 );
	}

	$header = $matches[1];

	if ( 1 !== preg_match( "/define\( 'WCCS_VERSION', '([^']+)' \)/", $contents, $constant ) ) {
		fwrite( STDERR, "The main file no longer defines WCCS_VERSION where this script can read it.\n" );
		exit( 1 );
	}

	if ( $header !== $constant[1] ) {
		fwrite( STDERR, "The header says {$header} and WCCS_VERSION says {$constant[1]}; they must agree.\n" );
		exit( 1 );
	}

	return $header;
}

/**
 * The directories that ship whole.
 *
 * @return array<int, string>
 */
function wccs_release_directories(): array {
	return array(
		'src',
		'build',
		'resources',
		'languages',
		'docs/api',
		'docs/operations',
	);
}

/**
 * The files that ship on their own.
 *
 * @return array<int, string>
 */
function wccs_release_files(): array {
	return array(
		'wc-checkoutsuite.php',
		'uninstall.php',
		'readme.txt',
		'CHANGELOG.md',
		'LICENSE',
	);
}

/**
 * Files that must be inside the package for it to work at all.
 *
 * Checked by name rather than by directory so a rename cannot quietly drop one: the
 * compiled bundles, the stylesheets PHP reads at runtime, the tokens, the homologation
 * record and the main file.
 *
 * @return array<int, string>
 */
function wccs_release_required(): array {
	return array(
		'wc-checkoutsuite.php',
		'uninstall.php',
		'readme.txt',
		'CHANGELOG.md',
		'LICENSE',
		'src/Plugin.php',
		'build/admin/index.js',
		'build/admin/index.asset.php',
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
 * The file kinds that ship from `resources/`.
 *
 * Stylesheets, records and binary assets: what PHP or the browser can read at runtime
 * without a build step. JavaScript is deliberately absent — it is compiled into `build/`
 * and the copy under `resources/` is the input to that build.
 *
 * @return array<int, string>
 */
function wccs_release_resource_extensions(): array {
	return array( 'css', 'json', 'woff', 'woff2', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif' );
}

/**
 * Whether a file under `resources/` is an asset rather than a source.
 *
 * @param string $relative Path relative to the plugin root.
 * @return bool
 */
function wccs_release_keeps_resource( string $relative ): bool {
	$extension = strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) );

	return in_array( $extension, wccs_release_resource_extensions(), true );
}

/**
 * Collects the paths that ship, relative to the plugin root.
 *
 * @param string $root Plugin root.
 * @return array<int, string>
 */
function wccs_release_collect( string $root ): array {
	$paths = array();

	foreach ( wccs_release_files() as $file ) {
		$full = $root . '/' . $file;

		if ( is_readable( $full ) && is_file( $full ) ) {
			$paths[] = $file;
		}
	}

	foreach ( wccs_release_directories() as $directory ) {
		$full = $root . '/' . $directory;

		if ( ! is_dir( $full ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$relative = ltrim( str_replace( $root, '', $item->getPathname() ), '/' );

			// Editor and operating-system droppings are never part of a release.
			if ( preg_match( '#(^|/)(\.DS_Store|Thumbs\.db|\.gitkeep)$#', $relative ) ) {
				continue;
			}

			if ( str_starts_with( $relative, 'resources/' ) && ! wccs_release_keeps_resource( $relative ) ) {
				continue;
			}

			// A release record carries the archive's checksum; shipping it would make the
			// archive describe itself. See the note at the top of this file.
			if ( 1 === preg_match( '#^docs/operations/release-.*\.md$#', $relative ) ) {
				continue;
			}

			$paths[] = $relative;
		}
	}

	sort( $paths );

	return $paths;
}

$wccs_version = wccs_release_version( $wccs_root . '/wc-checkoutsuite.php' );

$wccs_output = $wccs_root . '/dist/wc-checkoutsuite-' . $wccs_version . '.zip';

foreach ( array_slice( $argv, 1 ) as $wccs_argument ) {
	if ( 0 === strpos( $wccs_argument, '--output=' ) ) {
		$wccs_output = substr( $wccs_argument, strlen( '--output=' ) );
	}
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The zip extension is not available, so no package can be built.\n" );
	exit( 1 );
}

$wccs_paths = wccs_release_collect( $wccs_root );

$wccs_missing = array();

foreach ( wccs_release_required() as $wccs_required ) {
	if ( ! in_array( $wccs_required, $wccs_paths, true ) ) {
		$wccs_missing[] = $wccs_required;
	}
}

if ( array() !== $wccs_missing ) {
	fwrite( STDERR, "The package would be incomplete. Missing:\n  " . implode( "\n  ", $wccs_missing ) . "\n" );
	fwrite( STDERR, "Run `npm run build` before packaging: the compiled bundles are not versioned.\n" );
	exit( 1 );
}

$wccs_directory = dirname( $wccs_output );

if ( ! is_dir( $wccs_directory ) && ! mkdir( $wccs_directory, 0775, true ) && ! is_dir( $wccs_directory ) ) {
	fwrite( STDERR, "Could not create {$wccs_directory}.\n" );
	exit( 1 );
}

if ( file_exists( $wccs_output ) ) {
	unlink( $wccs_output );
}

$wccs_zip    = new ZipArchive();
$wccs_opened = $wccs_zip->open( $wccs_output, ZipArchive::CREATE );

if ( true !== $wccs_opened ) {
	fwrite( STDERR, "Could not open {$wccs_output} for writing.\n" );
	exit( 1 );
}

/*
 * WordPress installs a plugin from a folder named after its slug, and the ZIP is what
 * creates that folder. The path inside the archive is therefore the plugin root, and the
 * archive root is that folder and nothing else.
 */
$wccs_prefix = 'wc-checkout-suite/';

foreach ( $wccs_paths as $wccs_path ) {
	$wccs_zip->addFile( $wccs_root . '/' . $wccs_path, $wccs_prefix . $wccs_path );
}

/*
 * No build timestamp: an archive that changes every second cannot be verified against a
 * checksum, and the second a build finished is not information a store needs. What the
 * manifest states is what the archive is — the version and how many files it holds —
 * so rebuilding an unchanged tree reproduces the bytes, and the checksum in the release
 * record is a statement about the tree rather than about the moment it was packaged.
 */
$wccs_manifest_file = $wccs_directory . '/.dist-manifest.txt';

file_put_contents(
	$wccs_manifest_file,
	"WC CheckoutSuite {$wccs_version}\n"
	. 'Files: ' . count( $wccs_paths ) . "\n"
	. "Built by bin/build-release.php. The compiled assets in build/ are produced by `npm run build` and are not versioned; everything else in this package is the released source.\n"
);

/*
 * The entry is added from a file whose timestamp is the main plugin file's, not the
 * moment of the build: an entry added from a string carries the current time, and an
 * archive that changes every second cannot be verified against a checksum.
 */
touch( $wccs_manifest_file, (int) filemtime( $wccs_root . '/wc-checkoutsuite.php' ) );

$wccs_zip->addFile( $wccs_manifest_file, $wccs_prefix . 'dist-manifest.txt' );

$wccs_zip->close();

unlink( $wccs_manifest_file );

$wccs_bytes = (int) filesize( $wccs_output );

printf( "Package: %s\n", $wccs_output );
printf( "Version: %s\n", $wccs_version );
printf( "Files:   %d\n", count( $wccs_paths ) + 1 );
printf( "Size:    %.1f KB\n", $wccs_bytes / 1024 );
printf( "SHA-256: %s\n", hash_file( 'sha256', $wccs_output ) );

$wccs_by_directory = array();

foreach ( $wccs_paths as $wccs_path ) {
	$wccs_top = str_contains( $wccs_path, '/' ) ? strstr( $wccs_path, '/', true ) : '(root)';

	$wccs_by_directory[ $wccs_top ] = ( $wccs_by_directory[ $wccs_top ] ?? 0 ) + 1;
}

ksort( $wccs_by_directory );

echo "Contents:\n";

foreach ( $wccs_by_directory as $wccs_top => $wccs_count ) {
	printf( "  %-14s %d files\n", $wccs_top, $wccs_count );
}

exit( 0 );
