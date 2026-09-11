/**
 * Classic checkout entry point.
 *
 * Wires the lifecycle and the value keeper to the two events WooCommerce fires
 * around its AJAX refresh, and to the first page load.
 *
 * jQuery is not a preference here: the classic checkout fires `update_checkout`
 * and `updated_checkout` as jQuery events on `document.body`, and a jQuery event
 * does not reach a native DOM listener. Listening for them any other way would
 * mean never hearing them.
 *
 * Nothing is registered as a component here yet. The lifecycle is this phase's
 * deliverable and F05 registers the first component against it — a mask applied
 * once per input, which is the case the per-element rule exists for. Until then
 * the bundle's whole job is the value keeper, which is a component like any
 * other.
 */

// jQuery is not a package this project depends on: it is a WordPress runtime
// global, and the build externalises the import to `window.jQuery` and records
// `jquery` in the asset manifest so WordPress loads it first. Installing a copy
// here would suggest the version in node_modules is the version that runs, and
// the version that runs is WordPress's.
// eslint-disable-next-line import/no-unresolved
import $ from 'jquery';

import { createLifecycle } from './lifecycle';
import { createValueKeeper } from './values';

const lifecycle = createLifecycle();
const keeper = createValueKeeper();

lifecycle.register( 'suite-values', {
	selector: `[${ keeper.attribute }]`,
	start: ( element ) => keeper.restore( element ),
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
