<?php
/**
 * A payment happens when a gateway says it happened, once.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Payments;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Payments\PaymentActionAdapterInterface;
use WCCheckoutSuite\Domain\Payments\PaymentActionResult;
use WCCheckoutSuite\Domain\Payments\PaymentAdapterRegistry;

/**
 * The parts of the payment path that are decidable without a store.
 *
 * The gate — no scenario duplicates a charge — is proven against a real order in
 * `tests/Integration/FASE12-payment-actions-proof.php`, with a counter on the adapter. What is
 * pinned here is what the counter cannot see: that an outcome outside the vocabulary is read as the
 * safe one, and that an adapter written against a contract this build does not know is refused
 * rather than called.
 */
final class PaymentActionServiceTest extends TestCase {

	/**
	 * An adapter that answers with whatever it was told.
	 *
	 * @param string $version Contract version it claims.
	 * @param string $gateway Gateway it serves.
	 * @return PaymentActionAdapterInterface
	 */
	private static function adapter( string $version = PaymentAdapterRegistry::CONTRACT_VERSION, string $gateway = 'stripe' ): PaymentActionAdapterInterface {
		return new class( $version, $gateway ) implements PaymentActionAdapterInterface {
			/**
			 * Constructor.
			 *
			 * @param string $version Contract version.
			 * @param string $gateway Gateway.
			 */
			public function __construct( private string $version, private string $gateway ) {
			}

			/**
			 * Contract version.
			 *
			 * @return string
			 */
			public function contract_version(): string {
				return $this->version;
			}

			/**
			 * Gateway.
			 *
			 * @return string
			 */
			public function gateway(): string {
				return $this->gateway;
			}

			/**
			 * Actions.
			 *
			 * @return array<int, string>
			 */
			public function actions(): array {
				return array( 'capture' );
			}

			/**
			 * Executes nothing: the unit suite never calls it.
			 *
			 * @param string   $action  Action.
			 * @param mixed    $order   Order.
			 * @param string[] $context Context.
			 * @return PaymentActionResult
			 */
			public function execute( string $action, $order, array $context = array() ): PaymentActionResult {
				unset( $action, $order, $context );

				return new PaymentActionResult();
			}
		};
	}

	/**
	 * The four outcomes are the vocabulary, and only one of them is a payment.
	 *
	 * @return void
	 */
	public function test_only_a_confirmation_is_a_payment(): void {
		self::assertSame(
			array( 'confirmed', 'pending', 'refused', 'unsupported' ),
			PaymentActionResult::statuses()
		);

		self::assertTrue( ( new PaymentActionResult( PaymentActionResult::CONFIRMED ) )->is_confirmed() );
		self::assertFalse( ( new PaymentActionResult( PaymentActionResult::PENDING ) )->is_confirmed() );
		self::assertFalse( ( new PaymentActionResult( PaymentActionResult::REFUSED ) )->is_confirmed() );
		self::assertFalse( ( new PaymentActionResult( PaymentActionResult::UNSUPPORTED ) )->is_confirmed() );
	}

	/**
	 * **An outcome nobody knows is not a payment.**
	 *
	 * An adapter that answers something outside the vocabulary has not confirmed anything, and the
	 * direction of that mistake has to be the safe one: no `payment_complete()`, no charge recorded.
	 *
	 * @return void
	 */
	public function test_an_unknown_outcome_is_read_as_the_safe_one(): void {
		foreach ( array( 'maybe', '', 'CONFIRMED', 'paid', '2' ) as $unknown ) {
			$result = PaymentActionResult::from_array( array( 'status' => $unknown ) );

			self::assertSame( PaymentActionResult::UNSUPPORTED, $result->status(), $unknown );
			self::assertFalse( $result->is_confirmed(), $unknown );
		}
	}

	/**
	 * A result from an adapter carries what the audit needs.
	 *
	 * @return void
	 */
	public function test_a_result_carries_its_transaction(): void {
		$result = PaymentActionResult::from_array(
			array(
				'status'         => 'confirmed',
				'transaction_id' => 'ch_123',
				'message'        => 'captured',
			)
		);

		self::assertTrue( $result->is_confirmed() );
		self::assertSame( 'ch_123', $result->transaction_id() );
		self::assertSame( 'captured', $result->message() );
		self::assertSame( 'confirmed', $result->to_array()['status'] );
	}

	/**
	 * An adapter of an unknown contract is refused, not called.
	 *
	 * @return void
	 */
	public function test_an_adapter_of_another_contract_is_refused(): void {
		$registry = new PaymentAdapterRegistry( false );

		self::assertFalse( $registry->register( self::adapter( '2.0' ) ) );
		self::assertNull( $registry->for_gateway( 'stripe' ) );
		self::assertCount( 1, $registry->refusals() );
		self::assertStringContainsString( 'contrato', (string) $registry->refusals()[0]['reason'] );
	}

	/**
	 * An adapter with no gateway serves no store.
	 *
	 * @return void
	 */
	public function test_an_adapter_without_a_gateway_is_refused(): void {
		$registry = new PaymentAdapterRegistry( false );

		self::assertFalse( $registry->register( self::adapter( PaymentAdapterRegistry::CONTRACT_VERSION, '  ' ) ) );
		self::assertSame( array(), $registry->gateways() );
	}

	/**
	 * And a registered adapter answers for its own gateway and actions.
	 *
	 * @return void
	 */
	public function test_a_registered_adapter_answers_for_its_gateway(): void {
		$registry = new PaymentAdapterRegistry( false );

		self::assertTrue( $registry->register( self::adapter() ) );
		self::assertTrue( $registry->can_ask( 'stripe', 'capture' ) );
		self::assertFalse( $registry->can_ask( 'stripe', 'authorize' ) );
		self::assertFalse( $registry->can_ask( 'paypal', 'capture' ) );
		self::assertSame( array( 'stripe' ), $registry->gateways() );
	}
}
