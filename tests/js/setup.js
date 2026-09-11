/**
 * Jest setup for the JavaScript suite.
 *
 * Two accommodations are made here, both about the environment rather than
 * about the components:
 *
 * 1. `@testing-library/jest-dom` adds the DOM assertions the suite reads.
 *
 * 2. jsdom 26 models the `open` attribute of `<dialog>` but does not implement
 *    `showModal()` or `close()`. Without them the component could not be tested
 *    at all, so the modal API is shimmed down to what jsdom can express: the
 *    open state. What this proves is that the component asks the platform to
 *    open and close at the right moments. Focus containment, the backdrop and
 *    focus restoration are native browser behaviour and are verified in a real
 *    browser in WCCS-063, not here.
 */

import '@testing-library/jest-dom';

if ( typeof window !== 'undefined' && window.HTMLDialogElement ) {
	const proto = window.HTMLDialogElement.prototype;

	if ( typeof proto.showModal !== 'function' ) {
		proto.showModal = function showModal() {
			this.setAttribute( 'open', '' );
		};
	}

	if ( typeof proto.close !== 'function' ) {
		proto.close = function close() {
			this.removeAttribute( 'open' );
		};
	}
}
