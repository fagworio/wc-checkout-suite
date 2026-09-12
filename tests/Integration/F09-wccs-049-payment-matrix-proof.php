<?php
/**
 * WCCS-049 proof harness — the payment homology matrix.
 *
 * Task:   WCCS-049 "Homologar gateways e express"
 * Phase:  F09 · Página customizada e pagamento
 * Accept: "Sandbox, 3DS/redirect, tokens salvos, retry e dados required verificados."
 *
 * The acceptance is a list of observations, and this environment cannot make any of
 * them: no gateway is enabled and no sandbox credentials exist, which is the blocker
 * SANDBOX-PAYMENT. What this harness proves is everything that has to be true BEFORE
 * the observation can be trusted, and it is the half that a matrix written after the
 * fact always gets wrong:
 *
 * 1. the record is the store's real gateway list and not a wish list — every gateway
 *    the store offers has an answer, and on this store every answer is "undecided";
 * 2. a mode without a passing scenario, or recorded for another version, is not a
 *    homologation — asserted on the shipped record and on a forged one;
 * 3. the answer reaches the checkout: the payload carries a decision per gateway, and
 *    the presentation applies a decoration only when the record did not withhold it;
 * 4. the plugin promises nothing about a gateway nobody ran, which is the sentence
 *    ROADMAP.md section 15 writes and the one this task exists to make checkable.
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

$wccs_matrix = 'WCCheckoutSuite\\Domain\\Payments\\PaymentMatrix';
$wccs_mode   = 'WCCheckoutSuite\\Domain\\Payments\\PaymentMode';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-049 proof — the payment homologation matrix' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The record itself.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the record declares' );

wccs_proof_check(
	'The record is readable and its path is the one this plugin ships',
	is_readable( WCCS_PLUGIN_DIR . $wccs_matrix::FILE ),
	$wccs_matrix::FILE
);

wccs_proof_check(
	'It describes the scenarios a homologation can be recorded against',
	count( $wccs_matrix::scenarios() ) >= 6,
	'scenarios=' . wp_json_encode( $wccs_matrix::scenarios() )
);

wccs_proof_check(
	'And the decorations the code can actually withhold — no more than that',
	array( 'panel' ) === $wccs_matrix::decorations(),
	'a vocabulary entry nothing honours would read like a promise: ' . wp_json_encode( $wccs_matrix::decorations() )
);

$wccs_claimed = array();

foreach ( $wccs_matrix::rows() as $wccs_id => $wccs_row ) {
	if ( array() !== $wccs_matrix::tested( $wccs_row ) ) {
		$wccs_claimed[] = (string) $wccs_id;
	}
}

wccs_proof_check(
	'The shipped record claims no passing scenario for anything',
	array() === $wccs_claimed,
	'claimed=' . wp_json_encode( $wccs_claimed ) . ' — the record of a phase that could not run its observation says so'
);

// ---------------------------------------------------------------------------
// 2. Every gateway the store offers has an answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What the store actually offers' );

$wccs_gateways = array();

if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $wccs_gateway ) {
		if ( is_object( $wccs_gateway ) && isset( $wccs_gateway->id ) ) {
			$wccs_gateways[] = $wccs_gateway;
		}
	}
}

$wccs_decisions = array();
$wccs_modes     = array();

foreach ( $wccs_gateways as $wccs_gateway ) {
	$wccs_decision = $wccs_matrix::decide_for( $wccs_gateway );

	$wccs_decisions[ (string) $wccs_gateway->id ] = $wccs_decision;
	$wccs_modes[]                                 = $wccs_decision['mode'];
}

wccs_proof_check(
	'The store has gateways and every one of them got an answer',
	array() !== $wccs_gateways && count( $wccs_decisions ) === count( $wccs_gateways ),
	'gateways=' . count( $wccs_decisions )
);

wccs_proof_check(
	'And the answer for every one of them is that it was not homologated',
	array() !== $wccs_modes && array( $wccs_mode::UNDECIDED ) === array_values( array_unique( $wccs_modes ) ),
	'modes=' . wp_json_encode( array_count_values( $wccs_modes ) ) . ' — SANDBOX-PAYMENT: no gateway is enabled and no credentials exist'
);

$wccs_promised = array();

foreach ( $wccs_decisions as $wccs_id => $wccs_decision ) {
	if ( $wccs_mode::is_homologated( $wccs_decision['mode'] ) ) {
		$wccs_promised[] = (string) $wccs_id;
	}
}

wccs_proof_check(
	'So the plugin promises compatibility with none of them',
	array() === $wccs_promised,
	'promised=' . wp_json_encode( $wccs_promised )
);

wccs_proof_check(
	'An undecided gateway is still given every decoration — nothing is withheld without a record',
	array() === array_filter(
		$wccs_decisions,
		static function ( array $decision ): bool {
			return array() !== $decision['withheld'];
		}
	) && in_array( 'panel', $wccs_decisions ? reset( $wccs_decisions )['decorations'] : array(), true )
);

wccs_proof_check(
	'And the gateway identifier travels with the answer, so the report names it',
	array() === array_diff( array_keys( $wccs_decisions ), array_map(
		static function ( $gateway ) {
			return (string) $gateway->id;
		},
		$wccs_gateways
	) )
);

// ---------------------------------------------------------------------------
// 3. A claim without evidence is not a homologation.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Evidence decides, and the version is part of it' );

$wccs_property = new ReflectionProperty( $wccs_matrix, 'record' );
$wccs_property->setAccessible( true );
$wccs_shipped = $wccs_matrix::record();

$wccs_forged = array(
	'scenario_vocabulary'   => array( 'sandbox' => 'An order placed in the gateway sandbox completes.' ),
	'decoration_vocabulary' => array( 'panel' => 'The box around the gateway markup.' ),
	'gateways'              => array(
		array(
			'id'      => 'wccs_proof_gateway',
			'version' => '1.0.0',
			'mode'    => $wccs_mode::DECORATED,
			'tested'  => array(),
		),
	),
);

$wccs_property->setValue( null, $wccs_forged );

wccs_proof_check(
	'A row that declares a mode and names no passing scenario is undecided',
	$wccs_mode::UNDECIDED === $wccs_matrix::decision( 'wccs_proof_gateway', '1.0.0' )['mode'],
	'the rule that keeps the record from being a wish list'
);

$wccs_forged['gateways'][0]['tested'] = array( 'sandbox' );
$wccs_property->setValue( null, $wccs_forged );

wccs_proof_check(
	'With the scenario behind it, the same row decides',
	$wccs_mode::DECORATED === $wccs_matrix::decision( 'wccs_proof_gateway', '1.0.0' )['mode']
);

$wccs_other = $wccs_matrix::decision( 'wccs_proof_gateway', '2.0.0' );

wccs_proof_check(
	'And it stops deciding for a version it was not observed on',
	$wccs_mode::UNDECIDED === $wccs_other['mode']
		&& str_contains( $wccs_other['reason'], '1.0.0' )
		&& str_contains( $wccs_other['reason'], '2.0.0' ),
	'reason=' . $wccs_other['reason']
);

$wccs_forged['gateways'][0]['mode']     = $wccs_mode::COMPATIBLE;
$wccs_forged['gateways'][0]['withheld'] = array( 'panel', 'a-decoration-nobody-declared' );
$wccs_property->setValue( null, $wccs_forged );

$wccs_compatible = $wccs_matrix::decision( 'wccs_proof_gateway', '1.0.0' );

wccs_proof_check(
	'A compatible record withholds only a decoration the code declares',
	array( 'panel' ) === $wccs_compatible['withheld'] && array() === $wccs_compatible['decorations'],
	'withheld=' . wp_json_encode( $wccs_compatible['withheld'] )
);

$wccs_property->setValue( null, $wccs_shipped );

wccs_proof_check(
	'And the record is left as it was found',
	array() === array_filter( $wccs_matrix::rows(), static function ( array $row ): bool {
		return array() !== $wccs_matrix::tested( $row );
	} )
);

// ---------------------------------------------------------------------------
// 4. The answer reaches the checkout.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Where a merchant and a browser see it' );

$wccs_payload = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();

// The payload answers for the gateways this checkout will actually present, which is
// the available list and not the installed one: a store with seven gateways configured
// and none enabled has nothing to present, and a payload that listed the seven would be
// describing a checkout the customer does not get.
$wccs_available = WC()->payment_gateways()
	? array_map( 'strval', array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) )
	: array();

wccs_proof_check(
	'The payload carries a decision for exactly the gateways the checkout offers',
	array_map( 'strval', array_keys( (array) ( $wccs_payload['payments']['decisions'] ?? array() ) ) ) === $wccs_available,
	'available=' . wp_json_encode( $wccs_available ) . ' decisions=' . wp_json_encode( array_keys( (array) ( $wccs_payload['payments']['decisions'] ?? array() ) ) )
);

wccs_proof_check(
	'And it invents no gateway: every decision names something installed',
	array() === array_diff(
		array_map( 'strval', array_keys( (array) ( $wccs_payload['payments']['decisions'] ?? array() ) ) ),
		array_map(
			static function ( $gateway ) {
				return (string) $gateway->id;
			},
			$wccs_gateways
		)
	)
);

wccs_proof_check(
	'And the count of gateways nobody ran travels with it, so the gap is visible',
	isset( $wccs_payload['payments']['undecided'] )
		&& $wccs_payload['payments']['undecided'] === count( $wccs_available ),
	'undecided=' . ( $wccs_payload['payments']['undecided'] ?? -1 ) . ' of ' . count( $wccs_available ) . ' offered'
);

wccs_proof_check(
	'What the payload describes is this store: seven installed, none presented',
	count( $wccs_gateways ) === 7 && array() === $wccs_available
		? 0 === count( $wccs_payload['payments']['decisions'] )
		: count( $wccs_available ) === count( $wccs_payload['payments']['decisions'] ),
	'installed=' . count( $wccs_gateways ) . ' offered=' . count( $wccs_available )
);

wccs_proof_check(
	'Each decision carries the mode and what was withheld, and nothing else',
	array() === array_filter(
		$wccs_payload['payments']['decisions'],
		static function ( array $decision ): bool {
			$keys = array_keys( $decision );
			sort( $keys );

			return array( 'mode', 'withheld' ) !== $keys;
		}
	),
	'shape=' . wp_json_encode( array_keys( (array) reset( $wccs_payload['payments']['decisions'] ) ) )
);

$wccs_component = (string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/checkout/payment.js' );

wccs_proof_check(
	'The component reads the decision and applies a decoration only when it was not withheld',
	str_contains( $wccs_component, 'withheld' )
		&& str_contains( $wccs_component, 'data-wccs-mode' )
		&& str_contains( $wccs_component, 'data-wccs-payment-panel' )
);

$wccs_entry = (string) file_get_contents( WCCS_PLUGIN_DIR . 'resources/checkout/index.js' );

wccs_proof_check(
	'And the decision is handed to it by the entry point, which is where the payload is read',
	str_contains( $wccs_entry, 'bootstrap.payments' )
);

wccs_proof_note(
	'What this harness cannot do, and why the task is not closed',
	'The acceptance is five observations — sandbox, 3DS or redirect, saved tokens, retry and required data — and every one of them needs a gateway enabled with sandbox credentials. No gateway on this store is enabled and none has credentials, which is the blocker SANDBOX-PAYMENT, recorded since WCCS-004. What is proven here is the record and its decision, which is what makes those observations trustworthy when they happen: a matrix that cannot promise without evidence, and a checkout that cannot promise without the matrix.'
);

wccs_proof_note(
	'Why the version is part of the observation',
	'A row recorded for 4.1.3 says nothing about the 4.2.0 installed today. A matrix that matched on the identifier alone would quietly promote a stale run to a promise about different code, and the failure would look like a gateway breaking for no reason.'
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
