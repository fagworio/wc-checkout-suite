/**
 * Publication history.
 *
 * Lists the revisions the store has published and offers to go back to one.
 *
 * Restoring does not overwrite a revision: the repository publishes the old
 * document again as a **new** revision. That is deliberate and the panel says so,
 * because a history you can rewrite is not a history — and because the merchant
 * needs to be able to undo the restore as easily as they made it.
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
import { Badge } from './Badge';

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
		return __( 'date unknown', 'wc-checkoutsuite' );
	}

	const parsed = new Date( value );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return value;
	}

	return parsed.toLocaleString();
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
			<h3 className="wccs-revisions__title" id="wccs-revisions-title">
				{ __( 'Publication history', 'wc-checkoutsuite' ) }
			</h3>

			<p className="wccs-revisions__statement">
				{ __(
					'Going back to an earlier version publishes it again as a new revision. Nothing is erased.',
					'wc-checkoutsuite'
				) }
			</p>

			{ error ? <Notice status="error">{ error }</Notice> : null }
			{ restored ? <Notice status="success">{ restored }</Notice> : null }

			{ 0 === entries.length ? (
				<Notice status="info">
					{ __(
						'Nothing has been published yet. The store is running without a configured checkout.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : (
				<ol className="wccs-revisions__list">
					{ entries.map( ( entry ) => {
						const isCurrent =
							Number( entry.revision ) ===
							Number( currentRevision );

						return (
							<li
								key={ entry.revision }
								className={
									'wccs-revisions__item' +
									( isCurrent ? ' is-current' : '' )
								}
							>
								<div className="wccs-revisions__main">
									<span className="wccs-revisions__number">
										{ sprintf(
											/* translators: %d: revision number. */
											__(
												'Revision %d',
												'wc-checkoutsuite'
											),
											entry.revision
										) }
									</span>
									<span className="wccs-revisions__when">
										{ formatWhen( entry.published_at ) }
									</span>
								</div>

								{ isCurrent ? (
									<Badge tone="success">
										{ __(
											'In the store',
											'wc-checkoutsuite'
										) }
									</Badge>
								) : (
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
										{ __( 'Restore', 'wc-checkoutsuite' ) }
									</Button>
								) }
							</li>
						);
					} ) }
				</ol>
			) }
		</section>
	);
}
