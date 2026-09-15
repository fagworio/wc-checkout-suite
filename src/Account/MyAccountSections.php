<?php
/**
 * Real WooCommerce My Account endpoints owned by Suite sections.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Account;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Customers\CustomerSectionFields;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;
use WCCheckoutSuite\Domain\Uploads\UploadService;
use WCCheckoutSuite\Domain\Uploads\UploadsEnvironment;

/** Registers and renders Suite-owned WooCommerce My Account pages. */
final class MyAccountSections {

	private const SIGNATURE_OPTION = 'wccs_account_endpoint_signature';

	/**
	 * The destination this surface serves.
	 *
	 * The customer's own page and the panel staff read on the customer's profile are
	 * two destinations over the same stored values; this class is the one the customer
	 * sees and writes themselves.
	 */
	public const DESTINATION = 'customer_account';

	/** Endpoint definitions keyed by their public slug.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $endpoints = array();

	/** Registers the WordPress and WooCommerce hooks. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_endpoints' ), 20 );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu_items' ), 20 );
	}

	/** Registers configured endpoints and refreshes rewrite rules only when needed. */
	public static function register_endpoints(): void {
		self::$endpoints = self::configured_sections();

		foreach ( self::$endpoints as $slug => $section ) {
			add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
			add_action( 'woocommerce_account_' . $slug . '_endpoint', array( self::class, 'render' ) );
		}

		$signature = md5( (string) wp_json_encode( array_keys( self::$endpoints ) ) );
		$stored    = (string) get_option( self::SIGNATURE_OPTION, '' );

		// A store with no account sections has nothing to flush and nothing to remember.
		// Writing the signature anyway leaves an option behind that only ever says "no
		// endpoints", which is a stored fact nobody reads — and the harness that checks
		// for exactly that, on a request that should write nothing, caught it.
		if ( array() === self::$endpoints ) {
			if ( '' !== $stored ) {
				delete_option( self::SIGNATURE_OPTION );
				flush_rewrite_rules();
			}

			return;
		}

		if ( $stored !== $signature ) {
			update_option( self::SIGNATURE_OPTION, $signature, false );
			flush_rewrite_rules();
		}
	}

	/**
	 * Inserts account sections into WooCommerce's menu.
	 *
	 * @param array<string,string> $items Existing endpoint labels.
	 * @return array<string,string> Menu labels keyed by endpoint.
	 */
	public static function menu_items( array $items ): array {
		foreach ( self::$endpoints as $slug => $section ) {
			$account  = isset( $section['presentation']['account'] ) && is_array( $section['presentation']['account'] )
				? $section['presentation']['account']
				: array();
			$label    = isset( $account['menu_label'] ) && '' !== trim( (string) $account['menu_label'] )
				? (string) $account['menu_label']
				: (string) $section['title'];
			$position = isset( $account['position'] ) ? max( 0, (int) $account['position'] ) : count( $items );
			$before   = array_slice( $items, 0, min( $position, count( $items ) ), true );
			$after    = array_slice( $items, min( $position, count( $items ) ), null, true );
			$items    = $before + array( $slug => $label ) + $after;
		}

		return $items;
	}

