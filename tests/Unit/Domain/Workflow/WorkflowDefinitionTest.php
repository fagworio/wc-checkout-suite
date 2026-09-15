<?php
/**
 * A workflow is a plan the store can carry out, not a wish.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Workflow;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Workflow\WorkflowDefinition;
use WCCheckoutSuite\Domain\Workflow\WorkflowEvaluator;
use WCCheckoutSuite\Domain\Workflow\Workflows;
use WCCheckoutSuite\Domain\Workflow\WorkflowValidator;
use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * The model of §3.8, the vocabulary of §13 and the rules that keep the two honest.
 *
 * The properties these tests exist for are the phase's gate written as units: a workflow cannot ask
 * for a strategy this build cannot execute, and it cannot move an order into a state WooCommerce
 * treats as paid while nothing can perform a payment — §13.8's "nenhum status 'pago' é aplicado
 * antes do pagamento".
 */
final class WorkflowDefinitionTest extends TestCase {

	/**
	 * A workflow with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return WorkflowDefinition
	 */
	private static function workflow( array $overrides = array() ): WorkflowDefinition {
		return WorkflowDefinition::from_array(
			array_merge(
				array(
					'id'             => 'quimicos',
					'name'           => 'Produtos químicos',
					'initial_status' => 'analise_pendente',
				),
				$overrides
			)
		);
	}

	/**
	 * The identifier comes from the name, once, and the accents do not become separators.
	 *
	 * @return void
	 */
	public function test_the_identifier_is_readable(): void {
		self::assertSame( 'produtos_quimicos', WorkflowDefinition::unique_id( 'Produtos químicos' ) );
		self::assertSame( 'analise_de_documentos', WorkflowDefinition::unique_id( 'Análise de documentos' ) );
		self::assertSame( 'workflow', WorkflowDefinition::unique_id( '!!!' ) );
		self::assertSame(
			'quimicos_2',
			WorkflowDefinition::unique_id( 'Químicos', array( 'quimicos' ) )
		);
	}

	/**
	 * A transition is a decision, and the definition answers only the ones it names.
	 *
	 * @return void
	 */
	public function test_a_decision_is_answered_only_when_it_names_a_status(): void {
		$workflow = self::workflow(
			array(
				'transitions' => array(
					Workflows::DECISION_APPROVE => 'aprovado',
					Workflows::DECISION_REJECT  => 'reprovado',
				),
			)
		);

		self::assertTrue( $workflow->answers( Workflows::DECISION_APPROVE ) );
		self::assertTrue( $workflow->answers( Workflows::DECISION_REJECT ) );
		self::assertFalse( $workflow->answers( Workflows::DECISION_CORRECTION ) );
		self::assertSame( 'aprovado', $workflow->target_for( Workflows::DECISION_APPROVE ) );
		self::assertSame( '', $workflow->target_for( Workflows::DECISION_CORRECTION ) );
	}

	/**
	 * The clock needs both halves to be usable.
	 *
	 * @return void
	 */
	public function test_expiration_needs_hours_and_a_decision(): void {
		self::assertFalse( self::workflow( array( 'expires_after_hours' => 72 ) )->expires() );
		self::assertFalse(
			self::workflow( array( 'transitions' => array( Workflows::DECISION_EXPIRE => 'expirado' ) ) )->expires()
		);
		self::assertTrue(
			self::workflow(
				array(
					'expires_after_hours' => 72,
					'transitions'         => array( Workflows::DECISION_EXPIRE => 'expirado' ),
				)
			)->expires()
		);
	}

	/**
	 * **Every strategy the vocabulary names is one this build executes.**
	 *
	 * §30.1 from the other side: the editor draws what the server lists, so the server must not list
	 * work nothing does. Fase 10 made every payment strategy executable and Fase 13 did the same for
	 * the stock reservations, so both vocabularies are now fully executable and neither refusal
	 * fires — and a strategy no service carries out is still not executable, which is the seam those
	 * refusals exist for.
	 *
	 * @return void
	 */
	public function test_every_strategy_the_vocabulary_names_can_run(): void {
		$statuses = array( 'analise_pendente', 'aprovado' );
		$paid     = array( 'processing', 'completed' );

		foreach ( array( 'until_decision', 'hours' ) as $strategy ) {
			self::assertTrue( Workflows::can_execute( 'inventory', $strategy ), $strategy );
			self::assertNotContains(
				'inventory_strategy_not_available',
				WorkflowValidator::validate_one(
					self::workflow( array( 'inventory_strategy' => $strategy ) ),
					$statuses,
					$paid
				)->error_codes()
			);
		}

		foreach ( array( 'authorize_now', 'capture_after_approval', 'request_after_approval', 'generate_after_approval' ) as $strategy ) {
			self::assertTrue( Workflows::can_execute( 'payment', $strategy ), $strategy );
			self::assertNotContains(
				'payment_strategy_not_available',
				WorkflowValidator::validate_one(
					self::workflow( array( 'payment_strategy' => $strategy ) ),
					$statuses,
					$paid
				)->error_codes()
			);
		}

		self::assertFalse( Workflows::can_execute( 'inventory', 'reservar_para_sempre' ) );
		self::assertFalse( Workflows::can_execute( 'payment', 'cobrar_duas_vezes' ) );
	}

