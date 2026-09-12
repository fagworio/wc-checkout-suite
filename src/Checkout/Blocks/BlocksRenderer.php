<?php
/**
 * Blocks checkout: the fields this plugin renders itself.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Blocks;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Validation\MaskRegistry;

/**
 * Publishes the fields the Blocks checkout cannot render natively.
 *
 * The native adapter registers what the platform can draw and refuses the rest with
 * a reason. This class is the other half of that sentence: it takes the fields the
 * native API refuses *because they need a component of ours* — and only those — and
 * publishes everything a component needs to draw them.
 *
 * What travels is a payload, not a promise. Each entry carries the label, the type,
 * the options a choice field needs, whether it is required, and the mask to apply
 * when the field is a masked preset. The components themselves are the bundle built
 * from `resources/blocks/`, and they are given this payload through one inline
 * script written before the bundle, exactly as the classic checkout is given its
 * masks and rules.
 *
 * Three things are refused here as well, and for the same reasons they are refused
 * on the native side: a field whose section is not published, a field whose storage
 * the platform does not perform, and a field with no mode at all. A component that
 * rendered any of them would draw a field whose value goes nowhere — and a field
 * whose value goes nowhere is worse than a missing field, because the customer
 * fills it in.
 *
 * The mask travels as the mask's own definition, read from the registry, and not as
 * an identifier the browser would have to resolve: what the store published is what
 * the browser applies.
 *
 * @see \ROADMAP.md sections 8 and 9
 */
final class BlocksRenderer {

	/**
	 * Script handle of the Blocks bundle.
	 */
	public const SCRIPT_HANDLE = 'wc-checkout-suite-blocks';

	/**
	 * Handle of the stylesheet that presents the fields on the Blocks checkout.
	 */
	public const STYLE_HANDLE = 'wccs-blocks-presentation';

	/**
	 * The stylesheet's file, relative to the plugin root.
	 */
	public const STYLE_FILE = 'resources/blocks/presentation.css';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether this request is a checkout the Blocks rendering is for.
	 *
	 * The opposite question to the classic adapter's: there, a Blocks page is not
	 * this bundle's business; here it is the only business there is. Detected the
	 * same way, from the page WooCommerce has configured, because `is_checkout()` is
	 * true for both checkouts and a bundle that ran on the wrong one would render
	 * fields into a form that does not exist.
	 *
	 * @return bool
	 */
	public static function is_blocks_checkout(): bool {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		$page = get_post( wc_get_page_id( 'checkout' ) );

		return $page instanceof \WP_Post && has_block( 'woocommerce/checkout', $page );
	}

	/**
	 * The fields that need a component of ours.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, report: array<int, array{field: string, code: string, reason: string}>}
	 */
	public static function fields(): array {
		$document = PublishedDocument::read();
		$masks    = \WCCheckoutSuite\Domain\Registries::instance()->masks();

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
		$report = array();

		foreach ( $document->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();
			$stored     = $definition->to_array();
			$type       = $definition->type();

			if ( ! $definition->is_enabled() ) {
				continue;
			}

			if ( 'controlled' !== BlocksAdapter::mode_for( $stored ) ) {
				// Native fields are registered by the other half, and a restricted
				// one has no renderer anywhere: neither is this class's business.
				// A masked text field is the exception the adapter resolves, and it
				// arrives here as a controlled one.
				continue;
			}

			if ( ! BlocksAdapter::storage_is_representable( $stored ) ) {
				$report[] = self::entry(
					$id,
					'storage_not_native',
					sprintf(
						'A field rendered by this plugin still stores its value on the order, and this one declares the "%s" scope.',
						(string) ( $stored['storage']['scope'] ?? '(none)' )
					)
				);

				continue;
			}

			$section  = (string) $stored['section'];
			$location = $sections[ $section ] ?? '';

			if ( '' === $location ) {
				$report[] = self::entry(
					$id,
					'unknown_location',
					sprintf( 'The section "%s" is not in the published document, so where this field belongs is not known.', $section )
				);

				continue;
			}

			$entry = array(
				'id'       => $definition->integration_id(),
				'name'     => $id,
				'label'    => $definition->label(),
				'type'     => $type,
				'location' => $location,
				'required' => $definition->is_required(),
				'section'  => $section,
				// The hidden-value policy travels because the page applies it too:
				// a field a rule hides either keeps what the customer typed or
				// loses it, and the two checkouts have to answer alike. The server
				// re-applies it when the order is placed, so this is the page
				// agreeing with the store rather than deciding for it.
				'policy'   => (string) $stored['hidden_value_policy'],
			);

			if ( '' !== $definition->description() ) {
				$entry['description'] = $definition->description();
			}

			$options = self::options( $stored );

			if ( in_array( $type, array( 'radio', 'multiselect', 'checkbox-group' ), true ) ) {
				if ( array() === $options ) {
					$report[] = self::entry( $id, 'no_options', 'A choice field with no options has nothing to choose from.' );

					continue;
				}

				$entry['options'] = $options;
			}

			$mask = self::mask( $stored, $masks );

			if ( null !== $mask ) {
				$entry['mask'] = $mask;
			}

			$fields[] = $entry;
		}

		return array(
			'fields' => $fields,
			'report' => $report,
		);
	}

