<?php
/**
 * The upload field, as the classic checkout renders it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Uploads\UploadsEnvironment;

/**
 * Renders a file field in the classic checkout.
 *
 * WooCommerce's field API has no file type, and it does not need one: a type it does
 * not know is passed to the `woocommerce_form_field_{type}` filter, which is the
 * documented way for an extension to render one. That is the seam used here, and it
 * is why the classic adapter can translate a `file` field at all.
 *
 * What is rendered is deliberately plain: a labelled `<input type="file">` with the
 * markers the bundle looks for, and a sentence saying uploads are unavailable when
 * this store cannot keep a file private. The component builds the list, the progress
 * and the buttons on top of it, and a checkout without JavaScript still gets the
 * input and the sentence — an empty field and an honest reason rather than a control
 * that silently does nothing.
 *
 * @see ROADMAP.md section 12
 */
final class ClassicUploads {

	/**
	 * Registers the renderer.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_form_field_file', array( self::class, 'render' ), 10, 4 );
	}

	/**
	 * Answers the field markup for a file field.
	 *
	 * @param mixed $field Markup so far, which for this type is empty.
	 * @param mixed $key   Field key.
	 * @param mixed $args  Field arguments, whose shape is the caller's to decide —
	 *                     this is a public filter and not a private method.
	 * @param mixed $value Current value.
	 * @return string
	 */
	public static function render( $field, $key, $args, $value ): string {
		unset( $value );

		if ( is_string( $field ) && '' !== trim( $field ) ) {
			// Something else already rendered this type. Answering with our own markup
			// would replace another plugin's field rather than extend it.
			return $field;
		}

		$args  = is_array( $args ) ? $args : array();
		$label = isset( $args['label'] ) ? (string) $args['label'] : '';
		$id    = 'wccs-upload-' . sanitize_html_class( (string) $key );
		// `class` reaches this filter as a string when the field array carried one and
		// as an array when it carried several, so it is read as either rather than
		// assumed: the filter is a public extension point and its shape is whatever the
		// caller put there.
		$class   = isset( $args['class'] ) ? $args['class'] : array();
		$class   = is_array( $class ) ? $class : preg_split( '/\s+/', (string) $class );
		$classes = array( 'form-row' );

		foreach ( (array) $class as $name ) {
			$classes[] = sanitize_html_class( (string) $name );
		}

		$required = ! empty( $args['required'] );
		$multiple = ! empty( $args['wccs_multiple'] );
		$accept   = isset( $args['wccs_accept'] ) && is_array( $args['wccs_accept'] )
			? array_filter( array_map( static fn( $extension ): string => '.' . ltrim( sanitize_key( (string) $extension ), '.' ), $args['wccs_accept'] ) )
			: array();

		$input = sprintf(
			'<input type="file" class="wccs-upload__input" name="%1$s" id="%2$s" data-wccs-field="%3$s" data-wccs-upload="%4$s"%5$s />',
			esc_attr( (string) $key ),
			esc_attr( $id ),
			esc_attr( (string) $key ),
			$multiple ? 'true' : 'false',
			( $required ? ' required aria-required="true"' : '' )
			. ( array() !== $accept ? ' accept="' . esc_attr( implode( ',', $accept ) ) . '"' : '' )
			. ( $multiple ? ' multiple' : '' )
		);

		$notice = '';

		if ( ! UploadsEnvironment::enabled() ) {
			$notice = sprintf(
				'<span class="wccs-upload__unavailable" role="alert">%s</span>',
				esc_html( UploadsEnvironment::reason() )
			);
		}

		return sprintf(
			'<p class="%1$s" id="%2$s_field"><label class="wccs-upload__label" for="%2$s">%3$s</label>%4$s%5$s</p>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $id ),
			esc_html( $label ),
			$input,
			$notice
		);
	}
}
