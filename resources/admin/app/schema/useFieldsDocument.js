/**
 * Owns the server-backed schema document and its browser editing lifecycle.
 *
 * This hook deliberately does not know about sections, fields or the view model.
 * It owns loading, persistence, drafts, publication state and revision history;
 * schema operations remain in the schema modules and are applied by the screen.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import useUnsavedChanges from '../api/useUnsavedChanges';
import useDocumentHistory from './useDocumentHistory';
import { ambiguousDestinations, repairLegacyDraft } from './fieldOperations';
import { classifyFailure } from './failureState';

/** Local draft handoff used when the shell swaps the editor for another screen. */
export const LOCAL_DRAFT_KEY = 'wccs-local-draft';

/**
 * Creates a deterministic fingerprint for a server-confirmed document.
 *
 * @param {*} value Value to fingerprint.
 * @return {string} Deterministic JSON representation.
 */
export function documentFingerprint( value ) {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( documentFingerprint ).join( ',' ) }]`;
	}

	if ( value && 'object' === typeof value ) {
		return `{${ Object.keys( value )
			.sort()
			.map(
				( key ) =>
					`${ JSON.stringify( key ) }:${ documentFingerprint(
						value[ key ]
					) }}`
			)
			.join( ',' ) }}`;
	}

	return JSON.stringify( value );
}

/**
 * Manages the draft document, server confirmation and publication lifecycle.
 *
 * @param {{client: any}} props Hook properties.
 * @return {any} Document lifecycle state and actions.
 */
export default function useFieldsDocument( { client } ) {
	const edits = useDocumentHistory( null );
	const document = edits.document;
	const resetDocument = edits.reset;
	const commitDocument = edits.commit;
	const [ loading, setLoading ] = useState( true );
	const [ catalog, setCatalog ] = useState( null );
	const [ coreFields, setCoreFields ] = useState( null );
	const [ failure, setFailure ] = useState( /** @type {any} */ ( null ) );
	const [ problems, setProblems ] = useState(
		/** @type {Array<{fieldId?: string, message: string}>} */ ( [] )
	);
	const clearProblems = useCallback( () => setProblems( [] ), [] );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( '' );
	const [ localDraftRestored, setLocalDraftRestored ] = useState( false );
	const [ savedDocument, setSavedDocument ] = useState(
		/** @type {any} */ ( null )
	);
	const [ report, setReport ] = useState( null );
	const [ revisions, setRevisions ] = useState( [] );
	const [ restoring, setRestoring ] = useState( false );
	const [ publishError, setPublishError ] = useState( '' );
	const [ restored, setRestored ] = useState( '' );
	const [ legacyDestinations, setLegacyDestinations ] = useState(
		/** @type {string[]} */ ( [] )
	);
	const [ legacyPromptOpen, setLegacyPromptOpen ] = useState( false );
	const mounted = useRef( true );

	const dirty = null !== document && document !== savedDocument;

	useUnsavedChanges(
		dirty,
		__( 'You have unsaved checkout field changes.', 'wc-checkoutsuite' )
	);

	const refreshPublication = useCallback( async () => {
		try {
			const [ publication, history ] = await Promise.all( [
				client.diff(),
				client.revisions(),
			] );

			if ( ! mounted.current ) {
				return;
			}

			setReport( publication );
			setRevisions( history?.revisions ?? [] );
		} catch ( caught ) {
			if ( mounted.current ) {
				setPublishError( classifyFailure( caught ).message );
			}
		}
	}, [ client ] );

	const load = useCallback( async () => {
		setLoading( true );
		setFailure( null );
		setProblems( [] );

		try {
			const [ draft, types, core, publication, history ] =
				await Promise.all( [
					client.getDraft(),
					client.fieldTypes(),
					client.coreFields(),
					client.diff(),
					client.revisions(),
				] );

			if ( ! mounted.current ) {
				return;
			}

			let local = null;
			try {
				const handoffEnabled =
					window.location.search.includes( 'wccs-checkoutsuite' ) ||
					window.document.body?.classList.contains( 'wccs-admin' );
				const stored = handoffEnabled
					? window.sessionStorage?.getItem( LOCAL_DRAFT_KEY )
					: null;
				local = stored ? JSON.parse( stored ) : null;
			} catch {
				local = null;
			}

			const localMatchesServer =
				local?.baseRevision === draft.revision &&
				Boolean( local.document ) &&
				'string' === typeof local.baseDocumentSignature &&
				local.baseDocumentSignature === documentFingerprint( draft );

			if ( local && ! localMatchesServer ) {
				window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
			}

			const candidate = localMatchesServer ? local.document : draft;
			const hasLocalDraft = localMatchesServer;
			const repaired = repairLegacyDraft( candidate );
			const applied = repaired.changed ? repaired.document : candidate;
			const legacy = ambiguousDestinations( applied );

			setLegacyDestinations( legacy );
			setLegacyPromptOpen( legacy.length > 0 );

			if ( repaired.changed ) {
				setLocalDraftRestored( hasLocalDraft );
				resetDocument( applied );
				setSaved(
					__(
						'Encontramos dados antigos incompletos nesta edição local e os corrigimos. Revise e salve para continuar.',
						'wc-checkoutsuite'
					)
				);
			} else if (
				local?.baseRevision === draft.revision &&
				local.document
			) {
				setLocalDraftRestored( true );
				resetDocument( applied );
				setSaved(
					__(
						'Há uma edição local não salva preservada nesta sessão.',
						'wc-checkoutsuite'
					)
				);
			} else {
				setLocalDraftRestored( false );
				window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
				resetDocument( applied );
			}

			setSavedDocument( draft );
			setCatalog( types );
			setCoreFields( core );
			setReport( publication );
			setRevisions( history?.revisions ?? [] );

			return { catalog: types, coreFields: core };
		} catch ( caught ) {
			if ( mounted.current ) {
				setFailure( classifyFailure( caught ) );
			}
			return null;
		} finally {
			if ( mounted.current ) {
				setLoading( false );
			}
		}
	}, [ client, resetDocument ] );

	const discardLocalDraft = useCallback( () => {
		if (
			! savedDocument ||
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Descartar a edição local e remover as alterações não salvas desta sessão?',
					'wc-checkoutsuite'
				)
			)
		) {
			return;
		}

		window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );
		resetDocument( savedDocument );
		setSaved( '' );
		setLocalDraftRestored( false );
		setPublishError( '' );
	}, [ savedDocument, resetDocument ] );

	useEffect( () => {
		if (
			! document ||
			! savedDocument ||
			document === savedDocument ||
			( ! window.location.search.includes( 'wccs-checkoutsuite' ) &&
				! window.document.body?.classList.contains( 'wccs-admin' ) )
		) {
			return;
		}

		try {
			window.sessionStorage?.setItem(
				LOCAL_DRAFT_KEY,
				JSON.stringify( {
					baseRevision: savedDocument.revision,
					baseDocumentSignature: documentFingerprint( savedDocument ),
					document,
				} )
			);
		} catch {
			// Storage is an enhancement; editing remains available if it is blocked.
		}
	}, [ document, savedDocument ] );

	useEffect( () => {
		mounted.current = true;
		load();

		return () => {
			mounted.current = false;
		};
	}, [ load ] );

	const save = useCallback( async () => {
		if ( ! document ) {
			return;
		}

		setSaving( true );
		setFailure( null );
		setProblems( [] );
		setSaved( '' );
		setPublishError( '' );

		try {
			const result = await client.saveDraft(
				document,
				document.revision
			);
			const stored = result?.fields ? result : await client.getDraft();
			resetDocument( stored );
			setSavedDocument( stored );
			window.sessionStorage?.removeItem( LOCAL_DRAFT_KEY );

			try {
				await client.publish( stored.revision );
			} catch ( publication ) {
				setPublishError( classifyFailure( publication ).message );
				setSaved(
					__(
						'Alterações guardadas, mas a loja ainda corre a revisão anterior. Tente guardar de novo para publicar.',
						'wc-checkoutsuite'
					)
				);
				await refreshPublication();
				return;
			}

			setSaved(
				__( 'Alterações salvas com sucesso.', 'wc-checkoutsuite' )
			);
			await refreshPublication();
		} catch ( caught ) {
			const state = classifyFailure( caught );
			setFailure( state );
			if ( 'validation' === state.kind ) {
				setProblems( state.fields ?? [] );
			}
		} finally {
			if ( mounted.current ) {
				setSaving( false );
			}
		}
	}, [ client, document, refreshPublication, resetDocument ] );

	const restore = useCallback(
		async ( /** @type {number} */ revision ) => {
			setRestoring( true );
			setPublishError( '' );
			setRestored( '' );

			try {
				await client.restore( revision );
				if ( ! mounted.current ) {
					return;
				}

				setRestored(
					sprintf(
						/* translators: %d: revision number. */
						__(
							'Revision %d was published again as a new revision.',
							'wc-checkoutsuite'
						),
						revision
					)
				);
				await refreshPublication();
			} catch ( caught ) {
				if ( mounted.current ) {
					setPublishError( classifyFailure( caught ).message );
				}
			} finally {
				if ( mounted.current ) {
					setRestoring( false );
				}
			}
		},
		[ client, refreshPublication ]
	);

	return {
		document,
		edits,
		catalog,
		coreFields,
		commitDocument,
		resetDocument,
		loading,
		failure,
		problems,
		clearProblems,
		saving,
		saved,
		setSaved,
		dirty,
		localDraftRestored,
		savedDocument,
		report,
		revisions,
		restoring,
		restored,
		publishError,
		legacyDestinations,
		legacyPromptOpen,
		setLegacyDestinations,
		setLegacyPromptOpen,
		discardLocalDraft,
		load,
		save,
		restore,
		refreshPublication,
	};
}
