<?php
/**
 * Real WooCommerce My Account endpoints owned by Suite sections.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Account;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Customers\AccountSurfaces;
use WCCheckoutSuite\Domain\Customers\AccountFields;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Customers\CustomerSectionFields;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;
use WCCheckoutSuite\Domain\Uploads\FilePermissions;
use WCCheckoutSuite\Domain\Uploads\UploadService;
use WCCheckoutSuite\Domain\Uploads\UploadsEnvironment;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Http\Checkout\DownloadController;

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

	/** Sections that live on a native page, keyed by the page's endpoint key.
	 *
	 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §7.4: a section may
	 * be placed inside a page WooCommerce already has instead of a page of its own. Those pages
	 * are listed, with the reason each may host content, in
	 * {@see \WCCheckoutSuite\Domain\Customers\AccountSurfaces}.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private static array $native = array();

	/** Registers the WordPress and WooCommerce hooks. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_endpoints' ), 20 );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu_items' ), 20 );
		add_filter( 'woocommerce_save_account_details_required_fields', array( self::class, 'account_required_fields' ), 20 );
	}

	/**
	 * Removes hidden native account fields from WooCommerce's required-field check.
	 *
	 * A hidden field is already submitted as a hidden value to preserve existing profile data.
	 * When that value is empty, however, WooCommerce would still reject the save because its
	 * default required list was written for a visible input. The merchant's explicit hide action
	 * is the authority for that presentation decision; all other validation remains native.
	 *
	 * @param array<string,string> $required WooCommerce required fields.
	 * @return array<string,string>
	 */
	public static function account_required_fields( array $required ): array {
		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) || 'my_account' !== ( $raw['collection_surface'] ?? '' ) || ! empty( $raw['enabled'] ) ) {
				continue;
			}

			$id = (string) ( $raw['id'] ?? '' );

			if ( '' !== $id ) {
				unset( $required[ $id ] );
			}
		}

		return $required;
	}

	/** Registers configured endpoints and refreshes rewrite rules only when needed. */
	public static function register_endpoints(): void {
		self::$endpoints = self::configured_sections();
		self::$native    = self::native_sections();

		// When the merchant has configured a native account field, replace only WooCommerce's
		// account-details renderer. The replacement below deliberately posts the same field
		// names and nonce, so WooCommerce remains the authority that validates and persists the
		// account — WCCS only controls which controls are visible and how they are labelled.
		if ( self::account_details_controlled() ) {
			remove_action( 'woocommerce_account_edit-account_endpoint', 'woocommerce_account_edit_account', 10 );
			add_action( 'woocommerce_account_edit-account_endpoint', array( self::class, 'render_account_details' ), 10 );
		}

		foreach ( self::$endpoints as $slug => $section ) {
			add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
			add_action( 'woocommerce_account_' . $slug . '_endpoint', array( self::class, 'render' ) );
		}

		// A native page renders its own content through the same action, so a section placed on
		// it is a sibling of that content and never a nested form. The priority is after
		// WooCommerce's own, which is what makes it a sibling.
		foreach ( array_keys( self::$native ) as $page ) {
			add_action( 'woocommerce_account_' . $page . '_endpoint', array( self::class, 'render_native' ), 20 );
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
	 * Whether the published document contains an override for a native account field.
	 *
	 * @return bool
	 */
	private static function account_details_controlled(): bool {
		$catalogue = ( new AccountFields() )->catalogue();
		$ids       = array();

		foreach ( $catalogue['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) ) {
				$ids[ (string) $field['id'] ] = true;
			}
		}

		if ( array() === $ids ) {
			return false;
		}

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			if ( isset( $ids[ (string) ( $raw['id'] ?? '' ) ] ) && 'my_account' === ( $raw['collection_surface'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders WooCommerce's account-details form with WCCS's native overrides.
	 *
	 * The form contract intentionally mirrors WooCommerce's native template. Disabled identity
	 * fields are submitted as hidden values, preserving the account data for the native handler;
	 * password inputs are simply omitted because no password value may be carried forward.
	 *
	 * @return void
	 */
	public static function render_account_details(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user      = wp_get_current_user();
		$catalogue = ( new AccountFields() )->catalogue();
		$defaults  = array();

		foreach ( $catalogue['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) ) {
				$defaults[ (string) $field['id'] ] = $field;
			}
		}

		$overrides = array();

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$id = (string) ( $raw['id'] ?? '' );

			if ( isset( $defaults[ $id ] ) && 'my_account' === ( $raw['collection_surface'] ?? '' ) ) {
				$overrides[ $id ] = FieldDefinition::from_array( $raw );
			}
		}

		$details  = array();
		$password = array();

		foreach ( $defaults as $id => $field ) {
			if ( 'password' === ( $field['group'] ?? '' ) ) {
				$password[] = array( $id, $field, $overrides[ $id ] ?? null );
			} else {
				$details[] = array( $id, $field, $overrides[ $id ] ?? null );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_before_edit_account_form' );
		ob_start();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_edit_account_form_tag' );
		$form_tag = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hook output is an HTML attribute fragment supplied by WooCommerce extensions.
		printf( '<form class="woocommerce-EditAccountForm edit-account" action="" method="post" %s>', $form_tag );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_edit_account_form_start' );

		foreach ( $details as $entry ) {
			self::account_native_field( $entry[0], $entry[1], $entry[2], $user );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_edit_account_form_fields' );

		$visible_passwords = array_filter(
			$password,
			static fn( array $entry ): bool => null === $entry[2] || $entry[2]->is_enabled()
		);

		if ( array() !== $visible_passwords ) {
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Label belongs to WooCommerce's native template.
			echo '<fieldset><legend>' . esc_html__( 'Password change', 'woocommerce' ) . '</legend>';

			foreach ( $visible_passwords as $entry ) {
				self::account_native_field( $entry[0], $entry[1], $entry[2], $user );
			}

			echo '</fieldset>';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_edit_account_form' );
		echo '<p>';
		wp_nonce_field( 'save_account_details', 'save-account-details-nonce' );
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Label belongs to WooCommerce's native template.
		echo '<button type="submit" class="woocommerce-Button button" name="save_account_details" value="Save changes">' . esc_html__( 'Save changes', 'woocommerce' ) . '</button>';
		echo '<input type="hidden" name="action" value="save_account_details" />';
		echo '</p>';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_edit_account_form_end' );
		echo '</form>';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce extension hook.
		do_action( 'woocommerce_after_edit_account_form' );
	}

	/**
	 * Renders one native account field or preserves its value while hidden.
	 *
	 * @param string               $id       Field identifier.
	 * @param array<string,mixed>  $native   Native inventory entry.
	 * @param FieldDefinition|null $override Stored override.
	 * @param \WP_User             $user     Current user.
	 * @return void
	 */
	private static function account_native_field( string $id, array $native, ?FieldDefinition $override, \WP_User $user ): void {
		$enabled = null === $override || $override->is_enabled();
		$value   = self::account_native_value( $id, $user );

		if ( ! $enabled ) {
			if ( 'password' !== ( $native['nativeType'] ?? '' ) ) {
				printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $id ), esc_attr( (string) $value ) );
			}

			return;
		}

		$label       = null === $override ? (string) $native['label'] : $override->label();
		$description = null === $override ? (string) ( $native['description'] ?? '' ) : $override->description();
		$args        = array(
			'type'        => 'password' === ( $native['nativeType'] ?? '' ) ? 'password' : (string) $native['nativeType'],
			'label'       => $label,
			'required'    => (bool) $native['required'],
			'description' => $description,
			'class'       => isset( $native['classes'] ) && is_array( $native['classes'] ) ? $native['classes'] : array( 'form-row-wide' ),
		);

		// The helper is WooCommerce's own escaped field renderer. It preserves the input names
		// that class-wc-form-handler.php reads, which is the key to keeping native persistence.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo woocommerce_form_field( $id, $args, 'password' === $args['type'] ? '' : $value );
	}

	/**
	 * Current user value for a native account field.
	 *
	 * @param string   $id   Field id.
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	private static function account_native_value( string $id, \WP_User $user ): string {
		$values = array(
			'account_first_name'   => $user->first_name,
			'account_last_name'    => $user->last_name,
			'account_display_name' => $user->display_name,
			'account_email'        => $user->user_email,
		);

		return isset( $values[ $id ] ) ? (string) $values[ $id ] : '';
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

		self::render_section( $section );
	}

	/**
	 * Renders the sections a native page hosts, after WooCommerce's own content.
	 *
	 * The page is found from the query vars, the same way an endpoint finds its slug: a native
	 * page's action fires on the request that asked for it, and nothing else names the page.
	 *
	 * @return void
	 */
	public static function render_native(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		foreach ( self::native_page_order() as $page ) {
			if ( null === get_query_var( $page, null ) ) {
				continue;
			}

			foreach ( self::$native[ $page ] ?? array() as $section ) {
				self::render_section( $section );
			}

			return;
		}
	}

	/**
	 * One section, rendered.
	 *
	 * This is the whole of a section: its fields, the submission it may be answering, and the
	 * form — the same code whether the section has a page of its own or sits on a native one,
	 * because a submission does not care which page it arrived from.
	 *
	 * @param array<string, mixed> $section Section.
	 * @return void
	 */
	private static function render_section( array $section ): void {
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
	 * Only the sections that have a page of their own: a section placed on a native page
	 * ({@see self::native_sections()}) has no endpoint, so it has no slug to register and no
	 * menu entry to add.
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
			$page    = isset( $account['page'] ) ? sanitize_key( (string) $account['page'] ) : '';
			if ( ! $section->is_offered_in( self::DESTINATION ) || '' !== $page || '' === $slug || isset( $found[ $slug ] ) ) {
				continue;
			}
			$found[ $slug ] = $section->to_array();
		}
		return $found;
	}

	/**
	 * The sections placed on a native page, keyed by that page.
	 *
	 * A page this plugin does not know how to place content on is skipped here rather than
	 * rendered somewhere it does not belong: the validator refuses it by name, and a document
	 * that reached the store before that rule existed must not put a form on the orders list.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function native_sections(): array {
		$found = array();

		foreach ( PublishedDocument::read()->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$section = SectionDefinition::from_array( $raw );
			$account = $section->account();
			$page    = isset( $account['page'] ) ? sanitize_key( (string) $account['page'] ) : '';

			if ( ! $section->is_offered_in( self::DESTINATION ) || '' === $page || ! AccountSurfaces::has( $page ) ) {
				continue;
			}

			$position = (int) $section->position();

			$found[ $page ][ $position . '-' . $section->id() ] = $section->to_array();
		}

		// Sorted by the section's own position, so two sections on the same page come out in the
		// order the merchant put them in — and by identifier when the positions are equal, so the
		// order never changes between reads.
		foreach ( $found as $page => $sections ) {
			ksort( $sections );
			$found[ $page ] = array_values( $sections );
		}

		return $found;
	}

	/**
	 * The native pages that carry sections, in the order they are declared.
	 *
	 * @return array<int, string>
	 */
	private static function native_page_order(): array {
		$order = array();

		foreach ( AccountSurfaces::values() as $page ) {
			if ( isset( self::$native[ $page ] ) ) {
				$order[] = $page;
			}
		}

		return $order;
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
	 * @param FieldDefinition                                            $field    Field definition.
	 * @param string                                                     $title    Title the link configured for this area.
	 * @param mixed                                                      $value    Stored customer value.
	 * @param bool                                                       $editable Whether the section is editable.
	 * @param array<string, mixed>|array<int, array<string, mixed>>|null $document The documents they have, when the field is one.
	 * @param FieldBinding|null                                          $binding  Use of the field in this destination.
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
	 * A document is not a value: it is a row the customer's own store keeps for that field,
	 * which is why it is read here rather than taken from the values the customer has stored.
	 * The list preserves support for both single-file replacement and multiple-file fields.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}> $fields Entries.
	 * @param int                                                                                            $user_id Customer.
	 * @return array<string, array<int, array<string, mixed>>> Documents, keyed by field identifier.
	 */
	private static function documents( array $fields, int $user_id ): array {
		$service   = new UploadService();
		$documents = array();

		foreach ( $fields as $entry ) {
			$id = $entry['field']->id();

			if ( ! CustomerSectionFields::is_document( $entry['field'] ) || isset( $documents[ $id ] ) ) {
				continue;
			}

			$record = $service->for_customer_field_all( $user_id, $id );

			if ( array() !== $record ) {
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

			if ( ! self::entry_is_writable( $entry ) || ! self::entry_can_resubmit( $entry ) ) {
				continue;
			}

			$files = self::uploaded_files( $uploads, $id );

			// Nothing was sent for this field, which is not a mistake: the customer may be
			// filling in the rest of the form. Their document is left alone.
			if ( array() === $files ) {
				continue;
			}

			$result = $service->accept_for_customer_files( $files, $id, $user_id, $field->settings() );

			foreach ( $result['errors'] as $message ) {
				$errors[] = sprintf(
					/* translators: 1: field title, 2: reason. */
					__( '%1$s: %2$s', 'wc-checkoutsuite' ),
					$entry['title'],
					$message
				);
			}

			$accepted += $result['accepted'];
		}

		return $accepted;
	}

	/**
	 * Entries of one file input, in the shape the upload service reads.
	 *
	 * PHP hands a nested file input as parallel arrays, and a field nobody filled in arrives
	 * as an error code rather than as an absent key — so the two are told apart here, where
	 * the request shape is known, instead of inside the service.
	 *
	 * @param array<string, mixed> $uploads The `$_FILES` entry for the section's file input.
	 * @param string               $id      Field identifier.
	 * @return array<int, array<string, mixed>>
	 */
	private static function uploaded_files( array $uploads, string $id ): array {
		$name  = $uploads['name'][ $id ] ?? null;
		$names = is_array( $name ) ? $name : array( $name );
		$files = array();

		foreach ( $names as $index => $entry_name ) {
			$error = $uploads['error'][ $id ] ?? UPLOAD_ERR_NO_FILE;
			$type  = $uploads['type'][ $id ] ?? '';
			$tmp   = $uploads['tmp_name'][ $id ] ?? '';
			$size  = $uploads['size'][ $id ] ?? 0;

			if ( is_array( $error ) ) {
				$error = $error[ $index ] ?? UPLOAD_ERR_NO_FILE;
			}
			if ( is_array( $type ) ) {
				$type = $type[ $index ] ?? '';
			}
			if ( is_array( $tmp ) ) {
				$tmp = $tmp[ $index ] ?? '';
			}
			if ( is_array( $size ) ) {
				$size = $size[ $index ] ?? 0;
			}

			if ( UPLOAD_ERR_NO_FILE === (int) $error || ! is_string( $entry_name ) || '' === $entry_name ) {
				continue;
			}

			$files[] = array(
				'name'     => $entry_name,
				'type'     => (string) $type,
				'tmp_name' => (string) $tmp,
				'error'    => (int) $error,
				'size'     => (int) $size,
			);
		}

		return $files;
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
	 * Whether a customer may send or replace the document in this use.
	 *
	 * Editability controls scalar values; a file has a second, explicit permission so a
	 * section can show a document and offer its download link without accepting a replacement.
	 * The server checks this as well as the rendered control, because hiding the input is not
	 * an authorization boundary.
	 *
	 * @param array{field: FieldDefinition, title: string, position: int, binding: FieldBinding} $entry Entry.
	 * @return bool
	 */
	private static function entry_can_resubmit( array $entry ): bool {
		return FilePermissions::allows_binding( $entry['binding'], self::DESTINATION, 'resubmit' );
	}

	/**
	 * One document, as the customer sees it on their own page.
	 *
	 * Three statements, never mixed: what they have now, what they may send, and — when this
	 * store cannot keep a file private — why there is no control to send one with. A file
	 * input that cannot work is worse than no file input, because it looks like an offer.
	 *
	 * @param FieldDefinition                                            $field    Field.
	 * @param string                                                     $title    Title the section gave it.
	 * @param array<string, mixed>|array<int, array<string, mixed>>|null $document The documents they have, when they have any.
	 * @param bool                                                       $editable Whether the surface may write.
	 * @param FieldBinding|null                                          $binding  Use of the field in this destination.
	 * @return void
	 */
	private static function document( FieldDefinition $field, string $title, ?array $document, bool $editable, ?FieldBinding $binding ): void {
		$id      = $field->id();
		$records = null === $document
			? array()
			: ( isset( $document['token'] ) ? array( $document ) : array_values( array_filter( $document, 'is_array' ) ) );
		$label   = array() === $records
			? CustomerSectionFields::document_label( null )
			: implode( ', ', array_map( array( CustomerSectionFields::class, 'document_label' ), $records ) );

		printf(
			'<p class="form-row wccs-account-document" id="wccs-account-document-%1$s"><strong>%2$s</strong><br /><span class="wccs-account-document__current">%3$s</span>',
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $label )
		);

		// Reading it is a decision the use owns, and it is taken by the same door the
		// checkout uses: the page links to it rather than serving the bytes itself.
		if ( array() !== $records && null !== $binding && FilePermissions::allows_binding( $binding, self::DESTINATION, 'download' ) ) {
			foreach ( $records as $record ) {
				if ( ! isset( $record['token'] ) ) {
					continue;
				}

				printf(
					' <a class="wccs-account-document__link" href="%1$s">%2$s</a>',
					esc_url( self::document_url( (string) $record['token'] ) ),
					esc_html__( 'Baixar documento', 'wc-checkoutsuite' )
				);
			}
		}

		echo '</p>';

		if ( ! $editable || null === $binding || ! FilePermissions::allows_binding( $binding, self::DESTINATION, 'resubmit' ) ) {
			return;
		}

		if ( ! UploadsEnvironment::enabled() ) {
			printf(
				'<p class="form-row wccs-account-document__unavailable">%s</p>',
				esc_html( UploadsEnvironment::reason() )
			);

			return;
		}

		$extensions = $field->settings()['allowedExtensions'] ?? array();
		$extensions = is_array( $extensions ) ? array_map( 'sanitize_key', $extensions ) : array();
		$accept     = array();

		foreach ( $extensions as $extension ) {
			if ( '' !== $extension ) {
				$accept[] = '.' . ltrim( $extension, '.' );
			}
		}
		$max_files = isset( $field->settings()['maxFiles'] ) ? max( 1, min( 20, (int) $field->settings()['maxFiles'] ) ) : 1;
		$name      = 1 < $max_files ? 'wccs_account_files[' . $id . '][]' : 'wccs_account_files[' . $id . ']';
		$multiple  = 1 < $max_files ? ' multiple' : '';

		printf(
			'<p class="form-row wccs-account-upload"><label for="wccs-account-file-%1$s">%2$s</label><input type="file" id="wccs-account-file-%1$s" name="%3$s"%4$s%5$s /></p>',
			esc_attr( $id ),
			esc_html( self::document_hint( $field ) ),
			esc_attr( $name ),
			array() !== $accept ? ' accept="' . esc_attr( implode( ',', $accept ) ) . '"' : '',
			esc_attr( $multiple )
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
				'destination' => self::DESTINATION,
				// WordPress REST cookie authentication requires the REST nonce. A
				// download is a normal link, so it cannot send X-WP-Nonce as a header.
				'_wpnonce'    => wp_create_nonce( 'wp_rest' ),
			),
			rest_url(
				SchemaController::rest_namespace()
					. str_replace( '(?P<token>[a-f0-9]{64})', $token, DownloadController::ROUTE_DOWNLOAD )
			)
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
