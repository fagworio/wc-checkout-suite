/**
 * WC CheckoutSuite — administration application entry point.
 *
 * The bundle is enqueued only on the suite screen, so this file can assume the
 * mount node exists. When it does not, the application renders nothing rather
 * than throwing: a screen with a missing mount point is a bug, but it must not
 * take down wp-admin.
 */

import { render } from '@wordpress/element';

import AppShell from './app/AppShell';
import { createClient } from './app/api';
import './app/app.css';

const config = window.wccsAdmin ?? {};
const mountId = config.mountId ?? 'wccs-admin-root';
const mount = document.getElementById( mountId );

// The client is built from the bootstrap payload, which the server fills from
// SchemaController::routes() and CatalogController::routes(). Routes are never
// written by hand here, so a renamed path cannot leave the browser pointing at
// something that no longer exists.
const rest = config.rest ?? {};
const client = createClient( {
	root: rest.root ?? '',
	namespace: rest.namespace ?? '',
	nonce: rest.nonce ?? '',
	routes: rest.routes ?? {},
} );

if ( mount ) {
	render(
		<AppShell
			sections={ config.sections ?? [] }
			version={ config.version ?? '' }
			client={ client }
		/>,
		mount
	);
}
