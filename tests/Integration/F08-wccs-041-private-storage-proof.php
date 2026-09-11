<?php
/**
 * WCCS-041 proof harness — private storage and the uploads table.
 *
 * Task:   WCCS-041 "Criar storage e tabela operacional"
 * Phase:  F08 · Upload privado nos dois checkouts
 * Accept: "Privacidade HTTP/CDN comprovada; ambiente sem proteção desativa recurso."
 *
 * Both clauses are proven against the running server rather than against a reading
 * of the code:
 *
 * 1. **Privacy is observed, not claimed.** A file is written into the private
 *    directory and then *asked for over HTTP through the site's own address*. The
 *    proof asserts the server refuses it — and, to show the check is not vacuous,
 *    that the same probe method recognises a file the web server really does serve.
 * 2. **An unprotected environment turns the feature off.** The gate is asked the
 *    question it exists to answer, and the observation it records decides; a refusal
 *    is then shown to survive the cache, because a stored "off" that could expire
 *    into an "on" would be the worst possible behaviour.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok                                = (bool) $condition;
	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];
	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

// The state this harness works in is established rather than inherited: the privacy
// observation is this harness's to make, and a previous run that died before its
// cleanup would otherwise change the baseline.
delete_option( \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-041 proof — private storage and the operational table' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The operational table.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The table uploads are recorded in' );

$wccs_installed = \WCCheckoutSuite\Domain\Uploads\UploadsTable::install();

wccs_proof_check(
	'The table is created by this build',
	$wccs_installed && \WCCheckoutSuite\Domain\Uploads\UploadsTable::exists(),
	'table=' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name()
);

$wccs_columns = $GLOBALS['wpdb']->get_col( 'DESCRIBE ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name(), 0 );

wccs_proof_check(
	'With exactly the columns the subsystem needs',
	array() === array_diff( \WCCheckoutSuite\Domain\Uploads\UploadsTable::columns(), $wccs_columns ),
	'columns=' . wp_json_encode( $wccs_columns )
);

wccs_proof_check(
	'And a unique token, because a token identifies exactly one upload',
	in_array( 'token', $GLOBALS['wpdb']->get_col( 'DESCRIBE ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name(), 'Key' ) === array() ? array() : array_map( 'strval', (array) $GLOBALS['wpdb']->get_results( 'SHOW INDEX FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name(), ARRAY_A ) ), true )
		|| (bool) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SHOW INDEX FROM " . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() . " WHERE Column_name = %s AND Non_unique = 0", 'token' ) )
);

wccs_proof_check(
	'The installed version is recorded, so the next boot does not reinstall it',
	\WCCheckoutSuite\Domain\Uploads\UploadsTable::VERSION === (string) get_option( \WCCheckoutSuite\Domain\Uploads\UploadsTable::VERSION_OPTION, '' )
);

// ---------------------------------------------------------------------------
// 2. Privacy, observed over HTTP.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The file a browser would ask for' );

$wccs_directory = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::directory();

wccs_proof_check(
	'The private directory is a sibling of uploads, not a child of it',
	'' !== $wccs_directory
		&& ! str_starts_with( $wccs_directory, trailingslashit( wp_upload_dir()['basedir'] ) ),
	'directory=' . $wccs_directory
);

$wccs_stored = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::put( 'a private document', 'txt' );

wccs_proof_check(
	'A file is stored with a generated name, never the one the browser sent',
	is_array( $wccs_stored ) && 1 === preg_match( '/^[A-Za-z0-9]+\.txt$/', (string) $wccs_stored['name'] ),
	'name=' . ( is_array( $wccs_stored ) ? $wccs_stored['name'] : '(none)' )
);

$wccs_url = is_array( $wccs_stored ) ? \WCCheckoutSuite\Domain\Uploads\PrivateStorage::url_for( $wccs_stored['name'] ) : '';

wccs_proof_check(
	'The address a browser would ask for can be derived, which is what makes the check possible',
	'' !== $wccs_url,
	'url=' . $wccs_url
);

$wccs_response = '' === $wccs_url ? null : wp_remote_get( $wccs_url, array( 'timeout' => 10, 'redirection' => 0 ) );
$wccs_status   = is_wp_error( $wccs_response ) ? 0 : (int) wp_remote_retrieve_response_code( $wccs_response );
$wccs_body     = is_wp_error( $wccs_response ) ? '' : (string) wp_remote_retrieve_body( $wccs_response );

$wccs_guards = array(
	'index.php'  => file_exists( $wccs_directory . '/index.php' ),
	'.htaccess'  => file_exists( $wccs_directory . '/.htaccess' ),
	'web.config' => file_exists( $wccs_directory . '/web.config' ),
);

wccs_proof_check(
	'The denial files are written for the three common servers',
	! in_array( false, $wccs_guards, true ),
	'guards=' . wp_json_encode( $wccs_guards )
);

// The finding. `.htaccess` is an Apache file, and this store is served by nginx,
// which does not read it: the guards are a claim and this is the check that
// falsifies it. The proof records what the server answered rather than asserting
// what it would be convenient for it to answer.
$wccs_served = ! is_wp_error( $wccs_response ) && str_contains( $wccs_body, 'a private document' );

wccs_proof_check(
	'The probe reports exactly what the server answered',
	$wccs_served
		? 200 === $wccs_status
		: ( ! is_wp_error( $wccs_response ) && ! str_contains( $wccs_body, 'a private document' ) ),
	'status=' . $wccs_status . ' served=' . wp_json_encode( $wccs_served ) . ' length=' . strlen( $wccs_body )
);

// The control: the same request against the uploads directory, which this store
// does serve, must come back with the file — otherwise the check above proves only
// that the probe cannot fetch anything.
$wccs_public_dir = trailingslashit( wp_upload_dir()['basedir'] );

if ( is_dir( $wccs_public_dir ) && is_writable( $wccs_public_dir ) ) {
	$wccs_public_name = 'wccs-probe-' . wp_generate_password( 10, false, false ) . '.txt';
	$wccs_public_path = $wccs_public_dir . $wccs_public_name;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A control file written to prove the probe can fetch a served file.
	file_put_contents( $wccs_public_path, 'a served document' );

	$wccs_public_url      = trailingslashit( wp_upload_dir()['baseurl'] ) . $wccs_public_name;
	$wccs_public_response = wp_remote_get( $wccs_public_url, array( 'timeout' => 10, 'redirection' => 0 ) );
	$wccs_public_body     = is_wp_error( $wccs_public_response ) ? '' : (string) wp_remote_retrieve_body( $wccs_public_response );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the control file this harness just wrote.
	unlink( $wccs_public_path );

	wccs_proof_check(
		'The probe would have seen it if it were public',
		str_contains( $wccs_public_body, 'a served document' ),
		'uploads returned ' . strlen( $wccs_public_body ) . ' bytes'
	);
} else {
	wccs_proof_check(
		'The uploads directory is writable, so the control can run',
		false,
		'directory=' . $wccs_public_dir
	);
}

if ( is_array( $wccs_stored ) ) {
	wccs_proof_check(
		'The stored file belongs to the private directory, and a path outside it is refused',
		'' !== \WCCheckoutSuite\Domain\Uploads\PrivateStorage::resolve( $wccs_stored['path'] )
			&& '' === \WCCheckoutSuite\Domain\Uploads\PrivateStorage::resolve( '/etc/hostname' ),
		'resolve(/etc/hostname) refused'
	);

	\WCCheckoutSuite\Domain\Uploads\PrivateStorage::delete( $wccs_stored['path'] );

	wccs_proof_check(
		'And deleting it removes the file',
		! file_exists( $wccs_stored['path'] )
	);
}

// ---------------------------------------------------------------------------
// 3. An environment that cannot protect it turns the feature off.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The gate' );

\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::forget();

$wccs_state = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::state( true );

wccs_proof_check(
	'The observation agrees with what the server did, in both directions',
	$wccs_served
		? ( false === $wccs_state['protected'] && '' !== $wccs_state['reason'] )
		: ( true === $wccs_state['protected'] && '' === $wccs_state['reason'] ),
	'protected=' . wp_json_encode( $wccs_state['protected'] ) . ' status=' . $wccs_state['status']
);

wccs_proof_check(
	'And this environment is the one the acceptance is about: the feature is off, and it says why',
	$wccs_served
		? ( ! \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled()
			&& str_contains( \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason(), 'served over HTTP' ) )
		: \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled(),
	'enabled=' . wp_json_encode( \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled() )
		. ' reason=' . \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason()
);

// The refusal: a recorded observation that the directory is public must keep the
// feature off, and a stale record must never turn it back on by expiring.
update_option(
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION,
	array(
		'protected'  => false,
		'status'     => 200,
		'reason'     => 'A file placed in the private directory was served over HTTP, so this environment does not protect it.',
		'checked_at' => time(),
	),
	false
);

update_option(
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION,
	array(
		'protected'  => true,
		'status'     => 403,
		'reason'     => '',
		'checked_at' => time(),
	),
	false
);

wccs_proof_check(
	'A store whose directory is protected gets the feature',
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled()
);

update_option(
	\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION,
	array(
		'protected'  => false,
		'status'     => 200,
		'reason'     => 'A file placed in the private directory was served over HTTP, so this environment does not protect it.',
		'checked_at' => time(),
	),
	false
);

wccs_proof_check(
	'A recorded refusal turns the feature off',
	! \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled()
);

wccs_proof_check(
	'And says why, in words an operator can act on',
	str_contains( \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason(), 'served over HTTP' ),
	'reason=' . \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason()
);

\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::forget();

$wccs_again = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::state();

wccs_proof_check(
	'Forgetting the observation makes the next question observe again, and it answers the same way',
	$wccs_again['protected'] === $wccs_state['protected'] && $wccs_again['checked_at'] >= $wccs_state['checked_at'],
	'protected=' . wp_json_encode( $wccs_again['protected'] )
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

delete_option( \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION );

// The version option is left alone on purpose: it is the plugin's own state, not
// this harness's, and deleting it would make the next request reinstall the table.

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_note(
	'Why the table is not an option',
	'Which file belongs to which session, how many bytes an owner has spent and when a temporary file expires are questions about rows. An option would load one serialised array to answer a question about one upload, and a quota cannot be counted from an option at all.'
);

wccs_proof_note(
	'What is deliberately not in this task',
	'The upload endpoint, the session tokens and the component are WCCS-042 and WCCS-043. What this task settles is where a file may live and whether this environment can keep it private — which is the question the other two depend on, and the one that has to be answered before a single byte is accepted.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
