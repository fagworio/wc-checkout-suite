/**
 * The design's preview screen.
 *
 * The prototype draws a whole store checkout — header, address blocks, a payment
 * strip, an order summary — and fills it from a demo document it keeps in
 * localStorage. This is the same screen with the same markup, generated from the
 * draft the editor is holding: the sections, the fields that are enabled, their
 * descriptions, their widths and their required marks.
 *
 * What the prototype invents and this screen does not is stated where the design
 * states it. The order summary keeps the prototype's own words — the products,
 * the shipping and the total are illustrative and it says so — and the payment
 * strip keeps the sentence that matters most for this plugin: the Suite does not
 * collect card or CVV, the gateway's own component does.
 *
 * One control the design offers has nothing behind it here. "Publicado" would mean
 * reading the published slot, and the administration has no route for that: the
 * published checkout is the store itself. So the button is disabled and says why,
 * rather than switching to a document that does not exist.
 */

import { useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { sectionGroups } from '../schema/fieldOperations';
import { Icon } from '../design/icons';
import { sectionCopy } from '../design/sectionMeta';

/**
 * The device widths the design offers, and the class its stylesheet uses.
 *
 * @type {Record<string,string>}
 */
const DEVICES = {
	desktop: '',
	tablet: 'tablet',
	mobile: 'mobile',
};

/**
 * How a field's type is drawn in the preview.
 *
 * The checkout itself maps types to controls in its own renderers; this is the
 * preview's own mapping, and it is deliberately small: a type this map does not
 * know is drawn as a text input, which is what the customer would see for the
 * types that reach the classic checkout as text.
 *
 * @param {any} field Field definition.
 * @return {string} Control kind.
 */
function controlFor( field ) {
	const known = [
		'textarea',
		'select',
		'radio',
		'checkbox',
		'file',
		'date',
		'time',
		'datetime',
		'email',
		'tel',
		'url',
		'number',
	];

	return known.includes( field?.type ) ? field.type : 'text';
}

/**
 * The options a select or a radio group offers.
 *
 * @param {any} field Field definition.
 * @return {any[]} Options.
 */
function optionsFor( field ) {
	const options = field?.settings?.options;

	return Array.isArray( options ) ? options : [];
}

/**
 * The connected preview.
 *
 * @param {Object}     props            Component properties.
 * @param {any}        props.document   Draft document.
 * @param {string}     [props.siteName] Store name, for the mock header.
 * @param {() => void} props.onBack     Returns to the editor.
 * @return {*} Rendered element tree.
 */
export default function PreviewView( { document: doc, siteName, onBack } ) {
	const [ device, setDevice ] = useState( 'desktop' );
	const [ findings, setFindings ] = useState(
		/** @type {{missing: string[]}|null} */ ( null )
	);
	const form = useRef( /** @type {any} */ ( null ) );

	/** The sections the preview draws: those with at least one enabled field. */
	const sections = useMemo(
		() =>
			sectionGroups( doc )
				.map( ( /** @type {any} */ group ) => ( {
					...group,
					fields: ( group.fields ?? [] ).filter(
						( /** @type {any} */ field ) => field.enabled
					),
				} ) )
				.filter(
					( /** @type {any} */ group ) => group.fields.length > 0
				),
		[ doc ]
	);

	const total = sections.reduce(
		( count, /** @type {any} */ group ) => count + group.fields.length,
		0
	);

	/**
	 * The section's heading, in the words the editor uses for the same section.
	 *
	 * A declared section keeps the merchant's title; a section that is only a
	 * WooCommerce location takes the design's copy, which is what the editor's own
	 * tabs show. Reading the catalog's label instead would print "Billing" on the
	 * storefront the merchant is looking at.
	 *
	 * @param {any} group Section group.
	 * @return {string} Title.
	 */
	const sectionTitle = ( group ) =>
		sectionCopy(
			group?.section ?? { id: 'order', title: '', description: '' },
			Boolean( group?.declared )
		).title;

	/**
	 * Checks the preview's own inputs for the one rule the design claims.
	 *
	 * The prototype's legal note is explicit about the limit — "validação básica
	 * local, sem verificação matemática" — so this checks the required fields and
	 * nothing else. It never claims a document number is valid.
	 *
	 * @return {void}
	 */
	const validate = () => {
		const element = form.current;

		if ( ! element ) {
			return;
		}

		/** @type {string[]} */
		const missing = [];

		sections.forEach( ( /** @type {any} */ group ) => {
			group.fields.forEach( ( /** @type {any} */ field ) => {
				if ( ! field.required ) {
					return;
				}

				const control = element.elements.namedItem( field.id );
				let value = '';

				if ( control && 'value' in control ) {
					value = String( control.value ?? '' ).trim();
				}

				if ( '' === value ) {
					missing.push( field.label || field.id );
				}
			} );
		} );

		setFindings( { missing } );
	};

	const name = siteName || __( 'Sua loja', 'wc-checkoutsuite' );
	const initial = name.trim().slice( 0, 1 ).toLocaleLowerCase( 'pt-BR' );

	return (
		<>
			<section
				className="view active"
				id="previewView"
				aria-labelledby="previewTitle"
			>
				<div className="page-heading">
					<div>
						<div className="eyebrow">
							<span className="tiny-line" />
							{ __( 'PRÉVIA CONECTADA', 'wc-checkoutsuite' ) }
						</div>
						<h1 id="previewTitle">
							{ __(
								'Do editor para o checkout',
								'wc-checkoutsuite'
							) }
							<span className="heading-dot">.</span>
						</h1>
						<p>
							{ __(
								'Os campos abaixo são gerados pela configuração. Sem dados reais ou cobranças.',
								'wc-checkoutsuite'
							) }
						</p>
					</div>
					<button type="button" className="btn" onClick={ onBack }>
						<Icon name="back" />
						{ __( 'Voltar ao editor', 'wc-checkoutsuite' ) }
					</button>
				</div>

				<div className="preview-toolbar">
					<div
						className="segmented"
						aria-label={ __(
							'Origem da prévia',
							'wc-checkoutsuite'
						) }
					>
						<button type="button" className="active" aria-pressed>
							{ __( 'Rascunho atual', 'wc-checkoutsuite' ) }
						</button>
						<button
							type="button"
							disabled
							title={ __(
								'A prévia mostra o rascunho. A versão publicada é o checkout da loja, e a administração não tem rota para ler o documento publicado.',
								'wc-checkoutsuite'
							) }
						>
							{ __( 'Publicado', 'wc-checkoutsuite' ) }
						</button>
					</div>
					<div className="inline-actions">
						<span className="muted small">
							{ sprintf(
								/* translators: %d: number of fields. */
								__(
									'%d campo(s) na prévia',
									'wc-checkoutsuite'
								),
								total
							) }
						</span>
						<div
							className="segmented devices"
							aria-label={ __(
								'Tamanho da prévia',
								'wc-checkoutsuite'
							) }
						>
							{ Object.keys( DEVICES ).map( ( key ) => (
								<button
									key={ key }
									type="button"
									className={ device === key ? 'active' : '' }
									aria-pressed={ device === key }
									aria-label={ sprintf(
										/* translators: %s: device name. */
										__( 'Prévia %s', 'wc-checkoutsuite' ),
										key
									) }
									onClick={ () => setDevice( key ) }
								>
									<Icon name={ key } />
								</button>
							) ) }
						</div>
					</div>
				</div>

				<div className="preview-caption">
					{ __(
						'Prévia do rascunho. Nada aqui altera a loja, e nenhum pedido é criado.',
						'wc-checkoutsuite'
					) }
				</div>

				<div
					className={ `preview-shell wccs-checkout ${ DEVICES[ device ] }` }
					id="previewShell"
				>
					<div className="store-header">
						<div className="store-brand">
							<span className="store-symbol">{ initial }.</span>
							{ name }
							<span className="brand-dot">.</span>
						</div>
						<span>
							<Icon name="shield" />
							{ __( 'Prévia do rascunho', 'wc-checkoutsuite' ) }
						</span>
					</div>

					<div className="store-layout">
						<form
							className="store-form"
							ref={ form }
							noValidate
							onSubmit={ (
								/** @type {{preventDefault: () => void}} */ event
							) => {
								event.preventDefault();
								validate();
							} }
						>
							{ sections.length === 0 ? (
								<div className="empty-state">
									<Icon name="eye" />
									<h3>
										{ __(
											'Nada para pré-visualizar ainda.',
											'wc-checkoutsuite'
										) }
									</h3>
									<p>
										{ __(
											'Adicione um campo no editor e habilite-o: a prévia mostra os campos habilitados do rascunho.',
											'wc-checkoutsuite'
										) }
									</p>
								</div>
							) : (
								sections.map(
									(
										/** @type {any} */ group,
										/** @type {number} */ index
									) => (
										<div
											className="store-section"
											key={ group.section.id }
										>
											<h2>
												<span className="step">
													{ index + 1 }
												</span>
												{ sectionTitle( group ) }
											</h2>
											{ group.section.description ? (
												<p>
													{
														group.section
															.description
													}
												</p>
											) : null }
											<div className="field-grid">
												{ group.fields.map(
													(
														/** @type {any} */ field
													) => (
														<PreviewField
															key={ field.id }
															field={ field }
														/>
													)
												) }
											</div>
										</div>
									)
								)
							) }

							<div className="payment-demo">
								<h2>
									<span className="step">+</span>
									{ __( 'Pagamento', 'wc-checkoutsuite' ) }
								</h2>
								<p>
									{ __(
										'O layout acolhe os gateways. A Suite não coleta cartão ou CVV.',
										'wc-checkoutsuite'
									) }
								</p>
								<fieldset className="pay-options">
									<legend className="sr-only">
										{ __(
											'Forma de pagamento de demonstração',
											'wc-checkoutsuite'
										) }
									</legend>
									<div className="pay-item">
										<label htmlFor="wccs-demo-payment-card">
											<input
												id="wccs-demo-payment-card"
												type="radio"
												name="wccs_demo_payment"
												value="card"
												defaultChecked
											/>
											<Icon name="card" />
											{ __(
												'Cartão de crédito',
												'wc-checkoutsuite'
											) }
											<span className="payment-badge">
												{ __(
													'Gateway',
													'wc-checkoutsuite'
												) }
											</span>
										</label>
										<div className="pay-body">
											<Icon name="lock" />
											<strong>
												{ __(
													'Componente seguro do gateway',
													'wc-checkoutsuite'
												) }
											</strong>
											<small>
												{ __(
													'Espaço reservado. Nenhum dado de cartão é solicitado.',
													'wc-checkoutsuite'
												) }
											</small>
										</div>
									</div>
									<div className="pay-item">
										<label htmlFor="wccs-demo-payment-pix">
											<input
												id="wccs-demo-payment-pix"
												type="radio"
												name="wccs_demo_payment"
												value="pix"
											/>
											<Icon name="diamond" />
											{ __( 'Pix', 'wc-checkoutsuite' ) }
										</label>
										<div className="pay-body">
											{ __(
												'QR Code e confirmação pertencem ao gateway no produto real.',
												'wc-checkoutsuite'
											) }
										</div>
									</div>
									<div className="pay-item">
										<label htmlFor="wccs-demo-payment-boleto">
											<input
												id="wccs-demo-payment-boleto"
												type="radio"
												name="wccs_demo_payment"
												value="boleto"
											/>
											<Icon name="barcode" />
											{ __(
												'Boleto',
												'wc-checkoutsuite'
											) }
										</label>
										<div className="pay-body">
											{ __(
												'Emissão e acompanhamento pertencem ao gateway no produto real.',
												'wc-checkoutsuite'
											) }
										</div>
									</div>
								</fieldset>
							</div>

							{ findings ? (
								<div
									className={
										'preview-validation-summary' +
										( findings.missing.length > 0
											? ''
											: ' success' )
									}
									role="status"
								>
									{ findings.missing.length > 0
										? sprintf(
												/* translators: %s: comma-separated field labels. */
												__(
													'Campos obrigatórios sem valor: %s',
													'wc-checkoutsuite'
												),
												findings.missing.join( ', ' )
										  )
										: __(
												'Os campos obrigatórios da prévia estão preenchidos. A validação é local e não verifica CPF, CNPJ ou qualquer documento.',
												'wc-checkoutsuite'
										  ) }
								</div>
							) : null }

							<button
								className="btn btn-primary store-submit"
								type="submit"
							>
								{ __(
									'Validar campos da prévia',
									'wc-checkoutsuite'
								) }
								<Icon name="arrow" />
							</button>
							<p className="store-legal">
								{ __(
									'Validação básica local. Sem verificação matemática de CPF/CNPJ, PHP ou upload remoto. Não use informações pessoais.',
									'wc-checkoutsuite'
								) }
							</p>
						</form>

						<aside className="store-summary">
							<div className="summary-inner">
								<div className="summary-heading">
									<h2>
										{ __(
											'Seu pedido',
											'wc-checkoutsuite'
										) }
									</h2>
									<span className="badge">
										{ __( '1 item', 'wc-checkoutsuite' ) }
									</span>
								</div>
								<div className="product-row">
									<div
										className="product-art"
										aria-hidden="true"
									>
										<svg
											width="45"
											height="52"
											viewBox="0 0 45 52"
											fill="none"
										>
											<rect
												x="10"
												y="8"
												width="26"
												height="36"
												rx="5"
												fill="#7C3AED"
											/>
											<path
												d="M13 13h19v25H13z"
												fill="#A78BFA"
											/>
											<path
												d="M16 6h14v5H16z"
												fill="#4C1D95"
											/>
											<path
												d="m19 24 4 4 7-8"
												stroke="white"
												strokeWidth="2.4"
												strokeLinecap="round"
											/>
										</svg>
									</div>
									<div>
										<strong>
											{ __(
												'Item de demonstração',
												'wc-checkoutsuite'
											) }
										</strong>
										<small>
											{ __(
												'Edição essencial · 1 unidade',
												'wc-checkoutsuite'
											) }
										</small>
									</div>
									<b>R$ 249,90</b>
								</div>
								<div className="summary-lines">
									<div>
										<span>
											{ __(
												'Subtotal',
												'wc-checkoutsuite'
											) }
										</span>
										<strong>R$ 249,90</strong>
									</div>
									<div>
										<span>
											{ __(
												'Entrega ilustrativa',
												'wc-checkoutsuite'
											) }
										</span>
										<strong>R$ 19,90</strong>
									</div>
									<div className="summary-total">
										<span>
											{ __(
												'Total',
												'wc-checkoutsuite'
											) }
										</span>
										<strong>
											<small>BRL</small>R$ 269,80
										</strong>
									</div>
								</div>
								<div className="summary-trust">
									<Icon name="shield" />
									<div>
										<strong>
											{ __(
												'Apenas uma prévia visual',
												'wc-checkoutsuite'
											) }
										</strong>
										<p>
											{ __(
												'Produtos, frete e total são fictícios. Nenhum pedido será criado.',
												'wc-checkoutsuite'
											) }
										</p>
									</div>
								</div>
								<div className="summary-note">
									<Icon name="layers" />
									{ __(
										'Resumo na página, não Checkout Sidebar.',
										'wc-checkoutsuite'
									) }
								</div>
							</div>
						</aside>
					</div>
				</div>
			</section>
		</>
	);
}

/**
 * One field, drawn the way the design draws a public field.
 *
 * @param {Object} props       Component properties.
 * @param {any}    props.field Field definition.
 * @return {*} Rendered element tree.
 */
function PreviewField( { field } ) {
	const control = controlFor( field );
	const columns = Number( field?.layout?.desktop ?? 12 );
	const label = field.label || field.id;
	const help = field.description ? (
		<p className="field-help">{ field.description }</p>
	) : null;
	const name = field.id;
	const inputId = `wccs-preview-${ name }`;

	/**
	 * The input a control kind gets, drawn with the design's own class.
	 *
	 * @return {*} Input element.
	 */
	const input = () => {
		if ( 'textarea' === control ) {
			return (
				<textarea
					className="suite-input"
					id={ inputId }
					name={ name }
				/>
			);
		}

		if ( 'select' === control ) {
			return (
				<select className="suite-input" id={ inputId } name={ name }>
					<option value="">
						{ __( 'Escolha uma opção', 'wc-checkoutsuite' ) }
					</option>
					{ optionsFor( field ).map(
						( /** @type {any} */ option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label ?? option.value }
							</option>
						)
					) }
				</select>
			);
		}

		if ( 'radio' === control ) {
			return (
				<div className="choices">
					{ optionsFor( field ).map(
						( /** @type {any} */ option ) => (
							<label
								className="choice-label"
								key={ option.value }
								htmlFor={ `${ inputId }-${ option.value }` }
							>
								<input
									id={ `${ inputId }-${ option.value }` }
									type="radio"
									name={ name }
									value={ option.value }
								/>
								{ option.label ?? option.value }
							</label>
						)
					) }
				</div>
			);
		}

		if ( 'checkbox' === control ) {
			return (
				<label className="single-check" htmlFor={ inputId }>
					<input
						id={ inputId }
						type="checkbox"
						name={ name }
						value="1"
					/>
					{ field.description ||
						__( 'Marque para confirmar.', 'wc-checkoutsuite' ) }
				</label>
			);
		}

		if ( 'file' === control ) {
			return (
				<div className="upload-box">
					<Icon name="upload" />
					<label htmlFor={ inputId }>
						{ __( 'Escolher arquivo', 'wc-checkoutsuite' ) }
					</label>
					<p>
						{ __(
							'Nenhum upload é feito nesta prévia.',
							'wc-checkoutsuite'
						) }
					</p>
					<input id={ inputId } type="file" name={ name } disabled />
				</div>
			);
		}

		/** @type {Record<string,string>} */
		const types = {
			date: 'date',
			time: 'time',
			datetime: 'datetime-local',
			email: 'email',
			tel: 'tel',
			url: 'url',
			number: 'number',
		};

		return (
			<input
				className="suite-input"
				id={ inputId }
				type={ types[ control ] ?? 'text' }
				name={ name }
			/>
		);
	};

	if ( 'checkbox' === control ) {
		return (
			<div
				className="public-field"
				style={ { gridColumn: `span ${ columns }` } }
			>
				{ input() }
				{ help }
			</div>
		);
	}

	return (
		<div
			className="public-field"
			style={ { gridColumn: `span ${ columns }` } }
		>
			<label htmlFor={ inputId }>
				{ label }
				{ field.required ? null : (
					<span className="optional">
						{ __( 'opcional', 'wc-checkoutsuite' ) }
					</span>
				) }
			</label>
			{ input() }
			{ help }
		</div>
	);
}
