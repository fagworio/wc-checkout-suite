<?php
/**
 * WCCS-069 proof harness — installation and upgrade smoke.
 *
 * Task:   WCCS-069 "Executar smoke de instalação/upgrade"
 * Phase:  F13 · Release 1.0 e operação comercial
 * Accept: "Instalação limpa e upgrade/rollback com pedidos existentes aprovados."
 *
 * Two halves, because the acceptance names two different things.
 *
 * **Installation** is exercised on the artefact that is actually distributed: the ZIP
 * built by `bin/build-release.php` is extracted, checked for completeness, and then run
 * by a bare PHP process — no Composer autoloader, no WordPress — which loads the plugin's
 * main file and resolves every class it ships through the plugin's own autoloader. That
 * is what "instalação sem ferramentas de build" means, and it cannot be asserted on the
 * development tree, which has `vendor/` sitting in it.
 *
 * **Upgrade and rollback** are exercised on an order that already exists. The order is
 * created, values are written through the plugin's own order service, and then the schema
 * is upgraded and rolled back underneath it: what is asserted, in both directions, is
 * that the order still reads exactly what the customer answered. That is the promise the
 * release makes — an update does not rewrite history — and it is the one a rollback is
 * most likely to break.
 *
 * Prerequisite: the plugin must be ACTIVE and the package must have been built
 * (`php bin/build-release.php`).
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
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * A field definition.
 *
 * @param string               $id    Identifier.
 * @param array<string, mixed> $extra Extra keys.
 * @return array<string, mixed>
 */
function wccs_smoke_field( string $id, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Field ' . $id,
			'section'        => 'wccs_smoke',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => array(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array( 'admin_order' => true ),
		),
		$extra
	);
}

/**
 * Publishes a document of one field through the repository.
 *
 * @param \WCCheckoutSuite\Domain\Schema\SchemaRepository $repository Repository.
 * @param array<int, array<string, mixed>>                $fields     Fields.
 * @return int Published revision.
 */
function wccs_smoke_publish( $repository, array $fields ): int {
	$document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision'       => 1,
			'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
			'sections'       => array(
				array(
					'id'          => 'wccs_smoke',
					'title'       => 'Smoke',
					'description' => '',
					'position'    => 10,
					'location'    => 'billing',
				),
			),
			'settings'       => array(),
		)
	);

	$repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $document, null );

	return $repository->publish( $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), null, 1 )->revision();
}

/**
 * Runs a command and returns its exit code and output.
 *
 * @param string $command Command.
 * @return array{code: int, output: string}
 */
