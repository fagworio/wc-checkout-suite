<?php
/**
 * Translates a stored schema into the classic checkout's field array.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Applies a schema to `woocommerce_checkout_fields`.
 *
 * ROADMAP.md section 7 asks for the changes to the standard fields to be
 * **selective**, preserving country requirements, address rules, guest checkout,
 * registration and the behaviour of other plugins. Four rules follow from that,
 * and all four are enforced here rather than described:
 *
 * 1. **A WooCommerce field keeps its contract.** Only presentation is touched —
 *    label, description, placeholder, width, order. The `type`, `required`,
 *    `validate`, `sanitize`, `autocomplete` and everything else are left exactly
 *    as WooCommerce and other plugins set them, because those are what shipping,
 *    tax and payment read.
 *
 * 2. **A type Classic cannot render is not faked.** Rendering a file upload as a
 *    text input would produce a field that accepts something the store cannot
 *    store. Such a field is skipped and reported instead, and the report is what
 *    the diagnostics screen will show.
 *
 * 3. **A type Classic renders differently is said so.** A date becomes a text
 *    input; section 7 accepts that for the no-JavaScript path, but the reduction
 *    is recorded rather than hidden.
 *
 * 4. **Nothing here reads the draft.** The caller passes the published document;
 *    the adapter has no way to reach anything else, which is what keeps "saving a
 *    draft does not affect the store" true at this layer too.
 *
 * The class is pure: it takes the field array and returns a new one. That is what
 * lets the whole translation be unit tested without a checkout page, which this
 * store does not have.
 *
 * @see ROADMAP.md section 7
 */
final class ClassicAdapter {

	/**
	 * Attribute that marks a field this plugin added to the checkout.
	 *
	 * The identifier the merchant chose is not a pattern anything could match on,
	 * so the server says which fields are its own with a data attribute — the
	 * client half finds the fields it owns by it, and it travels with the
	 * fragment if WooCommerce replaces it.
	 *
	 * It is a constant because it is a contract with three readers: the client
	 * half that initialises its components on the marked fields, and
	 * {@see \WCCheckoutSuite\Domain\Checkout\CoreFields}, which asks WooCommerce
	 * which fields it owns through the very filter this adapter hooks. Without
	 * the marker there, the plugin's own published fields come back inside the
	 * answer and are mistaken for WooCommerce's — which is how a store ended up
	 * refusing to save a document it had already published.
	 */
	public const FIELD_ATTRIBUTE = 'data-wccs-field';

	/**
	 * Suite section to the WooCommerce section it lands in.
	 *
	 * Section 4 warns that Billing, Shipping, Contact, Account and Order "não
	 * correspondem automaticamente a slots idênticos em todos os checkouts". In
	 * the classic checkout there is no contact step: the fields that reach the
	 * customer live with the billing address, so that is where a contact section
	 * goes.
	 */
	private const SECTION_MAP = array(
		'billing'  => 'billing',
		'shipping' => 'shipping',
		'account'  => 'account',
		'order'    => 'order',
		'contact'  => 'billing',
	);

