/**
 * The checkout the store already runs, next to the document that configures it.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.2 is the rule
 * this module exists for: the screen must load the **real** checkout of the store and not
 * start empty. The sections and fields below are not a template — they are read live from
 * WooCommerce through the inventory, so a field another plugin added appears here too, and a
 * store whose checkout has an extra section is not described by a hard-coded list.
 *
 * Three statements are kept apart on purpose:
 *
 * 1. **The store's checkout** — what WooCommerce will render, whatever this plugin does.
 * 2. **Managed** — a definition with the same identifier exists in the document, so the
 *    merchant can configure it here. Adopting is how a native field becomes managed, and it
 *    is *not* a copy: the value keeps living in the WooCommerce field (ADR-0001), and the
 *    definition records the override.
 * 3. **Removed from this store's checkout** — out of scope here: the Suite never deletes a
 *    WooCommerce field, and a field that disappears from the inventory simply stops being
 *    listed.
 *
 * Nothing here writes. `adoptCoreSection` is the one operation, and it delegates to the
 * operation that adopts a single field so there is one rule for what adopting means.
 *
 * @see ROADMAP.md sections 6 and 7
 */

import { adoptCoreField } from './fieldOperations';

import type {
	CoreFieldEntry,
	CoreFieldInventory,
	FieldDefinition,
	OperationResult,
	SchemaDocument,
} from './types';

/**
 * One field of the real checkout, and whether this document manages it.
 */
export interface CoreFieldState {
	entry: CoreFieldEntry;
	managed: boolean;
	definition?: FieldDefinition;
}

/**
 * One section of the real checkout, with how much of it is managed.
 */
export interface CoreSectionState {
	key: string;
	label: string;
	fields: CoreFieldState[];
	managed: number;
	total: number;
}

/**
 * Whether the store's checkout could be read at all.
 *
 * An inventory that could not be read is not an empty checkout: the screen has to say which
 * one it is, which is why the caller asks this instead of counting zero fields.
 *
 * @param inventory Inventory, when it was read.
 * @return Whether there is a real checkout to show.
 */
export function hasCoreCheckout(
	inventory?: CoreFieldInventory | null
): boolean {
	if ( ! inventory?.available ) {
		return false;
	}

	// The inventory reports the same fields twice — grouped by section and flat — and a
	// checkout that was read successfully has at least one of them. Asking only the flat
	// list would call a readable checkout empty, which is the state this module exists to
	// tell apart.
	return Boolean( inventory.fields?.length || inventory.sections?.length );
}

/**
 * The real checkout, section by section, with what this document already manages.
 *
 * The sections come in the order WooCommerce declares them, and the fields of each section
 * in WooCommerce's own priority order: this is a report of what the store runs, and
 * reordering it here would be a second opinion about the checkout.
 *
 * @param inventory Inventory, when it was read.
 * @param document  Document being edited.
 * @return Sections, empty when the inventory could not be read.
 */
export function coreCheckout(
	inventory: CoreFieldInventory | null | undefined,
	document: SchemaDocument | null | undefined
): CoreSectionState[] {
	if ( ! hasCoreCheckout( inventory ) ) {
		return [];
	}

	const managed = new Map< string, FieldDefinition >();

	( document?.fields ?? [] ).forEach( ( field ) => {
		if ( field?.id ) {
			managed.set( field.id, field );
		}
	} );

	return ( inventory?.sections ?? [] ).map( ( section ) => {
		const fields = ( section.fields ?? [] ).map( ( entry ) => ( {
			entry,
			managed: managed.has( entry.id ),
			definition: managed.get( entry.id ),
		} ) );

		return {
			key: section.key,
			label: section.label,
			fields,
			managed: fields.filter( ( field ) => field.managed ).length,
			total: fields.length,
		};
	} );
}

/**
 * The fields of the real checkout this document does not manage yet.
 *
 * @param inventory Inventory.
 * @param document  Document.
 * @return Entries that can still be adopted.
 */
export function unmanagedCoreFields(
	inventory: CoreFieldInventory | null | undefined,
	document: SchemaDocument | null | undefined
): CoreFieldEntry[] {
	const entries: CoreFieldEntry[] = [];

	coreCheckout( inventory, document ).forEach( ( section ) => {
		section.fields.forEach( ( field ) => {
			if ( ! field.managed ) {
				entries.push( field.entry );
			}
		} );
	} );

	return entries;
}

