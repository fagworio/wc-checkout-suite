<?php
/**
 * A complete checkout composition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

/**
 * One alternative checkout: `roadmap/…` §3.4.
 *
 * A store does not have one checkout, it has the one it runs and the ones it would like to run
 * for some carts: a digital order has no shipping to ask about, a restricted product needs a
 * document the ordinary customer is never asked for. A profile is that second composition, and it
 * is not a second document: it carries **its own containers** and its own presentation, while the
 * fields stay where they are — a binding names a container, so a field appears in whichever
 * profile declares the container it was bound to. That is what keeps one field library behind
 * several checkouts.
 *
 * Three things about it are decisions rather than data:
 *
 * 1. **`source` says where it came from**, not what it is now. A store that started from the
 *    WooCommerce checkout and then edited it is still a checkout that started there, and the
 *    record of that is what makes "this began as the current checkout" answerable later.
 * 2. **`conditions` are the shared rules** (§14). They are the same tree a field uses, validated
 *    by the same validator and evaluated by the same engine: a profile that matched by a second
 *    dialect would be a second answer to the same question.
 * 3. **`fallback` is a property of one profile, and it can be none.** A store whose profiles all
 *    have conditions needs a checkout for the carts that match nothing — but the fallback may also
 *    be the store's own checkout, which is what "no fallback profile" means.
 *
 * @see \ROADMAP.md section 6.3
 */
final class CheckoutProfile {

	/**
	 * Where a profile's composition came from, as a closed list.
	 *
	 * @var array<int, string>
	 */
	public const SOURCES = array( 'woocommerce_current', 'duplicate_profile', 'minimal' );

	/**
	 * Constructor.
	 *
	 * @param string               $id           Permanent identifier.
	 * @param string               $name         Name the merchant gave it.
	 * @param bool                 $enabled      Whether it may be selected at all.
	 * @param string               $source       Where its composition came from.
	 * @param int                  $priority     Higher is considered first.
	 * @param bool                 $fallback     Whether it is the checkout for carts that match nothing.
	 * @param array<string, mixed> $conditions   Rule tree that decides when it is used.
	 * @param array<int, mixed>    $sections     Its containers.
	 * @param array<string, mixed> $presentation How it presents itself.
	 */
	public function __construct(
		private string $id = '',
		private string $name = '',
		private bool $enabled = true,
		private string $source = 'woocommerce_current',
		private int $priority = 0,
		private bool $fallback = false,
		private array $conditions = array(),
		private array $sections = array(),
		private array $presentation = array()
	) {
	}

	/**
	 * Builds one profile from a stored array.
	 *
	 * @param array<string, mixed> $data Raw profile.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['name'] ) ? (string) $data['name'] : '',
			! isset( $data['enabled'] ) || (bool) $data['enabled'],
			isset( $data['source'] ) ? (string) $data['source'] : 'woocommerce_current',
			isset( $data['priority'] ) && is_numeric( $data['priority'] ) ? (int) $data['priority'] : 0,
			! empty( $data['fallback'] ),
			isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? $data['conditions'] : array(),
			isset( $data['sections'] ) && is_array( $data['sections'] ) ? array_values( $data['sections'] ) : array(),
			isset( $data['presentation'] ) && is_array( $data['presentation'] ) ? $data['presentation'] : array()
		);
	}

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether it may be selected.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Where its composition came from.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Priority: higher is considered first.
	 *
	 * @return int
	 */
	public function priority(): int {
		return $this->priority;
	}

	/**
	 * Whether it is the checkout for carts that match nothing.
	 *
	 * @return bool
	 */
	public function is_fallback(): bool {
		return $this->fallback;
	}

	/**
	 * The rule tree that decides when it is used.
	 *
	 * @return array<string, mixed>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * Whether it is used unconditionally.
	 *
	 * A profile with no rule is the checkout for every cart it can be selected for, which is a
	 * different statement from "a profile nobody bound": the validator refuses a profile that is
	 * neither a fallback nor carries a rule, because it would be configuration that never runs.
	 *
	 * @return bool
	 */
	public function is_unconditional(): bool {
		return array() === $this->conditions;
	}

	/**
	 * Its containers.
	 *
	 * @return array<int, mixed>
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * How it presents itself.
	 *
	 * @return array<string, mixed>
	 */
	public function presentation(): array {
		return $this->presentation;
	}

	/**
	 * Exports the profile as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'name'         => $this->name,
			'enabled'      => $this->enabled,
			'source'       => $this->source,
			'priority'     => $this->priority,
			'fallback'     => $this->fallback,
			'conditions'   => $this->conditions,
			'sections'     => $this->sections,
			'presentation' => $this->presentation,
		);
	}
}
