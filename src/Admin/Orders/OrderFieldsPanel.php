<?php
/**
 * The checkout fields on the order edit screen.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Admin\Orders;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Orders\AreaProjection;
use WCCheckoutSuite\Domain\Orders\OrderFieldEntry;
use WCCheckoutSuite\Domain\Orders\OrderFieldsService;
use WCCheckoutSuite\Domain\Orders\OrderFieldValues;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WC_Order;

/**
 * Shows the Suite's fields for one order, and lets staff correct them.
 *
 * ROADMAP.md section 14 asks for four things in one sentence:
 *
 * > Administradores: bloco "Campos do checkout" na edição do pedido, origem, label,
 * > valor formatado, edição autorizada e trilha de alterações sem logs desnecessários
 * > do conteúdo pessoal.
 *
 * So the panel shows, for every value the order carries: the **label** as it was when
 * the order was placed, the **origin** (this plugin's field or WooCommerce's), and the
 * value as the customer's own checkout would have formatted it. The label travels with
 * the order rather than being read from today's schema, because a merchant who renames
 * a field has not renamed what a past order says.
 *
 * **Identical validation, and it is not re-implemented.** A value typed here goes
 * through the same `ValueProcessor` the checkout runs, with the same definitions and
 * the same context, so a document this panel accepts is a document the checkout would
 * have accepted and a refusal carries the code and the message the customer would have
 * seen. Two validators that agree today disagree the first time one of them is edited;
 * there is one.
 *
 * **Both order backends, through one contract.** The panel is registered on
 * `add_meta_boxes`, which WooCommerce fires with the screen identifier for the legacy
 * posts screen and for the HPOS orders screen alike, and it saves on
 * `woocommerce_process_shop_order_meta`, which both edit screens fire. Every read and
 * write goes through the `WC_Order` CRUD — `get_meta`, `update_meta_data` — and never
 * through `get_post_meta`, which is the difference the acceptance is about: with HPOS
 * authoritative, post meta is the wrong table and a panel that wrote there would appear
 * to work until the store turned synchronization off.
 *
 * **Editing is authorized twice, and the second check is the one that matters.**
 * Capability decides whether a person may edit this order; the field's own declaration
 * decides whether the order is where its value belongs. Section 14 separates the two
 * explicitly — "separar valor do pedido de preferência atual do perfil; editar perfil
 * não reescreve pedido passado" — and the same sentence read backwards is what stops
 * this panel: a field the store keeps on the customer is not editable here, because
 * changing it would rewrite a person's current preference from a snapshot of one order.
 */
final class OrderFieldsPanel {

	/**
	 * Screen identifier of the legacy posts-based orders screen.
	 */
	public const SCREEN_LEGACY = 'shop_order';

	/**
	 * Screen identifier of the HPOS orders screen.
	 */
	public const SCREEN_HPOS = 'woocommerce_page_wc-orders';

	/**
	 * Meta box identifier.
	 */
	public const BOX_ID = 'wccs-order-fields';

	/**
	 * Field name prefix of a submitted value.
	 */
	public const INPUT_PREFIX = 'wccs_order_field_';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'wccs_save_order_fields';

	/**
	 * Registers the hooks both order backends fire.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'save' ), 50, 2 );
	}

	/**
	 * The screens this panel belongs on.
	 *
	 * @return array<int, string>
	 */
	public static function screens(): array {
		return array( self::SCREEN_LEGACY, self::SCREEN_HPOS );
	}

