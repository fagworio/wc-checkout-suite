<?php
/**
 * Fase 6 proof harness — the three order surfaces obey bindings and nothing else.
 *
 * Task:   Fase 6 "Pedido cliente/Admin/E-mail sobre bindings"
 * Gate:   "todos obedecem somente bindings explícitos."
 *
 * The order faces the customer (the order view and the thank-you page), the staff (the order
 * screen) and the e-mail both receive. All three read one published document, and what each of
 * them shows has to come from the **uses** the merchant configured for its own destination:
 *
 * 1. **A field used twice in one destination is shown twice**, each use in its own container,
 *    under its own title and in its own order — this is what §3.3 exists for.
 * 2. **A use that is not visible is shown nowhere**, and the field-level question ("may this area
 *    show this value at all?") answers the same thing the page does instead of promising a row
 *    that never appears.
 * 3. **A file's permissions are decided per use**: a use that withholds the name is not listed,
 *    while another use of the same file in the same area is.
 * 4. **A field nobody bound appears in none of them**, however enabled it is.
 * 5. The harness owns the document, the order and every option it touches.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce loaded.
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
 * One use of a field, in the final model.
 *
 * @param string             $destination Destination.
 * @param string             $container   Container.
 * @param string             $title       Title shown there.
 * @param int                $position    Order inside the container.
 * @param array<int, string> $permissions What that use may do.
 * @param bool               $visible     Whether it is shown.
 * @return array<string, mixed>
 */
function wccs_proof_use( string $destination, string $container, string $title, int $position, array $permissions = array(), bool $visible = true ): array {
	return array(
		'container_id'   => $container,
		'destination'    => $destination,
		'label_override' => $title,
		'position'       => $position,
		'visible'        => $visible,
		'editable'       => false,
		'permissions'    => $permissions,
	);
}

