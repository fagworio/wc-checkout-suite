<?php
/**
 * The words a workflow is written with.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WCCheckoutSuite\Domain\Payments\GatewayCapabilities;

/**
 * Every gatilho, decisão, estratégia and communication event a workflow may name.
 *
 * Closed lists, for the reason ADR-0007 gives about every other vocabulary in this plugin: the set
 * the editor offers and the set the validator accepts have to be the same set, and the way to keep
 * that true is for both to read it from here.
 *
 * Two of these lists are deliberately **shorter than the design draws them**, and the reason is the
 * same in both cases: an option that cannot be executed is an option that lies about what will
 * happen. §13.2 step 3 offers three stock strategies and step 4 offers five payment strategies, and
 * neither can be executed in this build — the reservation belongs to the stock phase and the
 * payment action to the gateway capability registry. So the keys exist, the validator refuses all
 * but `none` **by name**, and the screen offers only what the store can honour. §12.4 is the rule
 * behind that: a status is not a charging command, and neither is a workflow that names a strategy
 * nothing implements.
 *
 * @see \ROADMAP.md sections 13.2, 13.3, 13.4 and 13.5
 */
final class Workflows {

	/**
	 * The moment an order enters the machine.
	 *
	 * §13.2 step 1 draws one: o checkout foi enviado. It is the moment the order exists and before a
	 * gateway has had its say, which is what lets an order go into a state that waits.
	 */
	public const TRIGGER_CHECKOUT_SUBMITTED = 'checkout_submitted';

