/**
 * Publication panel.
 *
 * Shows what publishing would do before it does it, in the three separate terms
 * ROADMAP.md section 428 asks for: **differences**, **validations** and
 * **incompatibilities**.
 *
 * They are kept apart on purpose, because they mean different things and lead to
 * different actions:
 *
 * - A **difference** is what will change. Nothing is wrong with it.
 * - A **validation** problem means publication will be refused. It has to be
 *   fixed.
 * - An **incompatibility** means the schema is perfectly valid and a checkout
 *   will still not honour it as written. It is a warning, and the merchant
 *   decides.
 *
 * Merging them into one list would hide the third behind the first two, and the
 * third is the one that reaches a customer.
 *
 * The panel never publishes on its own and never while the draft has unsaved
 * changes: publishing a version older than the one on screen is exactly the
 * accident this whole surface exists to prevent.
 *
 * @see ROADMAP.md sections 8 and 428
 */

import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import Notice from './Notice';
import { Badge } from './Badge';
import { Icon } from '../design/icons';

/**
 * Wording of the change count.
 *
 * @param {number} changes Number of reported changes.
 * @return {string} Label.
 */
function badgeLabel( changes ) {
	if ( 0 === changes ) {
		return __( 'Nothing to publish', 'wc-checkoutsuite' );
	}

	return sprintf(
		/* translators: %d: number of changes. */
		__( '%d change(s) ready', 'wc-checkoutsuite' ),
		changes
	);
}

/**
 * Publication panel.
 *
 * @param {Object}                  props              Component properties.
 * @param {any}                     props.report       Publication report from the diff route.
 * @param {(...args: any[]) => any} props.onPublish    Called when the merchant asks to publish.
 * @param {boolean}                 [props.publishing] Whether a publication is in progress.
 * @param {boolean}                 [props.dirty]      Whether the draft has unsaved changes.
 * @param {string}                  [props.error]      Failure from the last attempt.
 * @param {number}                  [props.enabled]    Fields the draft would activate.
 * @return {*} Rendered element tree.
 */
