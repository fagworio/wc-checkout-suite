<?php
/**
 * WCCS-076 proof harness — the audit of every area.
 *
 * Task:   WCCS-076 "Provar a ausência de inserção automática"
 * Phase:  F14
 * Accept: "Em cada área, um campo sem vínculo não aparece; com vínculo, aparece na
 *          seção, título e ordem configurados."
 *
 * This harness is the audit the phase's gate asks for, and it is written from the
 * surfaces inwards: each area is rendered the way the store renders it, and then read
 * back. Two sentences are checked for every area, and both have to hold at once.
 *
 * 1. **A field with no link is nowhere.** Not in the order screen, not on the
 *    customer's page, not on the thank-you page, not in either e-mail. The stronger
 *    version is checked too: a field linked to *another* area does not leak into this
 *    one, and an area with no links at all renders nothing.
 * 2. **A linked field appears as configured.** Under the section the link names, with
 *    the title it gives, in the order it puts it — and the document's own order decides
 *    nothing.
 *
 * The list of areas is the model's: the six that draw something, plus the integration
 * projection. One of them has no surface at all, and that one is reported here rather
 * than hidden: see the note at the end of section 4.
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
 * Writes the published document, through the real repository so it is validated.
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
 * One field of the document.
 *
 * @param string               $id           Identifier.
 * @param string               $label        Label.
 * @param int                  $position     The field's own position.
 * @param array<string, mixed> $destinations Destination links.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $label, int $position, array $destinations ): array {
	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => $label,
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => false,
		'position'       => $position,
		'destinations'   => $destinations,
	);
}

/**
 * One section of the document.
 *
 * @param string             $id       Identifier.
 * @param string             $title    Title.
 * @param int                $position Position.
 * @param array<int, string> $areas    Areas it is offered in.
 * @return array<string, mixed>
 */
function wccs_proof_section( string $id, string $title, int $position, array $areas ): array {
	return array(
		'id'       => $id,
		'title'    => $title,
		'position' => $position,
		'location' => 'order',
		'areas'    => $areas,
	);
}

/**
 * The document this harness audits.
 *
 * `wccs_segundo` is declared first and configured second, so an area that printed the
 * document's order instead of the link's would be seen doing it.
 *
 * @return array{fields: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}
 */
function wccs_proof_document(): array {
	$link = static function ( string $section, string $title, int $position ): array {
		return array(
			'enabled'  => true,
			'section'  => $section,
			'title'    => $title,
			'position' => $position,
		);
	};

	return array(
		'fields'   => array(
			wccs_proof_field(
				'wccs_segundo',
				'Rótulo segundo',
				1,
				array(
					'admin_order'      => $link( 'analise', 'Segundo na análise', 20 ),
					'customer_order'   => $link( 'enviados', 'Documento enviado', 20 ),
					'customer_email'   => $link( 'email', 'Anexo do e-mail', 20 ),
					'admin_email'      => $link( 'email', 'Cópia da loja', 20 ),
					'public_api'       => array( 'enabled' => true ),
					'customer_profile' => array( 'enabled' => false ),
					'order_received'   => array( 'enabled' => false ),
				)
			),
			wccs_proof_field(
				'wccs_primeiro',
				'Rótulo primeiro',
				2,
				array(
					'admin_order'      => $link( 'analise', 'Primeiro na análise', 10 ),
					'customer_order'   => array( 'enabled' => false ),
					'customer_email'   => array( 'enabled' => false ),
					'admin_email'      => array( 'enabled' => false ),
					'public_api'       => array( 'enabled' => false ),
					'customer_profile' => array( 'enabled' => false ),
					'order_received'   => $link( 'enviados', 'Enviado no checkout', 10 ),
				)
			),
			wccs_proof_field(
				'wccs_so_equipe',
				'Rótulo da equipa',
				3,
				array(
					'admin_order'      => $link( 'analise', 'Só para a equipa', 30 ),
					'customer_order'   => array( 'enabled' => false ),
					'customer_email'   => array( 'enabled' => false ),
					'admin_email'      => array( 'enabled' => false ),
					'public_api'       => array( 'enabled' => false ),
					'customer_profile' => array( 'enabled' => false ),
					'order_received'   => array( 'enabled' => false ),
				)
			),
			wccs_proof_field(
				'wccs_so_perfil',
				'Rótulo do perfil',
				4,
				array(
					'admin_order'      => array( 'enabled' => false ),
					'customer_order'   => array( 'enabled' => false ),
					'customer_email'   => array( 'enabled' => false ),
					'admin_email'      => array( 'enabled' => false ),
					'public_api'       => array( 'enabled' => false ),
					'customer_profile' => $link( 'perfil', 'Preferência do perfil', 10 ),
					'order_received'   => array( 'enabled' => false ),
				)
			),
			wccs_proof_field(
				'wccs_sem_vinculo',
				'Rótulo sem vínculo',
				5,
				array(
					'admin_order'      => array( 'enabled' => false ),
					'customer_order'   => array( 'enabled' => false ),
					'customer_email'   => array( 'enabled' => false ),
					'admin_email'      => array( 'enabled' => false ),
					'public_api'       => array( 'enabled' => false ),
					'customer_profile' => array( 'enabled' => false ),
					'order_received'   => array( 'enabled' => false ),
				)
			),
		),
		'sections' => array(
			wccs_proof_section( 'enviados', 'Documentos enviados', 20, array( 'customer_order', 'order_received' ) ),
			wccs_proof_section( 'analise', 'Documentos para análise', 10, array( 'admin_order' ) ),
			wccs_proof_section( 'email', 'Documentos do e-mail', 30, array( 'customer_email', 'admin_email' ) ),
			wccs_proof_section( 'perfil', 'Documentos do perfil', 40, array( 'customer_profile' ) ),
		),
	);
}

