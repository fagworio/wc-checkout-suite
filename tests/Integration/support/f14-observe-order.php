<?php
/**
 * Reads one real order's surfaces as a person would see them, and says what each shows.
 *
 * The user-level observations run in a browser for the pages and here for the two halves
 * a browser cannot show: the store's order screen (staff) and the two e-mail projections.
 * Both are rendered from the real order through the real classes, and each area is then
 * asked the only question that matters for the phase — did it show its own links, and
 * nothing else?
 *
 * Usage:
 *   wp eval-file tests/Integration/support/f14-observe-order.php <order-id>
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wccs_order_id = isset( $args[0] ) ? (int) $args[0] : 0;
$wccs_order    = $wccs_order_id > 0 ? wc_get_order( $wccs_order_id ) : null;

if ( ! $wccs_order instanceof WC_Order ) {
	echo 'no order ' . $wccs_order_id . PHP_EOL;
	exit( 1 );
}

/**
 * Renders one callback and returns its output as collapsed text.
 *
 * @param callable $render Renderer.
 * @return string Text.
 */
function wccs_observe_text( callable $render ): string {
	ob_start();
	$render();

	return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
}

/**
 * One check, printed the way the harnesses print theirs.
 *
 * @param string $label     What is being checked.
 * @param bool   $ok        Result.
 * @param string $detail    What was seen.
 * @return bool
 */
function wccs_observe_check( string $label, bool $ok, string $detail = '' ): bool {
	echo sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;

	return $ok;
}

$wccs_panel      = wccs_observe_text( static fn() => \WCCheckoutSuite\Admin\Orders\OrderFieldsPanel::render( $wccs_order ) );
$wccs_email_html = wccs_observe_text( static fn() => \WCCheckoutSuite\Checkout\OrderEmailFields::render( $wccs_order, false, false, null ) );
$wccs_email_text = wccs_observe_text( static fn() => \WCCheckoutSuite\Checkout\OrderEmailFields::render( $wccs_order, true, true, null ) );
$wccs_notes      = wc_get_order_notes( array( 'order_id' => $wccs_order_id ) );

$wccs_ok = true;

echo '=====================================================================' . PHP_EOL;
echo 'F14 user-level observation — the surfaces of order #' . $wccs_order_id . PHP_EOL;
echo '=====================================================================' . PHP_EOL;

echo PHP_EOL . 'ORDER SCREEN (admin_order)' . PHP_EOL;
$wccs_ok = wccs_observe_check(
	'The configured section titles the panel, with the configured titles in order',
	str_contains( $wccs_panel, 'Documentos para análise' )
		&& strpos( $wccs_panel, 'Documento fiscal' ) < strpos( $wccs_panel, 'Autorização assinada' )
		&& strpos( $wccs_panel, 'Autorização assinada' ) < strpos( $wccs_panel, 'Observações da entrega' ),
	$wccs_panel
) && $wccs_ok;

$wccs_ok = wccs_observe_check(
	'And shows nothing linked only to another area',
	! str_contains( $wccs_panel, 'Código de retirada' )
		&& ! str_contains( $wccs_panel, 'Campo sem vínculo' )
		&& ! str_contains( $wccs_panel, 'Como prefere ser contactado' ),
	'the thank-you-only field and the unlinked ones stay out'
) && $wccs_ok;

echo PHP_EOL . 'CUSTOMER E-MAIL (customer_email)' . PHP_EOL;
$wccs_ok = wccs_observe_check(
	'It shows the field linked to it, under its configured section and title',
	str_contains( $wccs_email_html, 'Documentos do pedido' )
		&& str_contains( $wccs_email_html, 'Documento do pedido' )
		&& str_contains( $wccs_email_html, '123.456.789-09' ),
	$wccs_email_html
) && $wccs_ok;

$wccs_ok = wccs_observe_check(
	'And nothing that belongs to another area',
	! str_contains( $wccs_email_html, 'Código de retirada' )
		&& ! str_contains( $wccs_email_html, 'Campo sem vínculo' )
		&& ! str_contains( $wccs_email_html, 'Como prefere ser contactado' ),
	'no order_received field, no unlinked field'
) && $wccs_ok;

echo PHP_EOL . 'STORE E-MAIL (admin_email, text part)' . PHP_EOL;
$wccs_ok = wccs_observe_check(
	'It shows the field linked to it, under the title the store configured for it',
	str_contains( $wccs_email_text, 'Documentos do pedido' )
		&& str_contains( $wccs_email_text, 'Cópia para a loja' )
		&& str_contains( $wccs_email_text, '123.456.789-09' ),
	$wccs_email_text
) && $wccs_ok;

$wccs_ok = wccs_observe_check(
	'And nothing that belongs to another area',
	! str_contains( $wccs_email_text, 'Código de retirada' )
		&& ! str_contains( $wccs_email_text, 'Campo sem vínculo' ),
	'no order_received field, no unlinked field'
) && $wccs_ok;

echo PHP_EOL . 'APPROVAL FLOW (status and note)' . PHP_EOL;
$wccs_flow    = \WCCheckoutSuite\Domain\Approval\ApprovalFlow::of(
	\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array(
		\WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->fields()[0]
	)
);
$wccs_holding = array_filter(
	$wccs_notes,
	static fn( $note ) => str_contains( (string) $note->content, 'Waiting for review' )
);

$wccs_ok = wccs_observe_check(
	'The order waits in the state the merchant named',
	$wccs_flow->status() === $wccs_order->get_status(),
	'status=' . $wccs_order->get_status() . ' expected=' . $wccs_flow->status()
) && $wccs_ok;

$wccs_ok = wccs_observe_check(
	'And says why, in one note',
	1 === count( $wccs_holding ),
	'notes holding the order=' . count( $wccs_holding ) . ' of ' . count( $wccs_notes )
) && $wccs_ok;

echo PHP_EOL . '=====================================================================' . PHP_EOL;
echo 'RESULT: ' . ( $wccs_ok ? 'all checks passed' : 'something failed' ) . PHP_EOL;
echo '=====================================================================' . PHP_EOL;

exit( $wccs_ok ? 0 : 1 );
