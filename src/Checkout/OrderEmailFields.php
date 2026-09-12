<?php
/**
 * The checkout fields in the order e-mails.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\OrderFieldEntry;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Http\Checkout\DownloadController;
use WC_Order;

/**
 * Projects an order's values into an e-mail, for one audience and in one format.
 *
 * ROADMAP.md section 14 and section 12 between them state four rules, and each one is a
 * decision this class makes once:
 *
 * > E-mail: configuração independente para lojista/cliente e HTML/texto puro. Arquivos e
 * > documentos são privados inicialmente.
 * > E-mails: mostrar informação ou link de acesso controlado apenas se configurado. Não
 * > anexar documentos pessoais por padrão.
 *
 * 1. **Two audiences, decided independently.** The projection for the customer reads
 *    `visibility.customer_email` and the one for the store reads `visibility.admin_email`.
 *    A field can be in both, in one, or in neither, and `$sent_to_admin` is the only thing
 *    that chooses — which means the same order can legitimately tell the customer one
 *    thing and the store another.
 * 2. **Two formats, and the plain one is not the rich one stripped of tags.** An HTML
 *    part escapes and uses structure; a text part is written as text, with no markup in
 *    it at all. Building the text by stripping tags from the HTML is how an e-mail ends up
 *    with `&amp;` in it, and it is why the two are built here rather than derived.
 * 3. **No attachment, ever, by default.** This class adds nothing to
 *    `woocommerce_email_attachments`, and a document is not attached because it is
 *    visible: a personal file in an e-mail is a copy of it outside every control the
 *    store has over the file.
 * 4. **A link only if configured, and only an authorized one.** No link is produced until
 *    a store asks for one through the filter this class publishes, and the link it then
 *    produces is the plugin's own download route — the one that decides per request
 *    whether this person may read this file — and never a path, a public URL or a direct
 *    address into the private storage.
 *
 * The values themselves come from the order's own payload, exactly as the customer's page
 * does, so an e-mail sent today about an order placed last year says what that order says.
 */
final class OrderEmailFields {

	/**
	 * The hook every order e-mail template fires.
	 */
	public const HOOK = 'woocommerce_email_order_meta';

