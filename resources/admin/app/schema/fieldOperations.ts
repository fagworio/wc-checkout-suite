/**
 * Field operations over a schema document.
 *
 * These are pure functions: they take a draft and return a new draft, never
 * mutating the one they were given. That is what lets the screen keep one
 * document as the source of truth, compare revisions for the unsaved-work guard,
 * and discard a change by dropping a value instead of undoing a mutation.
 *
 * What this module is *not* is protection. It refuses to archive a core field
 * because a button that does nothing is worse than no button, but the guarantee
 * lives on the server: CoreFieldGuard rejects any stored document that would
 * destroy a WooCommerce field, whichever client sent it. Refusing here is
 * courtesy; refusing there is the rule.
 *
 * @see ROADMAP.md sections 4 and 7
 */

import { __, sprintf } from '@wordpress/i18n';

import type {
	CoreFieldEntry,
	FieldDefinition,
	FieldLayout,
	OperationResult,
	PickerChoice,
	SchemaDocument,
	SectionDefinition,
	SectionGroup,
} from './types';

/**
 * Default storage and visibility for a type.
 *
 * The default has to follow the type. A heading stores nothing, so telling it to
 * keep its value with the order would be a claim the checkout cannot honour, and
 * the server refuses exactly that with `storage_scope_requires_value`. The
 * inspector must not be able to build a definition the server will reject.
 *
 * @param supports Capabilities the type declares.
 * @return Storage and visibility defaults.
 */
function surfacesFor( supports?: Record< string, boolean > ) {
	const storesValue = false !== supports?.value;

	return {
		storage: {
			scope: storesValue ? 'order' : 'none',
			sensitivity: 'personal',
		},
		visibility: {
			admin_order: storesValue,
			customer_order: false,
			customer_email: false,
			admin_email: false,
			public_api: false,
		},
	};
}

/**
 * Default width of a new field.
 *
 * Full width is the honest default: guessing that a new field is half width
 * would silently change the checkout layout.
 */
const DEFAULT_LAYOUT: FieldLayout = { desktop: 12, tablet: 12, mobile: 12 };

/**
 * Default section for a field that is not adopted from WooCommerce.
 */
const DEFAULT_SECTION = 'order';

/**
 * Whether a field is owned by WooCommerce.
 *
 * @param field Field definition.
 * @return True when protected.
 */
export function isProtected( field: FieldDefinition ): boolean {
	return 'core' === field?.origin;
}

/**
 * Why a field cannot be removed or archived.
 *
 * @param field Field definition.
 * @return Explanation, or an empty string when removal is allowed.
 */
export function protectionReason( field: FieldDefinition ): string {
	if ( ! isProtected( field ) ) {
		return '';
	}

	return sprintf(
		/* translators: %s: field identifier. */
		__(
			'"%s" belongs to WooCommerce. It can be renamed, described and resized, but not archived or removed: shipping, tax and payment read it.',
			'wc-checkoutsuite'
		),
		field.id
	);
}

/**
 * Turns a label into a candidate identifier.
 *
 * Accents are stripped rather than dropped, so "Endereço" becomes `endereco` and
 * not `endere_o`. The result always satisfies the server's identifier rule of
 * lowercase letters, digits and underscores.
 *
 * @param label Source text.
 * @return Candidate identifier.
 */
export function identifierFrom( label: string ): string {
	const normalised = ( label ?? '' )
		.normalize( 'NFD' )
		// Combining marks left behind by NFD.
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /_+/g, '_' )
		.replace( /^_+|_+$/g, '' );

	if ( '' === normalised ) {
		return 'field';
	}

	return normalised;
}

/**
 * Returns an identifier that is not already taken in the document.
 *
 * @param document Document.
 * @param base     Candidate identifier.
 * @return Free identifier.
 */
export function uniqueIdentifier(
	document: SchemaDocument,
	base: string
): string {
	const taken = new Set(
		( document?.fields ?? [] ).map( ( field ) => field?.id )
	);
	const candidate = identifierFrom( base );

	if ( ! taken.has( candidate ) ) {
		return candidate;
	}

	let suffix = 2;

	while ( taken.has( `${ candidate }_${ suffix }` ) ) {
		suffix += 1;
	}

	return `${ candidate }_${ suffix }`;
}

