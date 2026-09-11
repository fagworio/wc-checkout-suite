/**
 * Classic checkout entry point.
 *
 * Wires the component lifecycle to the two events WooCommerce fires around its
 * AJAX refresh, and to the first page load.
 *
 * jQuery is not a preference here: the classic checkout fires `update_checkout`
 * and `updated_checkout` as jQuery events on `document.body`, and a jQuery event
 * does not reach a native DOM listener. Listening for them any other way would
 * mean never hearing them.
 *
 * The order the two components are registered in is load-bearing, and it is the
 * reason they are registered next to each other rather than in their own modules.
 * A mask created over an element that already holds a value formats it; a mask
 * created over an empty element does not reformat a value assigned afterwards. So
 * the keeper restores first and the mask is built over the restored value — with
 * the order reversed, a field replaced by a refresh comes back unformatted inside
 * a masked input, which is the failure the acceptance is about.
 */

// jQuery is not a package this project depends on: it is a WordPress runtime
// global, and the build externalises the import to `window.jQuery` and records
// `jquery` in the asset manifest so WordPress loads it first. Installing a copy
// here would suggest the version in node_modules is the version that runs, and
// the version that runs is WordPress's.
// eslint-disable-next-line import/no-unresolved
import $ from 'jquery';

import { createFieldErrors } from './errors';
import { createFormValidation } from './form';
import { createLifecycle } from './lifecycle';
import { createMaskedFields } from './masks';
import { createRemoteValidation } from './remote';
import { createFieldChecker } from './validate';
import { createValueKeeper } from './values';

const bootstrap = window.wccsCheckout || {};

const lifecycle = createLifecycle();
const keeper = createValueKeeper();
const masked = createMaskedFields( bootstrap.masks || {}, keeper.attribute );

const rules = bootstrap.rules || {};
const endpoint = bootstrap.validation || {};

const checker = createFieldChecker( {
	rules,
	remote: createRemoteValidation( endpoint ),
	attribute: keeper.attribute,
} );

const errors = createFieldErrors( { attribute: keeper.attribute } );
const form = createFormValidation( {
	checker,
	errors,
	attribute: keeper.attribute,
} );

const selector = `[${ keeper.attribute }]`;

// First the value, then the mask over it. See the note above.
lifecycle.register( 'suite-values', {
	selector,
	start: ( element ) => keeper.restore( element ),
} );

lifecycle.register( 'suite-masks', {
	selector,
	start: ( element ) => masked.apply( element ),
} );

// The first pass marks every field already on the page, so a later refresh is
// only ever offered the elements it actually replaced.
$( () => {
	lifecycle.run( document.body );
} );

// Before the request: remember what the customer has typed.
$( document.body ).on( 'update_checkout', () => {
	keeper.capture( document.body );
} );

// After the new HTML is in the page: start what is new.
$( document.body ).on( 'updated_checkout', () => {
	lifecycle.run( document.body );
} );

// A field is checked when it is left, not while it is being typed: an incomplete
// document is a normal state to be in.
$( document.body ).on( 'focusout', selector, ( /** @type {any} */ event ) => {
	form.checkField( event.target );
} );

// Submitting revalidates rather than remembering. `checkout_place_order` is
// WooCommerce's own extension point: it fires on the form before the order is
// placed, and an answer of `false` abandons it.
//
// The guard is synchronous on purpose. WooCommerce decides on the spot, and a
// promise is not `false` — a guard that returned one would let every order
// through while looking like it checked something.
$( document.body ).on( 'checkout_place_order', ( /** @type {any} */ event ) =>
	form.guard( event.target )
);
