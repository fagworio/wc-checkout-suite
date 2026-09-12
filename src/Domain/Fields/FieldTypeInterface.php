<?php
/**
 * Field type contract.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields;

/**
 * Contract every field type must satisfy.
 *
 * This is the public extension point: a third-party plugin registers a type by
 * implementing this interface and hooking `wccs_register_field_types`. No core
 * file has to be edited, and there is no closed factory anywhere.
 *
 * The contract is intentionally small. Renderers, formatters and persistence
 * adapters live in their own contracts so the domain never depends on React,
 * HTML or WC_Order.
 *
 * @see \ROADMAP.md section 6
 */
interface FieldTypeInterface {

	/**
	 * Stable machine key of the type, e.g. `text` or `br.cnpj`.
	 *
	 * Once published the key never changes: it is part of the stored schema.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Human readable, translatable label shown in the admin picker.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Version of the value/settings contract this type implements.
	 *
	 * A breaking change to a type's value or settings shape must bump this so
	 * stored definitions can be migrated instead of silently misread.
	 *
	 * @return string
	 */
	public function contract_version(): string;

	/**
	 * JSON-schema-like description of the value this type stores.
	 *
	 * Describes the canonical value, not the rendered control. Used to validate
	 * submitted values on the server and to generate fixtures.
	 *
	 * The camelCase name is fixed by the published extension contract in
	 * ROADMAP.md section 6; renaming it would break third-party implementations,
	 * so the coding standard is waived for this declaration only.
	 *
	 * @return array<string, mixed>
	 */
	public function valueSchema(): array; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Contract name from ROADMAP.md section 6.

	/**
	 * JSON-schema-like description of the per-type settings the admin may edit.
	 *
	 * The admin inspector renders only what this declares, so a type can never
	 * be shown a setting it does not support. The camelCase name is fixed by the
	 * contract in ROADMAP.md section 6, as explained above.
	 *
	 * @return array<string, mixed>
	 */
	public function settingsSchema(): array; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Contract name from ROADMAP.md section 6.

	/**
	 * Capabilities this type declares, e.g. `value`, `file`, `multiple`, `maskable`.
	 *
	 * Declared capabilities are what the compatibility matrix reads. A type must
	 * never claim a capability it does not actually implement.
	 *
	 * @return array<string, bool>
	 */
	public function supports(): array;

	/**
	 * Converts an accepted raw value into the canonical stored value.
	 *
	 * Normalization is server-side authority. A mask in the browser is UX only.
	 *
	 * @param mixed        $value   Raw value.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed Canonical value.
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed;

	/**
	 * Validates a canonical value.
	 *
	 * @param mixed        $value   Value to validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult;
}
