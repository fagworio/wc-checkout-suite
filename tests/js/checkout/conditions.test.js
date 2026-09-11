/**
 * Condition engine tests, browser half.
 *
 * These are the same cases the PHP suite reads. `resources/fixtures/conditions.json`
 * is one file with one authored expectation per case, and both engines are held to
 * it — which is what "paridade" means here: not that two implementations look
 * alike, but that they answer alike on the inputs where they could differ.
 *
 * The fixture file is also asserted to exercise every operator and every source,
 * because a case set that quietly lost one would leave the parity of that operator
 * untested while every remaining assertion stayed green.
 */

import { evaluate } from '../../../resources/checkout/conditions';
import FIXTURE from '../../../resources/fixtures/conditions.json';

/**
 * One fixture case.
 *
 * @type {Array<{group: string, name: string, tree: any, context: any, expect: boolean, note?: string}>}
 */
const CASES = /** @type {any} */ ( FIXTURE ).cases;

describe( 'condition engine', () => {
	it.each(
		CASES.map( ( entry ) => [ `${ entry.group }: ${ entry.name }`, entry ] )
	)( '%s', ( _name, entry ) => {
		expect( evaluate( entry.tree, entry.context ) ).toBe( entry.expect );
	} );

	it( 'covers every word of the acceptance', () => {
		const groups = [
			...new Set( CASES.map( ( entry ) => entry.group ) ),
		].sort();

		expect( groups ).toEqual( [
			'comparison',
			'context',
			'empty',
			'false',
			'groups',
			'lists',
			'membership',
			'negation',
			'unreadable',
			'zero',
		] );
	} );

	it( 'exercises every operator and every source of the vocabulary', () => {
		/** @type {Record<string, boolean>} */
		const operators = {};
		/** @type {Record<string, boolean>} */
		const sources = {};

		/**
		 * Collects what a node reads.
		 *
		 * @param {any} node Node.
		 * @return {void}
		 */
		const collect = ( node ) => {
			if ( ! node || 'object' !== typeof node ) {
				return;
			}

			if (
				'string' === typeof node.source &&
				'string' === typeof node.operator
			) {
				sources[ node.source ] = true;
				operators[ node.operator ] = true;
			}

			for ( const child of node.all ?? node.any ?? [] ) {
				collect( child );
			}
		};

		CASES.forEach( ( entry ) => collect( entry.tree ) );

		expect(
			/** @type {any} */ ( FIXTURE ).vocabulary.operators.filter(
				( /** @type {string} */ key ) => ! operators[ key ]
			)
		).toEqual( [] );
		expect(
			/** @type {any} */ ( FIXTURE ).vocabulary.sources.filter(
				( /** @type {string} */ key ) => ! sources[ key ]
			)
		).toEqual( [] );
	} );

	it( 'treats a missing context as an empty one rather than as an error', () => {
		expect( evaluate( { source: 'country', operator: 'is_empty' } ) ).toBe(
			true
		);
	} );

	it( 'reads a rule written as a group of one', () => {
		expect(
			evaluate(
				{
					all: [
						{ source: 'country', operator: 'equals', value: 'BR' },
					],
				},
				{ country: 'BR' }
			)
		).toBe( true );
	} );
} );
