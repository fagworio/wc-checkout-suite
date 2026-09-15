<?php
/**
 * What each gateway has proven it can do.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * The transactional capability registry §13.7 and §20 ask for.
 *
 * **It is not the presentation matrix.** {@see PaymentMatrix} records whether this plugin may
 * *decorate* a gateway's payment area, and §17 is explicit that it must stay that: "Manter para
 * compatibilidade visual/homologação da apresentação. Não transformar esse objeto em matriz de
 * capture/authorize." Two questions, two records, two objects — and a test asserts they are apart,
 * because the failure this separation prevents is a presentation decision being read as permission
 * to move money.
 *
 * **Nothing here is a declaration.** A capability enters the registry only with its evidence, and
 * leaves it when the evidence is stale. Three answers, all of them about what may be *offered*:
 *
 * - `supports( $gateway, $action )` — proven, for the version installed.
 * - `offerable( $gateway )` — the actions an interface may show, which is the gate.
 * - `fallback( $gateway, $wanted )` — what the store does instead, which is always WooCommerce's own
 *   pay-for-order page and never a gateway action nobody proved.
 *
 * A declaration that cannot become a capability is **reported**, not dropped: a claim refused in
 * silence is a claim somebody will make again.
 *
 * @see \ROADMAP.md sections 13.7, 17, 20
 */
final class GatewayCapabilityRegistry {

	/**
	 * The record's file, relative to the plugin root.
	 */
	public const FILE = 'resources/payments/capabilities.json';

	/**
	 * Action an extension registers its capabilities through.
	 */
	public const REGISTER_ACTION = 'wccs_register_gateway_capabilities';

	/**
	 * The record and the declarations, read once.
	 *
	 * @var array<int, GatewayCapability>|null
	 */
	private ?array $capabilities = null;

	/**
	 * Whether the record has been read and the registration action fired.
	 *
	 * A flag rather than a null check, and the difference matters: a declaration made before the
	 * first read has to survive that read. With a null check the read would run afterwards and
	 * reset the list, and a capability somebody declared would vanish — silently, which is the one
	 * thing a registry of evidence must never do.
	 *
	 * @var bool
	 */
	private bool $built = false;

	/**
	 * The claims that could not become capabilities, with the reason.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $refusals = array();

	/**
	 * Constructor.
	 *
	 * @param bool $load Whether to read the record and fire the registration action now.
	 */
	public function __construct( bool $load = true ) {
		if ( $load ) {
			$this->build();
		}
	}

