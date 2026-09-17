/**
 * Owns the editor's navigation and destination context.
 *
 * URL changes stay replace-only: switching tabs or destinations should not fill
 * the browser history with intermediate editor states.
 */

import { useCallback, useState } from '@wordpress/element';

import { areaHref, sectionHref } from '../sectionUrl';

/**
 * @param {{areaIds: string[], checkoutMode?: string}} props Navigation inputs.
 * @return {any} Navigation state and actions.
 */
export default function useFieldsNavigation( { areaIds, checkoutMode = '' } ) {
	const [ mode, setMode ] = useState( checkoutMode || 'classic' );
	const [ area, setArea ] = useState( 'checkout' );
	const [ section, setSection ] = useState( 'order' );
	const [ activeProfile, setActiveProfile ] = useState( '' );

	const selectArea = useCallback(
		( /** @type {string} */ id ) => {
			setArea( id );

			if ( ! window.history?.replaceState ) {
				return;
			}

			window.history.replaceState(
				null,
				'',
				areaHref( window.location.href, id, areaIds )
			);
		},
		[ areaIds ]
	);

	const goTo = useCallback( ( /** @type {string} */ id ) => {
		const known = [
			'fields',
			'appearance',
			'sections',
			'rules',
			'checkout-page',
			'import-export',
			'diagnostics',
			'settings',
		];

		if ( window.history?.replaceState ) {
			window.history.replaceState(
				null,
				'',
				sectionHref( window.location.href, id, known )
			);
		}

		window.dispatchEvent(
			new CustomEvent( 'wccs:navigate', { detail: { section: id } } )
		);
	}, [] );

	return {
		mode,
		setMode,
		area,
		setArea,
		selectArea,
		section,
		setSection,
		activeProfile,
		setActiveProfile,
		goTo,
		onPreview: useCallback( () => goTo( 'appearance' ), [ goTo ] ),
		onOpenRules: useCallback( () => goTo( 'rules' ), [ goTo ] ),
	};
}
