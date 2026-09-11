<?php
/**
 * Renderer contract.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

/**
 * Describes how a field type is presented in each adapter.
 *
 * The contract deliberately carries no markup and no React: it declares which
 * adapters a renderer serves, which assets it needs and which template it uses.
 * Rendering itself belongs to the adapter, so the domain never depends on HTML,
 * React or WC_Order.
 *
 * @see \ROADMAP.md sections 3 and 6
 */
interface RendererInterface {

	/**
	 * Stable key, usually the field type key.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Version of this contract the renderer implements.
	 *
	 * @return string
	 */
	public function contract_version(): string;

	/**
	 * Adapters this renderer serves.
	 *
	 * @return array<int, string> Any of `classic`, `blocks`, `admin`.
	 */
	public function adapters(): array;

	/**
	 * Asset handles the adapter must enqueue before rendering.
	 *
	 * @return array<string, array<int, string>> Keyed by adapter.
	 */
	public function assets(): array;

	/**
	 * Template path relative to the plugin, when the adapter renders server side.
	 *
	 * @return string|null
	 */
	public function template(): ?string;
}
