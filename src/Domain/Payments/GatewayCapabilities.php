<?php
/**
 * The words a transactional capability is claimed with.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * Every action a gateway can be asked to perform, and what has to be true before it may be offered.
 *
 * §20 puts the vocabulary in two families and one rule above both: a gateway declares what it can do
 * and **proves** it, and nothing appears in an interface on the strength of a declaration alone. That
 * rule is the phase's gate — "nenhuma action aparece na UI sem capability comprovada" — and it is
 * why this class describes evidence as carefully as it describes actions.
 *
 * `pay_for_order` is deliberately in both families and is the one action that needs no gateway
 * capability. It is not an action on a gateway at all: it is WooCommerce's own pay-for-order page,
 * which exists for every order that still needs paying. §13.2 step 4 names it as the fallback for a
 * gateway that cannot generate a PIX or capture later — "após aprovação: enviar link «Pagar pedido»"
 * — and modelling it as a capability a gateway would have to prove would be modelling the platform
 * as if it were a plugin.
 *
 * @see \ROADMAP.md section 20
 */
final class GatewayCapabilities {

	/**
	 * Reserve the amount now, take it later.
	 */
	public const AUTHORIZE = 'authorize';

	/**
	 * Take an amount that was reserved.
	 */
	public const CAPTURE = 'capture';

	/**
	 * Release a reservation without taking it.
	 */
	public const VOID_AUTHORIZATION = 'void_authorization';

	/**
	 * Give money back.
	 */
	public const REFUND = 'refund';

	/**
	 * A card kept for a later charge.
	 */
	public const TOKENIZED = 'tokenized_payment';

	/**
	 * Create a PIX or a boleto after a decision.
	 */
	public const CREATE_AFTER_APPROVAL = 'create_after_approval';

	/**
	 * Create a second PIX or boleto for the same order.
	 */
	public const REGENERATE = 'regenerate';

	/**
	 * Close an unpaid PIX or boleto.
	 */
	public const EXPIRE = 'expire';

	/**
	 * Send the customer to WooCommerce's own pay-for-order page.
	 */
	public const PAY_FOR_ORDER = 'pay_for_order';

	/**
	 * The action the store falls back to, and the one no gateway has to prove.
	 */
	public const FALLBACK = self::PAY_FOR_ORDER;

	/**
	 * Every action, keyed by its stable key, with the family it belongs to.
	 *
	 * @return array<string, array{label: string, family: string, scenario: string}>
	 */
	public static function actions(): array {
		return array(
			self::AUTHORIZE             => array(
				'label'    => __( 'Autorizar agora, capturar depois', 'wc-checkoutsuite' ),
				'family'   => 'card',
				'scenario' => 'authorize_then_capture',
			),
			self::CAPTURE               => array(
				'label'    => __( 'Capturar uma autorização', 'wc-checkoutsuite' ),
				'family'   => 'card',
				'scenario' => 'capture_authorization',
			),
			self::VOID_AUTHORIZATION    => array(
				'label'    => __( 'Anular uma autorização', 'wc-checkoutsuite' ),
				'family'   => 'card',
				'scenario' => 'void_authorization',
			),
			self::REFUND                => array(
				'label'    => __( 'Devolver um valor', 'wc-checkoutsuite' ),
				'family'   => 'card',
				'scenario' => 'refund_full',
			),
			self::TOKENIZED             => array(
				'label'    => __( 'Cobrar um cartão guardado', 'wc-checkoutsuite' ),
				'family'   => 'card',
				'scenario' => 'tokenized_charge',
			),
			self::CREATE_AFTER_APPROVAL => array(
				'label'    => __( 'Gerar PIX ou boleto após aprovação', 'wc-checkoutsuite' ),
				'family'   => 'pix_boleto',
				'scenario' => 'create_after_approval',
			),
			self::REGENERATE            => array(
				'label'    => __( 'Gerar um segundo PIX ou boleto', 'wc-checkoutsuite' ),
				'family'   => 'pix_boleto',
				'scenario' => 'regenerate_pix',
			),
			self::EXPIRE                => array(
				'label'    => __( 'Fechar um PIX ou boleto sem pagamento', 'wc-checkoutsuite' ),
				'family'   => 'pix_boleto',
				'scenario' => 'expire_pix',
			),
			self::PAY_FOR_ORDER         => array(
				'label'    => __( 'Enviar o link «Pagar pedido»', 'wc-checkoutsuite' ),
				'family'   => 'platform',
				'scenario' => 'pay_for_order_link',
			),
		);
	}

