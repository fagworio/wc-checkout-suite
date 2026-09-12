<?php
/**
 * Every administration route this plugin publishes, in one place.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin;

use WCCheckoutSuite\Http\Admin\CatalogController;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Http\Admin\SettingsController;
use WCCheckoutSuite\Http\Integration\OrderFieldsController;

/**
 * The route map the administration client is built from.
 *
 * The controllers each declare their own paths; this is the composition, and it exists
 * because the composition had already been written twice — once in the assets that
 * publish the payload and once in the harness that asserts the payload is exactly what
 * the controllers declared. Adding the settings controller broke that assertion the
 * same afternoon, which is the argument for the class: a new controller is now named
 * here once, and both the code and the proof read this instead of remembering.
 *
 * It is a map of *names to paths* and not of controllers to paths: the client asks for
 * `draft` and `settings`, and the names are the interface. A path can move without the
 * client changing, which is the same reason the payload carries the map at all.
 */
final class Routes {

	/**
	 * Every published route, keyed by the name the admin client uses.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return SchemaController::routes()
			+ CatalogController::routes()
			+ SettingsController::routes()
			+ OrderFieldsController::routes();
	}
}
