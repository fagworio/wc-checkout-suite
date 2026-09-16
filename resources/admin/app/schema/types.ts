/**
 * Types of the schema domain as the browser sees them.
 *
 * The first TypeScript in the project, and deliberately the first: the field
 * manager is where the data actually has a shape worth describing. The component
 * library keeps its JSDoc for now — the pain there was JSX inference, which is a
 * separate migration — but the domain objects the admin reads and writes are
 * modelled here once and shared by every module that touches them.
 *
 * These types mirror what the PHP side serialises. They are a description of a
 * published contract, not a second source of truth: the server validates the
 * document, and nothing here decides whether a field is acceptable.
 *
 * @see ROADMAP.md sections 4 and 18
 */

/**
 * Column width of a field per viewport, on the 12 column grid.
 */
export interface FieldLayout {
	desktop: number;
	tablet: number;
	mobile: number;
}

/**
 * One field of the checkout, as stored in the document.
 */
export interface FieldDefinition {
	id: string;
	integration_id: string;
	origin: 'core' | 'custom';
	type: string;
	preset: string | null;
	label: string;
	description: string;
	section: string;
	enabled: boolean;
	required: boolean;
	position: number;
	layout: FieldLayout;
	settings: Record< string, unknown >;
	mask: { key: string; version: number } | null;
	normalizer: string | null;
	conditions: Record< string, unknown >;
	hidden_value_policy: string;
	storage: { scope: string; sensitivity: string };
	destinations: Record< string, DestinationLink >;
	/**
	 * Every use of the field, in the final model. Read as the authority when the server
	 * sends it; the map above is then its projection, and both are written back together.
	 */
	bindings?: FieldBinding[];
	collection_surface?: 'checkout' | 'my_account';
	/**
	 * Which ways a customer's value flows (§7.7, §10.4).
	 *
	 * `to_checkout` fills the checkout with the value the customer already has; `from_checkout`
	 * keeps what they type at the checkout on their profile. Named separately on purpose: one
	 * direction never implies the other.
	 */
	sync?: {
		to_checkout?: boolean;
		from_checkout?: boolean;
	};
	approval?: ApprovalFlow | null;
	validators?: unknown[];
	schema_version?: number;
}

/**
 * A section of the checkout.
 *
 * `location` is a logical place, not a slot: ROADMAP.md section 4 fixes Billing,
 * Shipping, Contact, Account and Order as domain concepts and warns that they do
 * not correspond to identical slots in every checkout. Each adapter decides where
 * a location actually lands.
 */
export interface SectionDefinition {
	id: string;
	/** Canonical container name used by the write model. */
	name?: string;
	title: string;
	description: string;
	position: number;
	/** Canonical destination key. */
	destination?: string;
	location: string;
	/**
	 * The areas the section may be offered in: the checkout, where its fields are
	 * filled, and the destinations where they may be shown afterwards. A section
	 * offered nowhere cannot be chosen by anything.
	 */
	areas: string[];
	enabled?: boolean;
	show_title?: boolean;
	display_title?: string;
	icon?: string;
	target?: string;
	settings?: Record< string, unknown >;
	presentation?: {
		show_title?: boolean;
		account?: {
			/**
			 * The page of My Account this section lives on.
			 *
			 * Empty means a page of its own, registered as an endpoint. A value means one of the
			 * native pages that may host content (§7.4): the section renders inside it and
			 * registers no endpoint, so the slug and the menu position do not apply.
			 */
			page?: string;
			slug?: string;
			menu_label?: string;
			icon?: string;
			position?: number;
			mode?: 'edit' | 'view';
		};
	};
}

/**
 * Canonical container shape emitted by the frontend write model.
 *
 * `SectionDefinition` remains the compatibility read type for pre-migration drafts;
 * every new container produced by `schema/containers` satisfies this stronger contract.
 */
