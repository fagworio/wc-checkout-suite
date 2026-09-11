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
 * Renders one side of a difference.
 *
 * @param {Object} props       Component properties.
 * @param {*}      props.value Value to render.
 * @return {*} Rendered element tree.
 */
function ValueText( { value } ) {
	if ( null === value || undefined === value ) {
		return <em>{ __( 'not set', 'wc-checkoutsuite' ) }</em>;
	}

	if ( '' === value ) {
		return <em>{ __( 'empty', 'wc-checkoutsuite' ) }</em>;
	}

	if ( 'object' === typeof value ) {
		return <code>{ JSON.stringify( value ) }</code>;
	}

	if ( 'boolean' === typeof value ) {
		return <code>{ value ? 'true' : 'false' }</code>;
	}

	return <code>{ String( value ) }</code>;
}

/**
 * Renders a list of key/from/to differences.
 *
 * @param {Object} props             Component properties.
 * @param {any[]}  props.differences Differences.
 * @return {*} Rendered element tree.
 */
function Differences( { differences } ) {
	if ( ! Array.isArray( differences ) || 0 === differences.length ) {
		return null;
	}

	return (
		<ul className="wccs-publish__differences">
			{ differences.map( ( entry ) => (
				<li key={ entry.key }>
					<span className="wccs-publish__key">{ entry.key }</span>
					<ValueText value={ entry.from } />
					<span aria-hidden="true">→</span>
					<ValueText value={ entry.to } />
				</li>
			) ) }
		</ul>
	);
}

/**
 * Renders one group of a diff.
 *
 * @param {Object}                            props            Component properties.
 * @param {string}                            props.title      Group title.
 * @param {any[]}                             props.items      Items to list.
 * @param {(item: any, index: number) => any} props.renderItem Renders one item.
 * @return {*} Rendered element tree.
 */
function Group( { title, items, renderItem } ) {
	if ( ! Array.isArray( items ) || 0 === items.length ) {
		return null;
	}

	return (
		<div className="wccs-publish__group">
			<h4 className="wccs-publish__group-title">
				{ title } <Badge tone="neutral">{ items.length }</Badge>
			</h4>
			<ul className="wccs-publish__list">
				{ items.map( ( item, index ) => (
					<li key={ item.id ?? index }>
						{ renderItem( item, index ) }
					</li>
				) ) }
			</ul>
		</div>
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
 * @return {*} Rendered element tree.
 */
export default function PublishPanel( {
	report,
	onPublish,
	publishing = false,
	dirty = false,
	error = '',
} ) {
	const diff = report?.diff ?? null;
	const validation = report?.validation ?? null;
	const incompatibilities = report?.incompatibilities ?? null;

	const invalid = validation ? ! validation.valid : false;
	const blocked = invalid || dirty || publishing;
	const changes = diff?.total_changes ?? 0;
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
				<Notice
					status="error"
					title={ __(
						'This draft cannot be published yet',
						'wc-checkoutsuite'
					) }
				>
					<ul className="wccs-publish__list">
						{ ( validation.errors ?? [] ).map(
							(
								/** @type {any} */ entry,
								/** @type {number} */ index
							) => (
								<li key={ `${ entry.code }-${ index }` }>
									{ entry.message }
								</li>
							)
						) }
					</ul>
				</Notice>
			) : null }

			{ diff && diff.empty ? (
				<Notice status="info">
					{ __(
						'The draft is identical to the published version.',
						'wc-checkoutsuite'
					) }
				</Notice>
			) : null }

			{ diff && ! diff.empty ? (
				<div className="wccs-publish__changes">
					<Group
						title={ __( 'Fields added', 'wc-checkoutsuite' ) }
						items={ diff.fields?.added }
						renderItem={ ( item ) => (
							<span>
								{ item.label } <code>{ item.id }</code>
							</span>
						) }
					/>
					<Group
						title={ __( 'Fields removed', 'wc-checkoutsuite' ) }
						items={ diff.fields?.removed }
						renderItem={ ( item ) => (
							<span>
								{ item.label } <code>{ item.id }</code>
							</span>
						) }
					/>
					<Group
						title={ __( 'Fields changed', 'wc-checkoutsuite' ) }
						items={ diff.fields?.changed }
						renderItem={ ( item ) => (
							<span>
								{ item.label }{ ' ' }
								<Differences differences={ item.differences } />
							</span>
						) }
					/>
					<Group
						title={ __( 'Sections added', 'wc-checkoutsuite' ) }
						items={ diff.sections?.added }
						renderItem={ ( item ) => (
							<span>
								{ item.title } <code>{ item.id }</code>
							</span>
						) }
					/>
					<Group
						title={ __( 'Sections removed', 'wc-checkoutsuite' ) }
						items={ diff.sections?.removed }
						renderItem={ ( item ) => (
							<span>
								{ item.title } <code>{ item.id }</code>
							</span>
						) }
					/>
					<Group
						title={ __( 'Sections changed', 'wc-checkoutsuite' ) }
						items={ diff.sections?.changed }
						renderItem={ ( item ) => (
							<span>
								{ item.title }{ ' ' }
								<Differences differences={ item.differences } />
							</span>
						) }
					/>
					<Group
						title={ __( 'Order changed', 'wc-checkoutsuite' ) }
						items={ diff.order }
						renderItem={ ( item ) => (
							<span>
								<code>{ item.section }</code>{ ' ' }
								{ item.from.join( ', ' ) } →{ ' ' }
								{ item.to.join( ', ' ) }
							</span>
						) }
					/>
				</div>
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
