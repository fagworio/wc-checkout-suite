<?php
/**
 * Inventories the licences of everything this project depends on.
 *
 * WCCS-067 asks for the dependencies to be inventoried, and an inventory that is typed
 * by hand is an inventory that is wrong the first time a dependency is added. This reads
 * the two lock files and reports what is actually there, separating the three cases that
 * matter for a distributed WordPress plugin:
 *
 *   - **Runtime**: what the shipped package needs in order to run on a store. For this
 *     plugin the answer is nothing — the runtime autoloader is its own, the front end uses
 *     the globals WordPress already prints, and the compiled bundles are built here, not
 *     there. The section is printed even when empty, because "no runtime dependencies" is
 *     a claim worth seeing.
 *   - **Build-time**: what produces the compiled assets. These never ship (the package
 *     excludes `node_modules/` and `vendor/`).
 *   - **Test-time**: what runs the suites. Never ships either.
 *
 * Usage:
 *   php bin/licence-inventory.php [--json]
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

$wccs_root = dirname( __DIR__ );

/**
 * Reads a JSON file, or exits.
 *
 * @param string $path Path.
 * @return array<string, mixed>
 */
function wccs_inventory_json( string $path ): array {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Missing lock file: {$path}\n" );
		exit( 1 );
	}

	$decoded = json_decode( (string) file_get_contents( $path ), true );

	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "Unreadable lock file: {$path}\n" );
		exit( 1 );
	}

	return $decoded;
}

/**
 * The licences declared by an installed Composer package.
 *
 * @param array<string, mixed> $package Package entry.
 * @return string
 */
function wccs_inventory_composer_licence( array $package ): string {
	$licence = $package['license'] ?? array();

	if ( is_string( $licence ) ) {
		return $licence;
	}

	if ( is_array( $licence ) && array() !== $licence ) {
		return implode( ' OR ', array_map( 'strval', $licence ) );
	}

	return '(not declared)';
}

/**
 * The licence declared by an installed npm package.
 *
 * @param array<string, mixed> $package Package entry.
 * @return string
 */
function wccs_inventory_npm_licence( array $package ): string {
	foreach ( array( 'license', 'licence' ) as $key ) {
		if ( isset( $package[ $key ] ) && is_string( $package[ $key ] ) ) {
			return $package[ $key ];
		}
	}

	if ( isset( $package['licenses'] ) && is_array( $package['licenses'] ) ) {
		$names = array();

		foreach ( $package['licenses'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['type'] ) ) {
				$names[] = (string) $entry['type'];
			}
		}

		if ( array() !== $names ) {
			return implode( ' OR ', $names );
		}
	}

	return '(not declared)';
}

$wccs_composer = wccs_inventory_json( $wccs_root . '/composer.lock' );
$wccs_npm      = wccs_inventory_json( $wccs_root . '/package-lock.json' );

$wccs_manifest = wccs_inventory_json( $wccs_root . '/composer.json' );

$wccs_groups = array(
	'runtime'    => array(),
	'build-time' => array(),
	'test-time'  => array(),
);

/*
 * Composer: the lock file holds the packages. A package is runtime when it is required
 * by the shipped plugin — which is decided by the package's own `require` section, and
 * this plugin requires nothing at runtime.
 */
$wccs_require = (array) ( $wccs_manifest['require'] ?? array() );

foreach ( array( 'packages', 'packages-dev' ) as $wccs_section ) {
	foreach ( (array) ( $wccs_composer[ $wccs_section ] ?? array() ) as $wccs_package ) {
		if ( ! is_array( $wccs_package ) || ! isset( $wccs_package['name'] ) ) {
			continue;
		}

		$wccs_name = (string) $wccs_package['name'];

		// `php` and the extensions are platform requirements, not packages with a licence.
		if ( isset( $wccs_require[ $wccs_name ] ) ) {
			$wccs_groups['runtime'][] = array(
				'name'    => $wccs_name,
				'version' => (string) ( $wccs_package['version'] ?? '?' ),
				'licence' => wccs_inventory_composer_licence( $wccs_package ),
				'source'  => 'composer',
			);

			continue;
		}

		$wccs_groups['test-time'][] = array(
			'name'    => $wccs_name,
			'version' => (string) ( $wccs_package['version'] ?? '?' ),
			'licence' => wccs_inventory_composer_licence( $wccs_package ),
			'source'  => 'composer',
		);
	}
}

/*
 * npm: `packages` in the lock file, with the root project at key "". A package is
 * build-time when the project's devDependencies ask for it and the build uses it — which
 * for this project means everything, since nothing from npm is required at runtime.
 */
$wccs_dev_dependencies = array_keys( (array) ( wccs_inventory_json( $wccs_root . '/package.json' )['devDependencies'] ?? array() ) );

foreach ( (array) ( $wccs_npm['packages'] ?? array() ) as $wccs_path => $wccs_package ) {
	if ( '' === $wccs_path || ! is_array( $wccs_package ) ) {
		continue;
	}

	$wccs_name = isset( $wccs_package['name'] )
		? (string) $wccs_package['name']
		: ltrim( (string) preg_replace( '#^node_modules/#', '', (string) $wccs_path ) );

	$wccs_top = ltrim( (string) preg_replace( '#^.*node_modules/#', '', (string) $wccs_path ) );

	$wccs_groups['build-time'][] = array(
		'name'    => $wccs_name,
		'version' => (string) ( $wccs_package['version'] ?? '?' ),
		'licence' => wccs_inventory_npm_licence( $wccs_package ),
		'source'  => in_array( $wccs_top, $wccs_dev_dependencies, true ) ? 'npm (direct)' : 'npm (transitive)',
	);
}

$wccs_summary = array();

foreach ( $wccs_groups as $wccs_group => $wccs_entries ) {
	$wccs_licences = array();

	foreach ( $wccs_entries as $wccs_entry ) {
		$wccs_licences[ $wccs_entry['licence'] ] = ( $wccs_licences[ $wccs_entry['licence'] ] ?? 0 ) + 1;
	}

	ksort( $wccs_licences );

	$wccs_summary[ $wccs_group ] = array(
		'packages' => count( $wccs_entries ),
		'licences' => $wccs_licences,
	);
}

if ( in_array( '--json', array_slice( $argv, 1 ), true ) ) {
	echo json_encode(
		array(
			'summary' => $wccs_summary,
			'groups'  => $wccs_groups,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";

	exit( 0 );
}

echo "Licence inventory — WC CheckoutSuite\n";
echo str_repeat( '=', 68 ) . "\n";

foreach ( $wccs_groups as $wccs_group => $wccs_entries ) {
	printf( "%-12s %d packages\n", strtoupper( $wccs_group ), count( $wccs_entries ) );

	foreach ( $wccs_summary[ $wccs_group ]['licences'] as $wccs_licence => $wccs_count ) {
		printf( "  %-34s %d\n", $wccs_licence, $wccs_count );
	}

	echo "\n";
}

if ( array() === $wccs_groups['runtime'] ) {
	echo "No runtime dependency: the shipped package requires neither Composer nor npm.\n";
}
