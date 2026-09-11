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
		return isset( self::TYPE_MAP[ $type ] ) || isset( self::DEGRADED[ $type ] );
	}

	/**
	 * Applies the definitions to a WooCommerce field array.
	 *
	 * @param array<string, mixed>             $fields      Fields from `woocommerce_checkout_fields`.
	 * @param array<int, array<string, mixed>> $definitions Raw field definitions, published.
	 * @param array<int, array<string, mixed>> $sections    Raw section definitions.
	 * @return array<string, mixed> The field array to return from the filter.
	 */
	public function apply( array $fields, array $definitions, array $sections = array() ): array {
		$this->report = array();

		$locations = $this->locations( $sections );
		$ordered   = $this->order( $definitions, $locations );
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
	 * Whether a definition overrides a field WooCommerce owns.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return bool
	 */
	private function is_core_override( FieldDefinition $definition ): bool {
		return 'core' === ( $definition->to_array()['origin'] ?? '' );
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
		$type = self::TYPE_MAP[ $definition->type() ] ?? 'text';

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

		$options = $this->setting( $definition, 'options' );

		if ( is_array( $options ) ) {
			$map = array();

			foreach ( $options as $option ) {
				if ( is_array( $option ) && isset( $option['value'] ) ) {
					$map[ (string) $option['value'] ] = isset( $option['label'] )
						? (string) $option['label']
						: (string) $option['value'];
				}
			}

			if ( array() !== $map ) {
				$field['options'] = $map;
			}
		}

		$max_length = $this->setting( $definition, 'maxLength' );

		if ( is_int( $max_length ) && $max_length > 0 ) {
			$field['custom_attributes'] = array( 'maxlength' => $max_length );
		}

		$default = $this->setting( $definition, 'default' );

		if ( is_string( $default ) && '' !== $default ) {
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