/**
 * Returns the next ordering position within a section.
 *
 * @param document Document.
 * @param section  Section identifier.
 * @return Next position.
 */
export function nextPosition(
	document: SchemaDocument,
	section: string
): number {
	let highest = 0;

	for ( const field of document?.fields ?? [] ) {
		if ( field?.section !== section ) {
			continue;
		}

		const position = Number( field.position ) || 0;

		if ( position > highest ) {
			highest = position;
		}
	}

	return highest + 10;
}

/**
 * Copies a document with a new field list.
 *
 * @param document Document.
 * @param fields   New field list.
 * @return New document.
 */
function withFields(
	document: SchemaDocument,
	fields: FieldDefinition[]
): SchemaDocument {
	return { ...document, fields };
}

/**
 * Builds a field definition from a picker choice.
 *
 * @param document Document the field will join.
 * @param choice   Picker choice.
 * @return Definition.
 */
export function buildField(
	document: SchemaDocument,
	choice: PickerChoice
): FieldDefinition {
	const label = choice.label ?? choice.type;
	const id = uniqueIdentifier( document, choice.idHint ?? label );
	const section = choice.section ?? DEFAULT_SECTION;

	const surfaces = surfacesFor( choice.supports );

	return {
		id,
		integration_id: `wc-checkoutsuite/${ id }`,
		origin: 'custom',
		type: choice.type,
		preset: choice.preset ?? null,
		label,
		description: choice.description ?? '',
		section,
		enabled: true,
		required: Boolean( choice.required ),
		position: nextPosition( document, section ),
		layout: { ...DEFAULT_LAYOUT, ...( choice.layout ?? {} ) },
		settings: choice.settings ?? {},
		mask: choice.mask ?? null,
		normalizer: null,
		conditions: {},
		hidden_value_policy: 'discard',
		...surfaces,
	};
}

/**
 * Builds a core field override from the WooCommerce inventory.
 *
 * The identifier is the WooCommerce field id, not a new one. The guard protects a
 * field because it is stored as core, and the server refuses an id that belongs
 * to WooCommerce unless the definition declares that origin, so adopting a field
 * has to keep the identity the store already uses.
 *
 * @param document Document the field will join.
 * @param core     Inventory entry.
 * @return Definition.
 */
export function buildCoreField(
	document: SchemaDocument,
	core: CoreFieldEntry
): FieldDefinition {
	// A WooCommerce field persists through the native flow, so the Suite does not
	// get to choose its storage scope: it records what actually happens.
	const surfaces = surfacesFor( { value: true } );

	return {
		id: core.id,
		integration_id: core.id,
		origin: 'core',
		type: core.type,
		preset: null,
		label: core.label,
		description: '',
		section: core.section,
		enabled: true,
		required: Boolean( core.required ),
		position:
			Number( core.priority ) || nextPosition( document, core.section ),
		layout: { ...DEFAULT_LAYOUT, ...( core.layout ?? {} ) },
		settings: {},
		mask: null,
		normalizer: null,
		conditions: {},
		hidden_value_policy: 'discard',
		...surfaces,
	};
}

/**
 * Adds a field chosen in the picker.
 *
 * @param document Document.
 * @param choice   Picker choice.
 * @return Result.
 */
export function createField(
	document: SchemaDocument,
	choice: PickerChoice
): OperationResult {
	// No collision check is needed: `uniqueIdentifier` already guarantees the id
	// is free, so a branch for "already exists" here would be unreachable code
	// pretending to be a safety net.
	const field = buildField( document, choice );

	return {
		ok: true,
		document: withFields( document, [
			...( document?.fields ?? [] ),
			field,
		] ),
		reason: '',
		field,
	};
}

/**
 * Adopts a WooCommerce core field so it can be customised.
 *
 * @param document Document.
 * @param core     Inventory entry.
 * @return Result.
 */
export function adoptCoreField(
	document: SchemaDocument,
	core: CoreFieldEntry
): OperationResult {
	const existing = ( document?.fields ?? [] ).find(
		( candidate ) => candidate?.id === core?.id
	);

	if ( existing ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__(
					'The WooCommerce field "%s" is already part of the schema.',
					'wc-checkoutsuite'
				),
				core.id
			),
		};
	}

	const field = buildCoreField( document, core );

	return {
		ok: true,
		document: withFields( document, [
			...( document?.fields ?? [] ),
			field,
		] ),
		reason: '',
		field,
	};
}