/**
 * One field with its uses.
 *
 * @param string               $id      Identifier.
 * @param string               $label   Label.
 * @param array<int, mixed>    $uses    Bindings.
 * @param array<string, mixed> $extra   Extra keys.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $label, array $uses, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
			'label'          => $label,
			'section'        => 'resumo_do_pedido',
			'enabled'        => true,
			'required'       => false,
			'position'       => 10,
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'bindings'       => $uses,
			'destinations'   => array(),
		),
		$extra
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 6 proof — the order surfaces obey bindings, and only bindings' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

// ---------------------------------------------------------------------------
// 1. A document with uses, and an order that holds their values.
// ---------------------------------------------------------------------------
$wccs_proof_definitions = array(
	// One field, two uses in the same destination, in two containers and two orders.
	wccs_proof_field(
		'observacao',
		'Observação',
		array(
			wccs_proof_use( 'customer_order', 'resumo_do_pedido', 'Sua observação', 10 ),
			wccs_proof_use( 'customer_order', 'detalhes', 'Observação (detalhes)', 20 ),
			// And one use in the mail's own destination: what each surface shows comes from the
			// destination it serves, not from the other one.
			wccs_proof_use( 'customer_email', 'email_do_pedido', 'Observação no e-mail', 10 ),
			// The staff read the same field on the order screen, with their own titles.
			wccs_proof_use( 'admin_order', 'painel_da_equipa', 'Observação (equipa)', 10 ),
			wccs_proof_use( 'admin_order', 'conferencia', 'Observação (conferência)', 20 ),
		)
	),
	// One field whose only use in this destination is not visible.
	wccs_proof_field(
		'escondido',
		'Escondido',
		array( wccs_proof_use( 'customer_order', 'resumo_do_pedido', 'Escondido', 30, array(), false ) )
	),
	// A field nobody bound anywhere.
	wccs_proof_field( 'sem_vinculo', 'Sem vínculo', array() ),
	wccs_proof_field(
		'documento',
		'Documento',
		array(
			// The first use withholds the name; the second shows it.
			wccs_proof_use( 'customer_order', 'resumo_do_pedido', 'Documento oculto', 40, array( 'view' ) ),
			wccs_proof_use( 'customer_order', 'detalhes', 'Documento', 50, array( 'show_metadata', 'view' ) ),
			// In the mail the use never asked for the name, so the document is not listed there.
			wccs_proof_use( 'customer_email', 'email_do_pedido', 'Documento no e-mail', 20, array( 'view' ) ),
		),
		array(
			'type'     => 'file',
			'storage'  => array(
				'scope'       => 'order',
				'sensitivity' => 'sensitive',
			),
			'settings' => array(
				'maxFiles'          => 1,
				'allowedExtensions' => array( 'pdf' ),
			),
		)
	),
);

$wccs_proof_containers = array(
	array(
		'id'          => 'resumo_do_pedido',
		'name'        => 'Resumo do pedido',
		'destination' => 'customer_order',
		'position'    => 10,
		'enabled'     => true,
		'show_title'  => true,
	),
	array(
		'id'          => 'detalhes',
		'name'        => 'Detalhes',
		'destination' => 'customer_order',
		'position'    => 20,
		'enabled'     => true,
		'show_title'  => true,
	),
	array(
		'id'          => 'email_do_pedido',
		'name'        => 'Informações do pedido',
		'destination' => 'customer_email',
		'position'    => 10,
		'enabled'     => true,
		'show_title'  => true,
	),
	array(
		'id'          => 'painel_da_equipa',
		'name'        => 'Painel da equipa',
		'destination' => 'admin_order',
		'position'    => 10,
		'enabled'     => true,
		'show_title'  => true,
	),
	array(
		'id'          => 'conferencia',
		'name'        => 'Conferência',
		'destination' => 'admin_order',
		'position'    => 20,
		'enabled'     => true,
		'show_title'  => true,
	),
);

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_proof_written = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision' => 1,
			'fields'   => $wccs_proof_definitions,
			'sections' => $wccs_proof_containers,
			'settings' => array(),
		)
	),
	$wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED )->revision()
);

wccs_proof_check(
	'The document with two uses of one field is a document the store accepts',
	$wccs_proof_written->is_ok(),
	'ok=' . ( $wccs_proof_written->is_ok() ? 'yes' : 'no' ) . ' errors=' . implode( ',', array_map( static fn( $error ) => (string) $error['code'], $wccs_proof_written->errors() ) )
);

$wccs_proof_order = wc_create_order( array( 'status' => 'processing' ) );
$wccs_proof_order = $wccs_proof_order instanceof WC_Order ? $wccs_proof_order : null;

if ( null === $wccs_proof_order ) {
	fwrite( STDERR, "Could not create the order this harness needs.\n" );
	exit( 1 );
}

( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write(
	$wccs_proof_order,
	array(
		'observacao'  => 'Entregar depois das 18h',
		'escondido'   => 'não devia aparecer',
		'sem_vinculo' => 'não devia aparecer',
		'documento'   => 'contrato.pdf',
	),
	$wccs_proof_definitions,
	1
);

$wccs_proof_order->save();

// ---------------------------------------------------------------------------
// 2. The customer's own view of the order.
// ---------------------------------------------------------------------------
ob_start();
\WCCheckoutSuite\Checkout\CustomerOrderFields::render( $wccs_proof_order );
$wccs_proof_view = (string) ob_get_clean();

$wccs_proof_view_text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $wccs_proof_view ) ) );

wccs_proof_check(
	'A field used twice in one destination is shown twice on the order view',
	2 === substr_count( $wccs_proof_view, 'Sua observação' ) + substr_count( $wccs_proof_view, 'Observação (detalhes)' ) &&
		1 === substr_count( $wccs_proof_view, 'Sua observação' ) &&
		1 === substr_count( $wccs_proof_view, 'Observação (detalhes)' ),
	$wccs_proof_view_text
);

wccs_proof_check(
	'Each use sits in its own container, in the order the uses configured',
	strpos( $wccs_proof_view, 'Resumo do pedido' ) < strpos( $wccs_proof_view, 'Detalhes' ) &&
		strpos( $wccs_proof_view, 'Sua observação' ) < strpos( $wccs_proof_view, 'Observação (detalhes)' ),
	'containers and uses in document order'
);

wccs_proof_check(
	'A use that is not visible is shown nowhere',
	false === strpos( $wccs_proof_view, 'Escondido' ),
	'the value exists on the order and no row prints it'
);

wccs_proof_check(
	'And a file use that withholds the name is not listed, while the other one is',
	false === strpos( $wccs_proof_view, 'Documento oculto' ) &&
		false !== strpos( $wccs_proof_view, 'Documento' ),
	'one use withheld show_metadata, the other did not'
);

wccs_proof_check(
	'A field nobody bound appears in none of them',
	false === strpos( $wccs_proof_view, 'Sem vínculo' ),
	'unbound field absent'
);

// ---------------------------------------------------------------------------
// 3. The question the surfaces ask before they draw.
// ---------------------------------------------------------------------------
$wccs_proof_visible = \WCCheckoutSuite\Checkout\CustomerOrderFields::visible( $wccs_proof_definitions, 'customer_order' );

wccs_proof_check(
	'The field-level question answers what the page does: a use that is not visible is not shown',
	! isset( $wccs_proof_visible['escondido'] ) && isset( $wccs_proof_visible['observacao'] ),
	'visible=' . implode( ',', array_keys( $wccs_proof_visible ) )
);

wccs_proof_check(
	'And a field with no use in that destination is not claimed either',
	! isset( $wccs_proof_visible['sem_vinculo'] ),
	'no use, no row'
);

// ---------------------------------------------------------------------------
// 4. The e-mail both sides receive.
// ---------------------------------------------------------------------------
ob_start();
\WCCheckoutSuite\Checkout\OrderEmailFields::render( $wccs_proof_order, false, true );
$wccs_proof_email = (string) ob_get_clean();

wccs_proof_check(
	'The e-mail shows the use its own destination configured, once',
	1 === substr_count( $wccs_proof_email, 'Observação no e-mail' ),
	'mail rows=' . substr_count( $wccs_proof_email, 'Observação' )
);

wccs_proof_check(
	'And it does not borrow the rows the order view configured',
	false === strpos( $wccs_proof_email, 'Sua observação' ) &&
		false === strpos( $wccs_proof_email, 'Observação (detalhes)' ),
	'one destination does not speak for another'
);

wccs_proof_check(
	'A file use that never asked for the name is not listed in the e-mail',
	false === strpos( $wccs_proof_email, 'Documento no e-mail' ),
	'the use withheld show_metadata in this destination'
);

// ---------------------------------------------------------------------------
// 5. The staff panel on the order screen.
// ---------------------------------------------------------------------------
ob_start();
\WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render_inline( $wccs_proof_order );
$wccs_proof_panel = (string) ob_get_clean();

wccs_proof_check(
	'The staff panel shows the two uses its own destination configured',
	1 === substr_count( $wccs_proof_panel, 'Observação (equipa)' ) &&
		1 === substr_count( $wccs_proof_panel, 'Observação (conferência)' ),
	'panel shows ' . substr_count( $wccs_proof_panel, 'Observação (' ) . ' of its uses'
);

wccs_proof_check(
	'And it does not borrow the rows the customer surfaces configured',
	false === strpos( $wccs_proof_panel, 'Sua observação' ) &&
		false === strpos( $wccs_proof_panel, 'Observação no e-mail' ),
	'one destination does not speak for another'
);

wccs_proof_check(
	'And it shows nothing the document did not bind there',
	false === strpos( $wccs_proof_panel, 'Sem vínculo' ) && false === strpos( $wccs_proof_panel, 'Escondido' ),
	'only bound, visible uses'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
$wccs_proof_order->delete( true );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . wccs_proof_option_count()
);

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
