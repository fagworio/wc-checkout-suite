<?php
/**
 * The adapters that can be asked to act on a gateway.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * Where a gateway integration registers itself.
 *
 * One action, plain and declarative — "here is my gateway, here are the actions I implemented" — and
 * a lookup that answers "who can perform this, on this gateway?". Everything else about a payment
 * belongs to {@see PaymentActionService}, which is what keeps this class replaceable with nothing
 * more than a filter if the seam ever needs to move.
 *
 * An adapter written against a contract version this build does not know is **refused**, not called:
 * the shape of a payment call is the last place to be optimistic about.
 *
 * @see \ROADMAP.md sections 13.7, 20
 */
final class PaymentAdapterRegistry {

	/**
	 * Action an extension registers its adapters through.
	 */
	public const REGISTER_ACTION = 'wccs_register_payment_action_adapters';

	/**
	 * Contract version this build calls.
	 */
	public const CONTRACT_VERSION = '1.0';

	/**
	 * The adapters, keyed by gateway.
	 *
	 * @var array<string, PaymentActionAdapterInterface>|null
	 */
	private ?array $adapters = null;

	/**
	 * What could not be registered, with the reason.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $refusals = array();

	/**
	 * Constructor.
	 *
	 * @param bool $load Whether to fire the registration action now.
	 */
	public function __construct( bool $load = true ) {
		if ( $load ) {
			$this->build();
		}
	}

	/**
	 * Forgets what was read, so a test can register again.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->adapters = null;
		$this->refusals = array();
	}

	/**
	 * Collects the adapters.
	 *
	 * @return void
	 */
	private function build(): void {
		if ( null !== $this->adapters ) {
			return;
		}

		$this->adapters = array();

		/**
		 * Registers the payment action adapters of a store.
		 *
		 * An adapter is the one thing this plugin cannot write for a gateway: which endpoint, which
		 * fields and which identifier comes back is knowledge that lives with the integration. So a
		 * plugin that has the gateway in front of it registers one here, and the store calls it
		 * through {@see PaymentActionService} — once per action per order, with the audit and the
		 * fallback that make that safe.
		 *
		 * @since 1.0.0
		 *
		 * @param PaymentAdapterRegistry $registry The registry being built.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the prefixed name, kept in one place.
		do_action( self::REGISTER_ACTION, $this );
	}

	/**
	 * Registers one adapter.
	 *
	 * @param PaymentActionAdapterInterface $adapter Adapter.
	 * @return bool Whether it was registered.
	 */
	public function register( PaymentActionAdapterInterface $adapter ): bool {
		$this->build();

		if ( self::CONTRACT_VERSION !== $adapter->contract_version() ) {
			$this->refusals[] = array(
				'gateway' => $adapter->gateway(),
				'reason'  => sprintf(
					/* translators: 1: contract version the adapter implements, 2: the one this build calls */
					__( 'O adapter declara o contrato %1$s e esta versão chama o %2$s. Um adapter de outra forma não é chamado: a forma de uma cobrança é o último sítio para se ser otimista.', 'wc-checkoutsuite' ),
					$adapter->contract_version(),
					self::CONTRACT_VERSION
				),
			);

			return false;
		}

		$gateway = trim( $adapter->gateway() );

		if ( '' === $gateway ) {
			$this->refusals[] = array(
				'gateway' => '',
				'reason'  => __( 'Um adapter sem gateway não serve loja nenhuma.', 'wc-checkoutsuite' ),
			);

			return false;
		}

		$this->adapters[ $gateway ] = $adapter;

		return true;
	}

	/**
	 * The adapter for a gateway, or null.
	 *
	 * @param string $gateway Gateway identifier.
	 * @return PaymentActionAdapterInterface|null
	 */
	public function for_gateway( string $gateway ): ?PaymentActionAdapterInterface {
		$this->build();

		return $this->adapters[ trim( $gateway ) ] ?? null;
	}

	/**
	 * Whether an adapter for a gateway claims an action.
	 *
	 * A claim, not a promise: the registry of capabilities is what says whether the store may offer
	 * the action, and this only says whether there is somebody to ask.
	 *
	 * @param string $gateway Gateway identifier.
	 * @param string $action  Action key.
	 * @return bool
	 */
	public function can_ask( string $gateway, string $action ): bool {
		$adapter = $this->for_gateway( $gateway );

		return null !== $adapter && in_array( $action, $adapter->actions(), true );
	}

	/**
	 * Every registered gateway.
	 *
	 * @return array<int, string>
	 */
	public function gateways(): array {
		$this->build();

		return array_keys( (array) $this->adapters );
	}

	/**
	 * The adapters that were refused, with the reason.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function refusals(): array {
		$this->build();

		return $this->refusals;
	}
}
