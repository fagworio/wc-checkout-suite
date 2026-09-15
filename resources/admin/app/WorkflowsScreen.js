/**
 * The screen §13 draws: the automations, in steps, and a simulator that runs nothing.
 *
 * The design's screen is a list of automations, a stepped editor and a simulation panel. This is
 * all three, and one thing it does **not** draw is as deliberate as the rest: the stock and payment
 * strategies §13.2 steps 3 and 4 offer. Only `none` of each can be carried out by this build — the
 * reservation belongs to the stock phase and the payment action to the gateway capability registry —
 * and a select offering "autorizar agora e capturar após aprovação" would be promising an
 * authorisation nothing performs. The selects therefore offer what the store can honour, and a
 * notice names what is missing and why (§30.1).
 *
 * The simulator is the other half of that honesty. §13.6 says it "não executa cobrança", and it does
 * not execute anything at all: it answers what the configuration would decide, including whether
 * each strategy can be carried out today, and writes nothing.
 *
 * @package
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './components/Button';
import Notice from './components/Notice';
import ConditionBuilder from './components/ConditionBuilder';
import { TextField, SelectField, CheckboxField } from './components/controls';
import { Icon } from './design/icons';
import { TopbarActions } from './design/TopbarActions';
import { Toast } from './design/Toast';
import {
	createWorkflow,
	decisionRows,
	executableOptions,
	payloadOf,
	removeWorkflow,
	unavailableOptions,
	updateWorkflow,
	workflowIssues,
} from './schema/workflows';

/**
 * @param {Object} props        Component properties.
 * @param {any}    props.client REST client.
 * @return {*} Rendered element tree.
 */
