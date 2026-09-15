/**
 * The checkouts a store runs: one document, several compositions.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.4 gives the store
 * **profiles**, §6.3 gives the screen (`[ Checkout padrão ] [ Checkout digital ] … [+ Novo
 * checkout ]`) and §6.9 gives the two mechanisms that decide which one a cart gets: priority among
 * the profiles whose rule matches, and the store's own checkout as the fallback.
 *
 * Everything here is a pure function over the document's `profiles` list, for the same reason the
 * bindings module is: the screen owns one document, this decides what a change means, and nothing
 * here talks to the server.
 *
 * Three decisions are worth stating rather than reading off the code.
 *
 * 1. **The store's own checkout is not a profile.** It always exists, it is what the document
 *    already holds, and it is the answer when nothing matches. A profile is an *additional*
 *    composition — which is why creating one from `woocommerce_current` copies the current
 *    containers rather than referring to them: a reference would make editing one of them edit the
 *    other, and the merchant asked for one checkout that starts like this one.
 * 2. **The only fallback cannot be deleted.** §6.3 says so, and the reason is that the fallback is
 *    the profile that answers the carts nobody claimed: removing it would silently move every
 *    unmatched cart to a composition the merchant did not choose for them.
 * 3. **A minimal checkout is not an empty one.** §6.3 lists what has to be verified before a
 *    minimal composition is saved — gateway, taxes, shipping, what the integrations require and
 *    the legal rules — and this reports exactly which of them the store cannot answer for. It
 *    reports; the screen decides how loudly.
 *
 * @see ROADMAP.md sections 3.4, 6.3 and 6.9
 */

import type {
	ConditionNode,
	ConditionVocabulary,
	PreviewContext,
} from './conditions';
import { preview } from './conditions';
import type {
	FieldDefinition,
	SchemaDocument,
	SectionDefinition,
} from './types';

/**
 * One checkout composition.
 */
export interface CheckoutProfile {
	id: string;
	name: string;
	enabled: boolean;
	source: ProfileSource;
	priority: number;
	fallback: boolean;
	conditions: ConditionNode | Record< string, never >;
	sections: SectionDefinition[];
	presentation: Record< string, unknown >;
}

/** Where a profile's composition came from. A closed list (§3.4). */
export type ProfileSource =
	| 'woocommerce_current'
	| 'duplicate_profile'
	| 'minimal';

/** The sources, in the order the modal offers them. */
export const PROFILE_SOURCES: ProfileSource[] = [
	'woocommerce_current',
	'duplicate_profile',
	'minimal',
];

/** The source that may not be duplicated without naming a profile to copy. */
export const DUPLICATION_SOURCE: ProfileSource = 'duplicate_profile';

/** The area a profile composes. §6.4 composes the checkout, and only the checkout. */
export const COMPOSED_AREA = 'checkout';

/**
 * Whether a stored container is offered in the checkout.
 *
 * Exported and single, because two readers ask it and they must not answer differently: the minimal
 * checklist and the composition of §6.4 both need to know which containers the checkout owns. A
 * container with no `areas` at all is a checkout container — that is how the editor's own
 * `sectionGroups()` reads it, and every container this screen writes carries the list explicitly.
 *
 * @param section Stored container.
 * @return Whether the checkout receives it.
 */
export function isCheckoutSection( section: SectionDefinition ): boolean {
	return ! Array.isArray( section.areas )
		? true
		: section.areas.includes( COMPOSED_AREA );
}

/**
 * The containers of one composition, in the order the checkout receives them.
 *
 * The mirror of `CheckoutProfileResolver::compose()` on the server, and deliberately so: the screen
 * has to show the same composition the cart will get, and two rules for one question is how the
 * screen ends up editing something other than what the storefront renders. The rule is the one
 * §6.4 draws — a profile owns its checkout containers, the document keeps the containers of the
 * other destinations — so a container offered to the checkout belongs to the profile.
 *
 * @param document Document.
 * @param profile  Composition, or null for the store's own.
 * @return Containers in cart order.
 */
