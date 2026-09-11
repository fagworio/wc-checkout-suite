<?php
/**
 * Remote validation endpoint.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Checkout;

use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Http\Admin\SchemaController;
use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Answers whether one value is acceptable, against the published document.
 *
 * The endpoint exists because a few rules need something the browser does not
 * have — a list only the server knows, or a provider it cannot reach. Section 10
 * puts it plainly: AJAX only when there is server-exclusive information. Anything
 * the browser can decide on its own is decided there, and the server checks it
 * again at submit.
 *
 * Four things it deliberately is not.
 *
 * **It is not an authorization.** The answer says the value was acceptable at the
 * moment it was asked about, against the revision it named. It carries no token,
 * nothing to cache and nothing to quote back at submit, because section 10 says a
 * response cannot work as permanent authorization and an old "valid" does not
 * authorize a new value. The server revalidates on submit, and the empty answer
 * for an unchanged value is cheap.
 *
 * **It is not an oracle about other customers.** Every rule this endpoint can run
 * is arithmetic about the value itself: no lookup, no registry, nothing that
 * knows whether a document belongs to somebody. The responses are the same shape
 * regardless of the answer, and none of them carries the value back.
 *
 * **It is not a place to run the buyer's code.** The request names a field and a
 * value, and nothing else is read. There is no regex, no callback, no path and no
 * remote endpoint in the contract, because all four were listed as things not to
 * accept.
 *
 * **It is not free.** A session may ask a limited number of times per field per
 * window, which is what keeps the endpoint from being a comfortable way to hammer
 * a store.
 *
 * @see \ROADMAP.md section 10
 */
final class ValidationController {

	/**
	 * Validation route.
	 */
	public const ROUTE_VALIDATE = '/validate';

	/**
	 * Session key holding the attempts, by field.
	 */
	public const SESSION_KEY = 'wccs_validation_attempts';

	/**
	 * Requests allowed per field per window.
	 */
	public const LIMIT = 20;

