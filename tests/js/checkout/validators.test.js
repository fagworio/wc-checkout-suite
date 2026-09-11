/**
 * Brazilian validator tests, against the shared fixtures.
 *
 * @package
 */

import fixtures from '../../../resources/fixtures/br-documents.json';
import { validators } from '../../../resources/checkout/validators';

/**
 * The fixture lists, by validator key.
 *
 * Typed as a lookup: an imported JSON file has a closed type, and a caller here
 * holds a key read from the file itself.
 *
 * @type {Record<string, {valid: Array<{value: string, why: string}>, invalid: Array<{value: string, why: string}>}>}
 */
const validity = /** @type {any} */ ( fixtures.validity );

/**
 * The validator keys the fixtures describe, without the one that has none.
 *
 * @return {string[]} Keys.
 */
function fixtureKeys() {
	return Object.keys( validity ).filter( ( key ) => 'br.rg' !== key );
}

describe( 'the Brazilian document rules in the browser', () => {
	it( 'covers every document the fixtures describe', () => {
		expect( Object.keys( validators ).sort() ).toEqual(
			fixtureKeys().sort()
		);
	} );

	it( 'has no rule for RG, because there is no national one', () => {
		expect( validators ).not.toHaveProperty( 'br.rg' );
		expect( validity[ 'br.rg' ] ).toHaveProperty( 'note' );
	} );

	it.each( fixtureKeys() )( 'accepts every valid %s fixture', ( key ) => {
		validity[ key ].valid.forEach( ( entry ) => {
			expect( validators[ key ]( entry.value ) ).toBe( true );
		} );
	} );

	it.each( fixtureKeys() )( 'refuses every invalid %s fixture', ( key ) => {
		validity[ key ].invalid.forEach( ( entry ) => {
			expect( validators[ key ]( entry.value ) ).toBe( false );
		} );
	} );

	it( 'reproduces the published alphanumeric examples', () => {
		expect( validators[ 'br.cnpj' ]( '12ABC34501DE35' ) ).toBe( true );
		expect( validators[ 'br.cnpj' ]( 'UKPVME1E8HI996' ) ).toBe( true );
	} );

	it( 'accepts every value the server refuses for being non-canonical', () => {
		// The shared fixture says which side answers which way, rather than
		// leaving the difference to be discovered. The server validates the value
		// it stores and refuses anything the normalizer did not produce; this side
		// validates what is in the field, which is the formatted value the mask
		// produced.
		expect( fixtures.not_canonical.refused_by ).toBe( 'server' );
		expect( fixtures.not_canonical.accepted_by ).toBe( 'browser' );

		fixtures.not_canonical.cases.forEach( ( entry ) => {
			expect( validators[ entry.validator ]( entry.value ) ).toBe( true );
		} );
	} );

	it( 'treats a lowercase document as the same document', () => {
		// The mask preserves the case the customer typed; the canonical form is
		// uppercase, so the arithmetic is done on the uppercase value.
		expect( validators[ 'br.cnpj' ]( '12abc34501de35' ) ).toBe( true );
		expect( validators[ 'br.cnpj' ]( 'ukpvmE1e8hi996' ) ).toBe( true );
	} );

	it( 'refuses a CNPJ whose check digits are letters', () => {
		// The answer to the question ADR-0003 left open: twelve alphanumeric
		// positions and two numeric check digits.
		expect( validators[ 'br.cnpj' ]( '12ABC34501DE3A' ) ).toBe( false );
	} );

	it( 'refuses a document made of one repeated digit', () => {
		expect( validators[ 'br.cpf' ]( '11111111111' ) ).toBe( false );
	} );

	it( 'refuses anything that is not text', () => {
		[ 'br.cpf', 'br.cnpj', 'br.cep', 'br.phone.mobile' ].forEach(
			( key ) => {
				expect( validators[ key ]( null ) ).toBe( false );
				expect( validators[ key ]( 12345678909 ) ).toBe( false );
			}
		);
	} );

	it( 'refuses a number of the wrong length', () => {
		expect( validators[ 'br.cpf' ]( '5299822472' ) ).toBe( false );
		expect( validators[ 'br.cep' ]( '0131010' ) ).toBe( false );
		expect( validators[ 'br.phone.mobile' ]( '1199999888' ) ).toBe( false );
	} );
} );