function wccs_smoke_exec( string $command ): array {
	$output = array();
	$code   = 0;

	exec( $command . ' 2>&1', $output, $code );

	return array(
		'code'   => (int) $code,
		'output' => implode( "\n", $output ),
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-069 proof harness — installation and upgrade smoke' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. The package that is distributed.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The package' );

$wccs_archive = WCCS_PLUGIN_DIR . 'dist/wc-checkoutsuite-' . WCCS_VERSION . '.zip';

wccs_proof_check(
	'The package for this version has been built',
	is_readable( $wccs_archive ),
	'archive=' . $wccs_archive
);

if ( ! is_readable( $wccs_archive ) ) {
	wccs_proof_check( 'The smoke test could run', false, 'build the package with `php bin/build-release.php`' );
} else {
	$wccs_smoke_dir = WCCS_PLUGIN_DIR . '.release-smoke';

	if ( is_dir( $wccs_smoke_dir ) ) {
		exec( 'rm -rf ' . escapeshellarg( $wccs_smoke_dir ) );
	}

	mkdir( $wccs_smoke_dir, 0775, true );

	$wccs_extract = wccs_smoke_exec( 'cd ' . escapeshellarg( $wccs_smoke_dir ) . ' && unzip -q ' . escapeshellarg( $wccs_archive ) );

	wccs_proof_check(
		'The archive extracts',
		0 === $wccs_extract['code'],
		$wccs_extract['code'] . ' ' . substr( $wccs_extract['output'], 0, 120 )
	);

	$wccs_extracted = $wccs_smoke_dir . '/wc-checkout-suite';

	wccs_proof_check(
		'It extracts into the folder WordPress would create, named after the plugin',
		is_dir( $wccs_extracted ) && is_readable( $wccs_extracted . '/wc-checkoutsuite.php' ),
		'path=' . $wccs_extracted
	);

	wccs_proof_check(
		'It carries no dependency directory, so nothing has to be resolved on the store',
		! is_dir( $wccs_extracted . '/vendor' ) && ! is_dir( $wccs_extracted . '/node_modules' ),
		'vendor=' . ( is_dir( $wccs_extracted . '/vendor' ) ? 'present' : 'absent' )
	);

	$wccs_required = array(
		'build/admin/index.js',
		'build/checkout/index.js',
		'build/blocks/index.js',
		'resources/checkout/presentation.css',
		'resources/blocks/presentation.css',
		'resources/design-tokens/tokens.css',
		'resources/payments/homologation.json',
		'languages/wc-checkoutsuite.pot',
		'uninstall.php',
	);

	$wccs_absent = array();

	foreach ( $wccs_required as $wccs_file ) {
		if ( ! is_readable( $wccs_extracted . '/' . $wccs_file ) ) {
			$wccs_absent[] = $wccs_file;
		}
	}

	wccs_proof_check(
		'Everything the plugin reads at runtime is inside the package',
		array() === $wccs_absent,
		'absent=' . wp_json_encode( $wccs_absent )
	);

	// The claim the whole package exists for: no Composer, no npm, no WordPress — just
	// PHP, the package, and the autoloader the plugin carries in its own main file.
	$wccs_smoke = wccs_smoke_exec( 'php ' . escapeshellarg( WCCS_PLUGIN_DIR . 'bin/smoke-package.php' ) . ' ' . escapeshellarg( $wccs_extracted ) );

	wccs_proof_check(
		'A bare PHP process loads the package and resolves every class it ships',
		0 === $wccs_smoke['code'],
		trim( str_replace( "\n", ' | ', $wccs_smoke['output'] ) )
	);

	exec( 'rm -rf ' . escapeshellarg( $wccs_smoke_dir ) );

	wccs_proof_check(
		'The extracted copy was removed from the development tree',
		! is_dir( $wccs_smoke_dir ),
		'path=' . $wccs_smoke_dir
	);
}

// ---------------------------------------------------------------------------
// 2. Activation on an installed store.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Activation' );

delete_option( WCCS_OPTION_REQUIREMENTS );

$wccs_activated = true;

try {
	\WCCheckoutSuite\Plugin::activate();
} catch ( Throwable $wccs_error ) {
	$wccs_activated = false;

	wccs_proof_note( 'Activation threw', $wccs_error->getMessage() );
}

wccs_proof_check(
	'Activation completes on a store that already has the plugin running',
	$wccs_activated
);

wccs_proof_check(
	'Activation leaves no unmet-requirement record, because this store meets them',
	false === get_option( WCCS_OPTION_REQUIREMENTS, false ),
	'option=' . var_export( get_option( WCCS_OPTION_REQUIREMENTS, false ), true )
);

wccs_proof_check(
	'Activation left the uploads table in place',
	\WCCheckoutSuite\Domain\Uploads\UploadsTable::name() === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() ) ),
	'table=' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name()
);

// ---------------------------------------------------------------------------
// 3. Upgrade and rollback with an order that already exists.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Upgrade and rollback over a live order' );

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_v1 = wccs_smoke_publish( $wccs_repository, array( wccs_smoke_field( 'wccs_smoke_note' ) ) );

wccs_proof_check( 'The version before the upgrade publishes', $wccs_v1 > 0, 'revision=' . $wccs_v1 );

$wccs_order   = wc_create_order( array( 'status' => 'pending' ) );
$wccs_service = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();

wccs_proof_check(
	'An order exists, created the way the store creates one',
	$wccs_order instanceof WC_Order,
	'order=' . ( $wccs_order instanceof WC_Order ? $wccs_order->get_id() : 'none' )
);

