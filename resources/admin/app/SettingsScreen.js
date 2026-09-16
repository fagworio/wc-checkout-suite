/**
 * Settings and compatibility screen.
 *
 * One screen for two sections, because they answer one question between them: is the
 * custom checkout on, and what does the store's payment area look like to it. The
 * settings section gets the switch; the diagnostics section gets the same state without
 * it. Splitting them would mean two screens reading the same route and disagreeing the
 * first time one of them was updated.
 *
 * Two things this screen deliberately cannot do. It cannot write anything except the
 * one boolean: the fields, the document and its revisions are not reachable from here,
 * which is what "turning it off preserves the editor" means as a property of the code.
 * And it cannot make a gateway homologated — the compatibility half is a read, because
 * a screen that could write a homologation would be a screen where somebody can promise
 * a gateway into compatibility.
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Button, CheckboxField, Notice } from './components';

/**
 * Settings and compatibility screen.
 *
 * @param {Object}  props            Component properties.
 * @param {any}     props.client     REST client.
 * @param {boolean} [props.editable] Whether the switch is offered here.
 * @return {*} Rendered element tree.
 */
export default function SettingsScreen( { client, editable = true } ) {
	const [ state, setState ] = useState( /** @type {any} */ ( null ) );
	const [ failure, setFailure ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ probingUploads, setProbingUploads ] = useState( false );

	/**
	 * Reads the current state.
	 *
	 * @param {AbortSignal} [signal] Cancellation signal.
	 * @return {Promise<void>} Resolves when the state is in.
	 */
	const load = useCallback(
		async ( /** @type {AbortSignal|undefined} */ signal ) => {
			try {
				setState( await client.settings( signal ) );
				setFailure( null );
			} catch ( error ) {
				if ( ! signal?.aborted ) {
					setFailure( /** @type {any} */ ( error ) );
				}
			}
		},
		[ client ]
	);

	useEffect( () => {
		const controller = new AbortController();

		load( controller.signal );

		return () => controller.abort();
	}, [ load ] );

	/**
	 * Records the merchant's choice and shows what it produced.
	 *
	 * The answer is the state the server now holds, so the screen never guesses what its
	 * own write did — which matters here more than usual, because the mode can differ
	 * from the switch: a gateway the record marks unavailable makes the presentation
	 * step aside even with the switch on.
	 *
	 * @param {boolean} enabled Whether the custom checkout is wanted.
	 * @return {Promise<void>} Resolves when the write is answered.
	 */
	const save = useCallback(
		async ( /** @type {boolean} */ enabled ) => {
			setSaving( true );

			try {
				setState( await client.saveSettings( enabled ) );
				setFailure( null );
			} catch ( error ) {
				setFailure( /** @type {any} */ ( error ) );
			} finally {
				setSaving( false );
			}
		},
		[ client ]
	);

	/**
	 * Runs the explicit server-side privacy probe.
	 *
	 * This action is deliberately available only on Diagnostics. The response contains
	 * the complete state so the panel reflects what the server persisted, including a
	 * refusal, instead of assuming that a completed request means uploads are safe.
	 *
	 * @return {Promise<void>} Resolves when the probe settles.
	 */
	const probeUploads = useCallback( async () => {
		setProbingUploads( true );

		try {
			setState( await client.probeUploads() );
			setFailure( null );
		} catch ( error ) {
			setFailure( /** @type {any} */ ( error ) );
		} finally {
			setProbingUploads( false );
		}
	}, [ client ] );

	if ( failure && ! state ) {
		return (
			<Notice
				status="error"
				title={ __( 'Settings', 'wc-checkoutsuite' ) }
			>
				{ __(
					'The settings could not be read. Reload the page to try again.',
					'wc-checkoutsuite'
				) }
			</Notice>
		);
	}

	if ( ! state ) {
		return (
			<p className="wccs-settings__loading" role="status">
				{ __( 'Reading the checkout settings…', 'wc-checkoutsuite' ) }
			</p>
		);
	}

	const gateways = state.gateways ?? [];
	const undecided = state.undecided ?? 0;
	const uploads = state.uploads ?? {};
	let uploadStatus = 'warning';
	let uploadMessage = __(
		'O ambiente ainda não foi verificado. Os campos de upload permanecem bloqueados até a validação.',
		'wc-checkoutsuite'
	);

	if ( uploads.observed ) {
		uploadStatus = uploads.enabled ? 'success' : 'error';
		uploadMessage = uploads.enabled
			? __(
					'O armazenamento privado foi validado e os uploads podem ser oferecidos.',
					'wc-checkoutsuite'
			  )
			: uploads.reason;
	}

	return (
		<div className="wccs-settings">
			<h1 className="wccs-settings__title">
				{ __( 'Qual checkout a loja deve usar?', 'wc-checkoutsuite' ) }
			</h1>
			<p className="wccs-settings__intro">
				{ __(
					'Essa escolha controla somente a apresentação do checkout ao cliente. Os campos e configurações continuam preservados nos dois modos.',
					'wc-checkoutsuite'
				) }
			</p>
			{ editable ? (
				<CheckboxField
					id="wccs-custom-checkout"
					label={ __(
						'Usar checkout modificado pelo WCCS (Custom checkout)',
						'wc-checkoutsuite'
					) }
					help={ __(
						'Ligado: o cliente vê o checkout modificado pelo WCCS. Desligado: o cliente vê o checkout padrão do WooCommerce. Desligar não remove nem despublica seus campos.',
						'wc-checkoutsuite'
					) }
					checked={ Boolean( state.custom_checkout ) }
					disabled={ saving }
					onChange={ ( /** @type {any} */ event ) =>
						save( event.target.checked )
					}
				/>
			) : null }

			<div
				className="wccs-settings__modes"
				aria-label={ __( 'Modos de checkout', 'wc-checkoutsuite' ) }
			>
				<div
					className={ `wccs-settings__mode-card${
						! state.custom_checkout ? ' is-active' : ''
					}` }
				>
					<strong>
						{ __(
							'Checkout padrão do WooCommerce',
							'wc-checkoutsuite'
						) }
					</strong>
					<span>
						{ __(
							'A loja mantém a apresentação nativa do WooCommerce.',
							'wc-checkoutsuite'
						) }
					</span>
				</div>
				<div
					className={ `wccs-settings__mode-card${
						state.custom_checkout ? ' is-active' : ''
					}` }
				>
					<strong>
						{ __(
							'Checkout modificado pelo WCCS',
							'wc-checkoutsuite'
						) }
					</strong>
					<span>
						{ __(
							'A loja aplica a apresentação personalizada do plugin, quando compatível.',
							'wc-checkoutsuite'
						) }
					</span>
				</div>
			</div>

			<p className="wccs-settings__mode">
				{ sprintf(
					/* translators: %s: the checkout mode the store is in. */
					__( 'Checkout mode: %s', 'wc-checkoutsuite' ),
					state.mode
				) }
			</p>

			<p className="wccs-settings__reason">{ state.reason }</p>

			{ ( state.blocked_by ?? [] ).length > 0 ? (
				<Notice
					status="warning"
					title={ __( 'Compatibility', 'wc-checkoutsuite' ) }
				>
					{ sprintf(
						/* translators: %s: comma separated list of payment gateway identifiers. */
						__(
							'The custom checkout is stepping aside for: %s.',
							'wc-checkoutsuite'
						),
						state.blocked_by.join( ', ' )
					) }
				</Notice>
			) : null }

			<h2 className="wccs-settings__heading">
				{ __( 'Payment gateways', 'wc-checkoutsuite' ) }
			</h2>

			<p className="wccs-settings__summary">
				{ sprintf(
					/* translators: 1: homologated gateways, 2: total gateways, 3: gateways never tested. */
					__(
						'%1$d of %2$d gateways have a homologation record. %3$d have never been tested, so nothing is promised about them.',
						'wc-checkoutsuite'
					),
					state.homologated ?? 0,
					gateways.length,
					undecided
				) }
			</p>

			<table className="wccs-settings__gateways">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Gateway', 'wc-checkoutsuite' ) }
						</th>
						<th scope="col">
							{ __( 'Mode', 'wc-checkoutsuite' ) }
						</th>
						<th scope="col">
							{ __( 'Enabled', 'wc-checkoutsuite' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ gateways.map( ( /** @type {any} */ gateway ) => (
						<tr key={ gateway.id }>
							<td>
								{ gateway.title }
								<code className="wccs-settings__id">
									{ gateway.id }
								</code>
							</td>
							<td>{ gateway.mode }</td>
							<td>
								{ gateway.enabled
									? __( 'Yes', 'wc-checkoutsuite' )
									: __( 'No', 'wc-checkoutsuite' ) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>

			{ ! editable ? (
				<section
					className="wccs-settings__uploads"
					aria-labelledby="wccs-uploads-heading"
				>
					<h2
						id="wccs-uploads-heading"
						className="wccs-settings__heading"
					>
						{ __( 'Armazenamento de uploads', 'wc-checkoutsuite' ) }
					</h2>
					<p className="wccs-settings__summary">
						{ __(
							'O WCCS cria um arquivo de teste, verifica se ele não pode ser lido pela URL pública e o remove. A verificação é executada somente quando você solicita esta ação.',
							'wc-checkoutsuite'
						) }
					</p>
					<Notice
						status={ uploadStatus }
						title={ __(
							'Privacidade dos arquivos',
							'wc-checkoutsuite'
						) }
					>
						{ uploadMessage }
					</Notice>
					<div className="wccs-settings__uploads-actions">
						<Button
							variant="primary"
							busy={ probingUploads }
							disabled={ probingUploads }
							onClick={ probeUploads }
						>
							{ __(
								'Verificar ambiente de uploads',
								'wc-checkoutsuite'
							) }
						</Button>
						{ uploads.checked_at ? (
							<small>
								{ sprintf(
									/* translators: %s: date and time of the last upload probe. */
									__(
										'Última verificação: %s',
										'wc-checkoutsuite'
									),
									new Date(
										uploads.checked_at * 1000
									).toLocaleString()
								) }
							</small>
						) : null }
					</div>
				</section>
			) : null }

			{ /* The transactional half, from its own registry. It sits beside the presentation
			     column because a merchant reads the two together — "may this plugin decorate the
			     payment area" and "may it ask the gateway to capture" — and it is a different
			     record because a presentation decision is not permission to move money (§17). */ }
			<h2 className="wccs-settings__heading">
				{ __( 'What each gateway proved', 'wc-checkoutsuite' ) }
			</h2>

			<p className="wccs-settings__summary">
				{ __(
					'Uma ação só aparece onde existe prova: a execução, o modo, a versão e a data. Sem prova, a loja envia o link «Pagar pedido» do próprio pedido.',
					'wc-checkoutsuite'
				) }
			</p>

			<table className="wccs-settings__gateways">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Gateway', 'wc-checkoutsuite' ) }
						</th>
						<th scope="col">
							{ __(
								'Actions that may be offered',
								'wc-checkoutsuite'
							) }
						</th>
						<th scope="col">
							{ __( 'Evidence', 'wc-checkoutsuite' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ gateways.map( ( /** @type {any} */ gateway ) => {
						const proven = ( gateway.actions ?? [] ).filter(
							( /** @type {any} */ action ) =>
								action.offerable && ! action.fallback
						);

						return (
							<tr key={ `caps-${ gateway.id }` }>
								<td>
									{ gateway.title }
									<code className="wccs-settings__id">
										{ gateway.id }
									</code>
								</td>
								<td>
									{ ( gateway.offerable ?? [] ).length > 0
										? ( gateway.offerable ?? [] ).join(
												', '
										  )
										: __( 'nada', 'wc-checkoutsuite' ) }
								</td>
								<td>
									{ proven.length > 0
										? proven
												.map(
													(
														/** @type {any} */ action
													) =>
														sprintf(
															/* translators: 1: action, 2: evidence mode, 3: version, 4: date */
															__(
																'%1$s: %2$s em %3$s, %4$s',
																'wc-checkoutsuite'
															),
															action.label,
															action.evidence
																?.mode ?? '',
															action.evidence
																?.version ?? '',
															action.evidence
																?.proven_at ??
																''
														)
												)
												.join( '; ' )
										: __(
												'nenhuma ação comprovada; só o link «Pagar pedido»',
												'wc-checkoutsuite'
										  ) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ ( state.refused ?? [] ).length > 0 ? (
				<Notice
					status="warning"
					title={ __(
						'Claims that could not become capabilities',
						'wc-checkoutsuite'
					) }
				>
					<ul>
						{ ( state.refused ?? [] ).map(
							( /** @type {any} */ refusal ) => (
								<li
									key={ `${ refusal.gateway }:${ refusal.action }` }
								>
									{ refusal.reason }
								</li>
							)
						) }
					</ul>
				</Notice>
			) : null }

			{ failure ? (
				<Notice
					status="error"
					title={ __( 'Not saved', 'wc-checkoutsuite' ) }
				>
					{ __( 'The change was not saved.', 'wc-checkoutsuite' ) }
				</Notice>
			) : null }

			<Button onClick={ () => load( undefined ) } disabled={ saving }>
				{ __( 'Reload', 'wc-checkoutsuite' ) }
			</Button>
		</div>
	);
}
