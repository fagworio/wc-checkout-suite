<?php
/**
 * Fase 11 proof harness — no action appears without a proven capability.
 *
 * Task:   Fase 11 "Gateway Capability Registry"
 * Gate:   "nenhuma action aparece na UI sem capability comprovada."
 *
 * The unit suite proves the rules of evidence without a store. What it cannot show is that a real
 * store's screens are built from them — so this harness declares capabilities through the same door
 * an extension uses, and then reads the two places an interface would read: the registry's report
 * and the diagnostics payload.
 *
 * 1. **The record the store ships proves nothing**, and a gateway therefore offers only the
 *    platform's pay-for-order page. That is the gate working, not the feature missing: no gateway is
 *    enabled here and no sandbox has run.
 * 2. **A claim is not a capability.** A declaration missing any part of its evidence is refused by
 *    name and reported; a declaration with evidence becomes offerable.
 * 3. **Evidence for another version is stale** and is offered no more than a claim is.
 * 4. **The fallback is always there**, and it is WooCommerce's own page rather than a gateway action
 *    nobody proved.
 * 5. **The two matrices stay apart**: the presentation mode and the transactional actions travel in
 *    one payload from two objects, and neither vocabulary appears in the other.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce loaded.
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

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 11 proof — no action without a proven capability' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_proof_gateway = 'wccs_proof_gateway';

// ---------------------------------------------------------------------------
// 1. What the store ships proves nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. O registo que a loja traz não prova nada' );

$wccs_proof_registry = new \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry();

wccs_proof_check(
	'No capability is declared by the record the store ships',
	array() === $wccs_proof_registry->all(),
	'declared=' . count( $wccs_proof_registry->all() )
);

wccs_proof_check(
	'And a gateway therefore offers nothing but the platform pay-for-order page',
	array( 'pay_for_order' ) === $wccs_proof_registry->offerable( $wccs_proof_gateway, '1.0.0' ),
	'offerable=' . implode( ',', $wccs_proof_registry->offerable( $wccs_proof_gateway, '1.0.0' ) )
);

wccs_proof_check(
	'And the fallback names the link the store sends instead of promising a capture',
	false === $wccs_proof_registry->fallback( $wccs_proof_gateway, 'capture', '1.0.0' )['supported']
		&& 'pay_for_order' === $wccs_proof_registry->fallback( $wccs_proof_gateway, 'capture', '1.0.0' )['fallback'],
	$wccs_proof_registry->fallback( $wccs_proof_gateway, 'capture', '1.0.0' )['reason']
);

// ---------------------------------------------------------------------------
// 2. A claim is not a capability.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Uma declaração sem prova é recusada por nome' );

$wccs_proof_claims = new \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry( false );

$wccs_proof_claims->declare( $wccs_proof_gateway, 'capture', array( 'mode' => 'sandbox' ) );

$wccs_proof_refused = $wccs_proof_claims->refusals();

wccs_proof_check(
	'The claim did not become a capability',
	! $wccs_proof_claims->supports( $wccs_proof_gateway, 'capture', '1.0.0' )
		&& array( 'pay_for_order' ) === $wccs_proof_claims->offerable( $wccs_proof_gateway, '1.0.0' ),
	'offerable=' . implode( ',', $wccs_proof_claims->offerable( $wccs_proof_gateway, '1.0.0' ) )
);

wccs_proof_check(
	'And the refusal names the three parts of the evidence that are missing',
	1 === count( $wccs_proof_refused )
		&& array( 'version', 'scenario', 'proven_at' ) === $wccs_proof_refused[0]['missing'],
	implode( ',', $wccs_proof_refused[0]['missing'] ?? array() )
);

wccs_proof_check(
	'And the refusal says what it means, rather than only what is missing',
	false !== strpos( (string) ( $wccs_proof_refused[0]['reason'] ?? '' ), 'não entra no registo' ),
	(string) ( $wccs_proof_refused[0]['reason'] ?? '' )
);

// ---------------------------------------------------------------------------
// 3. A proven capability is one an interface may show.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. E com prova, a capability passa a ser oferecível' );

$wccs_proof_evidence = array(
	'mode'      => 'sandbox',
	'version'   => '4.2.0',
	'scenario'  => 'capture_authorization',
	'proven_at' => '2026-09-15',
);

$wccs_proof_registry->declare( $wccs_proof_gateway, 'capture', $wccs_proof_evidence, 'Sandbox run on the homologation store.' );

wccs_proof_check(
	'The capability is proven and offerable for the version it was proven against',
	$wccs_proof_registry->supports( $wccs_proof_gateway, 'capture', '4.2.0' )
		&& in_array( 'capture', $wccs_proof_registry->offerable( $wccs_proof_gateway, '4.2.0' ), true ),
	'offerable=' . implode( ',', $wccs_proof_registry->offerable( $wccs_proof_gateway, '4.2.0' ) )
);

wccs_proof_check(
	'And it is not offerable for another version, because the evidence is about the one it names',
	! $wccs_proof_registry->supports( $wccs_proof_gateway, 'capture', '4.3.0' )
		&& array( 'pay_for_order' ) === $wccs_proof_registry->offerable( $wccs_proof_gateway, '4.3.0' ),
	'offerable=' . implode( ',', $wccs_proof_registry->offerable( $wccs_proof_gateway, '4.3.0' ) )
);

$wccs_proof_report = $wccs_proof_registry->report( $wccs_proof_gateway, '4.3.0' );
$wccs_proof_rows   = array();

foreach ( $wccs_proof_report['actions'] as $wccs_proof_entry ) {
	$wccs_proof_rows[ (string) $wccs_proof_entry['action'] ] = $wccs_proof_entry;
}

wccs_proof_check(
	'And the report says it is stale rather than merely absent',
	true === ( $wccs_proof_rows['capture']['declared'] ?? null )
		&& true === ( $wccs_proof_rows['capture']['proven'] ?? null )
		&& true === ( $wccs_proof_rows['capture']['stale'] ?? null )
		&& false === ( $wccs_proof_rows['capture']['offerable'] ?? null ),
	'stale=' . wp_json_encode( $wccs_proof_rows['capture']['stale'] ?? null )
);

wccs_proof_check(
	'And the capability carries its evidence wherever it is read',
	'sandbox' === (string) ( $wccs_proof_rows['capture']['evidence']['mode'] ?? '' )
		&& '4.2.0' === (string) ( $wccs_proof_rows['capture']['evidence']['version'] ?? '' )
		&& 'capture_authorization' === (string) ( $wccs_proof_rows['capture']['evidence']['scenario'] ?? '' ),
	wp_json_encode( $wccs_proof_rows['capture']['evidence'] ?? null )
);

wccs_proof_check(
	'And an action nobody declared is reported as undeclared, with the whole evidence list missing',
	false === ( $wccs_proof_rows['authorize']['declared'] ?? null )
		&& false === ( $wccs_proof_rows['authorize']['offerable'] ?? null )
		&& 4 === count( (array) ( $wccs_proof_rows['authorize']['missing'] ?? array() ) ),
	'missing=' . implode( ',', (array) ( $wccs_proof_rows['authorize']['missing'] ?? array() ) )
);

// ---------------------------------------------------------------------------
// 4. The extension point an adapter uses.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. O ponto de extensão que um adapter usa' );

/**
 * Declares a capability the way an extension does, and one claim it should refuse.
 *
 * @param \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry $registry Registry.
 * @return void
 */
