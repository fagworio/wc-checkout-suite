<?php
/**
 * The payment homologation matrix, read and decided.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Payments;

/**
 * The record of which gateway, version, mode and scenario were actually observed.
 *
 * ROADMAP.md section 15 asks for three things at once, and this class is the first
 * two:
 *
 * > Modo compatível deve existir quando um gateway homologado não tolerar determinada
 * > decoração. Registrar exatamente gateway, versão, modo e cenário testados. Não
 * > prometer compatibilidade com "todos os gateways".
 *
 * The record lives in `resources/payments/homologation.json` rather than in code,
 * because it is data that changes when somebody runs a sandbox and its shape has to
 * be readable by whoever maintains it. The decision lives here, because it is a rule
 * and a rule in two places is a rule that disagrees the first time one of them is
 * edited.
 *
 * **The rule that makes this more than a lookup table.** A mode is a claim, and a
 * claim needs evidence: a row that names no passing scenario is `undecided`, whatever
 * it declares. The same is true of a row whose version differs from the one installed
 * — a gateway that was homologated at 4.1.3 says nothing about 4.2.0, and a matrix
 * that matched on the identifier alone would quietly promote a stale observation to a
 * promise. Nothing is cached across requests beyond one read of the file per request,
 * and a missing or damaged file is `undecided` for everything rather than an error:
 * a checkout that cannot read a record must fall back to promising nothing, not to
 * promising everything.
 */
final class PaymentMatrix {

	/**
	 * The record's file, relative to the plugin root.
	 */
	public const FILE = 'resources/payments/homologation.json';

	/**
	 * The row, as read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $record = null;

	/**
	 * The plugin root, which the unit suite reaches without WordPress.
	 *
	 * The same resolution `Support\DesignTokens` uses, and for the same reason: this
	 * record has to be readable by a suite that runs without WordPress loaded.
	 *
	 * @return string
	 */
	private static function root(): string {
		return defined( 'WCCS_PLUGIN_DIR' )
			? rtrim( (string) WCCS_PLUGIN_DIR, '/' )
			: dirname( __DIR__, 3 );
	}

	/**
	 * The record, read from disk once.
	 *
	 * @return array<string, mixed>
	 */
	public static function record(): array {
		if ( null !== self::$record ) {
			return self::$record;
		}

		$path = self::root() . '/' . self::FILE;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local record shipped with the plugin; the sniff targets remote URLs.
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

		self::$record = is_array( $data ) ? $data : array();

		return self::$record;
	}

	/**
	 * Forgets the read, so a test can change the file underneath.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$record = null;
	}

	/**
	 * Every recorded row, keyed by gateway identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function rows(): array {
		$record = self::record();
		$listed = isset( $record['gateways'] ) && is_array( $record['gateways'] ) ? $record['gateways'] : array();
		$rows   = array();

		foreach ( $listed as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
				continue;
			}

			$rows[ $row['id'] ] = $row;
		}

		return $rows;
	}

	/**
	 * The scenarios a homologation can be recorded against.
	 *
	 * The vocabulary is the record's own, so a scenario the file does not describe is a
	 * scenario nobody has written down how to run — and a test that passed against a
	 * scenario nobody can repeat is not evidence.
	 *
	 * @return array<int, string>
	 */
	public static function scenarios(): array {
		$record = self::record();
		$listed = isset( $record['scenario_vocabulary'] ) && is_array( $record['scenario_vocabulary'] )
			? $record['scenario_vocabulary']
			: array();

		return array_values( array_map( 'strval', array_keys( $listed ) ) );
	}

	/**
	 * The decorations this plugin can withhold from a gateway.
	 *
	 * @return array<int, string>
	 */
	public static function decorations(): array {
		$record = self::record();
		$listed = isset( $record['decoration_vocabulary'] ) && is_array( $record['decoration_vocabulary'] )
			? $record['decoration_vocabulary']
			: array();

		return array_values( array_map( 'strval', array_keys( $listed ) ) );
	}

