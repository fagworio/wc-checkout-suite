/**
 * The uses of a field.
 *
 * The destination map holds one entry per destination, so it cannot say that a field is used
 * twice in the same place, nor that the same value is written in one area and only read in
 * another. That is what the list of uses is for, and this suite pins the rules that keep the
 * list and the map from becoming two answers to one question.
 */

import {
	addBinding,
	bindingIdentifier,
	bindingsFor,
	bindingsOf,
	destinationIsOn,
	ensureBinding,
	linkOf,
	mapOf,
	rebindFor,
	removeBinding,
	removeDestinationBindings,
	updateBinding,
	withBindings,
} from '../../../resources/admin/app/schema/bindings';

/**
 * A field with the given keys.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'autorizacao',
		type: 'file',
		label: 'Autorização',
		section: 'billing',
		destinations: {},
		...overrides,
	};
}

/**
 * One stored use.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Use.
 */
function use( overrides = {} ) {
	return {
		id: 'autorizacao@customer_order/documentos',
		field_id: 'autorizacao',
		container_id: 'documentos',
		destination: 'customer_order',
		position: 10,
		visible: true,
		editable: true,
		label_override: '',
		permissions: [],
		...overrides,
	};
}

describe( 'reading the uses of a field', () => {
	it( 'reads the list the server sent', () => {
		const subject = field( {
			bindings: [ use(), use( { id: 'outro', container_id: 'anexos' } ) ],
		} );

		expect( bindingsOf( subject ) ).toHaveLength( 2 );
		expect( bindingsFor( subject, 'customer_order' ) ).toHaveLength( 2 );
		expect( bindingsFor( subject, 'admin_order' ) ).toHaveLength( 0 );
		expect( destinationIsOn( subject, 'customer_order' ) ).toBe( true );
	} );

	it( 'reads a document written before the split through its map', () => {
		const subject = field( {
			destinations: {
				customer_order: {
					enabled: true,
					section: 'documentos',
					title: 'Documento enviado',
					position: 20,
					mode: 'view',
					actions: [ 'show_metadata', 'view' ],
				},
				admin_order: { enabled: false },
			},
		} );

		const [ only ] = bindingsOf( subject );

		expect( bindingsOf( subject ) ).toHaveLength( 1 );
		expect( only.destination ).toBe( 'customer_order' );
		expect( only.container_id ).toBe( 'documentos' );
		expect( only.label_override ).toBe( 'Documento enviado' );
		expect( only.position ).toBe( 20 );
		expect( only.editable ).toBe( false );
		expect( only.permissions ).toEqual( [ 'show_metadata', 'view' ] );
		expect( destinationIsOn( subject, 'admin_order' ) ).toBe( false );
	} );

	it( 'names a use by the field, the destination and the container', () => {
		expect(
			bindingIdentifier( 'autorizacao', 'customer_order', 'docs' )
		).toBe( 'autorizacao@customer_order/docs' );
		expect( bindingIdentifier( 'autorizacao', 'public_api', '' ) ).toBe(
			'autorizacao@public_api'
		);
	} );
} );

