<?php
/**
 * An order status is a state, and never a command.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Statuses;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Statuses\OrderStatus;
use WCCheckoutSuite\Domain\Statuses\StatusValidator;

/**
 * The status model of §12.3 and the rules of §12.4–12.6.
 *
 * The property these tests exist for is an absence: there is no field on a status that a gateway
 * could read. §12.4 forbids charging on a status change, and the cheapest way to keep that promise
 * is for the model to have nothing to charge with — so one of the specs below asserts the exact
 * shape of what a status exports, and it fails the day somebody adds a payment field to it.
 */
final class OrderStatusTest extends TestCase {

	/**
	 * A status with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return OrderStatus
	 */
	private static function status( array $overrides = array() ): OrderStatus {
		return OrderStatus::from_array(
			array_merge(
				array(
					'id'    => 'analise_pendente',
					'label' => 'Análise pendente',
				),
				$overrides
			)
		);
	}

	/**
	 * The identifier comes from the name once, and the key is the one WooCommerce writes.
	 *
	 * @return void
	 */
	public function test_the_identifier_is_readable_and_the_key_is_prefixed(): void {
		self::assertSame( 'analise_pendente', OrderStatus::unique_id( 'Análise pendente' ) );
		self::assertSame( 'aguardando_aprova', OrderStatus::unique_id( 'Aguardando aprovação' ) );
		self::assertSame( 'estado', OrderStatus::unique_id( '!!!' ) );
		self::assertSame( 'wc-analise_pendente', self::status()->key() );
	}

	/**
	 * Two statuses with one name get two identifiers.
	 *
	 * @return void
	 */
	public function test_a_collision_is_numbered(): void {
		// The number is part of what has to fit beside WooCommerce's prefix, so the base gives up
		// its last letter rather than the identifier growing past the column.
		self::assertSame(
			'analise_pendent_2',
			OrderStatus::unique_id( 'Análise pendente', array( 'analise_pendente' ) )
		);
		self::assertSame(
			'aprovado_2',
			OrderStatus::unique_id( 'Aprovado', array( 'aprovado' ) )
		);
	}

	/**
	 * The identifier never fits beside WooCommerce's prefix if it is too long.
	 *
	 * §12.6: the key is a post status, and the column holds twenty characters. An identifier that
	 * did not fit would be truncated by the database and two states would become one.
	 *
	 * @return void
	 */
	public function test_the_identifier_never_exceeds_what_fits(): void {
		foreach ( array( 'Aguardando documentos', 'Aprovado para cobrança', 'Reprovado' ) as $label ) {
			self::assertLessThanOrEqual(
				OrderStatus::MAX_ID,
				strlen( OrderStatus::unique_id( $label ) ),
				$label
			);
		}
	}

	/**
	 * A label that changes does not change the state.
	 *
	 * §12.7 step 8: renaming the label leaves every order in the same internal status.
	 *
	 * @return void
	 */
	public function test_renaming_the_label_keeps_the_identifier(): void {
		$before = self::status();
		$after  = OrderStatus::from_array( array_merge( $before->to_array(), array( 'label' => 'Em análise' ) ) );

		self::assertSame( $before->id(), $after->id() );
		self::assertSame( 'Em análise', $after->label() );
		self::assertSame( $before->key(), $after->key() );
	}

	/**
	 * The customer is told something, even when the merchant wrote no customer label.
	 *
	 * @return void
	 */
	public function test_the_customer_label_falls_back_to_the_name(): void {
		self::assertSame( 'Análise pendente', self::status()->customer_label() );
		self::assertSame(
			'Estamos a analisar o seu pedido',
			self::status( array( 'customer_label' => 'Estamos a analisar o seu pedido' ) )->customer_label()
		);
	}

