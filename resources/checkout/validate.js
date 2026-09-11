/**
 * Checking the Suite's own fields, locally or on the server.
 *
 * Section 10 asks for the critical rules to be checked in the browser **and**
 * again on the server, and for AJAX to be used only when the answer needs
 * something the browser does not have. So the rule decides where it is checked:
 * a rule the bundle can implement is checked here, and a rule whose key this
 * bundle does not know is a rule only the server can answer, which is what the
 * endpoint from WCCS-029 is for.
 *
 * The wording is not written here. Every message travels from the server with its
 * rule, because a message maintained in two languages is a message that says two
 * different things the first time one of them is edited.
 */

import { STATUS, createRemoteValidation } from './remote';
import { validators } from './validators';

/**
 * The checker.
 *
 * @typedef {Object} FieldChecker
 * @property {(element: any) => Promise<{status: string, code?: string, message?: string}>} check      Asks, locally or of the server.
 * @property {(element: any) => {status: string, code?: string, message?: string}}          checkLocal Decides on the spot, locally only.
 */

/**
 * A rule as the server published it.
 *
 * @typedef {Object} PublishedRule
 * @property {string} key     Validator key.
 * @property {string} code    Code a failure produces.
 * @property {string} message Message the customer reads.
 */

/**
 * Creates the validator for a page's fields.
 *
 * @param {Object}                                  [options]           Options.
 * @param {Record<string, PublishedRule[]>}         [options.rules]     Rules by field identifier, as the server published them.
 * @param {any}                                     [options.remote]    Transport, or null to check only what is local.
 * @param {Record<string, (typed: any) => boolean>} [options.local]     The validators this bundle knows.
 * @param {string}                                  [options.attribute] Attribute the server marks its fields with.
 * @return {FieldChecker} Checker.
 */
export function createFieldChecker( options = {} ) {
	const {
		rules = {},
		remote = null,
		local = validators,
		attribute = 'data-wccs-field',
	} = options;

	/**
	 * Checks one element.
	 *
	 * Always asynchronous, because a rule may have to be asked about, and a caller
	 * that sometimes gets a boolean and sometimes a promise will get it wrong on
	 * the branch it did not test.
	 *
	 * @param {any} element Field element.
	 * @return {Promise<{status: string, code?: string, message?: string}>} Answer.
	 */
	async function check( element ) {
		const field = element.getAttribute( attribute );
		const declared = field ? rules[ field ] : null;

		if ( ! declared || 0 === declared.length ) {
			return { status: STATUS.SKIP };
		}

		const value = element.value;

		for ( const entry of declared ) {
			const known = local[ entry.key ];

			if ( 'function' === typeof known ) {
				if ( ! known( value ) ) {
					return {
						status: STATUS.INVALID,
						code: entry.code,
						message: entry.message,
					};
				}

				continue;
			}

			// A rule this bundle cannot check is a rule only the server can
			// answer. Without a transport there is nothing to say, and saying
			// "valid" would be inventing a check that did not happen.
			if ( ! remote ) {
				return { status: STATUS.UNAVAILABLE, code: 'no_transport' };
			}

			const answer = await remote.check( field, value );

			if ( answer.status === STATUS.INVALID ) {
				return {
					status: STATUS.INVALID,
					code: answer.code || entry.code,
					message: answer.message || entry.message,
				};
			}

			if ( answer.status !== STATUS.VALID ) {
				return {
					status: STATUS.UNAVAILABLE,
					code: answer.code || 'unavailable',
				};
			}
		}

		return { status: STATUS.VALID };
	}

	/**
	 * Decides one element using only the rules this bundle implements.
	 *
	 * Synchronous by necessity: WooCommerce asks its submit handlers for an answer
	 * on the spot, and a promise is not an answer. A rule this bundle does not
	 * know is reported as undecidable rather than as a pass — the submit guard
	 * keeps whatever the last answer was instead of forgetting it.
	 *
	 * @param {any} element Field element.
	 * @return {{status: string, code?: string, message?: string}} Answer.
	 */
	function checkLocal( element ) {
		const field = element.getAttribute( attribute );
		const declared = field ? rules[ field ] : null;

		if ( ! declared || 0 === declared.length ) {
			return { status: STATUS.SKIP };
		}

		for ( const entry of declared ) {
			const known = local[ entry.key ];

			if ( 'function' !== typeof known ) {
				return { status: STATUS.UNAVAILABLE, code: 'needs_server' };
			}

			if ( ! known( element.value ) ) {
				return {
					status: STATUS.INVALID,
					code: entry.code,
					message: entry.message,
				};
			}
		}

		return { status: STATUS.VALID };
	}

	return { check, checkLocal };
}

export { createRemoteValidation };