	/**
	 * Enqueues the bundle and its payload, when there are fields to render.
	 *
	 * The two decisions can be handed in so the positive path is testable on a store
	 * whose checkout page is not a Blocks page, which is the same reason the classic
	 * assets take theirs.
	 *
	 * @param bool|null                                                                                                                    $is_blocks Whether this is a Blocks checkout. Null asks the request.
	 * @param array{fields: array<int, array<string, mixed>>, report: array<int, array{field: string, code: string, reason: string}>}|null $payload Prepared payload. Null builds it.
	 * @return void
	 */
	public static function enqueue( ?bool $is_blocks = null, ?array $payload = null ): void {
		$is_blocks = $is_blocks ?? self::is_blocks_checkout();
		$payload   = $payload ?? self::fields();

		if ( ! $is_blocks || array() === $payload['fields'] ) {
			return;
		}

		// The presentation first, and for the same reason the classic checkout gets
		// it first: the stylesheet is what makes the region line up, and the bundle is
		// an improvement on a checkout that already works without it.
		$wccs_settings = 'WCCheckoutSuite\\Domain\\Settings\\CheckoutSettings';

		if ( $wccs_settings::presents( $wccs_settings::offered_gateways() ) ) {
			\WCCheckoutSuite\Checkout\Presentation::enqueue( self::STYLE_HANDLE, self::STYLE_FILE );
		}

		$bundle = 'build/blocks/index.js';

		if ( ! is_readable( WCCS_PLUGIN_DIR . $bundle ) ) {
			return;
		}

		$asset        = self::asset_manifest();
		$dependencies = $asset['dependencies'];

		// The Blocks checkout API is a runtime global that WooCommerce registers,
		// and the payload is read by our bundle before it uses that API. Declaring
		// the dependency is what guarantees the order without importing a package
		// this plugin does not depend on.
		if ( ! in_array( 'wc-blocks-checkout', $dependencies, true ) && wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			$dependencies[] = 'wc-blocks-checkout';
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WCCS_PLUGIN_URL . $bundle,
			$dependencies,
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
			'window.wccsBlocks = ' . wp_json_encode(
				array(
					'fields' => $payload['fields'],
					'report' => $payload['report'],
				)
			) . ';',
			'before'
		);

		/**
		 * Reports what the Blocks renderer could not render.
		 *
		 * The native adapter reports its refusals through its own action; this is
		 * the half that renders, and a field it cannot draw is reported here rather
		 * than left as a field that silently is not on the checkout.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string>                                              $registered Identifiers registered.
		 * @param array<int, array{field: string, code: string, reason: string}> $report     Report entries.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the prefixed name, kept in one place.
		do_action( BlocksCheckout::REPORT_ACTION, array_column( $payload['fields'], 'id' ), $payload['report'] );
	}

	/**
	 * The options a choice field offers.
	 *
	 * @param array<string, mixed> $stored Stored definition.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function options( array $stored ): array {
		$settings = isset( $stored['settings'] ) && is_array( $stored['settings'] ) ? $stored['settings'] : array();
		$declared = isset( $settings['options'] ) && is_array( $settings['options'] ) ? $settings['options'] : array();
		$options  = array();

		foreach ( $declared as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
				continue;
			}

			$options[] = array(
				'value' => (string) $option['value'],
				'label' => isset( $option['label'] ) ? (string) $option['label'] : (string) $option['value'],
			);
		}

		return $options;
	}

	/**
	 * The mask a field applies, if it declares one.
	 *
	 * @param array<string, mixed> $stored Stored definition.
	 * @param MaskRegistry         $masks  Mask registry.
	 * @return array{key: string, version: int, definition: mixed}|null
	 */
	private static function mask( array $stored, MaskRegistry $masks ): ?array {
		$reference = isset( $stored['mask'] ) && is_array( $stored['mask'] ) ? $stored['mask'] : null;

		if ( null === $reference || ! isset( $reference['key'] ) || ! method_exists( $masks, 'mask' ) ) {
			return null;
		}

		$mask = $masks->mask( (string) $reference['key'] );

		if ( null === $mask || ! method_exists( $mask, 'definition' ) ) {
			return null;
		}

		return array(
			'key'        => (string) $mask->key(),
			'version'    => (int) $mask->version(),
			'definition' => $mask->definition(),
		);
	}

	/**
	 * One report entry.
	 *
	 * @param string $field  Field identifier.
	 * @param string $code   Stable code.
	 * @param string $reason What happened, in words.
	 * @return array{field: string, code: string, reason: string}
	 */
	private static function entry( string $field, string $code, string $reason ): array {
		return array(
			'field'  => $field,
			'code'   => $code,
			'reason' => $reason,
		);
	}

	/**
	 * The build manifest produced by the bundler.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	public static function asset_manifest(): array {
		$defaults = array(
			'dependencies' => array(),
			'version'      => (string) WCCS_VERSION,
		);

		$path = WCCS_PLUGIN_DIR . 'build/blocks/index.asset.php';

		if ( ! is_readable( $path ) ) {
			return $defaults;
		}

		$manifest = require $path;

		if ( ! is_array( $manifest ) ) {
			return $defaults;
		}

		return array(
			'dependencies' => isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] ) ? array_values( $manifest['dependencies'] ) : array(),
			'version'      => isset( $manifest['version'] ) ? (string) $manifest['version'] : $defaults['version'],
		);
	}
}
