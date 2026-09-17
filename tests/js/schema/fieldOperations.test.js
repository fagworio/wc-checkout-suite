/**
 * Field operation tests.
 *
 * The behaviour under test is what the field manager does to a draft: create,
 * edit, duplicate, archive and remove, and — the part that carries the risk — the
 * line between a field the merchant owns and one WooCommerce owns.
 *
 * Every operation is also checked for purity. A manager that mutated the document
 * in place would make the unsaved-work guard and the discard path lie about what
 * changed.
 */

import {
	activeCount,
	adoptAccountField,
	adoptCoreField,
	archiveField,
	archiveFields,
	bulkImpact,
	conditionDependents,
	createField,
	createSection,
	duplicateField,
	fieldsInSection,
	moveField,
	moveFieldsToSection,
	moveSection,
	groupBySection,
	identifierFrom,
	isProtected,
	nextPosition,
	protectionReason,
	ambiguousDestinations,
	removeField,
	removeSection,
	removeSectionWithDependents,
	reorderField,
	repairLegacyDraft,
	resolveAmbiguousDestinations,
	sectionGroups,
	setFieldEnabled,
	setAccountFieldEnabled,
	setFieldSection,
	setFieldsEnabled,
	setFieldsDestinations,
	uniqueIdentifier,
	updateField,
	updateSection,
} from '../../../resources/admin/app/schema/fieldOperations';

/**
 * Builds a document with the given fields.
 *
 * @param {any[]} fields   Field definitions.
 * @param {any[]} sections Section definitions.
 * @return {any} Document.
 */
function doc(
	/** @type {any[]} */ fields = [],
	/** @type {any[]} */ sections = []
) {
	return {
		revision: 3,
		fields,
		sections,
		settings: {},
	};
}

/**
 * Builds a custom field.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field definition.
 */
function custom( overrides = {} ) {
	return {
		id: 'billing_document',
		integration_id: 'wc-checkoutsuite/billing_document',
		origin: 'custom',
		type: 'text',
		preset: null,
		label: 'CPF',
		section: 'billing',
		enabled: true,
		required: false,
		position: 20,
		layout: { desktop: 12, tablet: 12, mobile: 12 },
		settings: {},
		...overrides,
	};
}

/**
 * Builds a WooCommerce-owned field.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field definition.
 */
function core( overrides = {} ) {
	return custom( {
		id: 'billing_first_name',
		integration_id: 'billing_first_name',
		origin: 'core',
		label: 'First name',
		required: true,
		position: 10,
		...overrides,
	} );
}

/**
 * Returns the field an operation produced.
 *
 * `OperationResult.field` is optional because a refusal has none. Failing here
 * rather than letting a test read `undefined` keeps the assertion honest.
 *
 * @param {any} result Operation result.
 * @return {any} Field definition.
 */
function fieldOf( result ) {
	if ( ! result.field ) {
		throw new Error( 'The operation produced no field.' );
	}

	return result.field;
}

/**
 * Builds a complete inventory entry, as the core fields route sends it.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Inventory entry.
 */
function coreEntry( overrides = {} ) {
	return {
		id: 'billing_first_name',
		section: 'billing',
		label: 'First name',
		type: 'text',
		nativeType: 'text',
		typeRemapped: false,
		required: true,
		priority: 10,
		classes: [ 'form-row-first' ],
		layout: { desktop: 6, tablet: 6, mobile: 12 },
		protected: true,
		...overrides,
	};
}

/**
 * Builds a native My Account inventory entry.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Inventory entry.
 */
function accountEntry( overrides = {} ) {
	return coreEntry( {
		id: 'account_first_name',
		section: 'edit-account',
		label: 'First name',
		nativeType: 'text',
		collectionSurface: 'my_account',
		...overrides,
	} );
}

describe( 'identifier generation', () => {
	it( 'strips accents instead of dropping the letter', () => {
		expect( identifierFrom( 'Endereço' ) ).toBe( 'endereco' );
		expect( identifierFrom( 'Número do documento' ) ).toBe(
			'numero_do_documento'
		);
	} );

	it( 'produces identifiers the server accepts', () => {
		const rule = /^[a-z0-9][a-z0-9_]*$/;

		for ( const label of [
			'CPF',
			'  spaced  out  ',
			'Pessoa Jurídica (CNPJ)',
			'123',
			'!!!',
			'',
		] ) {
			expect( identifierFrom( label ) ).toMatch( rule );
		}
	} );

	it( 'falls back to a usable identifier for a label with no letters', () => {
		expect( identifierFrom( '!!!' ) ).toBe( 'field' );
	} );

	it( 'avoids an identifier that is already taken', () => {
		const document = doc( [ custom( { id: 'cpf' } ) ] );

		expect( uniqueIdentifier( document, 'cpf' ) ).toBe( 'cpf_2' );
	} );

	it( 'keeps counting until it finds a free identifier', () => {
		const document = doc( [
			custom( { id: 'cpf' } ),
			custom( { id: 'cpf_2' } ),
			custom( { id: 'cpf_3' } ),
		] );

		expect( uniqueIdentifier( document, 'cpf' ) ).toBe( 'cpf_4' );
	} );
} );

describe( 'positions', () => {
	it( 'places a new field after the last one in its section', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 30 } ),
			custom( { id: 'c', section: 'shipping', position: 90 } ),
		] );

		expect( nextPosition( document, 'billing' ) ).toBe( 40 );
	} );

	it( 'starts at ten when the section is empty', () => {
		expect( nextPosition( doc(), 'billing' ) ).toBe( 10 );
	} );
} );

