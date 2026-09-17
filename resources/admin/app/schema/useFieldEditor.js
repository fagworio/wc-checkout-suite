/**
 * Owns field selection and the small field-editor actions.
 *
 * The hook delegates schema changes to the screen's existing apply boundary, so
 * undo, dirty state and refusal handling continue to use one document pipeline.
 */

import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	duplicateField,
	moveField,
	removeField,
	reorderField,
	setFieldEnabled,
} from './fieldOperations';

/**
 * @param {{document: any, apply: Function}} props Field editor inputs.
 * @return {any} Field selection, editing state and actions.
 */
export default function useFieldEditor( { document, apply } ) {
	const [ selected, setSelected ] = useState(
		/** @type {string[]} */ ( [] )
	);
	const [ editing, setEditing ] = useState(
		/** @type {string|null} */ ( null )
	);

	const editingField = useMemo(
		() =>
			document?.fields?.find(
				( /** @type {any} */ field ) => field.id === editing
			) ?? null,
		[ document, editing ]
	);

	const toggleSelected = useCallback( ( /** @type {string} */ id ) => {
		setSelected( ( current ) =>
			current.includes( id )
				? current.filter( ( entry ) => entry !== id )
				: [ ...current, id ]
		);
	}, [] );

	const duplicate = useCallback(
		( /** @type {string|null} */ id ) =>
			id &&
			apply(
				duplicateField( document, id ),
				__( 'Duplicar campo', 'wc-checkoutsuite' )
			),
		[ apply, document ]
	);

	const toggleEnabled = useCallback(
		( /** @type {string} */ id ) => {
			const field = document?.fields?.find(
				( /** @type {any} */ entry ) => entry.id === id
			);
			apply(
				setFieldEnabled( document, id, ! ( field?.enabled ?? true ) )
			);
		},
		[ apply, document ]
	);

	const move = useCallback(
		( /** @type {string} */ id, /** @type {'up'|'down'} */ direction ) =>
			apply(
				moveField( document, id, direction ),
				__( 'Reordenar campo', 'wc-checkoutsuite' )
			),
		[ apply, document ]
	);

	const reorder = useCallback(
		( /** @type {string} */ id, /** @type {string} */ targetId ) =>
			apply(
				reorderField( document, id, targetId ),
				__( 'Reordenar campo', 'wc-checkoutsuite' )
			),
		[ apply, document ]
	);

	const remove = useCallback(
		( /** @type {string|null} */ id ) =>
			id && apply( removeField( document, id ) ),
		[ apply, document ]
	);

	return {
		selected,
		setSelected,
		toggleSelected,
		editing,
		setEditing,
		editingField,
		duplicate,
		toggleEnabled,
		move,
		reorder,
		remove,
	};
}
