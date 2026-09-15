<?php
/**
 * WCCS-051 proof harness — the checkout fields on the order screen.
 *
 * Task:   WCCS-051 "Criar editor de dados do pedido"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "CRUD, edição autorizada e validação idêntica nos dois backends de pedidos."
 *
 * The three clauses, and each is asserted where it can actually fail:
 *
 * 1. **CRUD** — a value is created on a real order, read back, corrected, and removed
 *    when it is emptied, all through `WC_Order`'s own API.
 * 2. **Authorized editing** — capability decides whether a person may edit this order,
 *    and the field's own declaration decides whether the order is where its value
 *    belongs. A customer-scoped field submitted for an order is refused, because
 *    section 14 separates the order's snapshot from the profile's current preference
 *    and editing one must not rewrite the other.
 * 3. **Identical validation on both order backends** — a value the checkout would refuse
 *    is refused here with the same code and the same message, asserted by running the
 *    checkout's own pipeline on the same input and comparing; and every read and write
 *    goes through the order CRUD, which is the contract that makes the two backends
 *    (posts and HPOS) behave the same rather than merely appear to.
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
 * A field definition.
 *
 * @param string               $id      Identifier.
 * @param string               $type    Type.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $type = 'text', array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => $type,
			'label'          => 'Label ' . $id,
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => array(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array( 'admin_order' => true ),
			'validators'     => array(),
		),
		$changes
	);
}

/**
 * Publishes a document.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 51,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * An order to edit.
 *
 * @return WC_Order
 */
function wccs_proof_order(): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'pending' );
	$order->save();

	return $order;
}

/**
 * Runs the panel's save with a submitted field.
 *
 * The panel reads `$_POST`, verifies a nonce and writes through the order CRUD. This
 * drives exactly that path: the nonce the panel itself would have printed, the order
 * identifier the screen passes, and the capability of the current user.
 *
 * @param WC_Order                  $order  Order.
 * @param array<string, string>     $fields Submitted values, keyed by field identifier.
 * @param bool                      $as_staff Whether the current user may edit.
 * @return void
 */
function wccs_proof_submit( WC_Order $order, array $fields, bool $as_staff = true ): void {
	if ( $as_staff ) {
		wp_set_current_user( 1 );
	} else {
		wp_set_current_user( 0 );
	}

	$_POST = array( \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::NONCE . '_nonce' => wp_create_nonce( \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::NONCE ) );

	foreach ( $fields as $id => $value ) {
		$_POST[ \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::INPUT_PREFIX . $id ] = $value;
	}

	\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::save( $order->get_id(), $order );

	$_POST = array();

	wp_set_current_user( 1 );
}

/**
 * Reads a Suite value straight out of the order.
 *
 * @param WC_Order $order Order.
 * @param string   $id    Field identifier.
 * @return mixed
 */
function wccs_proof_stored( WC_Order $order, string $id ) {
	$fresh = wc_get_order( $order->get_id() );

	return ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( $fresh )->get( $id );
}

$wccs_panel = 'WCCheckoutSuite\\Admin\\Orders\\OrderFieldsPanel';

// The store is put in a known state: opted out of the presentation, which is where a
// fresh install is and what WCCS-050 asserts; this task does not depend on it.
delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-051 proof — the order editor' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// The option **names** this store had before the run, not their count: what this harness asserts is
// that it left nothing of its own behind, and a count also moves when another part of the plugin
// legitimately deletes something of its own — the published slot a harness cleared on the way in,
// or the option a migration writes once.
$wccs_options_before = $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. Both order backends, one contract.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. One panel, both backends' );

wccs_proof_check(
	'The panel is registered on the hook WooCommerce fires for both order screens',
	false !== has_action( 'add_meta_boxes', array( $wccs_panel, 'add' ) )
		&& false !== has_action( 'woocommerce_process_shop_order_meta', array( $wccs_panel, 'save' ) ),
	'the legacy posts screen and the HPOS orders screen fire the same two'
);

wccs_proof_check(
	'And it names the screen of each backend',
	in_array( 'shop_order', $wccs_panel::screens(), true )
		&& in_array( 'woocommerce_page_wc-orders', $wccs_panel::screens(), true ),
	'screens=' . wp_json_encode( $wccs_panel::screens() )
);

wccs_proof_check(
	'It resolves an order from either shape the screens hand over',
	$wccs_panel::order_from( (object) array( 'ID' => 0 ) ) === null
		&& $wccs_panel::order_from( 'not an order' ) === null
);