	/**
	 * The scenario vocabulary: what a sandbox run has to have done.
	 *
	 * One scenario per action, and each is a run somebody can repeat. A capability whose evidence
	 * names a scenario outside this list is refused, because evidence nobody can re-run is an
	 * opinion with a date on it.
	 *
	 * @return array<string, string>
	 */
	public static function scenarios(): array {
		return array(
			'authorize_then_capture' => __( 'Uma autorização é feita e capturada depois, e o pedido regista as duas.', 'wc-checkoutsuite' ),
			'capture_authorization'  => __( 'Uma autorização existente é capturada e o valor capturado é o esperado.', 'wc-checkoutsuite' ),
			'void_authorization'     => __( 'Uma autorização é anulada e o valor reservado desaparece sem cobrança.', 'wc-checkoutsuite' ),
			'refund_full'            => __( 'Um valor capturado é devolvido por inteiro.', 'wc-checkoutsuite' ),
			'tokenized_charge'       => __( 'Um cartão guardado é cobrado sem o cliente o escrever de novo.', 'wc-checkoutsuite' ),
			'create_after_approval'  => __( 'Um PIX ou boleto é gerado depois de uma aprovação e é pagável.', 'wc-checkoutsuite' ),
			'regenerate_pix'         => __( 'Um segundo PIX ou boleto é gerado para o mesmo pedido e o primeiro deixa de valer.', 'wc-checkoutsuite' ),
			'expire_pix'             => __( 'Um PIX ou boleto por pagar é fechado e deixa de aceitar pagamento.', 'wc-checkoutsuite' ),
			'pay_for_order_link'     => __( 'O cliente abre o link «Pagar pedido» do próprio pedido e consegue pagar.', 'wc-checkoutsuite' ),
		);
	}

	/**
	 * Where evidence may come from.
	 *
	 * A sandbox run is the ordinary proof. `live` is accepted because a store that has been running
	 * a gateway for a year has proven more than a sandbox ever will — and it is a different word, so
	 * a reader can tell the two apart rather than assuming every claim came from a test.
	 *
	 * @return array<int, string>
	 */
	public static function modes(): array {
		return array( 'sandbox', 'live' );
	}

	/**
	 * The fields a piece of evidence has to carry.
	 *
	 * The version is the one that makes the record worth keeping: a capability proven against 4.1.3
	 * says nothing about 4.2.0, and the registry refuses to promote a stale observation to a promise
	 * — the same rule the presentation matrix already follows.
	 *
	 * @return array<int, string>
	 */
	public static function evidence_fields(): array {
		return array( 'mode', 'version', 'scenario', 'proven_at' );
	}

	/**
	 * Whether an action is one of the vocabulary.
	 *
	 * @param string $action Action key.
	 * @return bool
	 */
	public static function has_action( string $action ): bool {
		return isset( self::actions()[ $action ] );
	}

	/**
	 * Whether an action needs no gateway capability.
	 *
	 * @param string $action Action key.
	 * @return bool
	 */
	public static function is_fallback( string $action ): bool {
		return self::FALLBACK === $action;
	}

	/**
	 * The actions of one family.
	 *
	 * @param string $family Family key.
	 * @return array<int, string>
	 */
	public static function family( string $family ): array {
		$found = array();

		foreach ( self::actions() as $action => $entry ) {
			if ( $entry['family'] === $family ) {
				$found[] = $action;
			}
		}

		return $found;
	}

	/**
	 * The vocabulary as an editor reads it.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array(): array {
		$actions = array();

		foreach ( self::actions() as $action => $entry ) {
			$actions[] = array(
				'value'    => $action,
				'label'    => $entry['label'],
				'family'   => $entry['family'],
				'scenario' => $entry['scenario'],
				'fallback' => self::is_fallback( $action ),
			);
		}

		return array(
			'actions'   => $actions,
			'scenarios' => self::scenarios(),
			'modes'     => self::modes(),
			'evidence'  => self::evidence_fields(),
			'fallback'  => self::FALLBACK,
		);
	}
}
