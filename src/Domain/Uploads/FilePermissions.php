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
 * **Nothing beyond the safe defaults is granted by being enabled.** A link that
 * declares no actions — the shape the inspector writes when a merchant only turns
 * the destination on — allows showing the name and reading the file. The customer
 * account surface also includes replacing that customer's own document in this
 * default, because an upload field there must be usable immediately. Other
 * operational acts, such as approval, must be chosen explicitly.
 *
 * @package WCCheckoutSuite
 */

namespace WCCheckoutSuite\Domain\Uploads;

use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Fields\FieldBinding;
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
	 * The union over the field's uses in that destination. A field may be used more than
	 * once in the same area (§3.3), and the question this answers — "may this area do this
	 * with this value at all?" — is true when any of its uses allows it. A surface that
	 * draws one use asks {@see self::actions_for_binding()} instead, because that is the
	 * decision that belongs to the use.
	 *
	 * @param FieldDefinition $definition  Definition.
	 * @param string          $destination Destination key.
	 * @return array<int, string> Allowed actions, empty when the destination is off.
	 */
	public static function actions( FieldDefinition $definition, string $destination ): array {
		$allowed = array();

		foreach ( $definition->bindings_for( $destination ) as $binding ) {
			$allowed = array_merge( $allowed, self::actions_for_binding( $binding, $destination ) );
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * The actions one use of a field allows in its destination.
	 *
	 * @param FieldBinding $binding     Binding.
	 * @param string       $destination Destination key.
	 * @return array<int, string> Allowed actions, empty when the use is not visible.
	 */
	public static function actions_for_binding( FieldBinding $binding, string $destination ): array {
		if ( ! $binding->is_visible() ) {
			return array();
		}

		$performable = DefinitionVocabulary::actions_for_destination( $destination );

		$declared = array() !== $binding->permissions()
			? $binding->permissions()
			: self::default_actions( $destination );

		return array_values( array_intersect( $declared, $performable ) );
	}

	/**
	 * Safe defaults for a destination.
	 *
	 * A new customer-account file must be usable immediately: the customer can
	 * send/replace their document and download the current version. Other surfaces
	 * keep the conservative read-only defaults until the merchant explicitly grants
	 * an operational action in the binding inspector.
	 *
	 * @param string $destination Destination.
	 * @return array<int, string>
	 */
	private static function default_actions( string $destination ): array {
		$actions = self::DEFAULT_ACTIONS;

		if ( 'customer_account' === $destination ) {
			$actions[] = 'resubmit';
		}

		return $actions;
	}

	/**
	 * Whether one use of a field allows one action in its destination.
	 *
	 * @param FieldBinding $binding     Binding.
	 * @param string       $destination Destination key.
	 * @param string       $action      Action key.
	 * @return bool
	 */
	public static function allows_binding( FieldBinding $binding, string $destination, string $action ): bool {
		return in_array( $action, self::actions_for_binding( $binding, $destination ), true );
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
