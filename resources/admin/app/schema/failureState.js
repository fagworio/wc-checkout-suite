/**
 * Turning a failure into something a person can act on.
 *
 * ROADMAP.md section 428 lists the states the editor must handle, and they are not
 * variations of one another: "vazio; carregando; salvo; alterado; erro inline;
 * conflito 409; falha de rede; permissão insuficiente; recurso incompatível;
 * extensão ausente; versão não suportada; restauração de rascunho". Each one asks
 * the merchant for something different, or for nothing at all.
 *
 * The list is centralised here for one reason: the client already classifies
 * failures — ApiError knows whether an answer was forbidden, a conflict or a
 * validation problem — and the screen used to collapse all of it into
 * `error.message`. Four states with four different remedies looked identical.
 *
 * Two rules are encoded rather than left to each caller:
 *
 * 1. **A failure never discards work.** Every state below keeps what is on screen
 *    and offers a way forward. There is no state whose advice is to start again.
 * 2. **A conflict is not an error.** Someone else saved first; nothing is broken,
 *    and the useful information is which revision won.
 *
 * @see ROADMAP.md section 428
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * @typedef {Object} FailureState
 * @property {string}                   kind       Machine kind, stable for tests and for callers.
 * @property {'error'|'warning'|'info'} status     Notice tone.
 * @property {string}                   title      Short heading.
 * @property {string}                   message    What happened, in the merchant's terms.
 * @property {string}                   recovery   What can be done about it.
 * @property {boolean}                  keepsWork  Whether what is on screen survives. Always true.
 * @property {number}                   [revision] Winning revision, on a conflict.
 * @property {*[]}                      [fields]   Per-field errors, on a validation failure.
 */

/**
 * Classifies a caught failure.
 *
 * Accepts anything: the client's ApiError, a bare Error, or a value thrown by
 * something else entirely. An unrecognised failure becomes a generic error rather
 * than an exception in the classifier, because a screen that fails while
 * reporting a failure is the worst possible outcome.
 *
 * ApiError exposes its classification as getters, not methods, so they are read
 * as properties. Calling them would throw a TypeError at exactly the moment a
 * save failed, which is the worst possible time to throw.
 *
 * @param {*} error Thrown value.
 * @return {FailureState} Classified state.
 */
export function classifyFailure( error ) {
	const failure = /** @type {any} */ ( error );

	if ( true === failure?.isForbidden ) {
		return {
			kind: 'forbidden',
			status: 'error',
			title: __( 'You are not allowed to do this', 'wc-checkoutsuite' ),
			message: __(
				'Your account does not have permission to manage the checkout, or the session has expired.',
				'wc-checkoutsuite'
			),
			recovery: __(
				'Reload the page. If it happens again, ask an administrator for the permission.',
				'wc-checkoutsuite'
			),
			keepsWork: true,
		};
	}

	if ( true === failure?.isConflict ) {
		const revision = failure?.currentRevision;

		return {
			kind: 'conflict',
			status: 'warning',
			title: __( 'Someone else saved first', 'wc-checkoutsuite' ),
			message:
				'number' === typeof revision
					? sprintf(
							/* translators: %d: revision number. */
							__(
								'This schema moved to revision %d while you were editing. Your changes were not saved and were not lost.',
								'wc-checkoutsuite'
							),
							revision
					  )
					: __(
							'This schema changed while you were editing. Your changes were not saved and were not lost.',
							'wc-checkoutsuite'
					  ),
			recovery: __(
				'Reload to see the version that won, or save again to overwrite it with yours.',
				'wc-checkoutsuite'
			),
			keepsWork: true,
			revision: 'number' === typeof revision ? revision : undefined,
		};
	}

	if ( true === failure?.isValidationFailure ) {
		return {
			kind: 'validation',
			status: 'error',
			title: __( 'Some fields are not valid yet', 'wc-checkoutsuite' ),
			message: __(
				'The draft was not saved because of the problems listed below. Nothing was changed.',
				'wc-checkoutsuite'
			),
			recovery: __(
				'Fix the problems and save again.',
				'wc-checkoutsuite'
			),
			keepsWork: true,
			fields: failure?.fieldErrors ?? [],
		};
	}

	if ( true === failure?.isTransient ) {
		return {
			kind: 'network',
			status: 'error',
			title: __( 'The site could not be reached', 'wc-checkoutsuite' ),
			message: __(
				'The request did not get an answer. Your changes are still here and nothing was saved.',
				'wc-checkoutsuite'
			),
			recovery: __(
				'Check the connection and save again.',
				'wc-checkoutsuite'
			),
			keepsWork: true,
		};
	}

	if ( 'StaleResponseError' === failure?.name ) {
		return {
			kind: 'stale',
			status: 'warning',
			title: __( 'A newer answer already arrived', 'wc-checkoutsuite' ),
			message: __(
				'This answer was for an older request and was ignored, so what you see is the newer state.',
				'wc-checkoutsuite'
			),
			recovery: __(
				'Carry on; nothing needs doing.',
				'wc-checkoutsuite'
			),
			keepsWork: true,
		};
	}

	return {
		kind: 'error',
		status: 'error',
		title: __( 'Something went wrong', 'wc-checkoutsuite' ),
		message:
			failure?.message ||
			__(
				'The request failed for an unknown reason.',
				'wc-checkoutsuite'
			),
		recovery: __(
			'Try again. If it keeps failing, the diagnostics section reports what this site supports.',
			'wc-checkoutsuite'
		),
		keepsWork: true,
	};
}

/**
 * Describes a field whose type is not registered any more.
 *
 * This is the "extensão ausente" state: a plugin that provided a field type was
 * deactivated, and the schema still refers to it. The field is not broken and its
 * stored value is untouched; what is gone is the code that knew how to render it.
 * Saying that plainly is different from showing an unknown type name.
 *
 * @param {import('./types').FieldDefinition}   field   Field definition.
 * @param {import('./types').FieldCatalog|null} catalog Catalogue, when loaded.
 * @return {?{field: string, label: string, type: string}} Entry, or null.
 */
export function missingExtension( field, catalog ) {
	if ( ! field || ! catalog?.types ) {
		return null;
	}

	if ( catalog.types[ field.type ] ) {
		return null;
	}

	return {
		field: field.id,
		label: field.label,
		type: field.type,
	};
}

/**
 * Describes a stored document this build cannot read.
 *
 * A document written by a newer build is not an empty schema, and showing "no
 * fields yet" for one is a lie: the fields exist and are invisible. The status
 * comes from the server, which is the only side that can tell the two apart.
 *
 * @param {any}    status Storage status from the report.
 * @param {string} slot   `draft` or `published`.
 * @return {?{slot: string, storedVersion: number}} Entry, or null.
 */
export function unsupportedVersion( status, slot ) {
	const entry = status?.[ slot ];

	if ( ! entry || 'unsupported_version' !== entry.state ) {
		return null;
	}

	return {
		slot,
		storedVersion: Number( entry.stored_version ) || 0,
	};
}
