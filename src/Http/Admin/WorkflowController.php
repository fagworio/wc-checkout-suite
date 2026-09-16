<?php
/**
 * The workflows route.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Http\Admin;

use WCCheckoutSuite\Domain\Conditions\Sources;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Statuses\OrderStatusRegistry;
use WCCheckoutSuite\Domain\Workflow\WorkflowEngine;
use WCCheckoutSuite\Domain\Workflow\WorkflowEvaluator;
use WCCheckoutSuite\Domain\Workflow\WorkflowRepository;
use WCCheckoutSuite\Domain\Workflow\WorkflowSimulator;
use WCCheckoutSuite\Domain\Workflow\Workflows;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reading, replacing and simulating the store's automations.
 *
 * Three operations and no more. The list is a list because a workflow is a row the merchant
 * arranges; the replacement is one request for the same reason the statuses are; and the simulation
 * is a **third** route rather than a flag on the second, because it must be impossible to mistake a
 * simulation for a write — it neither saves nor executes, and giving it its own verb is what makes
 * that visible in a log, in a test and in a permission review.
 *
 * @see \ROADMAP.md sections 13.2 and 13.6
 */
final class WorkflowController {

	/**
	 * Path of the workflows route.
	 */
	public const ROUTE_WORKFLOWS = '/workflows';

	/**
	 * Path of the simulation route.
	 */
	public const ROUTE_SIMULATE = '/workflows/simulate';

	/**
	 * The route names the administration client uses.
	 *
	 * @return array<string, string>
	 */
	public static function routes(): array {
		return array(
			'workflows' => self::ROUTE_WORKFLOWS,
			'simulate'  => self::ROUTE_SIMULATE,
		);
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_WORKFLOWS,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_workflows' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_workflows' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'workflows' => array(
							'type'        => 'array',
							'required'    => true,
							'description' => 'The whole list of the store\'s automations.',
						),
						'revision'  => array(
							'type'        => 'integer',
							'required'    => false,
							'description' => 'Revision read by the editor for compare-and-swap.',
						),
					),
				),
			)
		);

		register_rest_route(
			SchemaController::rest_namespace(),
			self::ROUTE_SIMULATE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'simulate' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'sample' => array(
						'type'        => 'object',
						'required'    => false,
						'description' => 'Values to test the rules against. Nothing is written.',
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may manage this store's automations.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error(
			'wccs_forbidden',
			__( 'You are not allowed to manage this store\'s automations.', 'wc-checkoutsuite' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Every automation, with the vocabulary and the states a workflow may name.
	 *
	 * The vocabulary travels with the list so the editor offers exactly what the validator accepts —
	 * including the two lists of strategies it will not accept, which the screen shows as
	 * unavailable rather than hiding.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_workflows( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$repository = new WorkflowRepository();

		return new WP_REST_Response(
			array(
				'workflows'  => $repository->raw(),
				'revision'   => $repository->revision(),
				'vocabulary' => Workflows::to_array(),
				'statuses'   => WorkflowSimulator::statuses(),
				'paid'       => array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) ),
			),
			200
		);
	}

	/**
	 * Replaces the store's automations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_workflows( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$incoming   = $request->get_param( 'workflows' );
		$incoming   = is_array( $incoming ) ? $incoming : array();
		$repository = new WorkflowRepository();
		$expected   = $request->get_param( 'revision' );

		if ( null !== $expected && (int) $expected !== $repository->revision() ) {
			return new WP_Error(
				'wccs_workflows_conflict',
				__( 'The workflows changed in another session. Reload them before saving.', 'wc-checkoutsuite' ),
				array(
					'status'   => 409,
					'revision' => $repository->revision(),
				)
			);
		}

		$result = $repository->save( $incoming, OrderStatusRegistry::known_ids() );

		if ( ! $result->is_valid() ) {
			return new WP_Error(
				'wccs_invalid_workflows',
				__( 'These automations cannot be stored as they are.', 'wc-checkoutsuite' ),
				array(
					'status' => 400,
					'errors' => $result->errors(),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'workflows' => $repository->raw(),
				'revision'  => $repository->revision(),
				'overlaps'  => WorkflowEvaluator::overlaps(
					( new WorkflowRepository() )->raw(),
					Workflows::TRIGGER_CHECKOUT_SUBMITTED,
					new FieldContext( array(), 'workflow' )
				),
			),
			200
		);
	}

	/**
	 * Answers what the configuration would do, and writes nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function simulate( WP_REST_Request $request ): WP_REST_Response {
		$sample = $request->get_param( 'sample' );
		$sample = is_array( $sample ) ? $sample : array();

		$trigger = $request->get_param( 'trigger' );
		$trigger = is_string( $trigger ) && '' !== $trigger ? $trigger : Workflows::TRIGGER_CHECKOUT_SUBMITTED;

		return new WP_REST_Response(
			WorkflowSimulator::run(
				( new WorkflowRepository() )->raw(),
				$trigger,
				$this->context( $sample ),
				$sample
			),
			200
		);
	}

	/**
	 * Builds a context from the values the merchant typed.
	 *
	 * Only the sources the vocabulary publishes are read, and only as the type each one declares:
	 * the simulation must not become a way to give a rule a value the pipeline would never hand it.
	 *
	 * @param array<string, mixed> $sample Values.
	 * @return FieldContext
	 */
	private function context( array $sample ): FieldContext {
		$values = array();

		foreach ( Sources::all() as $key => $source ) {
			if ( $source->is_reference() || ! array_key_exists( $key, $sample ) ) {
				continue;
			}

			$value = $sample[ $key ];

			$values[ $key ] = match ( $source->type() ) {
				'boolean' => (bool) $value,
				'number'  => is_numeric( $value ) ? (float) $value : 0.0,
				'list'    => is_array( $value )
					? array_values( array_map( 'strval', $value ) )
					: array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) ),
				default   => (string) $value,
			};
		}

		$fields = isset( $sample['fields'] ) && is_array( $sample['fields'] ) ? $sample['fields'] : array();

		$values['fields'] = $fields;

		return new FieldContext( $values, 'workflow' );
	}

	/**
	 * The engine, exposed for the diagnostics screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics(): array {
		return array(
			'engine'  => WorkflowEngine::class,
			'hooks'   => array( WorkflowEngine::HOOK_CLASSIC, WorkflowEngine::HOOK_API ),
			'payment' => Workflows::payment_actions_available(),
		);
	}
}
