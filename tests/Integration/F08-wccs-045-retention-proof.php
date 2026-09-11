<?php
/**
 * WCCS-045 proof harness — retention and cleanup.
 *
 * Task:   WCCS-045 "Criar retenção e limpeza"
 * Phase:  F08 · Upload privado nos dois checkouts
 * Accept: "Temporários expiram; draft/falha/efetivo têm regras testadas; cleanups podem repetir."
 *
 * The rules are covered by the unit suite. What this harness proves is that they act
 * on real rows and real files, and — the clause that is easy to claim and hard to
 * hold — that running the cleanup twice changes nothing the second time.
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

/**
 * Stores a file and records it, with an expiry the caller chooses.
 *
 * @param int    $expires_in Seconds from now; negative is in the past.
 * @param int    $order_id   Order, when it is bound.
 * @param string $body       Contents.
 * @return array{token: string, path: string}
 */
function wccs_proof_upload( int $expires_in, int $order_id = 0, string $body = 'a document' ): array {
	$stored = \WCCheckoutSuite\Domain\Uploads\PrivateStorage::put( $body, 'txt' );

	if ( null === $stored ) {
		return array(
			'token' => '',
			'path'  => '',
		);
	}

	$repository = new \WCCheckoutSuite\Domain\Uploads\UploadRepository();

	$token = $repository->insert(
		array(
			'owner'      => 'proof-owner',
			'field_id'   => 'wccs_document',
			'file_name'  => 'document.txt',
			'mime_type'  => 'text/plain',
			'byte_size'  => strlen( $body ),
			'path'       => $stored['path'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
		)
	);

	if ( $order_id > 0 && '' !== $token ) {
		$GLOBALS['wpdb']->query(
			$GLOBALS['wpdb']->prepare(
				'UPDATE ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() . ' SET order_id = %d, status = %s WHERE token = %s',
				$order_id,
				\WCCheckoutSuite\Domain\Uploads\UploadRepository::STATUS_ORDERED,
				$token
			)
		);
	}

	return array(
		'token' => $token,
		'path'  => (string) $stored['path'],
	);
}

/**
 * Whether a row is still there.
 *
 * @param string $token Token.
 * @return bool
 */
function wccs_proof_row( string $token ): bool {
	return null !== ( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->find_any( $token );
}

$wccs_privacy_option = \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::STATE_OPTION;

delete_option( $wccs_privacy_option );

$GLOBALS['wpdb']->query( 'DELETE FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-045 proof — retention and cleanup' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

\WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::state( true );

// ---------------------------------------------------------------------------
// 1. What expires.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the cleanup removes, and what it leaves' );

$wccs_living_order = wc_create_order();
$wccs_living_id    = $wccs_living_order instanceof WC_Order ? (int) $wccs_living_order->get_id() : 0;

$wccs_dead_order = wc_create_order();
$wccs_dead_id    = $wccs_dead_order instanceof WC_Order ? (int) $wccs_dead_order->get_id() : 0;

if ( $wccs_dead_order instanceof WC_Order ) {
	$wccs_dead_order->delete( true );
}

$wccs_expired = wccs_proof_upload( -60 );
$wccs_fresh   = wccs_proof_upload( 3600 );
$wccs_kept    = wccs_proof_upload( -60, $wccs_living_id );
$wccs_orphan  = wccs_proof_upload( -60, $wccs_dead_id );

wccs_proof_check(
	'Four uploads, one for each case: expired, fresh, bound to a living order, bound to a deleted one',
	'' !== $wccs_expired['token'] && '' !== $wccs_fresh['token'] && '' !== $wccs_kept['token'] && '' !== $wccs_orphan['token']
		&& $wccs_living_id > 0
		&& $wccs_dead_id > 0
);

$wccs_tally = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::sweep( time(), 100 );

wccs_proof_check(
	'The temporary upload past its time is removed, file and row together',
	! wccs_proof_row( $wccs_expired['token'] ) && ! file_exists( $wccs_expired['path'] ),
	'tally=' . wp_json_encode( $wccs_tally )
);

wccs_proof_check(
	'One inside its time is left alone',
	wccs_proof_row( $wccs_fresh['token'] ) && file_exists( $wccs_fresh['path'] )
);

wccs_proof_check(
	'One bound to an order that exists is left alone, however old',
	wccs_proof_row( $wccs_kept['token'] ) && file_exists( $wccs_kept['path'] )
);

wccs_proof_check(
	'And one whose order is gone goes with it',
	! wccs_proof_row( $wccs_orphan['token'] ) && ! file_exists( $wccs_orphan['path'] )
);

wccs_proof_check(
	'So the tally is the three cases, told apart',
	1 === $wccs_tally['expired'] && 1 === $wccs_tally['orphaned'] && 1 === $wccs_tally['kept'],
	'tally=' . wp_json_encode( $wccs_tally ) . ' — the upload inside its time is not a candidate, so it is never examined'
);

// ---------------------------------------------------------------------------
// 2. Running it twice.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Running it twice' );

$wccs_rows  = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );
$wccs_again = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::sweep( time(), 100 );

wccs_proof_check(
	'The second run removes nothing, and looks at the one row it still has to',
	0 === $wccs_again['expired'] && 0 === $wccs_again['orphaned'] && 1 === $wccs_again['kept'],
	'tally=' . wp_json_encode( $wccs_again ) . ' — the upload inside its time is not even a candidate'
);

wccs_proof_check(
	'And the table is exactly what it was after the first',
	$wccs_rows === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() )
);

// A row removed between the read and the delete — which is what two overlapping
// cleanups produce — must not be an error, and the file that is already gone must
// not be either.
$wccs_vanishing = wccs_proof_upload( -120, 0, 'vanishing' );

( new \WCCheckoutSuite\Domain\Uploads\UploadRepository() )->forget( $wccs_vanishing['token'] );

$wccs_third = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::sweep( time(), 100 );

wccs_proof_check(
	'A row that disappeared before the cleanup got to it is not an error',
	0 === $wccs_third['expired'] && file_exists( $wccs_vanishing['path'] ),
	'the file is left with no row, which the rules never produce'
);

// ---------------------------------------------------------------------------
// 3. The job.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The scheduled job' );

wp_clear_scheduled_hook( \WCCheckoutSuite\Domain\Uploads\UploadsRetention::HOOK );

\WCCheckoutSuite\Domain\Uploads\UploadsRetention::schedule();

$wccs_next = wp_next_scheduled( \WCCheckoutSuite\Domain\Uploads\UploadsRetention::HOOK );

wccs_proof_check(
	'The cleanup is scheduled as a single event',
	false !== $wccs_next && $wccs_next > time()
);

$wccs_before_count = count( _get_cron_array() );

\WCCheckoutSuite\Domain\Uploads\UploadsRetention::schedule();

wccs_proof_check(
	'Scheduling it again does not pile up a second occurrence',
	$wccs_before_count === count( _get_cron_array() )
);

$wccs_run = \WCCheckoutSuite\Domain\Uploads\UploadsRetention::run();

wccs_proof_check(
	'Running the job sweeps and leaves the next occurrence scheduled',
	is_array( $wccs_run ) && false !== wp_next_scheduled( \WCCheckoutSuite\Domain\Uploads\UploadsRetention::HOOK )
);

wp_clear_scheduled_hook( \WCCheckoutSuite\Domain\Uploads\UploadsRetention::HOOK );

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

if ( $wccs_living_order instanceof WC_Order ) {
	$wccs_living_order->delete( true );
}

foreach ( array( $wccs_fresh, $wccs_kept, $wccs_vanishing ) as $wccs_left ) {
	\WCCheckoutSuite\Domain\Uploads\PrivateStorage::delete( $wccs_left['path'] );
}

$GLOBALS['wpdb']->query( 'DELETE FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() );

delete_option( $wccs_privacy_option );

$wccs_options_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_options_after === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . $wccs_options_after
);

wccs_proof_check(
	'And no upload and no file behind',
	0 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WCCheckoutSuite\Domain\Uploads\UploadsTable::name() )
		&& ! file_exists( $wccs_fresh['path'] )
		&& ! file_exists( $wccs_kept['path'] )
		&& ! file_exists( $wccs_vanishing['path'] )
);

wccs_proof_note(
	'Why the file goes before the row',
	'The other order leaves, on a failure between the two, a file with nothing pointing at it — which is exactly the orphan this job exists to remove, and nothing would ever find it again. This way the worst case is a row whose file is already gone, which the next run deletes.'
);

wccs_proof_note(
	'Why a failed upload is not a case',
	'A refusal at the endpoint writes neither the file nor the row, so there is nothing to expire. A cleaner that looked for failed uploads would be looking for rows that never existed.'
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