function wccs_proof_declare( $registry ): void {
	$registry->declare(
		'wccs_proof_gateway',
		'create_after_approval',
		array(
			'mode'      => 'sandbox',
			'version'   => '2.1.0',
			'scenario'  => 'create_after_approval',
			'proven_at' => '2026-09-15',
		),
		'PIX generated after an approval.'
	);

	// A gateway that declares a capture it never proved: the registry must refuse it, and the
	// refusal must be reported where the declaration was made.
	$registry->declare( 'wccs_proof_gateway', 'authorize', array( 'mode' => 'sandbox', 'version' => '2.1.0' ) );
}

add_action( \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry::REGISTER_ACTION, 'wccs_proof_declare' );

$wccs_proof_registered = new \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry();

wccs_proof_check(
	'An extension declares what its gateway proved, and it is offerable',
	in_array( 'create_after_approval', $wccs_proof_registered->offerable( 'wccs_proof_gateway', '2.1.0' ), true ),
	'offerable=' . implode( ',', $wccs_proof_registered->offerable( 'wccs_proof_gateway', '2.1.0' ) )
);

wccs_proof_check(
	'And the claim it made without evidence is refused, not accepted and ignored',
	1 === count( $wccs_proof_registered->refused_declarations() )
		&& 'authorize' === (string) $wccs_proof_registered->refused_declarations()[0]['action']
		&& ! $wccs_proof_registered->supports( 'wccs_proof_gateway', 'authorize', '2.1.0' ),
	'action=' . (string) ( $wccs_proof_registered->refused_declarations()[0]['action'] ?? '' )
);

