/**
 * What happens around the checkout form.
 *
 * Three behaviours, and each answers a line of section 10.
 *
 * **A field is checked when the customer leaves it**, not while they type. An
 * incomplete document is a normal state to be in, and calling it wrong before the
 * customer has finished is the friction the section warns about. That check may
 * have to ask the server, so it is asynchronous and nothing waits on it.
 *
 * **Submitting revalidates rather than remembering.** A "valid" answer from a
 * moment ago is not an authorization — section 10 says so in as many words — so
 * every rule this bundle can decide is decided again on the way out. A rule it
 * cannot decide keeps the last answer it was given, and an error it is showing
 * blocks, because the alternative is treating "we never rechecked" as "it passed".
 *
 * **The submit guard is synchronous, and that is a decision.** WooCommerce asks
 * its `checkout_place_order` handlers for an answer and takes `false` to mean
 * "abandon this order"; a promise is not `false` and would let every order
 * through. So the guard can only use what it can decide on the spot. A rule that
 * needs the server is not asked here: the server runs it again when the order is
 * placed, which is the same layer and the same moment, and a browser-side round
 * trip would add a state machine and a re-submit loop without enforcing anything
 * the submit does not already enforce.
 *
 * **A check that could not be made is not a rule that was released.** Section 10
 * requires that a critical rule must not silently allow a value through when the
 * check times out, and requires an explicit alternative or a block with guidance.
 * The alternative here is that the value is not released: the rule runs again on
 * the server when the order is placed, and the customer is told so rather than
 * stopped. Blocking would refuse a sale because a network was slow.
 */

import { STATUS } from './remote';

/**
 * The message shown when a rule could not be checked.
 *
 * Printed by the server with the rule, and this is the fallback for one that
 * arrived without a message.
 */
const UNCHECKED = 'This value will be checked when you place the order.';

/**
 * Creates the form behaviour.
 *
 * @param {Object} [options]           Options.
 * @param {any}    [options.checker]   Field checker, from `createFieldChecker`.
 * @param {any}    [options.errors]    Error surface, from `createFieldErrors`.
 * @param {string} [options.attribute] Attribute the server marks its fields with.
 * @return {any} Behaviour.
 */
export function createFormValidation( options = {} ) {
	const { checker, errors, attribute = 'data-wccs-field' } = options;

	/**
	 * Checks one field, asking the server if a rule needs it.
	 *
	 * @param {any} element Field element.
	 * @return {Promise<boolean>} Whether the field may proceed.
	 */
	async function checkField( element ) {
		if ( ! checker || ! errors ) {
			return true;
		}

		const answer = await checker.check( element );

		return render( element, answer );
	}

	/**
	 * Re-decides every field this bundle can decide, on the spot.
	 *
	 * @param {any} root Element to search under.
	 * @return {boolean} Whether the form may be submitted.
	 */
	function guard( root ) {
		if ( ! checker || ! errors ) {
			return true;
		}

		let allowed = true;

		root.querySelectorAll( `[${ attribute }]` ).forEach(
			( /** @type {any} */ element ) => {
				const answer = checker.checkLocal( element );

				if ( answer.status === STATUS.INVALID ) {
					allowed = false;

					render( element, answer );

					return;
				}

				if (
					answer.status === STATUS.VALID ||
					answer.status === STATUS.SKIP
				) {
					errors.clear( element );

					return;
				}

				// Not decidable here. The last answer stands rather than being
				// forgotten, and an error it is showing keeps the form on the page.
				if ( 'true' === element.getAttribute( 'aria-invalid' ) ) {
					allowed = false;
				}
			}
		);

		if ( ! allowed ) {
			errors.focusFirst( root );
		}

		return allowed;
	}

	/**
	 * Renders one answer.
	 *
	 * @param {any} element Field element.
	 * @param {any} answer  Answer.
	 * @return {boolean} Whether the field may proceed.
	 */
	function render( element, answer ) {
		if ( answer.status === STATUS.INVALID ) {
			errors.set( element, answer.message );

			return false;
		}

		if ( answer.status === STATUS.UNAVAILABLE ) {
			// Told, not blocked: the rule is enforced on the server at submit.
			errors.set( element, answer.message || UNCHECKED, false );

			return true;
		}

		errors.clear( element );

		return true;
	}

	return { checkField, guard, uncheckedMessage: UNCHECKED };
}
