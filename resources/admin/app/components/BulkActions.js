/**
 * Bulk actions.
 *
 * Applies one change to many fields, with a confirmation that says what the
 * change will actually do.
 *
 * ROADMAP.md section 436 asks for bulk operations "com confirmação de impacto". A
 * confirmation that only counts the selection is not a confirmation of impact:
 * what the merchant needs to know before agreeing is **which** fields will be
 * left alone and why. Archiving ten fields where three belong to WooCommerce
 * succeeds for seven; saying "10 fields will be archived" and then quietly doing
 * seven is the kind of answer that costs trust.
 *
 * Nothing here decides whether an operation is allowed. The pure operations in
 * `fieldOperations` do, and the server has the last word. This component asks
 * what would happen, shows the answer, and then asks for a decision.
 *
 * @see ROADMAP.md sections 428 and 436
 */

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import Dialog from './Dialog';
import Notice from './Notice';
import { SelectField } from './controls';
import {
	archiveFields,
	bulkImpact,
	moveFieldsToSection,
	setFieldsEnabled,
	setFieldsVisibility,
} from '../schema/fieldOperations';

/**
 * Bulk action bar.
 *
 * @param {Object}                  props           Component properties.
 * @param {any}                     props.document  Draft document.
 * @param {string[]}                props.selected  Selected field identifiers.
 * @param {any[]}                   props.sections  Sections that can be moved to.
 * @param {any[]}                   props.audiences Visibility audiences.
 * @param {(...args: any[]) => any} props.onApply   Called with the result of a confirmed action.
 * @param {(...args: any[]) => any} props.onClear   Called when the selection is dropped.
 * @return {*} Rendered element tree.
 */
