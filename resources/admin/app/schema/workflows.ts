/**
 * The automations a store runs, as §13 draws them.
 *
 * A workflow is a plan: what starts it, where the order waits, who is told, and where it can go from
 * there. Two rules shape everything here.
 *
 * 1. **One vocabulary, read from the server.** The triggers, the decisions, the events and the
 *    strategies come from the route, not from a list written here. The editor offers exactly what
 *    the validator accepts, which is the only way the two cannot disagree.
 * 2. **What the store cannot execute is not offered.** A strategy lives in the vocabulary as soon as
 *    it has a name; whether the store runs it is a separate answer the route gives in `executable`.
 *    The selects carry the executable ones and the screen says which it withheld and why (§30.1). A
 *    select offering "autorizar agora" would be promising an authorisation nothing performs — and the
 *    validator refuses it by name anyway. Since Fase 13 both vocabularies are fully executable, so
 *    nothing is withheld today; the projection is kept honest by the two lists staying separate.
 *
 * @see ROADMAP.md sections 13.2, 13.3, 13.6
 */

/**
 * One automation, as the route reports it.
 */
export interface WorkflowEntry {
	id: string;
	name: string;
	enabled: boolean;
	priority: number;
	trigger: string;
	conditions: Record< string, unknown >;
	initial_status: string;
	inventory_strategy: string;
	inventory_hours: number;
	payment_strategy: string;
	communications: Record< string, boolean >;
	transitions: Record< string, string >;
	expires_after_hours: number;
	fallbacks: Array< string >;
}

/** One option of a vocabulary list. */
export interface WorkflowOption {
	value: string;
	label: string;
}

/**
 * The vocabulary the route publishes.
 */
export interface WorkflowVocabulary {
	triggers?: Array< WorkflowOption >;
	decisions?: Array< WorkflowOption >;
	inventory?: Array< WorkflowOption >;
	payment?: Array< WorkflowOption >;
	events?: Array< WorkflowOption >;
	executable?: { inventory?: Array< string >; payment?: Array< string > };
	expiration?: { minHours?: number; maxHours?: number };
}

/**
 * One new automation, named and otherwise defaulted.
 *
 * Every communication starts on: an automation that moves an order and tells nobody is a customer
 * waiting for an order they think is lost, and a merchant who wants silence can turn it off.
 *
 * @param name Name the merchant typed.
 * @return Workflow.
 */
export function createWorkflow( name: string ): WorkflowEntry {
	return {
		id: '',
		name,
		enabled: true,
		priority: 10,
		trigger: 'checkout_submitted',
		conditions: {},
		initial_status: '',
		inventory_strategy: 'none',
		inventory_hours: 0,
		payment_strategy: 'none',
		communications: {},
		transitions: {},
		expires_after_hours: 0,
		fallbacks: [],
	};
}

/**
 * Replaces one automation in the list.
 *
 * @param list    Automations.
 * @param index   Position.
 * @param changes Fields to change.
 * @return A new list.
 */
export function updateWorkflow(
	list: Array< WorkflowEntry >,
	index: number,
	changes: Partial< WorkflowEntry >
): Array< WorkflowEntry > {
	return list.map( ( entry, position ) =>
		position === index ? { ...entry, ...changes } : entry
	);
}

/**
 * Removes one automation.
 *
 * @param list  Automations.
 * @param index Position.
 * @return A new list.
 */
export function removeWorkflow(
	list: Array< WorkflowEntry >,
	index: number
): Array< WorkflowEntry > {
	return ( list ?? [] ).filter( ( _entry, position ) => position !== index );
}

/**
 * The strategies a select may offer.
 *
 * The executable ones, in the order the vocabulary lists them. The rest are not dropped silently —
 * `unavailable()` names them, and the screen says why they are missing.
 *
 * @param vocabulary Vocabulary.
 * @param kind       `inventory` or `payment`.
 * @return Options.
 */
export function executableOptions(
	vocabulary: WorkflowVocabulary,
	kind: 'inventory' | 'payment'
): Array< WorkflowOption > {
	const all = vocabulary?.[ kind ] ?? [];
	const allowed = vocabulary?.executable?.[ kind ] ?? [];

	return all.filter( ( entry ) => allowed.includes( entry.value ) );
}

