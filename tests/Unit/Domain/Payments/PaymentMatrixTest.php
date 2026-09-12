<?php
/**
 * Payment homologation matrix tests.
 *
 * The rule under test is the one sentence ROADMAP.md section 15 writes and the sentence
 * every compatibility table in the world gets wrong: *não prometer compatibilidade com
 * "todos os gateways"*. A matrix that answers `decorated` for a gateway nobody ran is
 * exactly that promise, and it is the failure this suite exists to make impossible.
 *
 * Three ways a record can fail to be a promise are asserted here: a row that names no
 * passing scenario, a row whose version is not the installed one, and a gateway with no
 * row at all. Each of them answers `undecided`, and `undecided` is reported rather than
 * silently treated as safe.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Payments;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Payments\PaymentMatrix;
use WCCheckoutSuite\Domain\Payments\PaymentMode;

/**
 * The homologation matrix.
 */
final class PaymentMatrixTest extends TestCase {

	/**
	 * Forgets the record read before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		PaymentMatrix::flush();
	}

	/**
	 * Puts the shipped record back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		PaymentMatrix::flush();

		parent::tearDown();
	}

	/**
	 * Replaces the record for one assertion, without touching the file.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return void
	 */
	private function with_record( array $record ): void {
		$property = new \ReflectionProperty( PaymentMatrix::class, 'record' );
		$property->setAccessible( true );
		$property->setValue( null, $record );
	}

	/**
	 * The vocabularies the record declares.
	 *
	 * @return void
	 */
	public function test_the_record_declares_its_vocabularies(): void {
		self::assertNotSame( array(), PaymentMatrix::scenarios(), 'the scenarios are described, so a test can be repeated' );
		self::assertNotSame( array(), PaymentMatrix::decorations(), 'the decorations are named, so a gateway can withhold one' );

		foreach ( PaymentMode::all() as $mode ) {
			self::assertNotSame( '', PaymentMode::reason( $mode ), "the mode {$mode} says what it means" );
		}
	}

	/**
	 * The shipped record promises nothing.
	 *
	 * This store has no enabled gateway and no sandbox credentials (SANDBOX-PAYMENT), so
	 * the shipped record is the record of a phase that could not run its observation —
	 * and the file has to *say* that rather than be filled in optimistically.
	 *
	 * @return void
	 */
	public function test_the_shipped_record_promises_nothing(): void {
		foreach ( PaymentMatrix::rows() as $id => $row ) {
			self::assertSame(
				array(),
				PaymentMatrix::tested( $row ),
				"the shipped record claims a passing scenario for {$id} with nothing behind it"
			);
		}

		$decision = PaymentMatrix::decision( 'ppcp-gateway', '4.1.3' );

		self::assertSame( PaymentMode::UNDECIDED, $decision['mode'] );
		self::assertSame( PaymentMatrix::decorations(), $decision['decorations'] );
		self::assertFalse( PaymentMode::is_homologated( $decision['mode'] ) );
	}

	/**
	 * A gateway with no row is undecided, and says so.
	 *
	 * @return void
	 */
	public function test_a_gateway_with_no_row_is_undecided(): void {
		$decision = PaymentMatrix::decision( 'some-gateway-nobody-ran', '1.0.0' );

		self::assertSame( PaymentMode::UNDECIDED, $decision['mode'] );
		self::assertSame( PaymentMode::reason( PaymentMode::UNDECIDED ), $decision['reason'] );
		self::assertSame( array(), $decision['withheld'] );
	}

	/**
	 * A mode without a passing scenario is not a homologation.
	 *
	 * This is the assertion the file would be a wish list without: writing `decorated`
	 * in a row changes nothing until a run has produced a scenario to put beside it.
	 *
	 * @return void
	 */
	public function test_a_mode_without_evidence_is_not_a_homologation(): void {
		$scenario = PaymentMatrix::scenarios()[0];

		foreach ( PaymentMode::all() as $mode ) {
			$this->with_record(
				array(
					'scenario_vocabulary'   => array( $scenario => 'what it proves' ),
					'decoration_vocabulary' => array( 'panel' => 'the box' ),
					'gateways'              => array(
						array(
							'id'      => 'gateway',
							'version' => '1.0.0',
							'mode'    => $mode,
							'tested'  => array(),
						),
					),
				)
			);

			$decision = PaymentMatrix::decision( 'gateway', '1.0.0' );

			self::assertSame(
				PaymentMode::UNDECIDED,
				$decision['mode'],
				"the mode {$mode} was accepted with no scenario behind it"
			);
		}
	}

