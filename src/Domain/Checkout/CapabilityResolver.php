<?php
/**
 * Capability resolver: the limits of every field, before it is published.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Checkout;

use WCCheckoutSuite\Checkout\Blocks\BlocksAdapter;
use WCCheckoutSuite\Checkout\Classic\ClassicAdapter;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * Answers "what will each checkout do with this field" for every field of a
 * document, so the answer is on screen before the publisher button is pressed.
 *
 * WCCS-019 already reports incompatibilities, and this is not a second opinion about
 * them: that check answers *whether something is wrong*, in the vocabulary of
 * warnings; this one answers *what the limits are* for each field, whether or not
 * any of them is a problem. A merchant configuring a field needs the second
 * question answered even when the first one has nothing to say — "four columns is
 * honoured by the classic checkout and is a class name in the Block one" is not an
 * incompatibility, it is a fact the interface should not hide.
 *
 * Four families of limit, and the planning names all four:
 *
 * - **core** — a WooCommerce field cannot be removed, archived or retyped, and the
 *   reason travels with it rather than being discovered at publication;
 * - **width** — the layout the field asks for, and what each adapter does with it;
 * - **section** — the logical location, and where each adapter actually puts it;
 * - **type** — whether the adapter provides the type itself, needs a Suite
 *   component for it, or cannot render it at all.
 *
 * The Block answers come from `BlocksAdapter`, which reads the installed
 * WooCommerce's own list of native types and the document's own definitions. There
 * is deliberately no second table here: a limit that disagreed with the adapter
 * would be the interface promising something the checkout does not do, which is the
 * one thing section 8 and the phase gate both forbid.
 *
 * @see ROADMAP.md section 8
 */
final class CapabilityResolver {

	/**
	 * Level meaning the adapter provides this by itself.
	 */
	public const PROVIDED = 'provided';

	/**
	 * Level meaning the adapter can do it, with something said about how.
	 */
	public const LIMITED = 'limited';

	/**
	 * Level meaning the adapter cannot do it.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Resolves the limits of every field a document declares.
	 *
	 * @param array<int, array<string, mixed>> $fields   Field definitions.
	 * @param array<int, array<string, mixed>> $sections Published sections.
	 * @param CoreFields|null                  $core     Core field inventory, when one is available.
	 * @return array<int, array{field: string, label: string, limits: array<int, array{family: string, adapter: string, level: string, reason: string}>}>
	 */
	public static function resolve( array $fields, array $sections = array(), ?CoreFields $core = null ): array {
		$locations = self::locations( $sections );
		$resolved  = array();

		foreach ( $fields as $raw ) {
			if ( ! is_array( $raw ) || ! isset( $raw['id'] ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );
			$stored     = $definition->to_array();
			$limits     = array();

			foreach ( self::core_limits( $definition, $core ) as $limit ) {
				$limits[] = $limit;
			}

			foreach ( self::width_limits( $stored ) as $limit ) {
				$limits[] = $limit;
			}

			foreach ( self::section_limits( $stored, $locations ) as $limit ) {
				$limits[] = $limit;
			}

			foreach ( self::type_limits( $stored ) as $limit ) {
				$limits[] = $limit;
			}

			$resolved[] = array(
				'field'  => $definition->id(),
				'label'  => $definition->label(),
				'limits' => $limits,
			);
		}

		return $resolved;
	}

	/**
	 * What the core guard says about a field.
	 *
	 * A core field is not merely "protected": what can be done to it and what cannot
	 * is a list, and the difference between renaming a core field and retyping it is
	 * the difference between an edit and a broken order history.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @param CoreFields|null $core       Inventory.
	 * @return array<int, array{family: string, adapter: string, level: string, reason: string}>
	 */
	private static function core_limits( FieldDefinition $definition, ?CoreFields $core ): array {
		if ( 'core' !== $definition->origin() ) {
			return array();
		}

		$reason = __( 'WooCommerce owns this field: it can be renamed, moved and resized, and it cannot be removed, archived or retyped.', 'wc-checkoutsuite' );

		if ( null !== $core ) {
			$inventory = $core->catalogue();

			// The inventory is a flat list of described fields, not a map keyed by
			// identifier, so membership is a search. Reading it as a map — which is
			// what this did first — made the check silently always true, and the
			// drift it exists to report could never appear.
			$known = false;

			foreach ( $inventory['fields'] as $entry ) {
				if ( isset( $entry['id'] ) && $definition->id() === (string) $entry['id'] ) {
					$known = true;

					break;
				}
			}

			// Only an inventory that was actually read can say a field is missing:
			// an unavailable one is empty for its own reason, and reporting the
			// drift it cannot see would be inventing it.
			if ( $inventory['available'] && ! $known ) {
				$reason = __( 'This field claims a WooCommerce field that is no longer in the inventory, so the store cannot honour the override.', 'wc-checkoutsuite' );
			}
		}

		return array(
			array(
				'family'  => 'core',
				'adapter' => 'all',
				'level'   => self::LIMITED,
				'reason'  => $reason,
			),
		);
	}

	/**
	 * What each adapter does with the width the field asks for.
	 *
	 * The classic checkout has a twelve column grid and only two widths in it — wide
	 * and half — so four and three columns are honoured by a class this plugin
	 * applies rather than by the grid. The Block checkout lays out its own fields and
	 * ignores the number entirely. Both are worth saying, and neither is a defect.
	 *
	 * @param array<string, mixed> $stored Stored definition.
	 * @return array<int, array{family: string, adapter: string, level: string, reason: string}>
	 */
	private static function width_limits( array $stored ): array {
		$layout = isset( $stored['layout'] ) && is_array( $stored['layout'] ) ? $stored['layout'] : array();
		$limits = array();

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $viewport ) {
			$width = isset( $layout[ $viewport ] ) ? (int) $layout[ $viewport ] : 12;

			if ( ! in_array( $width, array( 12, 6, 4, 3 ), true ) ) {
				$limits[] = array(
					'family'  => 'width',
					'adapter' => 'all',
					'level'   => self::UNAVAILABLE,
					'reason'  => sprintf(
						/* translators: 1: viewport, 2: width in columns. */
						__( 'The %1$s width of %2$s columns is not one the grid offers, so it is not applied.', 'wc-checkoutsuite' ),
						$viewport,
						(string) $width
					),
				);
			}
		}

		$limits[] = array(
			'family'  => 'width',
			'adapter' => AdapterCapabilities::CLASSIC,
			'level'   => self::PROVIDED,
			'reason'  => __( 'The classic checkout has a twelve column grid: twelve and six are honoured by the grid, and four and three by a class this plugin applies.', 'wc-checkoutsuite' ),
		);

		$limits[] = array(
			'family'  => 'width',
			'adapter' => AdapterCapabilities::BLOCKS,
			'level'   => self::LIMITED,
			'reason'  => __( 'The Block checkout lays out its own fields, so the requested width is not applied there.', 'wc-checkoutsuite' ),
		);

		return $limits;
	}

