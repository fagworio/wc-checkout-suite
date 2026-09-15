/**
 * The states an order can wait in, as §12 configures them.
 *
 * A status is a **state** and not a command. §12.4 forbids charging on a status change — a state can
 * be changed by hand, by an import or by a webhook — so what this module can express about a status
 * is its name, its colour, who sees it and whether it is a state before payment, and there is no
 * field here that a gateway could read. The payment decision belongs to a workflow transition and
 * an authorised payment action (§3.8, §11 of the phase list), which is why this screen offers none.
 *
 * Two decisions are worth stating rather than reading off the code.
 *
 * 1. **A new status has no identifier yet.** The screen sends the name and leaves `id` empty, and
 *    the store assigns it: the identifier becomes a post status key that orders are recorded in,
 *    and an identity rule implemented twice — once in the browser that proposes it, once in the
 *    server that stores it — is a rule that would eventually propose one key and store another.
 * 2. **WooCommerce's own statuses are listed and never edited.** The merchant should see the whole
 *    floor they are arranging their states on, and one of them being editable would be a way to
 *    take over a state the platform owns.
 *
 * @see ROADMAP.md section 12
 */

import type { ConditionVocabularyShape } from './types';

/**
 * One order status, as the route reports it.
 */
export interface StatusEntry {
	id: string;
	label: string;
	customer_label: string;
	colour: string;
	active: boolean;
	show_customer: boolean;
	show_emails: boolean;
	manual: boolean;
	prepayment: boolean;
	description: string;
	/** Whether the merchant configured it, or WooCommerce owns it. */
	custom: boolean;
	/** Whether the registry registers it. */
	registered: boolean;
}

/**
 * The colours the design offers, in the order it draws them.
 *
 * A palette and not a colour picker alone: the merchant should be able to tell their states apart at
 * a glance in the order list, and eleven half-chosen colours are what that looks like when it goes
 * wrong. A colour outside the palette is still accepted — a store with its own identity has its own
 * colours — and the validator is what refuses something that is not a colour.
 *
 * @type {Array<{value: string, label: string}>}
 */
export const PALETTE = [
	{ value: '#3858E9', label: 'Azul' },
	{ value: '#1F6FEB', label: 'Azul claro' },
	{ value: '#00A32A', label: 'Verde' },
	{ value: '#B32D2E', label: 'Vermelho' },
	{ value: '#DBA617', label: 'Âmbar' },
	{ value: '#FF59C0', label: 'Rosa' },
	{ value: '#8A4BC0', label: 'Violeta' },
	{ value: '#646970', label: 'Cinza' },
];

/**
 * The statuses the merchant configured.
 *
 * @param inventory Everything the route reported.
 * @return The merchant's own.
 */
export function customStatuses(
	inventory: Array< StatusEntry >
): Array< StatusEntry > {
	return ( inventory ?? [] ).filter( ( entry ) => entry?.custom );
}

/**
 * The statuses WooCommerce owns.
 *
 * @param inventory Everything the route reported.
 * @return The platform's.
 */
export function coreStatuses(
	inventory: Array< StatusEntry >
): Array< StatusEntry > {
	return ( inventory ?? [] ).filter( ( entry ) => entry && ! entry.custom );
}

/**
 * One new status, named and otherwise defaulted.
 *
 * @param label Name the merchant typed.
 * @return Status.
 */
export function createStatus( label: string ): StatusEntry {
	return {
		id: '',
		label,
		customer_label: '',
		colour: PALETTE[ 0 ].value,
		active: true,
		show_customer: true,
		show_emails: true,
		manual: true,
		prepayment: true,
		description: '',
		custom: true,
		registered: false,
	};
}

/**
 * Replaces one status in the list.
 *
 * @param list    The merchant's statuses.
 * @param index   Position.
 * @param changes Fields to change.
 * @return A new list.
 */
export function updateStatus(
	list: Array< StatusEntry >,
	index: number,
	changes: Partial< StatusEntry >
): Array< StatusEntry > {
	return list.map( ( entry, position ) =>
		position === index ? { ...entry, ...changes } : entry
	);
}

/**
 * Removes one status from the list.
 *
 * Removing is not a refusal even for a status an order is in: the store stops offering it, and the
 * orders recorded in it keep the key they have — which is why the screen says what removing means
 * instead of pretending the state never existed.
 *
 * @param list  The merchant's statuses.
 * @param index Position.
 * @return A new list.
 */
export function removeStatus(
	list: Array< StatusEntry >,
	index: number
): Array< StatusEntry > {
	return ( list ?? [] ).filter( ( _entry, position ) => position !== index );
}

/**
 * Whether a status declares itself a state before payment.
 *
 * @param status Status.
 * @return Whether it is pre-payment.
 */
export function isPrepayment( status: StatusEntry ): boolean {
	return Boolean( status?.prepayment );
}

/**
 * What is wrong with a status, said in words the merchant can act on.
 *
 * The length of the identifier beside `wc-`, the reserved keys and the uniqueness of the list are
 * the store's rules and the store answers them; what the screen checks is what it can see: a state
 * with no name, and a colour that is not a colour. A screen that re-implemented the rest would be a
 * second opinion, and the two would disagree the first time one was edited.
 *
 * @param status Status.
 * @return Messages.
 */
export function statusIssues( status: StatusEntry ): Array< string > {
	const found: Array< string > = [];

	if ( ! String( status?.label ?? '' ).trim() ) {
		found.push(
			'Um estado precisa de um nome: é o que o comerciante vê na lista de pedidos.'
		);
	}

	if ( status?.colour && ! /^#[0-9a-fA-F]{6}$/.test( status.colour ) ) {
		found.push(
			`"${ status.colour }" não é uma cor: escreva seis dígitos hexadecimais, como #1F6FEB.`
		);
	}

	return found;
}

/**
 * The list as the route wants it.
 *
 * The identifier travels when it exists and is empty when it does not: the store assigns it, and a
 * second one is never sent for the same row.
 *
 * @param list The merchant's statuses.
 * @return Payload.
 */
export function payloadOf(
	list: Array< StatusEntry >
): Array< Record< string, unknown > > {
	return ( list ?? [] ).map( ( entry ) => ( {
		id: entry.id ?? '',
		label: entry.label ?? '',
		customer_label: entry.customer_label ?? '',
		colour: entry.colour ?? '',
		active: Boolean( entry.active ),
		show_customer: Boolean( entry.show_customer ),
		show_emails: Boolean( entry.show_emails ),
		manual: Boolean( entry.manual ),
		prepayment: Boolean( entry.prepayment ),
		description: entry.description ?? '',
	} ) );
}

/**
 * Whether a status is one WooCommerce already considers paid.
 *
 * §12.5: a state before payment must stay out of that list. The screen shows the answer rather than
 * deciding it — the store keeps the rule, and this is how the merchant can see it holding.
 *
 * @param status Paid keys the route reported, with or without the `wc-` prefix.
 * @param id     Status identifier.
 * @return Whether it is paid.
 */
export function isPaid( status: Array< string >, id: string ): boolean {
	return ( status ?? [] ).some(
		( key ) => key === id || key === `wc-${ id }`
	);
}

/**
 * The vocabulary type this module keeps in step with, re-exported for the screens that need it.
 *
 * @type {ConditionVocabularyShape}
 */
export type StatusVocabulary = ConditionVocabularyShape;
