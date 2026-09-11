<?php
/**
 * Store API extension carrying the controlled fields.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Blocks;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Publishes the controlled fields to the Store API, typed.
 *
 * The fields this plugin renders itself are not additional fields as far as
 * WooCommerce is concerned, so nothing carries their values to the server on its
 * own. This is the namespace that does: it extends the `checkout` endpoint of the
 * Store API with one property per field, and a typed property is what makes the
 * payload checkable — the route validates the shape before any of our code runs, so
 * a request that sends a list where a string belongs is refused by the platform and
 * never reaches the pipeline.
 *
 * The type declared for each field is the type of the value the *component*
 * produces, which is not the type the customer sees: a multi-select produces a list
 * of strings and a checkbox produces a boolean, and saying "string" for either of
 * them would make the schema a decoration.
 *
 * A field is published here only when it is renderable and its storage is native,
 * which is the same rule the renderer applies. The two read the same document and
 * the same classification, so a field cannot be drawn without being postable, or
 * postable without being drawn.
 *
 * @see \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::register_endpoint_data()
 * @see docs/api/checkout-extension-points.md
 */
final class StoreApiExtension {

	/**
	 * Namespace the values travel under.
	 */
	public const NAMESPACE_KEY = 'wc-checkoutsuite';

	/**
	 * Endpoint the data belongs to.
	 */
	public const ENDPOINT = 'checkout';

	/**
	 * Registers the extension.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'extend' ) );
	}

	/**
	 * Declares the namespace on the checkout endpoint.
	 *
	 * @return void
	 */
	public static function extend(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			// The Store API is not loaded, so there is nothing to extend. Reporting
			// is the adapter's job; this class only has to not fatal.
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => self::ENDPOINT,
				'namespace'       => self::NAMESPACE_KEY,
				'data_callback'   => array( self::class, 'data' ),
				'schema_callback' => array( self::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * The properties the namespace declares.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$properties = array();

		foreach ( self::fields() as $field ) {
			$properties[ $field['name'] ] = array(
				'description' => $field['label'],
				'type'        => self::json_type( (string) $field['type'] ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
			);

			if ( 'array' === self::json_type( (string) $field['type'] ) ) {
				$properties[ $field['name'] ]['items'] = array( 'type' => 'string' );
			}
		}

		return $properties;
	}

	/**
	 * The values the endpoint answers with.
	 *
	 * Reading is deliberately empty: what a controlled field holds lives in the
	 * page until the order is placed, and answering with a value the server does not
	 * have would be inventing one. The namespace exists so the *client* can post,
	 * and this is the read half saying it has nothing to say.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$values = array();

		foreach ( self::fields() as $field ) {
			$values[ $field['name'] ] = 'array' === self::json_type( (string) $field['type'] ) ? array() : '';
		}

		return $values;
	}

	/**
	 * The fields the namespace carries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		$document = PublishedDocument::read();

		$sections = array();

		foreach ( $document->sections() as $raw ) {
			if ( is_array( $raw ) && isset( $raw['id'] ) ) {
				$location = isset( $raw['location'] ) ? (string) $raw['location'] : '';

				if ( '' !== $location && '' !== BlocksAdapter::native_location( $location ) ) {
					$sections[ (string) $raw['id'] ] = BlocksAdapter::native_location( $location );
				}
			}
		}

		$fields = array();

		foreach ( $document->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$stored     = $definition->to_array();

			if ( ! $definition->is_enabled() ) {
				continue;
			}

			if ( 'controlled' !== BlocksAdapter::mode_for( $stored ) ) {
				continue;
			}

			if ( ! BlocksAdapter::storage_is_representable( $stored ) ) {
				continue;
			}

			if ( ! isset( $sections[ (string) $stored['section'] ] ) ) {
				continue;
			}

			// The mask is deliberately absent from the contract: it is a hint for
			// the browser, and what the server validates is the canonical value its
			// own normalizer produces from whatever the customer typed.
			$fields[] = array(
				'name'  => $definition->id(),
				'label' => $definition->label(),
				'type'  => $definition->type(),
			);
		}

		return $fields;
	}

	/**
	 * The JSON Schema type of the value a component produces.
	 *
	 * @param string $type Suite field type.
	 * @return string
	 */
	public static function json_type( string $type ): string {
		if ( in_array( $type, array( 'multiselect', 'checkbox-group' ), true ) ) {
			return 'array';
		}

		if ( 'checkbox' === $type ) {
			return 'boolean';
		}

		return 'string';
	}

	/**
	 * The values a request submitted for this namespace.
	 *
	 * Read from the request's own `extensions` map, which is where the Store API
	 * puts an extension's payload. Anything that is not the map this namespace
	 * declared is ignored rather than guessed at.
	 *
	 * @param mixed $request Store API request.
	 * @return array<string, mixed>
	 */
	public static function submitted( mixed $request ): array {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return array();
		}

		$extensions = $request->get_param( 'extensions' );

		if ( ! is_array( $extensions ) || ! isset( $extensions[ self::NAMESPACE_KEY ] ) || ! is_array( $extensions[ self::NAMESPACE_KEY ] ) ) {
			return array();
		}

		return $extensions[ self::NAMESPACE_KEY ];
	}

	/**
	 * The definitions a submission can carry values for, keyed by field id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$definitions = array();

		foreach ( self::fields() as $field ) {
			$id = (string) $field['name'];

			foreach ( PublishedDocument::read()->fields() as $raw ) {
				if ( is_array( $raw ) && isset( $raw['id'] ) && $id === (string) $raw['id'] ) {
					$definitions[ $id ] = $raw;
					break;
				}
			}
		}

		return $definitions;
	}
}
