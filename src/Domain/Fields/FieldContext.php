<?php
/**
 * Trusted server-side field context.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Carries the trusted context a type may use to normalize or validate a value.
 *
 * Only the server populates the entries. Nothing here is ever taken from the
 * request body, because a rule that depends on client-supplied context is not a
 * rule.
 *
 * The context also carries the resolved per-type settings of the field being
 * validated. That keeps the extension contract from section 6 exactly as
 * specified — `validate( mixed $value, FieldContext $context )` — while still
 * letting a type honour settings such as `maxLength`.
 *
 * @see \ROADMAP.md sections 6, 10 and 11
 */
final class FieldContext {

	/**
	 * Context entries, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Resolved per-type settings of the field being processed.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Adapter rendering the field (`classic`, `blocks` or `admin`).
	 *
	 * @var string
	 */
	private string $adapter;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data     Trusted context entries.
	 * @param string               $adapter  Adapter identifier.
	 * @param array<string, mixed> $settings Resolved per-type settings.
	 */
	public function __construct( array $data = array(), string $adapter = 'classic', array $settings = array() ) {
		$this->data     = $data;
		$this->adapter  = $adapter;
		$this->settings = $settings;
	}

	/**
	 * Adapter currently rendering the field.
	 *
	 * @return string
	 */
	public function adapter(): string {
		return $this->adapter;
	}

	/**
	 * Whether a context entry exists.
	 *
	 * @param string $key Entry name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->data );
	}

	/**
	 * Reads a context entry.
	 *
	 * @param string $key     Entry name.
	 * @param mixed  $fallback Value returned when the entry is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	/**
	 * All context entries.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * All resolved per-type settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Reads one per-type setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $fallback Value returned when the setting is absent.
	 * @return mixed
	 */
	public function setting( string $key, mixed $fallback = null ): mixed {
		return $this->settings[ $key ] ?? $fallback;
	}

	/**
	 * Returns a copy with one context entry replaced.
	 *
	 * @param string $key   Entry name.
	 * @param mixed  $value Entry value.
	 * @return self
	 */
	public function with( string $key, mixed $value ): self {
		$data         = $this->data;
		$data[ $key ] = $value;

		return new self( $data, $this->adapter, $this->settings );
	}

	/**
	 * Returns a copy bound to another adapter.
	 *
	 * @param string $adapter Adapter identifier.
	 * @return self
	 */
	public function for_adapter( string $adapter ): self {
		return new self( $this->data, $adapter, $this->settings );
	}

	/**
	 * Returns a copy carrying another set of per-type settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return self
	 */
	public function with_settings( array $settings ): self {
		return new self( $this->data, $this->adapter, $settings );
	}
}