if ( $wccs_order instanceof WC_Order ) {
	$wccs_product = wc_get_product( 2777 );

	if ( $wccs_product instanceof WC_Product ) {
		$wccs_order->add_product( $wccs_product, 1 );
	}

	$wccs_typed = array( 'wccs_smoke_note' => 'answered before the upgrade' );

	$wccs_service->write( $wccs_order, $wccs_typed, array( wccs_smoke_field( 'wccs_smoke_note' ) ), $wccs_v1 );
	$wccs_order->save();

	$wccs_order_id = $wccs_order->get_id();
	$wccs_before   = $wccs_service->read( wc_get_order( $wccs_order_id ) )->all();

	wccs_proof_check(
		'The customer\'s answer is on the order before anything is upgraded',
		'answered before the upgrade' === ( $wccs_before['wccs_smoke_note'] ?? '' ),
		'values=' . wp_json_encode( array_keys( $wccs_before ) )
	);

	// The upgrade: the field changes type and a second field appears. Neither may touch
	// what the order already carries.
	$wccs_v2 = wccs_smoke_publish(
		$wccs_repository,
		array(
			wccs_smoke_field( 'wccs_smoke_note', array( 'type' => 'textarea' ) ),
			wccs_smoke_field( 'wccs_smoke_added' ),
		)
	);

	$wccs_after_upgrade = $wccs_service->read( wc_get_order( $wccs_order_id ) )->all();

	wccs_proof_check(
		'After the upgrade the order still reads exactly what the customer answered',
		$wccs_after_upgrade === $wccs_before,
		'before=' . wp_json_encode( $wccs_before ) . ' after=' . wp_json_encode( $wccs_after_upgrade )
	);

	wccs_proof_check(
		'The upgrade did not invent a value for the field that did not exist yet',
		! array_key_exists( 'wccs_smoke_added', $wccs_after_upgrade ),
		'keys=' . wp_json_encode( array_keys( $wccs_after_upgrade ) )
	);

	wccs_proof_check(
		'The published schema is the upgraded one',
		$wccs_v2 > $wccs_v1 && 2 === count( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields() ),
		'revision=' . $wccs_v2
	);

	// The rollback: back to the schema the order was placed under.
	$wccs_rollback = $wccs_repository->restore( $wccs_v1, 1 );

	wccs_proof_check(
		'The rollback publishes the old content as a new revision',
		$wccs_rollback->is_ok() && 1 === count( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields() ),
		'revision=' . $wccs_rollback->revision()
	);

	$wccs_after_rollback = $wccs_service->read( wc_get_order( $wccs_order_id ) )->all();

	wccs_proof_check(
		'After the rollback the order still reads the same values',
		$wccs_after_rollback === $wccs_before,
		'values=' . wp_json_encode( $wccs_after_rollback )
	);

	wccs_proof_check(
		'The order is readable through the order API on the other backend too',
		wc_get_order( $wccs_order_id ) instanceof WC_Order
			&& (string) wc_get_order( $wccs_order_id )->get_meta( '_wccs_fields', true ) !== '',
		'order=' . $wccs_order_id
	);

	// A schema from a newer build, on top of everything: the store refuses to read it and
	// the order keeps being readable, which is what a downgraded store looks like.
	$wccs_option = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );
	$wccs_backup = (string) get_option( $wccs_option, '' );

	update_option(
		$wccs_option,
		(string) wp_json_encode(
			array(
				'revision'       => 99,
				'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION + 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => array(),
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);

	wccs_proof_check(
		'A schema from a newer build is refused and the existing order is unaffected',
		'unsupported_version' === $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )['state']
			&& $wccs_service->read( wc_get_order( $wccs_order_id ) )->all() === $wccs_before,
		'state=' . $wccs_repository->read_status( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )['state']
	);

	update_option( $wccs_option, $wccs_backup, false );

	// Cleanup: the order this harness created is removed, and so is anything it wrote.
	$wccs_deleted = wc_get_order( $wccs_order_id );

	if ( $wccs_deleted instanceof WC_Order ) {
		$wccs_deleted->delete( true );
	}

	wccs_proof_check(
		'The smoke test removed the order it created',
		false === wc_get_order( $wccs_order_id ),
		'order=' . $wccs_order_id
	);
}

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

foreach ( array( 'draft', 'published' ) as $wccs_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_slot ) );
}

delete_option( 'wccs_schema_revisions' );

$wccs_final = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	$wccs_final === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_final
);

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