/**
 * The strategies the vocabulary has and the store cannot execute yet.
 *
 * @param vocabulary Vocabulary.
 * @param kind       `inventory` or `payment`.
 * @return Options.
 */
export function unavailableOptions(
	vocabulary: WorkflowVocabulary,
	kind: 'inventory' | 'payment'
): Array< WorkflowOption > {
	const all = vocabulary?.[ kind ] ?? [];
	const allowed = vocabulary?.executable?.[ kind ] ?? [];

	return all.filter( ( entry ) => ! allowed.includes( entry.value ) );
}

/**
 * What is wrong with an automation, said in words the merchant can act on.
 *
 * The status vocabulary, the refused strategies and the paid-status rule are the store's and the
 * store answers them; what the screen checks is what it can see before saving, so a merchant is not
 * told about a missing decision after pressing the button.
 *
 * @param workflow Workflow.
 * @return Messages.
 */
export function workflowIssues( workflow: WorkflowEntry ): Array< string > {
	const found: Array< string > = [];

	if ( ! String( workflow?.name ?? '' ).trim() ) {
		found.push( 'Um workflow precisa de um nome.' );
	}

	if ( ! workflow?.initial_status ) {
		found.push(
			'Um workflow precisa do estado em que o pedido espera: sem ele nada acontece quando o checkout é enviado.'
		);
	}

	if (
		Number( workflow?.expires_after_hours ) > 0 &&
		! workflow?.transitions?.expire
	) {
		found.push(
			'Um workflow que conta as horas precisa de dizer para que estado o pedido expira.'
		);
	}

	if (
		'hours' === workflow?.inventory_strategy &&
		Number( workflow?.inventory_hours ) <= 0
	) {
		found.push(
			'A reserva de estoque por horas precisa do número de horas: sem ele nada é reservado.'
		);
	}

	if (
		workflow?.transitions?.expire &&
		Number( workflow?.expires_after_hours ) <= 0
	) {
		found.push(
			'Uma expiração sem horas nunca é alcançada: diga ao fim de quantas horas o pedido expira.'
		);
	}

	return found;
}

/**
 * The list as the route wants it.
 *
 * The identifier travels when it exists and is empty when it does not: the store assigns it.
 *
 * @param list Automations.
 * @return Payload.
 */
export function payloadOf(
	list: Array< WorkflowEntry >
): Array< Record< string, unknown > > {
	return ( list ?? [] ).map( ( entry ) => ( {
		id: entry.id ?? '',
		name: entry.name ?? '',
		enabled: Boolean( entry.enabled ),
		priority: Number( entry.priority ) || 0,
		trigger: entry.trigger ?? 'checkout_submitted',
		conditions: entry.conditions ?? {},
		initial_status: entry.initial_status ?? '',
		inventory_strategy: entry.inventory_strategy ?? 'none',
		inventory_hours: Number( entry.inventory_hours ) || 0,
		payment_strategy: entry.payment_strategy ?? 'none',
		communications: entry.communications ?? {},
		transitions: entry.transitions ?? {},
		expires_after_hours: Number( entry.expires_after_hours ) || 0,
		fallbacks: entry.fallbacks ?? [],
	} ) );
}

/**
 * The decisions the merchant can map, and the status each one may lead to.
 *
 * @param vocabulary Vocabulary.
 * @param statuses   Status options.
 * @param workflow   Workflow.
 * @return Rows the editor draws.
 */
export function decisionRows(
	vocabulary: WorkflowVocabulary,
	statuses: Array< WorkflowOption >,
	workflow: WorkflowEntry
): Array< {
	value: string;
	label: string;
	status: string;
	options: Array< WorkflowOption >;
} > {
	return ( vocabulary?.decisions ?? [] ).map( ( decision ) => ( {
		value: decision.value,
		label: decision.label,
		status: workflow?.transitions?.[ decision.value ] ?? '',
		// `expire` is the clock's decision and is drawn with the hours, so it is offered the same
		// statuses and nothing else.
		options: statuses,
	} ) );
}
