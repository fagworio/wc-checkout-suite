import { __, sprintf } from '@wordpress/i18n';

import { Badge } from './Badge';
import { Icon } from '../design/icons';
import { typeGlyph } from '../design/typeGlyph';

/**
 * Editable view of the native fields WooCommerce renders on My Account > Account details.
 *
 * Inventory entries become stored overrides lazily, when the merchant edits or hides one.
 * This keeps the draft compact while allowing every native control to be customised and
 * restored without changing its WooCommerce identifier or persistence contract.
 *
 * @param {Object}      props             Props.
 * @param {any}         props.inventory   Account field inventory.
 * @param {any}         props.document    Current schema document.
 * @param {string}      [props.pageLabel] Native page label.
 * @param {string|null} [props.editing]   Currently edited field.
 * @param {Function}    props.onAdopt     Adopt a native field and open its inspector.
 * @param {Function}    props.onEdit      Open an adopted field in its inspector.
 * @param {Function}    props.onToggle    Show or hide a native field.
 * @return {*} Native account field editor.
 */
export default function AccountNativeFields( {
	inventory,
	document,
	pageLabel,
	editing,
	onAdopt,
	onEdit,
	onToggle,
} ) {
	const section = ( inventory?.sections ?? [] ).find(
		( /** @type {any} */ entry ) => entry.key === 'edit-account'
	);

	if ( ! inventory?.available || ! section?.fields?.length ) {
		return null;
	}

	const details = section.fields.filter(
		( /** @type {any} */ field ) => field.group !== 'password'
	);
	const passwords = section.fields.filter(
		( /** @type {any} */ field ) => field.group === 'password'
	);
	const stored = new Map(
		( document?.fields ?? [] )
			.filter(
				( /** @type {any} */ field ) =>
					field.collection_surface === 'my_account' &&
					section.fields.some(
						( /** @type {any} */ entry ) => entry.id === field.id
					)
			)
			.map( ( /** @type {any} */ field ) => [ field.id, field ] )
	);

	const fieldRow = ( /** @type {any} */ entry ) => {
		const definition = stored.get( entry.id );
		const field = definition ?? entry;
		const enabled = definition ? Boolean( definition.enabled ) : true;
		const managed = Boolean( definition );

		return (
			<li
				key={ entry.id }
				className={
					'account-native-field' +
					( ! enabled ? ' account-native-field--disabled' : '' ) +
					( editing === entry.id ? ' is-editing' : '' )
				}
			>
				<span
					className="account-native-field__glyph"
					aria-hidden="true"
				>
					{ typeGlyph( entry.type ?? 'text' ) }
				</span>
				<span className="account-native-field__name">
					<strong>{ field.label ?? entry.label ?? entry.id }</strong>
					<code>{ entry.id }</code>
				</span>
				<Badge tone="neutral">
					{ entry.nativeType === 'password'
						? __( 'Senha', 'wc-checkoutsuite' )
						: __( 'Texto', 'wc-checkoutsuite' ) }
				</Badge>
				<Badge tone={ entry.required ? 'neutral' : 'success' }>
					{ entry.required
						? __( 'Obrigatório', 'wc-checkoutsuite' )
						: __( 'Opcional', 'wc-checkoutsuite' ) }
				</Badge>
				<span className="account-native-field__protected">
					<Icon name="lock" />
					{ managed
						? __( 'Gerenciado', 'wc-checkoutsuite' )
						: __( 'Padrão', 'wc-checkoutsuite' ) }
				</span>
				<span className="account-native-field__actions">
					<button
						type="button"
						className="icon-btn small-icon"
						title={ __( 'Editar campo', 'wc-checkoutsuite' ) }
						aria-label={ sprintf(
							/* translators: %s: field label. */
							__( 'Editar campo %s', 'wc-checkoutsuite' ),
							field.label ?? field.id
						) }
						onClick={ () =>
							managed ? onEdit( field.id ) : onAdopt( entry )
						}
					>
						<Icon name="edit" />
					</button>
					<button
						type="button"
						className={
							'icon-btn small-icon' +
							( enabled
								? ' danger'
								: ' account-native-field__restore' )
						}
						title={
							enabled
								? __( 'Ocultar campo', 'wc-checkoutsuite' )
								: __( 'Restaurar campo', 'wc-checkoutsuite' )
						}
						aria-label={ sprintf(
							/* translators: 1: action, 2: field label. */
							__( '%1$s %2$s', 'wc-checkoutsuite' ),
							enabled
								? __( 'Ocultar campo', 'wc-checkoutsuite' )
								: __( 'Restaurar campo', 'wc-checkoutsuite' ),
							field.label ?? field.id
						) }
						onClick={ () => onToggle( entry, ! enabled ) }
					>
						<Icon name={ enabled ? 'eye' : 'reset' } />
					</button>
				</span>
			</li>
		);
	};

	return (
		<section
			className="core-checkout account-native-fields"
			aria-label={ __(
				'Campos padrão do WooCommerce',
				'wc-checkoutsuite'
			) }
		>
			<div className="core-checkout-head">
				<div>
					<h3>
						{ pageLabel ||
							__( 'Detalhes da conta', 'woocommerce' ) }
					</h3>
					<p>
						{ __(
							'Gerencie os campos que o WooCommerce exibe nesta página. Edite o rótulo e a descrição, ou oculte e restaure um campo sem alterar sua chave nativa.',
							'wc-checkoutsuite'
						) }
					</p>
				</div>
				<span className="core-checkout-count">
					{ sprintf(
						/* translators: %d: number of native account fields. */
						__( '%d campos nativos', 'wc-checkoutsuite' ),
						section.fields.length
					) }
				</span>
			</div>

			<div className="core-checkout-sections">
				<div className="core-checkout-section">
					<div className="core-checkout-section-head">
						<div>
							<strong>
								{ __( 'Dados da conta', 'wc-checkoutsuite' ) }
							</strong>
							<small>
								{ __(
									'Identidade e acesso do cliente',
									'wc-checkoutsuite'
								) }
							</small>
						</div>
						<Badge tone="neutral">
							{ __( 'WooCommerce', 'wc-checkoutsuite' ) }
						</Badge>
					</div>
					<ul className="core-checkout-fields">
						{ details.map( fieldRow ) }
					</ul>
				</div>

				{ passwords.length > 0 ? (
					<div className="core-checkout-section account-native-passwords">
						<div className="core-checkout-section-head">
							<div>
								<strong>
									{ __(
										'Alteração de senha',
										'woocommerce'
									) }
								</strong>
								<small>
									{ __(
										'Protegida pelo formulário nativo',
										'wc-checkoutsuite'
									) }
								</small>
							</div>
							<Icon name="lock" />
						</div>
						<ul className="core-checkout-fields">
							{ passwords.map( fieldRow ) }
						</ul>
					</div>
				) : null }
			</div>

			<p className="account-native-fields__note">
				<Icon name="shield" />
				{ __(
					'Você pode editar rótulo, descrição e exibição. A chave, o tipo, a validação e a gravação continuam protegidos pelo WooCommerce.',
					'wc-checkoutsuite'
				) }
			</p>
		</section>
	);
}