/**
 * One order carrying every value.
 *
 * @param array<int, array<string, mixed>> $definitions Published definitions.
 * @return WC_Order
 */
function wccs_proof_order( array $definitions ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->save();

	$values = array();

	foreach ( $definitions as $raw ) {
		$values[ (string) $raw['id'] ] = 'Valor de ' . $raw['id'];
	}

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $order, $values, $definitions, 1 );
	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * What one renderer prints.
 *
 * @param callable $render Renderer.
 * @return string Output.
 */
function wccs_proof_render( callable $render ): string {
	ob_start();
	$render();

	return (string) ob_get_clean();
}

/**
 * The order screen's panel, as the store draws it.
 *
 * @param WC_Order $order Order.
 * @return string Output.
 */
function wccs_proof_admin_panel( WC_Order $order ): string {
	return wccs_proof_render( static fn() => \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render( $order ) );
}

/**
 * The customer's page, as the theme draws it.
 *
 * @param WC_Order $order Order.
 * @return string Output.
 */
function wccs_proof_customer_panel( WC_Order $order ): string {
	return wccs_proof_render( static fn() => \WCCheckoutSuite\Checkout\CustomerOrderFields::render( $order ) );
}

/**
 * One e-mail part.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the message goes to the store.
 * @param bool     $plain_text    Whether the text part is being rendered.
 * @return string Output.
 */
function wccs_proof_email( WC_Order $order, bool $sent_to_admin, bool $plain_text ): string {
	return wccs_proof_render(
		static fn() => \WCCheckoutSuite\Checkout\OrderEmailFields::render( $order, $sent_to_admin, $plain_text, null )
	);
}

/**
 * Whether the rendered output carries these strings in this order.
 *
 * @param string             $html    Output.
 * @param array<int, string> $needles Strings, expected in this order.
 * @return bool
 */
function wccs_proof_in_order( string $html, array $needles ): bool {
	$cursor = 0;

	foreach ( $needles as $needle ) {
		$at = strpos( $html, $needle, $cursor );

		if ( false === $at ) {
			return false;
		}

		$cursor = $at + strlen( $needle );
	}

	return true;
}

/**
 * Runs a renderer with the request pretending to be the order-received page.
 *
 * The page is what decides the area, so the audit has to be able to stand on it. The
 * three globals are exactly what WooCommerce's own `is_order_received_page()` reads —
 * the checkout page as the queried page and the order in the query — and they are put
 * back the way they were afterwards, because pretending is only honest if it leaves.
 *
 * @param WC_Order $order  Order the page would be about.
 * @param callable $render Renderer.
 * @return string Output.
 */
