<?php
/**
 * The optional approval flow of one field.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Approval;

use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * What one field's approval configuration means.
 *
 * The flow is separate from the destination links on purpose. A link says where a
 * document is *shown*; asking for a manual review says what happens to the *order*,
 * and a store can want either one without the other. It is also off until it is asked
 * for: a store that does not use the flow gets nothing from this class — no status, no
 * hook, no order touched.
 *
 * **The configuration is all or nothing.** A flow that is enabled but does not say
 * where the review happens, in which section, or which state the order waits in is not
 * completed with a default: {@see self::missing()} reports what is absent, the
 * validator refuses it, and nothing in the checkout acts on it. A state the store
 * cannot name is a state the store cannot wait in, and inventing one silently is how a
 * store ends up holding an order in a status nobody recognises.
 *
 * @see ROADMAP.md section 12.1
 */
final class ApprovalFlow {

	/**
	 * The keys a flow needs before it can hold an order.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED = array( 'area', 'section', 'status' );

	/**
	 * Prefix of every status this plugin registers, below WooCommerce's own `wc-`.
	 */
	public const STATUS_PREFIX = 'wccs-';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $configuration Stored approval map, or null.
	 * @param string                    $field_id      Identifier of the field the flow belongs to.
	 */
	public function __construct( private ?array $configuration = null, private string $field_id = '' ) {
	}

	/**
	 * The flow one definition declares.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return self
	 */
	public static function of( FieldDefinition $definition ): self {
		return new self( $definition->approval(), $definition->id() );
	}

	/**
	 * The flows a document enables, in the order the document declares them.
	 *
	 * Only the fields this plugin owns are read: a core WooCommerce field is not the
	 * Suite's to hold an order for, and the values on an order follow the same rule.
	 *
	 * @param array<int, array<string, mixed>> $definitions Published definitions.
	 * @return array<int, array{id: string, flow: self}>
	 */
	public static function enabled_in( array $definitions ): array {
		$flows = array();

		foreach ( $definitions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$id         = $definition->id();

			if ( '' === $id || 'custom' !== $definition->origin() ) {
				continue;
			}

			$flow = self::of( $definition );

			if ( $flow->enabled() ) {
				$flows[] = array(
					'id'   => $id,
					'flow' => $flow,
				);
			}
		}

		return $flows;
	}

	/**
	 * Whether the store asked for a manual review.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return ! empty( $this->configuration['require_review'] );
	}

	/**
	 * The state the order waits in, as the merchant named it.
	 *
	 * @return string
	 */
	public function label(): string {
		return trim( (string) ( $this->configuration['status'] ?? '' ) );
	}

	/**
	 * The order status this flow waits in, without WooCommerce's `wc-` prefix.
	 *
	 * The merchant names the state in their own words, and the identifier is derived
	 * from the field instead of from that name. It has to be: `post_status` and the
	 * orders table both hold twenty characters, and a name a person would recognise —
	 * "Pendente de aprovação" — does not fit in twenty characters after the `wc-` and
	 * a plugin prefix. Deriving from the field identifier also makes it stable: the same
	 * field waits in the same state after it is renamed, and two fields cannot collide.
	 *
	 * @return string Empty when the store has not named a state yet.
	 */
	public function status(): string {
		if ( '' === $this->label() ) {
			return '';
		}

		return self::STATUS_PREFIX . substr( md5( 'wccs-approval-' . $this->field_id ), 0, 10 );
	}

	/**
	 * The area the review happens in.
	 *
	 * @return string
	 */
	public function area(): string {
		return trim( (string) ( $this->configuration['area'] ?? '' ) );
	}

	/**
	 * The section of the area the review happens in.
	 *
	 * @return string
	 */
	public function section(): string {
		return trim( (string) ( $this->configuration['section'] ?? '' ) );
	}

	/**
	 * Whether the store may ask the customer for a correction.
	 *
	 * @return bool
	 */
	public function allows_correction(): bool {
		return ! empty( $this->configuration['allow_correction'] );
	}

	/**
	 * Whether the customer may send a new version.
	 *
	 * @return bool
	 */
	public function allows_resubmit(): bool {
		return ! empty( $this->configuration['allow_resubmit'] );
	}

	/**
	 * Whether the customer is told the situation of the review.
	 *
	 * @return bool
	 */
	public function shows_status(): bool {
		return ! empty( $this->configuration['show_status'] );
	}

	/**
	 * What the flow still needs before it can do anything.
	 *
	 * Nothing is missing while the flow is off: a store that did not ask for a review
	 * has no incomplete review to report.
	 *
	 * @return array<int, string>
	 */
	public function missing(): array {
		if ( ! $this->enabled() ) {
			return array();
		}

		$missing = array();

		foreach ( self::REQUIRED as $key ) {
			if ( '' === trim( (string) ( $this->configuration[ $key ] ?? '' ) ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Whether the flow is enabled and has everything it needs.
	 *
	 * @return bool
	 */
	public function complete(): bool {
		return $this->enabled() && array() === $this->missing();
	}

	/**
	 * The flow as it is stored.
	 *
	 * @return array<string, mixed>|null
	 */
	public function to_array(): ?array {
		return $this->configuration;
	}

	/**
	 * Whether an order carries an answer that needs reviewing.
	 *
	 * Presence is not the question: a file field stores a list of tokens, and a list
	 * with nothing in it is an untouched field, not a document to review. An empty
	 * string, `null` and `false` are the same absence; `0` and `'0'` are answers.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function answered( mixed $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return true;
	}
}
