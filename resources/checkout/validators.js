/**
 * The Brazilian document rules, in the browser.
 *
 * These exist so a customer is told about a wrong document while they are still
 * on the field, and for nothing else. The server revalidates the canonical value
 * on submit and is the authority; an answer from here is a hint, and section 10
 * is explicit that the same critical rule is checked again on the server.
 *
 * There is one deliberate difference from the PHP validator, and it follows from
 * where each of them sits in the flow. The server validates the **canonical**
 * value, because the named normalizer has already removed the punctuation and it
 * is the only thing allowed to transform a document. This side validates what is
 * **in the field**, which is the formatted value the mask produced. So it removes
 * the same recognised punctuation before checking the digits — not to store
 * anything, but to read what the customer is looking at. Both behaviours are
 * asserted on their own side, so the asymmetry is a decision rather than a drift.
 *
 * A passing check digit means the number is well formed. It says nothing about
 * whether the person exists, whether the registration is active, or whether the
 * document belongs to whoever typed it, and no message built on these functions
 * may suggest otherwise.
 */

/**
 * Punctuation the Brazilian formats are written with.
 *
 * The same set as the server's named normalizer, and written out rather than
 * derived from it: the two live in different languages and a shared constant is
 * not available, so the fixture set is what keeps them together.
 */
const RECOGNISED = /[./\-()+\s]/g;

/**
 * The length of a CPF.
 */
const CPF_LENGTH = 11;

/**
 * The length of a CNPJ.
 */
const CNPJ_LENGTH = 14;

/**
 * Weights of the first CPF check digit, left to right.
 */
const CPF_FIRST = [ 10, 9, 8, 7, 6, 5, 4, 3, 2 ];

/**
 * Weights of the second CPF check digit.
 */
const CPF_SECOND = [ 11, 10, 9, 8, 7, 6, 5, 4, 3, 2 ];

/**
 * Weights of the first CNPJ check digit.
 */
const CNPJ_FIRST = [ 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ];

/**
 * Weights of the second CNPJ check digit.
 */
const CNPJ_SECOND = [ 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ];

/**
 * Removes the recognised punctuation and uppercases.
 *
 * Uppercasing is not cosmetic: a lowercase document and an uppercase one are the
 * same document, and the arithmetic works on the uppercase value.
 *
 * @param {any} typed What is in the field.
 * @return {string} The value the arithmetic is done on.
 */
function canonical( typed ) {
	if ( 'string' !== typeof typed ) {
		return '';
	}

	return typed.replace( RECOGNISED, '' ).toUpperCase();
}

/**
 * Whether a value is exactly this many digits.
 *
 * @param {string} value  Value.
 * @param {number} length Expected length.
 * @return {boolean} Whether it matches.
 */
function isDigits( value, length ) {
	return value.length === length && /^[0-9]+$/.test( value );
}

/**
 * One check digit.
 *
 * A digit is worth its face value and `A` starts at 17, which is
 * `charCodeAt( 0 ) - 48` for every character a CNPJ accepts.
 *
 * @param {string}   value   Characters it is computed from.
 * @param {number[]} weights Weights, left to right.
 * @return {number} The digit.
 */
function checkDigit( value, weights ) {
	let sum = 0;

	weights.forEach( ( weight, index ) => {
		sum += ( value.charCodeAt( index ) - 48 ) * weight;
	} );

	const remainder = sum % 11;

	return remainder < 2 ? 0 : 11 - remainder;
}

/**
 * Whether what is in the field is a well-formed CPF.
 *
 * @param {any} typed What is in the field.
 * @return {boolean} Whether it is well formed.
 */
export function cpf( typed ) {
	const value = canonical( typed );

	if ( ! isDigits( value, CPF_LENGTH ) ) {
		return false;
	}

	// One repeated digit satisfies the arithmetic and is not a document anybody
	// was issued, which is why every implementation refuses it.
	if ( /^(\d)\1{10}$/.test( value ) ) {
		return false;
	}

	return (
		checkDigit( value.slice( 0, 9 ), CPF_FIRST ) === Number( value[ 9 ] ) &&
		checkDigit( value.slice( 0, 10 ), CPF_SECOND ) === Number( value[ 10 ] )
	);
}

/**
 * Whether what is in the field is a well-formed CNPJ.
 *
 * Both accepted formats go through the same arithmetic, because they are one
 * document: the twelve leading positions are alphanumeric and the two check
 * digits are always numeric.
 *
 * @param {any} typed What is in the field.
 * @return {boolean} Whether it is well formed.
 */
export function cnpj( typed ) {
	const value = canonical( typed );

	if (
		CNPJ_LENGTH !== value.length ||
		! /^[0-9A-Z]{12}[0-9]{2}$/.test( value )
	) {
		return false;
	}

	return (
		checkDigit( value.slice( 0, 12 ), CNPJ_FIRST ) ===
			Number( value[ 12 ] ) &&
		checkDigit( value.slice( 0, 13 ), CNPJ_SECOND ) ===
			Number( value[ 13 ] )
	);
}

/**
 * Whether what is in the field is a postcode.
 *
 * A postcode has no check digit: the rule is the format, and pretending to
 * compute one would be inventing a rule the document does not have.
 *
 * @param {any} typed What is in the field.
 * @return {boolean} Whether it is well formed.
 */
export function postcode( typed ) {
	return isDigits( canonical( typed ), 8 );
}

/**
 * Whether what is in the field is a telephone number of this length.
 *
 * @param {string} value  Canonical value.
 * @param {number} length Expected number of digits.
 * @return {boolean} Whether it is well formed.
 */
function isPhone( value, length ) {
	if ( ! isDigits( value, length ) ) {
		return false;
	}

	const area = Number( value.slice( 0, 2 ) );

	return area >= 11 && area <= 99;
}

/**
 * Whether what is in the field is a Brazilian landline number.
 *
 * @param {any} typed What is in the field.
 * @return {boolean} Whether it is well formed.
 */
export function landline( typed ) {
	return isPhone( canonical( typed ), 10 );
}

/**
 * Whether what is in the field is a Brazilian mobile number.
 *
 * @param {any} typed What is in the field.
 * @return {boolean} Whether it is well formed.
 */
export function mobile( typed ) {
	const value = canonical( typed );

	return isPhone( value, 11 ) && '9' === value[ 2 ];
}

/**
 * The validators, by the key a definition names.
 *
 * The keys are the registry's, so a definition validated here is validated by the
 * same name on the server. `br.rg` is absent on purpose: the format depends on
 * the issuing state and no national rule was invented.
 *
 * Typed as a lookup rather than as a closed object: a caller has a key from a
 * definition and needs to ask whether that key is one this side knows, which a
 * fixed set of properties cannot answer.
 *
 * @type {Record<string, (typed: any) => boolean>}
 */
export const validators = {
	'br.cpf': cpf,
	'br.cnpj': cnpj,
	'br.cep': postcode,
	'br.phone.landline': landline,
	'br.phone.mobile': mobile,
};
