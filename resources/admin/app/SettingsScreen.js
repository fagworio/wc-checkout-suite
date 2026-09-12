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

	return (
		<div className="wccs-settings">
			{ editable ? (
				<CheckboxField
					id="wccs-custom-checkout"
					label={ __( 'Custom checkout', 'wc-checkoutsuite' ) }
					help={ __(
						'Presents the checkout this plugin designed. Turning it off restores the store’s own checkout and keeps every field you configured.',
						'wc-checkoutsuite'
					) }
					checked={ Boolean( state.custom_checkout ) }
					disabled={ saving }
					onChange={ ( /** @type {any} */ event ) =>
						save( event.target.checked )
					}
				/>
			) : null }

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