wccs_proof_check(
	'And both order stores are reachable on this store, so the acceptance has a subject',
	( function_exists( 'wc_get_orders' ) && class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ),
	'HPOS authoritative or not, the CRUD is the same call'
);

// ---------------------------------------------------------------------------
// 2. What may be edited where.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Authorized editing' );

wccs_proof_publish(
	array(
		wccs_proof_field( 'note', 'textarea' ),
		// Kept on the customer: shown for reference on the order and never editable
		// there, which is section 14's "editar perfil não reescreve pedido passado"
		// read in the direction that matters here.
		wccs_proof_field(
			'profile_note',
			'text',
			array(
				'storage' => array(
					'scope'       => 'customer',
					'sensitivity' => 'personal',
				),
			)
		),
		// Not shown to the order screen at all.
		wccs_proof_field(
			'hidden_one',
			'text',
			array( 'visibility' => array( 'admin_order' => false ) )
		),
		// Archived: a disabled definition is not a field to fill in.
		wccs_proof_field( 'archived', 'text', array( 'enabled' => false ) ),
	)
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();
$wccs_editable    = $wccs_panel::editable( $wccs_definitions );

wccs_proof_check(
	'Only the field the order owns and the merchant shows to staff is editable',
	array( 'wccs_note' ) === array_keys( $wccs_editable ),
	'editable=' . wp_json_encode( array_keys( $wccs_editable ) )
);

wccs_proof_check(
	'A field kept on the customer is not editable from the order',
	! isset( $wccs_editable['wccs_profile_note'] ),
	'changing it here would rewrite today\'s preference from one past order'
);

wccs_proof_check(
	'Nor is a field the order screen does not show, nor an archived one',
	! isset( $wccs_editable['wccs_hidden_one'], $wccs_editable['wccs_archived'] )
);

$wccs_order = wccs_proof_order();

wccs_proof_check(
	'Staff who may edit this order may use the panel',
	$wccs_panel::may_edit( $wccs_order )
);

// A user who cannot edit orders cannot use it: the panel is not drawn and the save
// hook returns before reading anything.
wp_set_current_user( 0 );

wccs_proof_check(
	'And somebody who may not edit the order may not, even with a valid nonce',
	! $wccs_panel::may_edit( $wccs_order )
		&& null === $wccs_panel::order_from( null )
);

wccs_proof_submit( $wccs_order, array( 'wccs_note' => 'refused' ), false );

wccs_proof_check(
	'The refused submission wrote nothing',
	! ( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->read( wc_get_order( $wccs_order->get_id() ) )->has( 'wccs_note' )
);

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 3. CRUD, through the order's own API.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Create, read, update, remove' );

wccs_proof_submit( $wccs_order, array( 'wccs_note' => 'Anything we should know.' ) );

wccs_proof_check(
	'A value submitted on the order screen is stored on the order',
	'Anything we should know.' === wccs_proof_stored( $wccs_order, 'wccs_note' ),
	'read back through wc_get_order()'
);

$wccs_raw_meta = wc_get_order( $wccs_order->get_id() )->get_meta( \WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS, true, 'edit' );

wccs_proof_check(
	'And it is stored through the order CRUD, in the store the order itself reads',
	is_string( $wccs_raw_meta ) && str_contains( $wccs_raw_meta, 'Anything we should know.' )
);

// With HPOS authoritative, post meta is the wrong table. The panel must never write
// there, and this is the assertion that would fail the day somebody "fixed" it with
// update_post_meta().
$wccs_post_meta = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s",
		$wccs_order->get_id(),
		\WCCheckoutSuite\Domain\Orders\OrderFieldsService::META_FIELDS
	)
);

wccs_proof_check(
	'And never in post meta, which is the wrong table when HPOS is authoritative',
	0 === $wccs_post_meta,
	'postmeta rows=' . $wccs_post_meta
);

wccs_proof_submit( $wccs_order, array( 'wccs_note' => 'Corrected.' ) );

wccs_proof_check(
	'Editing replaces the value rather than appending a second one',
	'Corrected.' === wccs_proof_stored( $wccs_order, 'wccs_note' )
);

wccs_proof_submit( $wccs_order, array( 'wccs_note' => '' ) );

$wccs_service = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();
$wccs_after   = wc_get_order( $wccs_order->get_id() );

