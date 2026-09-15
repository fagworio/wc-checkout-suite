<?php
/**
 * One action a gateway has proven it can perform.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * A capability, with the evidence that makes it one.
 *
 * The difference between a declaration and a capability is the whole of §20 and the whole of this
 * phase's gate, and it lives in this object: **an action is available when, and only when, somebody
 * ran it and wrote down what happened.** A gateway that says it can capture is a gateway with an
 * intention; a gateway with a capability has a sandbox run, a version and a date.
 *
 * Three consequences, and each is a method rather than a paragraph.
 *
 * - `proven()` is false without every field of the evidence, so a claim missing its version never
 *   becomes a capability and therefore never reaches an interface.
 * - `stale()` compares the version the evidence names with the one installed. Evidence for 4.1.3
 *   says nothing about 4.2.0 — the same rule the presentation matrix already keeps — and a stale
 *   capability is reported as stale rather than quietly promoted or quietly dropped.
 * - `to_array()` carries the evidence with the capability, so whatever shows the capability shows
 *   what backs it. A screen that listed the ability without the proof would be the interface this
 *   gate exists to prevent.
 *
 * @see \ROADMAP.md section 20
 */
final class GatewayCapability {

	/**
	 * Constructor.
	 *
	 * @param string               $gateway  Gateway identifier, as WooCommerce reports it.
	 * @param string               $action   Action key.
	 * @param array<string, mixed> $evidence Evidence: mode, version, scenario and date.
	 * @param string               $note     What the person who ran it wants the next reader to know.
	 */
	public function __construct(
		private string $gateway = '',
		private string $action = '',
		private array $evidence = array(),
		private string $note = ''
	) {
	}

	/**
	 * Builds one capability from a stored declaration.
	 *
	 * @param array<string, mixed> $data Raw declaration.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['gateway'] ) ? (string) $data['gateway'] : '',
			isset( $data['action'] ) ? (string) $data['action'] : '',
			isset( $data['evidence'] ) && is_array( $data['evidence'] ) ? $data['evidence'] : array(),
			isset( $data['note'] ) ? (string) $data['note'] : ''
		);
	}

	/**
	 * Gateway identifier.
	 *
	 * @return string
	 */
	public function gateway(): string {
		return $this->gateway;
	}

	/**
	 * Action key.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * The evidence.
	 *
	 * @return array<string, mixed>
	 */
	public function evidence(): array {
		return $this->evidence;
	}

	/**
	 * What the run's author wrote down.
	 *
	 * @return string
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * The version the evidence was gathered against.
	 *
	 * @return string
	 */
	public function version(): string {
		return (string) ( $this->evidence['version'] ?? '' );
	}

	/**
	 * Where the evidence came from: a sandbox run or a live store.
	 *
	 * @return string
	 */
	public function mode(): string {
		return (string) ( $this->evidence['mode'] ?? '' );
	}

	/**
	 * The scenario that was run.
	 *
	 * @return string
	 */
	public function scenario(): string {
		return (string) ( $this->evidence['scenario'] ?? '' );
	}

	/**
	 * When it was run.
	 *
	 * @return string
	 */
	public function proven_at(): string {
		return (string) ( $this->evidence['proven_at'] ?? '' );
	}

	/**
	 * What is missing for this to be a capability rather than a claim.
	 *
	 * @return array<int, string> Missing evidence fields, or an action outside the vocabulary.
	 */
	public function missing(): array {
		$missing = array();

		if ( ! GatewayCapabilities::has_action( $this->action ) ) {
			$missing[] = 'action';
		}

		if ( '' === trim( $this->gateway ) ) {
			$missing[] = 'gateway';
		}

		foreach ( GatewayCapabilities::evidence_fields() as $field ) {
			if ( '' === trim( (string) ( $this->evidence[ $field ] ?? '' ) ) ) {
				$missing[] = $field;
			}
		}

		if ( '' !== $this->mode() && ! in_array( $this->mode(), GatewayCapabilities::modes(), true ) ) {
			$missing[] = 'mode';
		}

		if ( '' !== $this->scenario() && ! isset( GatewayCapabilities::scenarios()[ $this->scenario() ] ) ) {
			$missing[] = 'scenario';
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Whether this is a capability the store may act on.
	 *
	 * @return bool
	 */
	public function is_proven(): bool {
		return array() === $this->missing();
	}

	/**
	 * Whether the evidence belongs to a version the store no longer runs.
	 *
	 * An unknown installed version is **not** staleness: a store that cannot say which version it
	 * runs cannot be told its evidence is old, and answering "stale" there would be inventing a
	 * fact. It answers false, and the report says the version is unknown.
	 *
	 * @param string|null $installed Installed version, when it is known.
	 * @return bool
	 */
	public function is_stale( ?string $installed ): bool {
		if ( null === $installed || '' === trim( $installed ) ) {
			return false;
		}

		return $this->version() !== trim( $installed );
	}

	/**
	 * Exports the capability, evidence and all.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'gateway'  => $this->gateway,
			'action'   => $this->action,
			'label'    => GatewayCapabilities::actions()[ $this->action ]['label'] ?? $this->action,
			'evidence' => array(
				'mode'      => $this->mode(),
				'version'   => $this->version(),
				'scenario'  => $this->scenario(),
				'proven_at' => $this->proven_at(),
			),
			'note'     => $this->note,
			'proven'   => $this->is_proven(),
			'missing'  => $this->missing(),
		);
	}
}
