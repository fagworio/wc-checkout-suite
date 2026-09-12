<?php
/**
 * WCCS-053 proof harness — the order e-mails.
 *
 * Task:   WCCS-053 "Criar e-mails HTML/texto"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Públicos distintos; documentos sem anexos por padrão; links autorizados."
 *
 * Three clauses, and the third is asserted in both of its states — a link that does not
 * exist until a store asks for one, and a link that, when it exists, is the plugin's own
 * authorization route and not an address into the private storage.
 *
 * The hook is grounded in the installed WooCommerce: the templates that fire it and the
 * two arguments that carry the audience and the format are read rather than assumed.
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
 * Reads a file of the installed WooCommerce, for the platform fact it holds.
 *
 * @param string $relative Path inside the plugins directory.
 * @return string
 */
function wccs_proof_platform_file( string $relative ): string {
	$path = dirname( WCCS_PLUGIN_DIR, 1 ) . '/' . $relative;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the installed WooCommerce, read to ground a hook in the platform's own templates; the sniff targets remote URLs.
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * The callbacks this plugin has on a hook, whoever else is on it.
 *
 * @param string $tag Hook name.
 * @return array<int, string> Callbacks of this plugin.
 */
function wccs_proof_plugin_callbacks( string $tag ): array {
	if ( ! isset( $GLOBALS['wp_filter'][ $tag ]->callbacks ) ) {
		return array();
	}

	$found = array();

	foreach ( (array) $GLOBALS['wp_filter'][ $tag ]->callbacks as $callbacks ) {
		foreach ( (array) $callbacks as $callback ) {
			$function = $callback['function'] ?? null;

			if ( ! is_array( $function ) || ! isset( $function[0] ) ) {
				continue;
			}

			$class = is_object( $function[0] )
				? get_class( $function[0] )
				: ( is_string( $function[0] ) ? $function[0] : '' );

			if ( 0 === strpos( $class, 'WCCheckoutSuite\\' ) ) {
				$found[] = $tag;
			}
		}
	}

	return $found;
}

/**
 * A field definition.
 *
 * @param string               $id      Identifier.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $changes = array() ): array {
	return array_merge(
		array(
			'id'             => 'wccs_' . $id,
			'integration_id' => 'wc-checkoutsuite/wccs_' . $id,
			'origin'         => 'custom',
			'type'           => 'text',
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
			'visibility'     => array( 'customer_email' => true ),
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
				'revision'       => 53,
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
 * An order carrying Suite values.
 *
 * @param array<string, mixed>             $values      Values, keyed by identifier.
 * @param array<int, array<string, mixed>> $definitions Definitions to snapshot from.
 * @return WC_Order
 */
function wccs_proof_order( array $values, array $definitions ): WC_Order {
	$order = wc_create_order();
	$order->set_status( 'completed' );
	$order->save();

	( new \WCCheckoutSuite\Domain\Orders\OrderFieldsService() )->write( $order, $values, $definitions, 53 );
	$order->save();

	return wc_get_order( $order->get_id() );
}

/**
 * What one e-mail part would contain.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the message goes to the store.
 * @param bool     $plain_text    Whether the text part is being rendered.
 * @return string
 */
function wccs_proof_part( WC_Order $order, bool $sent_to_admin, bool $plain_text ): string {
	ob_start();
	\WCCheckoutSuite\Checkout\OrderEmailFields::render( $order, $sent_to_admin, $plain_text, null );

	return (string) ob_get_clean();
}

$wccs_email = 'WCCheckoutSuite\\Checkout\\OrderEmailFields';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-053 proof — the order e-mails' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. The hook is the platform's, and it carries both answers.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the platform hands over' );

$wccs_template = wccs_proof_platform_file( 'woocommerce/templates/emails/customer-processing-order.php' );
$wccs_admin    = wccs_proof_platform_file( 'woocommerce/templates/emails/admin-new-order.php' );
$wccs_core     = wccs_proof_platform_file( 'woocommerce/includes/class-wc-emails.php' );

wccs_proof_check(
	'The customer and the store templates both fire the hook the plugin listens on',
	str_contains( $wccs_template, $wccs_email::HOOK )
		&& str_contains( $wccs_admin, $wccs_email::HOOK ),
	'one hook, and the audience is an argument rather than a second hook'
);

wccs_proof_check(
	'And the arguments are the audience and the format part',
	str_contains( $wccs_template, '$sent_to_admin, $plain_text' ),
	'the platform tells the projection which of the four it is'
);

wccs_proof_check(
	'And WooCommerce itself uses that hook, so this is the documented seam',
	str_contains( $wccs_core, "add_action( 'woocommerce_email_order_meta', array( \$this, 'order_meta' ), 10, 3 )" ),
	'not a hook this plugin invented'
);

wccs_proof_check(
	'The plugin adds nothing to the attachment filter',
	false === has_filter( 'woocommerce_email_attachments', array( $wccs_email, 'render' ) )
		&& 0 === count(
			array_filter(
				array_keys( $GLOBALS['wp_filter'] ),
				static function ( string $tag ): bool {
					return str_contains( $tag, 'email_attachments' ) && array() !== wccs_proof_plugin_callbacks( $tag );
				}
			)
		),
	'documents are not attached by default, and nothing here attaches them'
);

// ---------------------------------------------------------------------------
// 2. Two audiences.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Públicos distintos' );

$wccs_both     = wccs_proof_field( 'note', array( 'visibility' => array( 'customer_email' => true, 'admin_email' => true ) ) );
$wccs_customer = wccs_proof_field( 'greeting', array( 'visibility' => array( 'customer_email' => true ) ) );
$wccs_store    = wccs_proof_field( 'internal', array( 'visibility' => array( 'admin_email' => true ) ) );
$wccs_page     = wccs_proof_field( 'onpage', array( 'visibility' => array( 'customer_order' => true ) ) );

wccs_proof_publish( array( $wccs_both, $wccs_customer, $wccs_store, $wccs_page ) );

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

wccs_proof_check(
	'The two audiences read two different keys',
	'customer_email' === $wccs_email::audience_key( false )
		&& 'admin_email' === $wccs_email::audience_key( true )
);

wccs_proof_check(
	'And the sets they are shown are not the same set',
	array( 'wccs_note', 'wccs_greeting' ) === array_keys( $wccs_email::visible( $wccs_definitions, false ) )
		&& array( 'wccs_note', 'wccs_internal' ) === array_keys( $wccs_email::visible( $wccs_definitions, true ) ),
	'customer=' . wp_json_encode( array_keys( $wccs_email::visible( $wccs_definitions, false ) ) )
		. ' store=' . wp_json_encode( array_keys( $wccs_email::visible( $wccs_definitions, true ) ) )
);

wccs_proof_check(
	'A field shown only on the customer page is in neither e-mail',
	! isset( $wccs_email::visible( $wccs_definitions, false )['wccs_onpage'] )
		&& ! isset( $wccs_email::visible( $wccs_definitions, true )['wccs_onpage'] ),
	'a surface is not a policy: the page key does not open the e-mail'
);

$wccs_order = wccs_proof_order(
	array(
		'wccs_note'     => 'For both audiences.',
		'wccs_greeting' => 'Only the customer reads this.',
		'wccs_internal' => 'Only the store reads this.',
		'wccs_onpage'   => 'Only on the order page.',
	),
	$wccs_definitions
);

$wccs_to_customer = wccs_proof_part( $wccs_order, false, false );
$wccs_to_store    = wccs_proof_part( $wccs_order, true, false );

wccs_proof_check(
	'The customer message carries what is meant for the customer and nothing else',
	str_contains( $wccs_to_customer, 'For both audiences.' )
		&& str_contains( $wccs_to_customer, 'Only the customer reads this.' )
		&& ! str_contains( $wccs_to_customer, 'Only the store reads this.' )
		&& ! str_contains( $wccs_to_customer, 'Only on the order page.' )
);

wccs_proof_check(
	'The store message carries what is meant for the store and nothing else',
	str_contains( $wccs_to_store, 'Only the store reads this.' )
		&& ! str_contains( $wccs_to_store, 'Only the customer reads this.' )
);

// ---------------------------------------------------------------------------
// 3. Two formats.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. HTML e texto puro' );

wccs_proof_publish(
	array( wccs_proof_field( 'note', array( 'visibility' => array( 'customer_email' => true ) ) ) )
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_rich_order = wccs_proof_order(
	array( 'wccs_note' => 'Salt & pepper <b>bold</b>' ),
	$wccs_definitions
);

$wccs_rich = wccs_proof_part( $wccs_rich_order, false, false );
$wccs_text = wccs_proof_part( $wccs_rich_order, false, true );

wccs_proof_check(
	'The HTML part escapes the value',
	str_contains( $wccs_rich, 'Salt &amp; pepper' )
		&& ! str_contains( $wccs_rich, '<b>bold</b>' ),
	'a stored value does not become markup in an e-mail either'
);

wccs_proof_check(
	'The text part carries no markup of the plugin\'s and no entities',
	! str_contains( $wccs_text, '<h2' )
		&& ! str_contains( $wccs_text, '<p ' )
		&& ! str_contains( $wccs_text, '<strong' )
		&& ! str_contains( $wccs_text, '&amp;' )
		&& str_contains( $wccs_text, 'Salt & pepper <b>bold</b>' ),
	"the value's own angle brackets are the customer's text; what must be absent is markup this plugin emitted, and an escaped ampersand is the bug a stripped HTML part would have"
);

wccs_proof_check(
	'And the two parts carry the same labels and values',
	str_contains( $wccs_rich, 'Label note' ) && str_contains( $wccs_text, 'Label note: ' )
);

// ---------------------------------------------------------------------------
// 4. Documents: no attachment, and a link only if configured.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Documentos e links' );

$wccs_token = str_repeat( 'a', 64 );

wccs_proof_publish(
	array(
		wccs_proof_field(
			'attachment',
			array(
				'type'       => 'file',
				'visibility' => array( 'customer_email' => true ),
			)
		),
	)
);

$wccs_definitions = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields();

$wccs_document_order = wccs_proof_order( array( 'wccs_attachment' => $wccs_token ), $wccs_definitions );

$wccs_without = wccs_proof_part( $wccs_document_order, false, false );
$wccs_without_text = wccs_proof_part( $wccs_document_order, false, true );

wccs_proof_check(
	'The store has not configured links, so there is none',
	! $wccs_email::links_enabled()
);

wccs_proof_check(
	'And the document is named without its address, in both parts',
	! str_contains( $wccs_without, $wccs_token )
		&& ! str_contains( $wccs_without, 'href' )
		&& ! str_contains( $wccs_without_text, $wccs_token )
		&& str_contains( $wccs_without, 'A document was provided with this order.' ),
	'the token is an address into the private storage, and it is not printed'
);

wccs_proof_check(
	'Nothing about the value reaches the e-mail as an attachment',
	! str_contains( $wccs_without, 'attachment' )
		|| false === strpos( $wccs_without, 'wp-content' ),
	'no path into the private directory'
);

// With links configured, the link is the plugin's own authorization route.
$wccs_enable = static function (): bool {
	return true;
};

add_filter( $wccs_email::LINKS_FILTER, $wccs_enable );

$wccs_with = wccs_proof_part( $wccs_document_order, false, false );
$wccs_with_text = wccs_proof_part( $wccs_document_order, false, true );

remove_filter( $wccs_email::LINKS_FILTER, $wccs_enable );

$wccs_route = rest_url(
	\WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace() . '/uploads/' . $wccs_token . '/download'
);

wccs_proof_check(
	'Configured, the link points at the plugin\'s own download route',
	str_contains( $wccs_with, $wccs_route ),
	'the route that decides per request whether this person may read this file: ' . $wccs_route
);

wccs_proof_check(
	'And never at a path into the private directory',
	! str_contains( $wccs_with, 'wp-content' )
		&& ! str_contains( $wccs_with, 'file://' ),
	'the same address by any other name is a copy outside every control over the file'
);

wccs_proof_check(
	'And the text part carries the same authorized address, as text',
	str_contains( $wccs_with_text, '/uploads/' . $wccs_token . '/download' )
		&& ! str_contains( $wccs_with_text, '<a ' )
);

$wccs_not_token = wccs_proof_order( array( 'wccs_attachment' => 'not-a-token' ), $wccs_definitions );

add_filter( $wccs_email::LINKS_FILTER, $wccs_enable );

$wccs_not_token_html = wccs_proof_part( $wccs_not_token, false, false );

remove_filter( $wccs_email::LINKS_FILTER, $wccs_enable );

wccs_proof_check(
	'And a value that is not a store token produces no address and no value',
	! str_contains( $wccs_not_token_html, 'not-a-token' )
		&& ! str_contains( $wccs_not_token_html, 'href' ),
	'a value that is not a token is not a document this store holds'
);

wccs_proof_note(
	'Why the two parts are built and not derived',
	'An HTML part escapes and uses structure; a text part is written as text. Building the text by stripping tags from the HTML is how an e-mail arrives with `&amp;` where the customer wrote an ampersand — which is the assertion this harness makes, with a value that contains both an ampersand and a tag.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'No e-mail was sent: the projections are asserted by rendering the hook callback for each of the four combinations, not by delivering a message. What a mail client displays, and how a theme overriding the e-mail templates places the hook, are WCCS-063. Sending a real message is the end-to-end run in F12.'
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );

wccs_proof_check(
	'The harness left no stored option of its own behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before
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
