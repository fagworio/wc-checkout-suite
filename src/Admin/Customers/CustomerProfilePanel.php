<?php
/**
 * The customer values staff read and write on the customer's own profile screen.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin\Customers;

use WP_Error;
use WP_User;
use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Customers\CustomerSectionFields;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/**
 * The staff half of the customer's own data.
 *
 * A value collected on a page of Minha Conta belongs to the customer, and the customer
 * is not the only one who has to see it: the store answers for it too, at the counter
 * and on the phone. This is where that happens — the profile screen WordPress already
 * gives every user, which WooCommerce itself uses for the billing and shipping
 * addresses, so staff look for customer data where they already look for it.
 *
 * Three decisions:
 *
 * 1. **It is the same value, not a copy.** Both surfaces read and write
 *    `CustomerFieldsService`, so a change made here is what the customer sees on their
 *    own page. A second store for "what staff typed" would be two answers to one
 *    question, and the one the customer read would depend on which surface saved last.
 * 2. **It never rewrites an order.** What is shown here is the customer's current value;
 *    an order keeps the snapshot it took when it was placed. Editing a profile does not
 *    change what a past order says, which is the separation section 14 asks for.
 * 3. **Validation is not re-implemented.** A value typed here goes through the same
 *    `ValueProcessor` the checkout and the customer's own page use, with the same
 *    definitions, so a document this panel accepts is a document those accept.
 *
 * @see ROADMAP.md section 14
 */
final class CustomerProfilePanel {

	/**
	 * The destination this surface serves.
	 */
	public const DESTINATION = 'admin_customer_profile';

	/**
	 * Field name prefix of a submitted value.
	 */
	public const FIELD_PREFIX = 'wccs_customer_fields';

	/**
	 * Marker field: present only when this panel was part of the submission.
	 */
	public const SURFACE_FIELD = 'wccs_customer_surface';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'wccs_save_customer_fields';

	/**
	 * Registers the panel on the profile screen WordPress already renders.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ), 20, 1 );
		add_action( 'edit_user_profile', array( self::class, 'render' ), 20, 1 );
		// Validation runs while WordPress is still deciding whether to save the user:
		// an error here stops the update and brings the form back with the message,
		// which is the only moment the person who typed it can repair it.
		add_action( 'user_profile_update_errors', array( self::class, 'validate' ), 10, 3 );
		add_action( 'personal_options_update', array( self::class, 'save' ), 20, 1 );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ), 20, 1 );
	}

	/**
	 * The sections this panel shows, in the document's own order.
	 *
	 * @return array<int, array{section: SectionDefinition, entries: array<int, array{field: FieldDefinition, title: string, position: int, binding: FieldBinding}>}>
	 */
	public static function sections(): array {
		$document = PublishedDocument::read();
		$fields   = $document->fields();
		$found    = array();

		foreach ( $document->sections() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$section = SectionDefinition::from_array( $raw );

			if ( ! $section->is_offered_in( self::DESTINATION ) ) {
				continue;
			}

			$entries = CustomerSectionFields::entries( $fields, self::DESTINATION, $section->id() );

			if ( array() === $entries ) {
				// A section with nothing to show is not an empty panel: this screen is
				// where staff work, and a heading with no fields under it is noise.
				continue;
			}

			$found[] = array(
				'section' => $section,
				'entries' => $entries,
			);
		}

		return $found;
	}

	/**
	 * Draws the panel on the profile screen.
	 *
	 * @param mixed $user User the screen is editing.
	 * @return void
	 */
	public static function render( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$sections = self::sections();

		if ( array() === $sections ) {
			return;
		}

		$values = ( new CustomerFieldsService() )->values( (int) $user->ID );

		wp_nonce_field( self::NONCE . '_' . $user->ID, self::NONCE );
		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( self::SURFACE_FIELD ),
			esc_attr( self::DESTINATION )
		);

