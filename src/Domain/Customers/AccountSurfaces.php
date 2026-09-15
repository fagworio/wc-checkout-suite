<?php
/**
 * The pages of My Account that may receive a section of this plugin's own.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Customers;

/**
 * Which native My Account surfaces can safely host a section, and why.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §7.4 asks for a section
 * to be placeable **inside an existing page** ("Detalhes da conta") and is explicit that the
 * implementation has to *"definir explicitamente quais páginas nativas podem receber conteúdo com
 * segurança"*. This class is that definition: a closed list, one entry per surface, each with the
 * sentence a merchant reads.
 *
 * The question is not whether a page is a page. It is whether inserting a form into it is safe and
 * means something:
 *
 * - **Safe HTML.** Every WooCommerce account template closes its own `<form>` before the endpoint
 *   action returns, so a section rendered by that action is a sibling and never a nested form —
 *   nesting would make the browser drop one of the two, silently.
 * - **A page that means something.** A list page (orders, downloads) is its list: a form of fields
 *   under it would compete with the page's own purpose and would read as part of the list. An
 *   address page renders one form per address type and its meaning is the addresses.
 *
 * What is *not* here is as important as what is: `customer-logout` is a link and not a page at all,
 * which is why it can never host content, and a surface this plugin has never heard of is refused
 * by name rather than accepted and rendered nowhere.
 */
final class AccountSurfaces {

	/**
	 * The native surfaces that may host a section, with the reason each is offered.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function all(): array {
		return array(
			array(
				'value'       => 'edit-account',
				'label'       => __( 'Detalhes da conta', 'wc-checkoutsuite' ),
				'description' => __(
					'A página onde o cliente vê e altera os próprios dados. A secção aparece depois do formulário nativo do WooCommerce, como um bloco à parte.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => 'dashboard',
				'label'       => __( 'Painel', 'wc-checkoutsuite' ),
				'description' => __(
					'A primeira página da Minha Conta, que o cliente vê sem estar a editar nada. Recebe a secção pelo mesmo motivo, com o formulário dela próprio.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Valid surface keys.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map(
			static function ( array $surface ): string {
				return (string) $surface['value'];
			},
			self::all()
		);
	}

	/**
	 * Whether a surface may host a section.
	 *
	 * @param string $value Surface key.
	 * @return bool
	 */
	public static function has( string $value ): bool {
		return in_array( $value, self::values(), true );
	}

	/**
	 * The label of one surface.
	 *
	 * @param string $value Surface key.
	 * @return string
	 */
	public static function label( string $value ): string {
		foreach ( self::all() as $surface ) {
			if ( $surface['value'] === $value ) {
				return (string) $surface['label'];
			}
		}

		return $value;
	}

	/**
	 * The native pages that may **not** host a section, and why.
	 *
	 * Declared rather than left implicit: a merchant who wonders why they cannot put a section on
	 * their orders page is owed the reason, and a future contributor who wants to add one is owed
	 * the argument they would have to answer.
	 *
	 * @return array<string, string> Surface key to reason.
	 */
	public static function refused(): array {
		return array(
			'orders'          => __( 'É a lista de pedidos do cliente. Uma secção de campos por baixo dela pareceria fazer parte da lista.', 'wc-checkoutsuite' ),
			'downloads'       => __( 'É a lista de downloads. O mesmo motivo: a página é o que ela lista.', 'wc-checkoutsuite' ),
			'edit-address'    => __( 'Renderiza um formulário por tipo de endereço e o sentido da página são os endereços; a secção pertence a Detalhes da conta.', 'wc-checkoutsuite' ),
			'payment-methods' => __( 'É gerida pelo gateway: o que aparece ali depende de quem processa o pagamento, e não de um documento desta loja.', 'wc-checkoutsuite' ),
			'customer-logout' => __( 'Não é uma página, é uma ligação de saída do menu.', 'wc-checkoutsuite' ),
		);
	}
}
