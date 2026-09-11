<?php
/**
 * The submitted checkout values, as the Suite sees them.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Validation\ProcessedValue;
use WCCheckoutSuite\Domain\Validation\ValueProcessor;

/**
 * Runs a classic checkout submission through the value pipeline.
 *
 * This is the adapter half of "integrar normalização e validação final": it takes
 * the values WooCommerce built from the request and the published definitions,
 * and answers two questions — what the canonical value of each field is, and
 * what is wrong with it. The class is pure: it never reads the request, never
 * touches `WC_Order` and never calls a WordPress hook, so the whole decision can
 * be unit tested without a checkout page.
 *
 * Three rules are enforced here rather than described, and each one is a place
 * where the obvious implementation is wrong.
 *
 * 1. **Only the fields the form actually carried are examined.** The test is
 *    membership of the posted data, not the definition list. A definition whose
 *    field the adapter could not render — a file upload, a content block — is not
 *    on the checkout, so a forged request cannot make it appear, and a required
 *    field nobody can fill cannot block a customer forever.
 *
 * 2. **Only a textual canonical value is carried back.** The Suite normalizes
 *    strings: masks, digits, case, surrounding space. WooCommerce's posted data
 *    is its own representation, and for a checkbox the two disagree — it uses the
 *    empty string for "unchecked" and `false` means something else again. Writing
 *    the Suite's boolean back would make WooCommerce's own required check
 *    (`'' === $data[ $key ]`) miss an unchecked box, so a forged request could
 *    skip a required consent. The typed canonical value is still computed and
 *    still validated here; what is *stored* as typed is WCCS-023's job, where the
 *    order is written.
 *
 * 3. **Requiredness is not reported.** WooCommerce enforces it from the same
 *    field array the form was built from, including its own rules about which
 *    fieldsets this request skips — shipping is not validated when the customer
 *    is not shipping elsewhere. A second requiredness opinion here would either
 *    repeat WooCommerce's message or reject a customer for a field WooCommerce
 *    deliberately ignores. The pipeline still computes it, and the integration
 *    proof asserts the two layers agree: the error the customer sees is
 *    WooCommerce's, and it names the same field.
 *
 * @see ROADMAP.md sections 9 and 10
 */
final class ClassicSubmission {

	/**
	 * Constructor.
	 *
	 * @param ValueProcessor $processor Adapter-agnostic value pipeline.
	 */
	public function __construct( private ValueProcessor $processor ) {
	}

	/**
	 * Normalizes the posted values and reports what was validated.
	 *
	 * @param array<string, mixed>             $data        Posted values.
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return array{values: array<string, mixed>, results: array<string, ProcessedValue>}
	 */
	public function normalize( array $data, array $definitions ): array {
		$values  = $data;
		$results = array();

		foreach ( $this->definitions( $data, $definitions ) as $definition ) {
			$id = $definition->id();

			$processed = $this->processor->process( $definition, $data[ $id ], $this->context() );

			$results[ $id ] = $processed;
			$values[ $id ]  = $this->carried( $data[ $id ], $processed );
		}

		return array(
			'values'  => $values,
			'results' => $results,
		);
	}

	/**
	 * Errors to report for a submission, ready for the checkout.
	 *
	 * Each entry names the field so the notice can be attached to it. WooCommerce
	 * renders the `id` as a `data-id` attribute on the notice, links the message
	 * to that field and places it inline beside it, which is what "erros apontam
	 * campos corretos" means in the classic checkout.
	 *
	 * @param array<string, ProcessedValue> $results Outcome of {@see self::normalize()}.
	 * @return array<int, array{field: string, code: string, message: string}>
	 */
	public function errors( array $results ): array {
		$errors = array();

		foreach ( $results as $id => $processed ) {
			foreach ( $processed->result()->errors() as $error ) {
				$code = (string) $error['code'];

				// Requiredness belongs to the layer that knows which fieldsets
				// this request validates. See rule 3 above.
				if ( 'required' === $code ) {
					continue;
				}

				$errors[] = array(
					'field'   => (string) $id,
					'code'    => $code,
					'message' => (string) $error['message'],
				);
			}
		}

		return $errors;
	}

	/**
	 * The definitions this submission actually covers.
	 *
	 * @param array<string, mixed>             $data        Posted values.
	 * @param array<int, array<string, mixed>> $definitions Published field definitions.
	 * @return array<int, FieldDefinition>
	 */
	private function definitions( array $data, array $definitions ): array {
		$covered = array();

		foreach ( $definitions as $raw ) {
			// A decoded document is not guaranteed to hold objects: the option is
			// JSON, and a corrupt one decodes to whatever was written there. A
			// definition that is not an array is skipped, the same way the
			// adapter skips it when it draws the form.
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );

			if ( ! array_key_exists( $definition->id(), $data ) ) {
				continue;
			}

			$covered[] = $definition;
		}

		return $covered;
	}

	/**
	 * The value to carry back into WooCommerce's posted data.
	 *
	 * @param mixed          $posted    Value WooCommerce built from the request.
	 * @param ProcessedValue $processed Result of the pipeline.
	 * @return mixed
	 */
	private function carried( mixed $posted, ProcessedValue $processed ): mixed {
		if ( $processed->is_discarded() ) {
			// Section 11: a field hidden by a rule stores nothing, so its
			// residual value is dropped here instead of travelling to the order.
			return '';
		}

		if ( ! $processed->result()->is_valid() ) {
			// A rejected value is never replaced by a half-normalized one. What
			// the customer typed stays where the form can show it again.
			return $posted;
		}

		$canonical = $processed->value();

		return is_string( $canonical ) ? $canonical : $posted;
	}

	/**
	 * Trusted context handed to the types and validators.
	 *
	 * Deliberately empty apart from the adapter name. Section 11 requires the
	 * server to recompute conditions from trusted context, and nothing about the
	 * request body is trusted context — a rule that reads the submitted form to
	 * decide whether a field was required is a rule an attacker can turn off.
	 * Which entries a conditions engine will need is F06's decision; adding them
	 * here would be a promise nothing reads.
	 *
	 * @return FieldContext
	 */
	private function context(): FieldContext {
		return new FieldContext( array(), 'classic' );
	}
}