	/** Renders the configured account endpoint for the current customer. */
	public static function render(): void {
		$slug    = self::current_slug();
		$section = self::$endpoints[ $slug ] ?? null;

		if ( ! is_array( $section ) || ! is_user_logged_in() ) {
			return;
		}

		$fields   = CustomerSectionFields::entries(
			PublishedDocument::read()->fields(),
			self::DESTINATION,
			(string) $section['id']
		);
		$service  = new CustomerFieldsService();
		$values   = $service->values( get_current_user_id() );
		$errors   = array();
		$notice   = '';
		$account  = isset( $section['presentation']['account'] ) && is_array( $section['presentation']['account'] )
			? $section['presentation']['account']
			: array();
		$editable = 'view' !== ( $account['mode'] ?? 'edit' );

		$submitted_section = isset( $_POST['wccs_account_section'] )
			? sanitize_key( (string) wp_unslash( $_POST['wccs_account_section'] ) )
			: '';

		if ( $editable && (string) $section['id'] === $submitted_section ) {
			$nonce = isset( $_POST['_wccs_account_nonce'] )
				? sanitize_text_field( (string) wp_unslash( $_POST['_wccs_account_nonce'] ) )
				: '';

			if ( ! wp_verify_nonce( $nonce, 'wccs_account_section_' . $section['id'] ) ) {
				$errors[] = __( 'Sua sessão do formulário expirou. Tente novamente.', 'wc-checkoutsuite' );
			} else {
				$posted  = isset( $_POST['wccs_account_fields'] ) && is_array( $_POST['wccs_account_fields'] )
					? map_deep( wp_unslash( $_POST['wccs_account_fields'] ), 'sanitize_textarea_field' )
					: array();
				$updates = CustomerSectionFields::submission( $fields, self::DESTINATION, $values, $posted, $errors );

				// The documents first, and separately: a file is not a value the form
				// carries, it is a row the customer's own store keeps. A refusal names the
				// reason and leaves the value they already had alone; a success needs no
				// value written at all, because the newest row *is* the current document.
				$documents = self::accept_documents( $fields, get_current_user_id(), $errors );

				if ( array() === $errors ) {
					$service->update( get_current_user_id(), $updates );
					$values = array_merge( $values, $updates );
					$notice = $documents > 0
						? __( 'Documento enviado e informações salvas.', 'wc-checkoutsuite' )
						: __( 'Informações salvas com sucesso.', 'wc-checkoutsuite' );
				}
			}
		}

		if ( ! isset( $section['presentation']['show_title'] ) || ! empty( $section['presentation']['show_title'] ) ) {
			printf( '<h2>%s</h2>', esc_html( (string) $section['title'] ) );
		}
		if ( '' !== (string) ( $section['description'] ?? '' ) ) {
			printf( '<p>%s</p>', esc_html( (string) $section['description'] ) );
		}
		if ( '' !== $notice ) {
			printf( '<div class="woocommerce-message" role="alert">%s</div>', esc_html( $notice ) );
		}
		foreach ( $errors as $error ) {
			printf( '<div class="woocommerce-error" role="alert">%s</div>', esc_html( $error ) );
		}
		if ( array() === $fields ) {
			echo '<p>' . esc_html__( 'Nenhum campo compatível foi adicionado a esta seção.', 'wc-checkoutsuite' ) . '</p>';
			return;
		}
		// A page whose presentation says `view` is read-only: it shows the values and
		// offers nothing to submit. Wrapping them in a form anyway would give the
		// customer a control that does nothing and a POST target the page ignores.
		$documents = self::documents( $fields, get_current_user_id() );

		if ( $editable ) {
			// A document travels as a file and not as a field, so a section that *has* one
			// needs a form that can carry bytes — whether or not the customer has sent anything
			// yet, because the first upload is exactly the case where nothing is on file.
			printf(
				'<form method="post" enctype="%s" class="woocommerce-form wccs-account-section">',
				self::has_documents( $fields ) ? 'multipart/form-data' : 'application/x-www-form-urlencoded'
			);
		} else {
			echo '<div class="wccs-account-section">';
		}

		foreach ( $fields as $entry ) {
			self::field(
				$entry['field'],
				$entry['title'],
				$values[ $entry['field']->id() ] ?? null,
				$editable,
				CustomerSectionFields::is_document( $entry['field'] )
					? ( $documents[ $entry['field']->id() ] ?? null )
					: null,
				$entry['binding']
			);
		}

		if ( $editable ) {
			wp_nonce_field( 'wccs_account_section_' . $section['id'], '_wccs_account_nonce' );
			printf( '<input type="hidden" name="wccs_account_section" value="%s" />', esc_attr( (string) $section['id'] ) );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Salvar informações', 'wc-checkoutsuite' ) . '</button></p>';
			echo '</form>';
			return;
		}

		echo '</div>';
	}

	/**
	 * Reads valid My Account sections from the published document.
	 *
	 * @return array<string,array<string,mixed>> Endpoint sections keyed by slug.
	 */
	private static function configured_sections(): array {
		$found = array();
		foreach ( PublishedDocument::read()->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$section = SectionDefinition::from_array( $raw );
			$account = $section->account();
			$slug    = isset( $account['slug'] ) ? sanitize_title( (string) $account['slug'] ) : '';
			if ( ! $section->is_offered_in( self::DESTINATION ) || '' === $slug || isset( $found[ $slug ] ) ) {
				continue;
			}
			$found[ $slug ] = $section->to_array();
		}
		return $found;
	}