wccs_proof_check(
	'And the fallback for the action it could not prove is the platform page',
	'pay_for_order' === $wccs_proof_registered->fallback( 'wccs_proof_gateway', 'capture', '2.1.0' )['fallback']
);

remove_action( \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry::REGISTER_ACTION, 'wccs_proof_declare' );

// ---------------------------------------------------------------------------
// 5. What a screen reads, from two objects.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. O ecrã lê as duas matrizes, de dois objetos' );

$wccs_proof_state = ( new \WCCheckoutSuite\Http\Admin\SettingsController() )->state();

wccs_proof_check(
	'The diagnostics payload names every action the store knows',
	count( (array) ( $wccs_proof_state['capabilities']['actions'] ?? array() ) ) === count( \WCCheckoutSuite\Domain\Payments\GatewayCapabilities::actions() ),
	'actions=' . count( (array) ( $wccs_proof_state['capabilities']['actions'] ?? array() ) )
);

wccs_proof_check(
	'And the vocabulary carries the fallback and the evidence fields, so an editor cannot invent either',
	'pay_for_order' === (string) ( $wccs_proof_state['capabilities']['fallback'] ?? '' )
		&& array( 'mode', 'version', 'scenario', 'proven_at' ) === (array) ( $wccs_proof_state['capabilities']['evidence'] ?? array() ),
	'fallback=' . (string) ( $wccs_proof_state['capabilities']['fallback'] ?? '' )
);

$wccs_proof_gateways = (array) ( $wccs_proof_state['gateways'] ?? array() );

wccs_proof_check(
	'And every gateway the store has reports both its presentation mode and its transactional actions',
	array() === array_filter(
		$wccs_proof_gateways,
		static fn( array $entry ): bool => ! isset( $entry['mode'] ) || ! isset( $entry['offerable'] )
	),
	'gateways=' . count( $wccs_proof_gateways ) . ' ids=' . implode( ',', array_column( $wccs_proof_gateways, 'id' ) )
);

wccs_proof_check(
	'And no gateway offers an action the store did not prove',
	array() === array_filter(
		$wccs_proof_gateways,
		static function ( array $entry ): bool {
			foreach ( (array) ( $entry['actions'] ?? array() ) as $action ) {
				if ( ! empty( $action['offerable'] ) && empty( $action['proven'] ) && empty( $action['fallback'] ) ) {
					return true;
				}
			}

			return false;
		}
	),
	'offered=' . implode(
		',',
		array_map(
			static fn( array $entry ): string => (string) ( $entry['id'] ?? '' ) . ':' . implode( '|', (array) ( $entry['offerable'] ?? array() ) ),
			$wccs_proof_gateways
		)
	)
);

wccs_proof_check(
	'And the presentation vocabulary appears nowhere in the transactional one',
	array() === array_intersect(
		array( 'decorated', 'compatible', 'unavailable', 'undecided' ),
		(array) ( $wccs_proof_state['capabilities']['modes'] ?? array() )
	)
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

wccs_proof_check(
	'The harness left no hook behind',
	false === has_action( \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry::REGISTER_ACTION, 'wccs_proof_declare' )
);

wccs_proof_check(
	'And the record the store ships was not modified',
	array() === ( new \WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry() )->all()
);

wccs_proof_note(
	'Where the gate lives',
	'No registo e não no ecrã. Uma capability entra no registo apenas com modo, versão, cenário e data; sem os quatro, `declare()` recusa-a por nome e guarda a razão. Como o ecrã lê a lista do registo, uma ação sem prova não tem por onde aparecer — a interface não tem de se lembrar da regra, porque nunca vê a ação.'
);

wccs_proof_note(
	'Porque duas matrizes e não uma',
	'§17: «Manter para compatibilidade visual/homologação da apresentação. Não transformar esse objeto em matriz de capture/authorize.» São duas perguntas com provas diferentes: «posso decorar a área de pagamento deste gateway» e «posso pedir-lhe para capturar». Uma decisão de apresentação lida como permissão para mover dinheiro é a falha que a separação evita, e é por isso que os dois registos são ficheiros diferentes e um teste unitário afirma que os vocabulários não se cruzam.'
);

wccs_proof_note(
	'O que continua a faltar depois desta fase',
	'Executar. O registo diz o que um gateway provou; quem executa é o PaymentActionService da fase seguinte, e é ele que passa a poder oferecer as estratégias de pagamento que a §13.2 passo 4 desenha — por gateway, e só onde a capability existe. «Pay for order» é a única ação disponível hoje, e é a da própria WooCommerce.'
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