export function compositionSections(
	document: SchemaDocument,
	profile: CheckoutProfile | null
): SectionDefinition[] {
	const declared = document?.sections ?? [];

	if ( ! profile ) {
		return declared;
	}

	return [
		...( profile.sections ?? [] ),
		...declared.filter( ( section ) => ! isCheckoutSection( section ) ),
	];
}

/**
 * The document as the editor shows it while one composition is selected.
 *
 * A copy and not a mutation: `document` stays the single stored draft the screen owns, and the
 * composition is what the section list, the section inspector and the store's-checkout panel read
 * while a profile is on the strip. Without a profile this is the document itself, which is what
 * keeps the store's own checkout behaving exactly as it did before profiles existed.
 *
 * @param document Document.
 * @param profile  Composition, or null for the store's own.
 * @return Document to edit.
 */
export function compositionOf(
	document: SchemaDocument,
	profile: CheckoutProfile | null
): SchemaDocument {
	if ( ! profile ) {
		return document;
	}

	return { ...document, sections: compositionSections( document, profile ) };
}

/**
 * Writes an edited composition back into the draft.
 *
 * The checkout containers go to the profile and nothing else moves: the document's own containers,
 * its fields, its bindings and its other destinations are the merchant's work and are not a
 * composition's to rewrite. Without a profile the edited document is the draft, which is the case
 * §6.2 has always described.
 *
 * @param document  Draft the edit started from.
 * @param profileId Composition being edited, or an empty string for the store's own.
 * @param composed  Document the edit produced.
 * @return Draft with the composition updated.
 */
export function withComposition(
	document: SchemaDocument,
	profileId: string,
	composed: SchemaDocument
): SchemaDocument {
	if ( ! profileId ) {
		return composed;
	}

	const owned = ( composed?.sections ?? [] ).filter( isCheckoutSection );

	return {
		...document,
		profiles: ( document?.profiles ?? [] ).map( ( entry ) =>
			entry.id === profileId ? { ...entry, sections: owned } : entry
		),
	};
}

/**
 * The profiles of a document.
 *
 * A document written before profiles existed has none, and an empty list is the honest answer:
 * the store runs its own checkout.
 *
 * @param document Document.
 * @return Profiles.
 */
export function profilesOf(
	document: Partial< SchemaDocument > | null
): CheckoutProfile[] {
	const stored = ( document as { profiles?: unknown } | null )?.profiles;

	return Array.isArray( stored ) ? ( stored as CheckoutProfile[] ) : [];
}

/**
 * A copy of the document carrying a different profile list.
 *
 * @param document Document.
 * @param profiles Profiles.
 * @return Document.
 */
export function withProfiles< T extends Partial< SchemaDocument > >(
	document: T,
	profiles: CheckoutProfile[]
): T {
	return { ...document, profiles } as T;
}

/**
 * The identifier a new profile gets.
 *
 * Derived from the name so a merchant can read it, and numbered when it collides: two profiles
 * with the same id would be two answers to one name, which is what the validator refuses.
 *
 * @param profiles Existing profiles.
 * @param name     Name the merchant typed.
 * @return Identifier.
 */
export function uniqueProfileId(
	profiles: CheckoutProfile[],
	name: string
): string {
	const base =
		name
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.slice( 0, 40 ) || 'checkout';

	const taken = new Set( profiles.map( ( profile ) => profile.id ) );

	if ( ! taken.has( base ) ) {
		return base;
	}

	let suffix = 2;

	while ( taken.has( `${ base }_${ suffix }` ) ) {
		suffix += 1;
	}

	return `${ base }_${ suffix }`;
}

/**
 * Where a new composition starts from.
 *
 * @param source   Source the merchant chose.
 * @param document Document the screen holds.
 * @param from     Profile to copy, when the source is a duplication.
 * @return Containers the new profile starts with.
 */
