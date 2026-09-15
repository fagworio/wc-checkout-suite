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
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/** Registers and renders Suite-owned WooCommerce My Account pages. */
final class MyAccountSections {

	private const SIGNATURE_OPTION = 'wccs_account_endpoint_signature';

	/**
	 * The field controls the first account delivery can truthfully collect.
	 *
	 * File upload has its own private-media lifecycle and heading/hidden fields do
	 * not represent customer-owned values. They remain unavailable here until that
	 * lifecycle is implemented for My Account too.
	 *
	 * @var array<int,string>
	 */
	private const RENDERABLE_TYPES = array(
		'text',
		'textarea',
		'email',
		'tel',
		'url',
		'number',
		'date',
		'time',
		'select',
		'radio',
		'checkbox',
	);

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

		$fields   = self::fields( (string) $section['id'] );
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
				$updates = self::process_submission( $fields, $values, $posted, $errors );
				if ( array() === $errors ) {
					$service->update( get_current_user_id(), $updates );
					$values = array_merge( $values, $updates );
					$notice = __( 'Informações salvas com sucesso.', 'wc-checkoutsuite' );
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
		if ( $editable ) {
			echo '<form method="post" class="woocommerce-form wccs-account-section">';
		} else {
			echo '<div class="wccs-account-section">';
		}

		foreach ( $fields as $entry ) {
			self::field( $entry['field'], $entry['title'], $values[ $entry['field']->id() ] ?? null, $editable );
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
			if ( ! $section->is_offered_in( 'customer_profile' ) || '' === $slug || isset( $found[ $slug ] ) ) {
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
	 * Resolves the fields rendered in one account section.
	 *
	 * The section, the title and the order are the *link* the merchant configured for
	 * this destination, exactly as every other area reads them: a field named
	 * differently for the account page is named that way here, and the link's position
	 * decides where it sits in the form.
	 *
	 * @param string $section_id Section identifier.
	 * @return array<int, array{field: FieldDefinition, title: string, position: int}> Renderable entries.
	 */
	private static function fields( string $section_id ): array {
		$fields = array();
		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$field = FieldDefinition::from_array( $raw );
			$link  = $field->destinations()['customer_profile'] ?? array();
			if (
				! $field->is_enabled() ||
				! in_array( $field->type(), self::RENDERABLE_TYPES, true ) ||
				empty( $link['enabled'] ) ||
				(string) ( $link['section'] ?? '' ) !== $section_id
			) {
				continue;
			}
			$title    = isset( $link['title'] ) && '' !== trim( (string) $link['title'] )
				? (string) $link['title']
				: $field->label();
			$position = isset( $link['position'] ) && is_numeric( $link['position'] )
				? (int) $link['position']
				: $field->position();
			$fields[] = array(
				'field'    => $field,
				'title'    => $title,
				'position' => $position,
			);
		}
		usort( $fields, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );
		return $fields;
	}

	/**
	 * Validates customer input after the endpoint nonce has been checked.
	 *
	 * @param array<int, array{field: FieldDefinition, title: string, position: int}> $fields Rendered entries.
	 * @param array<string,mixed>                                                     $values Current customer values.
	 * @param array<string,mixed>                                                     $posted Sanitized submitted values.
	 * @param array<int,string>                                                       $errors Validation errors, by reference.
	 * @return array<string,mixed> Canonical updates.
	 */
	private static function process_submission( array $fields, array $values, array $posted, array &$errors ): array {
		$updates   = array();
		$canonical = array();
		foreach ( $fields as $entry ) {
			$field = $entry['field'];
			$link  = $field->destinations()['customer_profile'] ?? array();
			if ( 'edit' !== ( $link['mode'] ?? 'edit' ) ) {
				continue;
			}
			// The page renders every editable field, so a key that never reached the
			// request was not part of this submission: it is left alone rather than
			// read as empty and used to erase what the customer had stored. The one
			// exception is the checkbox, where the browser's own convention is that an
			// absent box means unchecked.
			$is_checkbox = 'checkbox' === $field->type();

			if ( ! $is_checkbox && ! array_key_exists( $field->id(), $posted ) ) {
				continue;
			}

			$raw       = $posted[ $field->id() ] ?? false;
			$processed = Registries::instance()->value_processor()->process(
				$field,
				$raw,
				new FieldContext(
					array(
						'customer_logged_in' => true,
						'fields'             => array_merge( $values, $canonical ),
					),
					'account'
				)
			);
			if ( ! $processed->result()->is_valid() ) {
				foreach ( $processed->result()->errors() as $error ) {
					$errors[] = (string) $error['message'];
				}
				continue;
			}
			$updates[ $field->id() ]   = $processed->value();
			$canonical[ $field->id() ] = $processed->value();
		}
		return $updates;
	}

	/**
	 * Renders one value as an editable WooCommerce form field or read-only text.
	 *
	 * @param FieldDefinition $field    Field definition.
	 * @param string          $title    Title the link configured for this area.
	 * @param mixed           $value    Stored customer value.
	 * @param bool            $editable Whether the section is editable.
	 * @return void
	 */
	private static function field( FieldDefinition $field, string $title, mixed $value, bool $editable ): void {
		if ( ! $editable ) {
			printf(
				'<p class="form-row wccs-account-value"><strong>%1$s</strong><br />%2$s</p>',
				esc_html( $title ),
				esc_html( self::display_value( $field, $value ) )
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
	 * Converts a stored value to a safe, readable value-only representation.
	 *
	 * @param FieldDefinition $field Field definition.
	 * @param mixed           $value Stored customer value.
	 * @return string Display value.
	 */
	private static function display_value( FieldDefinition $field, mixed $value ): string {
		if ( 'checkbox' === $field->type() ) {
			return ! empty( $value ) ? __( 'Sim', 'wc-checkoutsuite' ) : __( 'Não', 'wc-checkoutsuite' );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
