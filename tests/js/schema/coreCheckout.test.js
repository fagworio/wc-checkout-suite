/**
 * The checkout the store already runs.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.2: the screen
 * loads the real checkout and does not start empty. What is under test here is the reading of
 * it — which native fields exist, which of them this document manages, and what adopting a
 * whole section of the store's checkout means — never a hard-coded template.
 */

import {
	adoptCoreSection,
	coreCheckout,
	hasCoreCheckout,
	typeWasRemapped,
	unmanagedCoreFields,
} from '../../../resources/admin/app/schema/coreCheckout';

/**
 * An inventory of two sections.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Inventory.
 */
function inventory( overrides = {} ) {
	const sections = [
		{
			key: 'billing',
			label: 'Cobrança',
			fields: [
				{
					id: 'billing_first_name',
					section: 'billing',
					label: 'Nome',
					type: 'text',
					nativeType: 'text',
					typeRemapped: false,
					required: true,
					priority: 10,
					classes: [],
					layout: { desktop: 6, tablet: 6, mobile: 12 },
					protected: true,
				},
				{
					id: 'billing_phone',
					section: 'billing',
					label: 'Telefone',
					type: 'tel',
					nativeType: 'tel',
					typeRemapped: false,
					required: true,
					priority: 20,
					classes: [],
					layout: { desktop: 6, tablet: 6, mobile: 12 },
					protected: true,
				},
			],
		},
		{
			key: 'order',
			label: 'Pedido',
			fields: [
				{
					id: 'order_comments',
					section: 'order',
					label: 'Observações',
					type: 'textarea',
					nativeType: 'textarea',
					typeRemapped: false,
					required: false,
					priority: 10,
					classes: [],
					layout: { desktop: 12, tablet: 12, mobile: 12 },
					protected: true,
				},
			],
		},
	];

	return {
		available: true,
		reason: '',
		sections,
		// The server reports the same fields in both shapes, and the reading has to agree
		// with it.
		fields: sections.flatMap( ( section ) => section.fields ),
		...overrides,
	};
}

/**
 * A document with the given fields.
 *
 * @param {any[]} fields Fields.
 * @return {any} Document.
 */
function doc( fields = [] ) {
	return { revision: 1, fields, sections: [], settings: {} };
}

/**
 * A definition of one native field.
 *
 * @param {string} id Identifier.
 * @return {any} Field.
 */
function definition( id ) {
	return {
		id,
		integration_id: id,
		origin: 'core',
		type: 'text',
		label: id,
		section: 'billing',
		enabled: true,
		required: true,
		position: 10,
		layout: { desktop: 12, tablet: 12, mobile: 12 },
		settings: {},
		conditions: {},
		storage: { scope: 'order', sensitivity: 'personal' },
		destinations: {},
	};
}

describe( 'reading the store checkout', () => {
	it( 'lists the sections and the native fields of the store', () => {
		const sections = coreCheckout( inventory(), doc() );

		expect( sections.map( ( section ) => section.key ) ).toEqual( [
			'billing',
			'order',
		] );
		expect( sections[ 0 ].label ).toBe( 'Cobrança' );
		expect(
			sections[ 0 ].fields.map( ( field ) => field.entry.id )
		).toEqual( [ 'billing_first_name', 'billing_phone' ] );
		// WooCommerce's own order, not the order of this plugin's document.
		expect(
			sections[ 0 ].fields.map( ( field ) => field.entry.priority )
		).toEqual( [ 10, 20 ] );
	} );

	it( 'says how much of each section this document manages', () => {
		const sections = coreCheckout(
			inventory(),
			doc( [ definition( 'billing_first_name' ) ] )
		);

		expect( sections[ 0 ].managed ).toBe( 1 );
		expect( sections[ 0 ].total ).toBe( 2 );
		expect( sections[ 0 ].fields[ 0 ].managed ).toBe( true );
		expect( sections[ 0 ].fields[ 1 ].managed ).toBe( false );
		expect( sections[ 1 ].managed ).toBe( 0 );
		expect( sections[ 1 ].total ).toBe( 1 );
	} );

	it( 'counts only the fields that can still be adopted', () => {
		expect( unmanagedCoreFields( inventory(), doc() ) ).toHaveLength( 3 );
		expect(
			unmanagedCoreFields(
				inventory(),
				doc( [ definition( 'billing_first_name' ) ] )
			).map( ( entry ) => entry.id )
		).toEqual( [ 'billing_phone', 'order_comments' ] );
	} );

	it( 'reads nothing when the store checkout could not be read', () => {
		const absent = inventory( { available: false, reason: 'no WC' } );

		expect( hasCoreCheckout( absent ) ).toBe( false );
		expect( coreCheckout( absent, doc() ) ).toEqual( [] );
		expect( unmanagedCoreFields( absent, doc() ) ).toEqual( [] );
	} );

	it( 'reports a native type that had to be mapped', () => {
		expect( typeWasRemapped( { typeRemapped: true } ) ).toBe( true );
		expect( typeWasRemapped( { typeRemapped: false } ) ).toBe( false );
	} );
} );

describe( 'adopting a section of the store checkout', () => {
	it( 'adopts every field of the section, in the order the store runs it', () => {
		const sections = coreCheckout( inventory(), doc() );
		const result = adoptCoreSection( doc(), sections[ 0 ] );

		expect( result.ok ).toBe( true );
		// The identity is WooCommerce's, which is what makes this an override of the real
		// field and not a copy of it.
		expect( result.document.fields.map( ( field ) => field.id ) ).toEqual( [
			'billing_first_name',
			'billing_phone',
		] );
		expect( result.field?.id ).toBe( 'billing_first_name' );
		expect(
			result.document.fields.map( ( field ) => field.origin )
		).toEqual( [ 'core', 'core' ] );
	} );

	it( 'keeps the real order, label and required flag of each native field', () => {
		const sections = coreCheckout( inventory(), doc() );
		const result = adoptCoreSection( doc(), sections[ 1 ] );

		const [ adopted ] = result.document.fields;

		expect( adopted.id ).toBe( 'order_comments' );
		expect( adopted.section ).toBe( 'order' );
		expect( adopted.label ).toBe( 'Observações' );
		expect( adopted.position ).toBe( 10 );
		expect( adopted.required ).toBe( false );
	} );

	it( 'leaves the fields it already manages as they are', () => {
		const existing = definition( 'billing_first_name' );
		const sections = coreCheckout( inventory(), doc( [ existing ] ) );

		const result = adoptCoreSection(
			doc( [ { ...existing, label: 'Nome como o lojista escreveu' } ] ),
			sections[ 0 ]
		);

		expect( result.document.fields ).toHaveLength( 2 );
		expect( result.document.fields[ 0 ].label ).toBe(
			'Nome como o lojista escreveu'
		);
		expect( result.document.fields[ 1 ].id ).toBe( 'billing_phone' );
	} );

	it( 'has nothing to do when the section is fully managed', () => {
		const sections = coreCheckout(
			inventory(),
			doc( [
				definition( 'billing_first_name' ),
				definition( 'billing_phone' ),
			] )
		);

		const result = adoptCoreSection(
			doc( [
				definition( 'billing_first_name' ),
				definition( 'billing_phone' ),
			] ),
			sections[ 0 ]
		);

		expect( result.ok ).toBe( false );
		expect( result.document.fields ).toHaveLength( 2 );
	} );
} );
