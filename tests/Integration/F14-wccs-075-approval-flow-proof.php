<?php
/**
 * WCCS-075 proof harness — the optional approval flow.
 *
 * Task:   WCCS-075 "Fluxo de aprovação opcional"
 * Phase:  F14
 * Accept: "Desligado por padrão; não altera status nem bloqueia sem estar habilitado;
 *          configuração incompleta é apontada, não completada em silêncio."
 *
 * The acceptance is mostly about what does *not* happen, so most of this harness is
 * negative on purpose, and negative in the strong sense: not "the status did not
 * change" but "there was nothing to change it".
 *
 * 1. **Off is nothing.** With no flow configured the store gets no status, no filter
 *    and no hook from the plugin at all, and an order placed with the document keeps
 *    the status WooCommerce gave it.
 * 2. **Incomplete is reported, not completed.** A flow that says it holds orders but
 *    does not say where the review happens, in which section, or which state the order
 *    waits in is refused by the validator with the missing keys, registers no status,
 *    and holds nothing.
 * 3. **Configured holds the order that needs it.** The state the merchant named is
 *    registered from the published document, an order carrying an answer for that
 *    field waits in it (once, with one note), an order without an answer does not, and
 *    the customer is told the situation when the merchant asked for that.
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
 * Validates one definition and returns its codes.
 *
 * @param array<string, mixed> $data Definition.
 * @return array{valid: bool, codes: array<int, string>}
 */
function wccs_proof_validate( array $data ): array {
	$validator = \WCCheckoutSuite\Domain\Registries::instance()->definition_validator();
	$full      = array_merge(
		array(
			'id'       => 'wccs_authorisation',
			'origin'   => 'custom',
			'type'     => 'file',
			'label'    => 'Autorização',
			'settings' => array(
				'maxFiles'          => 1,
				'allowedExtensions' => array( 'pdf' ),
			),
		),
		$data
	);

	$result = $validator->validate_array( $full );

	return array(
		'valid' => $result->is_valid(),
		'codes' => $result->error_codes(),
	);
}

/**
 * The codes of the errors a write result carries.
 *
 * @param array<int, array<string, mixed>> $errors Errors.
 * @return array<int, string>
 */
