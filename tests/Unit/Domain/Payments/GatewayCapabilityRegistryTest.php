<?php
/**
 * No action without a proven capability.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Payments;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Payments\GatewayCapabilities;
use WCCheckoutSuite\Domain\Payments\GatewayCapability;
use WCCheckoutSuite\Domain\Payments\GatewayCapabilityRegistry;

/**
 * The gate of Fase 11, as units: a claim is not a capability, and a capability from another version
 * is not one either.
 *
 * The registry reads `resources/payments/capabilities.json` and fires
 * `wccs_register_gateway_capabilities`; both are exercised through `declare()`, which is the same
 * door an extension uses. Nothing here touches WooCommerce, which is the point: the rule that an
 * action cannot reach an interface unproven is decidable without a store.
 */
final class GatewayCapabilityRegistryTest extends TestCase {

	/**
	 * A registry that declares nothing of its own.
	 *
	 * @return GatewayCapabilityRegistry
	 */
	private static function registry(): GatewayCapabilityRegistry {
		$registry = new GatewayCapabilityRegistry();

		// The shipped record is empty on purpose — no gateway is enabled on this store and no
		// sandbox has run — so a test that wants a capability declares one.
		$registry->flush();

		return $registry;
	}

	/**
	 * A complete piece of evidence.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed>
	 */
	private static function evidence( array $overrides = array() ): array {
		return array_merge(
			array(
				'mode'      => 'sandbox',
				'version'   => '4.2.0',
				'scenario'  => 'capture_authorization',
				'proven_at' => '2026-09-15',
			),
			$overrides
		);
	}

	/**
	 * The vocabulary is closed and the fallback is in it.
	 *
	 * @return void
	 */
	public function test_the_action_vocabulary_is_the_one_section_20_names(): void {
		$actions = array_keys( GatewayCapabilities::actions() );

		foreach ( array( 'authorize', 'capture', 'void_authorization', 'refund', 'pay_for_order', 'tokenized_payment' ) as $card ) {
			self::assertContains( $card, $actions, 'cartão: ' . $card );
		}

		foreach ( array( 'create_after_approval', 'regenerate', 'expire', 'pay_for_order' ) as $pix ) {
			self::assertContains( $pix, $actions, 'PIX/boleto: ' . $pix );
		}

		self::assertSame( GatewayCapabilities::PAY_FOR_ORDER, GatewayCapabilities::FALLBACK );
		self::assertTrue( GatewayCapabilities::is_fallback( 'pay_for_order' ) );
		self::assertFalse( GatewayCapabilities::is_fallback( 'capture' ) );
	}

	/**
	 * **A declaration without evidence is refused by name, field by field.**
	 *
	 * @return void
	 */
	public function test_a_claim_without_evidence_is_refused(): void {
		$registry = self::registry();

		self::assertFalse( $registry->declare( 'stripe', 'capture', array() ) );

		$refusals = $registry->refusals();

		self::assertCount( 1, $refusals );
		self::assertSame( 'capture', $refusals[0]['action'] );
		self::assertSame(
			array( 'mode', 'version', 'scenario', 'proven_at' ),
			$refusals[0]['missing']
		);
	}

	/**
	 * Each missing part is reported, not just "invalid".
	 *
	 * @return void
	 */
	public function test_each_missing_part_is_named(): void {
		$registry = self::registry();

		$registry->declare( 'stripe', 'capture', self::evidence( array( 'version' => '' ) ) );

		self::assertSame( array( 'version' ), $registry->refusals()[0]['missing'] );
	}

	/**
	 * An action outside the vocabulary is refused, and so is a scenario nobody can repeat.
	 *
	 * @return void
	 */
	public function test_an_unknown_action_or_scenario_is_refused(): void {
		$registry = self::registry();

		$registry->declare( 'stripe', 'teleport', self::evidence() );

		self::assertSame( array( 'action' ), $registry->refusals()[0]['missing'] );

		$scenario = self::registry();
		$scenario->declare( 'stripe', 'capture', self::evidence( array( 'scenario' => 'i_think_it_works' ) ) );

		self::assertSame( array( 'scenario' ), $scenario->refusals()[0]['missing'] );
	}

	/**
	 * A mode the record does not describe is not evidence either.
	 *
	 * @return void
	 */
	public function test_an_unknown_evidence_mode_is_refused(): void {
		$registry = self::registry();

		$registry->declare( 'stripe', 'capture', self::evidence( array( 'mode' => 'hopefully' ) ) );

		self::assertSame( array( 'mode' ), $registry->refusals()[0]['missing'] );
	}

	/**
	 * **A proven capability is the only thing that becomes offerable.**
	 *
	 * @return void
	 */
	public function test_only_a_proven_capability_is_offerable(): void {
		$registry = self::registry();

		self::assertSame( array( 'pay_for_order' ), $registry->offerable( 'stripe', '4.2.0' ) );

		self::assertTrue( $registry->declare( 'stripe', 'capture', self::evidence() ) );

		self::assertSame( array( 'pay_for_order', 'capture' ), $registry->offerable( 'stripe', '4.2.0' ) );
		self::assertTrue( $registry->supports( 'stripe', 'capture', '4.2.0' ) );
		self::assertFalse( $registry->supports( 'stripe', 'authorize', '4.2.0' ) );
	}

	/**
	 * **Evidence for another version is not a capability.**
	 *
	 * @return void
	 */
	public function test_stale_evidence_is_not_offerable(): void {
		$registry = self::registry();

		$registry->declare( 'stripe', 'capture', self::evidence( array( 'version' => '4.1.3' ) ) );

		self::assertTrue( $registry->all()[0]->is_proven() );
		self::assertFalse( $registry->supports( 'stripe', 'capture', '4.2.0' ) );
		self::assertSame( array( 'pay_for_order' ), $registry->offerable( 'stripe', '4.2.0' ) );
		self::assertTrue( $registry->supports( 'stripe', 'capture', '4.1.3' ) );
	}

