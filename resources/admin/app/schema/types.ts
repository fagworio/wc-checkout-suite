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
	visibility: Record< string, boolean >;
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
	title: string;
	description: string;
	position: number;
	location: string;
}

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
	conditions?: ConditionVocabularyShape;
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
	visibilityKeys: VocabularyEntry[];
	hiddenValuePolicies: VocabularyEntry[];
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