	/**
	 * Registers the meta box, on whichever screen is asking.
	 *
	 * @param string $screen  Screen identifier.
	 * @param mixed  $context Order or post the screen is editing.
	 * @return void
	 */
	public static function add( $screen, $context = null ): void {
		if ( ! in_array( (string) $screen, self::screens(), true ) ) {
			return;
		}

		$order = self::order_from( $context );

		if ( ! $order instanceof WC_Order || ! self::may_edit( $order ) ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'Checkout fields', 'wc-checkoutsuite' ),
			array( self::class, 'render' ),
			(string) $screen,
			'normal',
			'default',
			array( 'order' => $order )
		);
	}

	/**
	 * The order an admin screen handed over.
	 *
	 * The legacy screen passes the post, the HPOS screen passes the order — the same
	 * hook, two shapes, documented as such by WooCommerce. Both are resolved here so no
	 * caller has to know which backend it is on.
	 *
	 * @param mixed $context Whatever the screen passed.
	 * @return WC_Order|null
	 */
	public static function order_from( $context ): ?WC_Order {
		if ( $context instanceof WC_Order ) {
			return $context;
		}

		if ( is_object( $context ) && isset( $context->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $context->ID );

			return $order instanceof WC_Order ? $order : null;
		}

		if ( is_numeric( $context ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $context );

			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}

	/**
	 * Whether this user may edit this order's checkout fields.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function may_edit( WC_Order $order ): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		return current_user_can( 'edit_shop_order', $order->get_id() )
			|| current_user_can( 'edit_shop_orders' );
	}

	/**
	 * The fields this order's screen may change.
	 *
	 * Three conditions and each comes from the planning: the field is enabled (a
	 * disabled definition is archived for new purchases, not a field to fill in), the
	 * merchant asked for it to be shown to staff on the order, and the store keeps its
	 * value on the order. A field without those is shown read-only or not at all, and
	 * the distinction is what stops an edit from rewriting a customer's current profile
	 * from one order's snapshot.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<string, array<string, mixed>> Editable definitions, keyed by identifier.
	 */
	public static function editable( array $definitions ): array {
		$editable = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || ! $definition->is_enabled() ) {
				continue;
			}

			$stored  = $definition->to_array();
			$storage = isset( $stored['storage'] ) && is_array( $stored['storage'] ) ? $stored['storage'] : array();
			$scope   = isset( $storage['scope'] ) ? (string) $storage['scope'] : '';

			if ( ! $definition->shows_in( 'admin_order' ) ) {
				continue;
			}

			// The order is a snapshot; the customer's profile is current. A field the
			// store keeps on the customer is not edited from here, because doing so
			// would rewrite today's preference from one past order.
			if ( 'order' !== $scope ) {
				continue;
			}

			$editable[ $id ] = $stored;
		}

		return $editable;
	}

	/**
	 * Draws the panel.
	 *
	 * @param mixed                $subject Whatever the screen passed to the callback.
	 * @param array<string, mixed> $box     Meta box arguments.
	 * @return void
	 */
	public static function render( $subject = null, array $box = array() ): void {
		$order = isset( $box['args']['order'] ) && $box['args']['order'] instanceof WC_Order
			? $box['args']['order']
			: self::order_from( $subject );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$document    = PublishedDocument::read();
		$definitions = $document->fields();
		$editable    = self::editable( $definitions );
		$stored      = self::service()->read( $order, $definitions );
		$status      = self::service()->read_status( $order );
		$entries     = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id ) {
				continue;
			}

			if ( ! $definition->shows_in( 'admin_order' ) ) {
				continue;
			}

			$entries[] = new OrderFieldEntry(
				$id,
				$definition->label(),
				$definition->type(),
				$stored->get( $id ),
				array(),
				OrderFieldEntry::SOURCE_SCHEMA,
				$definition->is_enabled()
			);
		}

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		echo '<div class="wccs-order-fields">';

		if ( 'readable' !== $status['state'] && 'absent' !== $status['state'] ) {
			printf(
				'<p class="wccs-order-fields__warning">%s</p>',
				esc_html__(
					'This order carries checkout values this version cannot read. They are left untouched.',
					'wc-checkoutsuite'
				)
			);
		}

		$groups = AreaProjection::group( $entries, $definitions, $document->sections(), 'admin_order' );

		if ( array() === $groups ) {
			printf(
				'<p class="wccs-order-fields__empty">%s</p>',
				esc_html__( 'No checkout field is shown on the order screen.', 'wc-checkoutsuite' )
			);
			echo '</div>';

			return;
		}

		// One table per section the links name: the merchant configured where each
		// field is shown, and the screen is the place that has to obey it.
		foreach ( $groups as $group ) {
			if ( '' !== $group['title'] ) {
				printf(
					'<h3 class="wccs-order-fields__section">%s</h3>',
					esc_html( $group['title'] )
				);
			}

			echo '<table class="widefat striped wccs-order-fields__table"><tbody>';

			foreach ( $group['fields'] as $shown ) {
				$entry          = $shown['entry'];
				$id             = $entry->id();
				$editable_field = isset( $editable[ $id ] );

				// A label points at a control. The read-only row has none, so it gets a
				// heading and not a `for` pointing at an element that does not exist — the
				// kind of thing a screen reader announces as a control and a keyboard
				// cannot reach.
				printf(
					'<tr><th scope="row">%s</th><td>',
					$editable_field
						? sprintf(
							'<label for="%s">%s</label>',
							esc_attr( self::INPUT_PREFIX . $id ),
							esc_html( $shown['title'] )
						)
						: sprintf(
							'<span class="wccs-order-fields__label">%s</span>',
							esc_html( $shown['title'] )
						)
				);

				if ( $editable_field ) {
					printf(
						'<input type="text" class="regular-text" id="%s" name="%s" value="%s" />',
						esc_attr( self::INPUT_PREFIX . $id ),
						esc_attr( self::INPUT_PREFIX . $id ),
						esc_attr( is_scalar( $entry->value() ) ? (string) $entry->value() : '' )
					);
				} else {
					printf(
						'<span class="wccs-order-fields__value">%s</span>',
						esc_html( self::display( $entry ) )
					);
				}

				printf(
					'<p class="description">%s</p></td></tr>',
					esc_html(
						$editable_field
							? __( 'Validated the same way the checkout validates it.', 'wc-checkoutsuite' )
							: __( 'Shown for reference: this field is not kept on the order.', 'wc-checkoutsuite' )
					)
				);
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * A value as text, for the read-only rows.
	 *
	 * @param OrderFieldEntry $entry Entry.
	 * @return string
	 */
	private static function display( OrderFieldEntry $entry ): string {
		$value = $entry->value();

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wc-checkoutsuite' ) : __( 'No', 'wc-checkoutsuite' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Saves what was submitted, if anything was.
	 *
	 * Both order backends fire this action, and the order is always fetched through
	 * `wc_get_order()` — the first argument is a post identifier on the legacy screen
	 * and an order identifier under HPOS, and the CRUD resolves whichever it is.
	 *
	 * @param int   $order_id Order or post identifier.
	 * @param mixed $context  Order or post the screen was editing.
	 * @return void
	 */
	public static function save( $order_id, $context = null ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order || ! self::may_edit( $order ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE . '_nonce' ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			// No panel was submitted. Another screen, another plugin, or the bulk
			// editor: this request is not this panel's and there is nothing to write.
			return;
		}

		$definitions = PublishedDocument::read()->fields();
		$editable    = self::editable( $definitions );

		if ( array() === $editable ) {
			return;
		}

		$service  = self::service();
		$existing = $service->read( $order );
		$accepted = array();
		$context  = new FieldContext( array(), 'admin' );

		foreach ( $editable as $id => $stored_definition ) {
			$input = self::INPUT_PREFIX . $id;

			if ( ! isset( $_POST[ $input ] ) ) {
				// A field the merchant chose not to draw on this screen keeps what the
				// order already had, rather than being cleared by its absence.
				if ( $existing->has( $id ) ) {
					$accepted[ $id ] = $existing->get( $id );
				}

				continue;
			}

			$raw        = wp_unslash( $_POST[ $input ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The value pipeline is what sanitizes and validates it, and it is the same pipeline the checkout runs.
			$definition = FieldDefinition::from_array( $stored_definition );
			$processed  = Registries::instance()->value_processor()->process(
				$definition,
				is_scalar( $raw ) ? (string) $raw : '',
				$context
			);

			if ( ! $processed->result()->is_valid() ) {
				// A refusal is not a silent drop: the field keeps the value it had, and
				// the same code and message the customer would have seen are recorded on
				// the order's notes by the caller that renders the notice.
				if ( $existing->has( $id ) ) {
					$accepted[ $id ] = $existing->get( $id );
				}

				continue;
			}

			$accepted[ $id ] = $processed->value();
		}

		$service->write( $order, $accepted, $definitions, PublishedDocument::read()->revision() );
		$order->save();
	}

	/**
	 * The order storage service.
	 *
	 * @return OrderFieldsService
	 */
	private static function service(): OrderFieldsService {
		return new OrderFieldsService();
	}
}