export type ContainerDefinition = SectionDefinition & {
	name: string;
	destination: string;
	enabled: boolean;
	show_title: boolean;
	display_title: string;
	icon: string;
	target: string;
	settings: Record< string, unknown >;
};

/**
 * One logical location a section may occupy.
 */
export interface SectionLocation {
	value: string;
	label: string;
	description: string;
}

/**
 * A section as the screen shows it: declared, or implied by a field.
 */
export interface SectionGroup {
	section: SectionDefinition;
	declared: boolean;
	fields: FieldDefinition[];
}

/**
 * The draft or published document.
 */
export interface SchemaDocument {
	revision: number;
	schema_version?: number;
	updated_at?: string;
	updated_by?: number;
	fields: FieldDefinition[];
	sections: SectionDefinition[];
	settings: Record< string, unknown >;
	migration_history?: unknown[];
	/**
	 * The checkouts this store runs beyond its own.
	 *
	 * The store's own composition is the document itself and always exists; a profile is an
	 * additional one, selected by the cart (§3.4, §6.9). Declared as a loose shape here and
	 * narrowed by `schema/profiles` so this module does not depend on the profiles module.
	 */
	profiles?: Array< Record< string, unknown > >;
}

/**
 * One registered field type, as the catalogue reports it.
 */
export interface FieldTypeEntry {
	key: string;
	label: string;
	category: string;
	source: string;
	contractVersion: string;
	supports: Record< string, boolean >;
	valueSchema: Record< string, unknown >;
	settingsSchema: Record< string, Record< string, any > >;
}

/**
 * Registered types grouped for the picker.
 */
export interface FieldTypeCategory {
	key: string;
	label: string;
	types: FieldTypeEntry[];
}

/**
 * A named starting point built on a registered type.
 */
export interface FieldPreset {
	key: string;
	label: string;
	type: string;
	defaults: Record< string, any >;
	settings: Record< string, unknown >;
	group: string;
	enabled: boolean;
}

/**
 * Payload of the field types route.
 */
export interface FieldCatalog {
	categories: FieldTypeCategory[];
	types: Record< string, FieldTypeEntry >;
	presets: FieldPreset[];
	masks: FieldMask[];
	vocabulary: DefinitionVocabulary;
	sectionLocations: SectionLocation[];
	sectionAreas: VocabularyEntry[];
	/** The native My Account pages that may host a section, with the reason each is offered. */
	accountSurfaces?: VocabularyEntry[];
	conditions?: ConditionVocabularyShape;
	/**
	 * What the store can answer for, for the checklist §6.3 requires before a minimal
	 * checkout is saved. Read from WooCommerce by the server, never guessed by the browser.
	 */
	checkoutFacts?: {
		gateway?: boolean;
		taxes?: boolean;
		shipping?: boolean;
		legal?: boolean;
	};
}

/**
 * The condition vocabulary, published by the server.
 *
 * Declared here rather than imported from `conditions` so the type module keeps
 * depending on nothing: a type-only import would be erased anyway, and a reader
 * looking for the catalogue's shape should find it with the catalogue.
 */
export interface ConditionVocabularyShape {
	operators?: Array< {
		key: string;
		label: string;
		takesValue: boolean;
		valueTypes: string[];
		sourceTypes: string[];
		negated: boolean;
	} >;
	sources?: Array< {
		key: string;
		label: string;
		type: string;
		scope: string;
		isReference: boolean;
	} >;
	limits?: { maxDepth?: number; maxNodes?: number };
}

/**
 * A declarative mask a field may be configured with.
 */
export interface FieldMask {
	key: string;
	version: number;
	definition: string | Record< string, unknown >;
	appliesTo: string[];
	declarative: boolean;
}

/**
 * One option of a closed vocabulary.
 */
export interface VocabularyEntry {
	value: string;
	label: string;
	description: string;
}

/**
 * The closed sets a definition may refer to.
 *
 * Published by the same class the server validator reads, so the inspector cannot
 * offer a choice the server would refuse.
 */
