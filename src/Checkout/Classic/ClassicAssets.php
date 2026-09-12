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
	 * Handle of the stylesheet that presents the checkout.
	 */
	public const STYLE_HANDLE = 'wccs-checkout-presentation';

	/**
	 * The stylesheet's file, relative to the plugin root.
	 */
	public const STYLE_FILE = 'resources/checkout/presentation.css';

	/**
	 * The class the presentation is scoped to, put on the checkout body.
	 */
	public const SCOPE_CLASS = 'wccs-checkout';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Marks the checkout body, which is what scopes the presentation.
	 *
	 * Every selector in the stylesheet is written under `.wccs-checkout` so that no
	 * rule it contains can reach another page of the store. A scope the stylesheet
	 * expects and nothing emits is a stylesheet that never applies, and this plugin
	 * ships no template to emit it — so it is emitted here, by the same gate that
	 * delivers the stylesheet. The class is therefore present on exactly the requests
	 * that are given the presentation.
	 *
	 * @param array<int, string>|mixed $classes Body classes.
	 * @return array<int, string>|mixed
	 */
	public static function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		// Asked in this order on purpose: on every page of the store that is not the
		// checkout, the published schema is not read at all.
		if ( ! self::is_classic_checkout() ) {
			return $classes;
		}

		return self::add_scope( $classes, true, self::has_renderable_fields() );
	}

	/**
	 * The body classes with the scope added, when the checkout needs it.
	 *
	 * Extracted for the same reason the gate was: the decision is two booleans and a
	 * mistake in it would either leak the scope across the storefront or leave the
	 * presentation unscoped. Being an array function, it is also the half of this
	 * behaviour a unit test can exercise without a checkout page.
	 *
	 * @param array<int, string> $classes     Body classes.
	 * @param bool               $is_checkout Whether the request is the classic checkout.
	 * @param bool               $has_fields  Whether the published schema has a renderable field.
	 * @return array<int, string>
	 */
	public static function add_scope( array $classes, bool $is_checkout, bool $has_fields ): array {
		if ( ! self::should_enqueue( $is_checkout, $has_fields ) ) {
			return $classes;
		}

		if ( in_array( self::SCOPE_CLASS, $classes, true ) ) {
			return $classes;
		}

		$classes[] = self::SCOPE_CLASS;

		return $classes;
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

		// The presentation first, so a request that has both gets the layout before
		// the behaviour: the stylesheet is what makes the fields line up, and the
		// bundle is an improvement on a checkout that already works without it.
		self::enqueue_presentation();

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
	 * Styles the checkout the way the planning describes it.
	 *
	 * The tokens are enqueued first and declared as a dependency, because every
	 * value in the presentation comes from them: a stylesheet that loaded before the
	 * variables it uses would render the layout with its fallbacks, which is the
	 * kind of difference nobody notices until a merchant changes a token and nothing
	 * moves. That rule and the file itself are the shared `Presentation`'s business,
	 * because the Blocks checkout is given a presentation of its own and the order
	 * should not be two implementations that agree today.
	 *
	 * The stylesheet is served as a file rather than injected by the bundle. It is
	 * the same file the build does not have to touch, and a layout that arrives with
	 * the JavaScript is a layout a customer does not get until the bundle has run.
	 *
	 * @return void
	 */
	private static function enqueue_presentation(): void {
		\WCCheckoutSuite\Checkout\Presentation::enqueue( self::STYLE_HANDLE, self::STYLE_FILE );
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
	 * @return array{masks: array<string, array{key: string, version: int, definition: string|array<mixed>}>, payments: array{decisions: array<string, array{mode: string, withheld: array<int, string>}>, undecided: int, reason: string}, summary: array{label: string, show: string, hide: string}, validation: array{url: string, nonce: string, revision: int}}
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
			'conditions' => self::conditions(),
			'uploads'    => self::uploads(),
			'payments'   => self::payments(),
			'summary'    => array(
				'label' => __( 'Order summary', 'wc-checkoutsuite' ),
				'show'  => __( 'Show order summary', 'wc-checkoutsuite' ),
				'hide'  => __( 'Hide order summary', 'wc-checkoutsuite' ),
			),
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
	 * What this plugin is allowed to do to each gateway the store offers.
	 *
	 * The record is the homologation matrix, and the answer for a gateway nobody ran is
	 * `undecided` — published rather than hidden, because the difference between "we
	 * tested it" and "we have not looked" is the thing a merchant has to be able to see.
	 * The count travels with the decisions so a diagnostic surface can say how much of
	 * this store's payment area is covered by an observation and how much is not.
	 *
	 * @return array{decisions: array<string, array{mode: string, withheld: array<int, string>}>, undecided: int, reason: string}
	 */
	private static function payments(): array {
		$decisions = array();

		if ( function_exists( 'WC' ) ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
				if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
					continue;
				}

				$decision = \WCCheckoutSuite\Domain\Payments\PaymentMatrix::decide_for( $gateway );

				$decisions[ (string) $gateway->id ] = array(
					'mode'     => $decision['mode'],
					'withheld' => $decision['withheld'],
				);
			}
		}

		$undecided = 0;

		foreach ( $decisions as $decision ) {
			if ( \WCCheckoutSuite\Domain\Payments\PaymentMode::UNDECIDED === $decision['mode'] ) {
				++$undecided;
			}
		}

		return array(
			'decisions' => $decisions,
			'undecided' => $undecided,
			'reason'    => \WCCheckoutSuite\Domain\Payments\PaymentMode::reason( \WCCheckoutSuite\Domain\Payments\PaymentMode::UNDECIDED ),
		);
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
	 * What the upload component needs to talk to the endpoint.
	 *
	 * The address and the nonce come from the server for the same reason the
	 * validation endpoint's do: the namespace can be renamed and the nonce changes
	 * with the session, and a bundle that hardcoded either would be wrong the first
	 * time one of them moved. `available` travels too, so the component can say that
	 * uploads are off on this store instead of failing at the first attempt.
	 *
	 * @return array{url: string, nonce: string, available: bool, reason: string, maxBytes: int}
	 */
	private static function uploads(): array {
		return array(
			'url'       => rest_url(
				\WCCheckoutSuite\Http\Admin\SchemaController::rest_namespace()
					. \WCCheckoutSuite\Http\Checkout\UploadController::ROUTE_UPLOADS
			),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'available' => \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::enabled(),
			'reason'    => \WCCheckoutSuite\Domain\Uploads\UploadsEnvironment::reason(),
			'maxBytes'  => \WCCheckoutSuite\Domain\Uploads\UploadRules::DEFAULT_MAX_BYTES,
		);
	}

	/**
	 * The visibility rules the browser is allowed to decide.
	 *
	 * Only the rules whose every source the page owns travel. A rule that reads the
	 * cart or whether the customer is logged in is a rule only the server can
	 * answer, and section 11 says what happens to it: the server recomputes it with
	 * trusted context. Publishing it would invite the browser to answer a question
	 * it cannot answer, and the two answers would disagree in the direction that
	 * blocks an order — the browser hiding a field the server considers required.
	 *
	 * The policy travels with the rule because the browser applies it too: a hidden
	 * discard field is cleared, and a hidden preserve field keeps what the customer
	 * typed. Neither decision is the browser's to make on its own, and both are
	 * checked again on the server.
	 *
	 * @return array<string, array{policy: string, visible: array<string, mixed>}>
	 */
	private static function conditions(): array {
		$client    = \WCCheckoutSuite\Domain\Conditions\Sources::client_keys();
		$published = array();

		foreach ( PublishedDocument::read()->fields() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = \WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( $raw );

			if ( ! $definition->is_enabled() || ! ClassicAdapter::can_render( $definition->type() ) ) {
				continue;
			}

			$conditions = $definition->to_array()['conditions'];
			$visible    = isset( $conditions['visible'] ) && is_array( $conditions['visible'] ) ? $conditions['visible'] : array();

			if ( array() === $visible || ! self::is_client_answerable( $visible, $client ) ) {
				continue;
			}

			$published[ $definition->id() ] = array(
				'policy'  => (string) $definition->to_array()['hidden_value_policy'],
				'visible' => $visible,
			);
		}

		return $published;
	}

	/**
	 * Whether every source a rule reads is one the page can answer for.
	 *
	 * Walked over the raw rule rather than over a parsed tree on purpose: this
	 * decides what to *send*, and a rule that cannot be parsed is a rule that should
	 * not be sent. The validator refuses one on every write, so this is about a
	 * document that was damaged in storage, and the answer to damage is to leave the
	 * decision with the server.
	 *
	 * @param array<string, mixed> $node   Raw rule node.
	 * @param array<int, string>   $client Source keys the page owns.
	 * @return bool
	 */
	private static function is_client_answerable( array $node, array $client ): bool {
		$children = $node['all'] ?? $node['any'] ?? null;

		if ( is_array( $children ) ) {
			if ( array() === $children ) {
				return false;
			}

			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || ! self::is_client_answerable( $child, $client ) ) {
					return false;
				}
			}

			return true;
		}

		$source = isset( $node['source'] ) && is_string( $node['source'] ) ? $node['source'] : '';

		return '' !== $source && in_array( $source, $client, true );
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
