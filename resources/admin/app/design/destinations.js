/**
 * Where a container of fields lives, and what the interface calls it there.
 *
 * The document has one concept — a section is a group of fields offered in one area — and
 * the merchant has seven of them, in seven different places, each with its own name for
 * the thing they are creating. A section on the checkout, a page in My Account, a block on
 * the customer's order, a panel on the order screen and a block in an e-mail are the same
 * record with different words, and `roadmap/ESPECIFICACAO-SECOES-WCCS.md` §2 asks the
 * interface to use the words of the place rather than the identifier of the model.
 *
 * This module is that vocabulary, and the shape of the editor's own navigation: the
 * destinations the merchant works in, which of them are grouped behind `Admin` and
 * `Mais destinos` (§3), and the words each one uses for its containers, its create button
 * and its empty state.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * The words one place uses for the group of fields it holds.
 *
 * @typedef {Object} ContainerWords
 * @property {string} one       Singular name, as in a heading.
 * @property {string} many      Plural name, as in a list.
 * @property {string} create    The button that opens the creation form.
 * @property {string} submit    The button that confirms it.
 * @property {string} actions   The label of the container's own actions.
 * @property {string} nameLabel The label for the container name field.
 */

/**
 * One destination, with the design's words for it.
 *
 * @typedef {Object} Destination
 * @property {string}         id          Destination key the document uses.
 * @property {string}         label       Name in the editor's navigation.
 * @property {string}         description One line saying where that is.
 * @property {?string}        group       Group it appears under, or null at the top level.
 * @property {boolean}        customer    Whether its values belong to the customer.
 * @property {boolean}        collects    Whether fields can be filled in there.
 * @property {ContainerWords} container   The words this place uses for its containers.
 */

/**
 * Every destination the editor offers, in the order the navigation shows them.
 *
 * `public_api` is not here: it is a projection of values, not a place a panel is drawn
 * (§10), and a container offered there would promise an interface that does not exist.
 *
 * @type {Destination[]}
 */