	/**
	 * **A status carries nothing a gateway reads.**
	 *
	 * §12.4: "Um status customizado **não é por si só um comando de cobrança**." The configuration a
	 * merchant writes about a state is about naming, colour, who sees it and who may move an order
	 * into it — and this is where that list is held, so a payment field added to the model fails
	 * here instead of reaching a store.
	 *
	 * @return void
	 */
	public function test_the_model_has_no_payment_command(): void {
		$keys = array_keys( self::status()->to_array() );

		sort( $keys );

		self::assertSame(
			array(
				'active',
				'colour',
				'customer_label',
				'description',
				'id',
				'label',
				'manual',
				'prepayment',
				'show_customer',
				'show_emails',
			),
			$keys
		);

		foreach ( array( 'capture', 'authorize', 'charge', 'gateway', 'payment_action', 'paid', 'void' ) as $forbidden ) {
			self::assertNotContains( $forbidden, $keys, 'a status must not carry ' . $forbidden );
		}
	}

	/**
	 * A key a later version wrote survives being read and written back.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_carried_not_dropped(): void {
		$status = OrderStatus::from_array(
			array(
				'id'         => 'analise_pendente',
				'label'      => 'Análise pendente',
				'automation' => array( 'on_enter' => 'notify' ),
			)
		);

		self::assertSame(
			array( 'on_enter' => 'notify' ),
			$status->to_array()['automation'] ?? null
		);
	}

	/**
	 * The rules a stored status has to satisfy.
	 *
	 * @return void
	 */
	public function test_the_validator_refuses_what_cannot_be_a_state(): void {
		self::assertTrue( StatusValidator::validate_one( self::status() )->is_valid() );

		$cases = array(
			'status_id_not_a_slug'  => array( 'id' => 'Análise Pendente' ),
			'status_id_too_long'    => array( 'id' => 'um_identificador_longo_demais' ),
			'status_id_reserved'    => array( 'id' => 'processing' ),
			'status_label_required' => array( 'label' => '   ' ),
			'status_colour_not_hex' => array( 'colour' => 'vermelho' ),
		);

		foreach ( $cases as $code => $overrides ) {
			$result = StatusValidator::validate_one( self::status( $overrides ) );

			self::assertContains( $code, $result->error_codes(), $code );
		}

		// An entry with no identifier at all is refused where the list is read, because a status
		// with no identifier is a state nothing can be recorded in.
		self::assertContains(
			'status_id_required',
			StatusValidator::validate_all( array( array( 'label' => 'Sem identificador' ) ) )->error_codes()
		);
	}

	/**
	 * A six digit hex colour is accepted, with or without the capital letters.
	 *
	 * @return void
	 */
	public function test_a_hex_colour_is_accepted(): void {
		self::assertTrue( StatusValidator::validate_one( self::status( array( 'colour' => '#1F6FEB' ) ) )->is_valid() );
		self::assertTrue( StatusValidator::validate_one( self::status( array( 'colour' => '#ff59c0' ) ) )->is_valid() );
		self::assertTrue( StatusValidator::validate_one( self::status( array( 'colour' => '' ) ) )->is_valid() );
	}

	/**
	 * The list refuses two statuses with one identifier.
	 *
	 * @return void
	 */
	public function test_the_list_refuses_a_duplicate(): void {
		$result = StatusValidator::validate_all(
			array(
				array(
					'id'    => 'analise_pendente',
					'label' => 'Análise pendente',
				),
				array(
					'id'    => 'analise_pendente',
					'label' => 'Em análise',
				),
			)
		);

		self::assertContains( 'duplicate_status_id', $result->error_codes() );
	}

	/**
	 * The statuses that declare themselves pre-payment are the ones the guard protects.
	 *
	 * @return void
	 */
	public function test_the_prepayment_states_are_reported(): void {
		self::assertSame(
			array( 'analise_pendente' ),
			StatusValidator::prepayment_ids(
				array(
					array(
						'id'         => 'analise_pendente',
						'label'      => 'Análise pendente',
						'prepayment' => true,
					),
					array(
						'id'    => 'aprovado',
						'label' => 'Aprovado para cobrança',
					),
				)
			)
		);
	}

	/**
	 * A store may not shadow a state WooCommerce owns.
	 *
	 * @return void
	 */
	public function test_the_states_woocommerce_owns_are_reserved(): void {
		self::assertTrue( OrderStatus::is_reserved( 'completed' ) );
		self::assertTrue( OrderStatus::is_reserved( 'ON-HOLD' ) );
		self::assertFalse( OrderStatus::is_reserved( 'analise_pendente' ) );
	}
}
