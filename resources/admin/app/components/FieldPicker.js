/**
 * Field picker.
 *
 * The merchant's entry point to the schema: a searchable, categorised list of
 * everything that can be added, followed by the WooCommerce fields that can be
 * adopted for customisation.
 *
 * Three decisions shape it:
 *
 * 1. **The list comes from the server.** Field types are read from the
 *    `/field-types` route, which reads the registry, so a type a third-party
 *    plugin registered appears here with no change to this file. The only
 *    hard-coded list would be a list that is wrong.
 *
 * 2. **Presets come before types.** A merchant wants "CPF", not "text with a
 *    mask". Presets are offered first and named for what they produce.
 *
 * 3. **Search and category filter compose.** Typing narrows within the chosen
 *    category and, when no category is chosen, across all of them, with the
 *    category name shown on each result so a mixed list stays readable.
 *
 * @see ROADMAP.md sections 6 and 19
 */

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import Notice from './Notice';
import Segmented from './Segmented';
import { TextField, SelectField } from './controls';
import { Badge } from './Badge';

/**
 * Identifier of the synthetic "everything" category.
 *
 * @type {string}
 */
const ALL = 'all';

/**
 * Normalises text for case and accent insensitive matching.
 *
 * A merchant typing "endereco" must find "Endereço".
 *
 * @param {string} value Source text.
 * @return {string} Normalised text.
 */
