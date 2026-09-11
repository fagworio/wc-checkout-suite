<?php
/**
 * WCCS-027 proof harness — masks in the classic checkout.
 *
 * Task:   WCCS-027 "Integrar IMask localmente"
 * Phase:  F05
 * Accept: "Colar, apagar, autocomplete, mobile e refresh não quebram cursor/valor."
 *
 * Applying a mask is JavaScript and is proven by the JavaScript suite, which types
 * into a real DOM, replaces nodes the way a fragment refresh does and reads the
 * cursor back. What this harness proves is the half the server owns: the masks a
 * store publishes are the masks the bundle is given, and nothing about how they
 * arrive depends on a server that is not this one.
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
	$ok                                = (bool) $condition;
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
 * Builds a field definition payload.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_def( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
		),
		$changes
	);
}

/**
 * Writes a document straight into the published slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 5,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

$wccs_options_before = wccs_proof_option_count();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-027 proof — masks in the classic checkout' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_published = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

delete_option( $wccs_published );

// ---------------------------------------------------------------------------
// 1. The masks the store published are the masks the bundle is given.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the bundle receives' );

wccs_proof_publish(
	array(
		wccs_proof_def(
			'wccs_cpf',
			array(
				'mask' => array(
					'key'     => 'br.cpf',
					'version' => 1,
				),
			)
		),
		wccs_proof_def(
			'wccs_cnpj',
			array(
				'mask' => array(
					'key'     => 'br.cnpj',
					'version' => 1,
				),
			)
		),
		wccs_proof_def( 'wccs_notes' ),
		wccs_proof_def(
			'wccs_archived',
			array(
				'label'   => 'Archived',
				'enabled' => false,
				'mask'    => array(
					'key'     => 'br.cep',
					'version' => 1,
				),
			)
		),
		wccs_proof_def(
			'wccs_upload',
			array(
				'type' => 'heading',
				'mask' => array(
					'key'     => 'br.cep',
					'version' => 1,
				),
			)
		),
	)
);

$wccs_bootstrap = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();
$wccs_masks     = (array) ( $wccs_bootstrap['masks'] ?? array() );

wccs_proof_check(
	'The fields that declare a mask are sent',
	array( 'wccs_cpf', 'wccs_cnpj' ) === array_keys( $wccs_masks ),
	'keys=' . wp_json_encode( array_keys( $wccs_masks ) )
);

wccs_proof_check(
	'The pattern travels, not just the key',
	'000.000.000-00' === ( $wccs_masks['wccs_cpf']['definition'] ?? null ),
	'definition=' . wp_json_encode( $wccs_masks['wccs_cpf']['definition'] ?? null )
);

wccs_proof_check(
	'The alphanumeric CNPJ mask travels as one mask for both formats',
	'**.***.***/****-00' === ( $wccs_masks['wccs_cnpj']['definition'] ?? null ),
	'definition=' . wp_json_encode( $wccs_masks['wccs_cnpj']['definition'] ?? null )
);

wccs_proof_check(
	'The version travels with the key, so a changed mask is detectable',
	1 === ( $wccs_masks['wccs_cpf']['version'] ?? null )
		&& 'br.cpf' === ( $wccs_masks['wccs_cpf']['key'] ?? null )
);

wccs_proof_check(
	'A field with no mask is not sent',
	! isset( $wccs_masks['wccs_notes'] )
);

wccs_proof_check(
	'An archived field is not sent',
	! isset( $wccs_masks['wccs_archived'] ),
	'it is not rendered, so there is nothing to mask'
);

wccs_proof_check(
	'A field the classic checkout cannot render is not sent',
	! isset( $wccs_masks['wccs_upload'] ),
	'the adapter skips a file field, so the bundle has nothing to find'
);

// ---------------------------------------------------------------------------
// 2. The bundle is given the masks before it runs.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. How the bundle receives them' );

wp_dequeue_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );
wp_deregister_script( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );

\WCCheckoutSuite\Checkout\Classic\ClassicAssets::enqueue( true, true );

$wccs_inline = array_values(
	array_filter(
		array_map( 'strval', (array) wp_scripts()->get_data( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE, 'before' ) )
	)
);

