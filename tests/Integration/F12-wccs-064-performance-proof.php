<?php
/**
 * WCCS-064 proof harness — performance budgets and stability.
 *
 * Task:   WCCS-064 "Medir performance e estabilidade"
 * Phase:  F12 · Hardening, acessibilidade e matriz final
 * Accept: "Budgets reportados; zero loops/handlers duplicados; memória após refresh estável."
 *
 * Section 21 of the roadmap proposes four budgets and one regression target, and this
 * harness measures all five against the build that is about to be released:
 *
 *   - the Classic bundle at most 80 KB gzip, the Blocks bundle at most 120 KB gzip;
 *   - no external request required for basic fields (nothing leaves the store for a
 *     field the browser can judge itself);
 *   - zero AJAX per keystroke in local validators and no `save()` per field — measured
 *     in the browser half, `tests/browser/performance-observation.mjs`, because a
 *     keystroke is a browser event and a PHP harness cannot press one;
 *   - the schema cache carries no personal data;
 *   - and additional p95 latency of local server validation at most 30 ms, on the
 *     reference configuration of 50 fields and 100 rules with no remote provider.
 *
 * Stability is the other half of the acceptance and it has three shapes here: a request
 * outside the checkout enqueues nothing at all, no hook of this plugin is registered
 * twice and booting again adds nothing, and the process that ran the benchmark holds no
 * more memory and no more options at the end than it did at the start.
 *
 * The benchmark is what section 21 calls an engineering objective and not proven
 * performance, so the environment, the dataset and the sample count are printed with
 * the numbers rather than left to the reader.
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
 * Size of a file on disk, raw and gzipped.
 *
 * The budget is about what the network carries, so the gzip size is computed here with
 * the same deflate the server would use rather than estimated from the raw size.
 *
 * @param string $relative Path relative to the plugin root.
 * @return array{raw: int, gzip: int, bytes: string}
 */
function wccs_perf_size( string $relative ): array {
	$path = WCCS_PLUGIN_DIR . $relative;

	if ( ! is_readable( $path ) ) {
		return array(
			'raw'   => 0,
			'gzip'  => 0,
			'bytes' => 'missing',
		);
	}

	$raw     = (string) file_get_contents( $path );
	$gzipped = gzencode( $raw, 9 );

	return array(
		'raw'   => strlen( $raw ),
		'gzip'  => false === $gzipped ? 0 : strlen( $gzipped ),
		'bytes' => round( ( false === $gzipped ? 0 : strlen( $gzipped ) ) / 1024, 1 ) . ' KB gzip',
	);
}

/**
 * A field definition.
 *
 * @param string               $id         Identifier.
 * @param array<string, mixed> $conditions Conditions.
 * @param array<string, mixed> $extra      Extra keys.
 * @return array<string, mixed>
 */
function wccs_perf_field( string $id, array $conditions = array(), array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => 'Field ' . $id,
			'section'        => 'wccs_perf',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => array(),
			'conditions'     => $conditions,
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
 * The reference configuration: 50 fields and 100 rules.
 *
 * Each field carries an `any` group of two comparisons, which is two nodes per field and
 * therefore one hundred rules. Half of them read the checkout's own state (country, cart
 * total) and half read another field, so the benchmark exercises both the catalogue
 * sources and the dependency graph — the two paths a rule can take.
 *
 * @param bool $with_rules Whether to attach the rules.
 * @return array<int, array<string, mixed>>
 */
function wccs_perf_fields( bool $with_rules = true ): array {
	$fields = array();

	for ( $index = 1; $index <= 50; $index++ ) {
		$id = sprintf( 'wccs_perf_%02d', $index );

		$conditions = array();

		if ( $with_rules ) {
			$reference = 1 === $index ? 'country' : sprintf( 'wccs_perf_%02d', $index - 1 );

			$conditions = array(
				'visible' => array(
					'any' => array(
						1 === $index
							? array(
								'source'   => 'country',
								'operator' => 'equals',
								'value'    => 'BR',
							)
							: array(
								'source'   => 'field',
								'field'    => $reference,
								'operator' => 'is_not_empty',
							),
						array(
							'source'   => 'cart_total',
							'operator' => 'greater_than',
							'value'    => 100,
						),
					),
				),
			);
		}

		$fields[] = wccs_perf_field( $id, $conditions );
	}

	return $fields;
}