/**
 * Applies changes to one field.
 *
 * The identifier, the integration key and the origin are never taken from
 * `changes`. All three are part of what a stored definition means, and letting a
 * caller rewrite them would make the protection rule depend on the caller's good
 * manners.
 *
 * @param document Document.
 * @param id       Field identifier.
 * @param changes  Values to apply.
 * @return Result.
 */
export function updateField(
	document: SchemaDocument,
	id: string,
	changes: Partial< FieldDefinition >
): OperationResult {
	const fields = document?.fields ?? [];
	const index = fields.findIndex( ( field ) => field?.id === id );

	if ( index < 0 ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	const current = fields[ index ];
	const safe: Partial< FieldDefinition > = { ...changes };

	delete safe.id;
	delete safe.origin;
	delete safe.integration_id;

	if (
		isProtected( current ) &&
		undefined !== safe.type &&
		safe.type !== current.type
	) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__(
					'The type of "%s" cannot change: WooCommerce stores what this field holds.',
					'wc-checkoutsuite'
				),
				id
			),
		};
	}

	const updated: FieldDefinition = { ...current, ...safe };
	const next = [ ...fields ];
	next[ index ] = updated;

	return {
		ok: true,
		document: withFields( document, next ),
		reason: '',
		field: updated,
	};
}

/**
 * Duplicates a field under a new identifier.
 *
 * A duplicate starts archived. Copying an active field straight into the checkout
 * would double a form row the moment it is saved, and the merchant has not said
 * they want it yet.
 *
 * @param document Document.
 * @param id       Field identifier to copy.
 * @return Result.
 */