export function startingSections(
	source: ProfileSource,
	document: Partial< SchemaDocument >,
	from: CheckoutProfile | null
): SectionDefinition[] {
	if ( DUPLICATION_SOURCE === source && from ) {
		return from.sections.map( ( section ) => ( { ...section } ) );
	}

	if ( 'minimal' === source ) {
		/*
		 * A minimal checkout keeps the containers the checkout cannot do without and drops the
		 * rest: what the merchant adds back is a decision, and starting from everything would
		 * make "minimal" mean "the same, but called minimal".
		 */
		return checkoutSections( document ).filter(
			( section ) =>
				'contact' === section.location || 'billing' === section.location
		);
	}

	return checkoutSections( document );
}

/**
 * The containers of a document that are offered in the checkout.
 *
 * @param document Document.
 * @return Containers.
 */
export function checkoutSections(
	document: Partial< SchemaDocument >
): SectionDefinition[] {
	const sections = Array.isArray( document.sections )
		? document.sections
		: [];

	return sections.filter( isCheckoutSection );
}

/**
 * Creates a profile.
 *
 * @param document Document the screen holds.
 * @param name     Name the merchant typed.
 * @param source   Where the composition starts from.
 * @param from     Profile to copy, when duplicating.
 * @return The new profile.
 */
export function createProfile(
	document: Partial< SchemaDocument >,
	name: string,
	source: ProfileSource,
	from: CheckoutProfile | null = null
): CheckoutProfile {
	const profiles = profilesOf( document );

	return {
		id: uniqueProfileId( profiles, name ),
		name,
		enabled: true,
		source,
		priority: nextPriority( profiles ),
		fallback: false,
		conditions: {},
		sections: startingSections( source, document, from ),
		presentation: {},
	};
}

/**
 * The priority a new profile gets, so it is considered before the ones that already exist.
 *
 * Higher is considered first (§6.9). A new profile that landed last would be configuration the
 * merchant has to go and make reachable before it does anything.
 *
 * @param profiles Existing profiles.
 * @return Priority.
 */
export function nextPriority( profiles: CheckoutProfile[] ): number {
	return (
		profiles.reduce(
			( highest, profile ) =>
				Math.max( highest, Number( profile.priority ) || 0 ),
			0
		) + 10
	);
}

/**
 * Replaces one profile.
 *
 * @param profiles Existing profiles.
 * @param id       Identifier.
 * @param changes  Fields to change.
 * @return Profiles.
 */
export function updateProfile(
	profiles: CheckoutProfile[],
	id: string,
	changes: Partial< CheckoutProfile >
): CheckoutProfile[] {
	return profiles.map( ( profile ) =>
		profile.id === id ? { ...profile, ...changes, id: profile.id } : profile
	);
}

/**
 * Removes a profile, unless it is the only fallback.
 *
 * The reason travels as a stable code rather than as a sentence: the screen owns the wording, the
 * rule belongs here, and a caller that wants to say why can translate the one it got.
 *
 * @param profiles Existing profiles.
 * @param id       Identifier.
 * @return The list, and the code of the refusal when it was not changed.
 */
export function removeProfile(
	profiles: CheckoutProfile[],
	id: string
): { profiles: CheckoutProfile[]; refusal: 'only_fallback' | null } {
	const target = profiles.find( ( profile ) => profile.id === id ) ?? null;

	if ( ! target ) {
		return { profiles, refusal: null };
	}

	if ( target.fallback && 1 === fallbacks( profiles ).length ) {
		return { profiles, refusal: 'only_fallback' };
	}

	return {
		profiles: profiles.filter( ( profile ) => profile.id !== id ),
		refusal: null,
	};
}

/**
 * The profiles marked as the fallback.
 *
 * @param profiles Profiles.
 * @return The fallbacks.
 */
export function fallbacks( profiles: CheckoutProfile[] ): CheckoutProfile[] {
	return profiles.filter( ( profile ) => profile.fallback );
}

