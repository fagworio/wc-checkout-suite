<?php
/**
 * F14 native-values proof harness — the values the platform stored, shown like any other.
 *
 * Task:   the user-level pass over F14, finding F-4 (docs/validation/F14-fluxos-de-utilizador.md)
 * Phase:  F14
 * Accept: "A real checkout with a native field shows the value in the areas it was linked to,
 *          and the order waits in the review state."
 *
 * Section 13 gives a natively rendered additional field one authority: WooCommerce's own API.
 * The Suite's payload never receives that value, and before this pass no Suite surface read it
 * either — a customer answered four fields, the store kept them, and every area was empty.
 *
 * This harness writes the order the way the platform writes it (the meta keys WooCommerce uses,
 * with the integration identifier) and then reads everything back:
 *
 * 1. **Nothing is copied.** This storage alone still knows nothing about those values; the read
 *    that is given the definitions sees them, and that is the only difference.
 * 2. **Every projection sees them**, in the areas the links name and nowhere else.
 * 3. **The approval flow holds the order**, because it reads through the same door.
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
 * Writes the published document through the repository.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Sections.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_proof_publish( array $fields, array $sections ): \WCCheckoutSuite\Domain\Schema\WriteResult {
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
 * One plain text field, which is what the platform renders natively.
 *
 * @param string               $id           Identifier.
 * @param string               $label        Label.
 * @param array<string, mixed> $destinations Destination links.
 * @param array<string, mixed> $extra        Extra definition keys.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $label, array $destinations, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => $label,
			'section'        => 'dados_fiscais',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'settings'       => array(),
			'destinations'   => $destinations,
		),
		$extra
	);
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'F14 native-values proof — the platform stored it, every area shows it' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_before = wccs_proof_option_count();

$wccs_link = static function ( string $section, string $title, int $position ): array {
	return array(
		'enabled'  => true,
		'section'  => $section,
		'title'    => $title,
		'position' => $position,
	);
};

$wccs_off = array( 'enabled' => false );

$wccs_fields = array(
	wccs_proof_field(
		'documento_fiscal',
		'CPF ou CNPJ',
		array(
			'admin_order'    => $wccs_link( 'analise', 'Documento fiscal', 10 ),
			'customer_order' => $wccs_link( 'enviados', 'Documento enviado', 10 ),
			'customer_email' => $wccs_link( 'email', 'Documento do pedido', 10 ),
			'admin_email'    => $wccs_link( 'email', 'Cópia para a loja', 20 ),
			'public_api'     => array( 'enabled' => true ),
			'order_received' => $wccs_off,
			'customer_profile' => $wccs_off,
		),
		array(
			'approval' => array(
				'require_review' => true,
				'area'           => 'admin_order',
				'section'        => 'analise',
				'status'         => 'Pendente de aprovação',
				'show_status'    => true,
			),
		)
	),
	wccs_proof_field(
		'codigo_retirada',
		'Código de retirada',
		array(
			'order_received' => $wccs_link( 'enviados', 'Código de retirada', 10 ),
			'admin_order'    => $wccs_off,
			'customer_order' => $wccs_off,
			'customer_email' => $wccs_off,
			'admin_email'    => $wccs_off,
			'public_api'     => $wccs_off,
			'customer_profile' => $wccs_off,
		)
	),
	wccs_proof_field(
		'sem_vinculo',
		'Campo sem vínculo',
		array(
			'admin_order'      => $wccs_off,
			'customer_order'   => $wccs_off,
			'customer_email'   => $wccs_off,
			'admin_email'      => $wccs_off,
			'public_api'       => $wccs_off,
			'customer_profile' => $wccs_off,
			'order_received'   => $wccs_off,
		)
	),
);

$wccs_sections = array(
	array(
		'id'       => 'dados_fiscais',
		'title'    => 'Dados fiscais',
		'position' => 10,
		'location' => 'billing',
		'areas'    => array( 'checkout' ),
	),
	array(
		'id'       => 'analise',
		'title'    => 'Documentos para análise',
		'position' => 20,
		'location' => 'order',
		'areas'    => array( 'admin_order' ),
	),
	array(
		'id'       => 'enviados',
		'title'    => 'Documentos enviados',
		'position' => 30,
		'location' => 'order',
		'areas'    => array( 'customer_order', 'order_received' ),
	),
	array(
		'id'       => 'email',
		'title'    => 'Documentos do pedido',
		'position' => 40,
		'location' => 'order',
		'areas'    => array( 'customer_email', 'admin_email' ),
	),
);

$wccs_published = wccs_proof_publish( $wccs_fields, $wccs_sections );

wccs_proof_check(
	'The document this harness reads was published',
	$wccs_published->is_ok(),
	'status=' . $wccs_published->status()
);

// The registration of the review status happens on `init`, which has already run in this
// request, so the harness asks for it the way the next page load would.
\WCCheckoutSuite\Domain\Approval\ReviewStatus::publish();

// The order, with the values **only where the platform puts them**: WooCommerce's meta keys,
// under the identifier the field was registered with. Nothing is written to the Suite's payload.
$wccs_order = wc_create_order();
$wccs_order->set_status( 'processing' );
$wccs_order->save();

$wccs_native = array(
	'documento_fiscal' => '123.456.789-09',
	'codigo_retirada'  => 'RET-42',
	'sem_vinculo'      => 'NUNCA-MOSTRAR',
);

foreach ( $wccs_native as $wccs_id => $wccs_value ) {
	$wccs_order->update_meta_data( '_wc_billing/wc-checkoutsuite/' . $wccs_id, $wccs_value );
}

$wccs_order->save();
$wccs_order = wc_get_order( $wccs_order->get_id() );

$wccs_service = new \WCCheckoutSuite\Domain\Orders\OrderFieldsService();

// ---------------------------------------------------------------------------
// 1. Nothing is copied, and the read that is given the definitions sees them.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Two authorities, one answer' );

$wccs_own = $wccs_service->read( $wccs_order )->all();

wccs_proof_check(
	'The Suite\'s own storage still knows nothing about those values',
	array() === $wccs_own,
	'payload=' . wp_json_encode( $wccs_own )
);

$wccs_all = $wccs_service->read( $wccs_order, $wccs_fields )->all();

wccs_proof_check(
	'And the read that is given the document answers with them',
	$wccs_native === array_intersect_key( $wccs_all, $wccs_native ) && count( $wccs_all ) === count( $wccs_native ),
	'values=' . wp_json_encode( $wccs_all )
);

wccs_proof_check(
	'Nothing was written to this storage by reading it',
	array() === $wccs_service->read( wc_get_order( $wccs_order->get_id() ) )->all(),
	'the payload is still empty'
);

$wccs_history = array_map(
	static fn( $entry ) => $entry->id(),
	$wccs_service->history( $wccs_order, $wccs_fields )
);

wccs_proof_check(
	'The history reads them too, which is the door every projection uses',
	$wccs_history === array( 'documento_fiscal', 'codigo_retirada', 'sem_vinculo' ),
	'ids=' . implode( ',', $wccs_history )
);

// ---------------------------------------------------------------------------
// 2. Every area shows them, in the areas the links name.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Who shows them' );

$wccs_render = static function ( callable $render ): string {
	ob_start();
	$render();

	return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
};

/**
 * Renders one callback and returns its markup, attributes included.
 *
 * @param callable $render Renderer.
 * @return string Markup.
 */