export default function WorkflowsScreen( { client } ) {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ list, setList ] = useState( /** @type {Array<any>} */ ( [] ) );
	const [ vocabulary, setVocabulary ] = useState( /** @type {any} */ ( {} ) );
	const [ statuses, setStatuses ] = useState(
		/** @type {Array<any>} */ ( [] )
	);
	const [ selected, setSelected ] = useState( 0 );
	const [ dirty, setDirty ] = useState( false );
	const [ sample, setSample ] = useState(
		/** @type {Record<string, any>} */ ( {} )
	);
	const [ simulation, setSimulation ] = useState(
		/** @type {any} */ ( null )
	);
	/**
	 * The condition vocabulary, read from the same catalogue the field editor reads.
	 *
	 * Fetched here rather than passed in, because the rule tree a workflow runs on is the same tree
	 * a field carries (§14) and the vocabulary that describes it is published once, for everyone.
	 */
	const [ conditions, setConditions ] = useState( /** @type {any} */ ( {} ) );

	/** Reads the store's automations and the vocabulary they are written with. */
	const load = useCallback( async () => {
		setLoading( true );

		try {
			const answer = await client.workflows();

			setConditions( ( await client.fieldTypes() )?.conditions ?? {} );
			setList(
				Array.isArray( answer?.workflows ) ? answer.workflows : []
			);
			setVocabulary( answer?.vocabulary ?? {} );
			setStatuses( answer?.statuses ?? [] );
			setFailure( '' );
			setDirty( false );
		} catch ( error ) {
			const thrown = /** @type {any} */ ( error );

			setFailure(
				String(
					thrown?.message ??
						__(
							'Não foi possível ler as automações.',
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

	const current = list[ selected ] ?? null;
	const issues = useMemo(
		() => ( current ? workflowIssues( current ) : [] ),
		[ current ]
	);

	/**
	 * Changes one field of the selected automation.
	 *
	 * @param {Object} changes Fields to change.
	 * @return {void}
	 */
	const change = ( changes ) => {
		setList( ( entries ) => updateWorkflow( entries, selected, changes ) );
		setDirty( true );
		setSaved( '' );
	};

	/**
	 * Adds an automation and selects it.
	 *
	 * @return {void}
	 */
	const add = () => {
		setList( ( entries ) => {
			const next = [ ...entries, createWorkflow( '' ) ];

			setSelected( next.length - 1 );

			return next;
		} );
		setDirty( true );
		setSaved( '' );
	};

	/**
	 * Removes the selected automation.
	 *
	 * @return {void}
	 */
	const remove = () => {
		setList( ( entries ) => removeWorkflow( entries, selected ) );
		setSelected( 0 );
		setDirty( true );
		setSaved( '' );
	};

	/** Writes the list. */
	const save = async () => {
		setSaving( true );
		setFailure( '' );

		try {
			const answer = await client.saveWorkflows( payloadOf( list ) );

			setList(
				Array.isArray( answer?.workflows ) ? answer.workflows : list
			);
			setDirty( false );
			setSaved( __( 'Automações guardadas.', 'wc-checkoutsuite' ) );
		} catch ( error ) {
			const thrown = /** @type {any} */ ( error );
			const details = thrown?.payload?.errors;

			setFailure(
				Array.isArray( details ) && details.length > 0
					? details
							.map( ( entry ) =>
								String( entry?.message ?? entry?.code ?? '' )
							)
							.join( ' ' )
					: String(
							thrown?.message ??
								__(
									'Não foi possível guardar as automações.',
									'wc-checkoutsuite'
								)
					  )
			);
		} finally {
			setSaving( false );
		}
	};

	/** Asks the simulator what the configuration would do, and writes nothing. */
	const simulate = async () => {
		try {
			setSimulation( await client.simulate( sample ) );
			setFailure( '' );
		} catch ( error ) {
			const thrown = /** @type {any} */ ( error );

			setFailure(
				String(
					thrown?.message ??
						__(
							'Não foi possível simular o cenário.',
							'wc-checkoutsuite'
						)
				)
			);
		}
	};

	const stockMissing = unavailableOptions( vocabulary, 'inventory' );
	const paymentMissing = unavailableOptions( vocabulary, 'payment' );
	const statusOptions = ( statuses ?? [] ).map( ( entry ) => ( {
		value: entry.value,
		label: entry.label,
	} ) );

	const topbar = (
		<TopbarActions>
			<button
				type="button"
				className="btn btn-primary"
				disabled={ ! dirty || saving || issues.length > 0 }
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

			<section className="view active" aria-labelledby="workflowsTitle">
				<div className="page-heading">
					<div>
						<div className="eyebrow">
							<span className="tiny-line" />
							{ __( 'STATUS E AUTOMAÇÕES', 'wc-checkoutsuite' ) }
						</div>
						<h1 id="workflowsTitle">
							{ __( 'Automação de status', 'wc-checkoutsuite' ) }
							<span className="heading-dot">.</span>
						</h1>
						<p>
							{ __(
								'Quando o checkout é enviado, para que estado o pedido vai, quem é avisado e onde ele pode ir a seguir. Uma automação muda estados e não cobra nada: a cobrança é uma ação de pagamento com uma capability de gateway.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>
				</div>

				{ failure ? (
					<Notice
						status="error"
						title={ __(
							'A automação não foi guardada',
							'wc-checkoutsuite'
						) }
						onDismiss={ () => setFailure( '' ) }
					>
						{ failure }
					</Notice>
				) : null }

				<div className="wccs-workflows">
					<div className="panel wccs-workflows__list">
						<h2>{ __( 'Automações', 'wc-checkoutsuite' ) }</h2>
						<p className="form-help">
							{ __(
								'Cada automação decide por prioridade quando mais de uma corresponde ao mesmo pedido.',
								'wc-checkoutsuite'
							) }
						</p>

						{ loading ? (
							<p>{ __( 'A carregar…', 'wc-checkoutsuite' ) }</p>
						) : null }

						<ul className="wccs-workflows__items">
							{ list.map( ( entry, index ) => (
								<li key={ `workflow-${ index }` }>
									<button
										type="button"
										aria-pressed={ index === selected }
										className={
											index === selected ? 'active' : ''
										}
										onClick={ () => setSelected( index ) }
									>
										<strong>
											{ entry.name ||
												__(
													'Automação sem nome',
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
									</button>
								</li>
							) ) }
						</ul>

						<Button variant="secondary" onClick={ add }>
							<Icon name="plus" />
							{ __( 'Nova automação', 'wc-checkoutsuite' ) }
						</Button>
					</div>

					<div className="panel wccs-workflows__editor">
						{ ! current ? (
							<Notice status="info">
								{ __(
									'Sem automações. Enquanto não houver nenhuma, o pedido segue o processo da própria loja.',
									'wc-checkoutsuite'
								) }
							</Notice>
						) : (
							<>
								<h2>
									{ __(
										'Edição em passos',
										'wc-checkoutsuite'
									) }
								</h2>

								{ issues.length > 0 ? (
									<Notice
										status="warning"
										title={ __(
											'Esta automação não pode ser guardada como está',
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

								<h3>
									{ __(
										'Passo 1 — Disparo',
										'wc-checkoutsuite'
									) }
								</h3>
								<TextField
									id="wccs-workflow-name"
									label={ __( 'Nome', 'wc-checkoutsuite' ) }
									value={ current.name }
									onChange={ ( /** @type {any} */ event ) =>
										change( { name: event.target.value } )
									}
								/>
								<SelectField
									id="wccs-workflow-trigger"
									label={ __( 'Quando', 'wc-checkoutsuite' ) }
									value={ current.trigger }
									options={ vocabulary.triggers ?? [] }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											trigger: event.target.value,
										} )
									}
								/>

								<div className="form-label">
									{ __(
										'E as condições:',
										'wc-checkoutsuite'
									) }
								</div>
								<ConditionBuilder
									value={ current.conditions ?? {} }
									envelope=""
									vocabulary={ conditions }
									onChange={ ( tree ) =>
										change( { conditions: tree } )
									}
								/>

								<h3>
									{ __(
										'Passo 2 — Estado inicial',
										'wc-checkoutsuite'
									) }
								</h3>
								<SelectField
									id="wccs-workflow-initial"
									label={ __(
										'O pedido espera em',
										'wc-checkoutsuite'
									) }
									value={ current.initial_status }
									options={ [
										{
											value: '',
											label: __(
												'Escolher um estado',
												'wc-checkoutsuite'
											),
										},
										...statusOptions,
									] }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											initial_status: event.target.value,
										} )
									}
								/>

								<h3>
									{ __(
										'Passo 3 — Estoque',
										'wc-checkoutsuite'
									) }
								</h3>
								<SelectField
									id="wccs-workflow-inventory"
									label={ __(
										'Estoque',
										'wc-checkoutsuite'
									) }
									value={ current.inventory_strategy }
									options={ executableOptions(
										vocabulary,
										'inventory'
									) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											inventory_strategy:
												event.target.value,
										} )
									}
								/>

								<h3>
									{ __(
										'Passo 4 — Pagamento',
										'wc-checkoutsuite'
									) }
								</h3>
								<SelectField
									id="wccs-workflow-payment"
									label={ __(
										'Pagamento',
										'wc-checkoutsuite'
									) }
									value={ current.payment_strategy }
									options={ executableOptions(
										vocabulary,
										'payment'
									) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											payment_strategy:
												event.target.value,
										} )
									}
								/>
								<Notice status="info">
									{ sprintf(
										/* translators: %s: comma separated list of strategy names the store cannot carry out yet. */
										__(
											'As restantes estratégias de estoque e de pagamento (%s) não são oferecidas porque esta versão não as executa: a reserva de estoque chega na fase da reserva e a ação de pagamento depende do registo de capabilities do gateway.',
											'wc-checkoutsuite'
										),
										[ ...stockMissing, ...paymentMissing ]
											.map( ( entry ) => entry.label )
											.join( ', ' )
									) }
								</Notice>

								<h3>
									{ __(
										'Passo 5 — Comunicação',
										'wc-checkoutsuite'
									) }
								</h3>
								{ ( vocabulary.events ?? [] ).map(
									( /** @type {any} */ event ) => (
										<CheckboxField
											key={ event.value }
											id={ `wccs-workflow-event-${ event.value }` }
											label={ event.label }
											checked={ Boolean(
												current.communications?.[
													event.value
												]
											) }
											onChange={ (
												/** @type {any} */ changeEvent
											) =>
												change( {
													communications: {
														...current.communications,
														[ event.value ]:
															changeEvent.target
																.checked,
													},
												} )
											}
										/>
									)
								) }

								<h3>
									{ __(
										'Passo 6 — Decisão e expiração',
										'wc-checkoutsuite'
									) }
								</h3>
								{ decisionRows(
									vocabulary,
									statusOptions,
									current
								).map( ( row ) => (
									<SelectField
										key={ row.value }
										id={ `wccs-workflow-decision-${ row.value }` }
										label={ row.label }
										value={ row.status }
										options={ [
											{
												value: '',
												label: __(
													'Sem transição',
													'wc-checkoutsuite'
												),
											},
											...row.options,
										] }
										onChange={ (
											/** @type {any} */ event
										) =>
											change( {
												transitions: {
													...current.transitions,
													[ row.value ]:
														event.target.value,
												},
											} )
										}
									/>
								) ) }

								<TextField
									id="wccs-workflow-hours"
									label={ __(
										'Expirar ao fim de (horas)',
										'wc-checkoutsuite'
									) }
									type="number"
									value={ String(
										current.expires_after_hours ?? 0
									) }
									help={ __(
										'Zero significa nunca. Um pedido que expira precisa de uma transição de expiração, e vice-versa.',
										'wc-checkoutsuite'
									) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											expires_after_hours:
												parseInt(
													event.target.value,
													10
												) || 0,
										} )
									}
								/>

								<CheckboxField
									id="wccs-workflow-enabled"
									label={ __(
										'Automação ligada',
										'wc-checkoutsuite'
									) }
									checked={ Boolean( current.enabled ) }
									onChange={ ( /** @type {any} */ event ) =>
										change( {
											enabled: event.target.checked,
										} )
									}
								/>

								<Button variant="secondary" onClick={ remove }>
									<Icon name="close" />
									{ __(
										'Remover automação',
										'wc-checkoutsuite'
									) }
								</Button>
							</>
						) }
					</div>

					<div className="panel wccs-workflows__simulation">
						<h2>{ __( 'Testar cenário', 'wc-checkoutsuite' ) }</h2>
						<p className="form-help">
							{ __(
								'O simulador não executa nada: responde o que a configuração decidiria, e não guarda nem move pedido nenhum.',
								'wc-checkoutsuite'
							) }
						</p>

						<TextField
							id="wccs-sim-categories"
							label={ __(
								'Categorias no carrinho',
								'wc-checkoutsuite'
							) }
							value={ String( sample.cart_categories ?? '' ) }
							help={ __(
								'Slugs separados por vírgula.',
								'wc-checkoutsuite'
							) }
							onChange={ ( /** @type {any} */ event ) =>
								setSample( {
									...sample,
									cart_categories: event.target.value,
								} )
							}
						/>
						<TextField
							id="wccs-sim-country"
							label={ __( 'País', 'wc-checkoutsuite' ) }
							value={ String( sample.country ?? '' ) }
							onChange={ ( /** @type {any} */ event ) =>
								setSample( {
									...sample,
									country: event.target.value,
								} )
							}
						/>
						<TextField
							id="wccs-sim-subtotal"
							label={ __( 'Subtotal', 'wc-checkoutsuite' ) }
							type="number"
							value={ String( sample.cart_subtotal ?? '' ) }
							onChange={ ( /** @type {any} */ event ) =>
								setSample( {
									...sample,
									cart_subtotal: event.target.value,
								} )
							}
						/>

						<Button variant="secondary" onClick={ simulate }>
							<Icon name="eye" />
							{ __( 'Testar cenário', 'wc-checkoutsuite' ) }
						</Button>

						{ simulation ? (
							<div className="wccs-workflows__result">
								<p className="condition-result">
									{ simulation.next }
								</p>
								{ simulation.overlaps?.length > 0 ? (
									<Notice status="warning">
										{ sprintf(
											/* translators: %s: the automations that both apply. */
											__(
												'Mais do que uma automação responde a este cenário (%s); a prioridade decide.',
												'wc-checkoutsuite'
											),
											simulation.overlaps.join( ', ' )
										) }
									</Notice>
								) : null }
								<ul className="wccs-workflows__answer">
									<li>
										{ __( 'Estoque:', 'wc-checkoutsuite' ) }{ ' ' }
										{ simulation.inventory?.label }
										{ simulation.inventory?.executable
											? ''
											: ' ' +
											  __(
													'(não executado por esta versão)',
													'wc-checkoutsuite'
											  ) }
									</li>
									<li>
										{ __(
											'Pagamento:',
											'wc-checkoutsuite'
										) }{ ' ' }
										{ simulation.payment?.label }
										{ simulation.payment?.executable
											? ''
											: ' ' +
											  __(
													'(não executado por esta versão)',
													'wc-checkoutsuite'
											  ) }
									</li>
								</ul>
							</div>
						) : null }
					</div>
				</div>
			</section>

			<Toast message={ saved } />
		</>
	);
}