export function normaliseSearch( value ) {
	return ( value ?? '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.trim();
}

/**
 * Whether an entry matches a search term.
 *
 * @param {{ label?: string, key?: string, type?: string }} entry Entry.
 * @param {string}                                          term  Normalised search term.
 * @return {boolean} True when it matches.
 */
function matches( entry, term ) {
	if ( '' === term ) {
		return true;
	}

	const haystack = normaliseSearch(
		`${ entry.label ?? '' } ${ entry.key ?? '' } ${ entry.type ?? '' }`
	);

	return haystack.includes( term );
}

/**
 * Groups filtered type entries by their category.
 *
 * @param {import('../schema/types').FieldTypeCategory[]} categories Categories with their types.
 * @param {string}                                        term       Normalised search term.
 * @return {import('../schema/types').FieldTypeCategory[]} Non-empty groups.
 */
function filterGroups( categories, term ) {
	return ( categories ?? [] )
		.map( ( category ) => ( {
			...category,
			types: ( category.types ?? [] ).filter( ( type ) =>
				matches( type, term )
			),
		} ) )
		.filter( ( category ) => category.types.length > 0 );
}

/**
 * The WooCommerce-owned fields offered for adoption.
 *
 * Extracted from the picker because the three states it can be in — inventory
 * unavailable, nothing matched, and a list — read as a nested conditional
 * otherwise, and because "the store's own fields could not be listed" is a
 * different kind of message from "your search found nothing".
 *
 * @param {Object}                                                   props             Component properties.
 * @param {import('../schema/types').CoreFieldInventory|null}        props.inventory   Core field inventory.
 * @param {import('../schema/types').CoreFieldEntry[]}               props.fields      Fields to offer.
 * @param {(core: import('../schema/types').CoreFieldEntry) => void} props.onAdoptCore Called with the chosen field.
 * @return {*} Rendered element tree.
 */
function CoreFieldChoices( { inventory, fields, onAdoptCore } ) {
	if ( inventory?.available === false ) {
		return <Notice status="warning">{ inventory.reason }</Notice>;
	}

	if ( fields.length === 0 ) {
		return (
			<p className="wccs-picker__empty">
				{ __(
					'No WooCommerce field matches the search.',
					'wc-checkoutsuite'
				) }
			</p>
		);
	}

	return (
		<ul className="wccs-picker__list">
			{ fields.map( ( field ) => (
				<li key={ field.id }>
					<button
						type="button"
						className="wccs-picker__item"
						onClick={ () => onAdoptCore( field ) }
					>
						<span className="wccs-picker__item-label">
							{ field.label }
						</span>
						<span className="wccs-picker__item-meta">
							{ field.id } · { field.section }
						</span>
						<Badge tone="brand">
							{ __( 'WooCommerce', 'wc-checkoutsuite' ) }
						</Badge>
					</button>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Field picker.
 *
 * @param {Object}                                                   props                   Component properties.
 * @param {import('../schema/types').FieldCatalog|null}              props.catalog           Registered field types and presets.
 * @param {import('../schema/types').CoreFieldInventory|null}        props.coreFields        Core field inventory.
 * @param {string}                                                   props.section           Section new fields are created in.
 * @param {(choice: import('../schema/types').PickerChoice) => void} props.onChooseType      Called with the chosen type or preset.
 * @param {(core: import('../schema/types').CoreFieldEntry) => void} props.onAdoptCore       Called with the chosen WooCommerce field.
 * @param {Array<{key: string, label: string}>}                      [props.sections]        Sections offered for new fields.
 * @param {(section: string) => void}                                [props.onSectionChange] Called with a new target section.
 * @param {boolean}                                                  [props.busy]            Whether the catalogue is loading.
 * @param {string}                                                   [props.error]           Catalogue loading error.
 * @return {*} Rendered element tree.
 */
export default function FieldPicker( {
	catalog,
	coreFields,
	section,
	onChooseType,
	onAdoptCore,
	sections = [],
	onSectionChange,
	busy = false,
	error = '',
} ) {
	const [ term, setTerm ] = useState( '' );
	const [ category, setCategory ] = useState( ALL );

	// Memoised: `?? []` would build a new array on every render and make every
	// memo below recompute for a reason that has nothing to do with the data.
	const categories = useMemo( () => catalog?.categories ?? [], [ catalog ] );
	const presets = useMemo( () => catalog?.presets ?? [], [ catalog ] );
	const normalised = normaliseSearch( term );

	const categoryOptions = useMemo(
		() => [
			{ id: ALL, label: __( 'All', 'wc-checkoutsuite' ) },
			...categories.map( ( entry ) => ( {
				id: entry.key,
				label: entry.label,
			} ) ),
		],
		[ categories ]
	);

	const visibleCategories = useMemo( () => {
		const scoped =
			ALL === category
				? categories
				: categories.filter( ( entry ) => entry.key === category );

		return filterGroups( scoped, normalised );
	}, [ categories, category, normalised ] );

	const visiblePresets = useMemo(
		() =>
			( presets ?? [] ).filter( ( preset ) =>
				matches( preset, normalised )
			),
		[ presets, normalised ]
	);

	const visibleCore = useMemo(
		() =>
			( coreFields?.fields ?? [] ).filter( ( field ) =>
				matches( field, normalised )
			),
		[ coreFields, normalised ]
	);

	const typeCount = visibleCategories.reduce(
		( total, entry ) => total + entry.types.length,
		0
	);
	const nothingFound =
		! busy &&
		'' === error &&
		0 === typeCount &&
		0 === visiblePresets.length &&
		0 === visibleCore.length;

	return (
		<div className="wccs-picker">
			<div className="wccs-picker__controls">
				<TextField
					id="wccs-picker-search"
					label={ __( 'Search fields', 'wc-checkoutsuite' ) }
					value={ term }
					onChange={ (
						/** @type {{ target: { value: string } }} */ event
					) => setTerm( event.target.value ) }
					placeholder={ __(
						'CPF, address, upload…',
						'wc-checkoutsuite'
					) }
				/>

				{ sections.length > 0 && onSectionChange ? (
					<SelectField
						id="wccs-picker-section"
						label={ __( 'Add to section', 'wc-checkoutsuite' ) }
						value={ section }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) => onSectionChange( event.target.value ) }
						options={ sections.map( ( entry ) => ( {
							value: entry.key,
							label: entry.label,
						} ) ) }
					/>
				) : null }
			</div>

			{ busy ? (
				<Notice status="info">
					{ __( 'Loading the field list…', 'wc-checkoutsuite' ) }
				</Notice>
			) : null }

			{ error ? (
				<Notice
					status="error"
					title={ __(
						'The field list could not be loaded',
						'wc-checkoutsuite'
					) }
				>
					{ error }
				</Notice>
			) : null }

			{ ! busy && ! error ? (
				<Segmented
					label={ __( 'Category', 'wc-checkoutsuite' ) }
					options={ categoryOptions }
					value={ category }
					onChange={ setCategory }
					compact
				/>
			) : null }

			{ nothingFound ? (
				<Notice status="info">
					{ sprintf(
						/* translators: %s: the search term. */
						__(
							'Nothing matches "%s". Try a shorter term or another category.',
							'wc-checkoutsuite'
						),
						term
					) }
				</Notice>
			) : null }

			{ visiblePresets.length > 0 ? (
				<section className="wccs-picker__group">
					<h3 className="wccs-picker__group-title">
						{ __( 'Ready-made fields', 'wc-checkoutsuite' ) }
					</h3>
					<ul className="wccs-picker__list">
						{ visiblePresets.map( ( preset ) => (
							<li key={ preset.key }>
								<button
									type="button"
									className="wccs-picker__item"
									onClick={ () =>
										onChooseType( {
											type: preset.type,
											label: preset.label,
											settings: preset.settings ?? {},
											defaults: preset.defaults ?? {},
											preset: preset.key,
											// The capabilities belong to the type the preset
											// is built on; without them the field would be
											// created with defaults the server refuses.
											supports:
												catalog?.types?.[ preset.type ]
													?.supports ?? {},
										} )
									}
								>
									<span className="wccs-picker__item-label">
										{ preset.label }
									</span>
									<span className="wccs-picker__item-meta">
										{ preset.type }
										{ preset.group
											? ` · ${ preset.group }`
											: '' }
									</span>
								</button>
							</li>
						) ) }
					</ul>
				</section>
			) : null }

			{ visibleCategories.map( ( entry ) => (
				<section
					key={ entry.key }
					className="wccs-picker__group"
					aria-labelledby={ `wccs-picker-cat-${ entry.key }` }
				>
					<h3
						className="wccs-picker__group-title"
						id={ `wccs-picker-cat-${ entry.key }` }
					>
						{ entry.label }
					</h3>
					<ul
						className="wccs-picker__list"
						aria-labelledby={ `wccs-picker-cat-${ entry.key }` }
					>
						{ entry.types.map( ( type ) => (
							<li key={ type.key }>
								<button
									type="button"
									className="wccs-picker__item"
									onClick={ () =>
										onChooseType( {
											type: type.key,
											label: type.label,
											settings: {},
											preset: null,
											supports: type.supports,
										} )
									}
								>
									<span className="wccs-picker__item-label">
										{ type.label }
									</span>
									<span className="wccs-picker__item-meta">
										{ type.key }
										{ ALL === category
											? ` · ${ type.category }`
											: '' }
									</span>
								</button>
							</li>
						) ) }
					</ul>
				</section>
			) ) }

			<section
				className="wccs-picker__group"
				aria-labelledby="wccs-picker-core"
			>
				<h3 className="wccs-picker__group-title" id="wccs-picker-core">
					{ __( 'WooCommerce fields', 'wc-checkoutsuite' ) }
				</h3>

				<CoreFieldChoices
					inventory={ coreFields }
					fields={ visibleCore }
					onAdoptCore={ onAdoptCore }
				/>

				<p className="wccs-picker__hint">
					{ __(
						'WooCommerce fields of your store. Adding one lets you rename, describe and resize it; it cannot be archived or removed, because the checkout depends on it.',
						'wc-checkoutsuite'
					) }
				</p>
			</section>

			{ ! busy && ! error && '' !== term ? (
				<Button variant="secondary" onClick={ () => setTerm( '' ) }>
					{ __( 'Clear search', 'wc-checkoutsuite' ) }
				</Button>
			) : null }
		</div>
	);
}
