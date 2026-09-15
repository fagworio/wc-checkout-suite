<?php
/**
 * Whether a workflow is one the store can carry out.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Workflow;

use WCCheckoutSuite\Domain\Conditions\ConditionValidator;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * The rules a stored workflow has to satisfy.
 *
 * Most of them are the ones every definition in this plugin obeys: an identifier, a name, a closed
 * vocabulary, a rule tree the shared validator accepts (§14), and a status that exists.
 *
 * The two that matter most are the ones that **refuse what this build cannot execute**, and they are
 * the gate of phase 10 written as a rule:
 *
 * - A stock strategy that is not executable is refused with `inventory_strategy_not_available`.
 * - A payment strategy that is not executable is refused with `payment_strategy_not_available`.
 *
 * Both vocabularies are fully executable as of Fase 13 — the reservation service holds stock and the
 * payment action service performs every payment strategy — so neither code fires today, and the unit
 * test asserts exactly that: every strategy the vocabulary names is one the store runs, so the editor
 * cannot advertise work nothing does. The refusals stay as the seam for the next strategy to be
 * described before it can run, which is why `executable_strategies()` is written out separately from
 * the vocabularies instead of derived from them.
 *
 * The alternative — accepting everything the vocabulary names and recording it as intent — was
 * rejected on the principle the rest of this plugin follows: configuration that cannot work is
 * refused **by name with a reason** rather than accepted and ignored. A merchant who chose "autorizar
 * agora e capturar após aprovação" and got nothing would have been told, by the interface, that their
 * card would be authorised.
 *
 * @see \ROADMAP.md sections 12.4, 13.2 and 13.7
 */
final class WorkflowValidator {