	/**
	 * Forgets what was read, so a test can change the record underneath.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->capabilities = null;
		$this->refusals     = array();
		$this->built        = false;
	}

	/**
	 * Reads the record and collects the declarations.
	 *
	 * @return void
	 */
	private function build(): void {
		if ( $this->built ) {
			return;
		}

		$this->built        = true;
		$this->capabilities = array();

		foreach ( $this->recorded() as $raw ) {
			$this->declare( GatewayCapability::from_array( $raw ), 'record' );
		}

		/**
		 * Registers the transactional capabilities of a gateway.
		 *
		 * §20 asks a gateway to declare what it can do and to keep the evidence beside it. An
		 * extension that has run its gateway's sandbox calls
		 * `$registry->declare( $gateway, $action, $evidence )` for each action it proved; a claim
		 * without evidence is refused by name and reported, so a capability that reaches an
		 * interface is always one somebody can point at a run for.
		 *
		 * @since 1.0.0
		 *
		 * @param GatewayCapabilityRegistry $registry The registry being built.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the prefixed name, kept in one place.
		do_action( self::REGISTER_ACTION, $this );
	}

	/**
	 * Whether a gateway has proven an action, for the version installed.
	 *
	 * @param string      $gateway   Gateway identifier.
	 * @param string      $action    Action key.
	 * @param string|null $installed Installed version, when it is known.
	 * @return bool
	 */
	public function supports( string $gateway, string $action, ?string $installed = null ): bool {
		if ( GatewayCapabilities::is_fallback( $action ) ) {
			// The platform's own page: it is there for every order that still needs paying, and no
			// gateway has to prove it.
			return true;
		}

		foreach ( $this->all() as $capability ) {
			if ( $capability->gateway() !== $gateway || $capability->action() !== $action ) {
				continue;
			}

			return $capability->is_proven() && ! $capability->is_stale( $installed );
		}

		return false;
	}

	/**
	 * The actions an interface may offer for a gateway.
	 *
	 * The gate, in one method: proven and not stale, plus the fallback — which is not offered
	 * because a gateway can do it but because the platform can, and which is therefore the one
	 * action a gateway without any capability still has.
	 *
	 * @param string      $gateway   Gateway identifier.
	 * @param string|null $installed Installed version, when it is known.
	 * @return array<int, string>
	 */
	public function offerable( string $gateway, ?string $installed = null ): array {
		$offered = array( GatewayCapabilities::FALLBACK );

		foreach ( $this->all() as $capability ) {
			if ( $capability->gateway() !== $gateway || ! $capability->is_proven() || $capability->is_stale( $installed ) ) {
				continue;
			}

			$offered[] = $capability->action();
		}

		return array_values( array_unique( $offered ) );
	}

	/**
	 * What the store does instead, when a gateway cannot do what was wanted.
	 *
	 * Always an answer: the pay-for-order page is WooCommerce's, it exists for every order that
	 * still needs paying, and §13.2 step 4 names it as the fallback for a gateway that cannot
	 * generate a PIX or capture later. The alternative — promising a generation nothing performs —
	 * is what the section forbids.
	 *
	 * @param string      $gateway   Gateway identifier.
	 * @param string      $wanted    Action the store wanted.
	 * @param string|null $installed Installed version, when it is known.
	 * @return array{wanted: string, supported: bool, fallback: string, reason: string}
	 */
	public function fallback( string $gateway, string $wanted, ?string $installed = null ): array {
		if ( $this->supports( $gateway, $wanted, $installed ) ) {
			return array(
				'wanted'    => $wanted,
				'supported' => true,
				'fallback'  => '',
				'reason'    => '',
			);
		}

		return array(
			'wanted'    => $wanted,
			'supported' => false,
			'fallback'  => GatewayCapabilities::FALLBACK,
			'reason'    => sprintf(
				/* translators: 1: action key, 2: gateway identifier */
				__( 'O gateway «%2$s» não tem «%1$s» comprovada nesta versão, portanto a loja envia o link «Pagar pedido» do próprio pedido.', 'wc-checkoutsuite' ),
				$wanted,
				$gateway
			),
		);
	}

	/**
	 * Every capability that is a capability, proven or not.
	 *
	 * @return array<int, GatewayCapability>
	 */
	public function all(): array {
		$this->build();

		return (array) $this->capabilities;
	}

	/**
	 * The claims that could not become capabilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function refusals(): array {
		$this->build();

		return $this->refusals;
	}

	/**
	 * Declares one capability.
	 *
	 * The ordinary way for an extension to register what its gateway proved, and the way the record
	 * is read. A declaration without evidence is refused **by name**: it does not enter the
	 * registry, so it cannot reach an interface, and it is reported so that the person who wrote it
	 * learns why.
	 *
	 * @param string|GatewayCapability $gateway  Gateway identifier, or a capability.
	 * @param string                   $action   Action key.
	 * @param array<string, mixed>     $evidence Evidence.
	 * @param string                   $note     Note for the next reader.
	 * @return bool Whether it became a capability.
	 */
	public function declare( $gateway, string $action = '', array $evidence = array(), string $note = '' ): bool {
		$capability = $gateway instanceof GatewayCapability
			? $gateway
			: new GatewayCapability( (string) $gateway, $action, $evidence, $note );

		// A declaration made before the record was read is a registry of its own: it is marked as
		// built so the read does not run afterwards and take the declaration with it.
		$this->built = true;

		if ( null === $this->capabilities ) {
			$this->capabilities = array();
		}

		$missing = $capability->missing();

		if ( array() !== $missing ) {
			$this->refusals[] = array(
				'gateway' => $capability->gateway(),
				'action'  => $capability->action(),
				'missing' => $missing,
				'reason'  => sprintf(
					/* translators: 1: action key, 2: comma separated list of what is missing */
					__( 'A capability «%1$s» foi declarada sem prova: falta %2$s. Uma ação sem prova não entra no registo e não aparece em interface nenhuma.', 'wc-checkoutsuite' ),
					$capability->action(),
					implode( ', ', $missing )
				),
			);

			return false;
		}

		$this->capabilities[] = $capability;

		return true;
	}

	/**
	 * What a screen or a diagnostic reads.
	 *
	 * The gate is visible here rather than only enforced: every action the store knows, whether it
	 * is offerable for this gateway, and — when it is not — whether that is because nothing was
	 * declared, because the evidence is missing, or because it is stale.
	 *
	 * @param string               $gateway   Gateway identifier.
	 * @param string|null          $installed Installed version, when it is known.
	 * @param array<string, mixed> $gateway_row Extra information about the gateway, echoed back.
	 * @return array<string, mixed>
	 */
	public function report( string $gateway, ?string $installed = null, array $gateway_row = array() ): array {
		$actions = array();

		foreach ( GatewayCapabilities::actions() as $action => $entry ) {
			$declared = null;

			foreach ( $this->all() as $capability ) {
				if ( $capability->gateway() === $gateway && $capability->action() === $action ) {
					$declared = $capability;
					break;
				}
			}

			$proven   = null !== $declared && $declared->is_proven();
			$stale    = null !== $declared && $proven && $declared->is_stale( $installed );
			$fallback = GatewayCapabilities::is_fallback( $action );

			$actions[] = array(
				'action'    => $action,
				'label'     => $entry['label'],
				'family'    => $entry['family'],
				'fallback'  => $fallback,
				'declared'  => null !== $declared,
				'proven'    => $proven,
				'stale'     => $stale,
				'offerable' => $fallback || ( $proven && ! $stale ),
				'evidence'  => null === $declared ? null : $declared->evidence(),
				'missing'   => null === $declared ? GatewayCapabilities::evidence_fields() : $declared->missing(),
				'note'      => null === $declared ? '' : $declared->note(),
			);
		}

		return array_merge(
			$gateway_row,
			array(
				'gateway'   => $gateway,
				'installed' => null === $installed ? '' : $installed,
				'offerable' => $this->offerable( $gateway, $installed ),
				'actions'   => $actions,
			)
		);
	}

	/**
	 * The vocabulary, so an editor offers what the registry accepts.
	 *
	 * @return array<string, mixed>
	 */
	public function vocabulary(): array {
		return GatewayCapabilities::to_array();
	}

	/**
	 * The declarations that were refused, whoever made them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function refused_declarations(): array {
		return $this->refusals();
	}

	/**
	 * The rows written into the record.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recorded(): array {
		$path = self::root() . '/' . self::FILE;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local record shipped with the plugin; the sniff targets remote URLs.
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) || ! isset( $data['capabilities'] ) || ! is_array( $data['capabilities'] ) ) {
			return array();
		}

		return array_values( array_filter( $data['capabilities'], 'is_array' ) );
	}

	/**
	 * The plugin root, which the unit suite reaches without WordPress.
	 *
	 * @return string
	 */
	private static function root(): string {
		return defined( 'WCCS_PLUGIN_DIR' )
			? rtrim( (string) WCCS_PLUGIN_DIR, '/' )
			: dirname( __DIR__, 3 );
	}
}
