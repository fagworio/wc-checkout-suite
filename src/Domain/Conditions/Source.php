<?php
/**
 * Condition source.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Conditions;

/**
 * One thing a rule is allowed to look at.
 *
 * Section 11 lists the sources of V1 and adds a condition to the list: *as long as
 * the adapter exposes that context*, and *any unavailable source gets an explicit
 * block or restriction*. So a source declares where its value comes from, and the
 * declaration here is about **who can know it**, which is the question the block is
 * about.
 *
 * `scope` is that answer, and it is decided by where the data lives rather than by
 * an opinion:
 *
 * - `client` — the value is in the page already. The customer's own address, the
 *   chosen shipping and payment methods, and anything the server inlines while
 *   rendering.
 * - `server` — only the server knows it: what is in the cart, what it costs, which
 *   categories it holds, and whether anybody is logged in at the moment the
 *   request arrives.
 *
 * A rule that reads a server source cannot be answered live in the browser, and
 * that is not a gap to paper over: section 11 requires the server to recompute with
 * trusted context anyway, and a browser that guessed would be a browser that could
 * be forged into hiding a field the server considers required.
 *
 * @see \ROADMAP.md section 11
 */
final class Source {

	/**
	 * The value is in the page.
	 */
	public const SCOPE_CLIENT = 'client';

	/**
	 * Only the server knows it.
	 */
	public const SCOPE_SERVER = 'server';

	/**
	 * Constructor.
	 *
	 * @param string $key       Stable key, e.g. `cart_total`.
	 * @param string $label     Translatable label.
	 * @param string $type      Value type it yields: string, number, boolean, list or mixed.
	 * @param string $scope     Where the value comes from.
	 * @param bool   $reference Whether it names another field.
	 */
	public function __construct(
		private string $key,
		private string $label,
		private string $type,
		private string $scope,
		private bool $reference = false
	) {
	}

	/**
	 * Stable key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Translatable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Value type this source yields.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Where the value comes from.
	 *
	 * @return string
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Whether the source names another field.
	 *
	 * A referencing source carries a field identifier, and the rule cannot be
	 * judged at all until that field is known to exist — which is what makes a
	 * removed field a broken rule rather than one that quietly never matches.
	 *
	 * @return bool
	 */
	public function is_reference(): bool {
		return $this->reference;
	}

	/**
	 * Whether only the server can answer for this source.
	 *
	 * @return bool
	 */
	public function is_server_only(): bool {
		return self::SCOPE_SERVER === $this->scope;
	}

	/**
	 * Exports the source as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'type'        => $this->type,
			'scope'       => $this->scope,
			'isReference' => $this->reference,
		);
	}
}