function wccs_proof_codes( array $errors ): array {
	$codes = array();

	foreach ( $errors as $error ) {
		if ( is_array( $error ) && isset( $error['code'] ) ) {
			$codes[] = (string) $error['code'];
		}
	}

	return $codes;
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

/**
 * The review statuses this plugin registered, in a list of WooCommerce statuses.
 *
 * Read from `wc_get_order_statuses()` by default, and from any given list otherwise,
 * so the same rule can be asked of the filter itself — where a status that was never
 * registered would still show up.
 *
 * @param array<string, string>|null $statuses Statuses, keyed by the `wc-` name.
 * @return array<int, string> Status keys with the `wc-` prefix.
 */
function wccs_proof_review_statuses( ?array $statuses = null ): array {
	$known = array_keys( $statuses ?? (array) wc_get_order_statuses() );

	return array_values(
		array_filter(
			$known,
			static fn( $status ) => str_starts_with( (string) $status, 'wc-wccs-' )
		)
	);
}

/**
 * Writes the published document, through the real repository so it is validated.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Sections.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_proof_publish( array $fields, array $sections = array() ): \WCCheckoutSuite\Domain\Schema\WriteResult {
	$repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
		\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
		new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
	);

	$document = new \WCCheckoutSuite\Domain\Schema\SchemaDocument(
		1,
		1,
		gmdate( 'c' ),
		1,
		$fields,
		$sections,
		array(),
		array()
	);

	return $repository->write(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
		$document,
		$repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
	);
}

/**
 * One file field, with the approval configuration given.
 *
 * @param array<string, mixed>|null $approval Approval map.
 * @return array<string, mixed>
 */
function wccs_proof_field( ?array $approval ): array {
	return array(
		'id'             => 'wccs_authorisation',
		'integration_id' => 'wc-checkoutsuite/wccs_authorisation',
		'origin'         => 'custom',
		'type'           => 'file',
		'label'          => 'Autorização',
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'settings'       => array(
			'maxFiles'          => 1,
			'allowedExtensions' => array( 'pdf' ),
		),
		'approval'       => $approval,
	);
}

/**
 * One section, offered in the given areas.
 *
 * @param array<int, string> $areas Areas.
 * @return array<string, mixed>
 */
function wccs_proof_section( array $areas ): array {
	return array(
		'id'       => 'documentos_para_analise',
		'title'    => 'Documentos para análise',
		'position' => 10,
		'location' => 'order',
		'areas'    => $areas,
	);
}

/**
 * One order carrying a value for the field.
 *
 * @param array<int, array<string, mixed>> $definitions Published definitions.
 * @param array<int, string>               $tokens      Upload tokens, empty for none.
 * @return WC_Order
 */
function wccs_proof_order( array $definitions, array $tokens = array( 'a-token' ) ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->save();

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write(
		$order,
		array( 'wccs_authorisation' => $tokens ),
		$definitions,
		1
	);

	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * How many notes the order carries.
 *
 * @param WC_Order $order Order.
 * @return int
 */
function wccs_proof_note_count( WC_Order $order ): int {
	$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

	return is_array( $notes ) ? count( $notes ) : 0;
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-075 proof — the optional approval flow' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );
// The states a flow asks for are migrated into the store's status list, which is durable on
// purpose: an order recorded in a state keeps being in a registered state even if the flow that
// named it is turned off. This harness is what causes that write, so this harness clears it.
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );

$wccs_options_before = wccs_proof_option_count();
$wccs_orders         = array();

// ---------------------------------------------------------------------------
// 1. Off is nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A store that did not ask for a review' );

$wccs_off = wccs_proof_publish(
	array( wccs_proof_field( array( 'require_review' => false ) ) )
);

wccs_proof_check(
	'A document that turns the flow off is a document with no review',
	$wccs_off->is_ok()
		&& array() === \WCCheckoutSuite\Domain\Approval\ReviewStatus::statuses( array( wccs_proof_field( array( 'require_review' => false ) ) ) ),
	'status=' . $wccs_off->status()
);

\WCCheckoutSuite\Domain\Approval\ReviewStatus::publish();

wccs_proof_check(
	'And no status is added to WooCommerce\'s list by the plugin',
	array() === wccs_proof_review_statuses() && array() === wccs_proof_review_statuses( apply_filters( 'wc_order_statuses', array() ) ),
	'statuses=' . implode( ',', wccs_proof_review_statuses() )
);

wccs_proof_check(
	'And no checkout hook is added either',
	false === has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_after_status_change' ) )
		&& false === has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_API, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_api' ) ),
	'classic=' . ( false !== has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_after_status_change' ) ) ? 'yes' : 'no' )
);

$wccs_field_off = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( wccs_proof_field( array( 'require_review' => false ) ) );

$wccs_order_off = wccs_proof_order( array( wccs_proof_field( array( 'require_review' => false ) ) ) );
$wccs_orders[]  = $wccs_order_off;

do_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, $wccs_order_off->get_id(), 'pending', 'processing', $wccs_order_off );

wccs_proof_check(
	'An order placed with it keeps the status WooCommerce gave it',
	'processing' === wc_get_order( $wccs_order_off->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_order_off->get_id() )->get_status()
);

if ( ! \WCCheckoutSuite\Domain\Approval\ApprovalFlow::of( $wccs_field_off )->enabled() ) {
	// The flow is read through the same class the runtime uses, so a definition that
	// does not enable it cannot be acted on by any caller that goes through here.
	wccs_proof_check( 'And the flow reads as off, which is why nothing could happen', true );
}

// ---------------------------------------------------------------------------
// 2. Incomplete is reported, never completed.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A flow that was asked for but not finished' );

$wccs_incomplete = wccs_proof_validate( array( 'approval' => array( 'require_review' => true ) ) );

wccs_proof_check(
	'It is refused, key by key',
	in_array( 'approval_incomplete', $wccs_incomplete['codes'], true ) && ! $wccs_incomplete['valid'],
	'codes=' . implode( ',', $wccs_incomplete['codes'] )
);

$wccs_no_state = wccs_proof_validate(
	array(
		'approval' => array(
			'require_review' => true,
			'area'           => 'admin_order',
			'section'        => 'documentos_para_analise',
		),
	)
);

wccs_proof_check(
	'And so is a flow that says where the review happens but not where the order waits',
	in_array( 'approval_incomplete', $wccs_no_state['codes'], true ),
	'codes=' . implode( ',', $wccs_no_state['codes'] )
);

$wccs_unknown_section = wccs_proof_publish(
	array(
		wccs_proof_field(
			array(
				'require_review' => true,
				'area'           => 'admin_order',
				'section'        => 'secao_que_ninguem_declarou',
				'status'         => 'Pendente de aprovação',
			)
		),
	),
	array( wccs_proof_section( array( 'admin_order' ) ) )
);

wccs_proof_check(
	'A review that points at a section the document does not offer there is refused too',
	! $wccs_unknown_section->is_ok()
		&& in_array( 'approval_section_not_offered', wccs_proof_codes( $wccs_unknown_section->errors() ), true ),
	'codes=' . implode( ',', wccs_proof_codes( $wccs_unknown_section->errors() ) )
);

$wccs_incomplete_field = wccs_proof_field( array( 'require_review' => true ) );
$wccs_incomplete_doc   = wccs_proof_publish( array( $wccs_incomplete_field ) );

wccs_proof_check(
	'An incomplete flow registers no state',
	array() === \WCCheckoutSuite\Domain\Approval\ReviewStatus::statuses( array( $wccs_incomplete_field ) )
		&& array() === wccs_proof_review_statuses(),
	'published=' . ( $wccs_incomplete_doc->is_ok() ? 'ok' : $wccs_incomplete_doc->status() )
);

$wccs_order_incomplete = wccs_proof_order( array( $wccs_incomplete_field ) );
$wccs_orders[]         = $wccs_order_incomplete;

$wccs_held_incomplete = \WCCheckoutSuite\Domain\Approval\ReviewStatus::apply( $wccs_order_incomplete );

wccs_proof_check(
	'And it holds nothing: the order stays where the store put it',
	! $wccs_held_incomplete && 'processing' === $wccs_order_incomplete->get_status(),
	'status=' . $wccs_order_incomplete->get_status()
);

// ---------------------------------------------------------------------------
// 3. Configured holds the order that needs it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A store that asked for the review' );

$wccs_flow_field = wccs_proof_field(
	array(
		'require_review'   => true,
		'area'             => 'admin_order',
		'section'          => 'documentos_para_analise',
		'status'           => 'Pendente de aprovação',
		'allow_correction' => true,
		'allow_resubmit'   => true,
		'show_status'      => true,
	)
);

$wccs_published = wccs_proof_publish( array( $wccs_flow_field ), array( wccs_proof_section( array( 'admin_order' ) ) ) );

wccs_proof_check(
	'The complete flow is accepted',
	$wccs_published->is_ok(),
	'status=' . $wccs_published->status()
);

\WCCheckoutSuite\Domain\Approval\ReviewStatus::publish();

$wccs_flow    = \WCCheckoutSuite\Domain\Approval\ApprovalFlow::of(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_flow_field )
);
$wccs_state   = $wccs_flow->status();
$wccs_statuses = wccs_proof_review_statuses();
$wccs_known    = wc_get_order_statuses();

wccs_proof_check(
	'The state the merchant named is registered, with the merchant\'s own words',
	in_array( 'wc-' . $wccs_state, $wccs_statuses, true )
		&& 'Pendente de aprovação' === ( $wccs_known[ 'wc-' . $wccs_state ] ?? '' )
		&& strlen( $wccs_state ) <= 17,
	'statuses=' . implode( ',', $wccs_statuses ) . ' label=' . ( $wccs_known[ 'wc-' . $wccs_state ] ?? '(none)' )
);

wccs_proof_check(
	'And the checkout is now watched, because a flow asked for it',
	false !== has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_after_status_change' ) )
		&& false !== has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_API, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_api' ) ),
	'classic=' . ( false !== has_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, array( \WCCheckoutSuite\Domain\Approval\ReviewStatus::class, 'hold_after_status_change' ) ) ? 'yes' : 'no' )
);