describe( 'creating', () => {
	it( 'adds an enabled custom field with its own integration key', () => {
		const result = createField( doc(), { type: 'text', label: 'CPF' } );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).id ).toBe( 'cpf' );
		expect( fieldOf( result ).origin ).toBe( 'custom' );
		expect( fieldOf( result ).integration_id ).toBe(
			'wc-checkoutsuite/cpf'
		);
		expect( fieldOf( result ).enabled ).toBe( true );
		expect( result.document.fields ).toHaveLength( 1 );
	} );

	it( 'carries the preset it was created from', () => {
		const result = createField( doc(), {
			type: 'text',
			label: 'CPF',
			preset: 'br.cpf',
			settings: { placeholder: '000.000.000-00' },
		} );

		expect( fieldOf( result ).preset ).toBe( 'br.cpf' );
		expect( fieldOf( result ).settings ).toEqual( {
			placeholder: '000.000.000-00',
		} );
	} );

	it( 'seeds the mask and the normalizer the preset declares', () => {
		// The picker hands the preset's defaults through, and until this was
		// wired the contract the server published stopped here: a merchant
		// picked CPF and got a plain text field with a placeholder.
		const result = createField( doc(), {
			type: 'text',
			label: 'CPF',
			preset: 'br.cpf',
			defaults: {
				label: 'CPF',
				normalizer: 'br.cpf',
				mask: { key: 'br.cpf', version: 1 },
			},
		} );

		expect( fieldOf( result ).normalizer ).toBe( 'br.cpf' );
		expect( fieldOf( result ).mask ).toEqual( {
			key: 'br.cpf',
			version: 1,
		} );
	} );

	it( 'lets an explicit choice win over the preset default', () => {
		const result = createField( doc(), {
			type: 'text',
			label: 'Postcode',
			preset: 'br.cep',
			mask: { key: 'br.cep', version: 1 },
			defaults: { mask: { key: 'br.cpf', version: 1 } },
		} );

		expect( fieldOf( result ).mask ).toEqual( {
			key: 'br.cep',
			version: 1,
		} );
	} );

	it( 'leaves a type with no preset unmasked and unnormalized', () => {
		const result = createField( doc(), { type: 'text', label: 'Notes' } );

		expect( fieldOf( result ).mask ).toBeNull();
		expect( fieldOf( result ).normalizer ).toBeNull();
	} );

	it( 'refuses to let a preset choose anything but how the value is typed', () => {
		// A preset comes from the server, where any plugin can register one
		// through a public hook. It may describe the value; it may not choose
		// the identity of the field, where it lives, or who may see it.
		const result = createField( doc(), {
			type: 'text',
			label: 'CPF',
			preset: 'br.cpf',
			section: 'billing',
			defaults: {
				id: 'billing_email',
				integration_id: 'wc-checkoutsuite/hijacked',
				origin: 'core',
				section: 'shipping',
				enabled: false,
				storage: { scope: 'none', sensitivity: 'personal' },
				destinations: {
					admin_order: { enabled: true },
					customer_order: { enabled: true },
				},
				hidden_value_policy: 'preserve',
				type: 'number',
			},
		} );

		const field = fieldOf( result );

		expect( field.id ).toBe( 'cpf' );
		expect( field.integration_id ).toBe( 'wc-checkoutsuite/cpf' );
		expect( field.origin ).toBe( 'custom' );
		expect( field.section ).toBe( 'billing' );
		expect( field.enabled ).toBe( true );
		expect( field.type ).toBe( 'text' );
		expect( field.storage.scope ).toBe( 'order' );
		// A preset chooses how the value is typed, never who sees it: the destinations
		// it asks for are not obeyed, and a new field starts with none.
		expect( field.destinations ).toEqual( {} );
		expect( field.hidden_value_policy ).toBe( 'discard' );
	} );

	it( 'never reuses an identifier', () => {
		const first = createField( doc(), { type: 'text', label: 'CPF' } );
		const second = createField( first.document, {
			type: 'text',
			label: 'CPF',
		} );

		expect( fieldOf( second ).id ).toBe( 'cpf_2' );
	} );

	it( 'creates the field in the section it was asked for', () => {
		const result = createField( doc(), {
			type: 'text',
			label: 'CPF',
			section: 'billing',
		} );

		expect( fieldOf( result ).section ).toBe( 'billing' );
	} );
} );

describe( 'adopting a WooCommerce field', () => {
	it( 'keeps the identifier the store already uses', () => {
		const result = adoptCoreField( doc(), coreEntry() );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).id ).toBe( 'billing_first_name' );
		expect( fieldOf( result ).integration_id ).toBe( 'billing_first_name' );
		expect( fieldOf( result ).origin ).toBe( 'core' );
		expect( fieldOf( result ).layout ).toEqual( {
			desktop: 6,
			tablet: 6,
			mobile: 12,
		} );
	} );

	it( 'refuses to adopt the same field twice, and says why', () => {
		const document = doc( [ core() ] );
		const result = adoptCoreField( document, coreEntry() );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /already part of the schema/ );
		expect( result.document ).toBe( document );
	} );
} );