	/**
	 * **A reservation calculated in hours needs the number of hours.**
	 *
	 * The third stock strategy is the only one that carries a quantity of its own; with none, the
	 * hold would be zero minutes — the merchant would have chosen to reserve and the store would
	 * reserve nothing, silently. §30.1's rule, and the reason `until_decision` is not refused for
	 * having no number: its window is the workflow's own clock, and the platform's when there is none.
	 *
	 * @return void
	 */
	public function test_a_stock_reservation_by_the_hour_needs_its_number(): void {
		$statuses = array( 'analise_pendente', 'aprovado' );
		$paid     = array( 'processing', 'completed' );

		$missing = WorkflowValidator::validate_one(
			self::workflow(
				array(
					'inventory_strategy' => 'hours',
					'inventory_hours'    => 0,
				)
			),
			$statuses,
			$paid
		);

		self::assertContains( 'workflow_inventory_hours_required', $missing->error_codes() );

		$given = WorkflowValidator::validate_one(
			self::workflow(
				array(
					'inventory_strategy' => 'hours',
					'inventory_hours'    => 6,
				)
			),
			$statuses,
			$paid
		);

		self::assertNotContains( 'workflow_inventory_hours_required', $given->error_codes() );

		$until_decision = WorkflowValidator::validate_one(
			self::workflow( array( 'inventory_strategy' => 'until_decision' ) ),
			$statuses,
			$paid
		);

		self::assertNotContains( 'workflow_inventory_hours_required', $until_decision->error_codes() );
	}

	/**
	 * **No paid status is applied before a payment.** §13.8's acceptance, as a rule.
	 *
	 * @return void
	 */
	public function test_a_paid_status_is_refused_without_a_payment_action(): void {
		self::assertTrue( Workflows::payment_actions_available() );

		// A workflow that moves an order into a paid state while its strategy performs no action is
		// claiming money arrived: nothing concluded a payment.
		$result = WorkflowValidator::validate_one(
			self::workflow( array( 'transitions' => array( Workflows::DECISION_APPROVE => 'processing' ) ) ),
			array( 'analise_pendente', 'processing' ),
			array( 'processing', 'completed' )
		);

		self::assertContains( 'workflow_paid_status_without_payment', $result->error_codes() );
	}

	/**
	 * And it is allowed when the strategy performs one.
	 *
	 * @return void
	 */
	public function test_a_paid_status_is_allowed_with_a_payment_action(): void {
		$result = WorkflowValidator::validate_one(
			self::workflow(
				array(
					'payment_strategy' => 'capture_after_approval',
					'transitions'      => array( Workflows::DECISION_APPROVE => 'processing' ),
				)
			),
			array( 'analise_pendente', 'processing' ),
			array( 'processing', 'completed' )
		);

		self::assertNotContains( 'workflow_paid_status_without_payment', $result->error_codes() );
	}

	/**
	 * And an initial status that is paid is refused for the same reason.
	 *
	 * @return void
	 */
	public function test_a_paid_initial_status_is_refused_too(): void {
		$result = WorkflowValidator::validate_one(
			self::workflow( array( 'initial_status' => 'completed' ) ),
			array( 'completed', 'analise_pendente' ),
			array( 'processing', 'completed' )
		);

		self::assertContains( 'workflow_paid_status_without_payment', $result->error_codes() );
	}

	/**
	 * The strategy-to-capability mapping is the one table both halves read.
	 *
	 * @return void
	 */
	public function test_the_strategy_mapping_names_the_capabilities(): void {
		self::assertSame( array(), Workflows::capabilities_for( Workflows::PAYMENT_NONE ) );
		self::assertSame( array(), Workflows::capabilities_for( 'request_after_approval' ) );
		self::assertSame( array( 'capture' ), Workflows::capabilities_for( 'capture_after_approval' ) );
		self::assertSame( array( 'authorize', 'capture' ), Workflows::capabilities_for( 'authorize_now' ) );
		self::assertSame( array( 'create_after_approval' ), Workflows::capabilities_for( 'generate_after_approval' ) );

		self::assertFalse( Workflows::strategy_performs_action( Workflows::PAYMENT_NONE ) );
		self::assertFalse( Workflows::strategy_performs_action( 'request_after_approval' ) );
		self::assertTrue( Workflows::strategy_performs_action( 'capture_after_approval' ) );
	}

	/**
	 * A workflow that names a state the store does not have is refused.
	 *
	 * @return void
	 */
	public function test_a_status_the_store_does_not_have_is_refused(): void {
		$result = WorkflowValidator::validate_one(
			self::workflow( array( 'initial_status' => 'nao_existe' ) ),
			array( 'analise_pendente' ),
			array()
		);

		self::assertContains( 'workflow_status_unknown', $result->error_codes() );
	}

