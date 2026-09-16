import { __ } from '@wordpress/i18n';

import {
	CheckboxField,
	SelectField,
	TextareaField,
	TextField,
} from './controls';

/**
 * @param {Object}                                               props                Props.
 * @param {Partial<import('../schema/types').SectionDefinition>} [props.value]        Container.
 * @param {(changes: Object) => void}                            props.onChange       Change callback.
 * @param {Array<{value: string, label: string}>}                [props.destinations] Destinations.
 * @param {boolean}                                              [props.disabled]     Disable controls.
 * @return {*} Properties form.
 */
export default function ContainerProperties( {
	value = {},
	onChange,
	destinations = [],
	disabled = false,
} ) {
	/** @param {Object} changes Changed values. */
	const update = ( changes ) => onChange( { ...value, ...changes } );
	const name = value.name ?? value.title ?? '';

	return (
		<div className="wccs-container-properties">
			<TextField
				id="wccs-container-name"
				label={ __( 'Nome do container', 'wc-checkoutsuite' ) }
				value={ name }
				disabled={ disabled }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) =>
					update( {
						name: event.target.value,
						title: event.target.value,
					} )
				}
			/>
			<TextField
				id="wccs-container-display-title"
				label={ __( 'Título exibido', 'wc-checkoutsuite' ) }
				value={ value.display_title ?? name }
				disabled={ disabled }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) => update( { display_title: event.target.value } ) }
			/>
			<TextareaField
				id="wccs-container-description"
				label={ __( 'Descrição', 'wc-checkoutsuite' ) }
				value={ value.description ?? '' }
				disabled={ disabled }
				onChange={ (
					/** @type {{ target: { value: string } }} */ event
				) => update( { description: event.target.value } ) }
			/>
			{ destinations.length ? (
				<SelectField
					id="wccs-container-destination"
					label={ __( 'Destino', 'wc-checkoutsuite' ) }
					value={ value.destination ?? value.areas?.[ 0 ] ?? '' }
					options={ destinations }
					disabled={ disabled }
					onChange={ (
						/** @type {{ target: { value: string } }} */ event
					) =>
						update( {
							destination: event.target.value,
							areas: [ event.target.value ],
						} )
					}
				/>
			) : null }
			<CheckboxField
				id="wccs-container-enabled"
				label={ __( 'Container ativo', 'wc-checkoutsuite' ) }
				checked={ value.enabled !== false }
				disabled={ disabled }
				onChange={ (
					/** @type {{ target: { checked: boolean } }} */ event
				) => update( { enabled: event.target.checked } ) }
			/>
			<CheckboxField
				id="wccs-container-show-title"
				label={ __( 'Exibir título no conteúdo', 'wc-checkoutsuite' ) }
				checked={
					value.show_title ?? value.presentation?.show_title !== false
				}
				disabled={ disabled }
				onChange={ (
					/** @type {{ target: { checked: boolean } }} */ event
				) =>
					update( {
						show_title: event.target.checked,
						presentation: {
							...( value.presentation ?? {} ),
							show_title: event.target.checked,
						},
					} )
				}
			/>
		</div>
	);
}