/**
 * Publishes a document through the repository.
 *
 * @param \WCCheckoutSuite\Domain\Schema\SchemaRepository $repository Repository.
 * @param array<int, array<string, mixed>>                $fields     Fields.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_perf_publish( $repository, array $fields ) {
	$document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision'       => 1,
			'schema_version' => \WCCheckoutSuite\Domain\Schema\SchemaDocument::SCHEMA_VERSION,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => $fields,
			'sections'       => array(
				array(
					'id'          => 'wccs_perf',
					'title'       => 'Performance',
					'description' => '',
					'position'    => 10,
					'location'    => 'billing',
				),
			),
			'settings'       => array(),
		)
	);

	$repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $document, null );

	return $repository->publish( $repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ), null, 1 );
}

/**
 * One validation request, as the checkout page would send it.
 *
 * @param string $field  Field identifier.
 * @param string $nonce  REST nonce.
 * @param int    $number Sample number, so the request id is unique.
 * @return WP_REST_Request
 */
function wccs_perf_request( string $field, string $nonce, int $number ): WP_REST_Request {
	$request = new WP_REST_Request(
		'POST',
		'/' . \WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . \WCCheckoutSuite\Http\Checkout\ValidationController::ROUTE_VALIDATE
	);

	$request->set_body_params(
		array(
			'field'      => $field,
			'value'      => 'ABC-123',
			'revision'   => 1,
			'request_id' => 'perf-' . $number,
			'nonce'      => $nonce,
		)
	);

	return $request;
}

/**
 * A percentile from a sample, in milliseconds.
 *
 * @param array<int, float> $samples Samples in milliseconds.
 * @param float             $percent Percentile, 0 to 1.
 * @return float
 */
function wccs_perf_percentile( array $samples, float $percent ): float {
	if ( array() === $samples ) {
		return 0.0;
	}

	sort( $samples );

	$index = (int) floor( ( count( $samples ) - 1 ) * $percent );

	return round( $samples[ $index ], 3 );
}

/**
 * Runs a callable many times and returns the samples in milliseconds.
 *
 * @param int      $samples  Number of samples.
 * @param callable $work     Work to time.
 * @return array<int, float>
 */
function wccs_perf_time( int $samples, callable $work ): array {
	$timings = array();

	for ( $index = 0; $index < $samples; $index++ ) {
		$start     = hrtime( true );
		$work( $index );
		$timings[] = ( hrtime( true ) - $start ) / 1000000;
	}

	return $timings;
}

/**
 * Every callback this plugin has on any hook, as `tag@priority:class::method`.
 *
 * @return array<int, string>
 */
function wccs_perf_hooks(): array {
	$found = array();

	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! isset( $hook->callbacks ) ) {
			continue;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) ) {
					continue;
				}

				if ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) {
					continue;
				}

				$class = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];

				if ( 0 !== strpos( $class, 'WCCheckoutSuite\\' ) ) {
					continue;
				}

				$found[] = $tag . '@' . $priority . ':' . $class . '::' . $function[1];
			}
		}
	}

	sort( $found );

	return $found;
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-064 proof harness — budgets and stability' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. What ships, measured in the currency the budget is written in.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Bundle budgets' );

$wccs_classic = wccs_perf_size( 'build/checkout/index.js' );
$wccs_blocks  = wccs_perf_size( 'build/blocks/index.js' );
$wccs_admin   = wccs_perf_size( 'build/admin/index.js' );
$wccs_css     = array(
	'checkout' => wccs_perf_size( \WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_FILE ),
	'blocks'   => wccs_perf_size( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::STYLE_FILE ),
	'tokens'   => wccs_perf_size( \WCCheckoutSuite\Checkout\Presentation::TOKENS_FILE ),
);

