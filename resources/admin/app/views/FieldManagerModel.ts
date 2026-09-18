/**
 * Contract between the document-owning screen and the field manager view.
 *
 * This is intentionally a view-model contract: domain operations remain in the
 * schema modules and the view remains responsible for rendering. Keeping the
 * boundary here makes the first refactor step observable without moving logic.
 */

import type { ReactNode } from 'react';

import type {
	CoreFieldEntry,
	CoreFieldInventory,
	FieldCatalog,
	FieldDefinition,
	OperationResult,
	PickerChoice,
	SchemaDocument,
	SectionGroup,
} from '../schema/types';
import type { CheckoutProfile } from '../schema/profiles';

export interface FieldManagerArea {
	id: string;
	label: string;
	description?: string;
}

export interface FieldManagerSectionOption {
	id: string;
	label: string;
	areas?: string[];
}

export interface FieldManagerAccountPage extends FieldManagerArea {
	custom?: boolean;
	enabled?: boolean;
	icon?: string;
	logout?: boolean;
	children?: Array< FieldManagerArea & { url?: string } >;
}

export interface FieldManagerEdits {
	canUndo: boolean;
	canRedo: boolean;
	undo: () => string;
	redo: () => string;
}

export interface FieldManagerUrls {
	checkout?: string;
	account?: string;
}

export interface FieldManagerFailure {
	status: 'error' | 'warning' | 'info';
	title: string;
	message: string;
	recovery: string;
}

export interface FieldManagerBulkImpact {
	dependents: string[];
	protected: string[];
	unchanged: string[];
	affected: number;
	total: number;
}

export interface FieldManagerReport {
	diff?: { published?: { revision?: number; updated_at?: string } };
	validation?: unknown;
	published?: { revision?: number; updated_at?: string };
}

export interface FieldManagerModel {
	scope?: 'all' | 'checkout';
	document: SchemaDocument;
	composition: SchemaDocument;
	groups: SectionGroup[];
	section: string;
	onSectionChange: ( id: string ) => void;
	catalog: FieldCatalog | null;
	coreFields: CoreFieldInventory | null;
	sectionOptions: FieldManagerSectionOption[];
	sections: Array< FieldManagerSectionOption & { areas: string[] } >;
	linkSections: Array< FieldManagerSectionOption & { areas: string[] } >;
	area: string;
	areas: FieldManagerArea[];
	accountMenu: FieldManagerAccountPage[];
	onAreaChange: ( id: string ) => void;
	loading: boolean;
	dirty: boolean;
	saving: boolean;
	saved: string;
	localDraftRestored: boolean;
	onDiscardLocalDraft: () => void;
	failure: FieldManagerFailure | null;
	refusal: string;
	problems: Array< { fieldId?: string; message: string } >;
	missingExtensions: Array< { field: string; label: string; type: string } >;
	unsupported: Array< { slot: string; storedVersion: number } >;
	selected: string[];
	onToggleSelected: ( id: string ) => void;
	onBulk: ( action: 'enable' | 'disable' | 'archive' ) => {
		ok: boolean;
		count: number;
	};
	bulkImpactFor: (
		action: 'enable' | 'disable' | 'archive'
	) => FieldManagerBulkImpact;
	onClearSelection: () => void;
	editing: string | null;
	onEdit: ( id: string | null ) => void;
	onDuplicate: ( id: string | null ) => void;
	onToggleEnabled: ( id: string ) => void;
	onMove: ( id: string, direction: 'up' | 'down' ) => void;
	onReorder: ( id: string, targetId: string ) => void;
	onRemove: ( id: string | null ) => void;
	onProtect: ( id: string | null ) => void;
	onChangeField: ( changes: Partial< FieldDefinition > ) => void;
	onCreateField: ( choice: PickerChoice & Record< string, unknown > ) => void;
	onAdoptCore: ( field: CoreFieldEntry ) => void;
	onAdoptAccount: ( field: CoreFieldEntry ) => void;
	onToggleAccount: ( field: CoreFieldEntry, enabled: boolean ) => void;
	onHideCore: ( field: CoreFieldEntry ) => void;
	onAdoptCoreSection: ( section: unknown ) => void;
	edits: FieldManagerEdits;
	onSave: () => Promise< void >;
	report: FieldManagerReport | null;
	revisions: unknown[];
	restoring: boolean;
	restored: string;
	publishError: string;
	onRestore: ( revision: number ) => Promise< void >;
	reference: string;
	onReferenceChange: ( reference: string ) => void;
	onExport: () => Promise< boolean >;
	onPreview: () => void;
	onRestoreField: ( id: string ) => void;
	onBackToEditor: () => void;
	onToggleSectionTitle?: ( checked: boolean ) => void;
	onOpenRules: () => void;
	onOpenSection: ( id: string | null ) => void;
	onCreateSection: () => void;
	onLinkExisting: () => void;
	isProtected: ( field: FieldDefinition ) => boolean;
	sectionEditor: ReactNode;
	sectionDraft: ReactNode;
	linkDialog: ReactNode;
	removalDialog: ReactNode;
	migrationDialog: ReactNode;
	profiles: CheckoutProfile[];
	activeProfile: string;
	onProfileSelect: ( id: string ) => void;
	onCreateProfile: ( choice: Record< string, unknown > ) => void;
	onUpdateProfile: ( id: string, changes: Record< string, unknown > ) => void;
	onRemoveProfile: ( id: string ) => void;
	onMoveProfile: ( id: string, delta: number ) => void;
	view: string;
	siteName?: string;
	urls: FieldManagerUrls;
	profileRefusal: string;
	onDismissProfileRefusal: () => void;
}

export type FieldManagerOperation = ( result: OperationResult ) => void;