export default function BulkActions( {
	document,
	selected,
	sections = [],
	audiences = [],
	onApply,
	onClear,
} ) {
	const [ section, setSection ] = useState( sections[ 0 ]?.key ?? '' );
	const [ audience, setAudience ] = useState( audiences[ 0 ]?.value ?? '' );
	/**
	 * The action waiting for confirmation.
	 *
	 * Annotated on the argument rather than destructured: a JSDoc type on the
	 * tuple does not reach useState, and without it the state is inferred as
	 * `null` and every assignment to it is refused.
	 *
	 * @type {[any, Function]}
	 */
	const [ pending, setPending ] = useState( /** @type {any} */ ( null ) );

	// Memoised: `?? []` would build a new array on every render and make the
	// selection memo below recompute for no reason.
	const ids = useMemo(
		() => ( Array.isArray( selected ) ? selected : [] ),
		[ selected ]
	);

	const selection = useMemo(
		() =>
			( document?.fields ?? [] ).filter( ( /** @type {any} */ field ) =>
				ids.includes( field.id )
			),
		[ document, ids ]
	);

	/**
	 * Confirms archiving.
	 *
	 * @return {void}
	 */
	const askArchive = () => {
		const impact = bulkImpact( document, ids, 'archive' );

		setPending( {
			title: __( 'Archive the selected fields', 'wc-checkoutsuite' ),
			impact,
			result: archiveFields( document, ids ),
			confirm: __( 'Archive', 'wc-checkoutsuite' ),
		} );
	};

	/**
	 * Confirms enabling.
	 *
	 * @return {void}
	 */
	const askEnable = () => {
		const impact = bulkImpact( document, ids, 'enable' );

		setPending( {
			title: __( 'Enable the selected fields', 'wc-checkoutsuite' ),
			impact,
			result: setFieldsEnabled( document, ids, true ),
			confirm: __( 'Enable', 'wc-checkoutsuite' ),
		} );
	};

	/**
	 * Confirms moving to a section.
	 *
	 * @return {void}
	 */
	const askMove = () => {
		if ( ! section ) {
			return;
		}

		const target = sections.find( ( entry ) => entry.key === section );

		setPending( {
			title: sprintf(
				/* translators: %s: section title. */
				__( 'Move the selected fields to %s', 'wc-checkoutsuite' ),
				target?.label ?? section
			),
			impact: {
				total: ids.length,
				affected: ids.length,
				protected: [],
				unchanged: [],
			},
			result: moveFieldsToSection( document, ids, section ),
			confirm: __( 'Move', 'wc-checkoutsuite' ),
		} );
	};

	/**
	 * Confirms a visibility change.
	 *
	 * @param {boolean} allowed Target state.
	 * @return {void}
	 */
	const askVisibility = ( allowed ) => {
		if ( ! audience ) {
			return;
		}

		const entry = audiences.find( ( item ) => item.value === audience );
		const impact = {
			total: ids.length,
			affected: selection.filter(
				( /** @type {any} */ field ) =>
					field.visibility?.[ audience ] !== allowed
			).length,
			protected: [],
			unchanged: selection
				.filter(
					( /** @type {any} */ field ) =>
						field.visibility?.[ audience ] === allowed
				)
				.map( ( /** @type {any} */ field ) => field.id ),
		};

		setPending( {
			title: sprintf(
				/* translators: 1: show or hide, 2: audience name. */
				__( '%1$s the selected fields for %2$s', 'wc-checkoutsuite' ),
				allowed
					? __( 'Show', 'wc-checkoutsuite' )
					: __( 'Hide', 'wc-checkoutsuite' ),
				entry?.label ?? audience
			),
			impact,
			result: setFieldsVisibility( document, ids, audience, allowed ),
			confirm: allowed
				? __( 'Show', 'wc-checkoutsuite' )
				: __( 'Hide', 'wc-checkoutsuite' ),
		} );
	};

	if ( 0 === ids.length ) {
		return null;
	}

	return (
		<div
			className="wccs-bulk"
			role="group"
			aria-label={ __( 'Bulk actions', 'wc-checkoutsuite' ) }
		>
			<p className="wccs-bulk__count">
				{ sprintf(
					/* translators: %d: number of selected fields. */
					__( '%d field(s) selected', 'wc-checkoutsuite' ),
					ids.length
				) }
			</p>

			<div className="wccs-bulk__actions">
				<Button size="small" onClick={ askEnable }>
					{ __( 'Enable', 'wc-checkoutsuite' ) }
				</Button>
				<Button size="small" onClick={ askArchive }>
					{ __( 'Archive', 'wc-checkoutsuite' ) }
				</Button>
				<Button size="small" onClick={ onClear }>
					{ __( 'Clear selection', 'wc-checkoutsuite' ) }
				</Button>
			</div>

			{ sections.length > 0 ? (
				<div className="wccs-bulk__actions">
					<SelectField
						id="wccs-bulk-section"
						label={ __( 'Move to section', 'wc-checkoutsuite' ) }
						value={ section }
						options={ sections.map( ( entry ) => ( {
							value: entry.key,
							label: entry.label,
						} ) ) }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => setSection( event.target.value ) }
					/>
					<Button size="small" onClick={ askMove }>
						{ __( 'Move', 'wc-checkoutsuite' ) }
					</Button>
				</div>
			) : null }

			{ audiences.length > 0 ? (
				<div className="wccs-bulk__actions">
					<SelectField
						id="wccs-bulk-audience"
						label={ __( 'Visibility', 'wc-checkoutsuite' ) }
						value={ audience }
						options={ audiences.map( ( entry ) => ( {
							value: entry.value,
							label: entry.label,
						} ) ) }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => setAudience( event.target.value ) }
					/>
					<Button
						size="small"
						onClick={ () => askVisibility( true ) }
					>
						{ __( 'Show', 'wc-checkoutsuite' ) }
					</Button>
					<Button
						size="small"
						onClick={ () => askVisibility( false ) }
					>
						{ __( 'Hide', 'wc-checkoutsuite' ) }
					</Button>
				</div>
			) : null }

			<Dialog
				open={ Boolean( pending ) }
				title={ pending?.title ?? '' }
				onClose={ () => setPending( null ) }
				footer={
					<>
						<Button onClick={ () => setPending( null ) }>
							{ __( 'Cancel', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								onApply( pending?.result );
								setPending( null );
							} }
						>
							{ pending?.confirm ??
								__( 'Apply', 'wc-checkoutsuite' ) }
						</Button>
					</>
				}
			>
				<p>
					{ sprintf(
						/* translators: 1: number that will change, 2: number selected. */
						__(
							'%1$d of %2$d selected field(s) will change.',
							'wc-checkoutsuite'
						),
						pending?.impact?.affected ?? 0,
						pending?.impact?.total ?? 0
					) }
				</p>

				{ ( pending?.impact?.protected ?? [] ).length > 0 ? (
					<Notice
						status="warning"
						title={ __(
							'Left alone because WooCommerce owns them',
							'wc-checkoutsuite'
						) }
					>
						<ul>
							{ pending.impact.protected.map(
								( /** @type {string} */ id ) => (
									<li key={ id }>
										<code>{ id }</code>
									</li>
								)
							) }
						</ul>
					</Notice>
				) : null }

				{ ( pending?.impact?.unchanged ?? [] ).length > 0 ? (
					<Notice
						status="info"
						title={ __(
							'Already in that state',
							'wc-checkoutsuite'
						) }
					>
						<ul>
							{ pending.impact.unchanged.map(
								( /** @type {string} */ id ) => (
									<li key={ id }>
										<code>{ id }</code>
									</li>
								)
							) }
						</ul>
					</Notice>
				) : null }
			</Dialog>
		</div>
	);
}
