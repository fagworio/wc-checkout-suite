<?php
/**
 * What each checkout adapter provides on its own.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;

/**
 * The capability matrix of ROADMAP.md section 8, as data.
 *
 * The matrix exists so the admin can say "Nativo", "Componente da Suite",
 * "Limitado" or "Não suportado" with a specific reason, and so nothing promises a
 * parity the checkout cannot deliver. This class holds the part of it that is
 * machine-checkable: for a given field type and a given adapter, what does that
 * adapter provide by itself.
 *
 * The Block answer is not an opinion. WCCS-003 verified against the installed
 * WooCommerce 11.1.0 that the additional-fields API registers exactly `text`,
 * `select` and `checkbox` — and that `date`, which section 8 lists, is *not*
 * among them. Everything else therefore needs a Suite component, which is what
 * section 8 calls "Blocks próprio" and what F07 provides.
 *
 * The Classic answer is "anything this plugin has a rendering for", and the
 * qualifier is the behavioural one rather than a shrug: the classic checkout
 * renders through PHP hooks this plugin owns, so a type with a rendering is
 * rendered there — and a type with none is skipped and said so, both by the
 * adapter when it draws the form and by this class when the merchant is told what
 * to expect.
 *
 * @see ROADMAP.md section 8
 */
final class AdapterCapabilities {

	/**
	 * The classic shortcode checkout.
	 */
	public const CLASSIC = 'classic';

	/**
	 * The Block checkout.
	 */
	public const BLOCKS = 'blocks';

	/**
	 * Level meaning the adapter provides the type by itself.
	 */
	public const NATIVE = 'native';

	/**
	 * Level meaning a Suite component is needed to render the type.
	 */
	public const COMPONENT = 'component';

	/**
	 * Level meaning the adapter cannot provide the type at all.
	 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * Types the Block checkout provides through its own field API.
	 *
	 * Verified in WCCS-003 against WooCommerce 11.1.0, which registers these
	 * three and no others.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKS_NATIVE = array( 'text', 'select', 'checkbox' );

	/**
	 * The adapters, with the label the admin shows.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function adapters(): array {
		return array(
			array(
				'value'       => self::CLASSIC,
				'label'       => __( 'Classic checkout', 'wc-checkoutsuite' ),
				'description' => __(
					'The shortcode checkout, rendered through the plugin\'s own PHP hooks.',
					'wc-checkoutsuite'
				),
			),
			array(
				'value'       => self::BLOCKS,
				'label'       => __( 'Block checkout', 'wc-checkoutsuite' ),
				'description' => __(
					'The Block checkout, which renders fields through its own API.',
					'wc-checkoutsuite'
				),
			),
		);
	}

	/**
	 * Valid adapter values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_values( array_map( static fn( array $entry ): string => $entry['value'], self::adapters() ) );
	}

	/**
	 * What an adapter provides for a field type.
	 *
	 * @param string $type    Registered field type key.
	 * @param string $adapter Adapter value.
	 * @return array{level: string, reason: string}
	 */
	public static function for_type( string $type, string $adapter ): array {
		if ( self::CLASSIC === $adapter ) {
			// The classic adapter renders through this plugin's own hooks, which is a
			// capability of the adapter and not a promise about every type: a type for
			// which no extension registers a control has no rendering there, and the
			// adapter says so when it draws the form. Asking the adapter the same
			// question here is what keeps this matrix from telling a merchant that a
			// field will be rendered while the checkout silently skips it — the
			// disagreement was found by the recovery run for a deactivated extension.
			if ( ClassicAdapter::can_render( $type ) ) {
				return array(
					'level'  => self::NATIVE,
					'reason' => __(
						'The classic checkout renders this through the plugin\'s own hooks.',
						'wc-checkoutsuite'
					),
				);
			}

			return array(
				'level'  => self::UNSUPPORTED,
				'reason' => sprintf(
					/* translators: %s: field type key */
					__(
						'The classic checkout has no rendering for the type "%s", so the field is skipped rather than shown as something else.',
						'wc-checkoutsuite'
					),
					$type
				),
			);
		}

		if ( self::BLOCKS !== $adapter ) {
			return array(
				'level'  => self::UNSUPPORTED,
				'reason' => sprintf(
					/* translators: %s: adapter key */
					__( 'The adapter "%s" is not one this build knows.', 'wc-checkoutsuite' ),
					$adapter
				),
			);
		}

		if ( in_array( $type, self::BLOCKS_NATIVE, true ) ) {
			return array(
				'level'  => self::NATIVE,
				'reason' => __(
					'Provided by the additional-fields API of the Block checkout.',
					'wc-checkoutsuite'
				),
			);
		}

		return array(
			'level'  => self::COMPONENT,
			'reason' => sprintf(
				/* translators: %s: field type key */
				__(
					'The Block checkout API provides text, select and checkbox. A field of type "%s" needs a Suite component.',
					'wc-checkoutsuite'
				),
				$type
			),
		);
	}

	/**
	 * Whether a level means the adapter handles the type on its own.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function is_native( string $level ): bool {
		return self::NATIVE === $level;
	}

	/**
	 * Everything the admin needs to draw the matrix.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array(): array {
		return array(
			'adapters'        => self::adapters(),
			'nativeByAdapter' => array(
				self::CLASSIC => '*',
				self::BLOCKS  => self::BLOCKS_NATIVE,
			),
			'verifiedAgainst' => 'WooCommerce 11.1.0',
		);
	}
}
