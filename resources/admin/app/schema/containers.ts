/**
 * Canonical frontend write model for containers.
 *
 * `ContainerDefinition` is the server name for a section. The admin used to write
 * `title`, `areas` and `location` directly, which made every new destination need a
 * special case. These helpers keep one write shape while retaining the legacy projection
 * until old documents have completed their migration.
 */

import type {
	ContainerDefinition,
	SchemaDocument,
	SectionDefinition,
} from './types';

export type ContainerDraft = Partial< SectionDefinition > & {
	name?: string;
	destination?: string;
};

export function containerName( container: ContainerDraft ): string {
	return ( container.name ?? container.title ?? container.id ?? '' ).trim();
}

export function containerDestination( container: ContainerDraft ): string {
	return container.destination ?? container.areas?.[ 0 ] ?? 'checkout';
}

export function containerDestinations( container: ContainerDraft ): string[] {
	if ( Array.isArray( container.areas ) ) {
		return container.areas;
	}

	if ( container.destination ) {
		return [ container.destination ];
	}

	// A pre-canonical section omitted `areas`; the old contract meant Checkout.
	return [ 'checkout' ];
}

/**
 * Makes a canonical container payload and includes the compatibility projection.
 *
 * @param container Existing container values.
 * @param changes   Values being changed.
 * @return Canonical container.
 */
export function containerPayload(
	container: ContainerDraft,
	changes: ContainerDraft = {}
): ContainerDefinition {
	const next = { ...container, ...changes };
	const name = containerName( next );
	const destination = containerDestination( next );
	const areas = containerDestinations( next );

	return {
		...( next as SectionDefinition ),
		id: next.id ?? '',
		name,
		title: name,
		destination,
		description: next.description ?? '',
		position: Number( next.position ?? 10 ),
		location: next.location ?? destination,
		areas,
		enabled: next.enabled ?? true,
		show_title:
			typeof next.show_title === 'boolean'
				? next.show_title
				: next.presentation?.show_title !== false,
		display_title: next.display_title ?? name,
		icon: next.icon ?? 'fields',
		// The destination is the surface (for example `customer_account`), while
		// target is the logical insertion point understood by the adapters. New
		// containers therefore inherit the explicit target, then their logical
		// location, and only lastly the destination for legacy callers that supplied
		// neither. Using the destination here makes every non-checkout container
		// impossible to save because it is not a valid SectionLocation.
		target: next.target ?? next.location ?? destination,
		settings: next.settings ?? {},
	};
}

export function updateContainer(
	document: SchemaDocument,
	id: string,
	changes: ContainerDraft
): SchemaDocument {
	return {
		...document,
		sections: ( document.sections ?? [] ).map( ( section ) =>
			section.id === id ? containerPayload( section, changes ) : section
		),
	};
}

export function createContainer(
	document: SchemaDocument,
	container: ContainerDraft
): ContainerDefinition {
	const highest = ( document.sections ?? [] ).reduce(
		( max, section ) => Math.max( max, Number( section.position ) || 0 ),
		0
	);

	return containerPayload( {
		...container,
		id: container.id ?? '',
		position: highest + 10,
	} );
}