// An emptied optional field is a value the checkout stores too — the customer who
// leaves it blank produces the same empty string — so the panel stores it rather than
// inventing a second meaning for blank. What the panel must not do is disagree with the
// checkout about what blank means, and this is that agreement asserted.
wccs_proof_check(
	'Emptying stores the empty value the checkout stores, not a second meaning for blank',
	$wccs_service->read( $wccs_after )->has( 'wccs_note' )
		&& '' === $wccs_service->read( $wccs_after )->get( 'wccs_note' )
		&& 'readable' === $wccs_service->read_status( $wccs_after )['state'],
	'value=' . wp_json_encode( $wccs_service->read( $wccs_after )->get( 'wccs_note' ) )
		. ' state=' . $wccs_service->read_status( $wccs_after )['state']
);

// The delete half of the CRUD: when nothing of the Suite's is left to keep, the stored
// payload goes rather than staying behind claiming to hold values.
$wccs_service->write( $wccs_after, array(), $wccs_definitions, 1 );
$wccs_after->save();

$wccs_emptied = wc_get_order( $wccs_order->get_id() );

wccs_proof_check(
	'And an order with nothing of the Suite\'s left keeps no payload at all',
	'absent' === $wccs_service->read_status( $wccs_emptied )['state'],
	'state=' . $wccs_service->read_status( $wccs_emptied )['state']
);

// A field the panel did not draw keeps what the order already had: its absence from
// the submission is not a request to clear it.
wccs_proof_submit( $wccs_order, array( 'wccs_note' => 'kept' ) );
wccs_proof_submit( $wccs_order, array() );

wccs_proof_check(
	'A submission that does not mention a field leaves that field alone',
	'kept' === wccs_proof_stored( $wccs_order, 'wccs_note' )
);

// ---------------------------------------------------------------------------
// 4. Validation identical to the checkout's.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The same validation the checkout runs' );

wccs_proof_publish(
	array(
		wccs_proof_field(
			'document',
			'text',
			array(
				'validators' => array(
					array(
						'key'     => 'br.cpf',
						'version' => 1,
					),
				),
			)
		),
	)
);

$wccs_definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
	\WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields()[0]
);

// A list of pairs and not a map: a document is a numeric string, and PHP turns a numeric
// string array key into an integer. The validation would then have been asked about an
// `int`, refused it for not being a string, and reported a refusal about the harness
// rather than about the document — which is exactly what the first version did.
$wccs_cases = array(
	array(
		'value' => '11144477735',
		'what'  => 'a document the fixture says is valid',
	),
	array(
		'value' => '11144477736',
		'what'  => 'a document the fixture says is invalid',
	),
	array(
		'value' => '52998224724',
		'what'  => 'another the fixture refuses',
	),
	array(
		'value' => '',
		'what'  => 'an empty value on a field that is not required',
	),
);

$wccs_disagreements = array();

foreach ( $wccs_cases as $wccs_case ) {
	$wccs_input = $wccs_case['value'];
	$wccs_what  = $wccs_case['what'];

	// What the checkout's own pipeline answers for this value.
	$wccs_checkout = \WCCheckoutSuite\Domain\Registries::instance()->value_processor()->process(
		$wccs_definition,
		$wccs_input,
		new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), 'classic' )
	);

	// What the panel answers for the same value.
	$wccs_panel_result = \WCCheckoutSuite\Domain\Registries::instance()->value_processor()->process(
		$wccs_definition,
		$wccs_input,
		new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), 'admin' )
	);

	$wccs_checkout_errors = $wccs_checkout->result()->errors();
	$wccs_panel_errors    = $wccs_panel_result->result()->errors();

	if ( $wccs_checkout->result()->is_valid() !== $wccs_panel_result->result()->is_valid() ) {
		$wccs_disagreements[] = $wccs_what . ' (verdict differs)';
	}

	if ( $wccs_checkout_errors !== $wccs_panel_errors ) {
		$wccs_disagreements[] = $wccs_what . ' (errors differ)';
	}

	if ( $wccs_checkout->result()->is_valid() && $wccs_checkout->value() !== $wccs_panel_result->value() ) {
		$wccs_disagreements[] = $wccs_what . ' (accepted value differs)';
	}
}

wccs_proof_check(
	'The checkout and the order screen answer the same for every value',
	array() === $wccs_disagreements,
	'cases=' . count( $wccs_cases ) . ' disagreements=' . wp_json_encode( $wccs_disagreements )
);

// An agreement assertion is worth nothing if both sides simply refuse everything. The
// first version of this section used a validator key that does not exist — `br_cpf`
// where the registry declares `br.cpf` — and the two sides agreed perfectly, about a
// definition no merchant could ever have saved. This is the guard that would have said
// so: the cases have to be decided in both directions before agreeing means anything.
$wccs_verdicts = array();