	/**
	 * Length of the window, in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Every route this controller publishes.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array( 'validate' => self::ROUTE_VALIDATE );
	}

	/**
	 * Registers the REST route.
	 *
	 * Public on purpose: a guest checkout has to be able to ask. What protects it
	 * is the nonce check below, the session limit, and the fact that it can only
	 * ever answer a question about a value the caller already has.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_VALIDATE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'field'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'value'      => array(
						'required' => true,
					),
					'revision'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'request_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'nonce'      => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Validates one value.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function validate( WP_REST_Request $request ): WP_REST_Response {
		$field      = (string) $request->get_param( 'field' );
		$revision   = (int) $request->get_param( 'revision' );
		$request_id = (string) $request->get_param( 'request_id' );

		// A nonce is not protecting a write here; it is what keeps the endpoint
		// from being an oracle any page on the internet can call on a customer's
		// behalf. A guest checkout has one because WordPress issues it to
		// logged-out visitors too.
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), 'wp_rest' ) ) {
			return $this->answer( $request_id, $revision, $field, 'unavailable', 'bad_nonce' );
		}

		$document = PublishedDocument::read();

		// The client names the revision it read. When it is not the published one
		// the checkout page is out of date, and section 13 is explicit about what
		// to do: revalidate against the published revision and inform the change.
		// Refusing would discard a customer's answer over a difference they cannot
		// see; judging the value against rules they never saw would be worse. So
		// the answer is computed against the published document and the difference
		// is reported alongside it.
		$changed = $revision !== $document->revision();

		$definition = $this->find( $document->fields(), $field );

		if ( null === $definition ) {
			return $this->answer( $request_id, $document->revision(), $field, 'unavailable', 'unknown_field' );
		}

		$throttle = $this->throttle( $field );

		if ( ! $throttle['allowed'] ) {
			return $this->answer( $request_id, $document->revision(), $field, 'unavailable', 'rate_limited' );
		}

		$processed = Registries::instance()->value_processor()->process(
			$definition,
			$request->get_param( 'value' ),
			new FieldContext( array(), 'classic' )
		);

		$result = $processed->result();

		if ( $result->is_valid() ) {
			return $this->answer( $request_id, $document->revision(), $field, 'valid', '', '', $changed );
		}

		$first = $result->errors()[0] ?? array(
			'code'    => 'invalid',
			'message' => '',
		);

		return $this->answer(
			$request_id,
			$document->revision(),
			$field,
			'invalid',
			(string) $first['code'],
			(string) $first['message'],
			$changed
		);
	}

	/**
	 * The definition of one field, when the checkout could render it.
	 *
	 * A field that is not in the published document, is archived, or cannot be
	 * rendered is not something the endpoint will judge: there is no form field to
	 * put the answer next to.
	 *
	 * @param array<int, array<string, mixed>> $fields Published fields.
	 * @param string                           $id     Field identifier.
	 * @return FieldDefinition|null
	 */
	private function find( array $fields, string $id ): ?FieldDefinition {
		foreach ( $fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$definition = FieldDefinition::from_array( $raw );

			if ( $definition->id() === $id && $definition->is_enabled() ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Records one attempt and reports whether it is allowed.
	 *
	 * The counters live in the WooCommerce session, which is what "per session"
	 * means here: a new session gets a new allowance, and that is how every
	 * session-scoped limit behaves. What it is for is to stop a script from using
	 * the endpoint as a free compute service, not to police a customer.
	 *
	 * One allowance per field, not one for the whole form. Section 10 says "limit
	 * per session and field" for a reason a single bucket gets wrong: a customer
	 * who corrects one document several times would spend the allowance the rest
	 * of the form needs, and the second field to be checked would be refused for
	 * something the first field did. The first version of this method had exactly
	 * that bug, and the proof caught it by asking about a second field after the
	 * first had exhausted its own.
	 *
	 * @param string $field Field identifier.
	 * @return array{allowed: bool}
	 */
	private function throttle( string $field ): array {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		if ( null === $session ) {
			// No session means no allowance to count and nobody to protect.
			// Refusing here would break the endpoint for a caller that has done
			// nothing wrong.
			return array( 'allowed' => true );
		}

		$stored = $session->get( self::SESSION_KEY );
		$by_key = is_array( $stored ) ? $stored : array();
		$recent = is_array( $by_key[ $field ] ?? null ) ? $by_key[ $field ] : array();
		$now    = time();

		$recent = array_values(
			array_filter(
				array_map( 'intval', $recent ),
				static function ( int $at ) use ( $now ): bool {
					return $at > $now - self::WINDOW;
				}
			)
		);

		$allowed = count( $recent ) < self::LIMIT;

		if ( $allowed ) {
			$recent[] = $now;
		}

		$by_key[ $field ] = $recent;

		$session->set( self::SESSION_KEY, $by_key );

		return array( 'allowed' => $allowed );
	}

	/**
	 * Builds the response.
	 *
	 * The shape is fixed and every field is always present, so a caller cannot
	 * learn anything from which keys arrived. The value the customer typed is
	 * never echoed: it is already on their screen, and repeating it back would put
	 * a document in a response body that did not need to carry one.
	 *
	 * @param string $request_id Request identifier echoed back.
	 * @param int    $revision   Revision the answer applies to.
	 * @param string $field      Field identifier.
	 * @param string $status     One of `valid`, `invalid`, `unavailable`.
	 * @param string $code       Stable machine code, or an empty string.
	 * @param string $message    Translatable message, or an empty string.
	 * @param bool   $changed    Whether the revision the client named is not the published one.
	 * @return WP_REST_Response
	 */
	private function answer(
		string $request_id,
		int $revision,
		string $field,
		string $status,
		string $code = '',
		string $message = '',
		bool $changed = false
	): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'request_id'     => $request_id,
				'revision'       => $revision,
				'field'          => $field,
				'status'         => $status,
				'code'           => $code,
				'message'        => $message,
				'schema_changed' => $changed,
			)
		);

		// A rate-limited or stale answer is still a successful HTTP exchange: the
		// client needs the body to know what happened, and a cache in between
		// must not keep either one.
		$response->header( 'Cache-Control', 'no-store' );

		if ( 'rate_limited' === $code ) {
			$response->set_status( 429 );
		}

		return $response;
	}
}