	/**
	 * A store that cannot say which version it runs is not told its evidence is old.
	 *
	 * @return void
	 */
	public function test_an_unknown_installed_version_is_not_staleness(): void {
		$registry = self::registry();

		$registry->declare( 'stripe', 'capture', self::evidence() );

		self::assertTrue( $registry->supports( 'stripe', 'capture', null ) );
		self::assertTrue( $registry->supports( 'stripe', 'capture', '' ) );
	}

	/**
	 * The fallback is always there, and it is the platform's page and not the gateway's action.
	 *
	 * @return void
	 */
	public function test_the_pay_for_order_fallback_needs_no_capability(): void {
		$registry = self::registry();

		self::assertTrue( $registry->supports( 'anything', 'pay_for_order', '1.0.0' ) );

		$missing = $registry->fallback( 'stripe', 'create_after_approval', '4.2.0' );

		self::assertFalse( $missing['supported'] );
		self::assertSame( 'pay_for_order', $missing['fallback'] );
		self::assertStringContainsString( 'create_after_approval', $missing['reason'] );

		$registry->declare(
			'stripe',
			'create_after_approval',
			self::evidence( array( 'scenario' => 'create_after_approval' ) )
		);

		self::assertTrue( $registry->fallback( 'stripe', 'create_after_approval', '4.2.0' )['supported'] );
	}

	/**
	 * The report says why an action is not offered, which is the screen's whole job.
	 *
	 * @return void
	 */
	public function test_the_report_says_why_an_action_is_not_offered(): void {
		$registry = self::registry();

		$report    = $registry->report( 'stripe', '4.2.0' );
		$by_action = array();

		foreach ( $report['actions'] as $entry ) {
			$by_action[ $entry['action'] ] = $entry;
		}

		self::assertFalse( $by_action['capture']['declared'] );
		self::assertFalse( $by_action['capture']['offerable'] );
		self::assertSame( array( 'mode', 'version', 'scenario', 'proven_at' ), $by_action['capture']['missing'] );

		self::assertTrue( $by_action['pay_for_order']['fallback'] );
		self::assertTrue( $by_action['pay_for_order']['offerable'] );

		$registry->declare( 'stripe', 'capture', self::evidence( array( 'version' => '4.1.3' ) ) );

		$stale = array();

		foreach ( $registry->report( 'stripe', '4.2.0' )['actions'] as $entry ) {
			$stale[ $entry['action'] ] = $entry;
		}

		self::assertTrue( $stale['capture']['declared'] );
		self::assertTrue( $stale['capture']['proven'] );
		self::assertTrue( $stale['capture']['stale'] );
		self::assertFalse( $stale['capture']['offerable'] );
	}

	/**
	 * The record ships empty, and an empty record offers only the fallback.
	 *
	 * That is the honest state of a store with no gateway enabled, and it is the gate working rather
	 * than the feature missing.
	 *
	 * @return void
	 */
	public function test_the_shipped_record_proves_nothing(): void {
		$registry = new GatewayCapabilityRegistry();

		self::assertSame( array(), $registry->refusals() );

		foreach ( $registry->all() as $capability ) {
			self::assertTrue( $capability->is_proven(), $capability->gateway() . ':' . $capability->action() );
		}

		self::assertSame( array( 'pay_for_order' ), $registry->offerable( 'qualquer', '9.9.9' ) );
	}

	/**
	 * The transactional registry is a different object from the presentation matrix.
	 *
	 * §17: "Manter para compatibilidade visual/homologação da apresentação. Não transformar esse
	 * objeto em matriz de capture/authorize." The failure this test prevents is a presentation
	 * decision being read as permission to move money.
	 *
	 * @return void
	 */
	public function test_the_transactional_matrix_is_not_the_presentation_one(): void {
		$presentation  = \WCCheckoutSuite\Domain\Payments\PaymentMatrix::scenarios();
		$transactional = array_keys( GatewayCapabilities::scenarios() );

		self::assertSame( array(), array_intersect( $presentation, $transactional ) );

		// And the transactional vocabulary holds no presentation word at all.
		foreach ( array( 'decorated', 'compatible', 'unavailable', 'undecided' ) as $mode ) {
			self::assertNotContains( $mode, GatewayCapabilities::modes() );
		}

		// The two records are different files, so neither can be edited into the other by accident.
		self::assertNotSame(
			\WCCheckoutSuite\Domain\Payments\PaymentMatrix::FILE,
			GatewayCapabilityRegistry::FILE
		);
	}

	/**
	 * The capability carries its evidence wherever it goes.
	 *
	 * @return void
	 */
	public function test_a_capability_exports_its_evidence(): void {
		$capability = GatewayCapability::from_array(
			array(
				'gateway'  => 'stripe',
				'action'   => 'capture',
				'evidence' => self::evidence(),
				'note'     => 'Sandbox run on the homologation store.',
			)
		);

		$exported = $capability->to_array();

		self::assertTrue( $exported['proven'] );
		self::assertSame( 'sandbox', $exported['evidence']['mode'] );
		self::assertSame( '4.2.0', $exported['evidence']['version'] );
		self::assertSame( 'capture_authorization', $exported['evidence']['scenario'] );
		self::assertSame( '2026-09-15', $exported['evidence']['proven_at'] );
		self::assertSame( 'Capturar uma autorização', $exported['label'] );
	}
}
