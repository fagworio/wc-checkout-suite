<?php
/**
 * Requirement evaluation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Support;

/**
 * Evaluates runtime requirements without touching WordPress or WooCommerce APIs.
 *
 * The evaluation is deliberately pure: it receives the observed versions as an
 * argument instead of reading globals. That is what makes "activation is safe
 * without WooCommerce" a testable claim rather than an assumption.
 */
final class Requirements {

	/**
	 * Human readable labels for the known requirement keys.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'php'         => 'PHP',
		'wordpress'   => 'WordPress',
		'woocommerce' => 'WooCommerce',
	);

	/**
	 * Compares observed versions against the required ones.
	 *
	 * @param array<string, string|null> $observed Observed versions, keyed by requirement key.
	 *                                            A null or empty value means the component is absent.
	 * @param array<string, string>      $required Required minimum versions, keyed by requirement key.
	 * @return array<int, array{key: string, label: string, required: string, observed: string, reason: string}>
	 *         One entry per unmet requirement. An empty array means everything is satisfied.
	 */
	public static function evaluate( array $observed, array $required ): array {
		$unmet = array();

		foreach ( $required as $key => $required_version ) {
			$observed_version = isset( $observed[ $key ] ) ? (string) $observed[ $key ] : '';
			$label            = self::LABELS[ $key ] ?? ucfirst( (string) $key );

			if ( '' === trim( $observed_version ) ) {
				$unmet[] = array(
					'key'      => (string) $key,
					'label'    => $label,
					'required' => (string) $required_version,
					'observed' => '',
					'reason'   => 'missing',
				);
				continue;
			}

			if ( version_compare( $observed_version, (string) $required_version, '<' ) ) {
				$unmet[] = array(
					'key'      => (string) $key,
					'label'    => $label,
					'required' => (string) $required_version,
					'observed' => $observed_version,
					'reason'   => 'outdated',
				);
			}
		}

		return $unmet;
	}

	/**
	 * Builds a translatable, human readable sentence describing one unmet requirement.
	 *
	 * @param array{key: string, label: string, required: string, observed: string, reason: string} $entry Unmet requirement.
	 * @return string
	 */
	public static function describe( array $entry ): string {
		if ( 'missing' === $entry['reason'] ) {
			return sprintf(
				/* translators: 1: component name, 2: required version */
				__( '%1$s %2$s or higher is required but was not detected.', 'wc-checkoutsuite' ),
				$entry['label'],
				$entry['required']
			);
		}

		return sprintf(
			/* translators: 1: component name, 2: required version, 3: detected version */
			__( '%1$s %2$s or higher is required. Version %3$s was detected.', 'wc-checkoutsuite' ),
			$entry['label'],
			$entry['required'],
			$entry['observed']
		);
	}
}