export const DESTINATIONS = [
	{
		id: 'checkout',
		label: __( 'Checkout', 'wc-checkoutsuite' ),
		description: __(
			'Campos preenchidos durante a compra.',
			'wc-checkoutsuite'
		),
		group: null,
		customer: false,
		collects: true,
		container: {
			one: __( 'Seção', 'wc-checkoutsuite' ),
			many: __( 'Seções', 'wc-checkoutsuite' ),
			create: __( 'Nova seção', 'wc-checkoutsuite' ),
			submit: __( 'Adicionar seção', 'wc-checkoutsuite' ),
			actions: __( 'Ações da seção', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome da seção', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'customer_account',
		label: __( 'Minha conta', 'wc-checkoutsuite' ),
		description: __(
			'Página própria do cliente, fora de um pedido.',
			'wc-checkoutsuite'
		),
		group: null,
		customer: true,
		collects: true,
		container: {
			one: __( 'Página da conta', 'wc-checkoutsuite' ),
			many: __( 'Páginas da conta', 'wc-checkoutsuite' ),
			create: __( 'Nova página', 'wc-checkoutsuite' ),
			submit: __( 'Criar página', 'wc-checkoutsuite' ),
			actions: __( 'Ações da página', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome da página', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'customer_order',
		label: __( 'Pedido do cliente', 'wc-checkoutsuite' ),
		description: __(
			'Minha conta → Pedidos → Ver pedido.',
			'wc-checkoutsuite'
		),
		group: null,
		customer: false,
		collects: false,
		container: {
			one: __( 'Bloco do pedido', 'wc-checkoutsuite' ),
			many: __( 'Blocos do pedido', 'wc-checkoutsuite' ),
			create: __( 'Novo bloco', 'wc-checkoutsuite' ),
			submit: __( 'Criar bloco', 'wc-checkoutsuite' ),
			actions: __( 'Ações do bloco', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do bloco', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'admin_order',
		label: __( 'Pedido', 'wc-checkoutsuite' ),
		description: __(
			'Admin → WooCommerce → Pedidos → Editar pedido.',
			'wc-checkoutsuite'
		),
		group: 'admin',
		customer: false,
		collects: false,
		container: {
			one: __( 'Painel do pedido', 'wc-checkoutsuite' ),
			many: __( 'Painéis do pedido', 'wc-checkoutsuite' ),
			create: __( 'Novo painel', 'wc-checkoutsuite' ),
			submit: __( 'Criar painel', 'wc-checkoutsuite' ),
			actions: __( 'Ações do painel', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do painel', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'admin_customer_profile',
		label: __( 'Perfil do cliente', 'wc-checkoutsuite' ),
		description: __(
			'Admin → Usuários → editar cliente.',
			'wc-checkoutsuite'
		),
		group: 'admin',
		customer: true,
		collects: false,
		container: {
			one: __( 'Painel do cliente', 'wc-checkoutsuite' ),
			many: __( 'Painéis do cliente', 'wc-checkoutsuite' ),
			create: __( 'Novo painel', 'wc-checkoutsuite' ),
			submit: __( 'Criar painel', 'wc-checkoutsuite' ),
			actions: __( 'Ações do painel', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do painel', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'order_received',
		label: __( 'Pedido recebido', 'wc-checkoutsuite' ),
		description: __(
			'Página mostrada ao cliente depois de pagar.',
			'wc-checkoutsuite'
		),
		group: 'more',
		customer: false,
		collects: false,
		container: {
			one: __( 'Bloco da página', 'wc-checkoutsuite' ),
			many: __( 'Blocos da página', 'wc-checkoutsuite' ),
			create: __( 'Novo bloco', 'wc-checkoutsuite' ),
			submit: __( 'Criar bloco', 'wc-checkoutsuite' ),
			actions: __( 'Ações do bloco', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do bloco', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'customer_email',
		label: __( 'E-mails do cliente', 'wc-checkoutsuite' ),
		description: __(
			'E-mails que o cliente recebe sobre o pedido.',
			'wc-checkoutsuite'
		),
		group: 'more',
		customer: false,
		collects: false,
		container: {
			one: __( 'Bloco do e-mail', 'wc-checkoutsuite' ),
			many: __( 'Blocos do e-mail', 'wc-checkoutsuite' ),
			create: __( 'Novo bloco', 'wc-checkoutsuite' ),
			submit: __( 'Criar bloco', 'wc-checkoutsuite' ),
			actions: __( 'Ações do bloco', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do bloco', 'wc-checkoutsuite' ),
		},
	},
	{
		id: 'admin_email',
		label: __( 'E-mails da loja', 'wc-checkoutsuite' ),
		description: __(
			'E-mails internos que a loja recebe sobre o pedido.',
			'wc-checkoutsuite'
		),
		group: 'more',
		customer: false,
		collects: false,
		container: {
			one: __( 'Bloco do e-mail', 'wc-checkoutsuite' ),
			many: __( 'Blocos do e-mail', 'wc-checkoutsuite' ),
			create: __( 'Novo bloco', 'wc-checkoutsuite' ),
			submit: __( 'Criar bloco', 'wc-checkoutsuite' ),
			actions: __( 'Ações do bloco', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome do bloco', 'wc-checkoutsuite' ),
		},
	},
];

/**
 * The groups the navigation keeps behind one entry, in the order it shows them.
 *
 * @type {Array<{id: string, label: string, description: string}>}
 */
export const DESTINATION_GROUPS = [
	{
		id: 'admin',
		label: __( 'Admin', 'wc-checkoutsuite' ),
		description: __( 'Onde a equipa trabalha.', 'wc-checkoutsuite' ),
	},
	{
		id: 'more',
		label: __( 'Mais destinos', 'wc-checkoutsuite' ),
		description: __(
			'Página de agradecimento e e-mails.',
			'wc-checkoutsuite'
		),
	},
];

/**
 * One destination by its key.
 *
 * @param {string} id Destination key.
 * @return {Destination|undefined} Destination, or undefined for a key this build does not know.
 */
export function destination( id ) {
	return DESTINATIONS.find( ( entry ) => entry.id === id );
}

/**
 * The words one destination uses for its containers.
 *
 * @param {string} id Destination key.
 * @return {ContainerWords} Words.
 */
export function containerWords( id ) {
	return (
		destination( id )?.container ?? {
			one: __( 'Seção', 'wc-checkoutsuite' ),
			many: __( 'Seções', 'wc-checkoutsuite' ),
			create: __( 'Nova seção', 'wc-checkoutsuite' ),
			submit: __( 'Adicionar seção', 'wc-checkoutsuite' ),
			actions: __( 'Ações da seção', 'wc-checkoutsuite' ),
			nameLabel: __( 'Nome da seção', 'wc-checkoutsuite' ),
		}
	);
}

/**
 * Whether a destination's values belong to the customer rather than to an order.
 *
 * @param {string} id Destination key.
 * @return {boolean} Whether it is a customer surface.
 */
export function isCustomerDestination( id ) {
	return true === destination( id )?.customer;
}

/**
 * Whether fields can be filled in at this destination.
 *
 * A destination that only shows values is where a merchant *reuses* what was collected
 * somewhere else (§6): creating a field there would define data nobody fills in.
 *
 * @param {string} id Destination key.
 * @return {boolean} Whether it collects.
 */
export function collectsAt( id ) {
	return true === destination( id )?.collects;
}

/**
 * The navigation: the entries the merchant sees, and the destinations each opens.
 *
 * `Admin` and `Mais destinos` open a second row rather than showing every destination at
 * once (§3), and the three destinations that stand on their own are their own entry.
 *
 * @return {Array<{id: string, label: string, description: string, members: Destination[]}>} Entries.
 */
export function navigation() {
	/** @type {Array<{id: string, label: string, description: string, members: Destination[]}>} */
	const entries = [];

	for ( const entry of DESTINATIONS ) {
		if ( entry.group ) {
			continue;
		}

		entries.push( {
			id: entry.id,
			label: entry.label,
			description: entry.description,
			members: [ entry ],
		} );
	}

	for ( const group of DESTINATION_GROUPS ) {
		const members = DESTINATIONS.filter(
			( entry ) => entry.group === group.id
		);

		if ( 0 === members.length ) {
			continue;
		}

		entries.push( {
			id: group.id,
			label: group.label,
			description: group.description,
			members,
		} );
	}

	return entries;
}

/**
 * The navigation entry one destination belongs to.
 *
 * @param {string} id Destination key.
 * @return {string} Entry identifier: the group's, or the destination's own.
 */
export function activeEntry( id ) {
	return destination( id )?.group ?? id;
}

/**
 * The words of a group, for the navigation entry and its own heading.
 *
 * @param {string} id Group identifier.
 * @return {{label: string, description: string}} Words.
 */
export function groupWords( id ) {
	const group = DESTINATION_GROUPS.find( ( entry ) => entry.id === id );

	return {
		label: group ? group.label : id,
		description: group ? group.description : '',
	};
}

/**
 * A sentence naming the destinations a container is offered in.
 *
 * A container created in the editor belongs to the destination the merchant is working in,
 * and that is one area. A stored document may offer the same section in more than one —
 * the same group of fields shown to the customer and to the staff, for instance — and the
 * editor says so instead of pretending it is a choice to be made again here.
 *
 * @param {string[]} ids Destination keys.
 * @return {string} Sentence.
 */
export function offeredInSentence( ids ) {
	const labels = ( ids ?? [] )
		.map( ( id ) => destination( id )?.label ?? id )
		.filter( Boolean );

	if ( 0 === labels.length ) {
		return __( 'Não aparece em nenhum destino.', 'wc-checkoutsuite' );
	}

	return sprintf(
		/* translators: %s: comma separated destination names. */
		__( 'Aparece em: %s', 'wc-checkoutsuite' ),
		labels.join( ', ' )
	);
}
