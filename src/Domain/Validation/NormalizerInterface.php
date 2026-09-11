<?php
/**
 * Normalizer contract.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Validation;

use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Converts a raw value into the canonical value that will be stored.
 *
 * Normalization is server-side authority. A mask in the browser is user
 * experience only and never decides what is persisted.
 *
 * @see \ROADMAP.md section 9
 */
interface NormalizerInterface {

	/**
	 * Stable key referenced by a field definition.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Version of this contract the normalizer implements.
	 *
	 * @return string
	 */
	public function contract_version(): string;

	/**
	 * Returns the canonical value.
	 *
	 * @param mixed        $value   Raw value.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed Canonical value.
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed;
}