	/**
	 * Suite type to the WooCommerce type that renders it.
	 *
	 * Only the ones whose meaning survives the translation.
	 */
	private const TYPE_MAP = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'tel'      => 'tel',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'country'  => 'country',
		'state'    => 'state',
		'hidden'   => 'hidden',
		// The field API has no file type; ClassicUploads renders this one through the
		// `woocommerce_form_field_file` filter, which is the documented seam for a type
		// WooCommerce does not know.
		'file'     => 'file',
	);

	/**
	 * Suite types rendered as text, and honestly reduced.
	 *
	 * The classic checkout has no number, url or date input through this API, and
	 * a plugin could add one only by replacing the template. Section 7 accepts a
	 * text input for the no-JavaScript path; what it does not accept is calling it
	 * something it is not, so each of these is reported.
	 */
	private const DEGRADED = array(
		'number'   => 'Rendered as a text input: the classic field API has no number type.',
		'url'      => 'Rendered as a text input: the classic field API has no url type.',
		'date'     => 'Rendered as a text input: the classic field API has no date type.',
		'time'     => 'Rendered as a text input: the classic field API has no time type.',
		'datetime' => 'Rendered as a text input: the classic field API has no date and time type.',
	);

	/**
	 * What the last translation could not do.
	 *
	 * @var array<int, array{field: string, type: string, level: string, reason: string}>
	 */
	private array $report = array();

	/**
	 * The values the checkout starts with, by field identifier.
	 *
	 * Resolved by the caller, which is the half that can know who is shopping: the adapter is a
	 * pure function over the document, and looking up a customer here would make it depend on the
	 * session it happens to be running in.
	 *
	 * @var array<string, string>
	 */
	private array $prefill = array();

	/**
	 * What the last translation could not do.
	 *
	 * Two levels: `skipped` means nothing was rendered, `degraded` means something
	 * was rendered that is less than the type promises.
	 *
	 * @return array<int, array{field: string, type: string, level: string, reason: string}>
	 */
	public function report(): array {
		return $this->report;
	}

	/**
	 * Whether a field type can be rendered by the classic checkout at all.
	 *
	 * @param string $type Suite type key.
	 * @return bool
	 */
	public static function can_render( string $type ): bool {
		return isset( self::TYPE_MAP[ $type ] )
			|| isset( self::DEGRADED[ $type ] )
			|| '' !== self::control_for( $type );
	}

	/**
	 * The control a contributed type is rendered by.
	 *
	 * A type this plugin knows is rendered by its own entry in the maps above. A type
	 * contributed by another plugin is rendered by the control it declares — and only by a
	 * control that exists here, because a declaration is a choice among what is available
	 * and not a promise that anything can be drawn.
	 *
	 * @param string $type Field type.
	 * @return string Control name, or an empty string.
	 */
	public static function control_for( string $type ): string {
		if ( isset( self::TYPE_MAP[ $type ] ) || isset( self::DEGRADED[ $type ] ) ) {
			return '';
		}

		$control = \WCCheckoutSuite\Domain\Registries::instance()->types()->control( $type );

		return in_array( $control, array( 'text', 'textarea', 'email', 'tel', 'select', 'radio', 'checkbox' ), true )
			? $control
			: '';
	}

	/**
	 * Applies the definitions to a WooCommerce field array.
	 *
	 * @param array<string, mixed>             $fields      Fields from `woocommerce_checkout_fields`.
	 * @param array<int, array<string, mixed>> $definitions Raw field definitions, published.
	 * @param array<int, array<string, mixed>> $sections    Raw section definitions.
	 * @param array<string, string>            $prefill     Values the checkout starts with, by field.
	 * @return array<string, mixed> The field array to return from the filter.
	 */
	public function apply( array $fields, array $definitions, array $sections = array(), array $prefill = array() ): array {
		$this->report  = array();
		$this->prefill = $prefill;

		$locations = $this->locations( $sections );
		$ordered   = $this->order( $this->collection_definitions( $definitions ), $locations );
		$wides     = array();

		foreach ( $ordered as $definition ) {
			if ( ! $definition->is_enabled() ) {
				continue;
			}

			$section = $this->section_for( $definition, $locations );
			$width   = $this->width( $definition );

			// An empty or unrenderable type is reported, not guessed at. The
			// validator rejects an unregistered type, so reaching here means a
			// registered type this adapter has no rendering for.
			if ( ! self::can_render( $definition->type() ) ) {
				$this->report[] = array(
					'field'  => $definition->id(),
					'type'   => $definition->type(),
					'level'  => 'skipped',
					'reason' => sprintf(
						'The classic checkout has no rendering for the type "%s", so the field was not added rather than shown as something else.',
						$definition->type()
					),
				);

				continue;
			}

			if ( isset( self::DEGRADED[ $definition->type() ] ) ) {
				$this->report[] = array(
					'field'  => $definition->id(),
					'type'   => $definition->type(),
					'level'  => 'degraded',
					'reason' => self::DEGRADED[ $definition->type() ],
				);
			}

			$classes = $this->classes( $width, $section, $wides );

			if ( $this->is_core_override( $definition ) ) {
				$fields = $this->apply_to_core( $fields, $definition, $section, $classes );

				continue;
			}

			$fields = $this->add_custom( $fields, $definition, $section, $classes );
		}

		return $fields;
	}

	/**
	 * Keeps display-only sections out of the checkout renderer.
	 *
	 * A section in an administrative or customer destination is a grouping for a
	 * projection, never an instruction to collect another value at checkout.
	 *
	 * @param array<int, array<string, mixed>> $definitions Raw field definitions.
	 * @return array<int, array<string, mixed>> Definitions eligible for collection.
	 */
	private function collection_definitions( array $definitions ): array {
		// Fields created for Minha Conta have a customer-owned collection surface.
		// They may be linked to the account form, but must never become checkout
		// inputs merely because a classic adapter sees their section identifier.
		return array_values(
			array_filter(
				$definitions,
				static function ( $raw ): bool {
					if ( ! is_array( $raw ) ) {
						return false;
					}

					return 'my_account' !== FieldDefinition::from_array( $raw )->collection_surface();
				}
			)
		);
	}

	/**
	 * Whether a definition overrides a field WooCommerce owns.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return bool
	 */
	private function is_core_override( FieldDefinition $definition ): bool {
		return 'core' === $definition->origin();
	}

	/**
	 * Section identifiers to their logical location.
	 *
	 * @param array<int, array<string, mixed>> $sections Raw sections.
	 * @return array<string, string>
	 */
	private function locations( array $sections ): array {
		$locations = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'] ) ) {
				continue;
			}

			$locations[ (string) $section['id'] ] = isset( $section['location'] )
				? (string) $section['location']
				: 'order';
		}

		return $locations;
	}

	/**
	 * The WooCommerce section a definition belongs to.
	 *
	 * A field may name a location directly, which is what adopting a WooCommerce
	 * field does, or name a declared section, whose location decides.
	 *
	 * @param FieldDefinition       $definition Definition.
	 * @param array<string, string> $locations Section to location.
	 * @return string
	 */
	private function section_for( FieldDefinition $definition, array $locations ): string {
		$section = (string) ( $definition->to_array()['section'] ?? 'order' );

		if ( isset( self::SECTION_MAP[ $section ] ) ) {
			return self::SECTION_MAP[ $section ];
		}

		$location = $locations[ $section ] ?? 'order';

		return self::SECTION_MAP[ $location ] ?? 'order';
	}

	/**
	 * Definitions in the order they should be rendered.
	 *
	 * Ordered by section and then by position, so widths and priorities are
	 * assigned in the order the merchant arranged.
	 *
	 * @param array<int, array<string, mixed>> $definitions Raw definitions.
	 * @param array<string, string>            $locations   Section to location.
	 * @return array<int, FieldDefinition>
	 */
	private function order( array $definitions, array $locations ): array {
		$prepared = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$section    = $this->section_for( $definition, $locations );

			$prepared[] = array(
				'section'    => $section,
				'position'   => $definition->position(),
				'definition' => $definition,
			);
		}

		usort(
			$prepared,
			static function ( array $a, array $b ): int {
				$by_section = strcmp( $a['section'], $b['section'] );

				if ( 0 !== $by_section ) {
					return $by_section;
				}

				$by_position = $a['position'] <=> $b['position'];

				return 0 !== $by_position
					? $by_position
					: strcmp( $a['definition']->id(), $b['definition']->id() );
			}
		);

		return array_map(
			static function ( array $entry ): FieldDefinition {
				return $entry['definition'];
			},
			$prepared
		);
	}

	/**
	 * Desktop width of a definition, clamped to the grid.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return int
	 */
	private function width( FieldDefinition $definition ): int {
		$layout = $definition->layout();
		$width  = isset( $layout['desktop'] ) ? (int) $layout['desktop'] : 12;

		return max( 1, min( 12, $width ) );
	}

	/**
	 * The row classes for a width.
	 *
	 * WooCommerce's own grid only knows full width and halves, so halves use its
	 * classes and alternate first and last within a section. Any other width gets
	 * a Suite class: section 8 puts widths under "CSS escopado" rather than
	 * promising the plugin controls the template's grid.
	 *
	 * @param int      $width   Desktop width.
	 * @param string   $section WooCommerce section.
	 * @param string[] $wides   Row classes assigned so far, by section.
	 * @return array<int, string>
	 */
	private function classes( int $width, string $section, array &$wides ): array {
		if ( 12 === $width ) {
			return array( 'form-row-wide' );
		}

		if ( 6 === $width ) {
			$next = ( $wides[ $section ] ?? 0 ) % 2 === 0 ? 'form-row-first' : 'form-row-last';

			$wides[ $section ] = ( $wides[ $section ] ?? 0 ) + 1;

			return array( $next );
		}

		return array( 'form-row-wide', 'wccs-col-' . $width );
	}

	/**
	 * Modifies a field WooCommerce owns, touching presentation only.
	 *
	 * Everything else in the array is preserved by not being read. That is the
	 * whole of "campos core mantêm contratos": the adapter cannot break a contract
	 * it never assigns.
	 *
	 * @param array<string, mixed> $fields     Field array.
	 * @param FieldDefinition      $definition Definition.
	 * @param string               $section    WooCommerce section.
	 * @param array<int, string>   $classes    Row classes.
	 * @return array<string, mixed>
	 */
	private function apply_to_core( array $fields, FieldDefinition $definition, string $section, array $classes ): array {
		$id = $definition->id();

		if ( ! isset( $fields[ $section ][ $id ] ) || ! is_array( $fields[ $section ][ $id ] ) ) {
			// A core override whose field is gone is an incompatibility the admin
			// reports; there is nothing to modify here.
			return $fields;
		}

		if ( '' !== $definition->label() ) {
			$fields[ $section ][ $id ]['label'] = $definition->label();
		}

		if ( '' !== $definition->description() ) {
			$fields[ $section ][ $id ]['description'] = $definition->description();
		}

		$placeholder = $this->setting( $definition, 'placeholder' );

		if ( is_string( $placeholder ) && '' !== $placeholder ) {
			$fields[ $section ][ $id ]['placeholder'] = $placeholder;
		}

		if ( array() !== $classes ) {
			$fields[ $section ][ $id ]['class'] = $classes;
		}

		if ( $definition->position() > 0 ) {
			$fields[ $section ][ $id ]['priority'] = $definition->position();
		}

		return $fields;
	}

	/**
	 * Adds a field the schema defines.
	 *
	 * @param array<string, mixed> $fields     Field array.
	 * @param FieldDefinition      $definition Definition.
	 * @param string               $section    WooCommerce section.
	 * @param array<int, string>   $classes    Row classes.
	 * @return array<string, mixed>
	 */
	private function add_custom( array $fields, FieldDefinition $definition, string $section, array $classes ): array {
		// A type this plugin knows is rendered by its own entry. A type another plugin
		// contributed is rendered by the control it declared — and the blind fallback to
		// text is what happened before WCCS-058: a membership code became a text box with
		// nobody told, which is the same silent loss the migration task refuses.
		$declared = self::control_for( $definition->type() );

		$type = self::TYPE_MAP[ $definition->type() ] ?? ( '' !== $declared ? $declared : 'text' );

		if ( ! isset( $fields[ $section ] ) || ! is_array( $fields[ $section ] ) ) {
			$fields[ $section ] = array();
		}

		$field = array(
			'type'     => $type,
			'label'    => $definition->label(),
			'required' => $definition->is_required(),
			'class'    => $classes,
			'priority' => $definition->position() > 0 ? $definition->position() : 100,
		);

		$description = $definition->description();

		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		$placeholder = $this->setting( $definition, 'placeholder' );

		if ( is_string( $placeholder ) && '' !== $placeholder ) {
			$field['placeholder'] = $placeholder;
		}

		$options = $definition->options();

		if ( array() !== $options ) {
			$field['options'] = $options;
		}

		$max_length = $this->setting( $definition, 'maxLength' );

		// The client half has to be able to tell the Suite's fields apart from
		// every other field on the form, and the identifier the merchant chose is
		// not a pattern anything could match on. A data attribute is how the
		// server says which fields are its own, and it travels with the fragment
		// if WooCommerce ever replaces it.
		//
		// Only a custom field is marked. A field WooCommerce owns is WooCommerce's
		// to re-render and to repopulate, and marking it would invite the client
		// half to restore a value that the platform is already responsible for.
		$field['custom_attributes'] = array( self::FIELD_ATTRIBUTE => $definition->id() );

		if ( is_int( $max_length ) && $max_length > 0 ) {
			$field['custom_attributes']['maxlength'] = $max_length;
		}

		$default = $this->setting( $definition, 'default' );

		// The customer's own value comes first, and only for a field that asked for it (§7.7,
		// §10.4). The type's configured default is what a field falls back to when the store has
		// nothing to prefill with — not a second opinion about the same value.
		$prefilled = $this->prefill[ $definition->id() ] ?? '';

		if ( $definition->prefills_checkout() && is_string( $prefilled ) && '' !== $prefilled ) {
			$field['default'] = $prefilled;
		} elseif ( is_string( $default ) && '' !== $default ) {
			$field['default'] = $default;
		}

		$fields[ $section ][ $definition->id() ] = $field;

		return $fields;
	}

	/**
	 * One declared setting, or null.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @param string          $name       Setting name.
	 * @return mixed
	 */
	private function setting( FieldDefinition $definition, string $name ): mixed {
		$settings = $definition->settings();

		return $settings[ $name ] ?? null;
	}
}