function wccs_proof_html( callable $render ): string {
	ob_start();
	$render();

	return (string) ob_get_clean();
}

$wccs_panel_html = wccs_proof_html( static fn() => \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render( $wccs_order ) );
$wccs_panel      = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $wccs_panel_html ) ) );
$wccs_email = $wccs_render( static fn() => \WCCheckoutSuite\Checkout\OrderEmailFields::render( $wccs_order, false, false, null ) );

// The panel edits the field, so the value is the control's, not the text: reading it back as
// text strips the attribute the value actually lives in — which is what a first version of this
// check did, and it reported a defect that was not there.
wccs_proof_check(
	'The order screen shows the natively stored value under the configured section and title',
	str_contains( $wccs_panel, 'Documentos para análise' )
		&& str_contains( $wccs_panel, 'Documento fiscal' )
		&& str_contains( $wccs_panel_html, 'value="123.456.789-09"' ),
	'input carries the value'
);

wccs_proof_check(
	'And the customer e-mail does, under its own',
	str_contains( $wccs_email, 'Documentos do pedido' )
		&& str_contains( $wccs_email, 'Documento do pedido' )
		&& str_contains( $wccs_email, '123.456.789-09' ),
	$wccs_email
);

wccs_proof_check(
	'Neither of them shows the field linked nowhere',
	! str_contains( $wccs_panel, 'NUNCA-MOSTRAR' ) && ! str_contains( $wccs_email, 'NUNCA-MOSTRAR' ),
	'the unlinked value stays out'
);

// The two customer pages, read the way each of them reads.
$wccs_account = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( $wccs_fields, 'customer_order' );
$wccs_thankyou = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( $wccs_fields, 'order_received' );

wccs_proof_check(
	'Each customer page lists only its own destination',
	isset( $wccs_account['documento_fiscal'] ) && ! isset( $wccs_account['codigo_retirada'] )
		&& isset( $wccs_thankyou['codigo_retirada'] ) && ! isset( $wccs_thankyou['documento_fiscal'] ),
	'account=' . implode( ',', array_keys( $wccs_account ) ) . ' thank-you=' . implode( ',', array_keys( $wccs_thankyou ) )
);

// ---------------------------------------------------------------------------
// 3. The approval flow holds the order, through the same door.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The review that depends on it' );

$wccs_held = \WCCheckoutSuite\Domain\Approval\ReviewStatus::apply( wc_get_order( $wccs_order->get_id() ) );
$wccs_status = wc_get_order( $wccs_order->get_id() )->get_status();

wccs_proof_check(
	'The order waits in the state the merchant named',
	$wccs_held && str_starts_with( $wccs_status, 'wccs-' ),
	'status=' . $wccs_status
);

$wccs_quiet = wc_create_order();
$wccs_quiet->set_status( 'processing' );
$wccs_quiet->save();
$wccs_quiet = wc_get_order( $wccs_quiet->get_id() );

wccs_proof_check(
	'And an order whose field has no value anywhere is not held',
	! \WCCheckoutSuite\Domain\Approval\ReviewStatus::apply( $wccs_quiet ) && 'processing' === $wccs_quiet->get_status(),
	'status=' . $wccs_quiet->get_status()
);

// ---------------------------------------------------------------------------
// Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What the harness left behind' );

foreach ( array( $wccs_order->get_id(), $wccs_quiet->get_id() ) as $wccs_id ) {
	$wccs_created = wc_get_order( $wccs_id );

	if ( $wccs_created instanceof WC_Order ) {
		$wccs_created->delete( true );
	}
}

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( 'wccs_schema_revisions' );

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