describe( 'writing the uses', () => {
	it( 'adds a use, numbered so it does not answer to the same name', () => {
		const subject = field( { bindings: [ use() ] } );
		const added = addBinding( subject, 'customer_order', 'documentos' );

		expect( added ).toHaveLength( 2 );
		expect( added[ 1 ].container_id ).toBe( 'documentos' );
		expect( added[ 1 ].destination ).toBe( 'customer_order' );
		expect( added[ 1 ].field_id ).toBe( 'autorizacao' );
		expect( added[ 1 ].id ).not.toBe( added[ 0 ].id );
		// The second use is ordered after the first, so it appears after it.
		expect( Number( added[ 1 ].position ) ).toBeGreaterThan(
			Number( added[ 0 ].position )
		);
	} );

	it( 'renames a use when its container changes, keeping it unique', () => {
		const subject = field( {
			bindings: [
				use(),
				use( {
					id: 'autorizacao@customer_order/anexos',
					container_id: 'anexos',
				} ),
			],
		} );

		const renamed = updateBinding( subject, subject.bindings[ 1 ].id, {
			container_id: 'documentos',
		} );

		expect( renamed[ 1 ].container_id ).toBe( 'documentos' );
		expect( renamed[ 1 ].id ).not.toBe( renamed[ 0 ].id );
		expect( renamed[ 0 ].id ).toBe( subject.bindings[ 0 ].id );
	} );

	it( 'removes one use without touching the others', () => {
		const subject = field( {
			bindings: [
				use(),
				use( {
					id: 'autorizacao@customer_order/anexos',
					container_id: 'anexos',
				} ),
				use( {
					id: 'autorizacao@admin_order/docs',
					container_id: 'docs',
					destination: 'admin_order',
				} ),
			],
		} );

		const left = removeBinding( subject, subject.bindings[ 1 ].id );

		expect( left ).toHaveLength( 2 );
		expect( left.map( ( binding ) => binding.container_id ) ).toEqual( [
			'documentos',
			'docs',
		] );
	} );

	it( 'turns a destination off by removing its uses', () => {
		const subject = field( {
			bindings: [
				use(),
				use( { id: 'outro', container_id: 'anexos' } ),
				use( { id: 'terceiro', destination: 'admin_order' } ),
			],
		} );

		const left = removeDestinationBindings( subject, 'customer_order' );

		expect( left ).toHaveLength( 1 );
		expect( left[ 0 ].destination ).toBe( 'admin_order' );
	} );

	it( 'turns a destination on by giving it a first use', () => {
		const subject = field( {
			destinations: { admin_order: { enabled: false } },
		} );
		const on = ensureBinding( subject, 'admin_order' );

		expect( on ).toHaveLength( 1 );
		expect( on[ 0 ].destination ).toBe( 'admin_order' );
		// The first use names no container, which means the field's own section.
		expect( on[ 0 ].container_id ).toBe( '' );

		// Asking again changes nothing: the switch is not a second use.
		expect(
			ensureBinding( { ...subject, bindings: on }, 'admin_order' )
		).toHaveLength( 1 );
	} );

	it( 'rebinds the uses of a duplicated field', () => {
		const copied = rebindFor(
			bindingsOf( field( { bindings: [ use() ] } ) ),
			'copia'
		);

		expect( copied[ 0 ].field_id ).toBe( 'copia' );
		expect( copied[ 0 ].id ).toBe( 'copia@customer_order/documentos' );
	} );
} );

describe( 'the map the list projects to', () => {
	it( 'writes the link of one use', () => {
		expect(
			linkOf(
				use( {
					container_id: 'documentos',
					label_override: 'Documento enviado',
					position: 20,
					permissions: [ 'show_metadata', 'view' ],
					editable: false,
				} )
			)
		).toEqual( {
			enabled: true,
			section: 'documentos',
			title: 'Documento enviado',
			position: 20,
			actions: [ 'show_metadata', 'view' ],
			mode: 'view',
		} );
	} );

	it( 'carries the last use of a destination, which is all a map can say', () => {
		const subject = field( {
			bindings: [
				use( { label_override: 'Primeiro' } ),
				use( {
					id: 'outro',
					container_id: 'anexos',
					label_override: 'Segundo',
				} ),
			],
		} );

		const map = mapOf( subject, bindingsOf( subject ) );

		expect( map.customer_order.title ).toBe( 'Segundo' );
	} );

	it( 'does not leave a destination saying it is on when no use justifies it', () => {
		const subject = field( {
			destinations: {
				admin_order: { enabled: true, section: 'equipa' },
				customer_email: { enabled: false },
			},
			bindings: [ use() ],
		} );

		const keys = withBindings( subject, bindingsOf( subject ) );

		expect( keys.bindings ).toHaveLength( 1 );
		expect( keys.destinations.admin_order ).toBeUndefined();
		expect( keys.destinations.customer_order.enabled ).toBe( true );
		// A link that is off stays: it is configuration the merchant typed.
		expect( keys.destinations.customer_email ).toEqual( {
			enabled: false,
		} );
	} );
} );