	/**
	 * The scenarios a row actually passed.
	 *
	 * A scenario in the row that is not in the vocabulary is dropped rather than
	 * counted: evidence has to be checkable by whoever reads the record next.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<int, string>
	 */
	public static function tested( array $row ): array {
		$passed = isset( $row['tested'] ) && is_array( $row['tested'] ) ? $row['tested'] : array();

		return array_values(
			array_filter(
				array_map( 'strval', $passed ),
				static function ( string $scenario ): bool {
					return in_array( $scenario, self::scenarios(), true );
				}
			)
		);
	}

	/**
	 * What this plugin is allowed to do to a gateway's payment area.
	 *
	 * @param string      $gateway_id Gateway identifier, as WooCommerce reports it.
	 * @param string|null $version    Installed version, when it is known.
	 * @return array{mode: string, decorations: array<int, string>, withheld: array<int, string>, tested: array<int, string>, reason: string}
	 */
	public static function decision( string $gateway_id, ?string $version = null ): array {
		$undecided = array(
			'mode'        => PaymentMode::UNDECIDED,
			'decorations' => self::decorations(),
			'withheld'    => array(),
			'tested'      => array(),
			'reason'      => '',
		);

		$id  = trim( $gateway_id );
		$row = '' === $id ? null : ( self::rows()[ $id ] ?? null );

		if ( null === $row ) {
			$undecided['reason'] = PaymentMode::reason( PaymentMode::UNDECIDED );

			return $undecided;
		}

		$mode = isset( $row['mode'] ) && is_string( $row['mode'] ) ? $row['mode'] : '';

		if ( ! PaymentMode::is_known( $mode ) ) {
			$undecided['reason'] = PaymentMode::reason( PaymentMode::UNDECIDED );

			return $undecided;
		}

		$tested = self::tested( $row );

		// A mode without a passing scenario is not a homologation. This is the rule that
		// keeps the file from becoming a wish list: writing `decorated` in a row changes
		// nothing until a run has produced a scenario to put beside it.
		if ( ! PaymentMode::is_homologated( $mode ) || array() === $tested ) {
			$undecided['reason'] = PaymentMode::reason( PaymentMode::UNDECIDED );

			return $undecided;
		}

		if ( null !== $version ) {
			$recorded = isset( $row['version'] ) ? (string) $row['version'] : '';

			// The version is part of the observation. A row that recorded 4.1.3 says
			// nothing about the 4.2.0 installed today, and matching on the identifier
			// alone would promote a stale run to a promise about different code.
			if ( '' !== $recorded && $recorded !== $version ) {
				$undecided['reason'] = sprintf(
					'Recorded for version %1$s; %2$s is installed, so the record does not apply.',
					$recorded,
					$version
				);

				return $undecided;
			}
		}

		$withheld = array();

		if ( PaymentMode::COMPATIBLE === $mode ) {
			$listed = isset( $row['withheld'] ) && is_array( $row['withheld'] ) ? $row['withheld'] : array();

			$withheld = array_values(
				array_filter(
					array_map( 'strval', $listed ),
					static function ( string $decoration ): bool {
						return in_array( $decoration, self::decorations(), true );
					}
				)
			);
		}

		return array(
			'mode'        => $mode,
			'decorations' => array_values( array_diff( self::decorations(), $withheld ) ),
			'withheld'    => $withheld,
			'tested'      => $tested,
			'reason'      => PaymentMode::reason( $mode ),
		);
	}

	/**
	 * The decision for a gateway the store is actually offering.
	 *
	 * Preserved as a separate question because it is the one the checkout asks, and the
	 * answer must not depend on whether the caller remembered to pass a version: the
	 * gateway object knows its own version, and a caller that cannot produce it is a
	 * caller asking about a gateway that is not installed.
	 *
	 * @param object $gateway Gateway object as WooCommerce holds it.
	 * @return array{mode: string, decorations: array<int, string>, withheld: array<int, string>, tested: array<int, string>, reason: string}
	 */
	public static function decide_for( object $gateway ): array {
		$id      = property_exists( $gateway, 'id' ) ? (string) $gateway->id : '';
		$version = property_exists( $gateway, 'version' ) && is_string( $gateway->version ) ? $gateway->version : null;

		return self::decision( $id, $version );
	}
}
