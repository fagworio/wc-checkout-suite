import { __ } from '@wordpress/i18n';

import { Icon } from '../design/icons';

/** @type {Record<string, string>} WooCommerce account endpoint icons. */
const ACCOUNT_ICONS = {
	dashboard: 'home',
	orders: 'file',
	downloads: 'download',
	'edit-address': 'location',
	'payment-methods': 'card',
	'edit-account': 'user',
};

/** @type {Record<string, string>} WooCommerce account endpoint descriptions. */
const ACCOUNT_DESCRIPTIONS = {
	dashboard: __( 'Visão geral da conta', 'wc-checkoutsuite' ),
	orders: __( 'Histórico e detalhes dos pedidos', 'wc-checkoutsuite' ),
	downloads: __( 'Arquivos para download', 'wc-checkoutsuite' ),
	'edit-address': __( 'Endereços de cobrança e entrega', 'wc-checkoutsuite' ),
	'payment-methods': __( 'Formas de pagamento salvas', 'wc-checkoutsuite' ),
	'edit-account': __( 'Informações pessoais do cliente', 'wc-checkoutsuite' ),
};

/**
 * @param {Object}               props          Props.
 * @param {Array<any>}           [props.items]  Account menu items.
 * @param {string}               props.active   Active menu item.
 * @param {(id: string) => void} props.onSelect Selection callback.
 * @param {() => void}           props.onCreate Creation callback.
 * @return {*} WooCommerce account menu list.
 */
export default function AccountMenuList( {
	items = [],
	active,
	onSelect,
	onCreate,
} ) {
	return (
		<aside
			className="wccs-container-list wccs-account-menu-list"
			aria-label={ __( 'Páginas da minha conta', 'wc-checkoutsuite' ) }
		>
			<div className="wccs-container-list__heading">
				<div>
					<h2>
						{ __( 'Páginas da minha conta', 'wc-checkoutsuite' ) }
					</h2>
					<p>
						{ __(
							'Organize as páginas da área de minha conta. Itens nativos vêm do WooCommerce.',
							'wc-checkoutsuite'
						) }
					</p>
				</div>
			</div>
			<div className="wccs-container-list__items">
				{ items.map( ( item ) => (
					<button
						key={ item.id }
						type="button"
						className={ item.id === active ? 'active' : '' }
						aria-pressed={ item.id === active }
						onClick={ () => onSelect( item.id ) }
					>
						<span
							className="wccs-container-list__icon"
							aria-hidden="true"
						>
							<Icon
								name={
									item.icon ??
									ACCOUNT_ICONS[ item.id ] ??
									'fields'
								}
							/>
						</span>
						<span>
							<strong>{ item.label }</strong>
							<small>
								{ item.custom
									? __(
											'Campos personalizados',
											'wc-checkoutsuite'
									  )
									: ACCOUNT_DESCRIPTIONS[ item.id ] ??
									  __(
											'Página nativa do WooCommerce',
											'wc-checkoutsuite'
									  ) }
							</small>
						</span>
					</button>
				) ) }
			</div>
			<button
				type="button"
				className="wccs-container-list__create"
				onClick={ onCreate }
			>
				<Icon name="plus" /> { __( 'Nova página', 'wc-checkoutsuite' ) }
			</button>
		</aside>
	);
}
