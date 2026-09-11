<?php
/**
 * Classic checkout assets.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

/**
 * Loads the checkout bundle on the classic checkout and nowhere else.
 *
 * The bundle carries the component lifecycle: it starts each component once per
 * element and re-enters only for elements WooCommerce's refresh actually
 * replaced. It is delivered here rather than in F05 because the lifecycle is
 * what F05's components are written against, and a contract that arrives with
 * its first consumer is a contract shaped by that consumer.
 *
 * Two gates, and both matter. The screen gate keeps the plugin off every other
 * page of the store; the content gate means a store that does not use the Suite
 * — or whose published schema has nothing the classic checkout can render —
 * ships no script at all. A storefront request pays for nothing it does not use.
 *
 * @see \ROADMAP.md sections 9 and 16
 */
final class ClassicAssets {

	/**
	 * Handle of the checkout bundle.
	 */
	public const SCRIPT_HANDLE = 'wccs-checkout';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the bundle belongs on this request.
	 *
	 * Extracted so the decision is testable without a checkout page, which this
	 * store does not have: the gate is two booleans, and a mistake in it would
	 * either leak the plugin across the storefront or silently disable the
	 * checkout half.
	 *
	 * @param bool $is_checkout Whether the request is the classic checkout.
	 * @param bool $has_fields  Whether the published schema has a renderable field.
	 * @return bool
	 */
	public static function should_enqueue( bool $is_checkout, bool $has_fields ): bool {
		return $is_checkout && $has_fields;
	}

	/**
	 * Enqueues the bundle when the checkout needs it.
	 *
	 * The two decisions can be handed in so the positive path is testable without
	 * a classic checkout page, which this store does not have. The administration
	 * assets take a screen id for the same reason: a gate nobody can exercise is a
	 * gate nobody has checked.
	 *
	 * @param bool|null $is_checkout Whether this is the classic checkout. Null asks the request.
	 * @param bool|null $has_fields  Whether there is something to initialise. Null asks the schema.
	 * @return void
	 */
	public static function enqueue( ?bool $is_checkout = null, ?bool $has_fields = null ): void {
		$is_checkout = $is_checkout ?? self::is_classic_checkout();
		$has_fields  = $has_fields ?? self::has_renderable_fields();

		if ( ! self::should_enqueue( $is_checkout, $has_fields ) ) {
			return;
		}

		$bundle = 'build/checkout/index.js';

		// A bundle that was never built is not enqueued. Enqueuing a URL that is
		// not there puts a 404 in the checkout and changes nothing else, and what
		// the bundle does — keeping typed values across a refresh — is an
		// improvement on top of a checkout that already works without it. The
		// build requirement itself is recorded in ADR-0009.
		if ( ! is_readable( WCCS_PLUGIN_DIR . $bundle ) ) {
			return;
		}

		$asset = self::asset_manifest();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WCCS_PLUGIN_URL . $bundle,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			WCCS_TEXT_DOMAIN,
			WCCS_PLUGIN_DIR . 'languages'
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wccsCheckout = ' . wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * The masks the bundle has to apply, keyed by field identifier.
	 *
	 * Built from the published document rather than from the registry, so what the
	 * browser applies is what the store actually published: a mask on a disabled
	 * field is not sent, and a field the classic checkout cannot render does not
	 * appear at all.
	 *
	 * Only the definition travels, not the mask's whole record. The bundle needs to
	 * build the mask and needs nothing else, and a definition that cannot be
	 * resolved is left out rather than sent as something the client would have to
	 * guess at.
	 *
	 * The validation endpoint travels with it: its address, the nonce that keeps
	 * it from being an oracle any page can call, and the revision this page was
	 * rendered from. All three come from the server because the namespace can be
	 * renamed and the revision changes, and a bundle that hardcoded either would
	 * be wrong the first time one of them moved.
	 *
	 * @return array{masks: array<string, array{key: string, version: int, definition: string|array<mixed>}>, validation: array{url: string, nonce: string, revision: int}}
	 */
	public static function bootstrap_data(): array {
		$masks      = \WCCheckoutSuite\Domain\Registries::instance()->masks();
		$registered = array();

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $raw );
			$reference  = $definition->to_array()['mask'];

			if ( ! $definition->is_enabled() || ! ClassicAdapter::can_render( $definition->type() ) ) {
				continue;
			}

			if ( ! is_array( $reference ) || ! isset( $reference['key'] ) ) {
				continue;
			}

			$mask = $masks->mask( (string) $reference['key'] );

			if ( null === $mask ) {
				continue;
			}

			$registered[ $definition->id() ] = array(
				'key'        => $mask->key(),
				'version'    => $mask->version(),
				'definition' => $mask->definition(),
			);
		}