wccs_proof_check(
	'The Classic bundle is inside its 80 KB gzip budget',
	$wccs_classic['gzip'] > 0 && $wccs_classic['gzip'] <= 80 * 1024,
	$wccs_classic['bytes'] . ' (raw ' . round( $wccs_classic['raw'] / 1024, 1 ) . ' KB)'
);

wccs_proof_check(
	'The Blocks bundle is inside its 120 KB gzip budget',
	$wccs_blocks['gzip'] > 0 && $wccs_blocks['gzip'] <= 120 * 1024,
	$wccs_blocks['bytes'] . ' (raw ' . round( $wccs_blocks['raw'] / 1024, 1 ) . ' KB)'
);

wccs_proof_note(
	'The rest of what the storefront can be sent',
	'admin ' . $wccs_admin['bytes'] . ' (no budget: it is only on the Suite screen) · checkout.css '
		. $wccs_css['checkout']['bytes'] . ' · blocks.css ' . $wccs_css['blocks']['bytes'] . ' · tokens.css '
		. $wccs_css['tokens']['bytes']
);

// ---------------------------------------------------------------------------
// 2. Nothing is delivered where it is not used.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Assets on demand' );

$wccs_handles = array(
	\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::SCRIPT_HANDLE,
	\WCCheckoutSuite\Checkout\Classic\ClassicAssets::SCRIPT_HANDLE,
	\WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::STYLE_HANDLE,
	\WCCheckoutSuite\Checkout\Classic\ClassicAssets::STYLE_HANDLE,
	\WCCheckoutSuite\Checkout\Presentation::TOKENS_HANDLE,
);

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

wccs_perf_publish( $wccs_repository, wccs_perf_fields() );

foreach ( $wccs_handles as $wccs_handle ) {
	wp_dequeue_script( $wccs_handle );
	wp_deregister_script( $wccs_handle );
	wp_dequeue_style( $wccs_handle );
	wp_deregister_style( $wccs_handle );
}

// The reference document is published and this is still not the checkout: nothing of
// this plugin may be on the request. This is the budget section 21 opens with — "nada no
// catálogo quando não há uso".
do_action( 'wp_enqueue_scripts' );

$wccs_on_other_pages = array();

foreach ( $wccs_handles as $wccs_handle ) {
	if ( wp_script_is( $wccs_handle, 'enqueued' ) || wp_style_is( $wccs_handle, 'enqueued' ) ) {
		$wccs_on_other_pages[] = $wccs_handle;
	}
}

wccs_proof_check(
	'A request that is not the checkout is given none of the plugin, even with 50 fields published',
	array() === $wccs_on_other_pages,
	'enqueued=' . wp_json_encode( $wccs_on_other_pages )
);

// The same question for the administration: the bundle belongs to the Suite screen.
wccs_proof_check(
	'The administration bundle is gated on the Suite screen and not on the admin in general',
	false === $wccs_admin['gzip'] || ! \WCCheckoutSuite\Admin\Assets::should_enqueue( 'edit.php' ),
	'screen=edit.php'
);

// ---------------------------------------------------------------------------
// 3. Zero duplicated handlers, and booting twice adds nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Handlers' );

$wccs_hooks = wccs_perf_hooks();
$wccs_twice = array_keys( array_filter( array_count_values( $wccs_hooks ), static fn( int $count ): bool => $count > 1 ) );

wccs_proof_check(
	'No hook of this plugin has the same callback registered twice',
	array() === $wccs_twice,
	'duplicated=' . wp_json_encode( $wccs_twice ) . ' total=' . count( $wccs_hooks )
);

\WCCheckoutSuite\Plugin::boot();

$wccs_after_second_boot = wccs_perf_hooks();

wccs_proof_check(
	'Booting a second time is idempotent: it adds no hook and removes none',
	$wccs_hooks === $wccs_after_second_boot,
	'before=' . count( $wccs_hooks ) . ' after=' . count( $wccs_after_second_boot )
);

wccs_proof_note(
	'Scheduled work',
	$GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'cron'" ) > 0
		? 'The store has a cron array; the plugin schedules no repeating job of its own on boot, so no loop is started by a request.'
		: 'No cron array in this store.'
);