describe( 'native My Account fields', () => {
	it( 'adopts the native identifier as an account override', () => {
		const result = adoptAccountField( doc(), accountEntry() );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).id ).toBe( 'account_first_name' );
		expect( fieldOf( result ).collection_surface ).toBe( 'my_account' );
		expect( fieldOf( result ).origin ).toBe( 'core' );
	} );

	it( 'can hide and restore a native account field without deleting its definition', () => {
		const hidden = setAccountFieldEnabled( doc(), accountEntry(), false );

		expect( hidden.ok ).toBe( true );
		expect( fieldOf( hidden ).enabled ).toBe( false );
		expect( hidden.document.fields ).toHaveLength( 1 );

		const restored = setAccountFieldEnabled(
			hidden.document,
			accountEntry(),
			true
		);

		expect( restored.ok ).toBe( true );
		expect( fieldOf( restored ).enabled ).toBe( true );
	} );
} );

describe( 'editing', () => {
	it( 'changes the label without touching identity', () => {
		const result = updateField( doc( [ custom() ] ), 'billing_document', {
			label: 'Documento',
		} );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).label ).toBe( 'Documento' );
		expect( fieldOf( result ).id ).toBe( 'billing_document' );
		expect( fieldOf( result ).integration_id ).toBe(
			'wc-checkoutsuite/billing_document'
		);
	} );

	it( 'ignores an attempt to rewrite the identifier or the origin', () => {
		const result = updateField( doc( [ custom() ] ), 'billing_document', {
			id: 'something_else',
			origin: 'core',
			integration_id: 'hijacked',
		} );

		expect( fieldOf( result ).id ).toBe( 'billing_document' );
		expect( fieldOf( result ).origin ).toBe( 'custom' );
		expect( fieldOf( result ).integration_id ).toBe(
			'wc-checkoutsuite/billing_document'
		);
	} );

	it( 'refuses a type change on a WooCommerce field', () => {
		const result = updateField( doc( [ core() ] ), 'billing_first_name', {
			type: 'hidden',
		} );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /cannot change/ );
	} );

	it( 'allows a type change on a custom field', () => {
		const result = updateField( doc( [ custom() ] ), 'billing_document', {
			type: 'select',
		} );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).type ).toBe( 'select' );
	} );

	it( 'reports an unknown identifier instead of inventing a field', () => {
		const result = updateField( doc(), 'nope', { label: 'x' } );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /does not exist/ );
	} );
} );

describe( 'duplicating', () => {
	it( 'copies under a new identifier and starts archived', () => {
		const result = duplicateField(
			doc( [ custom() ] ),
			'billing_document'
		);

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).id ).toBe( 'billing_document_copy' );
		expect( fieldOf( result ).enabled ).toBe( false );
		expect( fieldOf( result ).label ).toBe( 'CPF (copy)' );
		expect( result.document.fields ).toHaveLength( 2 );
	} );

	it( 'never makes the copy a WooCommerce field, even from one', () => {
		const result = duplicateField(
			doc( [ core() ] ),
			'billing_first_name'
		);

		expect( fieldOf( result ).origin ).toBe( 'custom' );
		expect( fieldOf( result ).integration_id ).toBe(
			'wc-checkoutsuite/billing_first_name_copy'
		);
		expect( isProtected( fieldOf( result ) ) ).toBe( false );
	} );

	it( 'keeps looking for a free copy identifier', () => {
		const document = doc( [
			custom(),
			custom( { id: 'billing_document_copy' } ),
		] );

		expect(
			fieldOf( duplicateField( document, 'billing_document' ) ).id
		).toBe( 'billing_document_copy_2' );
	} );
} );

describe( 'archiving and removing', () => {
	it( 'archives a custom field', () => {
		const result = archiveField( doc( [ custom() ] ), 'billing_document' );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).enabled ).toBe( false );
	} );

	it( 'restores an archived field', () => {
		const document = doc( [ custom( { enabled: false } ) ] );
		const result = setFieldEnabled( document, 'billing_document', true );

		expect( result.ok ).toBe( true );
		expect( fieldOf( result ).enabled ).toBe( true );
	} );

	it( 'refuses to archive a WooCommerce field, and explains why', () => {
		const document = doc( [ core() ] );
		const result = archiveField( document, 'billing_first_name' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /shipping, tax and payment/ );
		expect( result.document ).toBe( document );
	} );

	it( 'refuses to remove a WooCommerce field', () => {
		const result = removeField( doc( [ core() ] ), 'billing_first_name' );

		expect( result.ok ).toBe( false );
		expect( result.document.fields ).toHaveLength( 1 );
	} );

	it( 'removes a custom field', () => {
		const result = removeField( doc( [ custom() ] ), 'billing_document' );

		expect( result.ok ).toBe( true );
		expect( result.document.fields ).toHaveLength( 0 );
	} );

	it( 'describes the protection in terms of the field', () => {
		expect( protectionReason( core() ) ).toContain( 'billing_first_name' );
		expect( protectionReason( custom() ) ).toBe( '' );
	} );
} );

describe( 'grouping and counting', () => {
	it( 'groups by section in position order', () => {
		const document = doc( [
			custom( { id: 'b', section: 'billing', position: 30 } ),
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'c', section: 'shipping', position: 5 } ),
		] );

		expect( groupBySection( document ) ).toEqual( [
			{
				section: 'billing',
				fields: [
					expect.objectContaining( { id: 'a' } ),
					expect.objectContaining( { id: 'b' } ),
				],
			},
			{
				section: 'shipping',
				fields: [ expect.objectContaining( { id: 'c' } ) ],
			},
		] );
	} );

	it( 'counts only the fields that are active', () => {
		const document = doc( [
			custom( { id: 'a' } ),
			custom( { id: 'b', enabled: false } ),
			custom( { id: 'c' } ),
		] );

		expect( activeCount( document ) ).toBe( 2 );
	} );
} );

