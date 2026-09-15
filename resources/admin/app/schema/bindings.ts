/**
 * The uses of a field: one definition, several places.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.3 makes the
 * *binding* its own concept. One field may be used several times, each use with its own
 * container, order, title, visible/editable decision and permissions — and the destination
 * map cannot express that, because it holds exactly one entry per destination.
 *
 * Two rules shape what is here:
 *
 * 1. **The list is the authority and the map is its projection.** The editor reads the list
 *    the server sent, edits it, and writes both back in the shape the server writes:
 *    `bindings[]` plus the `destinations` map derived from it. A surface that still reads
 *    the map therefore sees the same configuration, and no two answers exist.
 * 2. **A document written before the split is read through the map.** When the server sends
 *    no list — a draft written by an older version of this screen — each *enabled* link is
 *    one use, read exactly as the server reads it: `mode` decides `editable`, `actions` are
 *    the permissions.
 *
 * Nothing here talks to the server. These are pure functions over a field, which is what
 * lets the screen keep one document as the source of truth and compare revisions for the
 * unsaved-work guard.
 *
 * @see ROADMAP.md section 4
 */

import type { DestinationLink, FieldBinding, FieldDefinition } from './types';

/**
 * The identifier of one use, derived the way the server derives it.
 *
 * Field, destination and container are what identify a use, so the same three always give
 * the same name. Two uses of one field in one container and destination would collide —
 * which the server refuses, and which the screen avoids by numbering the second use.
 *
 * @param fieldId     Field identifier.
 * @param destination Destination key.
 * @param containerId Container identifier.
 * @return Identifier.
 */
export function bindingIdentifier(
	fieldId: string,
	destination: string,
	containerId: string
): string {
	return (
		fieldId + '@' + destination + ( containerId ? '/' + containerId : '' )
	);
}

/**
 * One destination link, read as a use.
 *
 * @param field       Field the use belongs to.
 * @param destination Destination key.
 * @param link        The link.
 * @return The use.
 */
function fromLink(
	field: FieldDefinition,
	destination: string,
	link: DestinationLink
): FieldBinding {
	return {
		id: bindingIdentifier( field.id, destination, link.section ?? '' ),
		field_id: field.id,
		container_id: link.section ?? '',
		destination,
		position: typeof link.position === 'number' ? link.position : null,
		visible: true,
		editable: 'view' !== ( link.mode ?? 'edit' ),
		label_override: link.title ?? '',
		permissions: link.actions ?? [],
	};
}

/**
 * Every use of a field, in the shape the document stores them.
 *
 * The list the server sent is read as it is; a document written before the split is read
 * through its map, one use per enabled destination.
 *
 * @param field Field definition.
 * @return The uses.
 */
export function bindingsOf( field: FieldDefinition ): FieldBinding[] {
	if ( Array.isArray( field.bindings ) ) {
		return field.bindings;
	}

	const destinations = field.destinations ?? {};

	return Object.keys( destinations )
		.filter( ( key ) => Boolean( destinations[ key ]?.enabled ) )
		.map( ( key ) => fromLink( field, key, destinations[ key ] ) );
}

/**
 * The uses of one field in one destination.
 *
 * @param field       Field definition.
 * @param destination Destination key.
 * @return The uses, in the order they are stored.
 */
export function bindingsFor(
	field: FieldDefinition,
	destination: string
): FieldBinding[] {
	return bindingsOf( field ).filter(
		( binding ) => binding.destination === destination
	);
}

/**
 * One use, as the destination map reads it.
 *
 * @param binding The use.
 * @return The link.
 */
export function linkOf( binding: FieldBinding ): DestinationLink {
	return {
		enabled: true,
		...( binding.container_id ? { section: binding.container_id } : {} ),
		...( binding.label_override ? { title: binding.label_override } : {} ),
		...( typeof binding.position === 'number'
			? { position: binding.position }
			: {} ),
		...( binding.permissions && binding.permissions.length
			? { actions: binding.permissions }
			: {} ),
		mode: binding.editable ? 'edit' : 'view',
	};
}

/**
 * The map a list of uses projects to.
 *
 * The map is a projection, so it carries what the list justifies and nothing else: a
 * destination the list no longer uses is not left saying it is on, which is what turning a
 * destination off has to mean. What *is* kept is a link that is off — configuration the
 * merchant typed, inert, and still theirs. Where more than one use exists for a destination
 * the map carries the last of them, which is all it can say; the list is what says the rest.
 *
 * @param field    Field the uses belong to.
 * @param bindings The uses.
 * @return The destination map.
 */
export function mapOf(
	field: FieldDefinition,
	bindings: FieldBinding[]
): Record< string, DestinationLink > {
	const map: Record< string, DestinationLink > = {};

	Object.entries( field.destinations ?? {} ).forEach( ( [ key, link ] ) => {
		if ( ! link?.enabled ) {
			map[ key ] = link;
		}
	} );

	bindings.forEach( ( binding ) => {
		map[ binding.destination ] = linkOf( binding );
	} );

	return map;
}

/**
 * The two shapes written back together, as the server writes them.
 *
 * @param field    Field the uses belong to.
 * @param bindings The uses.
 * @return The keys to write on the field.
 */
export function withBindings(
	field: FieldDefinition,
	bindings: FieldBinding[]
): Pick< FieldDefinition, 'bindings' | 'destinations' > {
	return {
		bindings,
		destinations: mapOf( field, bindings ),
	};
}

