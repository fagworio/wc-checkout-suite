/**
 * Owns the transient state of the section editor dialogs.
 *
 * The schema operations remain in fieldOperations and the screen still decides
 * when to apply them. This boundary only keeps section drafts, selection and the
 * currently inspected section together.
 */

import { useMemo, useState } from '@wordpress/element';

/**
 * @param {{composition: any}} props Current composed document.
 * @return {any} Section editor state and actions.
 */
export default function useSectionEditor( { composition } ) {
	const [ sectionDraftOpen, setSectionDraftOpen ] = useState( false );
	const [ editingSectionId, setEditingSectionId ] = useState( null );
	const [ sectionRemoval, setSectionRemoval ] = useState( null );
	const [ newSectionTitle, setNewSectionTitle ] = useState( '' );
	const [ newSectionLocation, setNewSectionLocation ] = useState( 'billing' );
	const [ newSectionIcon, setNewSectionIcon ] = useState( 'user' );
	const [ newSectionMode, setNewSectionMode ] = useState( 'edit' );
	const [ newSectionShowTitle, setNewSectionShowTitle ] = useState( false );

	const editingSection = useMemo(
		() =>
			composition?.sections?.find(
				( /** @type {{id: string}} */ entry ) =>
					entry.id === editingSectionId
			) ?? null,
		[ composition, editingSectionId ]
	);

	const openNewSection = (
		/** @type {{location: string, showTitle: boolean}} */ {
			location,
			showTitle,
		}
	) => {
		setNewSectionTitle( '' );
		setNewSectionLocation( location );
		setNewSectionIcon( 'user' );
		setNewSectionMode( 'edit' );
		setNewSectionShowTitle( showTitle );
		setSectionDraftOpen( true );
	};

	const closeNewSection = () => {
		setNewSectionTitle( '' );
		setSectionDraftOpen( false );
	};

	return {
		sectionDraftOpen,
		setSectionDraftOpen,
		editingSectionId,
		setEditingSectionId,
		editingSection,
		sectionRemoval,
		setSectionRemoval,
		newSectionTitle,
		setNewSectionTitle,
		newSectionLocation,
		setNewSectionLocation,
		newSectionIcon,
		setNewSectionIcon,
		newSectionMode,
		setNewSectionMode,
		newSectionShowTitle,
		setNewSectionShowTitle,
		openNewSection,
		closeNewSection,
	};
}
