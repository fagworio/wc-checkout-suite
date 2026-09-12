/**
 * Publication history.
 *
 * Lists the revisions the store has published and offers to go back to one, in the
 * rows the design draws: an icon, the revision number, the moment it was published
 * and the button that puts it back in the draft.
 *
 * Restoring does not overwrite a revision: the repository publishes the old
 * document again as a **new** revision. That is deliberate and the panel says so,
 * because a history you can rewrite is not a history — and because the merchant
 * needs to be able to undo the restore as easily as they made it.
 *
 * One thing the prototype's row shows is missing here, and it is missing because
 * the server does not keep it: its rows list how many fields the revision had and
 * which checkout it was written for. The stored revision is a number, a moment, an
 * author and a hash — so the row states what exists instead of inventing the rest.
 *
 * ROADMAP.md section 428 keeps the two mechanisms apart: "Undo local de edição e
 * histórico de publicação são mecanismos separados". This is the second one. The
 * first belongs to WCCS-020, and nothing here pretends to be it.
 *
 * @see ROADMAP.md sections 19 and 428
 */

import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import Notice from './Notice';
import { Icon } from '../design/icons';

/**
 * Formats a stored timestamp for display.
 *
 * Returns the raw value when the browser cannot parse it, so an unexpected format
 * shows something rather than "Invalid Date".
 *
 * @param {string} value ISO timestamp.
 * @return {string} Display text.
 */
function formatWhen( value ) {
	if ( ! value ) {
		return __( 'data desconhecida', 'wc-checkoutsuite' );
	}

	const parsed = new Date( value );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return value;
	}

	return parsed.toLocaleString( 'pt-BR', {
		dateStyle: 'short',
		timeStyle: 'short',
	} );
}

/**
 * Publication history.
 *
 * @param {Object}                  props                 Component properties.
 * @param {any[]}                   props.revisions       Revisions, newest first.
 * @param {number}                  props.currentRevision Revision currently published.
 * @param {(...args: any[]) => any} props.onRestore       Called with the revision to restore.
 * @param {boolean}                 [props.restoring]     Whether a restore is in progress.
 * @param {string}                  [props.error]         Failure from the last attempt.
 * @param {string}                  [props.restored]      Confirmation of the last restore.
 * @return {*} Rendered element tree.
 */
export default function RevisionsList( {
	revisions,
	currentRevision = 0,
	onRestore,
	restoring = false,
	error = '',
	restored = '',
} ) {
	const entries = Array.isArray( revisions ) ? revisions : [];

	return (
		<section
			className="wccs-revisions"
			aria-labelledby="wccs-revisions-title"
		>
			<h3 className="sr-only" id="wccs-revisions-title">
				{ __( 'Histórico de publicação', 'wc-checkoutsuite' ) }
			</h3>

			{ error ? <Notice status="error">{ error }</Notice> : null }
			{ restored ? <Notice status="success">{ restored }</Notice> : null }

			{ 0 === entries.length ? (
				<Notice status="info">
					{ __(
						'Nada foi publicado ainda. A loja corre sem um checkout configurado.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : (
				<div className="wccs-revisions__list">
					{ entries.map( ( entry ) => {
						const isCurrent =
							Number( entry.revision ) ===
							Number( currentRevision );

						return (
							<div className="history-row" key={ entry.revision }>
								<Icon name="history" />
								<div>
									<strong>
										{ sprintf(
											/* translators: %d: revision number. */
											__(
												'Revisão %d',
												'wc-checkoutsuite'
											),
											entry.revision
										) }
										{ isCurrent ? (
											<span className="badge green">
												{ __(
													'Na loja',
													'wc-checkoutsuite'
												) }
											</span>
										) : null }
									</strong>
									<p>{ formatWhen( entry.published_at ) }</p>
								</div>
								{ isCurrent ? null : (
									<Button
										size="small"
										busy={ restoring }
										disabled={ restoring }
										onClick={ () =>
											onRestore(
												Number( entry.revision )
											)
										}
									>
										{ __(
											'Usar como rascunho',
											'wc-checkoutsuite'
										) }
									</Button>
								) }
							</div>
						);
					} ) }
				</div>
			) }

			<p className="form-help">
				{ __(
					'Restaurar cria um rascunho e não altera a versão publicada. Desfazer edição e restaurar revisão são ações distintas.',
					'wc-checkoutsuite'
				) }
			</p>
		</section>
	);
}
