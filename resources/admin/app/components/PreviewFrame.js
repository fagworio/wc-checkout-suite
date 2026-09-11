/**
 * Preview frame.
 *
 * Shows how the checkout will look in each device class **without touching the
 * live checkout and without any real data**.
 *
 * Why the frame is a query container
 * ----------------------------------
 * The obvious implementation — narrow a `div` and call it "mobile" — is a lie.
 * The checkout layout is decided by viewport media queries, which keep
 * responding to the browser window no matter how narrow the wrapper is, so a
 * narrowed wrapper would keep showing the desktop layout and the preview would
 * confirm a layout that does not exist.
 *
 * The frame therefore establishes a query container and states its own width,
 * and previewed content opts into `@container` rules. Content that only has
 * viewport rules still renders correctly; it simply does not reflow, and the
 * frame says so through its limitations surface instead of pretending
 * otherwise.
 *
 * The frame is self-contained: it holds no adapter, no repository and no client,
 * and performs no network access of any kind. Everything it displays comes from
 * `renderPreview`, which the caller is expected to feed with synthetic values.
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import Segmented from './Segmented';
import { Badge, CompatibilityBadge } from './Badge';
import Notice from './Notice';
import Field from './Field';
import { TextField, SelectField, CheckboxField } from './controls';

/**
 * Device classes offered by the preview.
 *
 * Labels name the device class rather than a pixel width, because the exact
 * width is a token and can change without the device class changing.
 *
 * @type {*[]}
 */
const VIEWPORTS = [
	{
		id: 'desktop',
		label: __( 'Desktop', 'wc-checkoutsuite' ),
		description: __(
			'Wide layout, as on a full-size screen.',
			'wc-checkoutsuite'
		),
	},
	{
		id: 'tablet',
		label: __( 'Tablet', 'wc-checkoutsuite' ),
		description: __(
			'Narrow layout, as on a tablet in portrait.',
			'wc-checkoutsuite'
		),
	},
	{
		id: 'mobile',
		label: __( 'Mobile', 'wc-checkoutsuite' ),
		description: __(
			'Single-column layout, as on a phone.',
			'wc-checkoutsuite'
		),
	},
];

/**
 * Adapters the preview can present.
 *
 * @type {*[]}
 */
const ADAPTERS = [
	{
		id: 'classic',
		label: __( 'Classic', 'wc-checkoutsuite' ),
		description: __(
			'Shortcode checkout, the classic WooCommerce template.',
			'wc-checkoutsuite'
		),
	},
	{
		id: 'blocks',
		label: __( 'Blocks', 'wc-checkoutsuite' ),
		description: __(
			'Block checkout, the WooCommerce Blocks experience.',
			'wc-checkoutsuite'
		),
	},
];

/**
 * Person types, which change which documents are required.
 *
 * @type {*[]}
 */
const PERSON_TYPES = [
	{
		id: 'pf',
		label: __( 'Individual', 'wc-checkoutsuite' ),
		description: __( 'Pessoa física: CPF.', 'wc-checkoutsuite' ),
	},
	{
		id: 'pj',
		label: __( 'Company', 'wc-checkoutsuite' ),
		description: __( 'Pessoa jurídica: CNPJ.', 'wc-checkoutsuite' ),
	},
];

/**
 * Customer states that change which fields are shown at all.
 *
 * @type {*[]}
 */
const CUSTOMER_STATES = [
	{
		id: 'guest',
		label: __( 'Guest', 'wc-checkoutsuite' ),
		description: __( 'Not signed in.', 'wc-checkoutsuite' ),
	},
	{
		id: 'logged-in',
		label: __( 'Signed in', 'wc-checkoutsuite' ),
		description: __( 'Existing customer.', 'wc-checkoutsuite' ),
	},
];

/**
 * A synthetic sample, so the frame can be used before any field is configured.
 *
 * The values are placeholders and are never submitted, stored or sent anywhere.
 *
 * @param {Object} props            Component properties.
 * @param {string} props.adapter    Selected adapter identifier.
 * @param {string} props.personType Selected person type identifier.
 * @param {string} props.customer   Selected customer state identifier.
 * @return {*} Rendered sample.
 */
function SyntheticSample( { adapter, personType, customer } ) {
	const isCompany = personType === 'pj';

	return (
		<div className="wccs-preview__sample">
			<p className="wccs-preview__sample-notice">
				{ __(
					'Sample content with placeholder values. Nothing here is real, saved or submitted.',
					'wc-checkoutsuite'
				) }
			</p>

			{ customer === 'logged-in' ? (
				<p className="wccs-preview__sample-line">
					{ __( 'Signed in as Ana Example.', 'wc-checkoutsuite' ) }
				</p>
			) : null }

			<Field
				id="wccs-preview-email"
				label={ __( 'Email address', 'wc-checkoutsuite' ) }
				required
			>
				<TextField
					id="wccs-preview-email"
					value="ana@example.test"
					onChange={ () => {} }
				/>
			</Field>

			<Field
				id="wccs-preview-document"
				label={
					isCompany
						? __( 'CNPJ', 'wc-checkoutsuite' )
						: __( 'CPF', 'wc-checkoutsuite' )
				}
				required
			>
				<TextField
					id="wccs-preview-document"
					value={
						isCompany ? '00.000.000/0000-00' : '000.000.000-00'
					}
					onChange={ () => {} }
				/>
			</Field>

			{ isCompany ? (
				<Field
					id="wccs-preview-ie"
					label={ __( 'State registration', 'wc-checkoutsuite' ) }
				>
					<TextField
						id="wccs-preview-ie"
						value="000.000.000.000"
						onChange={ () => {} }
					/>
				</Field>
			) : null }

			<Field
				id="wccs-preview-country"
				label={ __( 'Country / region', 'wc-checkoutsuite' ) }
				required
			>
				<SelectField
					id="wccs-preview-country"
					value="BR"
					onChange={ () => {} }
					options={ [
						{
							value: 'BR',
							label: __( 'Brazil', 'wc-checkoutsuite' ),
						},
					] }
				/>
			</Field>

			<CheckboxField
				id="wccs-preview-terms"
				label={ __( 'I accept the terms.', 'wc-checkoutsuite' ) }
				checked
				onChange={ () => {} }
			/>

			<p className="wccs-preview__sample-adapter">
				{ adapter === 'blocks'
					? __(
							'Rendered as the Block checkout would present it.',
							'wc-checkoutsuite'
					  )
					: __(
							'Rendered as the shortcode checkout would present it.',
							'wc-checkoutsuite'
					  ) }
			</p>
		</div>
	);
}