	/** Returns the endpoint slug WordPress resolved for this request. */
	private static function current_slug(): string {
		foreach ( array_keys( self::$endpoints ) as $slug ) {
			if ( null !== get_query_var( $slug, null ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Renders one value as an editable WooCommerce form field or read-only text.
	 *
	 * @param FieldDefinition           $field    Field definition.
	 * @param string                    $title    Title the link configured for this area.
	 * @param mixed                     $value    Stored customer value.
	 * @param bool                      $editable Whether the section is editable.
	 * @param array<string, mixed>|null $document The document they have, when the field is one.
	 * @param FieldBinding|null         $binding  Use of the field in this destination.
	 * @return void
	 */
	private static function field( FieldDefinition $field, string $title, mixed $value, bool $editable, ?array $document = null, ?FieldBinding $binding = null ): void {
		if ( CustomerSectionFields::is_document( $field ) ) {
			self::document( $field, $title, $document, $editable, $binding );

			return;
		}

		if ( ! $editable ) {
			printf(
				'<p class="form-row wccs-account-value"><strong>%1$s</strong><br />%2$s</p>',
				esc_html( $title ),
				esc_html( CustomerSectionFields::display_value( $field, $value ) )
			);
			return;
		}

		$args = array(
			'type'        => $field->type(),
			'label'       => $title,
			'required'    => $field->is_required(),
			'description' => $field->description(),
			'options'     => $field->options(),
		);

		// The WooCommerce helper builds escaped field markup from the escaped arguments above.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo woocommerce_form_field( 'wccs_account_fields[' . $field->id() . ']', $args, $value );
	}

	/**
	 * Whether a section has a document field at all.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> $fields Entries.
	 * @return bool
	 */
	private static function has_documents( array $fields ): bool {
		foreach ( $fields as $entry ) {
			if ( CustomerSectionFields::is_document( $entry['field'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The documents of one section, keyed by field identifier.
	 *
	 * A document is not a value: it is the newest row the customer's own store keeps for that
	 * field, which is why it is read here rather than taken from the values the customer has
	 * stored. A field used twice in the section is one document, and the map says so.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> $fields Entries.
	 * @param int                                                                                            $user_id Customer.
	 * @return array<string, array<string, mixed>> Documents, keyed by field identifier.
	 */
	private static function documents( array $fields, int $user_id ): array {
		$service   = new UploadService();
		$documents = array();

		foreach ( $fields as $entry ) {
			$id = $entry['field']->id();

			if ( ! CustomerSectionFields::is_document( $entry['field'] ) || isset( $documents[ $id ] ) ) {
				continue;
			}

			$record = $service->for_customer_field( $user_id, $id );

			if ( null !== $record ) {
				$documents[ $id ] = $record;
			}
		}

		return $documents;
	}

	/**
	 * Accepts the documents this submission carried.
	 *
	 * Each file field of the section is looked at once. A file that arrived is handed to the
	 * customer store, which owns every rule about it — what it is, whether it fits, and
	 * whether this store may hold files at all; a refusal becomes a message on the form and
	 * the document the customer already had stays where it is.
	 *
	 * The nonce is not checked here: the caller that reads the submission has already verified
	 * it, and this method reads the files that came with that same request.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> $fields  Entries.
	 * @param int                                                                                            $user_id Customer.
	 * @param array<int, string>                                                                             $errors  Errors, by reference.
	 * @return int How many documents were accepted.
	 */
	private static function accept_documents( array $fields, int $user_id, array &$errors ): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The request was verified by the caller before this ran; a file is not a value a nonce can sanitise either way.
		if ( ! isset( $_FILES['wccs_account_files'] ) || ! is_array( $_FILES['wccs_account_files'] ) ) {
			return 0;
		}

		$uploads = $_FILES['wccs_account_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A file array is validated by the upload service, which reads what the file is rather than what it claims.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$accepted = 0;
		$service  = new UploadService();
		$seen     = array();

		foreach ( $fields as $entry ) {
			$field = $entry['field'];
			$id    = $field->id();

			if ( ! CustomerSectionFields::is_document( $field ) || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			if ( ! self::entry_is_writable( $entry ) ) {
				continue;
			}

			$file = self::uploaded_file( $uploads, $id );

			// Nothing was sent for this field, which is not a mistake: the customer may be
			// filling in the rest of the form. Their document is left alone.
			if ( null === $file ) {
				continue;
			}

			$result = $service->accept_for_customer( $file, $id, $user_id );

			if ( '' !== $result['code'] ) {
				$errors[] = sprintf(
					/* translators: 1: field title, 2: reason. */
					__( '%1$s: %2$s', 'wc-checkoutsuite' ),
					$entry['title'],
					$result['message']
				);

				continue;
			}

			++$accepted;
		}

		return $accepted;
	}

	/**
	 * One entry of the file input, in the shape the upload service reads.
	 *
	 * PHP hands a nested file input as parallel arrays, and a field nobody filled in arrives
	 * as an error code rather than as an absent key — so the two are told apart here, where
	 * the request shape is known, instead of inside the service.
	 *
	 * @param array<string, mixed> $uploads The `$_FILES` entry for the section's file input.
	 * @param string               $id      Field identifier.
	 * @return array<string, mixed>|null
	 */
	private static function uploaded_file( array $uploads, string $id ): ?array {
		$name  = isset( $uploads['name'][ $id ] ) ? $uploads['name'][ $id ] : null;
		$error = isset( $uploads['error'][ $id ] ) ? (int) $uploads['error'][ $id ] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error || ! is_string( $name ) || '' === $name ) {
			return null;
		}

		return array(
			'name'     => $name,
			'type'     => isset( $uploads['type'][ $id ] ) ? (string) $uploads['type'][ $id ] : '',
			'tmp_name' => isset( $uploads['tmp_name'][ $id ] ) ? (string) $uploads['tmp_name'][ $id ] : '',
			'error'    => $error,
			'size'     => isset( $uploads['size'][ $id ] ) ? (int) $uploads['size'][ $id ] : 0,
		);
	}

	/**
	 * Whether one entry may be written by this surface.
	 *
	 * @param array{field: FieldDefinition, title: string, position: int, binding: FieldBinding} $entry Entry.
	 * @return bool
	 */
	private static function entry_is_writable( array $entry ): bool {
		return CustomerSectionFields::entry_writable( $entry );
	}

	/**
	 * One document, as the customer sees it on their own page.
	 *
	 * Three statements, never mixed: what they have now, what they may send, and — when this
	 * store cannot keep a file private — why there is no control to send one with. A file
	 * input that cannot work is worse than no file input, because it looks like an offer.
	 *
	 * @param FieldDefinition           $field    Field.
	 * @param string                    $title    Title the section gave it.
	 * @param array<string, mixed>|null $document The document they have, when they have one.
	 * @param bool                      $editable Whether the surface may write.
	 * @param FieldBinding|null         $binding  Use of the field in this destination.
	 * @return void
	 */
	private static function document( FieldDefinition $field, string $title, ?array $document, bool $editable, ?FieldBinding $binding ): void {
		$id = $field->id();

		printf(
			'<p class="form-row wccs-account-document" id="wccs-account-document-%1$s"><strong>%2$s</strong><br /><span class="wccs-account-document__current">%3$s</span>',
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( CustomerSectionFields::document_label( $document ) )
		);

		// Reading it is a decision the use owns, and it is taken by the same door the
		// checkout uses: the page links to it rather than serving the bytes itself.
		if ( null !== $document && null !== $binding && FilePermissions::allows_binding( $binding, self::DESTINATION, 'view' ) ) {
			printf(
				' <a class="wccs-account-document__link" href="%1$s">%2$s</a>',
				esc_url( self::document_url( (string) $document['token'] ) ),
				esc_html__( 'Ver documento', 'wc-checkoutsuite' )
			);
		}

		echo '</p>';

		if ( ! $editable ) {
			return;
		}

		if ( ! UploadsEnvironment::enabled() ) {
			printf(
				'<p class="form-row wccs-account-document__unavailable">%s</p>',
				esc_html( UploadsEnvironment::reason() )
			);

			return;
		}

		printf(
			'<p class="form-row wccs-account-upload"><label for="wccs-account-file-%1$s">%2$s</label><input type="file" id="wccs-account-file-%1$s" name="wccs_account_files[%1$s]" /></p>',
			esc_attr( $id ),
			esc_html( self::document_hint( $field ) )
		);
	}

	/**
	 * What the customer is told before choosing a file.
	 *
	 * The limits come from the same rules the service applies, so the sentence the customer
	 * reads is the sentence the store enforces.
	 *
	 * @param FieldDefinition $field Field.
	 * @return string
	 */
	private static function document_hint( FieldDefinition $field ): string {
		$extensions = $field->settings()['allowedExtensions'] ?? array();
		$extensions = is_array( $extensions ) ? array_map( 'strval', $extensions ) : array();

		if ( array() === $extensions ) {
			return __( 'Enviar documento', 'wc-checkoutsuite' );
		}

		return sprintf(
			/* translators: %s: comma separated extensions. */
			__( 'Enviar documento (%s)', 'wc-checkoutsuite' ),
			implode( ', ', $extensions )
		);
	}

	/**
	 * The address of the door that serves one document.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function document_url( string $token ): string {
		$url = add_query_arg(
			array(
				'token'       => $token,
				'destination' => self::DESTINATION,
			),
			home_url( '/' )
		);

		/**
		 * Filters the address a customer reads their own document from.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url   Address.
		 * @param string $token Token.
		 */
		return (string) apply_filters( 'wccs_account_document_url', $url, $token );
	}
}
