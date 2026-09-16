import { __ } from '@wordpress/i18n';

import { Icon } from '../design/icons';

const TABS = [
	{
		id: 'checkout',
		label: __( 'Checkout', 'wc-checkoutsuite' ),
		icon: 'card',
	},
	{
		id: 'customer_account',
		label: __( 'Minha conta', 'wc-checkoutsuite' ),
		icon: 'user',
	},
	{
		id: 'customer_order',
		label: __( 'Pedido do cliente', 'wc-checkoutsuite' ),
		icon: 'file',
	},
	{
		id: 'admin_order',
		label: __( 'Pedido (admin)', 'wc-checkoutsuite' ),
		icon: 'layers',
	},
	{
		id: 'admin_customer_profile',
		label: __( 'Perfil do cliente (admin)', 'wc-checkoutsuite' ),
		icon: 'user',
	},
	{
		id: 'customer_email',
		label: __( 'E-mails', 'wc-checkoutsuite' ),
		icon: 'mail',
	},
];

/**
 * @param {Object}                        props          Props.
 * @param {string}                        props.active   Active destination.
 * @param {(destination: string) => void} props.onChange Destination callback.
 * @return {*} Tabs.
 */
export default function SurfaceTabs( { active, onChange } ) {
	return (
		<div
			className="wccs-surface-tabs"
			role="tablist"
			aria-label={ __( 'Superfície', 'wc-checkoutsuite' ) }
		>
			{ TABS.map( ( tab ) => {
				const selected =
					tab.id === active ||
					( 'customer_email' === tab.id && 'admin_email' === active );

				return (
					<button
						key={ tab.id }
						type="button"
						role="tab"
						aria-selected={ selected }
						className={ selected ? 'active' : '' }
						onClick={ () =>
							onChange(
								'customer_email' === tab.id &&
									'admin_email' === active
									? 'admin_email'
									: tab.id
							)
						}
					>
						<Icon name={ tab.icon } />
						<span>{ tab.label }</span>
					</button>
				);
			} ) }
		</div>
	);
}