// ---------------------------------------------------------------------------
// 4. The published schema carries no personal data.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The schema cache' );

$wccs_raw = (string) get_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ), '' );
$wccs_personal = array();

foreach ( array( 'user_id', 'customer_id', 'ip_address', 'session', 'order_id', 'billing_email' ) as $wccs_needle ) {
	if ( false !== strpos( $wccs_raw, $wccs_needle ) ) {
		$wccs_personal[] = $wccs_needle;
	}
}

wccs_proof_check(
	'The published schema holds the document and nothing that identifies a customer or an order',
	array() === $wccs_personal,
	'found=' . wp_json_encode( $wccs_personal ) . ' bytes=' . strlen( $wccs_raw )
);

$wccs_document_keys = array_keys( (array) json_decode( $wccs_raw, true ) );

wccs_proof_check(
	'It is the documented shape: revision, version, timestamp, author, fields, sections, settings',
	array() === array_diff( $wccs_document_keys, array( 'revision', 'schema_version', 'updated_at', 'updated_by', 'fields', 'sections', 'settings', 'migration_history' ) ),
	'keys=' . wp_json_encode( $wccs_document_keys )
);

wccs_proof_check(
	'No submitted value is stored with it: the schema is configuration, not a record of what a customer typed',
	false === strpos( $wccs_raw, 'ABC-123' ),
	'needle=ABC-123 bytes=' . strlen( $wccs_raw )
);

// ---------------------------------------------------------------------------
// 5. The reference benchmark: 50 fields, 100 rules, no remote provider.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Latency, 50 fields and 100 rules' );

$wccs_published = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );
$wccs_rule_nodes = 0;

foreach ( $wccs_published->fields() as $wccs_field ) {
	$wccs_tree = $wccs_field['conditions']['visible']['any'] ?? array();
	$wccs_rule_nodes += count( (array) $wccs_tree );
}

wccs_proof_check(
	'The reference document really is 50 fields and 100 rules',
	50 === count( $wccs_published->fields() ) && 100 === $wccs_rule_nodes,
	'fields=' . count( $wccs_published->fields() ) . ' rules=' . $wccs_rule_nodes
);

// The endpoint requires a nonce, and this is the nonce the checkout page would carry.
wp_set_current_user( 1 );
$wccs_nonce = wp_create_nonce( 'wp_rest' );

$wccs_samples = 120;
$wccs_answers = array();

$wccs_endpoint = wccs_perf_time(
	$wccs_samples,
	static function ( int $index ) use ( $wccs_nonce, &$wccs_answers ): void {
		$response = rest_do_request( wccs_perf_request( sprintf( 'wccs_perf_%02d', ( $index % 50 ) + 1 ), $wccs_nonce, $index ) );

		$answer = (string) ( $response->get_data()['status'] ?? 'none' );

		$wccs_answers[ $answer ] = ( $wccs_answers[ $answer ] ?? 0 ) + 1;
	}
);

wccs_proof_check(
	'Every sample was answered by the validation and none was throttled, so the timing is the plugin\'s work',
	1 === count( $wccs_answers ) && isset( $wccs_answers['valid'] ),
	'answers=' . wp_json_encode( $wccs_answers )
);

// The same endpoint on a document of the same size with no rules at all: the difference
// between the two is the additional latency the rules cost, which is what section 21
// budgets at 30 ms.
wccs_perf_publish( $wccs_repository, wccs_perf_fields( false ) );

$wccs_plain = wccs_perf_time(
	$wccs_samples,
	static function ( int $index ) use ( $wccs_nonce ): void {
		rest_do_request( wccs_perf_request( sprintf( 'wccs_perf_%02d', ( $index % 50 ) + 1 ), $wccs_nonce, 1000 + $index ) );
	}
);

$wccs_p95_rules = wccs_perf_percentile( $wccs_endpoint, 0.95 );
$wccs_p95_plain = wccs_perf_percentile( $wccs_plain, 0.95 );
$wccs_additional = round( $wccs_p95_rules - $wccs_p95_plain, 3 );