	/**
	 * Validates a whole list.
	 *
	 * @param array<int, mixed>       $workflows Raw workflows.
	 * @param array<int, string>|null $statuses  Status identifiers the store has, or null to skip.
	 * @return ValidationResult
	 */
	public static function validate_all( array $workflows, ?array $statuses = null ): ValidationResult {
		$result = ValidationResult::valid();
		$seen   = array();
		$paid   = function_exists( 'wc_get_is_paid_statuses' )
			? array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) )
			: array( 'processing', 'completed' );

		foreach ( $workflows as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'invalid_workflow_entry',
						sprintf(
							/* translators: %d: index of the entry */
							__( 'The workflow at index %d is not a workflow.', 'wc-checkoutsuite' ),
							(int) $index
						),
						array( 'index' => (int) $index )
					)
				);

				continue;
			}

			$workflow = WorkflowDefinition::from_array( $raw );
			$id       = $workflow->id();

			if ( '' === $id ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'workflow_id_required',
						__( 'A workflow needs an identifier: it is what an audit entry names when an order moved.', 'wc-checkoutsuite' ),
						array( 'index' => (int) $index )
					)
				);
			} elseif ( isset( $seen[ $id ] ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'duplicate_workflow_id',
						sprintf(
							/* translators: %s: workflow identifier */
							__( 'The workflow identifier "%s" appears more than once.', 'wc-checkoutsuite' ),
							$id
						),
						array( 'workflow' => $id )
					)
				);
			} else {
				$seen[ $id ] = true;
			}

			$result = $result->merge( self::validate_one( $workflow, $statuses, $paid ) );
		}

		return $result;
	}

	/**
	 * Validates one workflow.
	 *
	 * @param WorkflowDefinition      $workflow Workflow.
	 * @param array<int, string>|null $statuses Status identifiers the store has, or null to skip.
	 * @param array<int, string>      $paid     Statuses WooCommerce considers paid.
	 * @return ValidationResult
	 */
	public static function validate_one( WorkflowDefinition $workflow, ?array $statuses = null, array $paid = array() ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $workflow->id();

		if ( '' === trim( $workflow->name() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_name_required',
					__( 'A workflow needs a name: it is how the merchant tells one automation from another.', 'wc-checkoutsuite' ),
					array( 'workflow' => $id )
				)
			);
		}

		if ( ! isset( Workflows::triggers()[ $workflow->trigger() ] ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_trigger_unknown',
					sprintf(
						/* translators: %s: trigger key */
						__( 'The trigger "%s" is not a moment the store knows how to watch.', 'wc-checkoutsuite' ),
						$workflow->trigger()
					),
					array( 'workflow' => $id )
				)
			);
		}

		// The rule tree the workflow runs on, validated by the shared validator (§14) exactly as a
		// field's or a profile's is: the same dialect, the same limits, the same errors.
		$result = $result->merge( self::rename( ConditionValidator::validate_tree( $id, $workflow->conditions() ), $id ) );

		$result = $result->merge( self::validate_statuses( $workflow, $statuses, $paid ) );
		$result = $result->merge( self::validate_strategies( $workflow ) );

		if ( $workflow->answers( Workflows::DECISION_EXPIRE ) && 0 === $workflow->expires_after_hours() ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_expiration_without_hours',
					__( 'A workflow that expires an order has to say after how many hours: an expiration with no clock is a transition nothing can reach.', 'wc-checkoutsuite' ),
					array( 'workflow' => $id )
				)
			);
		}

		if ( $workflow->expires_after_hours() > 0 && ! $workflow->answers( Workflows::DECISION_EXPIRE ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_hours_without_expiration',
					__( 'A workflow that counts the hours has to say which status the order expires into; otherwise the clock reaches a decision the workflow does not answer.', 'wc-checkoutsuite' ),
					array( 'workflow' => $id )
				)
			);
		}

		return $result;
	}

	/**
	 * The statuses a workflow names have to be ones the store has.
	 *
	 * An order cannot be moved into a state that does not exist, and finding that out at the moment
	 * a customer checks out is finding out too late.
	 *
	 * @param WorkflowDefinition      $workflow Workflow.
	 * @param array<int, string>|null $statuses Known status identifiers, or null to skip.
	 * @param array<int, string>      $paid     Statuses WooCommerce considers paid.
	 * @return ValidationResult
	 */
	private static function validate_statuses( WorkflowDefinition $workflow, ?array $statuses, array $paid = array() ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $workflow->id();

		if ( null === $statuses ) {
			return $result;
		}

		$named = array( 'initial_status' => $workflow->initial_status() );

		foreach ( $workflow->transitions() as $decision => $status ) {
			$named[ 'transition:' . (string) $decision ] = $status;
		}

		foreach ( $named as $where => $status ) {
			// §13.8 says it in the acceptance of this very screen: "nenhum status 'pago' é aplicado
			// antes do pagamento". A state WooCommerce treats as paid may therefore be reached only
			// by a workflow whose payment strategy **performs a payment action** — otherwise nothing
			// concluded a payment and the status would be claiming money arrived. The mapping from
			// strategy to capability is one table, so this rule and the runtime read the same
			// sentence.
			if ( '' !== $status && in_array( $status, $paid, true ) && ! Workflows::strategy_performs_action( $workflow->payment_strategy() ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'workflow_paid_status_without_payment',
						sprintf(
							/* translators: 1: status identifier, 2: where the workflow names it */
							__( 'O workflow move o pedido para «%1$s» (%2$s), e a WooCommerce considera esse estado pago. Um estado pago só pode ser alcançado por um workflow cuja estratégia de pagamento executa uma ação: escolha uma estratégia que o faça, ou um estado que não seja de pagamento.', 'wc-checkoutsuite' ),
							$status,
							(string) $where
						),
						array(
							'workflow' => $id,
							'status'   => $status,
						)
					)
				);

				continue;
			}

			if ( '' !== $status && ! in_array( $status, $statuses, true ) ) {
				$result = $result->merge(
					ValidationResult::invalid(
						'workflow_status_unknown',
						sprintf(
							/* translators: 1: status identifier, 2: where the workflow names it */
							__( 'The status "%1$s" named at %2$s is not one this store has.', 'wc-checkoutsuite' ),
							$status,
							(string) $where
						),
						array(
							'workflow' => $id,
							'status'   => $status,
						)
					)
				);
			}
		}

		// Where a correction lands is deliberately **not** checked against the initial status: a
		// workflow may legitimately send the order somewhere else — back into a queue the customer
		// can see, or to a state staff watch — and refusing that would be refusing a decision the
		// merchant is allowed to make.
		return $result;
	}

	/**
	 * The strategies this build can execute.
	 *
	 * @param WorkflowDefinition $workflow Workflow.
	 * @return ValidationResult
	 */
	private static function validate_strategies( WorkflowDefinition $workflow ): ValidationResult {
		$result = ValidationResult::valid();
		$id     = $workflow->id();

		if ( ! isset( Workflows::inventory_strategies()[ $workflow->inventory_strategy() ] ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_inventory_strategy_unknown',
					sprintf(
						/* translators: %s: strategy key */
						__( 'The stock strategy "%s" is not one the store knows.', 'wc-checkoutsuite' ),
						$workflow->inventory_strategy()
					),
					array( 'workflow' => $id )
				)
			);
		} elseif ( ! Workflows::can_execute( 'inventory', $workflow->inventory_strategy() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'inventory_strategy_not_available',
					sprintf(
						/* translators: %s: strategy label */
						__( 'A reserva de estoque («%s») ainda não é executada por esta versão. O pedido entra no estado escolhido e o estoque fica como a WooCommerce o deixou.', 'wc-checkoutsuite' ),
						Workflows::inventory_strategies()[ $workflow->inventory_strategy() ]
					),
					array(
						'workflow' => $id,
						'strategy' => $workflow->inventory_strategy(),
					)
				)
			);
		}

		// A strategy that holds stock for a number of hours is refused when there is no number: the
		// hold would be zero minutes, which is the same as never reserving and the opposite of what
		// the merchant chose. Refused by name with the reason, as §30.1 asks.
		if ( 'hours' === $workflow->inventory_strategy() && $workflow->inventory_hours() <= 0 ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_inventory_hours_required',
					__( 'A reserva de estoque por horas precisa do número de horas: sem ele nada é reservado e o pedido espera por uma decisão sem unidade guardada.', 'wc-checkoutsuite' ),
					array(
						'workflow' => $id,
						'strategy' => $workflow->inventory_strategy(),
					)
				)
			);
		}

		if ( ! isset( Workflows::payment_strategies()[ $workflow->payment_strategy() ] ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'workflow_payment_strategy_unknown',
					sprintf(
						/* translators: %s: strategy key */
						__( 'The payment strategy "%s" is not one the store knows.', 'wc-checkoutsuite' ),
						$workflow->payment_strategy()
					),
					array( 'workflow' => $id )
				)
			);
		} elseif ( ! Workflows::can_execute( 'payment', $workflow->payment_strategy() ) ) {
			$result = $result->merge(
				ValidationResult::invalid(
					'payment_strategy_not_available',
					sprintf(
						/* translators: %s: strategy label */
						__( 'A ação de pagamento («%s») ainda não é executada por esta versão: uma cobrança só acontece depois de o gateway declarar que a sabe fazer. O estado muda e o pagamento não é tocado.', 'wc-checkoutsuite' ),
						Workflows::payment_strategies()[ $workflow->payment_strategy() ]
					),
					array(
						'workflow' => $id,
						'strategy' => $workflow->payment_strategy(),
					)
				)
			);
		}

		return $result;
	}

	/**
	 * Re-labels the errors of an owned rule so they point at the workflow.
	 *
	 * @param ValidationResult $result   Result to re-label.
	 * @param string           $workflow Workflow identifier.
	 * @return ValidationResult
	 */
	private static function rename( ValidationResult $result, string $workflow ): ValidationResult {
		if ( $result->is_valid() ) {
			return $result;
		}

		$renamed = ValidationResult::valid();

		foreach ( $result->errors() as $error ) {
			$context = $error['context'];

			unset( $context['field'] );

			$context['workflow'] = $workflow;

			$renamed = $renamed->merge(
				ValidationResult::invalid( (string) $error['code'], (string) $error['message'], $context )
			);
		}

		return $renamed;
	}
}
