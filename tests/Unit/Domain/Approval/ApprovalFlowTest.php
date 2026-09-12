<?php
/**
 * The optional approval flow, as the domain reads it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Approval;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Approval\ApprovalFlow;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;

/**
 * What one field's approval configuration means.
 *
 * The rule under test is the one the phase keeps stating: a flow that was not asked
 * for does nothing, and a flow that was asked for but not finished is reported rather
 * than completed with a default.
 */
final class ApprovalFlowTest extends TestCase {

	/**
	 * Builds a definition carrying an approval map.
	 *
	 * @param array<string, mixed>|null $approval Approval configuration.
	 * @param string                    $id       Field identifier.
	 * @param string                    $origin   Origin of the field.
	 * @return FieldDefinition
	 */
	private function definition( ?array $approval, string $id = 'billing_document', string $origin = 'custom' ): FieldDefinition {
		return FieldDefinition::from_array(
			array(
				'id'       => $id,
				'origin'   => $origin,
				'type'     => 'file',
				'label'    => 'Documento',
				'approval' => $approval,
			)
		);
	}

	/**
	 * Nothing is enabled until it is asked for, and nothing is missing either.
	 *
	 * @return void
	 */
	public function test_a_flow_that_was_not_asked_for_is_off_and_complete(): void {
		$none = ApprovalFlow::of( $this->definition( null ) );
		$off  = ApprovalFlow::of( $this->definition( array( 'require_review' => false ) ) );

		self::assertFalse( $none->enabled() );
		self::assertFalse( $off->enabled() );
		self::assertSame( array(), $none->missing() );
		self::assertSame( array(), $off->missing() );
		self::assertFalse( $none->complete() );
	}

	/**
	 * A flow that says it holds orders but not where, in what section or in what state
	 * is reported key by key.
	 *
	 * @return void
	 */
	public function test_an_enabled_flow_reports_what_it_is_missing(): void {
		$flow = ApprovalFlow::of( $this->definition( array( 'require_review' => true ) ) );

		self::assertTrue( $flow->enabled() );
		self::assertFalse( $flow->complete() );
		self::assertSame( array( 'area', 'section', 'status' ), $flow->missing() );
		self::assertSame( '', $flow->status() );
	}

	/**
	 * A complete flow names the state the order waits in.
	 *
	 * The name is the merchant's, and the identifier is derived from the field: the
	 * database holds twenty characters for a status, and "Pendente de aprovação" is not
	 * one of them. The identifier follows the field and not the text, so renaming the
	 * state does not move the orders that are already waiting in it, and two fields
	 * cannot collide.
	 *
	 * @return void
	 */
	public function test_a_complete_flow_names_its_state(): void {
		$configuration = array(
			'require_review' => true,
			'area'           => 'admin_order',
			'section'        => 'documentos_para_analise',
			'status'         => 'Pendente de aprovação',
		);

		$flow = ApprovalFlow::of( $this->definition( $configuration ) );

		self::assertTrue( $flow->complete() );
		self::assertSame( array(), $flow->missing() );
		self::assertSame( 'Pendente de aprovação', $flow->label() );
		self::assertSame( 'admin_order', $flow->area() );
		self::assertSame( 'documentos_para_analise', $flow->section() );

		$status = $flow->status();

		self::assertStringStartsWith( ApprovalFlow::STATUS_PREFIX, $status );
		self::assertLessThanOrEqual( 17, strlen( $status ), 'the status fits beside the wc- prefix the database adds' );

		$renamed = ApprovalFlow::of(
			$this->definition( array_merge( $configuration, array( 'status' => 'Em análise' ) ) )
		);

		self::assertSame( $status, $renamed->status() );
		self::assertNotSame( $status, ApprovalFlow::of( $this->definition( $configuration, 'billing_other' ) )->status() );
	}

	/**
	 * A state the store did not name is a missing state, and it is reported instead of
	 * being numbered, hashed or defaulted into existence.
	 *
	 * @return void
	 */
	public function test_a_state_without_a_name_is_missing(): void {
		$flow = ApprovalFlow::of(
			$this->definition(
				array(
					'require_review' => true,
					'area'           => 'admin_order',
					'section'        => 'documentos_para_analise',
				)
			)
		);

		self::assertFalse( $flow->complete() );
		self::assertSame( array( 'status' ), $flow->missing() );
		self::assertSame( '', $flow->status() );
	}

	/**
	 * The switches are read as they were stored, and absent means off.
	 *
	 * @return void
	 */
	public function test_the_switches_are_read_as_stored(): void {
		$flow = ApprovalFlow::of(
			$this->definition(
				array(
					'require_review'   => true,
					'allow_correction' => true,
					'allow_resubmit'   => false,
					'show_status'      => true,
				)
			)
		);

		self::assertTrue( $flow->allows_correction() );
		self::assertFalse( $flow->allows_resubmit() );
		self::assertTrue( $flow->shows_status() );
	}

	/**
	 * A document is read field by field, and only the fields this plugin owns can hold
	 * an order: a core WooCommerce field is not the Suite's to review.
	 *
	 * @return void
	 */
	public function test_only_enabled_fields_of_this_plugin_are_read(): void {
		$on = array(
			'id'       => 'billing_document',
			'origin'   => 'custom',
			'type'     => 'file',
			'label'    => 'Documento',
			'approval' => array(
				'require_review' => true,
				'status'         => 'Em análise',
			),
		);

		$core           = $on;
		$core['id']     = 'billing_company';
		$core['origin'] = 'core';

		$off             = $on;
		$off['id']       = 'billing_other';
		$off['approval'] = array( 'require_review' => false );

		$flows = ApprovalFlow::enabled_in( array( $core, $off, $on, 'not-a-field' ) );

		self::assertCount( 1, $flows );
		self::assertSame( 'billing_document', $flows[0]['id'] );
		self::assertTrue( $flows[0]['flow']->enabled() );
	}

	/**
	 * A field with nothing in it is not a document to review.
	 *
	 * @return void
	 */
	public function test_an_answer_is_what_needs_reviewing(): void {
		self::assertTrue( ApprovalFlow::answered( array( 'a-token' ) ) );
		self::assertTrue( ApprovalFlow::answered( '0' ) );
		self::assertTrue( ApprovalFlow::answered( 'texto' ) );

		self::assertFalse( ApprovalFlow::answered( array() ) );
		self::assertFalse( ApprovalFlow::answered( '' ) );
		self::assertFalse( ApprovalFlow::answered( '   ' ) );
		self::assertFalse( ApprovalFlow::answered( null ) );
		self::assertFalse( ApprovalFlow::answered( false ) );
	}
}