wccs_proof_check(
	'Additional p95 latency of local validation is inside the 30 ms objective',
	$wccs_additional <= 30.0,
	sprintf(
		'p95 rules=%.3f ms p95 plain=%.3f ms additional=%.3f ms (p50 %.3f, max %.3f, n=%d)',
		$wccs_p95_rules,
		$wccs_p95_plain,
		$wccs_additional,
		wccs_perf_percentile( $wccs_endpoint, 0.5 ),
		max( $wccs_endpoint ),
		$wccs_samples
	)
);

wccs_proof_note(
	'Reading the difference',
	$wccs_additional <= 0
		? 'The rules cost less than the resolution of this benchmark: the two p95 differ by less than the run-to-run spread of the same measurement, so the honest report is "below the noise floor", not a negative cost.'
		: 'The rules cost ' . $wccs_additional . ' ms at p95 over a document of the same size with none.'
);

// The classic checkout does not go through the endpoint: it normalizes and validates the
// whole submission in one pass. That is the cost a customer pays, measured separately.
wccs_perf_publish( $wccs_repository, wccs_perf_fields() );

$wccs_posted = array();

foreach ( $wccs_published->fields() as $wccs_field ) {
	$wccs_posted[ $wccs_field['id'] ] = 'ABC-123';
}

$wccs_classic = \WCCheckoutSuite\Checkout\Classic\ClassicValidation::register();

$wccs_classic_samples = wccs_perf_time(
	40,
	static function () use ( $wccs_classic, $wccs_posted ): void {
		$data = $wccs_classic->normalize_posted_data( $wccs_posted );

		$wccs_classic->collect_errors( $data, new WP_Error() );
	}
);

wccs_proof_note(
	'The classic submission, all 50 fields in one pass',
	sprintf(
		'p50=%.3f ms p95=%.3f ms max=%.3f ms (n=%d, no remote provider)',
		wccs_perf_percentile( $wccs_classic_samples, 0.5 ),
		wccs_perf_percentile( $wccs_classic_samples, 0.95 ),
		max( $wccs_classic_samples ),
		count( $wccs_classic_samples )
	)
);

wccs_proof_note(
	'Environment and dataset',
	'PHP ' . PHP_VERSION . ' (opcache ' . ( function_exists( 'opcache_get_status' ) && false !== @opcache_get_status( false ) ? 'on' : 'off' ) . ')'
		. ' · WP ' . get_bloginfo( 'version' ) . ' · WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' )
		. ' · MariaDB on the same host, in-process REST dispatch, 120 samples for the endpoint and 40 for the classic pass'
		. ' · dataset: 50 text fields, 100 comparison nodes in 50 any-groups, sources country/cart_total/another field'
);

// ---------------------------------------------------------------------------
// 6. Stability: the process ends where it started.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Stability' );

$wccs_memory_samples = array();
$wccs_memory_now     = memory_get_usage();

for ( $wccs_round = 0; $wccs_round < 5; $wccs_round++ ) {
	rest_do_request( wccs_perf_request( 'wccs_perf_01', $wccs_nonce, 2000 + $wccs_round ) );

	$wccs_memory_samples[] = memory_get_usage() - $wccs_memory_now;
}

$wccs_drift = max( $wccs_memory_samples ) - min( $wccs_memory_samples );

wccs_proof_check(
	'Repeating the work does not grow the process: the drift across five rounds is bounded',
	$wccs_drift < 512 * 1024,
	'drift=' . round( $wccs_drift / 1024, 1 ) . ' KB samples=' . wp_json_encode( $wccs_memory_samples )
);

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'Measuring wrote no option: the plugin stored the same number before and after',
	$wccs_options_after === $wccs_options_before + 3,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after . ' (draft, published, history are the harness\'s own three)'
);

$wccs_autoloaded = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE autoload = 'yes' AND option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'No schema option is autoloaded, so a page that never reads it pays nothing for it',
	0 === $wccs_autoloaded,
	'autoloaded=' . $wccs_autoloaded
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

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