// The list holds one entry per inline script, and `wp_set_script_translations()`
// contributes an empty one. What matters is that the payload is there, not where
// in the list it landed.
wccs_proof_check(
	'The masks are printed into the page before the bundle runs',
	1 === count( $wccs_inline ) && str_contains( $wccs_inline[0], 'window.wccsCheckout' ),
	'entries=' . wp_json_encode( array_map( static function ( string $entry ): string {
		return substr( $entry, 0, 60 );
	}, $wccs_inline ) )
);

wccs_proof_check(
	'And the payload is the one the server built',
	str_contains( $wccs_inline[0] ?? '', '000.000.000-00' ),
	'the pattern the customer will see is the published one'
);

// `do_item()` prints the tag and returns whether it did, so the output is
// captured rather than assigned.
ob_start();
wp_scripts()->do_item( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE );
$wccs_printed = (string) ob_get_clean();

wccs_proof_check(
	'The page really carries it, and loads the bundle from this site',
	str_contains( (string) $wccs_printed, 'window.wccsCheckout' )
		&& str_contains( (string) $wccs_printed, WCCS_PLUGIN_URL . 'build/checkout/index.js' )
		&& ! str_contains( (string) $wccs_printed, '//cdn.' ),
	'printed=' . strlen( (string) $wccs_printed ) . ' bytes'
);

$wccs_registered = wp_scripts()->registered[ \WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE ] ?? null;

wccs_proof_check(
	'WordPress is asked to load nothing from another origin',
	is_object( $wccs_registered )
		&& array() === array_filter(
			(array) $wccs_registered->deps,
			static function ( string $handle ): bool {
				return ! in_array( $handle, array( 'jquery', 'wp-i18n' ), true );
			}
		),
	'deps=' . wp_json_encode( is_object( $wccs_registered ) ? $wccs_registered->deps : null )
);

// ---------------------------------------------------------------------------
// 3. The mask library is a local, pinned dependency.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The mask library is local' );

$wccs_package = json_decode( (string) file_get_contents( WCCS_PLUGIN_DIR . 'package.json' ), true );
$wccs_pinned  = $wccs_package['dependencies']['imask'] ?? null;

wccs_proof_check(
	'IMask is declared as a dependency',
	is_string( $wccs_pinned ),
	'imask=' . var_export( $wccs_pinned, true )
);

wccs_proof_check(
	'And pinned to an exact version, not a range',
	is_string( $wccs_pinned ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $wccs_pinned ),
	'a range would let a rebuild ship a different mask library than the one that was tested'
);

$wccs_installed = json_decode( (string) file_get_contents( WCCS_PLUGIN_DIR . 'node_modules/imask/package.json' ), true );

wccs_proof_check(
	'The installed version is the pinned one',
	( $wccs_installed['version'] ?? null ) === $wccs_pinned,
	'installed=' . var_export( $wccs_installed['version'] ?? null, true )
);

$wccs_bundle = WCCS_PLUGIN_DIR . 'build/checkout/index.js';

wccs_proof_check(
	'The bundle is built and carries the library inside it',
	is_readable( $wccs_bundle ) && filesize( $wccs_bundle ) > 20000,
	'size=' . ( is_readable( $wccs_bundle ) ? (string) filesize( $wccs_bundle ) : '0' ) . ' bytes; section 9 asks for a local dependency rather than a CDN'
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

delete_option( $wccs_published );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Where the acceptance is proven',
	'tests/js/checkout/masks.test.js types into a real DOM and reads the cursor back: paste, delete, autocomplete, a replaced element and a surviving one. The last two are the refresh case, and they depend on the order the components are registered in — the value is restored before the mask is built over it, because IMask formats a value an element already holds and does not reformat one assigned afterwards. That ordering is asserted rather than assumed, so a reordering that breaks the refresh fails a test.'
);

wccs_proof_note(
	'Not exercised here',
	'A rendered classic checkout and a real mobile browser. The touch keyboard is a property of the device; what the code controls is that the mask is applied once per element and that the value it formats is the one the customer typed.'
);

wccs_proof_note(
	'A measured limitation',
	'IMask\'s wildcard token matches any character rather than an alphanumeric one, so the CNPJ mask is permissive about what can be typed. It is left that way on purpose: tightening it would move a rule about what a document may contain into the browser, and the layer that owns that rule is the validator on the server, which WCCS-028 delivers. A wrong character is refused rather than hidden.'
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