		return array(
			'masks'      => $registered,
			'rules'      => self::rules(),
			'validation' => array(
				'url'      => rest_url(
					\WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace()
						. \WCCheckoutSuite\Http\Checkout\ValidationController::ROUTE_VALIDATE
				),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'revision' => PublishedDocument::read()->revision(),
			),
		);
	}

	/**
	 * Whether this request is the checkout the adapter renders.
	 *
	 * A Blocks checkout is not this bundle's business: it renders on the client
	 * from the Store API, so a classic script would run against a form that does
	 * not exist. `is_checkout()` is true for both, which is why the block check
	 * is explicit rather than assumed.
	 *
	 * @return bool
	 */
	private static function is_classic_checkout(): bool {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		if ( function_exists( 'has_block' ) ) {
			$page = get_post( wc_get_page_id( 'checkout' ) );

			if ( $page instanceof \WP_Post && has_block( 'woocommerce/checkout', $page ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the published schema has a field the classic checkout can render.
	 *
	 * The same question the adapter answers when it draws the form, asked here so
	 * the bundle is not downloaded for a schema that produces nothing to
	 * initialise.
	 *
	 * @return bool
	 */
	public static function has_renderable_fields(): bool {
		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $raw );

			if ( $definition->is_enabled() && ClassicAdapter::can_render( $definition->type() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The validators each field declares, with the code and message a failure
	 * produces.
	 *
	 * The wording is not repeated in JavaScript: it is written once, beside the
	 * rule, and travels with it. A field whose validator the browser does not
	 * implement is one the checkout has to ask the server about, which is what
	 * section 10 means by asking only when the answer needs the server.
	 *
	 * Only enabled fields the classic checkout can render are sent, for the same
	 * reason the masks are: there is no form field to put an answer next to.
	 *
	 * @return array<string, array<int, array{key: string, code: string, message: string}>>
	 */
	private static function rules(): array {
		$validators = \WCCheckoutSuite\Domain\Registries::instance()->validators();
		$rules      = array();

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $raw );

			if ( ! $definition->is_enabled() || ! ClassicAdapter::can_render( $definition->type() ) ) {
				continue;
			}

			$declared = array();

			foreach ( $definition->validators() as $reference ) {
				$key = is_array( $reference ) && isset( $reference['key'] ) ? (string) $reference['key'] : '';

				if ( '' === $key ) {
					continue;
				}

				$validator = $validators->validator( $key );

				if ( ! $validator instanceof \WCCheckoutSuite\Domain\Validation\FailureMessageInterface ) {
					// A validator registered as a closure, or one that simply does
					// not describe itself, has no wording to publish. The field is
					// still checked on the server; the browser has nothing to say
					// about it in advance.
					continue;
				}

				$declared[] = array(
					'key'     => $key,
					'code'    => $validator->failure_code(),
					'message' => $validator->failure_message(),
				);
			}

			if ( array() !== $declared ) {
				$rules[ $definition->id() ] = $declared;
			}
		}

		return $rules;
	}

	/**
	 * Reads the build manifest produced by the bundler.
	 *
	 * Dependencies and the cache-busting version come from the build, never from
	 * a hand maintained list that would drift the first time an import changes.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	public static function asset_manifest(): array {
		$defaults = array(
			'dependencies' => array(),
			'version'      => WCCS_VERSION,
		);

		$path = WCCS_PLUGIN_DIR . 'build/checkout/index.asset.php';

		if ( ! is_readable( $path ) ) {
			return $defaults;
		}

		$manifest = require $path;

		if ( ! is_array( $manifest ) ) {
			return $defaults;
		}

		return array(
			'dependencies' => isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] )
				? array_values( array_map( 'strval', $manifest['dependencies'] ) )
				: array(),
			'version'      => isset( $manifest['version'] ) ? (string) $manifest['version'] : WCCS_VERSION,
		);
	}
}