function wccs_proof_on_thankyou( WC_Order $order, callable $render ): string {
	global $wp, $wp_query;

	$saved_id   = $wp_query->queried_object_id ?? null;
	$saved_post = $wp_query->queried_object ?? null;
	$saved_page = $wp_query->is_page ?? null;
	$page_id    = wc_get_page_id( 'checkout' );

	$wp->query_vars['order-received'] = $order->get_id();
	$wp_query->queried_object_id      = $page_id;
	$wp_query->queried_object         = get_post( $page_id );
	$wp_query->is_page                = true;

	$output = wccs_proof_render( $render );

	unset( $wp->query_vars['order-received'] );

	$wp_query->queried_object_id = $saved_id;
	$wp_query->queried_object    = $saved_post;
	$wp_query->is_page           = $saved_page;

	return $output;
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-076 proof — every area, and only what was linked to it' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_options_before = wccs_proof_option_count();

$wccs_document    = wccs_proof_document();
$wccs_published   = wccs_proof_publish( $wccs_document['fields'], $wccs_document['sections'] );
$wccs_definitions = $wccs_document['fields'];

wccs_proof_check(
	'The document this audit reads was published',
	$wccs_published->is_ok(),
	'status=' . $wccs_published->status()
);

$wccs_order = wccs_proof_order( $wccs_definitions );

$wccs_unlinked = 'Rótulo sem vínculo';
$wccs_profile  = 'Rótulo do perfil';

// ---------------------------------------------------------------------------
// 1. The order screen.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. admin_order — the order screen' );

$wccs_admin = wccs_proof_admin_panel( $wccs_order );

wccs_proof_check(
	'The section the links name titles the table',
	str_contains( $wccs_admin, 'Documentos para análise' ),
	'section title present'
);

wccs_proof_check(
	'The links\' titles are the rows',
	str_contains( $wccs_admin, 'Primeiro na análise' )
		&& str_contains( $wccs_admin, 'Segundo na análise' )
		&& str_contains( $wccs_admin, 'Só para a equipa' ),
	'rows=Primeiro, Segundo, Só para a equipa'
);

wccs_proof_check(
	'And the configured order decides, not the document\'s',
	wccs_proof_in_order( $wccs_admin, array( 'Primeiro na análise', 'Segundo na análise', 'Só para a equipa' ) ),
	'the document declares them the other way round'
);

wccs_proof_check(
	'A field with no link is not on the order screen',
	! str_contains( $wccs_admin, $wccs_unlinked ) && ! str_contains( $wccs_admin, $wccs_profile ),
	'absent: ' . $wccs_unlinked
);

// ---------------------------------------------------------------------------
// 2. The customer's two pages.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. customer_order and order_received — the pages the customer sees' );

$wccs_account = wccs_proof_customer_panel( $wccs_order );

wccs_proof_check(
	'The account page shows what its own destination links',
	str_contains( $wccs_account, 'Documentos enviados' ) && str_contains( $wccs_account, 'Documento enviado' ),
	'customer_order'
);

wccs_proof_check(
	'And nothing that belongs to another area',
	! str_contains( $wccs_account, 'Primeiro na análise' )
		&& ! str_contains( $wccs_account, 'Segundo na análise' )
		&& ! str_contains( $wccs_account, 'Só para a equipa' )
		&& ! str_contains( $wccs_account, 'Enviado no checkout' )
		&& ! str_contains( $wccs_account, 'Anexo do e-mail' ),
	'the staff area and the thank-you page stay out'
);

$wccs_area_before = \WCCheckoutSuite\Checkout\CustomerOrderFields::area();
$wccs_area_after  = '';
$wccs_thankyou    = wccs_proof_on_thankyou(
	$wccs_order,
	static function () use ( $wccs_order, &$wccs_area_after ): void {
		$wccs_area_after = \WCCheckoutSuite\Checkout\CustomerOrderFields::area();
		\WCCheckoutSuite\Checkout\CustomerOrderFields::render( $wccs_order );
	}
);

wccs_proof_check(
	'The page decides the area, not the document',
	'customer_order' === $wccs_area_before && 'order_received' === $wccs_area_after,
	'before=' . $wccs_area_before . ' on the thank-you page=' . $wccs_area_after
);

wccs_proof_check(
	'The thank-you page shows its own destination and not the account one',
	str_contains( $wccs_thankyou, 'Enviado no checkout' ) && ! str_contains( $wccs_thankyou, 'Documento enviado' ),
	'order_received'
);

wccs_proof_check(
	'And it obeys the section it was configured with, without inventing a panel',
	str_contains( $wccs_thankyou, 'Documentos enviados' ) && ! str_contains( $wccs_thankyou, $wccs_unlinked ),
	'section from the link'
);

// ---------------------------------------------------------------------------
// 3. The two e-mails.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. customer_email and admin_email — the two e-mails' );

$wccs_email_customer = wccs_proof_email( $wccs_order, false, false );
$wccs_email_store    = wccs_proof_email( $wccs_order, true, true );

wccs_proof_check(
	'The customer e-mail shows the field linked to it, under the configured title',
	str_contains( $wccs_email_customer, 'Anexo do e-mail' ) && str_contains( $wccs_email_customer, 'Documentos do e-mail' ),
	'customer_email'
);

wccs_proof_check(
	'The store e-mail shows its own, in the text part as well',
	str_contains( $wccs_email_store, 'Cópia da loja' ) && str_contains( $wccs_email_store, 'Documentos do e-mail' ),
	'admin_email, text part'
);

wccs_proof_check(
	'And neither of them shows the staff area or the unlinked field',
	! str_contains( $wccs_email_customer, 'Primeiro na análise' )
		&& ! str_contains( $wccs_email_customer, $wccs_unlinked )
		&& ! str_contains( $wccs_email_store, 'Primeiro na análise' )
		&& ! str_contains( $wccs_email_store, $wccs_unlinked ),
	'the areas do not lend each other entries'
);

// ---------------------------------------------------------------------------
// 4. Areas that show nothing.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. The areas that have nothing to show' );

$wccs_api_field = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_definitions[0] );
$wccs_api_gate  = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_definitions[1] );