	/**
	 * Where each adapter actually puts the field.
	 *
	 * @param array<string, mixed>  $stored    Stored definition.
	 * @param array<string, string> $locations Section id to explicit location, when the document declares one.
	 * @return array<int, array{family: string, adapter: string, level: string, reason: string}>
	 */
	private static function section_limits( array $stored, array $locations ): array {
		$section  = isset( $stored['section'] ) ? (string) $stored['section'] : '';
		$location = $locations[ $section ] ?? '';

		if ( '' === $location ) {
			return array(
				array(
					'family'  => 'section',
					'adapter' => 'all',
					'level'   => self::UNAVAILABLE,
					'reason'  => sprintf(
						/* translators: %s: section identifier. */
						__( 'The section "%s" is not part of the document, so no checkout has a place to put this field.', 'wc-checkoutsuite' ),
						$section
					),
				),
			);
		}

		$classic = 'contact' === $location
			? __( 'The classic checkout has no contact step, so the field is drawn with the billing address.', 'wc-checkoutsuite' )
			: __( 'The classic checkout draws the field in this location.', 'wc-checkoutsuite' );

		$blocks = BlocksAdapter::native_location( $location );

		return array(
			array(
				'family'  => 'section',
				'adapter' => AdapterCapabilities::CLASSIC,
				'level'   => 'contact' === $location ? self::LIMITED : self::PROVIDED,
				'reason'  => $classic,
			),
			array(
				'family'  => 'section',
				'adapter' => AdapterCapabilities::BLOCKS,
				'level'   => '' === $blocks ? self::UNAVAILABLE : self::PROVIDED,
				'reason'  => '' === $blocks
					? __( 'The Block checkout has no location this section maps to.', 'wc-checkoutsuite' )
					: sprintf(
						/* translators: %s: Blocks location. */
						__( 'The Block checkout draws the field in its "%s" location.', 'wc-checkoutsuite' ),
						$blocks
					),
			),
		);
	}

	/**
	 * What each adapter does with the type.
	 *
	 * The Block answer comes from the adapter that registers the fields, so the
	 * matrix and the registration cannot disagree: `native` is a field the platform
	 * draws, `controlled` is one this plugin draws, and `restricted` is one neither
	 * does.
	 *
	 * @param array<string, mixed> $stored Stored definition.
	 * @return array<int, array{family: string, adapter: string, level: string, reason: string}>
	 */
	private static function type_limits( array $stored ): array {
		$type = isset( $stored['type'] ) ? (string) $stored['type'] : '';
		$mode = BlocksAdapter::mode_for( $stored );

		$levels = array(
			'native'     => self::PROVIDED,
			'controlled' => self::LIMITED,
			'restricted' => self::UNAVAILABLE,
		);

		$classic = ClassicAdapter::can_render( $type )
			? self::PROVIDED
			: self::UNAVAILABLE;

		return array(
			array(
				'family'  => 'type',
				'adapter' => AdapterCapabilities::CLASSIC,
				'level'   => $classic,
				'reason'  => ClassicAdapter::can_render( $type )
					? __( 'The classic checkout renders this type.', 'wc-checkoutsuite' )
					: sprintf(
						/* translators: %s: field type. */
						__( 'The classic checkout cannot render a "%s" field.', 'wc-checkoutsuite' ),
						$type
					),
			),
			array(
				'family'  => 'type',
				'adapter' => AdapterCapabilities::BLOCKS,
				'level'   => $levels[ $mode ] ?? self::UNAVAILABLE,
				'reason'  => BlocksAdapter::reason( $type ),
			),
		);
	}

	/**
	 * The explicit location of every published section.
	 *
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return array<string, string>
	 */
	private static function locations( array $sections ): array {
		$locations = array();

		foreach ( $sections as $raw ) {
			if ( ! is_array( $raw ) || ! isset( $raw['id'], $raw['location'] ) ) {
				continue;
			}

			$locations[ (string) $raw['id'] ] = (string) $raw['location'];
		}

		return $locations;
	}
}