/**
 * Marks one profile as the fallback, and no other.
 *
 * The validator refuses two fallbacks rather than picking one, because two would leave the answer
 * to whichever the array happened to put first. The screen therefore makes the choice exclusive
 * where the merchant makes it, instead of letting them write something the store will refuse.
 *
 * @param profiles Profiles.
 * @param id       Identifier, or an empty string to have no fallback profile.
 * @return Profiles.
 */
export function setFallback(
	profiles: CheckoutProfile[],
	id: string
): CheckoutProfile[] {
	return profiles.map( ( profile ) => ( {
		...profile,
		fallback: '' !== id && profile.id === id,
	} ) );
}

/**
 * Moves a profile one place up or down the priority order.
 *
 * The list is shown by priority (§6.9) and the merchant reorders what they see. Two profiles with
 * the same priority keep the order they were declared in, so a move between equal priorities swaps
 * the positions in the list rather than a number nobody can see.
 *
 * @param profiles Profiles.
 * @param id       Identifier.
 * @param delta    -1 for earlier, 1 for later.
 * @return Profiles.
 */
export function moveProfile(
	profiles: CheckoutProfile[],
	id: string,
	delta: number
): CheckoutProfile[] {
	const ordered = orderedProfiles( profiles );
	const index = ordered.findIndex( ( profile ) => profile.id === id );

	if ( index < 0 ) {
		return profiles;
	}

	const target = index + delta;

	if ( target < 0 || target >= ordered.length ) {
		return profiles;
	}

	const moved = ordered.slice();
	const [ entry ] = moved.splice( index, 1 );

	moved.splice( target, 0, entry );

	/*
	 * Priorities are rewritten from the order the merchant now sees, highest first, so the screen
	 * and the store agree about what "first" means. The step keeps the numbers readable and leaves
	 * room for a new profile between two of them.
	 */
	return moved.map( ( profile, position ) => ( {
		...profile,
		priority: ( moved.length - position ) * 10,
	} ) );
}

/**
 * The profiles in the order they are considered, best first.
 *
 * @param profiles Profiles.
 * @return Ordered profiles.
 */
export function orderedProfiles(
	profiles: CheckoutProfile[]
): CheckoutProfile[] {
	return profiles
		.map( ( profile, index ) => ( { profile, index } ) )
		.sort( ( a, b ) => {
			const byPriority =
				( Number( b.profile.priority ) || 0 ) -
				( Number( a.profile.priority ) || 0 );

			return 0 !== byPriority ? byPriority : a.index - b.index;
		} )
		.map( ( entry ) => entry.profile );
}

/**
 * Whether a profile's rule is a rule at all.
 *
 * @param profile Profile.
 * @return Whether it has one.
 */
export function hasRule( profile: CheckoutProfile ): boolean {
	const condition = profile.conditions as ConditionNode | null;

	return (
		null !== condition &&
		'object' === typeof condition &&
		0 < Object.keys( condition ).length
	);
}

/**
 * The profiles a sample context selects, and therefore the overlaps it exposes.
 *
 * §6.9 asks for the overlap to be **shown**, not refused: two profiles that match the same cart are
 * a decision the merchant is allowed to make — priority resolves it — but one they should see while
 * making it. The values come from the merchant, because a store that guessed the cart would be
 * previewing a store that does not exist.
 *
 * @param profiles   Profiles.
 * @param context    Sample values.
 * @param vocabulary Condition vocabulary.
 * @return The pairs that both matched, best first.
 */
export function overlapsFor(
	profiles: CheckoutProfile[],
	context: PreviewContext,
	vocabulary: ConditionVocabulary
): Array< { profile: CheckoutProfile; other: CheckoutProfile } > {
	const matching = orderedProfiles( profiles ).filter( ( profile ) => {
		if ( ! profile.enabled ) {
			return false;
		}

		if ( ! hasRule( profile ) ) {
			// A profile with no rule is the checkout for every cart it can be selected for.
			return true;
		}

		return preview(
			profile.conditions as ConditionNode,
			context,
			vocabulary
		).matches;
	} );

	const pairs: Array< { profile: CheckoutProfile; other: CheckoutProfile } > =
		[];

	matching.forEach( ( profile, index ) => {
		matching.slice( index + 1 ).forEach( ( other ) => {
			pairs.push( { profile, other } );
		} );
	} );

	return pairs;
}

