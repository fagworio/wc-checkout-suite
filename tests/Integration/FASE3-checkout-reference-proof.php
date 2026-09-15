<?php
/**
 * Fase 3 proof harness — the checkout the store already runs.
 *
 * Task:   Fase 3 "Checkout atual como referência"
 * Gate:   "checkout existente pode ser gerenciado sem reconstrução manual."
 *
 * The screen half is proven in the browser (`tests/browser/fase3-checkout-reference.mjs`).
 * What this harness proves is the server half, which the screen depends on:
 *
 * 1. **The store's checkout can be read at all**, through the same inventory the screen
 *    reads: the standard sections, and for each field the identity, the label, the type,
 *    whether it is required and the priority WooCommerce runs it at (§6.2).
 * 2. **Which checkout the store runs is answered from the store** — `blocks` or `classic` —
 *    and the administration bootstrap carries that answer, because what a native field may
 *    be changed into depends on it (§6.7).
 * 3. **Adopting a native field produces a document the server accepts**, with WooCommerce's
 *    own identity and priority — the definition the screen builds for "usar esta seção"
 *    (§6.5, §6.7). Adopting is not copying: the value keeps living in the WooCommerce field.
 * 4. The harness leaves nothing behind.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce must be loaded.
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
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

/**
 * The repository the routes use.
 *
 * @return \WCCheckoutSuite\Domain\Schema\SchemaRepository
 */
function wccs_ref_repository(): \WCCheckoutSuite\Domain\Schema\SchemaRepository {
	return new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);
}

/**
 * Writes one document through the real draft route.
 *
 * @param array<int, array<string, mixed>> $sections Containers.
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @return array{status: int, codes: array<int, string>}
 */
function wccs_ref_write( array $sections, array $fields ): array {
	$repository = wccs_ref_repository();

	$request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		(string) wp_json_encode(
			array(
				'schema'            => array(
					'revision' => 0,
					'fields'   => $fields,
					'sections' => $sections,
					'settings' => array(),
				),
				'expected_revision' => $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->revision(),
			)
		)
	);

	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$codes    = array();

	if ( is_array( $data ) && isset( $data['data']['errors'] ) && is_array( $data['data']['errors'] ) ) {
		$data['errors'] = $data['data']['errors'];
	}

	foreach ( (array) ( $data['errors'] ?? array() ) as $index => $error ) {
		if ( is_array( $error ) && isset( $error['code'] ) ) {
			$codes[] = (string) $error['code'];
		} elseif ( is_string( $index ) ) {
			$codes[] = $index;
		}
	}

	return array(
		'status' => (int) $response->get_status(),
		'codes'  => $codes,
	);
}

/**
 * One native field of the store's checkout, adopted.
 *
 * This is the definition the screen writes when the merchant says "usar esta seção": the
 * identity is WooCommerce's own, the order is the priority WooCommerce runs the field at, and
 * the origin says the field belongs to the platform.
 *
 * @param array<string, mixed> $entry Inventory entry.
 * @return array<string, mixed>
 */