export function duplicateField(
	document: SchemaDocument,
	id: string
): OperationResult {
	const source = ( document?.fields ?? [] ).find(
		( field ) => field?.id === id
	);

	if ( ! source ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	const copyId = uniqueIdentifier( document, `${ id }_copy` );

	const copy: FieldDefinition = {
		...source,
		id: copyId,
		// A copy is never a core field, even when it was copied from one. Keeping
		// the core origin would make the duplicate permanently unremovable.
		origin: 'custom',
		integration_id: `wc-checkoutsuite/${ copyId }`,
		label: sprintf(
			/* translators: %s: label of the field being duplicated. */
			__( '%s (copy)', 'wc-checkoutsuite' ),
			source.label
		),
		enabled: false,
		position: nextPosition( document, source.section ),
	};

	return {
		ok: true,
		document: withFields( document, [
			...( document?.fields ?? [] ),
			copy,
		] ),
		reason: '',
		field: copy,
	};
}

/**
 * Archives a field, or restores an archived one.
 *
 * @param document Document.
 * @param id       Field identifier.
 * @param enabled  Target state.
 * @return Result.
 */
export function setFieldEnabled(
	document: SchemaDocument,
	id: string,
	enabled: boolean
): OperationResult {
	const field = ( document?.fields ?? [] ).find(
		( candidate ) => candidate?.id === id
	);

	if ( ! field ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	if ( ! enabled && isProtected( field ) ) {
		return { ok: false, document, reason: protectionReason( field ) };
	}

	return updateField( document, id, { enabled } );
}

/**
 * Archives a field.
 *
 * @param document Document.
 * @param id       Field identifier.
 * @return Result.
 */
export function archiveField(
	document: SchemaDocument,
	id: string
): OperationResult {
	return setFieldEnabled( document, id, false );
}

/**
 * Removes a field from the document permanently.
 *
 * Only custom fields can be removed. A core field is refused here and rejected by
 * the server if a caller ignores this.
 *
 * @param document Document.
 * @param id       Field identifier.
 * @return Result.
 */
export function removeField(
	document: SchemaDocument,
	id: string
): OperationResult {
	const fields = document?.fields ?? [];
	const field = fields.find( ( candidate ) => candidate?.id === id );

	if ( ! field ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	if ( isProtected( field ) ) {
		return { ok: false, document, reason: protectionReason( field ) };
	}

	return {
		ok: true,
		document: withFields(
			document,
			fields.filter( ( candidate ) => candidate?.id !== id )
		),
		reason: '',
	};
}

/**
 * Fields of a document grouped by section, in position order.
 *
 * @param document Document.
 * @return Groups.
 */
export function groupBySection(
	document: SchemaDocument
): Array< { section: string; fields: FieldDefinition[] } > {
	const groups = new Map< string, FieldDefinition[] >();

	for ( const field of document?.fields ?? [] ) {
		const section = field?.section ?? DEFAULT_SECTION;

		if ( ! groups.has( section ) ) {
			groups.set( section, [] );
		}

		groups.get( section )?.push( field );
	}

	return [ ...groups.entries() ].map( ( [ section, fields ] ) => ( {
		section,
		fields: [ ...fields ].sort(
			( a, b ) =>
				( Number( a.position ) || 0 ) - ( Number( b.position ) || 0 )
		),
	} ) );
}

/**
 * Counts the fields that are not archived.
 *
 * @param document Document.
 * @return Active field count.
 */
export function activeCount( document: SchemaDocument ): number {
	return ( document?.fields ?? [] ).filter( ( field ) => field?.enabled )
		.length;
}

/* ------------------------------------------------------------------- Order */

/**
 * Step between two positions.
 *
 * Positions are spaced so a later insertion can land between two neighbours
 * without renumbering the whole section.
 */
const POSITION_STEP = 10;

/**
 * Moves one item of a list by one place.
 *
 * Out-of-range moves return the list unchanged rather than throwing: a button at
 * the end of a list is disabled, and a keyboard shortcut at the end should do
 * nothing rather than fail.
 *
 * @param items     Current order.
 * @param id        Identifier to move.
 * @param direction `up` or `down`.
 * @return New order.
 */
function shift< T extends { id: string } >(
	items: T[],
	id: string,
	direction: 'up' | 'down'
): T[] {
	const index = items.findIndex( ( item ) => item.id === id );
	const target = 'up' === direction ? index - 1 : index + 1;

	if ( index < 0 || target < 0 || target >= items.length ) {
		return items;
	}

	const next = [ ...items ];
	const moved = next[ index ];

	next[ index ] = next[ target ];
	next[ target ] = moved;

	return next;
}

/**
 * Assigns sequential positions within each section.
 *
 * Reordering by swapping positions would leave ties behind and make the stored
 * order depend on the order entries happen to appear in the array. Renumbering
 * makes the saved order the order that was chosen.
 *
 * @param fields Fields, already in the intended order per section.
 * @return Fields with positions assigned.
 */
function renumber( fields: FieldDefinition[] ): FieldDefinition[] {
	const counters = new Map< string, number >();

	return fields.map( ( field ) => {
		const section = field.section ?? 'order';
		const next = ( counters.get( section ) ?? 0 ) + POSITION_STEP;

		counters.set( section, next );

		return { ...field, position: next };
	} );
}

/**
 * Fields of one section, in position order.
 *
 * @param document Document.
 * @param section  Section identifier.
 * @return Ordered fields.
 */
export function fieldsInSection(
	document: SchemaDocument,
	section: string
): FieldDefinition[] {
	return ( document.fields ?? [] )
		.filter( ( field ) => ( field.section ?? 'order' ) === section )
		.sort( ( a, b ) => ( a.position || 0 ) - ( b.position || 0 ) );
}

/**
 * Moves a field one place within its section.
 *
 * @param document  Document.
 * @param id        Field identifier.
 * @param direction `up` or `down`.
 * @return Result.
 */
export function moveField(
	document: SchemaDocument,
	id: string,
	direction: 'up' | 'down'
): OperationResult {
	const field = ( document.fields ?? [] ).find(
		( candidate ) => candidate.id === id
	);

	if ( ! field ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	const section = field.section ?? 'order';
	const ordered = fieldsInSection( document, section );
	const moved = shift( ordered, id, direction );

	if ( moved === ordered ) {
		return {
			ok: false,
			document,
			reason: __(
				'The field is already at the end of its section.',
				'wc-checkoutsuite'
			),
		};
	}

	// The reordered section is written back in place, so fields of other sections
	// keep the positions they had.
	const others = ( document.fields ?? [] ).filter(
		( candidate ) => ( candidate.section ?? 'order' ) !== section
	);

	return {
		ok: true,
		document: {
			...document,
			fields: renumber( [ ...others, ...moved ] ),
		},
		reason: '',
	};
}

/**
 * Moves a field to another section, at the end of it.
 *
 * @param document Document.
 * @param id       Field identifier.
 * @param section  Target section identifier.
 * @return Result.
 */
export function setFieldSection(
	document: SchemaDocument,
	id: string,
	section: string
): OperationResult {
	const field = ( document.fields ?? [] ).find(
		( candidate ) => candidate.id === id
	);

	if ( ! field ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: field identifier. */
				__( 'The field "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	if ( ( field.section ?? 'order' ) === section ) {
		return { ok: true, document, reason: '', field };
	}

	const others = ( document.fields ?? [] ).filter(
		( candidate ) => candidate.id !== id
	);

	return {
		ok: true,
		document: {
			...document,
			fields: renumber( [
				...others,
				{ ...field, section, position: Number.MAX_SAFE_INTEGER },
			] ),
		},
		reason: '',
		field: { ...field, section },
	};
}

/**
 * Titles a section for display when it is implied by its fields.
 *
 * @param location Location value.
 * @return Title.
 */
function titleFromLocation( location: string ): string {
	return location.charAt( 0 ).toUpperCase() + location.slice( 1 );
}

/**
 * Sections of a document, in display order, including the ones fields imply.
 *
 * A field may live in one of the five domain locations without the document
 * declaring a section for it — that is what adopting a WooCommerce field does.
 * Those sections still have to appear, or the field would vanish from the screen
 * while remaining in the schema.
 *
 * @param document Document.
 * @return Groups in order, each with whether it was declared.
 */
export function sectionGroups( document: SchemaDocument ): SectionGroup[] {
	const declared = [ ...( document.sections ?? [] ) ].sort(
		( a, b ) => ( a.position || 0 ) - ( b.position || 0 )
	);

	const groups = new Map< string, SectionGroup >();

	for ( const section of declared ) {
		groups.set( section.id, {
			section,
			declared: true,
			fields: [],
		} );
	}

	for ( const field of document.fields ?? [] ) {
		const id = field.section ?? 'order';

		if ( ! groups.has( id ) ) {
			groups.set( id, {
				section: {
					id,
					title: titleFromLocation( id ),
					description: '',
					position: Number.MAX_SAFE_INTEGER,
					location: id,
				},
				declared: false,
				fields: [],
			} );
		}

		groups.get( id )?.fields.push( field );
	}

	return [ ...groups.values() ].map( ( group ) => ( {
		...group,
		fields: [ ...group.fields ].sort(
			( a, b ) => ( a.position || 0 ) - ( b.position || 0 )
		),
	} ) );
}

/**
 * Returns an identifier for a section that is not already taken.
 *
 * @param document Document.
 * @param base     Candidate identifier.
 * @return Free identifier.
 */
export function uniqueSectionId(
	document: SchemaDocument,
	base: string
): string {
	const taken = new Set(
		( document.sections ?? [] ).map( ( section ) => section.id )
	);
	const candidate = identifierFrom( base );

	if ( ! taken.has( candidate ) ) {
		return candidate;
	}

	let suffix = 2;

	while ( taken.has( `${ candidate }_${ suffix }` ) ) {
		suffix += 1;
	}

	return `${ candidate }_${ suffix }`;
}

/**
 * Adds a section.
 *
 * @param document           Document.
 * @param choice             Section to add.
 * @param choice.title
 * @param choice.location
 * @param choice.description
 * @return Result.
 */
export function createSection(
	document: SchemaDocument,
	choice: { title: string; location: string; description?: string }
): OperationResult {
	const id = uniqueSectionId( document, choice.title );
	const highest = ( document.sections ?? [] ).reduce(
		( max, section ) => Math.max( max, Number( section.position ) || 0 ),
		0
	);

	const section: SectionDefinition = {
		id,
		title: choice.title,
		description: choice.description ?? '',
		position: highest + POSITION_STEP,
		location: choice.location,
	};

	return {
		ok: true,
		document: {
			...document,
			sections: [ ...( document.sections ?? [] ), section ],
		},
		reason: '',
	};
}

/**
 * Applies changes to a section.
 *
 * The identifier is never taken from `changes`: stored fields point at it, and
 * renaming a title must not orphan them.
 *
 * @param document Document.
 * @param id       Section identifier.
 * @param changes  Values to apply.
 * @return Result.
 */
export function updateSection(
	document: SchemaDocument,
	id: string,
	changes: Partial< SectionDefinition >
): OperationResult {
	const sections = document.sections ?? [];
	const index = sections.findIndex( ( section ) => section.id === id );

	if ( index < 0 ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: %s: section identifier. */
				__( 'The section "%s" does not exist.', 'wc-checkoutsuite' ),
				id
			),
		};
	}

	const safe = { ...changes };
	delete safe.id;

	const next = [ ...sections ];
	next[ index ] = { ...sections[ index ], ...safe };

	return { ok: true, document: { ...document, sections: next }, reason: '' };
}

/**
 * Removes a section, refusing while fields still belong to it.
 *
 * @param document Document.
 * @param id       Section identifier.
 * @return Result.
 */
export function removeSection(
	document: SchemaDocument,
	id: string
): OperationResult {
	const occupants = fieldsInSection( document, id );

	if ( occupants.length > 0 ) {
		return {
			ok: false,
			document,
			reason: sprintf(
				/* translators: 1: number of fields, 2: section identifier. */
				__(
					'%1$d field(s) still belong to "%2$s". Move them before removing the section.',
					'wc-checkoutsuite'
				),
				occupants.length,
				id
			),
		};
	}

	return {
		ok: true,
		document: {
			...document,
			sections: ( document.sections ?? [] ).filter(
				( section ) => section.id !== id
			),
		},
		reason: '',
	};
}

/**
 * Moves a section one place among the declared sections.
 *
 * @param document  Document.
 * @param id        Section identifier.
 * @param direction `up` or `down`.
 * @return Result.
 */
export function moveSection(
	document: SchemaDocument,
	id: string,
	direction: 'up' | 'down'
): OperationResult {
	const ordered = [ ...( document.sections ?? [] ) ].sort(
		( a, b ) => ( a.position || 0 ) - ( b.position || 0 )
	);
	const moved = shift( ordered, id, direction );

	if ( moved === ordered ) {
		return {
			ok: false,
			document,
			reason: __(
				'The section is already at the end.',
				'wc-checkoutsuite'
			),
		};
	}

	return {
		ok: true,
		document: {
			...document,
			sections: moved.map( ( section, index ) => ( {
				...section,
				position: ( index + 1 ) * POSITION_STEP,
			} ) ),
		},
		reason: '',
	};
}

/* -------------------------------------------------------------------- Bulk */

/**
 * A bulk operation's outcome, including what it deliberately left alone.
 *
 * The skipped list is not an error: archiving ten fields where three belong to
 * WooCommerce is a legitimate request, and the answer is that seven were
 * archived. Reporting the three as a failure would hide the seven that worked;
 * reporting nothing would hide the three.
 */
export interface BulkResult {
	ok: boolean;
	document: SchemaDocument;
	reason: string;
	applied: string[];
	skipped: Array< { id: string; reason: string } >;
}

/**
 * Applies one change to many fields.
 *
 * @param document Document.
 * @param ids      Identifiers to change.
 * @param change   Called per field; returns the changes, or a reason to skip.
 * @return Result.
 */
function bulk(
	document: SchemaDocument,
	ids: string[],
	change: ( field: FieldDefinition ) => Partial< FieldDefinition > | string
): BulkResult {
	const wanted = new Set( ids );
	const changes = new Map< string, Partial< FieldDefinition > >();
	const skipped: Array< { id: string; reason: string } > = [];

	// One pass decides, a second applies. Building the new list while deciding
	// would leave it assigned before the early return below, and the rule that
	// flags that is right: the variable may end up unused.
	for ( const field of document.fields ?? [] ) {
		if ( ! wanted.has( field.id ) ) {
			continue;
		}

		const outcome = change( field );

		if ( 'string' === typeof outcome ) {
			skipped.push( { id: field.id, reason: outcome } );

			continue;
		}

		changes.set( field.id, outcome );
	}

	if ( 0 === changes.size ) {
		return {
			ok: false,
			document,
			reason: __(
				'None of the selected fields could be changed.',
				'wc-checkoutsuite'
			),
			applied: [],
			skipped,
		};
	}

	const applied = [ ...changes.keys() ];
	const fields = ( document.fields ?? [] ).map( ( field ) => {
		const patch = changes.get( field.id );

		return undefined === patch ? field : { ...field, ...patch };
	} );

	return {
		ok: true,
		document: { ...document, fields },
		reason: '',
		applied,
		skipped,
	};
}

/**
 * Enables or archives many fields.
 *
 * A WooCommerce-owned field is skipped when archiving rather than refused: the
 * merchant asked for ten, and seven of them are perfectly archivable.
 *
 * @param document Document.
 * @param ids      Identifiers.
 * @param enabled  Target state.
 * @return Result.
 */
export function setFieldsEnabled(
	document: SchemaDocument,
	ids: string[],
	enabled: boolean
): BulkResult {
	return bulk( document, ids, ( field ) => {
		if ( ! enabled && isProtected( field ) ) {
			return __(
				'WooCommerce owns this field and it cannot be archived.',
				'wc-checkoutsuite'
			);
		}

		if ( field.enabled === enabled ) {
			return __( 'It is already in that state.', 'wc-checkoutsuite' );
		}

		return { enabled };
	} );
}

/**
 * Archives many fields without touching the ones WooCommerce owns.
 *
 * @param document Document.
 * @param ids      Identifiers.
 * @return Result.
 */
export function archiveFields(
	document: SchemaDocument,
	ids: string[]
): BulkResult {
	return setFieldsEnabled( document, ids, false );
}

/**
 * Moves many fields to a section.
 *
 * @param document Document.
 * @param ids      Identifiers.
 * @param section  Target section.
 * @return Result.
 */
export function moveFieldsToSection(
	document: SchemaDocument,
	ids: string[],
	section: string
): BulkResult {
	const result = bulk( document, ids, ( field ) => {
		if ( ( field.section ?? 'order' ) === section ) {
			return __( 'It is already in that section.', 'wc-checkoutsuite' );
		}

		return { section };
	} );

	if ( ! result.ok ) {
		return result;
	}

	// The moved fields go to the end of the list, the way moving one field does.
	// Leaving them where they were would interleave them with the fields that
	// were already in the target section, which is not what "move these there"
	// means.
	const moved = new Set( result.applied );

	return {
		...result,
		document: {
			...result.document,
			fields: renumber( [
				...result.document.fields.filter(
					( field ) => ! moved.has( field.id )
				),
				...result.document.fields.filter( ( field ) =>
					moved.has( field.id )
				),
			] ),
		},
	};
}

/**
 * Shows or hides many fields for one audience.
 *
 * @param document Document.
 * @param ids      Identifiers.
 * @param audience Audience key.
 * @param allowed  Target state.
 * @return Result.
 */
export function setFieldsVisibility(
	document: SchemaDocument,
	ids: string[],
	audience: string,
	allowed: boolean
): BulkResult {
	return bulk( document, ids, ( field ) => {
		if ( field.visibility?.[ audience ] === allowed ) {
			return __( 'It is already set that way.', 'wc-checkoutsuite' );
		}

		return {
			visibility: {
				...( field.visibility ?? {} ),
				[ audience ]: allowed,
			},
		};
	} );
}

/**
 * Reports what a bulk operation would do before it is applied.
 *
 * ROADMAP.md section 436 asks for bulk operations "com confirmação de impacto".
 * A confirmation that only says "3 fields will change" is not a confirmation of
 * impact: what the merchant needs to know is which of them will be left alone and
 * why.
 *
 * @param document Document.
 * @param ids      Identifiers.
 * @param action   Action being considered.
 * @return Impact description.
 */
export function bulkImpact(
	document: SchemaDocument,
	ids: string[],
	action: 'enable' | 'disable' | 'archive' | 'section' | 'visibility'
): {
	total: number;
	affected: number;
	protected: string[];
	unchanged: string[];
} {
	const wanted = new Set( ids );
	const selected = ( document.fields ?? [] ).filter( ( field ) =>
		wanted.has( field.id )
	);

	const guarded = selected.filter( ( field ) => isProtected( field ) );
	const protectedIds =
		'disable' === action || 'archive' === action
			? guarded.map( ( field ) => field.id )
			: [];

	const unchanged = selected
		.filter( ( field ) => {
			if ( 'enable' === action ) {
				return field.enabled;
			}

			if ( 'disable' === action || 'archive' === action ) {
				return ! field.enabled && ! isProtected( field );
			}

			return false;
		} )
		.map( ( field ) => field.id );

	return {
		total: selected.length,
		affected: selected.length - protectedIds.length - unchanged.length,
		protected: protectedIds,
		unchanged,
	};
}