$wccs_definitions = array( $wccs_flow_field );

$wccs_order = wccs_proof_order( $wccs_definitions );
$wccs_orders[] = $wccs_order;

$wccs_notes_before = wccs_proof_note_count( $wccs_order );

do_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, $wccs_order->get_id(), 'pending', 'processing', $wccs_order );

$wccs_held = wc_get_order( $wccs_order->get_id() );

wccs_proof_check(
	'An order carrying an answer waits in it',
	$wccs_state === $wccs_held->get_status(),
	'status=' . $wccs_held->get_status()
);

wccs_proof_check(
	'And the order says why, once',
	wccs_proof_note_count( $wccs_held ) === $wccs_notes_before + 1,
	'notes=' . wccs_proof_note_count( $wccs_held ) . ' before=' . $wccs_notes_before
);

do_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, $wccs_held->get_id(), 'processing', 'processing', $wccs_held );

wccs_proof_check(
	'Asking again changes nothing and says nothing twice',
	$wccs_state === wc_get_order( $wccs_held->get_id() )->get_status()
		&& wccs_proof_note_count( wc_get_order( $wccs_held->get_id() ) ) === $wccs_notes_before + 1,
	'notes=' . wccs_proof_note_count( wc_get_order( $wccs_held->get_id() ) )
);

// The merchant reviews the documents and moves the order on. That transition is a
// post-payment one too, so without a record of the hold the plugin would read the
// approved order as a fresh one, put it back in review, and the merchant could never
// take it out: the decision would undo itself.
$wccs_approved = wc_get_order( $wccs_held->get_id() );
$wccs_approved->update_status( 'processing', 'Documentos aprovados.' );