describe( 'purity', () => {
	it( 'never mutates the document it was given', () => {
		const document = doc( [ custom(), core() ] );
		const before = JSON.stringify( document );

		createField( document, { type: 'text', label: 'Novo' } );
		updateField( document, 'billing_document', { label: 'Outro' } );
		duplicateField( document, 'billing_document' );
		archiveField( document, 'billing_document' );
		removeField( document, 'billing_document' );

		expect( JSON.stringify( document ) ).toBe( before );
	} );

	it( 'returns a new field array rather than the same one', () => {
		const document = doc( [ custom() ] );
		const result = updateField( document, 'billing_document', {
			label: 'Outro',
		} );

		expect( result.document ).not.toBe( document );
		expect( result.document.fields ).not.toBe( document.fields );
	} );
} );

describe( 'ordering fields within a section', () => {
	it( 'swaps two neighbours and renumbers them', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
		] );

		const result = moveField( document, 'b', 'up' );

		expect( result.ok ).toBe( true );
		expect(
			fieldsInSection( result.document, 'billing' ).map( ( f ) => f.id )
		).toEqual( [ 'b', 'a' ] );
		expect(
			fieldsInSection( result.document, 'billing' ).map(
				( f ) => f.position
			)
		).toEqual( [ 10, 20 ] );
	} );

	it( 'refuses to move the first field up', () => {
		const document = doc( [ custom( { id: 'a', section: 'billing' } ) ] );
		const result = moveField( document, 'a', 'up' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /end of its section/ );
		expect( result.document ).toBe( document );
	} );

	it( 'refuses to move the last field down', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
		] );

		expect( moveField( document, 'b', 'down' ).ok ).toBe( false );
	} );

	it( 'leaves the fields of other sections alone', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
			custom( { id: 'x', section: 'shipping', position: 10 } ),
			custom( { id: 'y', section: 'shipping', position: 20 } ),
		] );

		const result = moveField( document, 'b', 'up' );

		expect(
			fieldsInSection( result.document, 'shipping' ).map( ( f ) => f.id )
		).toEqual( [ 'x', 'y' ] );
	} );

	it( 'reports an unknown identifier instead of inventing a field', () => {
		expect( moveField( doc(), 'nope', 'up' ).reason ).toMatch(
			/does not exist/
		);
	} );
} );

describe( 'dropping a field onto another one', () => {
	it( 'takes the target position and shifts the rest', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
			custom( { id: 'c', section: 'billing', position: 30 } ),
		] );

		const result = reorderField( document, 'c', 'a' );

		expect( result.ok ).toBe( true );
		expect(
			fieldsInSection( result.document, 'billing' ).map( ( f ) => f.id )
		).toEqual( [ 'c', 'a', 'b' ] );
		expect(
			fieldsInSection( result.document, 'billing' ).map(
				( f ) => f.position
			)
		).toEqual( [ 10, 20, 30 ] );
	} );

	it( 'moves a field down onto the row it is dropped on', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
			custom( { id: 'c', section: 'billing', position: 30 } ),
		] );

		const result = reorderField( document, 'a', 'b' );

		expect(
			fieldsInSection( result.document, 'billing' ).map( ( f ) => f.id )
		).toEqual( [ 'b', 'a', 'c' ] );
	} );

	it( 'leaves the fields of other sections alone', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
			custom( { id: 'x', section: 'shipping', position: 10 } ),
			custom( { id: 'y', section: 'shipping', position: 20 } ),
		] );

		const result = reorderField( document, 'b', 'a' );

		expect(
			fieldsInSection( result.document, 'shipping' ).map( ( f ) => f.id )
		).toEqual( [ 'x', 'y' ] );
	} );

	it( 'refuses to cross a section boundary', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'x', section: 'shipping', position: 10 } ),
		] );

		const result = reorderField( document, 'a', 'x' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /inside their own section/ );
		expect( result.document ).toBe( document );
	} );

	it( 'refuses to drop a field on itself', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
		] );

		const result = reorderField( document, 'a', 'a' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /already in that position/ );
	} );

	it( 'reports an unknown target instead of inventing a place to drop it', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
		] );

		expect( reorderField( document, 'a', 'nope' ).reason ).toMatch(
			/does not exist/
		);
	} );

	it( 'does not touch the document it is given', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
		] );

		reorderField( document, 'b', 'a' );

		expect(
			document.fields.map( ( /** @type {any} */ f ) => f.id )
		).toEqual( [ 'a', 'b' ] );
	} );
} );

describe( 'moving a field to another section', () => {
	it( 'appends it at the end of the target section', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'shipping', position: 10 } ),
			custom( { id: 'c', section: 'shipping', position: 20 } ),
		] );

		const result = setFieldSection( document, 'a', 'shipping' );

		expect( result.ok ).toBe( true );
		expect(
			fieldsInSection( result.document, 'shipping' ).map( ( f ) => f.id )
		).toEqual( [ 'b', 'c', 'a' ] );
		expect( fieldsInSection( result.document, 'billing' ) ).toHaveLength(
			0
		);
	} );

	it( 'does nothing when the field is already there', () => {
		const document = doc( [ custom( { id: 'a', section: 'billing' } ) ] );

		expect( setFieldSection( document, 'a', 'billing' ).document ).toBe(
			document
		);
	} );

	it( 'reports an unknown identifier', () => {
		expect( setFieldSection( doc(), 'nope', 'billing' ).ok ).toBe( false );
	} );
} );