function wccs_ref_adopt( array $entry ): array {
	return array(
		'id'             => (string) $entry['id'],
		'integration_id' => (string) $entry['id'],
		'origin'         => 'core',
		'type'           => (string) $entry['type'],
		'preset'         => null,
		'label'          => (string) $entry['label'],
		'description'    => '',
		'section'        => (string) $entry['section'],
		'enabled'        => true,
		'required'       => (bool) $entry['required'],
		'position'       => (int) $entry['priority'],
		'layout'         => is_array( $entry['layout'] ) ? $entry['layout'] : array(),
		'settings'       => array(),
		'mask'           => null,
		'normalizer'     => null,
		'conditions'     => array(),
		'hidden_value_policy' => 'discard',
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'destinations'   => array(),
		'collection_surface' => 'checkout',
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 3 proof — the checkout the store already runs' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_ref_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. The store's checkout, read live.
// ---------------------------------------------------------------------------
$wccs_ref_core = new \WCCheckoutSuite\Domain\Checkout\CoreFields();
$wccs_ref_list = $wccs_ref_core->catalogue();

wccs_proof_check(
	'The store checkout can be read',
	true === $wccs_ref_list['available'],
	'available=' . ( $wccs_ref_list['available'] ? 'yes' : 'no' ) . ' reason=' . $wccs_ref_list['reason']
);

$wccs_ref_keys = array_map(
	static function ( array $section ): string {
		return (string) $section['key'];
	},
	$wccs_ref_list['sections']
);

wccs_proof_check(
	'It reports the sections WooCommerce declares',
	count( $wccs_ref_list['sections'] ) >= 4,
	implode( ',', $wccs_ref_keys )
);

$wccs_ref_flat = $wccs_ref_list['fields'];

wccs_proof_check(
	'Every field carries the identity, the label, the type and the priority',
	array() !== $wccs_ref_flat &&
		! in_array( '', array_column( $wccs_ref_flat, 'id' ), true ) &&
		! in_array( '', array_column( $wccs_ref_flat, 'label' ), true ) &&
		! in_array( '', array_column( $wccs_ref_flat, 'type' ), true ) &&
		array() !== array_filter( $wccs_ref_flat, static fn( array $entry ): bool => $entry['priority'] > 0 ),
	'fields=' . count( $wccs_ref_flat )
);

$wccs_ref_first    = $wccs_ref_list['sections'][0]['fields'][0] ?? array();
$wccs_ref_second   = ( new \WCCheckoutSuite\Domain\Checkout\CoreFields() )->catalogue();
$wccs_ref_again    = $wccs_ref_second['sections'][0]['fields'][0] ?? array();

wccs_proof_check(
	'Reading it twice answers the same thing',
	$wccs_ref_first === $wccs_ref_again,
	'id=' . ( $wccs_ref_first['id'] ?? '(none)' )
);

// ---------------------------------------------------------------------------
// 2. Which checkout the store runs.
// ---------------------------------------------------------------------------
$wccs_ref_mode = \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::store_checkout_mode();

wccs_proof_check(
	'The store says which checkout it runs',
	in_array( $wccs_ref_mode, array( 'blocks', 'classic' ), true ),
	'mode=' . $wccs_ref_mode
);

$wccs_ref_bootstrap = \WCCheckoutSuite\Admin\Assets::bootstrap_data();

wccs_proof_check(
	'The administration carries that answer to the screen',
	( $wccs_ref_bootstrap['checkoutMode'] ?? '' ) === $wccs_ref_mode,
	'bootstrap=' . ( $wccs_ref_bootstrap['checkoutMode'] ?? '(absent)' ) . ' store=' . $wccs_ref_mode
);

wccs_proof_check(
	'The bootstrap still carries what the shell needs',
	isset( $wccs_ref_bootstrap['mountId'] ) &&
		isset( $wccs_ref_bootstrap['rest']['nonce'] ) &&
		is_array( $wccs_ref_bootstrap['sections'] ),
	'mount=' . ( $wccs_ref_bootstrap['mountId'] ?? '(absent)' )
);

// ---------------------------------------------------------------------------
// 3. Adopting a section of the store's checkout.
// ---------------------------------------------------------------------------
$wccs_ref_section = null;

foreach ( $wccs_ref_list['sections'] as $wccs_ref_candidate ) {
	if ( count( $wccs_ref_candidate['fields'] ) >= 2 ) {
		$wccs_ref_section = $wccs_ref_candidate;
		break;
	}
}

$wccs_ref_adopted = array_map( 'wccs_ref_adopt', $wccs_ref_section['fields'] ?? array() );

$wccs_ref_result = wccs_ref_write( array(), $wccs_ref_adopted );

wccs_proof_check(
	'The document the screen adopts is accepted by the server',
	200 === $wccs_ref_result['status'],
	'section=' . ( $wccs_ref_section['key'] ?? '(none)' ) . ' status=' . $wccs_ref_result['status'] . ' codes=' . implode( ',', $wccs_ref_result['codes'] )
);

$wccs_ref_stored = wccs_ref_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_ref_read   = array();

foreach ( $wccs_ref_stored->fields() as $wccs_ref_raw ) {
	if ( is_array( $wccs_ref_raw ) ) {
		$wccs_ref_read[ (string) $wccs_ref_raw['id'] ] = $wccs_ref_raw;
	}
}

$wccs_ref_ids = array_map(
	static function ( array $entry ): string {
		return (string) $entry['id'];
	},
	$wccs_ref_section['fields'] ?? array()
);

wccs_proof_check(
	'Every field of the section is stored, under the identity WooCommerce uses',
	array_keys( $wccs_ref_read ) === $wccs_ref_ids,
	implode( ',', array_keys( $wccs_ref_read ) )
);

$wccs_ref_priorities = array_map(
	static function ( array $entry ) use ( $wccs_ref_read ): int {
		return (int) ( $wccs_ref_read[ (string) $entry['id'] ]['position'] ?? -1 );
	},
	$wccs_ref_section['fields'] ?? array()
);

wccs_proof_check(
	'And with the order the store runs it at, not a new one',
	$wccs_ref_priorities === array_map(
		static function ( array $entry ): int {
			return (int) $entry['priority'];
		},
		$wccs_ref_section['fields'] ?? array()
	),
	implode( ',', $wccs_ref_priorities )
);

$wccs_ref_origins = array_values(
	array_unique(
		array_map(
			static function ( array $entry ): string {
				return (string) $entry['origin'];
			},
			$wccs_ref_read
		)
	)
);

wccs_proof_check(
	'They are stored as the platform\'s fields, which is what protects them',
	array( 'core' ) === $wccs_ref_origins,
	implode( ',', $wccs_ref_origins )
);

$wccs_ref_labels = array_map(
	static function ( array $entry ) use ( $wccs_ref_read ): string {
		return (string) ( $wccs_ref_read[ (string) $entry['id'] ]['label'] ?? '' );
	},
	$wccs_ref_section['fields'] ?? array()
);

wccs_proof_check(
	'And they keep the labels the store shows',
	$wccs_ref_labels === array_map(
		static function ( array $entry ): string {
			return (string) $entry['label'];
		},
		$wccs_ref_section['fields'] ?? array()
	),
	implode( ' | ', $wccs_ref_labels )
);

// ---------------------------------------------------------------------------
// 4. A native field cannot be deleted by adopting it twice.
// ---------------------------------------------------------------------------
$wccs_ref_twice = wccs_ref_write( array(), $wccs_ref_adopted );

wccs_proof_check(
	'Adopting the same section again changes nothing',
	200 === $wccs_ref_twice['status'],
	'status=' . $wccs_ref_twice['status'] . ' codes=' . implode( ',', $wccs_ref_twice['codes'] )
);

$wccs_ref_after = wccs_ref_repository()->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );
$wccs_ref_count = 0;

foreach ( $wccs_ref_after->fields() as $wccs_ref_raw ) {
	if ( is_array( $wccs_ref_raw ) ) {
		++$wccs_ref_count;
	}
}

wccs_proof_check(
	'And the document still holds one definition per native field',
	$wccs_ref_count === count( $wccs_ref_adopted ),
	'stored=' . $wccs_ref_count . ' adopted=' . count( $wccs_ref_adopted )
);

wccs_proof_note(
	'The browser observation proves the screen half: the real checkout is listed on a first entry and a whole section is adopted in one action.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_ref_options_before,
	'before=' . $wccs_ref_options_before . ' after=' . wccs_proof_option_count()
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
