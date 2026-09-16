/**
 * The screen §12 draws: the states an order can wait in.
 *
 * The design's own screen is three columns — the states, what the selected one does, and its
 * configuration — and this is the **first** of them plus the third. The middle column (behaviour,
 * payment policy, automatic transitions, the flow preview) is the workflow engine of Fase 10, and
 * it is not drawn here on purpose: §12.4 says a status is not a charging command, and a screen that
 * showed "não capturar agora / pré-autorizar" beside a state would be inviting the merchant to read
 * the state as the thing that charges. The screen says so where the merchant would look for it,
 * which is better than an empty column.
 *
 * What is here is what §12.3 lists and what a store can already honour: the name, the colour, who
 * sees it, whether staff may move an order into it by hand, whether the customer sees it, whether it
 * appears in the e-mails, and whether it is a state before payment — and nothing a gateway reads.
 *
 * @package
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './components/Button';
import Notice from './components/Notice';
import PreviewPanel from './components/PreviewPanel';
import {
	TextField,
	TextareaField,
	SelectField,
	CheckboxField,
} from './components/controls';
import { Icon } from './design/icons';
import { TopbarActions } from './design/TopbarActions';
import { Toast } from './design/Toast';
import {
	PALETTE,
	coreStatuses,
	createStatus,
	customStatuses,
	isPaid,
	payloadOf,
	removeStatus,
	statusIssues,
	updateStatus,
} from './schema/statuses';

/**
 * @param {Object} props        Component properties.
 * @param {any}    props.client REST client.
 * @return {*} Rendered element tree.
 */