/**
 * What a minimal checkout has to be able to answer before it is saved.
 *
 * §6.3: "não significa ignorar obrigações técnicas". Four of the entries are facts about the
 * **store** — the server reads them from WooCommerce and the browser never guesses them. The
 * fifth is about the **composition**: a field the store requires has to have a container in this
 * checkout to be filled in, or an integration that needs it loses it. That one is answered here,
 * where the composition is known.
 */
export interface MinimalFacts {
	gateway: boolean;
	taxes: boolean;
	shipping: boolean;
	legal: boolean;
}

/** One thing a minimal checkout must not ignore. */
export interface MinimalRequirement {
	key: keyof MinimalFacts | 'integrations';
	label: string;
	reason: string;
	met: boolean;
}

/**
 * The requirements a minimal composition has to satisfy.
 *
 * @param profile Profile being checked.
 * @param fields  Fields of the document.
 * @param facts   What the store reports.
 * @return Every requirement, met or not, in the order §6.3 lists them.
 */
export function minimalChecklist(
	profile: CheckoutProfile,
	fields: Array< Partial< FieldDefinition > >,
	facts: MinimalFacts
): MinimalRequirement[] {
	const containers = new Set(
		( profile.sections ?? [] ).map( ( section ) => section.id )
	);

	const dropped = ( fields ?? [] ).filter(
		( field ) =>
			field.enabled &&
			field.required &&
			! containers.has( field.section ?? '' )
	);

	const requirements: Array< Omit< MinimalRequirement, 'met' > > = [
		{
			key: 'gateway',
			label: 'Meio de pagamento',
			reason: 'Um checkout sem um meio de pagamento disponível não consegue fechar um pedido.',
		},
		{
			key: 'taxes',
			label: 'Impostos',
			reason: 'Os impostos configurados na loja continuam a ser calculados neste checkout.',
		},
		{
			key: 'shipping',
			label: 'Entrega',
			reason: 'Uma composição que não pergunta o endereço não pode ser a única de uma loja que entrega.',
		},
		{
			key: 'integrations',
			label: 'Dados exigidos pelas integrações',
			reason:
				0 === dropped.length
					? 'Os campos obrigatórios continuam a ser recolhidos nesta composição.'
					: `Campos obrigatórios fora desta composição: ${ dropped
							.map( ( field ) => field.label ?? field.id ?? '' )
							.join( ', ' ) }.`,
		},
		{
			key: 'legal',
			label: 'Regras legais',
			reason: 'As regras legais configuradas têm de continuar a poder ser cumpridas.',
		},
	];

	return requirements.map( ( requirement ) => ( {
		...requirement,
		met:
			'integrations' === requirement.key
				? 0 === dropped.length
				: Boolean( facts[ requirement.key as keyof MinimalFacts ] ),
	} ) );
}

/**
 * Whether a profile is the minimal composition.
 *
 * @param profile Profile.
 * @return Whether the checklist applies.
 */
export function needsChecklist( profile: CheckoutProfile ): boolean {
	return 'minimal' === profile.source;
}

/**
 * The profiles a document holds, with the profiles that do not decide anything removed.
 *
 * Used when a document is read from a store that wrote profiles before the screen understood them:
 * an entry that is not an object is not a composition, and showing it would be showing something
 * no cart can select.
 *
 * @param document Document.
 * @return Profiles.
 */
export function readableProfiles(
	document: Partial< SchemaDocument >
): CheckoutProfile[] {
	return profilesOf( document ).filter(
		( profile ) =>
			null !== profile &&
			'object' === typeof profile &&
			'string' === typeof profile.id &&
			'' !== profile.id
	);
}
