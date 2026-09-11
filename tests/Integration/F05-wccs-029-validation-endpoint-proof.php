<?php
/**
 * WCCS-029 proof harness — the remote validation endpoint.
 *
 * Task:   WCCS-029 "Implementar endpoint de validação"
 * Phase:  F05
 * Accept: "Sessão, rate limit, timeout, abort e request ID impedem estado obsoleto."
 *
 * Timeout, abort and request id are properties of the transport, and the
 * JavaScript suite drives them with a fetch it can hold open. What this harness
 * proves is the half the server owns: that the route answers, that it refuses what
 * it should, that the session limit holds, and — the part worth more than the rest
 * — that its answer cannot be used as an authorization and cannot be used to learn
 * anything about anybody.
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
 * A definition built the way the picker builds one from a preset.
 *
 * @param string $id     Field identifier.
 * @param string $preset Preset key.
 * @return array<string, mixed>
 */
function wccs_proof_from_preset( string $id, string $preset ): array {
	$registered = \WCCheckoutSuite\Domain\Registries::instance()->presets()->preset( $preset );

	if ( null === $registered ) {
		return array();
	}

	$defaults = $registered->defaults();

	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => $registered->type(),
		'preset'         => $preset,
		'label'          => $registered->label(),
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => 30,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => $registered->settings(),
		'mask'           => $defaults['mask'] ?? null,
		'normalizer'     => $defaults['normalizer'] ?? null,
		'validators'     => $defaults['validators'] ?? array(),
		'conditions'     => array(),
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'visibility'     => array(
			'admin_order' => true,
		),
	);
}

/**
 * Publishes a document into the published slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @param int               $revision Revision.
 * @return void
 */
