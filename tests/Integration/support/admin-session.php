<?php
/**
 * A logged-in cookie for a browser observation, and its removal.
 *
 * WCCS-063 opens the store's checkout in a real browser, and this store runs
 * WooCommerce's "coming soon" mode with store pages only: an anonymous request is
 * answered with the coming-soon screen, which carries none of the checkout. The
 * store's own bypass is a capability, not an option — a user who can
 * `manage_woocommerce` sees the live store — so the observation authenticates
 * instead of changing a store setting.
 *
 * The cookie is minted the way WordPress mints it, printed once, and can be
 * destroyed with the token the same run printed. Nothing else is written: no
 * password, no option, no user record beyond the session token WordPress keeps for
 * any login, and that token is removed on request.
 *
 * It is an eval-file and not a proof: it asserts nothing and it is not part of the
 * gate. It exists so the browser half of WCCS-063 is reproducible without asking
 * anyone for a password.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/admin-session.php create [login] [any]
 *   wp eval-file tests/Integration/support/admin-session.php destroy <token>
 *
 * `any` mints a session for a user who does not manage WooCommerce — a customer
 * observation — and says in its output that the session is not a coming-soon bypass.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This helper must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$wccs_session_action = isset( $args[0] ) ? (string) $args[0] : 'create';

if ( 'destroy' === $wccs_session_action ) {
	$wccs_session_token = isset( $args[1] ) ? (string) $args[1] : '';

	if ( '' === $wccs_session_token ) {
		fwrite( STDERR, "destroy needs the token the create run printed.\n" );
		exit( 1 );
	}

	$wccs_session_users = get_users( array( 'fields' => 'ID' ) );

	foreach ( $wccs_session_users as $wccs_session_user_id ) {
		WP_Session_Tokens::get_instance( (int) $wccs_session_user_id )->destroy( $wccs_session_token );
	}

	echo "destroyed\n";

	exit( 0 );
}

$wccs_session_login = isset( $args[1] ) ? (string) $args[1] : 'admin';
$wccs_session_user  = get_user_by( 'login', $wccs_session_login );

if ( ! $wccs_session_user ) {
	fwrite( STDERR, "no such user: {$wccs_session_login}\n" );
	exit( 1 );
}

if ( ! user_can( $wccs_session_user, 'manage_woocommerce' ) ) {
	// A customer observation needs a customer's session, and the capability this guard asks
	// about is the store's coming-soon bypass rather than a requirement of signing in. The
	// caller says which it needs, so the sentence above keeps its meaning for the default
	// case and a customer session is asked for on purpose.
	$wccs_session_any = isset( $args[2] ) ? (string) $args[2] : '';

	if ( 'any' !== $wccs_session_any ) {
		fwrite( STDERR, "{$wccs_session_login} cannot manage WooCommerce, so it does not bypass the coming-soon screen. Pass `any` as the third argument to mint the session anyway.\n" );
		exit( 1 );
	}

	echo "note=this session is not a coming-soon bypass\n";
}

$wccs_session_expiration = time() + 3600;
$wccs_session_manager    = WP_Session_Tokens::get_instance( $wccs_session_user->ID );
$wccs_session_token      = $wccs_session_manager->create( $wccs_session_expiration );
$wccs_session_cookie     = wp_generate_auth_cookie( $wccs_session_user->ID, $wccs_session_expiration, 'logged_in', $wccs_session_token );

// Two cookies, because WordPress asks two different questions. The front end asks
// whether the visitor is logged in, with the `logged_in` cookie; wp-admin asks
// whether the request may enter the administration, with the `auth` cookie, and a
// browser holding only the first is sent back to the login screen with `reauth=1`.
// A session created here is therefore printed as both.
$wccs_auth_cookie = wp_generate_auth_cookie( $wccs_session_user->ID, $wccs_session_expiration, 'auth', $wccs_session_token );

echo 'user_id=' . $wccs_session_user->ID . "\n";
echo 'expires=' . gmdate( 'c', $wccs_session_expiration ) . "\n";
echo 'token=' . $wccs_session_token . "\n";
echo 'cookie_name=' . LOGGED_IN_COOKIE . "\n";
echo 'cookie_value=' . $wccs_session_cookie . "\n";
echo 'auth_cookie_name=' . AUTH_COOKIE . "\n";
echo 'auth_cookie_value=' . $wccs_auth_cookie . "\n";