	/**
	 * Every trigger, keyed by its stable key.
	 *
	 * @return array<string, string>
	 */
	public static function triggers(): array {
		return array(
			self::TRIGGER_CHECKOUT_SUBMITTED => __( 'Checkout enviado', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * Aprovar.
	 */
	public const DECISION_APPROVE = 'approve';

	/**
	 * Pedir correção ao cliente.
	 */
	public const DECISION_CORRECTION = 'request_correction';

	/**
	 * Reprovar.
	 */
	public const DECISION_REJECT = 'reject';

	/**
	 * Expirar sem decisão.
	 */
	public const DECISION_EXPIRE = 'expire';

	/**
	 * Every decision, keyed by its stable key.
	 *
	 * @return array<string, string>
	 */
	public static function decisions(): array {
		return array(
			self::DECISION_APPROVE    => __( 'Aprovar', 'wc-checkoutsuite' ),
			self::DECISION_CORRECTION => __( 'Solicitar correção', 'wc-checkoutsuite' ),
			self::DECISION_REJECT     => __( 'Reprovar', 'wc-checkoutsuite' ),
			self::DECISION_EXPIRE     => __( 'Expirar', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * The decisions a person makes, as opposed to the one the clock makes.
	 *
	 * @return array<int, string>
	 */
	public static function manual_decisions(): array {
		return array( self::DECISION_APPROVE, self::DECISION_CORRECTION, self::DECISION_REJECT );
	}

	/**
	 * Nada é feito ao estoque.
	 */
	public const INVENTORY_NONE = 'none';

	/**
	 * Every stock strategy, keyed by its stable key.
	 *
	 * The three §13.2 draws are here; only `none` can run in this build.
	 *
	 * @return array<string, string>
	 */
	public static function inventory_strategies(): array {
		return array(
			self::INVENTORY_NONE => __( 'Não reservar', 'wc-checkoutsuite' ),
			'until_decision'     => __( 'Reservar até decisão', 'wc-checkoutsuite' ),
			'hours'              => __( 'Reservar por X horas', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * Nada é feito ao pagamento.
	 */
	public const PAYMENT_NONE = 'none';

	/**
	 * Every payment strategy, keyed by its stable key.
	 *
	 * @return array<string, string>
	 */
	public static function payment_strategies(): array {
		return array(
			self::PAYMENT_NONE        => __( 'Não iniciar pagamento', 'wc-checkoutsuite' ),
			'authorize_now'           => __( 'Autorizar agora e capturar após aprovação', 'wc-checkoutsuite' ),
			'capture_after_approval'  => __( 'Capturar após aprovação', 'wc-checkoutsuite' ),
			'request_after_approval'  => __( 'Solicitar pagamento após aprovação', 'wc-checkoutsuite' ),
			'generate_after_approval' => __( 'Gerar PIX/boleto após aprovação', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * Pedido recebido para análise.
	 */
	public const EVENT_RECEIVED = 'received';

	/**
	 * Documentos recebidos.
	 */
	public const EVENT_DOCUMENTS = 'documents_received';

	/**
	 * Correção solicitada.
	 */
	public const EVENT_CORRECTION = 'correction_requested';

	/**
	 * Aprovação.
	 */
	public const EVENT_APPROVED = 'approved';

	/**
	 * Cobrança liberada.
	 */
	public const EVENT_CHARGE_RELEASED = 'charge_released';

	/**
	 * Reprovação.
	 */
	public const EVENT_REJECTED = 'rejected';

	/**
	 * Expiração.
	 */
	public const EVENT_EXPIRED = 'expired';

	/**
	 * Every communication event §13.3 lists, keyed by its stable key.
	 *
	 * @return array<string, string>
	 */
	public static function events(): array {
		return array(
			self::EVENT_RECEIVED        => __( 'Pedido recebido para análise', 'wc-checkoutsuite' ),
			self::EVENT_DOCUMENTS       => __( 'Documentos recebidos', 'wc-checkoutsuite' ),
			self::EVENT_CORRECTION      => __( 'Correção solicitada', 'wc-checkoutsuite' ),
			self::EVENT_APPROVED        => __( 'Aprovação', 'wc-checkoutsuite' ),
			self::EVENT_CHARGE_RELEASED => __( 'Cobrança liberada', 'wc-checkoutsuite' ),
			self::EVENT_REJECTED        => __( 'Reprovação', 'wc-checkoutsuite' ),
			self::EVENT_EXPIRED         => __( 'Expiração', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * The event a decision announces, or an empty string.
	 *
	 * One map rather than a switch in every caller: the decision and the message are the same
	 * moment seen twice, and two places deciding which message belongs to which decision is how a
	 * store ends up telling a customer their order was approved when it was rejected.
	 *
	 * @param string $decision Decision key.
	 * @return string
	 */
	public static function event_for( string $decision ): string {
		$map = array(
			self::DECISION_APPROVE    => self::EVENT_APPROVED,
			self::DECISION_CORRECTION => self::EVENT_CORRECTION,
			self::DECISION_REJECT     => self::EVENT_REJECTED,
			self::DECISION_EXPIRE     => self::EVENT_EXPIRED,
		);

		return $map[ $decision ] ?? '';
	}

	/**
	 * Whether this build can perform a payment action at all.
	 *
	 * One named switch, and it is `false`. Nothing in this version talks to a gateway: the payment
	 * action and the capability registry that would authorise it are the next phases, and until they
	 * exist there is no way for a store to charge on a transition. The switch is what makes that a
	 * single fact the validator reads rather than a property of several unrelated checks — the day
	 * the phase lands, it becomes true and the rule about paid statuses becomes the rule §13.4
	 * describes: a paid state is reachable through a transition that performs a payment action.
	 *
	 * @return bool
	 */
	public static function payment_actions_available(): bool {
		return true;
	}

	/**
	 * The capabilities a payment strategy needs before it can run.
	 *
	 * The bridge between §13.2 step 4's options and §20's vocabulary, and the reason both live in one
	 * class: a strategy is a sentence about a gateway action, and the sentence is only honest while
	 * the mapping is written down once. A strategy whose capabilities the gateway has not proven does
	 * not fail — it falls back to the pay-for-order link, which is what §13.2 step 4 asks for.
	 *
	 * `request_after_approval` needs nothing: asking the customer to pay through WooCommerce's own
	 * page is not something a gateway has to be able to do.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function strategy_capabilities(): array {
		return array(
			self::PAYMENT_NONE        => array(),
			'request_after_approval'  => array(),
			'authorize_now'           => array( GatewayCapabilities::AUTHORIZE, GatewayCapabilities::CAPTURE ),
			'capture_after_approval'  => array( GatewayCapabilities::CAPTURE ),
			'generate_after_approval' => array( GatewayCapabilities::CREATE_AFTER_APPROVAL ),
		);
	}

	/**
	 * Whether a strategy asks a gateway to do something.
	 *
	 * The question the rule about paid statuses turns on: a state WooCommerce treats as paid may be
	 * reached only by a workflow whose strategy performs a payment action, because otherwise nothing
	 * concluded a payment and the status would be saying money arrived.
	 *
	 * @param string $strategy Strategy key.
	 * @return bool
	 */
	public static function strategy_performs_action( string $strategy ): bool {
		return array() !== ( self::strategy_capabilities()[ $strategy ] ?? array() );
	}

	/**
	 * The capabilities a strategy needs.
	 *
	 * @param string $strategy Strategy key.
	 * @return array<int, string>
	 */
	public static function capabilities_for( string $strategy ): array {
		return self::strategy_capabilities()[ $strategy ] ?? array();
	}

	/**
	 * The strategies this build can execute.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function executable_strategies(): array {
		return array(
			// Stock stays refused until the phase that reserves it exists; the payment strategies
			// are all executable now, each one falling back to the pay-for-order link where the
			// gateway has not proven what it needs.
			'inventory' => array( self::INVENTORY_NONE ),
			'payment'   => array_keys( self::payment_strategies() ),
		);
	}

	/**
	 * Whether a strategy is one the store can carry out today.
	 *
	 * @param string $kind  `inventory` or `payment`.
	 * @param string $value Strategy key.
	 * @return bool
	 */
	public static function can_execute( string $kind, string $value ): bool {
		$executable = self::executable_strategies();

		return in_array( $value, $executable[ $kind ] ?? array(), true );
	}

	/**
	 * The vocabulary as the editor reads it.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array(): array {
		$labels = static fn( array $entries ): array => array_map(
			static fn( string $label, string $key ): array => array(
				'value' => $key,
				'label' => $label,
			),
			array_values( $entries ),
			array_keys( $entries )
		);

		return array(
			'triggers'   => $labels( self::triggers() ),
			'decisions'  => $labels( self::decisions() ),
			'inventory'  => $labels( self::inventory_strategies() ),
			'payment'    => $labels( self::payment_strategies() ),
			'events'     => $labels( self::events() ),
			// What the store can carry out today, so the editor offers only that and can say why
			// the rest is not there (§30.1).
			'executable' => self::executable_strategies(),
			'expiration' => array(
				'minHours' => 1,
				'maxHours' => 8760,
			),
		);
	}
}