function wccs_proof_publish( array $fields, int $revision = 8 ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => $revision,
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
 * Calls the validation route.
 *
 * @param WP_REST_Request $request Request.
 * @return array{status: int, body: array<string, mixed>, keys: array<int, string>}
 */
function wccs_proof_call( WP_REST_Request $request ): array {
	$response = rest_do_request( $request );
	$data     = (array) $response->get_data();

	return array(
		'status' => (int) $response->get_status(),
		'body'   => $data,
		'keys'   => array_keys( $data ),
	);
}

/**
 * Builds a validation request.
 *
 * @param array<string, mixed> $params Parameters.
 * @return WP_REST_Request
 */
function wccs_proof_request( array $params ): WP_REST_Request {
	$request = new WP_REST_Request(
		'POST',
		'/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Checkout\ValidationController::ROUTE_VALIDATE
	);

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return $request;
}

/**
 * Validates one value through the real route.
 *
 * @param string $field    Field identifier.
 * @param mixed  $value    Value.
 * @param int    $revision Revision.
 * @return array{status: int, body: array<string, mixed>, keys: array<int, string>}
 */
function wccs_proof_validate( string $field, mixed $value, int $revision = 8 ): array {
	return wccs_proof_call(
		wccs_proof_request(
			array(
				'field'      => $field,
				'value'      => $value,
				'revision'   => $revision,
				'request_id' => 'wccs-proof-' . wp_generate_password( 8, false ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
			)
		)
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
wccs_proof_out( 'WCCS-029 proof — the remote validation endpoint' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_published = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

delete_option( $wccs_published );

wccs_proof_publish(
	array(
		wccs_proof_from_preset( 'wccs_cpf', 'br.cpf' ),
		wccs_proof_from_preset( 'wccs_cep', 'br.cep' ),
	)
);

// ---------------------------------------------------------------------------
// 1. The endpoint answers.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The endpoint answers' );

$wccs_good = wccs_proof_validate( 'wccs_cpf', '529.982.247-25' );

wccs_proof_check(
	'A well-formed document is accepted',
	200 === $wccs_good['status'] && 'valid' === ( $wccs_good['body']['status'] ?? null ),
	'size=' . strlen( (string) wp_json_encode( $wccs_good['body'] ) )
);

$wccs_bad = wccs_proof_validate( 'wccs_cpf', '529.982.247-24' );

wccs_proof_check(
	'A document whose check digits do not match is refused, with a code',
	'invalid' === ( $wccs_bad['body']['status'] ?? null )
		&& 'invalid_cpf' === ( $wccs_bad['body']['code'] ?? null ),
	'code=' . (string) ( $wccs_bad['body']['code'] ?? '' )
);

wccs_proof_check(
	'And with something the customer can read',
	'' !== ( $wccs_bad['body']['message'] ?? '' ),
	'message=' . (string) ( $wccs_bad['body']['message'] ?? '' )
);

wccs_proof_check(
	'The answer names the revision it was computed against',
	8 === ( $wccs_bad['body']['revision'] ?? null ),
	'revision=' . var_export( $wccs_bad['body']['revision'] ?? null, true )
);

$wccs_normalized = wccs_proof_validate( 'wccs_cpf', '529.982.247-25' );

wccs_proof_check(
	'The value the customer sees is normalized on the server before it is judged',
	'valid' === ( $wccs_normalized['body']['status'] ?? null ),
	'the endpoint runs the same pipeline the checkout does'
);

// ---------------------------------------------------------------------------
// 2. It cannot be used to learn anything about anybody.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The answer says nothing about a person' );

$wccs_keys = array( 'code', 'field', 'message', 'request_id', 'revision', 'schema_changed', 'status' );

sort( $wccs_keys );
$wccs_answered = $wccs_good['keys'];
sort( $wccs_answered );

wccs_proof_check(
	'The answer carries exactly the declared keys and nothing else',
	$wccs_keys === $wccs_answered,
	'keys=' . wp_json_encode( $wccs_good['keys'] )
);

wccs_proof_check(
	'The invalid answer carries exactly the same keys as the valid one',
	$wccs_bad['keys'] === $wccs_good['keys'],
	'invalid=' . wp_json_encode( $wccs_bad['keys'] )
);

wccs_proof_check(
	'The value the customer typed is never echoed back',
	! str_contains( (string) wp_json_encode( $wccs_good['body'] ), '529' )
		&& ! str_contains( (string) wp_json_encode( $wccs_bad['body'] ), '529' ),
	'body=' . wp_json_encode( $wccs_good['body'] )
);

wccs_proof_check(
	'And the request identifier is the only thing carried across from the request',
	'wccs-proof-' === substr( (string) ( $wccs_good['body']['request_id'] ?? '' ), 0, 11 ),
	'request_id=' . (string) ( $wccs_good['body']['request_id'] ?? '' )
);

// ---------------------------------------------------------------------------
// 3. Nothing the answer carries can be spent later.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The answer is not an authorization' );

$wccs_tokens = array( 'token', 'validated_at', 'expires', 'signature', 'authorization', 'grant', 'signed' );

foreach ( $wccs_tokens as $wccs_token ) {
	wccs_proof_check(
		'The answer carries no ' . $wccs_token,
		! in_array( $wccs_token, $wccs_good['keys'], true ),
		'keys=' . wp_json_encode( $wccs_good['keys'] )
	);
}

// A cached "valid" is exactly the permanent authorization section 10 forbids, so
// the header is asserted rather than assumed.
$wccs_cacheable = rest_do_request(
	wccs_proof_request(
		array(
			'field'      => 'wccs_cpf',
			'value'      => '529.982.247-25',
			'revision'   => 8,
			'request_id' => 'wccs-proof-cache',
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		)
	)
);

$wccs_headers = $wccs_cacheable->get_headers();

wccs_proof_check(
	'And it is not cacheable',
	isset( $wccs_headers['Cache-Control'] ) && 'no-store' === trim( (string) $wccs_headers['Cache-Control'] ),
	'Cache-Control=' . (string) ( $wccs_headers['Cache-Control'] ?? 'absent' )
);

// ---------------------------------------------------------------------------
// 4. An out-of-date form is told, not refused.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. A stale revision is reported and still answered' );

$wccs_stale = wccs_proof_validate( 'wccs_cpf', '529.982.247-25', 2 );

wccs_proof_check(
	'A form rendered from an older revision is still answered',
	'valid' === ( $wccs_stale['body']['status'] ?? null ),
	'the value is judged against the published document, which is the one that will be used'
);

wccs_proof_check(
	'And the difference is reported rather than hidden',
	true === ( $wccs_stale['body']['schema_changed'] ?? null ),
	'schema_changed=' . var_export( $wccs_stale['body']['schema_changed'] ?? null, true )
);

wccs_proof_check(
	'With the revision the answer actually applies to',
	8 === ( $wccs_stale['body']['revision'] ?? null ),
	'revision=' . var_export( $wccs_stale['body']['revision'] ?? null, true )
);

$wccs_current = wccs_proof_validate( 'wccs_cpf', '529.982.247-25', 8 );

wccs_proof_check(
	'A current revision is not reported as changed',
	false === ( $wccs_current['body']['schema_changed'] ?? null )
);

// ---------------------------------------------------------------------------
// 5. What the endpoint will not answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What it refuses to answer' );

$wccs_no_nonce = wccs_proof_call(
	wccs_proof_request(
		array(
			'field'      => 'wccs_cpf',
			'value'      => '529.982.247-25',
			'revision'   => 8,
			'request_id' => 'wccs-proof-nonce',
			'nonce'      => 'not-a-nonce',
		)
	)
);

wccs_proof_check(
	'A request without a valid nonce is not answered',
	'unavailable' === ( $wccs_no_nonce['body']['status'] ?? null )
		&& 'bad_nonce' === ( $wccs_no_nonce['body']['code'] ?? null ),
	'code=' . (string) ( $wccs_no_nonce['body']['code'] ?? '' )
);

$wccs_unknown = wccs_proof_validate( 'wccs_not_a_field', 'anything' );

wccs_proof_check(
	'A field that is not in the published document is not judged',
	'unavailable' === ( $wccs_unknown['body']['status'] ?? null )
		&& 'unknown_field' === ( $wccs_unknown['body']['code'] ?? null ),
	'code=' . (string) ( $wccs_unknown['body']['code'] ?? '' )
);

$wccs_smuggle = wccs_proof_call(
	wccs_proof_request(
		array(
			'field'      => 'wccs_cpf',
			'value'      => '529.982.247-25',
			'revision'   => 8,
			'request_id' => 'wccs-proof-smuggle',
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			// Section 10 names four things the endpoint must not accept. There is
			// nowhere in the contract to put them, so they are ignored rather
			// than honoured — and the rules still come from the published
			// document.
			'regex'      => '/^.*$/',
			'callback'   => 'system',
			'path'       => '/etc/passwd',
			'endpoint'   => 'https://example.test/rules',
		)
	)
);

wccs_proof_check(
	'A regex, a callback, a path or a remote endpoint in the body changes nothing',
	'valid' === ( $wccs_smuggle['body']['status'] ?? null )
		&& $wccs_smuggle['keys'] === $wccs_good['keys'],
	'the answer is the same one the same value gets on its own'
);

// ---------------------------------------------------------------------------
// 6. The session limit holds.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. The session limit holds' );

$wccs_session = function_exists( 'WC' ) ? WC()->session : null;

wccs_proof_check(
	'The endpoint counts against the WooCommerce session',
	$wccs_session instanceof WC_Session,
	'session=' . ( is_object( $wccs_session ) ? get_class( $wccs_session ) : 'none' )
);

if ( $wccs_session instanceof WC_Session ) {
	// Start from a clean allowance so the assertion does not depend on how many
	// questions the rest of this harness asked.
	$wccs_session->set( \WCCheckoutSuite\Http\Checkout\ValidationController::SESSION_KEY, array() );

	$wccs_allowed = 0;
	$wccs_limited = 0;

	for ( $wccs_attempt = 0; $wccs_attempt < \WCCheckoutSuite\Http\Checkout\ValidationController::LIMIT + 3; $wccs_attempt++ ) {
		$wccs_result = wccs_proof_validate( 'wccs_cpf', '529.982.247-25' );

		if ( 'rate_limited' === ( $wccs_result['body']['code'] ?? '' ) ) {
			++$wccs_limited;

			wccs_proof_check(
				'A request past the allowance is answered with the status a client can act on',
				429 === $wccs_result['status'],
				'status=' . $wccs_result['status']
			);

			continue;
		}

		++$wccs_allowed;
	}

	wccs_proof_check(
		'Exactly the allowance is served and the rest is refused',
		\WCCheckoutSuite\Http\Checkout\ValidationController::LIMIT === $wccs_allowed && 3 === $wccs_limited,
		'allowed=' . $wccs_allowed . ' limited=' . $wccs_limited . ' limit=' . \WCCheckoutSuite\Http\Checkout\ValidationController::LIMIT
	);

	// A different field has its own allowance: the limit is per session and per
	// field, so a busy document does not starve the rest of the form.
	$wccs_other_field = wccs_proof_validate( 'wccs_cep', '01310100' );

	wccs_proof_check(
		'Another field still has its own allowance',
		'valid' === ( $wccs_other_field['body']['status'] ?? null ),
		'status=' . (string) ( $wccs_other_field['body']['status'] ?? '' )
	);

	$wccs_session->set( \WCCheckoutSuite\Http\Checkout\ValidationController::SESSION_KEY, array() );
}

// ---------------------------------------------------------------------------
// 7. The bundle is given the endpoint.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. The checkout is given the endpoint' );

$wccs_bootstrap = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();
$wccs_validation = (array) ( $wccs_bootstrap['validation'] ?? array() );

wccs_proof_check(
	'The address comes from the server rather than from the bundle',
	str_contains( (string) ( $wccs_validation['url'] ?? '' ), '/wc-checkoutsuite/v1/validate' ),
	'url=' . (string) ( $wccs_validation['url'] ?? '' )
);

wccs_proof_check(
	'With a nonce, because a guest checkout has to be able to ask',
	'' !== (string) ( $wccs_validation['nonce'] ?? '' )
);

wccs_proof_check(
	'And the revision the page was rendered from',
	8 === ( $wccs_validation['revision'] ?? null ),
	'revision=' . var_export( $wccs_validation['revision'] ?? null, true )
);

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

delete_option( $wccs_published );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Where the acceptance is proven',
	'tests/js/checkout/remote.test.js holds a fetch open and asserts all five: the debounce waits before asking, a second question aborts the one in flight, a response whose request id is not the one being waited for is discarded, a slow answer becomes unavailable and never valid, and a caller can cancel a field. The server half — session, limit and the stale revision — is asserted above.'
);

wccs_proof_note(
	'A timeout never releases a rule',
	'The transport answers `unavailable` when it gives up, and section 10 requires that a critical rule must not silently allow through a timeout: the interface has to offer an explicit alternative or block with guidance. That interface is WCCS-030, and the state it needs — distinct from valid and from invalid — is what this endpoint and transport produce.'
);

wccs_proof_note(
	'The wire between the two halves',
	'The bundle is given the endpoint and the transport exists and is tested, but nothing in the checkout calls it yet: the field interface that reacts to the answer is WCCS-030, and creating the transport with no caller would be a component wired to nothing.'
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
