/**
 * The checkouts a store runs, as §6.3 draws them.
 *
 * The strip of tabs above the editor is the design's: `[ Checkout padrão ] [ Checkout digital ] …
 * [ + Novo checkout ]`, with `Condições de exibição` at the right of the active one. What the
 * design does not show, and what this panel therefore states in words, is what each tab means:
 *
 * - **Checkout padrão is not a profile.** It is the store's own composition — the document — and it
 *   always exists. It is the tab that edits the containers the store already has, and the tab the
 *   customer gets when no profile matches.
 * - **A profile is selected by the cart** (§6.9), so the panel shows, for the active one, the rule
 *   that selects it, where it sits in the priority order, and whether it is the fallback.
 * - **The only fallback is not deleted.** The server would accept it; the store would then send
 *   every cart nobody claimed to a composition the merchant did not choose for them, so the button
 *   is not offered and the reason is stated.
 * - **Two profiles that could both be selected are shown, not refused** (§6.9), with the values the
 *   merchant typed as the sample.
 * - **A minimal checkout is verified before it is saved** (§6.3), against facts the server reports
 *   and never against a guess.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import Dialog from './Dialog';
import Notice from './Notice';
import { TextField, SelectField, CheckboxField } from './controls';
import { Icon } from '../design/icons';
import ConditionBuilder from './ConditionBuilder';
import { describe } from '../schema/conditions';
import { minimalChecklist, overlapsFor } from '../schema/profiles';

/**
 * The design's label for the store's own composition.
 *
 * @type {string}
 */
const OWN_LABEL = __( 'Checkout padrão', 'wc-checkoutsuite' );

/**
 * What each source is called in the modal.
 *
 * @type {Array<{value: string, label: string, help: string}>}
 */
const SOURCES = [
	{
		value: 'woocommerce_current',
		label: __( 'Checkout atual do WooCommerce', 'wc-checkoutsuite' ),
		help: __(
			'Começa com as seções e os campos que o checkout tem hoje.',
			'wc-checkoutsuite'
		),
	},
	{
		value: 'duplicate_profile',
		label: __( 'Duplicar outro checkout', 'wc-checkoutsuite' ),
		help: __(
			'Começa com a composição de um checkout que já existe.',
			'wc-checkoutsuite'
		),
	},
	{
		value: 'minimal',
		label: __( 'Checkout mínimo', 'wc-checkoutsuite' ),
		help: __(
			'Mantém apenas o essencial. As obrigações técnicas são verificadas antes de guardar.',
			'wc-checkoutsuite'
		),
	},
];

/**
 * @param {Object}     props                  Component properties.
 * @param {Array<any>} props.profiles         Stored profiles.
 * @param {string}     props.active           Active profile id, or an empty string for the store's own.
 * @param {Function}   props.onSelect         Called with the profile id to show.
 * @param {Function}   props.onCreate         Called with `{ name, source, from }`.
 * @param {Function}   props.onUpdate         Called with `( id, changes )`.
 * @param {Function}   props.onRemove         Called with the profile id.
 * @param {Function}   props.onMove           Called with `( id, delta )`.
 * @param {Object}     props.vocabulary       Condition vocabulary.
 * @param {Array<any>} props.fields           Fields of the document, for what the integrations require.
 * @param {any}        props.facts            What the store reports for a minimal composition.
 * @param {boolean}    props.factsChecked     Whether the store answered.
 * @param {string}     props.refusal          Code of the last refusal, when there was one.
 * @param {Function}   props.onDismissRefusal Called when the refusal is acknowledged.
 * @return {*} Rendered element tree.
 */
