<?php
/**
 * Field type registry.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Holds every registered field type.
 *
 * There is no factory with a list of known types. A type exists because it was
 * registered, which is what lets an external plugin add one without editing the
 * core.
 *
 * @see \ROADMAP.md section 6
 */
final class FieldTypeRegistry extends AbstractRegistry {

	/**
	 * The control a type declares it is rendered by.
	 *
	 * The question the adapters ask about a type this plugin does not know. A type that
	 * declares nothing answers with an empty string, and an empty string is rendered
	 * nowhere — which is the state a contributed type is in until it declares one.
	 *
	 * @param string $type Field type.
	 * @return string Control name, or an empty string.
	 */
	public function control( string $type ): string {
		$registered = $this->get( $type );

		if ( ! is_object( $registered ) || ! method_exists( $registered, 'supports' ) ) {
			return '';
		}

		$supports = $registered->supports();
		$control  = is_array( $supports ) && isset( $supports['control'] ) ? (string) $supports['control'] : '';

		return 'control' === $control ? '' : $control;
	}


	/**
	 * Category of each registered type, keyed by type key.
	 *
	 * Kept beside the registry rather than inside it because the shared
	 * {@see AbstractRegistry} serves seven registries, only one of which groups
	 * its items for a picker.
	 *
	 * @var array<string, string>
	 */
	private array $categories = array();

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'field type';
	}

	/**
	 * Registers a field type.
	 *
	 * The category is an argument rather than a method on the type on purpose:
	 * {@see FieldTypeInterface} is published, so adding a method to it would
	 * break every implementation already in the wild. An extension that omits
	 * the category keeps working and appears under "Other".
	 *
	 * @param FieldTypeInterface $type     Field type.
	 * @param string             $source   Origin, for diagnostics.
	 * @param string             $category Picker category key.
	 * @return bool
	 */
	public function register_type( FieldTypeInterface $type, string $source = 'core', string $category = FieldCategory::GENERAL ): bool {
		$registered = $this->register( $type->key(), $type, $source );

		if ( $registered ) {
			$this->categories[ $type->key() ] = '' === $category ? FieldCategory::GENERAL : $category;
		}

		return $registered;
	}

	/**
	 * Category of a registered type.
	 *
	 * @param string $key Type key.
	 * @return string
	 */
	public function category_of( string $key ): string {
		return $this->categories[ $key ] ?? FieldCategory::GENERAL;
	}

	/**
	 * Returns a field type or null.
	 *
	 * @param string $key Type key.
	 * @return FieldTypeInterface|null
	 */
	public function type( string $key ): ?FieldTypeInterface {
		$type = $this->get( $key );

		return $type instanceof FieldTypeInterface ? $type : null;
	}

	/**
	 * All field types, keyed by key.
	 *
	 * @return array<string, FieldTypeInterface>
	 */
	public function types(): array {
		/**
		 * Registered field types, keyed by type key.
		 *
		 * @var array<string, FieldTypeInterface>
		 */
		$types = array_filter(
			$this->all(),
			static function ( $type ): bool {
				return $type instanceof FieldTypeInterface;
			}
		);

		return $types;
	}

	/**
	 * Labels of every registered type, keyed by key.
	 *
	 * This is what the admin picker shows; a type that was never registered
	 * simply cannot appear.
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		$labels = array();

		foreach ( $this->types() as $key => $type ) {
			$labels[ $key ] = $type->label();
		}

		return $labels;
	}
	/**
	 * Everything the admin picker needs, grouped by category.
	 *
	 * Only registered types appear. There is no hard-coded list anywhere in the
	 * admin, so a type an extension registers shows up here and nothing else has
	 * to change.
	 *
	 * `types` is returned as a map as well as inside the groups so the client can
	 * resolve a definition's type without walking the groups.
	 *
	 * @return array{categories: array<int, array{key: string, label: string, types: array<int, array<string, mixed>>}>, types: array<string, array<string, mixed>>}
	 */
	public function catalogue(): array {
		$types = array();

		foreach ( $this->types() as $key => $type ) {
			$types[ $key ] = array(
				'key'             => $key,
				'label'           => $type->label(),
				'category'        => $this->category_of( $key ),
				'source'          => $this->source_of( $key ),
				'contractVersion' => $type->contract_version(),
				'supports'        => $type->supports(),
				'valueSchema'     => $type->valueSchema(),
				'settingsSchema'  => $type->settingsSchema(),
			);
		}

		$grouped = array();

		foreach ( $types as $entry ) {
			$grouped[ (string) $entry['category'] ][] = $entry;
		}

		// Known categories first in the declared order, then anything an
		// extension invented, so a third-party category is never hidden.
		$category_keys = array_keys( $grouped );

		usort(
			$category_keys,
			static function ( string $a, string $b ): int {
				$weight = FieldCategory::weight( $a ) <=> FieldCategory::weight( $b );

				return 0 !== $weight ? $weight : strcmp( $a, $b );
			}
		);

		$categories = array();

		foreach ( $category_keys as $category ) {
			$entries = $grouped[ $category ];

			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return strcasecmp( (string) $a['label'], (string) $b['label'] );
				}
			);

			$categories[] = array(
				'key'   => $category,
				'label' => FieldCategory::label( $category ),
				'types' => $entries,
			);
		}

		return array(
			'categories' => $categories,
			'types'      => $types,
		);
	}
}