	/**
	 * The filter a store turns controlled links on with.
	 */
	public const LINKS_FILTER = 'wccs_email_field_links';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant is WooCommerce's own hook name; this plugin does not own it and must not rename it.
		add_action( self::HOOK, array( self::class, 'render' ), 10, 4 );
	}

	/**
	 * The visibility key an audience reads.
	 *
	 * @param bool $sent_to_admin Whether the e-mail is going to the store.
	 * @return string
	 */
	public static function audience_key( bool $sent_to_admin ): string {
		return $sent_to_admin ? 'admin_email' : 'customer_email';
	}

	/**
	 * Whether controlled links are configured for this store.
	 *
	 * Off unless something asks for it, which is what "apenas se configurado" means read
	 * as a default rather than as a menu: a link to a document is a decision a store makes
	 * about its own files, and the safe answer to a decision nobody has made is no.
	 *
	 * @return bool
	 */
	public static function links_enabled(): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed name, kept in one place.
		return (bool) apply_filters( self::LINKS_FILTER, false );
	}

	/**
	 * The fields one audience may be shown.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @param bool                             $sent_to_admin Whether the e-mail goes to the store.
	 * @return array<string, array<string, mixed>> Allowed definitions.
	 */
	public static function visible( array $definitions, bool $sent_to_admin ): array {
		$key     = self::audience_key( $sent_to_admin );
		$visible = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || ! $definition->is_enabled() ) {
				continue;
			}

			$stored = $definition->to_array();

			if ( ! $definition->shows_in( $key ) ) {
				continue;
			}

			$visible[ $id ] = $stored;
		}

		return $visible;
	}

	/**
	 * The entries one audience is shown for this order.
	 *
	 * @param WC_Order                         $order          Order.
	 * @param array<int, array<string, mixed>> $definitions    Published definitions.
	 * @param bool                             $sent_to_admin  Whether the e-mail goes to the store.
	 * @return array<int, array{entry: OrderFieldEntry, field: array<string, mixed>}> Each entry with the definition that allowed it, because a document is rendered by its definition and not by its value.
	 */
	public static function entries( WC_Order $order, array $definitions, bool $sent_to_admin ): array {
		$visible = self::visible( $definitions, $sent_to_admin );
		$shown   = array();

		foreach ( ( new OrderFieldsService() )->history( $order, $definitions ) as $entry ) {
			if ( ! isset( $visible[ $entry->id() ] ) ) {
				continue;
			}

			$shown[] = array(
				'entry' => $entry,
				'field' => $visible[ $entry->id() ],
			);
		}

		return $shown;
	}

	/**
	 * Whether a field's value is a private document.
	 *
	 * @param array<string, mixed> $field Stored definition.
	 * @return bool
	 */
	public static function is_document( array $field ): bool {
		return 'file' === (string) ( $field['type'] ?? '' );
	}

	/**
	 * Draws the projection.
	 *
	 * @param mixed $order         Order the e-mail is about.
	 * @param bool  $sent_to_admin Whether the e-mail goes to the store.
	 * @param bool  $plain_text    Whether the e-mail is the text part.
	 * @param mixed $email         The e-mail object, unused.
	 * @return void
	 */
	public static function render( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$entries = self::entries( $order, PublishedDocument::read()->fields(), (bool) $sent_to_admin );

		if ( array() === $entries ) {
			return;
		}

		$heading = $sent_to_admin
			? __( 'Checkout information (store copy)', 'wc-checkoutsuite' )
			: __( 'Checkout information', 'wc-checkoutsuite' );

		if ( $plain_text ) {
			// No escaping here, and that is the point rather than an oversight: this part
			// is text, so an escaped value would print `&amp;` where the customer's
			// document has an ampersand. The plain part is written as text, not derived
			// from the rich one.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is the text/plain part of the e-mail. Escaping here is the defect the format split exists to avoid: the customer would read `&amp;` where they wrote an ampersand.
			echo "\n" . $heading . "\n\n";

			foreach ( $entries as $shown ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same reason: text part, and `plain()` returns text rather than markup.
				echo $shown['entry']->label() . ': ' . self::plain( $shown ) . "\n";
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A newline.
			echo "\n";

			return;
		}

		printf(
			'<h2 style="color: #202334; display: block; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%%; margin: 0 0 18px; text-align: left;">%s</h2>',
			esc_html( $heading )
		);

		echo '<div style="margin-bottom: 24px;">';

		foreach ( $entries as $shown ) {
			printf(
				'<p style="margin: 0 0 8px;"><strong>%s</strong><br />%s</p>',
				esc_html( $shown['entry']->label() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `rich()` returns escaped text, an escaped link, or escaped wording; the sniff cannot follow the call.
				self::rich( $shown )
			);
		}

		echo '</div>';
	}

	/**
	 * A value as it appears on the HTML part, escaped.
	 *
	 * @param array{entry: OrderFieldEntry, field: array<string, mixed>} $shown Entry and its definition.
	 * @return string
	 */
	private static function rich( array $shown ): string {
		if ( self::is_document( $shown['field'] ) ) {
			// A document is not attached and its address is not printed unless a store
			// configured controlled links, and then the address is the plugin's own
			// download route — the one that decides per request whether this person may
			// read this file. A path, a public URL or an address into the private
			// storage would be a copy of the document outside every control over it.
			return self::document( $shown );
		}

		return esc_html( self::display( $shown['entry']->value() ) );
	}

	/**
	 * A value as it appears on the text part, with no markup at all.
	 *
	 * @param array{entry: OrderFieldEntry, field: array<string, mixed>} $shown Entry and its definition.
	 * @return string
	 */
	private static function plain( array $shown ): string {
		if ( self::is_document( $shown['field'] ) ) {
			$url = self::document_url( $shown );

			return '' === $url
				? __( 'A document was provided with this order.', 'wc-checkoutsuite' )
				: $url;
		}

		return self::display( $shown['entry']->value() );
	}

	/**
	 * What the HTML part says for a document.
	 *
	 * @param array{entry: OrderFieldEntry, field: array<string, mixed>} $shown Entry and its definition.
	 * @return string
	 */
	private static function document( array $shown ): string {
		$url = self::document_url( $shown );

		// No address means no link. An anchor with an empty href is worse than no anchor:
		// it looks like a way to the document, it is focusable, and it goes nowhere —
		// which is exactly what a value that is not a token this store holds would
		// produce if the link were emitted before the address was checked.
		if ( '' === $url ) {
			return esc_html__( 'A document was provided with this order.', 'wc-checkoutsuite' );
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Open your document', 'wc-checkoutsuite' )
		);
	}

	/**
	 * The authorized address of a document, when links are configured.
	 *
	 * @param array{entry: OrderFieldEntry, field: array<string, mixed>} $shown Entry and its definition.
	 * @return string
	 */
	private static function document_url( array $shown ): string {
		if ( ! self::links_enabled() ) {
			// A store that has not configured controlled links gets no address at all,
			// which is what "apenas se configurado" means as a default.
			return '';
		}

		$token = $shown['entry']->value();

		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			// A value that is not a token is not a document this store holds, and there
			// is no address to give for it.
			return '';
		}

		return rest_url(
			SchemaController::rest_namespace()
				. str_replace( '(?P<token>[a-f0-9]{64})', $token, DownloadController::ROUTE_DOWNLOAD )
		);
	}

	/**
	 * A value as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display( $value ): string {
		if ( is_array( $value ) ) {
			$labels = array();

			foreach ( $value as $one ) {
				if ( is_scalar( $one ) ) {
					$labels[] = (string) $one;
				}
			}

			return implode( ', ', $labels );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wc-checkoutsuite' ) : __( 'No', 'wc-checkoutsuite' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