	/**
	 * Evidence decides, and only for the version it was observed on.
	 *
	 * @return void
	 */
	public function test_evidence_decides_for_the_version_it_was_observed_on(): void {
		$record = array(
			'scenario_vocabulary'   => array(
				'sandbox' => 'an order completes',
				'retry'   => 'a refused attempt can be tried again',
			),
			'decoration_vocabulary' => array(
				'list'  => 'the list treatment',
				'panel' => 'the box around the gateway markup',
			),
			'gateways'              => array(
				array(
					'id'      => 'gateway',
					'version' => '1.0.0',
					'mode'    => PaymentMode::DECORATED,
					'tested'  => array( 'sandbox', 'retry' ),
				),
			),
		);

		$this->with_record( $record );

		$decision = PaymentMatrix::decision( 'gateway', '1.0.0' );

		self::assertSame( PaymentMode::DECORATED, $decision['mode'] );
		self::assertSame( array( 'sandbox', 'retry' ), $decision['tested'] );
		self::assertSame( array( 'list', 'panel' ), $decision['decorations'] );

		// The version is part of the observation: a row recorded for 1.0.0 says nothing
		// about the code installed today.
		$newer = PaymentMatrix::decision( 'gateway', '2.0.0' );

		self::assertSame( PaymentMode::UNDECIDED, $newer['mode'] );
		self::assertStringContainsString( '1.0.0', $newer['reason'] );
		self::assertStringContainsString( '2.0.0', $newer['reason'] );
	}

	/**
	 * Compatible mode withholds exactly the decorations the record names.
	 *
	 * @return void
	 */
	public function test_compatible_mode_withholds_what_the_record_names(): void {
		$this->with_record(
			array(
				'scenario_vocabulary'   => array( 'sandbox' => 'an order completes' ),
				'decoration_vocabulary' => array(
					'list'  => 'the list treatment',
					'panel' => 'the box around the gateway markup',
				),
				'gateways'              => array(
					array(
						'id'       => 'gateway',
						'version'  => '1.0.0',
						'mode'     => PaymentMode::COMPATIBLE,
						'tested'   => array( 'sandbox' ),
						'withheld' => array( 'panel', 'a-decoration-nobody-declared' ),
					),
				),
			)
		);

		$decision = PaymentMatrix::decision( 'gateway', '1.0.0' );

		self::assertSame( PaymentMode::COMPATIBLE, $decision['mode'] );
		self::assertSame( array( 'panel' ), $decision['withheld'], 'only a declared decoration can be withheld' );
		self::assertSame( array( 'list' ), $decision['decorations'] );
	}

	/**
	 * A scenario nobody described is not evidence.
	 *
	 * @return void
	 */
	public function test_a_scenario_outside_the_vocabulary_is_not_evidence(): void {
		$this->with_record(
			array(
				'scenario_vocabulary'   => array( 'sandbox' => 'an order completes' ),
				'decoration_vocabulary' => array( 'panel' => 'the box' ),
				'gateways'              => array(
					array(
						'id'      => 'gateway',
						'version' => '1.0.0',
						'mode'    => PaymentMode::DECORATED,
						'tested'  => array( 'sandbox', 'looked-fine-to-me' ),
					),
				),
			)
		);

		$decision = PaymentMatrix::decision( 'gateway', '1.0.0' );

		self::assertSame( array( 'sandbox' ), $decision['tested'], 'evidence has to be checkable by whoever reads the record next' );
	}

	/**
	 * A record that cannot be read is undecided for everything, not permissive.
	 *
	 * @return void
	 */
	public function test_an_unreadable_record_promises_nothing(): void {
		$this->with_record( array( 'gateways' => 'not a list' ) );

		$decision = PaymentMatrix::decision( 'gateway', '1.0.0' );

		self::assertSame( PaymentMode::UNDECIDED, $decision['mode'] );
		self::assertSame( array(), PaymentMatrix::scenarios() );
	}

	/**
	 * The decision for an installed gateway reads its own version.
	 *
	 * @return void
	 */
	public function test_the_decision_for_a_gateway_reads_its_own_version(): void {
		$this->with_record(
			array(
				'scenario_vocabulary'   => array( 'sandbox' => 'an order completes' ),
				'decoration_vocabulary' => array( 'panel' => 'the box' ),
				'gateways'              => array(
					array(
						'id'      => 'gateway',
						'version' => '1.0.0',
						'mode'    => PaymentMode::DECORATED,
						'tested'  => array( 'sandbox' ),
					),
				),
			)
		);

		$gateway          = new \stdClass();
		$gateway->id      = 'gateway';
		$gateway->version = '1.0.0';

		self::assertSame( PaymentMode::DECORATED, PaymentMatrix::decide_for( $gateway )['mode'] );

		$gateway->version = '1.1.0';

		self::assertSame( PaymentMode::UNDECIDED, PaymentMatrix::decide_for( $gateway )['mode'] );
	}
}