export default function PublishPanel( {
	report,
	onPublish,
	publishing = false,
	dirty = false,
	error = '',
	enabled = 0,
} ) {
	const diff = report?.diff ?? null;
	const validation = report?.validation ?? null;
	const incompatibilities = report?.incompatibilities ?? null;
	const capabilities = report?.capabilities ?? [];

	const invalid = validation ? ! validation.valid : false;
	const blocked = invalid || dirty || publishing;
	const changes = diff?.total_changes ?? 0;

	/**
	 * The design's diff rows: one badge, one label and one detail each.
	 *
	 * Built from the same report the panel already reports, so the dialog states the
	 * differences once and in one shape instead of a group per kind.
	 *
	 * @return {{kind: string, tone: string, label: string, detail: string}[]} Rows.
	 */
	const diffRows = () => {
		if ( ! diff || diff.empty ) {
			return [];
		}

		/** @type {{kind: string, tone: string, label: string, detail: string}[]} */
		const rows = [];
		const code = ( /** @type {any} */ entry ) =>
			entry.id ?? entry.key ?? '';

		( diff.fields?.added ?? [] ).forEach( ( /** @type {any} */ entry ) =>
			rows.push( {
				kind: __( 'Adicionado', 'wc-checkoutsuite' ),
				tone: 'green',
				label: entry.label ?? code( entry ),
				detail: code( entry ),
			} )
		);
		( diff.fields?.removed ?? [] ).forEach( ( /** @type {any} */ entry ) =>
			rows.push( {
				kind: __( 'Removido', 'wc-checkoutsuite' ),
				tone: 'red',
				label: entry.label ?? code( entry ),
				detail: code( entry ),
			} )
		);
		( diff.fields?.changed ?? [] ).forEach( ( /** @type {any} */ entry ) =>
			rows.push( {
				kind: __( 'Alterado', 'wc-checkoutsuite' ),
				tone: 'amber',
				label: entry.label ?? code( entry ),
				detail: ( entry.differences ?? [] )
					.map(
						( /** @type {any} */ difference ) =>
							`${ difference.key }: ${ difference.from } → ${ difference.to }`
					)
					.join( ' · ' ),
			} )
		);
		/** @type {Record<string,{kind: string, tone: string}>} */
		const sectionKinds = {
			added: {
				kind: __( 'Adicionado', 'wc-checkoutsuite' ),
				tone: 'green',
			},
			removed: {
				kind: __( 'Removido', 'wc-checkoutsuite' ),
				tone: 'red',
			},
			changed: {
				kind: __( 'Alterado', 'wc-checkoutsuite' ),
				tone: 'amber',
			},
		};

		Object.keys( sectionKinds ).forEach( ( kind ) => {
			( diff.sections?.[ kind ] ?? [] ).forEach(
				( /** @type {any} */ entry ) =>
					rows.push( {
						kind: sectionKinds[ kind ].kind,
						tone: sectionKinds[ kind ].tone,
						label: entry.title ?? entry.id ?? '',
						detail: __( 'Seção', 'wc-checkoutsuite' ),
					} )
			);
		} );
		( diff.order ?? [] ).forEach( ( /** @type {any} */ entry ) =>
			rows.push( {
				kind: __( 'Reordenado', 'wc-checkoutsuite' ),
				tone: 'purple',
				label: entry.section ?? '',
				detail: `${ ( entry.from ?? [] ).join( ', ' ) } → ${ (
					entry.to ?? []
				).join( ', ' ) }`,
			} )
		);

		return rows;
	};

	const rows = diffRows();
	const incompatibilityTotal = incompatibilities?.total ?? 0;

	// Why publishing is unavailable, in the order that matters: an unsaved draft
	// would publish something other than what is on screen, and an invalid schema
	// would be refused anyway.
	let hint = '';

	if ( ! publishing && dirty ) {
		hint = __( 'Save the draft first.', 'wc-checkoutsuite' );
	} else if ( ! publishing && invalid ) {
		hint = __( 'Fix the problems above first.', 'wc-checkoutsuite' );
	}

	return (
		<section className="wccs-publish" aria-labelledby="wccs-publish-title">
			<header className="wccs-publish__header">
				<h3 className="wccs-publish__title" id="wccs-publish-title">
					{ __( 'Publish', 'wc-checkoutsuite' ) }
				</h3>

				{ diff ? (
					<Badge tone={ 0 === changes ? 'success' : 'warning' }>
						{ badgeLabel( changes ) }
					</Badge>
				) : null }
			</header>

			<div className="publish-stats">
				<div className="publish-stat">
					<strong>{ changes }</strong>
					<span>
						{ __( 'alterações para revisar', 'wc-checkoutsuite' ) }
					</span>
				</div>
				<div className="publish-stat">
					<strong>{ enabled }</strong>
					<span>
						{ __( 'campos habilitados', 'wc-checkoutsuite' ) }
					</span>
				</div>
				<div className="publish-stat">
					<strong>{ ( validation?.errors ?? [] ).length }</strong>
					<span>{ __( 'impedimentos', 'wc-checkoutsuite' ) }</span>
				</div>
			</div>

			<p className="wccs-publish__statement">
				{ __(
					'Your changes are in the draft. The store keeps running the published version until you publish, and publishing is a new revision you can go back to.',
					'wc-checkoutsuite'
				) }
			</p>

			{ error ? <Notice status="error">{ error }</Notice> : null }

			{ dirty ? (
				<Notice status="warning">
					{ __(
						'Save the draft before publishing, so the published version is the one you are looking at.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : null }

			{ invalid ? (
				<>
					<div className="publish-subtitle">
						{ __(
							'Corrija antes de publicar',
							'wc-checkoutsuite'
						) }
					</div>
					<ul className="validation-list">
						{ ( validation.errors ?? [] ).map(
							(
								/** @type {any} */ entry,
								/** @type {number} */ index
							) => (
								<li key={ `${ entry.code }-${ index }` }>
									<Icon name="info" />
									<span>{ entry.message }</span>
								</li>
							)
						) }
					</ul>
				</>
			) : null }

			{ diff && diff.empty ? (
				<Notice status="info">
					{ __(
						'The draft is identical to the published version.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : null }

			{ rows.length > 0 ? (
				<>
					<div className="publish-subtitle">
						{ sprintf(
							/* translators: %s: revision number. */
							__(
								'Diferenças em relação à revisão %s',
								'wc-checkoutsuite'
							),
							String( diff?.published?.revision ?? 0 )
						) }
					</div>
					<div className="diff-list">
						{ rows.map( ( row, index ) => (
							<div
								className="diff-row"
								key={ `${ row.kind }-${ row.label }-${ index }` }
							>
								<span className={ `badge ${ row.tone }` }>
									{ row.kind }
								</span>
								<div>
									<strong>{ row.label }</strong>
									<small>{ row.detail }</small>
								</div>
							</div>
						) ) }
					</div>
				</>
			) : null }

			{ capabilities.length > 0 ? (
				// The limits come before the warnings on purpose: what a checkout
				// does with a field is a fact to decide with, and what needs work is
				// a problem to fix. Showing the second without the first turns the
				// panel into a list of complaints.
				<details className="wccs-publish__capabilities">
					<summary>
						{ __(
							'What each checkout does with these fields',
							'wc-checkoutsuite'
						) }
					</summary>

					{ capabilities.map( ( /** @type {any} */ entry ) => (
						<div
							key={ entry.field }
							className="wccs-publish__capability"
						>
							<h4 className="wccs-publish__group-title">
								{ entry.label }
							</h4>
							<ul className="wccs-publish__list">
								{ ( entry.limits ?? [] ).map(
									(
										/** @type {any} */ limit,
										/** @type {number} */ index
									) => (
										<li
											key={ `${ limit.family }-${ limit.adapter }-${ index }` }
										>
											<span className="wccs-publish__capability-family">
												{ limit.family }
												{ 'all' === limit.adapter
													? ''
													: ` · ${ limit.adapter }` }
											</span>{ ' ' }
											{ limit.reason }
										</li>
									)
								) }
							</ul>
						</div>
					) ) }
				</details>
			) : null }

			{ incompatibilities && incompatibilityTotal > 0 ? (
				<Notice
					status="warning"
					title={ __(
						'Some fields need work before every checkout can show them',
						'wc-checkoutsuite'
					) }
				>
					<p>
						{ __(
							'These do not block publication. They mean a checkout will not honour the configuration as written.',
							'wc-checkoutsuite'
						) }
					</p>

					{ ( report?.adapters ?? [] ).map(
						( /** @type {any} */ adapter ) => {
							const entries =
								incompatibilities.adapters?.[ adapter.value ] ??
								[];

							if ( 0 === entries.length ) {
								return (
									<p
										key={ adapter.value }
										className="wccs-publish__ok"
									>
										{ sprintf(
											/* translators: %s: adapter name. */
											__(
												'No incompatibilities with the %s.',
												'wc-checkoutsuite'
											),
											adapter.label
										) }
									</p>
								);
							}

							return (
								<div
									key={ adapter.value }
									className="wccs-publish__group"
								>
									<h4 className="wccs-publish__group-title">
										{ adapter.label }{ ' ' }
										<Badge tone="warning">
											{ entries.length }
										</Badge>
									</h4>
									<ul className="wccs-publish__list">
										{ entries.map(
											( /** @type {any} */ entry ) => (
												<li key={ entry.field }>
													<strong>
														{ entry.label }
													</strong>{ ' ' }
													{ entry.reason }
												</li>
											)
										) }
									</ul>
								</div>
							);
						}
					) }

					{ ( incompatibilities.store ?? [] ).length > 0 ? (
						<div className="wccs-publish__group">
							<h4 className="wccs-publish__group-title">
								{ __( 'This store', 'wc-checkoutsuite' ) }
							</h4>
							<ul className="wccs-publish__list">
								{ incompatibilities.store.map(
									( /** @type {any} */ entry ) => (
										<li key={ entry.field }>
											<strong>{ entry.label }</strong>{ ' ' }
											{ entry.reason }
										</li>
									)
								) }
							</ul>
						</div>
					) : null }
				</Notice>
			) : null }

			<div className="wccs-publish__actions">
				<Button
					variant="primary"
					busy={ publishing }
					disabled={ blocked || 0 === changes }
					onClick={ onPublish }
				>
					{ __( 'Publish changes', 'wc-checkoutsuite' ) }
				</Button>

				{ hint ? (
					<span className="wccs-publish__hint">{ hint }</span>
				) : null }
			</div>
		</section>
	);
}