wccs_proof_check(
	'The integration projection is gated by its own destination',
	$wccs_api_field->shows_in( 'public_api' ) && ! $wccs_api_gate->shows_in( 'public_api' ),
	'one field declares it, the other does not'
);

$wccs_profile_field = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $wccs_definitions[3] );

wccs_proof_check(
	'The profile link is stored and read by the model',
	$wccs_profile_field->shows_in( 'customer_profile' )
		&& false === $wccs_profile_field->shows_in( 'customer_order' )
		&& false === $wccs_profile_field->shows_in( 'public_api' ),
	'a destination nobody else lends to'
);

wccs_proof_check(
	'And it reaches no surface, which is the safe direction for an area with no data',
	! str_contains( $wccs_account, $wccs_profile )
		&& ! str_contains( $wccs_thankyou, $wccs_profile )
		&& ! str_contains( $wccs_admin, $wccs_profile )
		&& ! str_contains( $wccs_email_customer, $wccs_profile )
		&& ! str_contains( $wccs_email_store, $wccs_profile ),
	'nowhere, rather than somewhere it was not configured for'
);

wccs_proof_note(
	'customer_profile has no surface and no store yet',
	'The customer scope is declared in the vocabulary and nothing writes it: there is no profile value to show, so this destination is inert. A field linked there appears nowhere — the audit confirms the absence — and the gap is named in docs/validation/WCCS-076.md instead of being papered over with an empty panel.'
);

// A store that linked nothing anywhere: no area may draw a panel of its own.
$wccs_silent = array(
	wccs_proof_field(
		'wccs_sem_vinculo',
		$wccs_unlinked,
		5,
		array(
			'admin_order'      => array( 'enabled' => false ),
			'customer_order'   => array( 'enabled' => false ),
			'customer_email'   => array( 'enabled' => false ),
			'admin_email'      => array( 'enabled' => false ),
			'public_api'       => array( 'enabled' => false ),
			'customer_profile' => array( 'enabled' => false ),
			'order_received'   => array( 'enabled' => false ),
		)
	),
);

wccs_proof_publish( $wccs_silent, array() );

$wccs_silent_definitions = $wccs_silent;
$wccs_silent_order       = wccs_proof_order( $wccs_silent_definitions );

wccs_proof_check(
	'An area with no links draws no panel at all',
	'' === trim( wccs_proof_customer_panel( $wccs_silent_order ) )
		&& '' === trim( wccs_proof_email( $wccs_silent_order, false, false ) )
		&& '' === trim( wccs_proof_email( $wccs_silent_order, true, false ) ),
	'the customer page and both e-mails stay empty'
);

$wccs_silent_panel = wccs_proof_admin_panel( $wccs_silent_order );

wccs_proof_check(
	'And the order screen says there is nothing, rather than showing the field',
	str_contains( $wccs_silent_panel, 'No checkout field is shown on the order screen.' )
		&& ! str_contains( $wccs_silent_panel, $wccs_unlinked ),
	'the empty state, not a panel'
);

// ---------------------------------------------------------------------------
// Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What the harness left behind' );

foreach ( array( $wccs_order, $wccs_silent_order ) as $wccs_created ) {
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