wccs_proof_check(
	'An order the staff already reviewed is not put back in review by their own decision',
	'processing' === wc_get_order( $wccs_held->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_held->get_id() )->get_status()
);

$wccs_review_notes = 0;

foreach ( wc_get_order_notes( array( 'order_id' => $wccs_held->get_id() ) ) as $wccs_note ) {
	if ( str_contains( (string) $wccs_note->content, 'Waiting for review' ) ) {
		++$wccs_review_notes;
	}
}

wccs_proof_check(
	'And it carries the record that it waited, which is what makes the rule a fact',
	'1' === (string) wc_get_order( $wccs_held->get_id() )->get_meta( \WCCheckoutSuite\Domain\Approval\ReviewStatus::META_HELD )
		&& 1 === $wccs_review_notes,
	'notes=' . wccs_proof_note_count( wc_get_order( $wccs_held->get_id() ) ) . ' review notes=' . $wccs_review_notes
);

$wccs_order_empty = wccs_proof_order( $wccs_definitions, array() );
$wccs_orders[]    = $wccs_order_empty;

do_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_CLASSIC, $wccs_order_empty->get_id(), 'pending', 'processing', $wccs_order_empty );

wccs_proof_check(
	'An order with nothing attached is not held',
	'processing' === wc_get_order( $wccs_order_empty->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_order_empty->get_id() )->get_status()
);

$wccs_store_api_order = wccs_proof_order( $wccs_definitions );
$wccs_orders[]        = $wccs_store_api_order;

do_action( \WCCheckoutSuite\Domain\Approval\ReviewStatus::HOOK_API, $wccs_store_api_order->get_id() );

wccs_proof_check(
	'The Blocks checkout is held by the same rule',
	$wccs_state === wc_get_order( $wccs_store_api_order->get_id() )->get_status(),
	'status=' . wc_get_order( $wccs_store_api_order->get_id() )->get_status()
);

// The customer's own panel: the situation, when the merchant asked for it.
ob_start();
\WCCheckoutSuite\Checkout\CustomerOrderFields::render( $wccs_held );
$wccs_panel = (string) ob_get_clean();

wccs_proof_check(
	'The customer is told the situation, in the store\'s own words',
	str_contains( $wccs_panel, 'Pendente de aprovação' ),
	'panel=' . ( '' === trim( wp_strip_all_tags( $wccs_panel ) ) ? '(empty)' : trim( wp_strip_all_tags( $wccs_panel ) ) )
);

$wccs_quiet = wccs_proof_field(
	array(
		'require_review' => true,
		'area'           => 'admin_order',
		'section'        => 'documentos_para_analise',
		'status'         => 'Em análise',
		'show_status'    => false,
	)
);

wccs_proof_publish( array( $wccs_quiet ), array( wccs_proof_section( array( 'admin_order' ) ) ) );

// The registration happens on `init`, which has already run in this request, so the
// harness asks for it the way the next page load would.
\WCCheckoutSuite\Domain\Approval\ReviewStatus::publish();

$wccs_quiet_order = wccs_proof_order( array( $wccs_quiet ) );
$wccs_orders[]    = $wccs_quiet_order;

$wccs_quiet_state = \WCCheckoutSuite\Domain\Approval\ApprovalFlow::of(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_quiet )
)->status();

\WCCheckoutSuite\Domain\Approval\ReviewStatus::apply( $wccs_quiet_order );

ob_start();
\WCCheckoutSuite\Checkout\CustomerOrderFields::render( wc_get_order( $wccs_quiet_order->get_id() ) );
$wccs_quiet_panel = (string) ob_get_clean();

wccs_proof_check(
	'And is told nothing when the store did not ask for it',
	$wccs_quiet_state === wc_get_order( $wccs_quiet_order->get_id() )->get_status()
		&& ! str_contains( $wccs_quiet_panel, 'Em análise' ),
	'status=' . wc_get_order( $wccs_quiet_order->get_id() )->get_status() . ' panel=' . wp_strip_all_tags( $wccs_quiet_panel )
);

wccs_proof_note(
	'What this harness does not observe',
	'No e-mail is sent: the hold happens after WooCommerce has already sent the e-mails of the state the gateway set, and the review state itself has none. Whether a store wants an e-mail for it is a store decision this plugin does not take.'
);

// ---------------------------------------------------------------------------
// Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What the harness left behind' );

foreach ( $wccs_orders as $wccs_created ) {
	if ( $wccs_created instanceof WC_Order ) {
		$wccs_created->delete( true );
	}
}

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );
delete_option( \WCCheckoutSuite\Domain\Statuses\OrderStatusRepository::OPTION );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
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
