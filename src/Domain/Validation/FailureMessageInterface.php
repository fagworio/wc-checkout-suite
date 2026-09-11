<?php
/**
 * A validator that can say what it refuses with.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

/**
 * Describes the failure a validator produces, before it produces it.
 *
 * The checkout has to show the customer the same sentence the server would, and
 * the alternative to asking for it is writing the wording a second time in
 * JavaScript. A message maintained in two languages is a message that says two
 * different things the first time one of them is edited.
 *
 * Implementing this is optional. A validator that does not is still run on the
 * server; the browser simply has nothing to say about the field in advance.
 *
 * @see \ROADMAP.md section 10
 */
interface FailureMessageInterface {

	/**
	 * The stable code a refusal carries.
	 *
	 * @return string
	 */
	public function failure_code(): string;

	/**
	 * The message a refusal carries.
	 *
	 * @return string
	 */
	public function failure_message(): string;
}