/**
 * Renders the compatibility surface of the preview.
 *
 * The matrix is never omitted. When the selected adapter has no entry, or
 * declares no limitations at all, the frame says so instead of rendering a
 * preview that looks complete — the point of the matrix is that an adapter's
 * limits cannot quietly disappear.
 *
 * @param {Object}                                                  props             Component properties.
 * @param {{ level: string, label: string, reason: string }[]|null} props.limitations Entries, or null when none were declared.
 * @return {*} Rendered matrix, warning or empty state.
 */
function CapabilityMatrix( { limitations } ) {
	if ( limitations === null ) {
		return (
			<Notice status="warning">
				{ __(
					'No compatibility information was provided for this adapter. Treat the result below as unverified.',
					'wc-checkoutsuite'
				) }
			</Notice>
		);
	}

	if ( limitations.length === 0 ) {
		return (
			<Notice status="warning">
				{ __(
					'This adapter declares no limitations. That is unusual; confirm it against the compatibility matrix before relying on it.',
					'wc-checkoutsuite'
				) }
			</Notice>
		);
	}

	return (
		<ul className="wccs-preview__capabilities">
			{ limitations.map( ( entry ) => (
				<li key={ entry.label } className="wccs-preview__capability">
					<span className="wccs-preview__capability-label">
						{ entry.label }
					</span>
					<CompatibilityBadge
						level={ entry.level }
						reason={ entry.reason }
					/>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Preview frame.
 *
 * @param {Object}                                                             props                   Component properties.
 * @param {Record<string, { level: string, label: string, reason: string }[]>} props.capabilities      Capability entries keyed by adapter id.
 * @param {Function}                                                           [props.renderPreview]   Called with the context; returns the previewed tree.
 * @param {string}                                                             [props.initialViewport] Device class to start on.
 * @param {string}                                                             [props.title]           Heading for the preview region.
 * @return {*} Rendered element tree.
 */
export default function PreviewFrame( {
	capabilities,
	renderPreview,
	initialViewport = 'desktop',
	title = __( 'Checkout preview', 'wc-checkoutsuite' ),
} ) {
	const [ viewport, setViewport ] = useState( initialViewport );
	const [ adapter, setAdapter ] = useState( 'classic' );
	const [ personType, setPersonType ] = useState( 'pf' );
	const [ customer, setCustomer ] = useState( 'guest' );

	const context = { viewport, adapter, personType, customer };

	// The matrix is keyed by adapter so the frame keeps the selector state
	// internal while the data stays outside it. An adapter with no entry is
	// reported, never silently rendered as though it had no limitations.
	const declared = capabilities ? capabilities[ adapter ] : undefined;
	const limitations = Array.isArray( declared ) ? declared : null;

	return (
		<section className="wccs-preview" aria-label={ title }>
			<header className="wccs-preview__header">
				<h2 className="wccs-preview__title">{ title }</h2>

				{ /* Always visible: no state of this frame is the live checkout. */ }
				<Badge tone="warning">
					{ __( 'Preview', 'wc-checkoutsuite' ) }
				</Badge>
			</header>

			<p className="wccs-preview__statement">
				{ __(
					'This is a simulation. It uses placeholder values, does not read your orders or customers, and never touches the live checkout.',
					'wc-checkoutsuite'
				) }
			</p>

			<div className="wccs-preview__controls">
				<Segmented
					label={ __( 'Device', 'wc-checkoutsuite' ) }
					options={ VIEWPORTS }
					value={ viewport }
					onChange={ setViewport }
				/>
				<Segmented
					label={ __( 'Checkout type', 'wc-checkoutsuite' ) }
					options={ ADAPTERS }
					value={ adapter }
					onChange={ setAdapter }
					compact
				/>
				<Segmented
					label={ __( 'Person type', 'wc-checkoutsuite' ) }
					options={ PERSON_TYPES }
					value={ personType }
					onChange={ setPersonType }
					compact
				/>
				<Segmented
					label={ __( 'Customer', 'wc-checkoutsuite' ) }
					options={ CUSTOMER_STATES }
					value={ customer }
					onChange={ setCustomer }
					compact
				/>
			</div>

			<CapabilityMatrix limitations={ limitations } />

			<div className="wccs-preview__stage">
				<div
					className="wccs-preview__surface"
					data-viewport={ viewport }
				>
					<div className="wccs-preview__surface-inner">
						{ typeof renderPreview === 'function' ? (
							renderPreview( context )
						) : (
							<SyntheticSample
								adapter={ adapter }
								personType={ personType }
								customer={ customer }
							/>
						) }
					</div>
				</div>
			</div>
		</section>
	);
}