/**
 * Adopts every field of one section of the real checkout that is not managed yet.
 *
 * One action, one document: the merchant who says "manage this part of my checkout" gets the
 * whole section, in the order WooCommerce runs it, and does not have to add the fields back
 * one by one — which is exactly the reconstruction §6.2 forbids. Fields already managed are
 * left as they are, so running this again changes nothing.
 *
 * @param document Document.
 * @param section  Section of the real checkout.
 * @return Result, with the first field adopted so the screen can open it.
 */
export function adoptCoreSection(
	document: SchemaDocument,
	section: CoreSectionState
): OperationResult {
	const pending = section.fields
		.filter( ( field ) => ! field.managed )
		.map( ( field ) => field.entry );

	if ( 0 === pending.length ) {
		return {
			ok: false,
			document,
			reason: '',
		};
	}

	// One operation per field, through the same function the picker uses: two ways of
	// adopting a WooCommerce field would be two answers to what adopting means.
	let working = document;
	let first: FieldDefinition | undefined;

	pending.forEach( ( entry ) => {
		const result = adoptCoreField( working, entry );

		if ( result.ok ) {
			working = result.document;
			first = first ?? result.field;
		}
	} );

	return {
		ok: true,
		document: working,
		reason: '',
		field: first,
	};
}

/**
 * Whether a native field's type has an equivalent the Suite knows.
 *
 * §6.7 asks for an immediate notice when a property cannot be carried over. A WooCommerce
 * type with no Suite equivalent is adopted as text, and the field's own rendering stays
 * WooCommerce's — so this says what the *definition* records, not what the checkout does.
 *
 * @param entry Entry of the real checkout.
 * @return Whether the type had to be mapped.
 */
export function typeWasRemapped(
	entry?: { typeRemapped?: boolean } | null
): boolean {
	return Boolean( entry?.typeRemapped );
}

/**
 * One property of a native field, and whether this plugin can change it where the store runs.
 */
export interface NativeProperty {
	key: string;
	label: string;
	applied: boolean;
}

/**
 * What this plugin can change about a native field, on the checkout the store runs.
 *
 * §6.7: "a edição deve modificar o campo real nos limites suportados pela integração… se uma
 * propriedade não puder ser alterada no Blocks, informar imediatamente." This is that answer,
 * read from what the adapters actually do and nothing else:
 *
 * - **Classic** (`ClassicAdapter::apply_to_core()`): the label, the description, the
 *   placeholder, the row width and the order are written onto WooCommerce's own field. The
 *   type and the required flag are deliberately left alone — shipping, tax and payment read
 *   them, and "a WooCommerce field keeps its contract".
 * - **Blocks**: nothing here is applied. The Blocks checkout owns its native fields, and the
 *   plugin's adapter is built to *add* fields — handing it `billing_first_name` would ask the
 *   platform to draw a second field with the name of the real one, which is the silent copy
 *   §6.7 forbids. So the adapter refuses it and this says so.
 *
 * `mode` is the checkout the merchant is looking at, which starts as the one the store runs.
 *
 * @param mode `blocks` or `classic`.
 * @return Properties in the order the inspector shows them.
 */
export function nativeFieldSupport( mode: string ): NativeProperty[] {
	const classic = 'blocks' !== mode;

	return [
		{ key: 'label', label: 'Etiqueta', applied: classic },
		{ key: 'description', label: 'Descrição', applied: classic },
		{ key: 'placeholder', label: 'Placeholder', applied: classic },
		{ key: 'width', label: 'Largura da linha', applied: classic },
		{ key: 'position', label: 'Ordem', applied: classic },
		// Never taken over, on either checkout, and for the same reason: they are the
		// contract shipping, tax and payment read.
		{ key: 'required', label: 'Obrigatório', applied: false },
		{ key: 'type', label: 'Tipo', applied: false },
	];
}

/**
 * A sentence stating what the current checkout will and will not take from an edit.
 *
 * @param mode `blocks` or `classic`.
 * @return The sentence.
 */
export function nativeFieldSummary( mode: string ): string {
	if ( 'blocks' !== mode ) {
		return 'Neste checkout, a etiqueta, a descrição, o placeholder, a largura e a ordem são escritas no campo real do WooCommerce. O tipo e o obrigatório continuam a ser da plataforma.';
	}

	return 'No checkout Blocks o WooCommerce é dono dos campos nativos: este plugin não os altera nem cria uma cópia. O campo continua a cobrar como sempre, com a etiqueta e a ordem da plataforma.';
}