describe( 'section groups', () => {
	it( 'includes the sections fields imply, in position order', () => {
		const document = doc(
			[
				custom( { id: 'a', section: 'billing', position: 20 } ),
				custom( { id: 'b', section: 'billing', position: 10 } ),
				custom( { id: 'c', section: 'shipping', position: 10 } ),
			],
			[
				{
					id: 'custom_block',
					title: 'Extra',
					description: '',
					position: 5,
					location: 'order',
				},
			]
		);

		const groups = sectionGroups( document );

		expect( groups.map( ( g ) => g.section.id ) ).toEqual( [
			'custom_block',
			'billing',
			'shipping',
		] );
		expect( groups[ 1 ].fields.map( ( f ) => f.id ) ).toEqual( [
			'b',
			'a',
		] );
		expect( groups[ 0 ].declared ).toBe( true );
		expect( groups[ 1 ].declared ).toBe( false );
	} );

	it( 'gives an implied section a readable title', () => {
		const document = doc( [ custom( { id: 'a', section: 'billing' } ) ] );

		expect( sectionGroups( document )[ 0 ].section.title ).toBe(
			'Billing'
		);
	} );

	it( 'keeps collection and display sections in their own editor areas', () => {
		const document = doc(
			[
				custom( {
					id: 'document',
					section: 'checkout_docs',
					destinations: {
						customer_order: {
							enabled: true,
							section: 'customer_docs',
						},
						admin_order: {
							enabled: true,
							section: 'admin_docs',
						},
					},
				} ),
			],
			[
				{
					id: 'checkout_docs',
					title: 'Documentos da compra',
					description: '',
					position: 10,
					location: 'order',
					areas: [ 'checkout' ],
				},
				{
					id: 'customer_docs',
					title: 'Documentos enviados',
					description: '',
					position: 20,
					location: 'order',
					areas: [ 'customer_order' ],
				},
				{
					id: 'admin_docs',
					title: 'Documentos para análise',
					description: '',
					position: 30,
					location: 'order',
					areas: [ 'admin_order' ],
				},
			]
		);

		expect(
			sectionGroups( document, 'checkout' ).map(
				( group ) => group.section.id
			)
		).toEqual( [ 'checkout_docs' ] );
		expect(
			sectionGroups( document, 'customer_order' )[ 0 ].fields.map(
				( field ) => field.id
			)
		).toEqual( [ 'document' ] );
		expect(
			sectionGroups( document, 'admin_order' )[ 0 ].fields.map(
				( field ) => field.id
			)
		).toEqual( [ 'document' ] );
	} );
} );

describe( 'the retired destination key', () => {
	it( 'is reported by name, with the surfaces that replaced it', () => {
		const document = doc( [
			custom( {
				id: 'registo',
				destinations: {
					customer_profile: {
						enabled: true,
						section: 'billing',
					},
				},
			} ),
		] );

		expect( ambiguousDestinations( document ) ).toEqual( [
			'customer_profile',
		] );
		expect( ambiguousDestinations( doc() ) ).toEqual( [] );
	} );

	it( 'is rewritten to the surface the merchant chose, links and areas alike', () => {
		const document = doc(
			[
				custom( {
					id: 'registo',
					destinations: {
						customer_profile: {
							enabled: true,
							section: 'dados_profissionais',
							title: 'Registo profissional',
							position: 10,
							mode: 'edit',
						},
					},
				} ),
			],
			[
				{
					id: 'dados_profissionais',
					title: 'Dados profissionais',
					description: '',
					position: 10,
					location: 'account',
					areas: [ 'customer_profile' ],
				},
			]
		);

		const staff = resolveAmbiguousDestinations(
			document,
			'admin_customer_profile'
		);

		expect( staff.changed ).toBe( true );
		expect(
			Object.keys( staff.document.fields[ 0 ].destinations )
		).toEqual( [ 'admin_customer_profile' ] );
		expect(
			staff.document.fields[ 0 ].destinations.admin_customer_profile
		).toEqual( {
			enabled: true,
			section: 'dados_profissionais',
			title: 'Registo profissional',
			position: 10,
			mode: 'edit',
		} );
		expect( staff.document.sections[ 0 ].areas ).toEqual( [
			'admin_customer_profile',
		] );
		expect( ambiguousDestinations( staff.document ) ).toEqual( [] );

		// The same document can answer the other way, and nothing else changes.
		const customer = resolveAmbiguousDestinations(
			document,
			'customer_account'
		);

		expect(
			Object.keys( customer.document.fields[ 0 ].destinations )
		).toEqual( [ 'customer_account' ] );
		expect( customer.document.sections[ 0 ].areas ).toEqual( [
			'customer_account',
		] );
	} );

	it( 'refuses an answer that is not one of the replacements', () => {
		const document = doc( [
			custom( {
				id: 'registo',
				destinations: {
					customer_profile: { enabled: true, section: 'billing' },
				},
			} ),
		] );

		const unchanged = resolveAmbiguousDestinations(
			document,
			'admin_order'
		);

		expect( unchanged.changed ).toBe( false );
		expect( unchanged.document ).toBe( document );
	} );
} );

