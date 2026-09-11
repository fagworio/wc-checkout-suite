<?php
/**
 * Plugin Name:       WC CheckoutSuite — Example field type
 * Description:       Minimal example that adds a field type to WC CheckoutSuite without editing its core.
 * Version:           0.1.0
 * Requires PHP:      8.2
 * Text Domain:       wccs-example
 *
 * @package WCCheckoutSuiteExample
 *
 * This is a documentation artefact, not a shipped product. It exists to prove
 * that the extension contract published in ROADMAP.md section 6 works from the
 * outside: the type is registered through the public hook, declares its own
 * value and settings schemas, normalizes and validates its own values, and
 * appears in the registry with its own origin.
 *
 * It is the base that WCCS-058 (F11) turns into the documented example once the
 * Classic and Blocks renderers exist and the field can be seen in a checkout.
 *
 * The `Requires Plugins` header is intentionally absent: this example must load
 * harmlessly whether or not WC CheckoutSuite is active, and simply contribute
 * nothing when the hook never fires.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/MembershipCodeType.php';

add_action(
	'wccs_register_field_types',
	array( 'WCCheckoutSuiteExample\\MembershipCodeType', 'register_field_types' )
);