export default function StatusesScreen( { client } ) {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ inventory, setInventory ] = useState(
		/** @type {Array<any>} */ ( [] )
	);
	const [ paid, setPaid ] = useState( /** @type {Array<string>} */ ( [] ) );
	const [ list, setList ] = useState( /** @type {Array<any>} */ ( [] ) );
	const [ selected, setSelected ] = useState( 0 );
	const [ dirty, setDirty ] = useState( false );
	const [ revision, setRevision ] = useState( 0 );
	const [ conflict, setConflict ] = useState( false );

	/**
	 * Reads the store's statuses.
	 *
	 * @return {Promise<void>}
	 */
	const load = useCallback( async () => {
		setLoading( true );

		try {
			const answer = await client.statuses();

			setInventory( answer?.statuses ?? [] );
			setPaid( answer?.paid ?? [] );
			setList( customStatuses( answer?.statuses ?? [] ) );
			setRevision( Number( answer?.revision ?? 0 ) );
			setConflict( false );
			setFailure( '' );
			setDirty( false );
		} catch ( error ) {
			const thrown = /** @type {any} */ ( error );

			setFailure(
				String(
					thrown && thrown.message
						? thrown.message
						: __(
								'Não foi possível ler os estados.',
								'wc-checkoutsuite'
						  )
				)
			);
		} finally {
			setLoading( false );
		}
	}, [ client ] );

	useEffect( () => {
		load();
	}, [ load ] );

	/** The status being edited, or null. */
	const current = list[ selected ] ?? null;

	/** What the screen can see wrong with it. */
	const issues = useMemo(
		() => ( current ? statusIssues( current ) : [] ),
		[ current ]
	);

	/** The states WooCommerce owns, shown so the merchant sees the whole floor. */
	const platform = useMemo( () => coreStatuses( inventory ), [ inventory ] );

	/**
	 * Changes one field of the selected status.
	 *
	 * @param {Object} changes Fields to change.
	 * @return {void}
	 */
	const change = ( changes ) => {
		setList( ( entries ) => updateStatus( entries, selected, changes ) );
		setDirty( true );
		setSaved( '' );
		setConflict( false );
	};

	/**
	 * Adds a state, and selects it so it can be named.
	 *
	 * @return {void}
	 */
	const add = () => {
		setList( ( entries ) => {
			const next = [ ...entries, createStatus( '' ) ];

			setSelected( next.length - 1 );

			return next;
		} );
		setDirty( true );
		setSaved( '' );
		setConflict( false );
	};

	/**
	 * Removes the selected state from the store's list.
	 *
	 * @return {void}
	 */
	const remove = () => {
		setList( ( entries ) => removeStatus( entries, selected ) );
		setSelected( 0 );
		setDirty( true );
		setSaved( '' );
		setConflict( false );
	};

	/**
	 * Writes the list.
	 *
	 * @return {Promise<void>}
	 */
	const save = async () => {
		setSaving( true );
		setFailure( '' );

		try {
			const answer = await client.saveStatuses(
				payloadOf( list ),
				revision
			);

			setInventory( answer?.statuses ?? [] );
			setPaid( answer?.paid ?? [] );
			setList( customStatuses( answer?.statuses ?? [] ) );
			setRevision( Number( answer?.revision ?? revision + 1 ) );
			setConflict( false );
			setDirty( false );
			setSaved( __( 'Estados guardados.', 'wc-checkoutsuite' ) );
		} catch ( error ) {
			// The store refuses a status it cannot register and says why, key by key. The
			// refusal is shown rather than folded into "something went wrong", because the
			// merchant is the only one who can fix it.
			const thrown = /** @type {any} */ ( error );
			const details = thrown?.payload?.errors;
			const isConflict =
				409 === Number( thrown?.status ?? thrown?.payload?.status ) ||
				'wccs_statuses_conflict' === thrown?.code;
			setConflict( isConflict );

			setFailure(
				Array.isArray( details ) && details.length > 0
					? details
							.map( ( entry ) =>
								String( entry?.message ?? entry?.code ?? '' )
							)
							.join( ' ' )
					: String(
							thrown && thrown.message
								? thrown.message
								: __(
										'Não foi possível guardar os estados.',
										'wc-checkoutsuite'
								  )
					  )
			);
		} finally {
			setSaving( false );
		}
	};

	const topbar = (
		<TopbarActions>
			<button
				type="button"
				className="btn btn-primary"
				disabled={ ! dirty || saving || issues.length > 0 || conflict }
				onClick={ save }
			>
				<Icon name="save" />
				<span>
					{ saving
						? __( 'Guardando…', 'wc-checkoutsuite' )
						: __( 'Salvar alterações', 'wc-checkoutsuite' ) }
				</span>
			</button>
		</TopbarActions>
	);

	return (
		<>
			{ topbar }

			<section className="view active" aria-labelledby="statusesTitle">
				<div className="page-heading">
					<div>
						<div className="eyebrow">
							<span className="tiny-line" />
							{ __( 'STATUS E AUTOMAÇÕES', 'wc-checkoutsuite' ) }
						</div>
						<h1 id="statusesTitle">
							{ __(
								'Status personalizados',
								'wc-checkoutsuite'
							) }
							<span className="heading-dot">.</span>
						</h1>
						<p>
							{ __(
								'Os estados em que um pedido pode esperar. Um estado não cobra nada: quem cobra é uma transição de workflow com uma ação de pagamento autorizada.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>
				</div>

				{ conflict ? (
					<Notice
						status="warning"
						title={ __( 'Conflito de edição', 'wc-checkoutsuite' ) }
					>
						<p>
							{ __(
								'Outra sessão alterou os status. Recarregue a configuração para evitar sobrescrever alterações.',
								'wc-checkoutsuite'
							) }
						</p>
						<Button variant="secondary" onClick={ load }>
							{ __( 'Recarregar status', 'wc-checkoutsuite' ) }
						</Button>
					</Notice>
				) : null }

				{ failure ? (
					<Notice
						status="error"
						title={ __(
							'O estado não foi guardado',
							'wc-checkoutsuite'
						) }
						onDismiss={ () => setFailure( '' ) }
					>
						{ failure }
					</Notice>
				) : null }

				{ saved ? (
					<Notice
						status="success"
						title={ __( 'Alterações salvas', 'wc-checkoutsuite' ) }
					>
						{ saved }
					</Notice>
				) : null }

				<div className="wccs-statuses">
					<div className="panel wccs-statuses__list">
						<h2>
							{ __( 'Estados disponíveis', 'wc-checkoutsuite' ) }
						</h2>
						<p className="form-help">
							{ __(
								'Estados que a loja usa nos seus fluxos. Arraste para reordenar.',
								'wc-checkoutsuite'
							) }
						</p>

						{ loading ? (
							<p className="wccs-statuses__loading">
								{ __( 'A carregar…', 'wc-checkoutsuite' ) }
							</p>
						) : null }

						<ul className="wccs-statuses__items">
							{ list.map( ( entry, index ) => (
								<li key={ `custom-${ index }` }>
									<button
										type="button"
										aria-pressed={ index === selected }
										className={
											index === selected ? 'active' : ''
										}
										onClick={ () => setSelected( index ) }
									>
										<span
											className="wccs-statuses__swatch"
											style={ {
												background:
													entry.colour ||
													'transparent',
											} }
											aria-hidden="true"
										/>
										<span>
											<strong>
												{ entry.label ||
													__(
														'Estado sem nome',
														'wc-checkoutsuite'
													) }
											</strong>
											<small>
												{ entry.id ||
													__(
														'o identificador é criado ao guardar',
														'wc-checkoutsuite'
													) }
											</small>
										</span>
									</button>
								</li>
							) ) }
						</ul>

						{ /* WooCommerce's own states, so the merchant sees what they are
						     arranging theirs among — and never editable, because taking one
						     over would be taking over a state the platform decides. */ }
						{ platform.length > 0 ? (
							<>
								<h3 className="wccs-statuses__core-title">
									{ __(
										'Da WooCommerce',
										'wc-checkoutsuite'
									) }
								</h3>
								<ul className="wccs-statuses__items wccs-statuses__items--core">
									{ platform.map( ( entry ) => (
										<li key={ `core-${ entry.id }` }>
											<span>
												<strong>{ entry.label }</strong>
												<small>{ entry.id }</small>
											</span>
										</li>
									) ) }
								</ul>
							</>
						) : null }

						<Button variant="secondary" onClick={ add }>
							<Icon name="plus" />
							{ __( 'Novo estado', 'wc-checkoutsuite' ) }
						</Button>
					</div>

					<PreviewPanel
						title={ __( 'Prévia do status', 'wc-checkoutsuite' ) }
						description={ __(
							'A apresentação é separada das ações financeiras.',
							'wc-checkoutsuite'
						) }
					>
						{ current ? (
							<div className="wccs-status-preview">
								<span
									className="wccs-status-preview__badge"
									style={ { background: current.colour } }
								>
									{ current.customer_label ||
										current.label ||
										__( 'Sem nome', 'wc-checkoutsuite' ) }
								</span>
								<strong>
									{ current.label
										? sprintf(
												/* translators: %s: internal status label. */
												__(
													'Prévia: %s',
													'wc-checkoutsuite'
												),
												current.label
										  )
										: __(
												'Estado sem nome',
												'wc-checkoutsuite'
										  ) }
								</strong>
								<p>
									{ current.description ||
										__(
											'Sem observação interna.',
											'wc-checkoutsuite'
										) }
								</p>
								<Notice status="info">
									{ __(
										'Mudar um status nunca captura, autoriza ou estorna pagamento. Essas ações só podem ser configuradas em transições autorizadas de workflow.',
										'wc-checkoutsuite'
									) }
								</Notice>
							</div>
						) : (
							<p className="muted small">
								{ __(
									'Selecione um status para visualizar.',
									'wc-checkoutsuite'
								) }
							</p>
						) }
					</PreviewPanel>

					<div className="panel wccs-statuses__settings">
						<h2>
							{ __(
								'Configurações do estado',
								'wc-checkoutsuite'
							) }
						</h2>

						{ ! current ? (
							<Notice status="info">
								{ __(
									'Sem estados próprios. Crie um para configurar a sua apresentação e a sua visibilidade.',
									'wc-checkoutsuite'
								) }
							</Notice>
						) : (
							<>
								{ issues.length > 0 ? (
									<Notice
										status="warning"
										title={ __(
											'Este estado não pode ser guardado como está',
											'wc-checkoutsuite'
										) }
									>
										<ul>
											{ issues.map( ( message ) => (
												<li key={ message }>
													{ message }
												</li>
											) ) }
										</ul>
									</Notice>
								) : null }

								<TextField
									id="wccs-status-label"
									label={ __(
										'Nome do estado',
										'wc-checkoutsuite'
									) }
									value={ current.label }
									onChange={ ( /** @type {any} */ event ) =>
										change( { label: event.target.value } )
									}
								/>

								<TextField
									id="wccs-status-id"
									label={ __(
										'Slug interno',
										'wc-checkoutsuite'
									) }
									value={ current.id }
									help={ __(
										'Permanente, e criado uma vez a partir do nome. Renomear o estado não muda este identificador, e é ele que os pedidos guardam.',
										'wc-checkoutsuite'
									) }
									readOnly
								/>

								<SelectField
									id="wccs-status-colour"
									label={ __(
										'Cor do estado',
										'wc-checkoutsuite'
									) }
									value={ current.colour }
									options={ [
										...PALETTE,
										...( PALETTE.some(
											( entry ) =>
												entry.value === current.colour
										)
											? []
											: [
													{
														value: current.colour,
														label: current.colour,
													},
											  ] ),
									] }
									onChange={ ( /** @type {any} */ event ) =>
										change( { colour: event.target.value } )
									}
								/>

								<CheckboxField
									id="wccs-status-active"
									label={ __(
										'Estado ativo',
										'wc-checkoutsuite'
									) }
									help={ __(
										'Um estado inativo não é registado: os pedidos que já estão nele continuam a ter o mesmo identificador.',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.active ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											active: event.target.checked,
										} )
									}
								/>

								<CheckboxField
									id="wccs-status-customer"
									label={ __(
										'Exibir na área do cliente',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.show_customer ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											show_customer: event.target.checked,
										} )
									}
								/>

								<CheckboxField
									id="wccs-status-emails"
									label={ __(
										'Exibir em e-mails',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.show_emails ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											show_emails: event.target.checked,
										} )
									}
								/>

								<CheckboxField
									id="wccs-status-manual"
									label={ __(
										'Permitir ações manuais',
										'wc-checkoutsuite'
									) }
									help={ __(
										'Se a equipa pode mover um pedido para este estado a partir do ecrã do pedido. É uma permissão sobre mãos, não sobre dinheiro.',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.manual ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											manual: event.target.checked,
										} )
									}
								/>

								<CheckboxField
									id="wccs-status-prepayment"
									label={ __(
										'Estado antes do pagamento',
										'wc-checkoutsuite'
									) }
									help={ __(
										'Um estado antes do pagamento nunca é considerado pago pela loja, mesmo que outra extensão o acrescente à lista da WooCommerce.',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.prepayment ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											prepayment: event.target.checked,
										} )
									}
								/>

								{ current.prepayment ? (
									<Notice status="info">
										{ sprintf(
											/* translators: 1: name of the status, 2: whether it is in the paid list. */
											__(
												'«%1$s»: %2$s na lista de estados pagos da WooCommerce.',
												'wc-checkoutsuite'
											),
											current.label ||
												__(
													'este estado',
													'wc-checkoutsuite'
												),
											isPaid( paid, current.id )
												? __(
														'está na lista',
														'wc-checkoutsuite'
												  )
												: __(
														'não está na lista',
														'wc-checkoutsuite'
												  )
										) }
									</Notice>
								) : null }

								<TextField
									id="wccs-status-customer-label"
									label={ __(
										'Label para o cliente',
										'wc-checkoutsuite'
									) }
									value={ current.customer_label }
									help={ __(
										'Como o estado é escrito na conta do cliente, no agradecimento e nos e-mails. Vazio significa o mesmo nome que a equipa vê.',
										'wc-checkoutsuite'
									) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											customer_label: event.target.value,
										} )
									}
								/>

								<TextareaField
									id="wccs-status-description"
									label={ __(
										'Observação interna',
										'wc-checkoutsuite'
									) }
									value={ current.description }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											description: event.target.value,
										} )
									}
								/>

								{ /* Where the design draws behaviour, payment and transitions, the
								     screen says which phase carries them — so the merchant is not
								     left looking for a control that will not charge anything. */ }
								<Notice status="info">
									{ __(
										'O comportamento do pedido, as regras que aplicam este estado, as transições automáticas e o que acontece ao pagamento pertencem ao motor de workflow: são uma decisão por transição, e não uma propriedade do estado.',
										'wc-checkoutsuite'
									) }
								</Notice>

								<Button variant="secondary" onClick={ remove }>
									<Icon name="close" />
									{ __(
										'Remover estado',
										'wc-checkoutsuite'
									) }
								</Button>
							</>
						) }
					</div>
				</div>
			</section>

			<Toast message={ saved } />
		</>
	);
}