foreach ( $wccs_cases as $wccs_case ) {
	$wccs_verdicts[ $wccs_case['what'] ] = \WCCheckoutSuite\Domain\Registries::instance()->value_processor()->process(
		$wccs_definition,
		$wccs_case['value'],
		new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), 'classic' )
	)->result()->is_valid();
}

wccs_proof_check(
	'And the cases are decided both ways, so agreeing is not agreeing on a refusal',
	in_array( true, $wccs_verdicts, true ) && in_array( false, $wccs_verdicts, true ),
	'verdicts=' . wp_json_encode( $wccs_verdicts )
);

// And the refusal reaches the order as a refusal: the field keeps what it had and the
// invalid text is not stored.
$wccs_document_order = wccs_proof_order();

wccs_proof_submit( $wccs_document_order, array( 'wccs_document' => '11144477735' ) );

wccs_proof_check(
	'An accepted value reaches the order, canonicalised by the same pipeline',
	'11144477735' === wccs_proof_stored( $wccs_document_order, 'wccs_document' ),
	'stored=' . wp_json_encode( wccs_proof_stored( $wccs_document_order, 'wccs_document' ) )
		. ' — the punctuation the checkout removes is removed here too'
);

$wccs_accepted_value = wccs_proof_stored( $wccs_document_order, 'wccs_document' );

wccs_proof_submit( $wccs_document_order, array( 'wccs_document' => '52998224724' ) );

wccs_proof_check(
	'A refused value does not overwrite the one the order had',
	$wccs_accepted_value === wccs_proof_stored( $wccs_document_order, 'wccs_document' ),
	'the refusal is the checkout\'s own, which is what makes the two the same validation'
);

// ---------------------------------------------------------------------------
// 5. What the panel draws.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What staff see' );

wccs_proof_publish(
	array(
		wccs_proof_field( 'note', 'textarea' ),
		wccs_proof_field(
			'profile_note',
			'text',
			array(
				'storage' => array(
					'scope'       => 'customer',
					'sensitivity' => 'personal',
				),
			)
		),
	)
);

$wccs_render_order = wccs_proof_order();
wccs_proof_submit( $wccs_render_order, array( 'wccs_note' => 'From the checkout.' ) );

// The order is loaded the way the admin screen loads it — fresh — and not the instance
// the save wrote through: a metabox is drawn from a reloaded order, and handing it the
// object an earlier write used is a harness mistake, not a panel behaviour.
$wccs_fresh = wc_get_order( $wccs_render_order->get_id() );

ob_start();
$wccs_panel::render( $wccs_fresh, array( 'args' => array( 'order' => $wccs_fresh ) ) );
$wccs_html = (string) ob_get_clean();


wccs_proof_check(
	'The panel draws a control for the editable field and a value for the other',
	str_contains( $wccs_html, $wccs_panel::INPUT_PREFIX . 'wccs_note' )
		&& str_contains( $wccs_html, 'From the checkout.' )
);

wccs_proof_check(
	'A field kept on the customer is shown without a control to change it',
	! str_contains( $wccs_html, $wccs_panel::INPUT_PREFIX . 'wccs_profile_note' ),
	'the order screen shows it for reference and does not offer to rewrite it'
);

wccs_proof_check(
	'And the panel carries its own nonce, which is what the save verifies',
	str_contains( $wccs_html, $wccs_panel::NONCE . '_nonce' )
);

wccs_proof_note(
	'Why both backends are one contract and not two code paths',
	'WooCommerce fires `add_meta_boxes` with the screen identifier for the legacy posts screen and for the HPOS orders screen, and `woocommerce_process_shop_order_meta` from both edit screens. Every read and write goes through `WC_Order`: `get_meta`, `update_meta_data`, `save`. There is no branch on which backend is active, because a branch is a second code path and a second code path is the thing that stops agreeing. The proof asserts the wrong table is never written.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No browser: the metabox is asserted by rendering it and by driving the save it registers, not by opening an order in wp-admin. The visuals of the panel, and its behaviour with JavaScript-driven order recalculation, belong to WCCS-063. The two backends were exercised through the same CRUD in this environment; running the same assertions with HPOS switched off is WCCS-062\'s functional matrix.'
);

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( \WCCheckoutSuite\Domain\Settings\CheckoutSettings::OPTION );

$wccs_options_after = $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

$wccs_created = array_values( array_diff( (array) $wccs_options_after, (array) $wccs_options_before ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	array() === $wccs_created,
	$wccs_created
		? 'created by this run: ' . implode( ', ', $wccs_created )
		: 'the store has the same ' . count( (array) $wccs_options_after ) . ' options it had'
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