export default function CheckoutProfilesPanel( {
	profiles,
	active,
	onSelect,
	onCreate,
	onUpdate,
	onRemove,
	onMove,
	vocabulary,
	fields,
	facts,
	factsChecked,
	refusal,
	onDismissRefusal,
} ) {
	const [ createOpen, setCreateOpen ] = useState( false );
	const [ conditionsOpen, setConditionsOpen ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ source, setSource ] = useState( 'woocommerce_current' );
	const [ from, setFrom ] = useState( profiles[ 0 ]?.id ?? '' );

	/*
	 * The sample the overlap question is asked with. The merchant types it, because the only
	 * honest answer to "would two checkouts answer this cart?" needs a cart — and a store that
	 * guessed one would be previewing a store that does not exist. A category is enough for the
	 * rules §6.9 draws its example from.
	 */
	const [ sample, setSample ] = useState( '' );

	const sampleContext = {
		cart_categories: sample
			.split( ',' )
			.map( ( entry ) => entry.trim() )
			.filter( Boolean ),
	};

	const overlaps = sample
		? overlapsFor( profiles, sampleContext, vocabulary )
		: [];

	const current =
		profiles.find( ( profile ) => profile.id === active ) ?? null;

	/** Whether the active composition is a profile at all. */
	const editingOwn = '' === active || null === current;

	/**
	 * Why a profile cannot be deleted, in the screen's words.
	 *
	 * @param {string} code Stable code the rule answered with.
	 * @return {string} Sentence.
	 */
	const refusalSentence = ( code ) => {
		if ( 'only_fallback' === code ) {
			return __(
				'Este é o único checkout que responde aos carrinhos que nenhuma outra regra cobre. Escolha outro como padrão antes de o excluir.',
				'wc-checkoutsuite'
			);
		}

		return '';
	};

	/**
	 * Closes the modal and forgets what was typed.
	 *
	 * @return {void}
	 */
	const closeCreate = () => {
		setCreateOpen( false );
		setName( '' );
		setSource( 'woocommerce_current' );
	};

	/**
	 * Creates the checkout the merchant described.
	 *
	 * @return {void}
	 */
	const submitCreate = () => {
		onCreate( {
			name: name.trim(),
			source,
			from: 'duplicate_profile' === source ? from : null,
		} );
		closeCreate();
	};

	return (
		<div className="wccs-checkouts">
			<div
				className="wccs-checkouts__strip"
				role="tablist"
				aria-label={ __( 'Checkouts da loja', 'wc-checkoutsuite' ) }
			>
				<button
					type="button"
					role="tab"
					aria-selected={ editingOwn }
					className={ editingOwn ? 'active' : '' }
					onClick={ () => onSelect( '' ) }
				>
					<Icon name="spark" />
					<strong>{ OWN_LABEL }</strong>
				</button>

				{ profiles.map( ( profile ) => (
					<button
						key={ profile.id }
						type="button"
						role="tab"
						aria-selected={ profile.id === active }
						className={ profile.id === active ? 'active' : '' }
						onClick={ () => onSelect( profile.id ) }
					>
						{ profile.fallback ? <Icon name="shield" /> : null }
						<strong>{ profile.name }</strong>
						{ ! profile.enabled ? (
							<small>
								{ __( 'Desligado', 'wc-checkoutsuite' ) }
							</small>
						) : null }
					</button>
				) ) }

				<button
					type="button"
					className="wccs-checkouts__new"
					onClick={ () => setCreateOpen( true ) }
				>
					<Icon name="plus" />
					{ __( 'Novo checkout', 'wc-checkoutsuite' ) }
				</button>

				{ ! editingOwn ? (
					<button
						type="button"
						className="btn wccs-checkouts__conditions"
						onClick={ () => setConditionsOpen( true ) }
					>
						<Icon name="branch" />
						{ __( 'Condições de exibição', 'wc-checkoutsuite' ) }
					</button>
				) : null }
			</div>

			{ refusal ? (
				<Notice
					status="warning"
					title={ __(
						'Não foi possível excluir',
						'wc-checkoutsuite'
					) }
					onDismiss={ onDismissRefusal }
				>
					{ refusalSentence( refusal ) }
				</Notice>
			) : null }

			{ editingOwn ? (
				<p className="wccs-checkouts__own">
					{ __(
						'Este é o checkout da própria loja: existe sempre, é o que o documento guarda e é o que o cliente recebe quando nenhuma condição de um checkout alternativo é satisfeita.',
						'wc-checkoutsuite'
					) }
				</p>
			) : (
				<div className="wccs-checkouts__detail">
					<p className="wccs-checkouts__rule">
						<strong>
							{ __( 'Usar quando:', 'wc-checkoutsuite' ) }
						</strong>{ ' ' }
						{ current.conditions &&
						Object.keys( current.conditions ).length > 0
							? describe(
									current.conditions,
									vocabulary,
									( field ) => field
							  )
							: __(
									'Sempre que este checkout puder ser escolhido.',
									'wc-checkoutsuite'
							  ) }
					</p>

					{ /* §6.4: with this checkout selected, the list of sections beside this panel is
					     *this* checkout's composition — the one the cart receives — and the store's own
					     list is the one the strip's own tab shows. Saying which is which is the
					     difference between a merchant editing this composition and a merchant believing
					     they are editing the other one. */ }
					<Notice status="info">
						{ __(
							'As secções à esquerda são a composição deste checkout, e é ela que o carrinho recebe. O checkout da própria loja guarda a sua, no separador sem nome de checkout.',
							'wc-checkoutsuite'
						) }
					</Notice>

					<div className="wccs-checkouts__actions">
						<Button
							variant="secondary"
							onClick={ () => onMove( current.id, -1 ) }
						>
							<Icon name="up" />
							{ __( 'Considerar antes', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => onMove( current.id, 1 ) }
						>
							<Icon name="down" />
							{ __( 'Considerar depois', 'wc-checkoutsuite' ) }
						</Button>
						<span className="wccs-checkouts__priority">
							{ sprintf(
								/* translators: %d: priority number. Higher is considered first. */
								__( 'Prioridade %d', 'wc-checkoutsuite' ),
								Number( current.priority ) || 0
							) }
						</span>
					</div>

					<div className="wccs-checkouts__switches">
						<CheckboxField
							id="wccs-profile-enabled"
							label={ __(
								'Checkout ligado',
								'wc-checkoutsuite'
							) }
							help={ __(
								'Um checkout desligado não é escolhido por carrinho nenhum, e também não responde como padrão.',
								'wc-checkoutsuite'
							) }
							checked={ Boolean( current.enabled ) }
							onChange={ ( /** @type {any} */ event ) =>
								onUpdate( current.id, {
									enabled: event.target.checked,
								} )
							}
						/>
						<CheckboxField
							id="wccs-profile-fallback"
							label={ __(
								'Checkout padrão para carrinhos sem correspondência',
								'wc-checkoutsuite'
							) }
							help={ __(
								'Responde quando nenhuma condição é satisfeita. Só um checkout pode estar neste lugar; marcar este desmarca o anterior.',
								'wc-checkoutsuite'
							) }
							checked={ Boolean( current.fallback ) }
							onChange={ ( /** @type {any} */ event ) =>
								onUpdate( current.id, {
									fallback: event.target.checked,
								} )
							}
						/>
					</div>

					<TextField
						id="wccs-profile-sample"
						label={ __(
							'Verificar sobreposição com um carrinho',
							'wc-checkoutsuite'
						) }
						help={ __(
							'Escreva as categorias de produto de um carrinho, separadas por vírgula. Dois checkouts que respondam ao mesmo carrinho são avisados, não recusados: a prioridade decide.',
							'wc-checkoutsuite'
						) }
						value={ sample }
						placeholder={ __( 'quimicos', 'wc-checkoutsuite' ) }
						onChange={ ( /** @type {any} */ event ) =>
							setSample( event.target.value )
						}
					/>

					{ sample && overlaps.length > 0 ? (
						<Notice
							status="warning"
							title={ __(
								'Dois checkouts podem ser escolhidos pelas mesmas condições',
								'wc-checkoutsuite'
							) }
						>
							{ overlaps
								.map( ( pair ) =>
									sprintf(
										/* translators: 1: first checkout name, 2: second checkout name. */
										__(
											'«%1$s» e «%2$s» respondem a este carrinho; a prioridade decide, e «%1$s» é considerada primeiro.',
											'wc-checkoutsuite'
										),
										pair.profile.name,
										pair.other.name
									)
								)
								.join( ' ' ) }
						</Notice>
					) : null }

					{ 'minimal' === current.source ? (
						<MinimalChecklist
							profile={ current }
							fields={ fields }
							facts={ facts }
							checked={ factsChecked }
						/>
					) : null }

					<Button
						variant="secondary"
						className="wccs-checkouts__remove"
						onClick={ () => onRemove( current.id ) }
					>
						<Icon name="close" />
						{ __( 'Excluir checkout', 'wc-checkoutsuite' ) }
					</Button>
				</div>
			) }

			{ /* The rule a profile is selected by is the shared rule tree (§14). The builder is the
			     same one the field inspector uses, told that a profile stores the tree bare: the
			     envelope is what names what a rule decides, and a profile decides which checkout
			     runs, not whether something is visible. */ }
			<Dialog
				open={ conditionsOpen && null !== current }
				title={ __( 'Condições de exibição', 'wc-checkoutsuite' ) }
				eyebrow={ __( 'Checkout alternativo', 'wc-checkoutsuite' ) }
				subtitle={ __(
					'Este checkout é usado quando as condições abaixo forem satisfeitas. Se nenhuma condição for definida, ele pode ser escolhido por qualquer carrinho.',
					'wc-checkoutsuite'
				) }
				onClose={ () => setConditionsOpen( false ) }
				footer={
					<Button
						variant="primary"
						onClick={ () => setConditionsOpen( false ) }
					>
						{ __( 'Fechar', 'wc-checkoutsuite' ) }
					</Button>
				}
			>
				{ null !== current ? (
					<ConditionBuilder
						value={ current.conditions ?? {} }
						envelope=""
						vocabulary={ vocabulary }
						fields={ ( fields ?? [] ).map( ( field ) => ( {
							id: field.id,
							label: field.label ?? field.id,
						} ) ) }
						onChange={ ( conditions ) =>
							onUpdate( current.id, { conditions } )
						}
					/>
				) : null }
			</Dialog>

			<Dialog
				open={ createOpen }
				title={ __( 'Novo checkout', 'wc-checkoutsuite' ) }
				eyebrow={ __( 'Checkouts', 'wc-checkoutsuite' ) }
				subtitle={ __(
					'Um checkout alternativo é escolhido pelas condições que definir a seguir.',
					'wc-checkoutsuite'
				) }
				onClose={ closeCreate }
				footer={
					<>
						<Button variant="secondary" onClick={ closeCreate }>
							{ __( 'Cancelar', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ '' === name.trim() }
							onClick={ submitCreate }
						>
							{ __( 'Criar checkout', 'wc-checkoutsuite' ) }
						</Button>
					</>
				}
			>
				<TextField
					id="wccs-profile-name"
					label={ __( 'Nome', 'wc-checkoutsuite' ) }
					value={ name }
					placeholder={ __( 'Checkout digital', 'wc-checkoutsuite' ) }
					onChange={ ( /** @type {any} */ event ) =>
						setName( event.target.value )
					}
				/>

				<fieldset className="wccs-checkouts__sources">
					<legend>
						{ __( 'Começar com:', 'wc-checkoutsuite' ) }
					</legend>
					{ SOURCES.map( ( option ) => (
						<label
							key={ option.value }
							htmlFor={ `wccs-profile-source-${ option.value }` }
						>
							<input
								id={ `wccs-profile-source-${ option.value }` }
								type="radio"
								name="wccs-profile-source"
								value={ option.value }
								checked={ option.value === source }
								onChange={ () => setSource( option.value ) }
							/>
							<span>
								{ /* The visible label is the strong element; it is inside the
								     label, which is what associates the two for a screen
								     reader as well as for the eye. */ }
								<strong>{ option.label }</strong>
								<small>{ option.help }</small>
							</span>
						</label>
					) ) }
				</fieldset>

				{ 'duplicate_profile' === source && profiles.length > 0 ? (
					<SelectField
						id="wccs-profile-from"
						label={ __( 'Duplicar de', 'wc-checkoutsuite' ) }
						value={ from }
						onChange={ ( /** @type {any} */ event ) =>
							setFrom( event.target.value )
						}
						options={ profiles.map( ( profile ) => ( {
							value: profile.id,
							label: profile.name,
						} ) ) }
					/>
				) : null }
			</Dialog>
		</div>
	);
}

/**
 * What a minimal composition has to be able to answer, before it is saved.
 *
 * The rules are `schema/profiles`; this draws them. Four of the five are facts the server read from
 * WooCommerce, and the fifth — whether a required field still has somewhere to be filled in — is
 * answered from the composition itself. A checklist that guessed would agree with a store that
 * cannot take a payment.
 *
 * @param {Object}     props         Component properties.
 * @param {any}        props.profile The composition being checked.
 * @param {Array<any>} props.fields  Fields of the document.
 * @param {any}        props.facts   What the store reports.
 * @param {boolean}    props.checked Whether the store answered.
 * @return {*} Rendered element tree.
 */
function MinimalChecklist( { profile, fields, facts, checked } ) {
	if ( ! checked || ! facts ) {
		return (
			<Notice
				status="info"
				title={ __( 'Checkout mínimo', 'wc-checkoutsuite' ) }
			>
				{ __(
					'As obrigações técnicas deste checkout ainda não foram verificadas.',
					'wc-checkoutsuite'
				) }
			</Notice>
		);
	}

	const requirements = minimalChecklist( profile, fields ?? [], facts );
	const missing = requirements.filter( ( entry ) => ! entry.met );

	if ( 0 === missing.length ) {
		return (
			<Notice
				status="success"
				title={ __( 'Checkout mínimo', 'wc-checkoutsuite' ) }
			>
				{ __(
					'Meio de pagamento, impostos, entrega, dados exigidos pelas integrações e regras legais estão todos satisfeitos.',
					'wc-checkoutsuite'
				) }
			</Notice>
		);
	}

	return (
		<Notice
			status="warning"
			title={ __(
				'Um checkout mínimo não ignora obrigações técnicas',
				'wc-checkoutsuite'
			) }
		>
			<ul>
				{ missing.map( ( entry ) => (
					<li key={ entry.key }>
						<strong>{ entry.label }</strong> — { entry.reason }
					</li>
				) ) }
			</ul>
		</Notice>
	);
}