	/**
	 * The trigger is a closed list.
	 *
	 * @return void
	 */
	public function test_an_unknown_trigger_is_refused(): void {
		$result = WorkflowValidator::validate_one( self::workflow( array( 'trigger' => 'whenever' ) ), null, array() );

		self::assertContains( 'workflow_trigger_unknown', $result->error_codes() );
	}

	/**
	 * The clock's two halves have to agree, in both directions.
	 *
	 * @return void
	 */
	public function test_the_clock_halves_are_checked_against_each_other(): void {
		$without_hours = WorkflowValidator::validate_one(
			self::workflow( array( 'transitions' => array( Workflows::DECISION_EXPIRE => 'expirado' ) ) ),
			null,
			array()
		);

		self::assertContains( 'workflow_expiration_without_hours', $without_hours->error_codes() );

		$without_decision = WorkflowValidator::validate_one(
			self::workflow( array( 'expires_after_hours' => 72 ) ),
			null,
			array()
		);

		self::assertContains( 'workflow_hours_without_expiration', $without_decision->error_codes() );
	}

	/**
	 * Only enabled workflows, of the right trigger, whose rule matches, are considered.
	 *
	 * @return void
	 */
	public function test_only_the_workflows_that_apply_are_considered(): void {
		$workflows = array(
			array(
				'id'       => 'desligado',
				'name'     => 'Desligado',
				'enabled'  => false,
				'priority' => 99,
			),
			array(
				'id'       => 'outro_gatilho',
				'name'     => 'Outro gatilho',
				'trigger'  => 'nao_existe',
				'priority' => 98,
			),
			array(
				'id'         => 'nao_casa',
				'name'       => 'Não casa',
				'priority'   => 97,
				'conditions' => array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'PT',
				),
			),
			array(
				'id'       => 'baixa',
				'name'     => 'Baixa',
				'priority' => 10,
			),
			array(
				'id'       => 'alta',
				'name'     => 'Alta',
				'priority' => 20,
			),
		);

		$context = new FieldContext( array( 'country' => 'BR' ), 'workflow' );

		$matching = WorkflowEvaluator::matching( $workflows, Workflows::TRIGGER_CHECKOUT_SUBMITTED, $context );

		self::assertSame( array( 'alta', 'baixa' ), array_map( static fn( WorkflowDefinition $entry ): string => $entry->id(), $matching ) );
		self::assertSame( 'alta', WorkflowEvaluator::resolve( $workflows, Workflows::TRIGGER_CHECKOUT_SUBMITTED, $context )?->id() );
	}

	/**
	 * Two workflows that both apply are reported, because priority settles it and the merchant should
	 * be able to see it.
	 *
	 * @return void
	 */
	public function test_two_workflows_that_apply_are_reported(): void {
		$workflows = array(
			array(
				'id'       => 'um',
				'name'     => 'Um',
				'priority' => 20,
			),
			array(
				'id'       => 'dois',
				'name'     => 'Dois',
				'priority' => 10,
			),
			array(
				'id'         => 'tres',
				'name'       => 'Três',
				'priority'   => 5,
				'conditions' => array(
					'source'   => 'country',
					'operator' => 'equals',
					'value'    => 'PT',
				),
			),
		);

		$overlaps = WorkflowEvaluator::overlaps( $workflows, Workflows::TRIGGER_CHECKOUT_SUBMITTED, new FieldContext( array( 'country' => 'BR' ), 'workflow' ) );

		self::assertCount( 1, $overlaps );
		self::assertSame( 'um', $overlaps[0]['workflow'] );
		self::assertSame( 'dois', $overlaps[0]['other'] );
	}

	/**
	 * An order no workflow claims is an answer, not a gap.
	 *
	 * @return void
	 */
	public function test_no_match_is_an_answer(): void {
		self::assertNull(
			WorkflowEvaluator::resolve( array(), Workflows::TRIGGER_CHECKOUT_SUBMITTED, new FieldContext( array(), 'workflow' ) )
		);
	}

	/**
	 * The vocabulary the editor reads is the one the validator accepts.
	 *
	 * @return void
	 */
	public function test_the_vocabulary_is_the_one_the_validator_reads(): void {
		$vocabulary = Workflows::to_array();

		self::assertSame( array( Workflows::TRIGGER_CHECKOUT_SUBMITTED ), array_column( $vocabulary['triggers'], 'value' ) );
		self::assertSame( array_keys( Workflows::decisions() ), array_column( $vocabulary['decisions'], 'value' ) );
		self::assertSame( array_keys( Workflows::inventory_strategies() ), $vocabulary['executable']['inventory'] );
		self::assertSame( array_keys( Workflows::payment_strategies() ), $vocabulary['executable']['payment'] );
		self::assertSame( array_keys( Workflows::events() ), array_column( $vocabulary['events'], 'value' ) );
	}

	/**
	 * A key a later version wrote survives being read and written back.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_carried(): void {
		$workflow = WorkflowDefinition::from_array(
			array(
				'id'         => 'quimicos',
				'name'       => 'Químicos',
				'automation' => array( 'notify' => 'staff' ),
			)
		);

		self::assertSame( array( 'notify' => 'staff' ), $workflow->to_array()['automation'] ?? null );
	}
}
