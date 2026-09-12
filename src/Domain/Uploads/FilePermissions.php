<?php
/**
 * What one destination may do with one field's file.
 *
 * The matrix in ROADMAP.md section 12 is data here rather than a paragraph: each
 * destination link declares which actions it allows, and this answers whether one of
 * them is allowed. Two rules shape it.
 *
 * **A link cannot grant what its destination cannot perform.** The declared actions
 * are intersected with the destination's own list, so a document written by hand
 * cannot give a customer surface the right to approve.
 *
 * **Nothing is granted by being enabled.** A link that declares no actions — the shape
 * the inspector writes when a merchant only turns the destination on — allows showing
 * the name and reading the file, and nothing else. Approving and replacing are acts,
 * and an act is chosen.
 *
 * @package WCCheckoutSuite
 */

namespace WCCheckoutSuite\Domain\Uploads;

use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;

/**
 * The per-destination permissions of a field's file.
 */
final class FilePermissions {

	/**
	 * What an enabled destination allows when it declares no actions.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_ACTIONS = array( 'show_metadata', 'view', 'download' );

	/**
	 * Whether a definition stores a file at all.
	 *
	 * The type is the authority, not a list of keys written here: a third-party type
	 * that declares `file` gets the same permissions without this class knowing it.
	 *
	 * @param FieldDefinition $definition Definition.
	 * @return bool
	 */
	public static function is_file( FieldDefinition $definition ): bool {
		$type = Registries::instance()->types()->get( $definition->type() );

		if ( null === $type ) {
			return false;
		}

		$supports = $type->supports();

		return isset( $supports['file'] ) && true === $supports['file'];
	}

	/**
	 * The actions one destination allows for one field.
	 *
	 * @param FieldDefinition $definition  Definition.
	 * @param string          $destination Destination key.
	 * @return array<int, string> Allowed actions, empty when the destination is off.
	 */
	public static function actions( FieldDefinition $definition, string $destination ): array {
		$link = $definition->destinations()[ $destination ] ?? array();

		if ( empty( $link['enabled'] ) ) {
			return array();
		}

		$performable = DefinitionVocabulary::actions_for_destination( $destination );

		$declared = isset( $link['actions'] ) && is_array( $link['actions'] ) && array() !== $link['actions']
			? array_map( 'strval', $link['actions'] )
			: self::DEFAULT_ACTIONS;

		return array_values( array_intersect( $declared, $performable ) );
	}

	/**
	 * Whether one action is allowed in one destination.
	 *
	 * @param FieldDefinition $definition  Definition.
	 * @param string          $destination Destination key.
	 * @param string          $action      Action key.
	 * @return bool
	 */
	public static function allows( FieldDefinition $definition, string $destination, string $action ): bool {
		return in_array( $action, self::actions( $definition, $destination ), true );
	}
}
