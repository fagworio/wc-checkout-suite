<?php
/**
 * Shared registry behaviour.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Minimal name-to-item registry with collision diagnostics.
 *
 * A duplicate key is never allowed to silently replace an earlier registration:
 * two plugins claiming the same key is exactly the situation that must surface
 * as a diagnostic instead of producing whichever one loaded last.
 */
abstract class AbstractRegistry {

	/**
	 * Registered items, keyed by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $items = array();

	/**
	 * Origin of each registered item, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $sources = array();

	/**
	 * Problems found while registering.
	 *
	 * @var array<int, array{code: string, key: string, message: string}>
	 */
	private array $diagnostics = array();

	/**
	 * Human readable kind of item, used in diagnostics.
	 *
	 * @return string
	 */
	abstract public function kind(): string;

	/**
	 * Contract version this registry accepts.
	 *
	 * An item declaring a different major version is rejected instead of being
	 * loaded and failing later in an unpredictable way.
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * Major part of a version string.
	 *
	 * @param string $version Version string.
	 * @return string
	 */
	protected static function major_of( string $version ): string {
		$parts = explode( '.', $version );

		return (string) $parts[0];
	}

	/**
	 * Contract version declared by an item, when it declares one.
	 *
	 * @param mixed $item Item being registered.
	 * @return string|null
	 */
	protected function item_contract_version( mixed $item ): ?string {
		if ( is_object( $item ) && method_exists( $item, 'contract_version' ) ) {
			return (string) $item->contract_version();
		}

		return null;
	}

	/**
	 * Registers one item.
	 *
	 * @param string $key    Stable key.
	 * @param mixed  $item   Item to register.
	 * @param string $source Where the item came from, for diagnostics.
	 * @return bool True when registered, false when rejected.
	 */
	public function register( string $key, mixed $item, string $source = 'core' ): bool {
		if ( '' === $key ) {
			$this->diagnostics[] = array(
				'code'    => 'empty_key',
				'key'     => '',
				'message' => sprintf( 'A %s was registered without a key and was ignored.', $this->kind() ),
			);

			return false;
		}

		$declared_contract = $this->item_contract_version( $item );

		if ( null !== $declared_contract && self::major_of( $declared_contract ) !== self::major_of( $this->contract_version() ) ) {
			$this->diagnostics[] = array(
				'code'    => 'incompatible_contract_version',
				'key'     => $key,
				'message' => sprintf(
					'The %1$s "%2$s" from %3$s declares contract version %4$s, but this build accepts %5$s.',
					$this->kind(),
					$key,
					$source,
					$declared_contract,
					$this->contract_version()
				),
			);

			return false;
		}

		if ( array_key_exists( $key, $this->items ) ) {
			$this->diagnostics[] = array(
				'code'    => 'duplicate_key',
				'key'     => $key,
				'message' => sprintf(
					'The %1$s "%2$s" is already registered by %3$s. The registration from %4$s was rejected.',
					$this->kind(),
					$key,
					$this->sources[ $key ] ?? 'unknown',
					$source
				),
			);

			return false;
		}

		$this->items[ $key ]   = $item;
		$this->sources[ $key ] = $source;

		return true;
	}

	/**
	 * Whether a key is registered.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->items );
	}

	/**
	 * Returns a registered item or null.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		return $this->items[ $key ] ?? null;
	}

	/**
	 * All registered items, keyed by key.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->items;
	}

	/**
	 * All registered keys.
	 *
	 * @return array<int, string>
	 */
	public function keys(): array {
		return array_keys( $this->items );
	}

	/**
	 * Origin of a registered item.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function source_of( string $key ): string {
		return $this->sources[ $key ] ?? '';
	}

	/**
	 * Problems found while registering.
	 *
	 * @return array<int, array{code: string, key: string, message: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	/**
	 * Whether any registration problem was recorded.
	 *
	 * @return bool
	 */
	public function has_diagnostics(): bool {
		return array() !== $this->diagnostics;
	}

	/**
	 * Records a registration problem.
	 *
	 * @param string $code    Stable diagnostic code.
	 * @param string $key     Key involved.
	 * @param string $message Human readable explanation.
	 * @return void
	 */
	protected function add_diagnostic( string $code, string $key, string $message ): void {
		$this->diagnostics[] = array(
			'code'    => $code,
			'key'     => $key,
			'message' => $message,
		);
	}
}