/**
 * A new use, added to the end of a destination's list.
 *
 * The identifier is made unique inside the field: two uses of one field in one container and
 * destination would otherwise answer to the same name, which the server refuses rather than
 * guessing which one was meant.
 *
 * @param field       Field the use belongs to.
 * @param destination Destination key.
 * @param containerId Container the use sits in.
 * @return The uses, with the new one last.
 */
export function addBinding(
	field: FieldDefinition,
	destination: string,
	containerId: string
): FieldBinding[] {
	const bindings = bindingsOf( field );
	const siblings = bindings.filter(
		( binding ) => binding.destination === destination
	);
	const base = bindingIdentifier( field.id, destination, containerId );

	let identifier = base;
	let suffix = 2;

	while ( bindings.some( ( binding ) => binding.id === identifier ) ) {
		identifier = base + '#' + suffix;
		suffix += 1;
	}

	const last = siblings[ siblings.length - 1 ];
	const position =
		typeof last?.position === 'number' ? last.position + 10 : 10;

	return [
		...bindings,
		{
			id: identifier,
			field_id: field.id,
			container_id: containerId,
			destination,
			position,
			visible: true,
			editable: true,
			label_override: '',
			permissions: [],
		},
	];
}

/**
 * One use, with changes applied.
 *
 * A change to the container renames the use, because the identifier is how a surface asks
 * for it and the container is part of what it names. The renamed identifier is kept unique
 * against the other uses of the same field.
 *
 * @param field   Field the uses belong to.
 * @param id      Identifier of the use.
 * @param changes What changed.
 * @return The uses.
 */
export function updateBinding(
	field: FieldDefinition,
	id: string,
	changes: Partial< FieldBinding >
): FieldBinding[] {
	const bindings = bindingsOf( field );
	const target = bindings.find( ( binding ) => binding.id === id );

	if ( ! target ) {
		return bindings;
	}

	const next = { ...target, ...changes };
	const recognised = [
		...new Set(
			bindings
				.filter( ( binding ) => binding.id !== id )
				.map( ( binding ) => binding.id )
		),
	];

	if ( typeof changes.container_id === 'string' ) {
		let identifier = bindingIdentifier(
			field.id,
			next.destination,
			changes.container_id
		);

		if ( recognised.includes( identifier ) ) {
			let suffix = 2;

			while ( recognised.includes( identifier + '#' + suffix ) ) {
				suffix += 1;
			}

			identifier = identifier + '#' + suffix;
		}

		next.id = identifier;
	}

	return bindings.map( ( binding ) =>
		binding.id === id ? next : binding
	);
}

/**
 * One use, removed.
 *
 * Removing every use of a destination is how a destination is turned off: in the final
 * model there is no switch beside a link, because "shown there" and "used there" are the
 * same statement.
 *
 * @param field Field the uses belong to.
 * @param id    Identifier of the use.
 * @return The uses.
 */
export function removeBinding(
	field: FieldDefinition,
	id: string
): FieldBinding[] {
	return bindingsOf( field ).filter( ( binding ) => binding.id !== id );
}

/**
 * Every use of one destination, removed.
 *
 * @param field       Field the uses belong to.
 * @param destination Destination key.
 * @return The uses.
 */
export function removeDestinationBindings(
	field: FieldDefinition,
	destination: string
): FieldBinding[] {
	return bindingsOf( field ).filter(
		( binding ) => binding.destination !== destination
	);
}

/**
 * The uses of a field, with at least one in this destination.
 *
 * Turning a destination on is the same statement as using the field there, so the switch and
 * the bulk action both end up here. The first use names no container, which means the field's
 * own section — the same meaning an untouched link always had.
 *
 * @param field       Field the uses belong to.
 * @param destination Destination key.
 * @return The uses.
 */
export function ensureBinding(
	field: FieldDefinition,
	destination: string
): FieldBinding[] {
	if ( bindingsFor( field, destination ).length > 0 ) {
		return bindingsOf( field );
	}

	return addBinding( field, destination, '' );
}

/**
 * The uses of a field, rebind to another field.
 *
 * A duplicate is a new field: the uses it was copied from name the original, and the server
 * refuses a use that belongs to a field other than the one it is listed under. The
 * identifiers are derived again for the same reason they are derived at all — they say which
 * field, destination and container the use is.
 *
 * @param bindings The uses being copied.
 * @param fieldId  Identifier of the field they now belong to.
 * @return The uses, rebind.
 */
export function rebindFor(
	bindings: FieldBinding[],
	fieldId: string
): FieldBinding[] {
	const used = new Set< string >();

	return bindings.map( ( binding ) => {
		const base = bindingIdentifier(
			fieldId,
			binding.destination,
			binding.container_id
		);

		let identifier = base;
		let suffix = 2;

		while ( used.has( identifier ) ) {
			identifier = base + '#' + suffix;
			suffix += 1;
		}

		used.add( identifier );

		return { ...binding, field_id: fieldId, id: identifier };
	} );
}

/**
 * Whether a destination carries at least one use.
 *
 * @param field       Field the uses belong to.
 * @param destination Destination key.
 * @return Whether it is on.
 */
export function destinationIsOn(
	field: FieldDefinition,
	destination: string
): boolean {
	return bindingsFor( field, destination ).length > 0;
}