describe( 'sections', () => {
	it( 'keeps My Account collection fields out of checkout groups', () => {
		const document = doc(
			[
				custom( {
					id: 'customer_note',
					section: 'customer_notes',
					collection_surface: 'my_account',
					destinations: {
						customer_account: {
							enabled: true,
							section: 'customer_notes',
							mode: 'edit',
						},
					},
				} ),
			],
			[
				{
					id: 'customer_notes',
					title: 'Anotações',
					description: '',
					position: 10,
					location: 'account',
					areas: [ 'customer_account' ],
				},
			]
		);

		expect( sectionGroups( document, 'checkout' ) ).toEqual( [] );
		expect(
			sectionGroups( document, 'customer_account' )[ 0 ].fields.map(
				( entry ) => entry.id
			)
		).toEqual( [ 'customer_note' ] );
	} );

	it( 'preserves account presentation when creating a section', () => {
		const presentation = {
			show_title: true,
			account: {
				slug: 'meus-documentos',
				menu_label: 'Meus documentos',
				icon: 'file',
				position: 5,
				mode: /** @type {'edit'} */ ( 'edit' ),
			},
		};
		const result = createSection( doc(), {
			title: 'Meus documentos',
			location: 'account',
			areas: [ 'customer_account' ],
			presentation,
		} );

		expect( result.document.sections[ 0 ].presentation ).toEqual(
			presentation
		);
		expect( result.document.sections[ 0 ].target ).toBe( 'account' );
	} );

	it( 'creates one with an identifier derived from the title', () => {
		const result = createSection( doc(), {
			title: 'Dados extras',
			location: 'billing',
		} );

		expect( result.ok ).toBe( true );
		expect( result.document.sections[ 0 ].id ).toBe( 'dados_extras' );
		expect( result.document.sections[ 0 ].location ).toBe( 'billing' );
	} );

	it( 'places a new section after the last one', () => {
		const document = doc(
			[],
			[
				{
					id: 'a',
					title: 'A',
					description: '',
					position: 10,
					location: 'billing',
				},
				{
					id: 'b',
					title: 'B',
					description: '',
					position: 30,
					location: 'order',
				},
			]
		);

		expect(
			createSection( document, { title: 'C', location: 'order' } )
				.document.sections[ 2 ].position
		).toBe( 40 );
	} );

	it( 'never reuses a section identifier', () => {
		const document = doc(
			[],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		expect(
			createSection( document, { title: 'Extra', location: 'order' } )
				.document.sections[ 1 ].id
		).toBe( 'extra_2' );
	} );

	it( 'renames a section without changing its identifier', () => {
		const document = doc(
			[],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		const result = updateSection( document, 'extra', {
			title: 'Mais dados',
		} );

		expect( result.document.sections[ 0 ].id ).toBe( 'extra' );
		expect( result.document.sections[ 0 ].title ).toBe( 'Mais dados' );
	} );

	it( 'ignores an attempt to rewrite the identifier', () => {
		const document = doc(
			[],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		expect(
			updateSection( document, 'extra', { id: 'outro' } ).document
				.sections[ 0 ].id
		).toBe( 'extra' );
	} );

	it( 'refuses to remove a section that still holds fields', () => {
		const document = doc(
			[ custom( { id: 'a', section: 'extra' } ) ],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		const result = removeSection( document, 'extra' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /still used/ );
		expect( result.document ).toBe( document );
	} );

	it( 'removes an empty section', () => {
		const document = doc(
			[],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		expect(
			removeSection( document, 'extra' ).document.sections
		).toHaveLength( 0 );
	} );

	it( 'removes every enabled and disabled link when deleting a section with custom fields', () => {
		const document = doc(
			[
				custom( {
					id: 'shared',
					destinations: {
						customer_account: {
							enabled: true,
							section: 'extra',
						},
						admin_order: {
							enabled: false,
							section: 'extra',
						},
					},
				} ),
			],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);

		const result = removeSectionWithDependents( document, 'extra' );
		const field = result.document.fields[ 0 ];

		expect( result.ok ).toBe( true );
		expect( result.document.sections ).toHaveLength( 0 );
		expect( field.destinations ).toEqual( {} );
		expect( field.bindings ).toEqual( [] );
	} );

	it( 'refuses deletion while display links or approval still reference it', () => {
		const document = doc(
			[
				custom( {
					id: 'document',
					destinations: {
						admin_order: { enabled: true, section: 'review' },
					},
					approval: {
						require_review: true,
						area: 'admin_order',
						section: 'review',
					},
				} ),
			],
			[
				{
					id: 'review',
					title: 'Análise',
					description: '',
					position: 10,
					location: 'order',
					areas: [ 'admin_order' ],
				},
			]
		);

		const result = removeSection( document, 'review' );

		expect( result.ok ).toBe( false );
		expect( result.reason ).toMatch( /1 display link/ );
		expect( result.reason ).toMatch( /1 approval flow/ );
	} );

	it( 'moves a section and renumbers the order', () => {
		const document = doc(
			[],
			[
				{
					id: 'a',
					title: 'A',
					description: '',
					position: 10,
					location: 'billing',
				},
				{
					id: 'b',
					title: 'B',
					description: '',
					position: 20,
					location: 'order',
				},
			]
		);

		const result = moveSection( document, 'b', 'up' );

		expect( result.document.sections.map( ( s ) => s.id ) ).toEqual( [
			'b',
			'a',
		] );
		expect( result.document.sections.map( ( s ) => s.position ) ).toEqual( [
			10, 20,
		] );
	} );

	it( 'refuses to move the first section up', () => {
		const document = doc(
			[],
			[
				{
					id: 'a',
					title: 'A',
					description: '',
					position: 10,
					location: 'billing',
				},
			]
		);

		expect( moveSection( document, 'a', 'up' ).ok ).toBe( false );
	} );
} );

describe( 'section operations are pure too', () => {
	it( 'never mutates the document it was given', () => {
		const document = doc(
			[
				custom( { id: 'a', section: 'billing', position: 10 } ),
				custom( { id: 'b', section: 'billing', position: 20 } ),
			],
			[
				{
					id: 'extra',
					title: 'Extra',
					description: '',
					position: 10,
					location: 'order',
				},
			]
		);
		const before = JSON.stringify( document );

		moveField( document, 'b', 'up' );
		setFieldSection( document, 'a', 'shipping' );
		createSection( document, { title: 'Nova', location: 'order' } );
		updateSection( document, 'extra', { title: 'Outra' } );
		moveSection( document, 'extra', 'down' );
		removeSection( document, 'extra' );

		expect( JSON.stringify( document ) ).toBe( before );
	} );
} );

describe( 'bulk operations', () => {
	it( 'archives the custom fields and reports the protected ones', () => {
		const document = doc( [
			custom( { id: 'a', enabled: true } ),
			core( { id: 'billing_first_name', enabled: true } ),
			custom( { id: 'c', enabled: true } ),
		] );

		const result = archiveFields( document, [
			'a',
			'billing_first_name',
			'c',
		] );

		expect( result.ok ).toBe( true );
		expect( result.applied ).toEqual( [ 'a', 'c' ] );
		expect( result.skipped ).toEqual( [
			{
				id: 'billing_first_name',
				reason: expect.stringContaining( 'WooCommerce owns' ),
			},
		] );

		const byId = Object.fromEntries(
			result.document.fields.map( ( f ) => [ f.id, f.enabled ] )
		);

		expect( byId ).toEqual( {
			a: false,
			billing_first_name: true,
			c: false,
		} );
	} );

	it( 'reports a field that is already in the target state', () => {
		const document = doc( [ custom( { id: 'a', enabled: false } ) ] );
		const result = archiveFields( document, [ 'a' ] );

		expect( result.ok ).toBe( false );
		expect( result.skipped[ 0 ].reason ).toMatch( /already in that state/ );
		expect( result.document ).toBe( document );
	} );

	it( 'enables many at once', () => {
		const document = doc( [
			custom( { id: 'a', enabled: false } ),
			custom( { id: 'b', enabled: true } ),
		] );

		const result = setFieldsEnabled( document, [ 'a', 'b' ], true );

		expect( result.applied ).toEqual( [ 'a' ] );
		expect( result.skipped ).toHaveLength( 1 );
	} );

	it( 'moves many fields to a section and renumbers them', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing', position: 10 } ),
			custom( { id: 'b', section: 'billing', position: 20 } ),
			custom( { id: 'c', section: 'shipping', position: 10 } ),
		] );

		const result = moveFieldsToSection(
			document,
			[ 'a', 'c' ],
			'shipping'
		);

		// `c` was already in the target section and is skipped; `a` is appended
		// after it, exactly as moving one field appends.
		expect( result.applied ).toEqual( [ 'a' ] );
		expect(
			fieldsInSection( result.document, 'shipping' ).map( ( f ) => f.id )
		).toEqual( [ 'c', 'a' ] );
		expect(
			fieldsInSection( result.document, 'shipping' ).map(
				( f ) => f.position
			)
		).toEqual( [ 10, 20 ] );
	} );

	it( 'sets one audience for many fields without touching the others', () => {
		const document = doc( [
			custom( {
				id: 'a',
				destinations: {
					admin_order: { enabled: true },
					public_api: { enabled: false },
				},
			} ),
			custom( {
				id: 'b',
				destinations: {
					admin_order: { enabled: true },
					public_api: { enabled: false },
				},
			} ),
		] );

		const result = setFieldsDestinations(
			document,
			[ 'a', 'b' ],
			'public_api',
			true
		);

		// Turning a destination on for many fields is the same statement as using each of
		// them there, so the use is written and the map follows it. The link the field
		// already had is read as a use of its own — which is what writing the list means —
		// and the destination it named keeps its link, untouched.
		const [ first, second ] = result.document.fields;

		expect(
			first?.bindings?.map( ( binding ) => binding.destination )
		).toEqual( [ 'admin_order', 'public_api' ] );
		expect( first?.bindings?.[ 1 ] ).toEqual(
			expect.objectContaining( {
				field_id: 'a',
				destination: 'public_api',
				container_id: '',
				visible: true,
			} )
		);
		expect( first?.destinations?.admin_order ).toEqual(
			expect.objectContaining( { enabled: true } )
		);
		expect( first?.destinations?.public_api ).toEqual(
			expect.objectContaining( { enabled: true } )
		);
		expect(
			second?.bindings?.map( ( binding ) => binding.destination )
		).toEqual( [ 'admin_order', 'public_api' ] );
	} );

	it( 'reports the impact before anything is applied', () => {
		const document = doc( [
			custom( { id: 'a', enabled: true } ),
			core( { id: 'billing_first_name', enabled: true } ),
			custom( { id: 'c', enabled: false } ),
		] );

		const impact = bulkImpact(
			document,
			[ 'a', 'billing_first_name', 'c' ],
			'archive'
		);

		expect( impact.total ).toBe( 3 );
		expect( impact.protected ).toEqual( [ 'billing_first_name' ] );
		expect( impact.unchanged ).toEqual( [ 'c' ] );
		expect( impact.affected ).toBe( 1 );
	} );

	it( 'does not treat a protected field as impacted when enabling', () => {
		const document = doc( [
			core( { id: 'billing_first_name', enabled: false } ),
		] );

		const impact = bulkImpact(
			document,
			[ 'billing_first_name' ],
			'enable'
		);

		expect( impact.protected ).toEqual( [] );
		expect( impact.affected ).toBe( 1 );
	} );

	it( 'never mutates the document it was given', () => {
		const document = doc( [
			custom( { id: 'a', enabled: true } ),
			custom( { id: 'b', enabled: true } ),
		] );
		const before = JSON.stringify( document );

		archiveFields( document, [ 'a', 'b' ] );
		moveFieldsToSection( document, [ 'a' ], 'shipping' );
		setFieldsDestinations( document, [ 'a' ], 'public_api', true );
		bulkImpact( document, [ 'a' ], 'archive' );

		expect( JSON.stringify( document ) ).toBe( before );
	} );

	it( 'refuses when nothing in the selection can change', () => {
		const document = doc( [
			core( { id: 'billing_first_name', enabled: true } ),
		] );

		expect( archiveFields( document, [ 'billing_first_name' ] ).ok ).toBe(
			false
		);
	} );
} );

describe( 'the fields a rule reads', () => {
	it( 'names the fields whose rules read the ones being changed', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing' } ),
			custom( {
				id: 'b',
				section: 'billing',
				conditions: {
					all: [
						{
							source: 'field',
							operator: 'not_empty',
							field: 'a',
						},
					],
				},
			} ),
		] );

		expect( conditionDependents( document, [ 'a' ] ) ).toEqual( [ 'b' ] );
	} );

	it( 'does not name a field the action is already changing', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing' } ),
			custom( {
				id: 'b',
				section: 'billing',
				conditions: {
					any: [
						{
							source: 'field',
							operator: 'not_empty',
							field: 'a',
						},
					],
				},
			} ),
		] );

		expect( conditionDependents( document, [ 'a', 'b' ] ) ).toEqual( [] );
	} );

	it( 'finds a reference nested inside a group', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing' } ),
			custom( {
				id: 'c',
				section: 'billing',
				conditions: {
					all: [
						{ source: 'cart_total', operator: 'gt', value: 100 },
						{
							any: [
								{
									source: 'field',
									operator: 'empty',
									field: 'a',
								},
							],
						},
					],
				},
			} ),
		] );

		expect( conditionDependents( document, [ 'a' ] ) ).toEqual( [ 'c' ] );
	} );

	it( 'says nothing when no rule reads the field', () => {
		const document = doc( [
			custom( { id: 'a', section: 'billing' } ),
			custom( { id: 'b', section: 'billing', conditions: {} } ),
		] );

		expect( conditionDependents( document, [ 'a' ] ) ).toEqual( [] );
	} );
} );