		foreach ( $sections as $entry ) {
			$stored = $entry['section']->to_array();

			if ( ! isset( $stored['presentation']['show_title'] ) || ! empty( $stored['presentation']['show_title'] ) ) {
				printf( '<h2>%s</h2>', esc_html( $entry['section']->title() ) );
			}

			$description = trim( (string) ( $stored['description'] ?? '' ) );

			if ( '' !== $description ) {
				printf( '<p>%s</p>', esc_html( $description ) );
			}

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $entry['entries'] as $field ) {
				$definition = $field['field'];
				$value      = $values[ $definition->id() ] ?? null;

				echo '<tr>';

				// The use of the field decides, not the field: the same definition may be
				// editable in one panel and read-only in the next (§3.3).
				if ( ! CustomerSectionFields::entry_writable( $field ) ) {
					printf(
						'<th scope="row">%s</th><td>%s</td>',
						esc_html( $field['title'] ),
						esc_html( CustomerSectionFields::display_value( $definition, $value ) )
					);
				} else {
					echo '<td colspan="2">';
					// The WooCommerce helper builds escaped markup from the escaped
					// arguments above, and it is the same control the customer's own page
					// renders: one implementation of a field, not two that agree today.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo woocommerce_form_field(
						self::FIELD_PREFIX . '[' . $definition->id() . ']',
						array(
							'type'        => $definition->type(),
							'label'       => $field['title'],
							'required'    => $definition->is_required(),
							'description' => $definition->description(),
							'options'     => $definition->options(),
						),
						$value
					);
					echo '</td>';
				}

				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Validates a submitted panel before WordPress saves the user.
	 *
	 * @param mixed $errors Errors WordPress is collecting.
	 * @param mixed $update Whether this is an existing user.
	 * @param mixed $user   User being saved.
	 * @return void
	 */
	public static function validate( $errors, $update, $user ): void {
		unset( $update );

		if ( ! $errors instanceof WP_Error || ! $user instanceof WP_User ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) || ! self::is_posted() ) {
			return;
		}

		if ( ! self::nonce_is_valid( (int) $user->ID ) ) {
			$errors->add(
				'wccs_customer_fields_nonce',
				__( 'The form session expired. Reload the profile and try again.', 'wc-checkoutsuite' )
			);

			return;
		}

		foreach ( self::submission( (int) $user->ID )['errors'] as $message ) {
			$errors->add( 'wccs_customer_fields', $message );
		}
	}

	/**
	 * Writes the validated values to the customer.
	 *
	 * @param mixed $user_id User identifier.
	 * @return void
	 */
	public static function save( $user_id ): void {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) || ! self::is_posted() ) {
			return;
		}

		if ( ! self::nonce_is_valid( $user_id ) ) {
			return;
		}

		// The submission is computed once and written only when it produced no error.
		// `validate()` already refused the update WordPress was about to make, so this
		// second look is what keeps a partial write impossible even if another plugin
		// calls the save action directly.
		$result = self::submission( $user_id );

		if ( array() !== $result['errors'] || array() === $result['updates'] ) {
			return;
		}

		( new CustomerFieldsService() )->update( $user_id, $result['updates'] );
	}

	/**
	 * Validates one submission of this panel.
	 *
	 * @param int $user_id User identifier.
	 * @return array{updates: array<string, mixed>, errors: array<int, string>}
	 */
	private static function submission( int $user_id ): array {
		$values  = ( new CustomerFieldsService() )->values( $user_id );
		$posted  = self::posted_values();
		$errors  = array();
		$updates = array();

		foreach ( self::sections() as $entry ) {
			$updates = array_merge(
				$updates,
				CustomerSectionFields::submission(
					$entry['entries'],
					self::DESTINATION,
					array_merge( $values, $updates ),
					$posted,
					$errors
				)
			);
		}

		return array(
			'updates' => $updates,
			'errors'  => $errors,
		);
	}

	/**
	 * Whether this panel was part of the submission.
	 *
	 * @return bool
	 */
	private static function is_posted(): bool {
		// The marker says which surface posted, and the only thing done with it is a
		// comparison against a constant: unslashed and sanitized on the way in.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified before any value is read.
		$surface = sanitize_key( (string) wp_unslash( $_POST[ self::SURFACE_FIELD ] ?? '' ) );

		return self::DESTINATION === $surface;
	}

	/**
	 * Whether the submitted nonce belongs to this user and this panel.
	 *
	 * @param int $user_id User identifier.
	 * @return bool
	 */
	private static function nonce_is_valid( int $user_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the nonce being verified.
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce ) {
			return false;
		}

		return false !== wp_verify_nonce( $nonce, self::NONCE . '_' . $user_id );
	}

	/**
	 * The submitted values, sanitized.
	 *
	 * @return array<string, mixed>
	 */
	private static function posted_values(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified before this is called.
		if ( ! isset( $_POST[ self::FIELD_PREFIX ] ) || ! is_array( $_POST[ self::FIELD_PREFIX ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified before this is called.
		return map_deep( wp_unslash( $_POST[ self::FIELD_PREFIX ] ), 'sanitize_textarea_field' );
	}
}