export interface DefinitionVocabulary {
	storageScopes: VocabularyEntry[];
	storageSensitivities: VocabularyEntry[];
	destinations: DestinationEntry[];
	destinationActions: VocabularyEntry[];
	sectionAreas: VocabularyEntry[];
	hiddenValuePolicies: VocabularyEntry[];
}

/**
 * One destination a field's answer may be shown in.
 *
 * `actions` is what that destination is allowed to do with the answer — approving is
 * a staff action and a customer destination does not carry it — and the server
 * refuses an action outside the list it publishes here.
 */
export interface DestinationEntry extends VocabularyEntry {
	actions: string[];
}

/**
 * What one field configured for one destination.
 *
 * Only `enabled` is required: a destination the merchant has not configured carries
 * nothing, and starts disabled.
 */
export interface DestinationLink {
	enabled: boolean;
	section?: string;
	title?: string;
	position?: number;
	actions?: string[];
	/**
	 * How the value behaves where it is shown: `view` is read-only and `edit` lets the
	 * person reading it change it. Kept because the map is what the surfaces read.
	 */
	mode?: 'edit' | 'view';
}

/**
 * One use of a field: the field, the container it sits in, and how it appears there.
 *
 * This is the final model (§3.3). A definition may be used several times, each use with
 * its own container, order, title, visible/editable decision and permissions — which is
 * what the destination map cannot express, because it holds one entry per destination.
 */
export interface FieldBinding {
	id?: string;
	field_id?: string;
	container_id: string;
	destination: string;
	position?: number | null;
	visible?: boolean;
	editable?: boolean;
	required_override?: boolean;
	label_override?: string;
	description_override?: string;
	permissions?: string[];
	conditions?: Record< string, unknown >;
}

/**
 * The optional approval flow, separate from every destination link.
 */
export interface ApprovalFlow {
	require_review?: boolean;
	rule?: string;
	area?: string;
	section?: string;
	status?: string;
	allow_correction?: boolean;
	allow_resubmit?: boolean;
	show_status?: boolean;
}

/**
 * One checkout field WooCommerce owns.
 */
export interface CoreFieldEntry {
	id: string;
	section: string;
	label: string;
	type: string;
	nativeType: string;
	typeRemapped: boolean;
	required: boolean;
	priority: number;
	classes: string[];
	layout: FieldLayout;
	protected: boolean;
	description?: string;
	group?: string;
	source?: string;
	collectionSurface?: 'checkout' | 'my_account';
}

/**
 * Inventory of the fields WooCommerce owns.
 */
export interface CoreFieldInventory {
	available: boolean;
	reason: string;
	sections: Array< {
		key: string;
		label: string;
		fields: CoreFieldEntry[];
	} >;
	fields: CoreFieldEntry[];
	account?: CoreFieldInventory;
}

/**
 * What the picker hands back when a type or preset is chosen.
 */
export interface PickerChoice {
	type: string;
	label?: string;
	idHint?: string;
	preset?: string | null;
	settings?: Record< string, unknown >;
	defaults?: Record< string, any >;
	section?: string;
	required?: boolean;
	layout?: Partial< FieldLayout >;
	supports?: Record< string, boolean >;
	description?: string;
	mask?: { key: string; version: number } | null;
	settingsSchema?: Record< string, Record< string, unknown > >;
	settingsValue?: Record< string, unknown >;
	conditions?: Record< string, unknown >;
	collectionSurface?: 'checkout' | 'my_account';
	storage?: { scope: string; sensitivity: string };
	destinations?: Record< string, DestinationLink >;
}

/**
 * Outcome of an operation on the document.
 *
 * A refusal returns the document it was given, unchanged. Callers must look at
 * `ok`: a refusal that is convenient to ignore is a refusal that will be.
 */
export interface OperationResult {
	ok: boolean;
	document: SchemaDocument;
	reason: string;
	field?: FieldDefinition;
}
