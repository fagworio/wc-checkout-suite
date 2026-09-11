<?php
/**
 * Base field type implementation.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Default behaviour shared by the field types shipped with the plugin.
 *
 * Extensions are free to implement {@see FieldTypeInterface} directly; this base
 * exists so a type only overrides what actually differs.
 */
abstract class AbstractFieldType implements FieldTypeInterface {

	/**
	 * Version of the value and settings contract.
	 */
	public const CONTRACT_VERSION = '1.0';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function contract_version(): string {
		return self::CONTRACT_VERSION;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function valueSchema(): array {
		return array( 'type' => 'string' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function supports(): array {
		return array(
			'value'       => true,
			'multiple'    => false,
			'maskable'    => false,
			'conditional' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		return $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		return ValidationResult::valid();
	}

	/**
	 * Whether a value counts as absent.
	 *
	 * Deliberately not `empty()`: `0`, `'0'`, `false` and `[]` are legitimate
	 * stored values and must stay distinguishable from an absent one.
	 *
	 * @see \ROADMAP.md section 5
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	public static function is_absent( mixed $value ): bool {
		return null === $value || '' === $value;
	}
}
