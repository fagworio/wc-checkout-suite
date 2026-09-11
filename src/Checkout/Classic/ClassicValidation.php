<?php
/**
 * Classic checkout validation wiring.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Classic;

use WCCheckoutSuite\Domain\Registries;

/**
 * Connects the value pipeline to the classic checkout's server-side flow.
 *
 * Two hooks, and the choice of each is part of the answer to "POST adulterado
 * falha mesmo sem JS".
 *
 * `woocommerce_checkout_posted_data` runs inside `get_posted_data()`, before the
 * session is updated and before anything is validated. Its return value is the
 * array the whole checkout then works from, so it is the only place where a
 * normalized value becomes the value the store acts on. Nothing here depends on
 * JavaScript having run: the request body is read by WooCommerce, and the
 * normalization and validation happen on the server either way.
 *
 * `woocommerce_after_checkout_validation` runs at the end of
 * `WC_Checkout::validate_checkout()` and hands over the `WP_Error` that
 * `process_checkout()` turns into customer-facing notices. Adding to that error
 * is what makes the checkout stop, and the `id` carried with each error is what
 * makes the notice point at the right field.
 *
 * The rules themselves live in {@see ClassicSubmission}; this class only binds
 * them to WooCommerce.
 *
 * @see ROADMAP.md section 10
 */
final class ClassicValidation {

	/**
	 * The pipeline adapter, built on first use.
	 *
	 * @var ClassicSubmission|null
	 */
	private ?ClassicSubmission $submission = null;

	/**
	 * Outcome of the submission currently being processed.
	 *
	 * @var array<string, \WCCheckoutSuite\Domain\Validation\ProcessedValue>
	 */
	private array $results = array();

	/**
	 * Whether the submitted values were already run through the pipeline.
	 *
	 * @var bool
	 */
	private bool $scanned = false;

	/**
	 * Registers the checkout hooks.
	 *
	 * Registered unconditionally: both hooks only fire while WooCommerce is
	 * processing a checkout, so an admin screen or a REST call pays nothing.
	 *
	 * One instance serves both hooks on purpose. The value is normalized once and
	 * validated against that same outcome; running the pipeline a second time on
	 * the normalized value would judge the customer on a value they never typed —
	 * a mask that shortens a forged 3000-character value would hide the length
	 * error it should have raised.
	 *
	 * The instance is returned so the order-writing half can read the same
	 * outcome. Persisting a value that was not the one validated would be the
	 * other way to break the same promise.
	 *
	 * @return self
	 */
	public static function register(): self {
		$validation = new self();

		add_filter( 'woocommerce_checkout_posted_data', array( $validation, 'normalize_posted_data' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $validation, 'collect_errors' ), 20, 2 );

		return $validation;
	}

	/**
	 * The typed canonical values this submission produced that may be stored.
	 *
	 * The typed form and not the carried one. What WooCommerce's posted data
	 * holds is WooCommerce's representation — an unchecked box is an empty string
	 * there — while what belongs on the order is the value the field actually has,
	 * which for that same box is `false`. WCCS-022 keeps them apart deliberately;
	 * this is the seam where the typed form leaves the checkout.
	 *
	 * A discarded value is not here, and neither is an invalid one: nothing that
	 * was rejected reaches an order.
	 *
	 * @return array<string, mixed>
	 */
	public function storable_values(): array {
		$values = array();

		foreach ( $this->results as $id => $processed ) {
			if ( $processed->is_storable() ) {
				$values[ (string) $id ] = $processed->value();
			}
		}

		return $values;
	}

	/**
	 * Normalizes the posted values before WooCommerce uses them.
	 *
	 * @param array<string, mixed> $data Values WooCommerce built from the request.
	 * @return array<string, mixed>
	 */
	public function normalize_posted_data( array $data ): array {
		$outcome = $this->submission()->normalize( $data, PublishedDocument::read()->fields() );

		$this->results = $outcome['results'];
		$this->scanned = true;

		return $outcome['values'];
	}

	/**
	 * Adds the pipeline's errors to the checkout's error collection.
	 *
	 * @param array<string, mixed> $data   Values WooCommerce is validating.
	 * @param mixed                $errors Error collection, a `WP_Error` in every
	 *                                     flow WooCommerce itself runs.
	 * @return void
	 */
	public function collect_errors( array $data, mixed $errors ): void {
		// Typed loosely and checked rather than declared. The action is public,
		// so a third party can fire it with anything; refusing to add errors to
		// something that is not an error collection is better than a fatal error
		// during someone else's checkout.
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		if ( ! $this->scanned ) {
			// Reached when the checkout is validated without the posted data
			// having been filtered — a caller that builds its own array. The
			// values are then judged as they arrived.
			$this->results = $this->submission()->normalize( $data, PublishedDocument::read()->fields() )['results'];
			$this->scanned = true;
		}

		foreach ( $this->submission()->errors( $this->results ) as $error ) {
			$errors->add(
				$error['field'] . '_wccs_' . $error['code'],
				$error['message'],
				array( 'id' => $error['field'] )
			);
		}
	}

	/**
	 * The pipeline adapter.
	 *
	 * @return ClassicSubmission
	 */
	private function submission(): ClassicSubmission {
		if ( null === $this->submission ) {
			$this->submission = new ClassicSubmission( Registries::boot()->value_processor() );
		}

		return $this->submission;
	}
}