describe( 'legacy draft recovery', () => {
	it( 'repairs the invalid shapes left by an older local draft', () => {
		const document = doc(
			[
				custom( {
					id: 'wccs_e2e_file',
					type: 'file',
					section: 'checkout_fields',
					settings: {},
					conditions: {
						visible: {
							source: 'field',
							operator: 'equals',
							field: '',
							value: '',
						},
					},
					destinations: {
						customer_order: {
							enabled: true,
							section: 'customer_fields',
						},
					},
				} ),
			],
			[
				{
					id: 'checkout_fields',
					title: 'Checkout fields',
					description: '',
					position: 10,
					location: 'order',
					areas: [],
				},
				{
					id: 'customer_fields',
					title: 'Customer fields',
					description: '',
					position: 20,
					location: 'order',
					areas: [],
				},
			]
		);

		const result = repairLegacyDraft( document );

		expect( result.changed ).toBe( true );
		expect( result.document.sections[ 0 ].areas ).toEqual( [ 'checkout' ] );
		expect( result.document.sections[ 1 ].areas ).toEqual( [
			'customer_order',
		] );
		expect( result.document.fields[ 0 ].settings ).toEqual( {
			maxFiles: 1,
			allowedExtensions: [ 'pdf' ],
		} );
		expect( result.document.fields[ 0 ].conditions ).toEqual( {} );
		expect( result.document ).not.toBe( document );
		expect( document.sections[ 0 ].areas ).toEqual( [] );
	} );

	it( 'does not rewrite an already valid draft', () => {
		const document = doc(
			[ custom( { id: 'name', type: 'text', settings: {} } ) ],
			[
				{
					id: 'billing',
					title: 'Billing',
					description: '',
					position: 10,
					location: 'billing',
					areas: [ 'checkout' ],
				},
			]
		);

		const result = repairLegacyDraft( document );

		expect( result.changed ).toBe( false );
		expect( result.document ).toBe( document );
	} );
} );
