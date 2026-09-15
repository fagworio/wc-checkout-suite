<?php
/**
 * The checkout a cart gets must be the one the profiles say.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Checkout;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Checkout\CheckoutProfile;
use WCCheckoutSuite\Domain\Checkout\CheckoutProfileResolver;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;

/**
 * Resolution, fallback and composition of checkout profiles.
 *
 * Section 6.9 names two mechanisms and an order: profiles that match are considered by priority,
 * and the store's own checkout is the fallback. Each test below pins one word of that sentence,
 * because the failure this design exists to prevent is a checkout the merchant believes is running
 * and that never runs.
 */
final class CheckoutProfileResolverTest extends TestCase {

	/**
	 * A container offered in the checkout.
	 *
	 * @param string $id       Container id.
	 * @param int    $position Position.
	 * @return array<string, mixed>
	 */
	private static function container( string $id, int $position = 10 ): array {
		return array(
			'id'       => $id,
			'title'    => $id,
			'position' => $position,
			'location' => 'billing',
			'areas'    => array( 'checkout' ),
		);
	}

	/**
	 * A stored profile.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private static function profile( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => 'padrao',
				'name'     => 'Checkout padrão',
				'enabled'  => true,
				'source'   => 'woocommerce_current',
				'priority' => 0,
				'fallback' => false,
				'sections' => array( self::container( 'contato' ) ),
			),
			$overrides
		);
	}

	/**
	 * A rule that matches a cart of a given total.
	 *
	 * @param float $total Total the rule needs to be true.
	 * @return array<string, mixed>
	 */
	private static function over( float $total ): array {
		return array(
			'source'   => 'cart_total',
			'operator' => 'greater_than',
			'value'    => $total,
		);
	}

	/**
	 * A context carrying one cart total.
	 *
	 * @param float $total Cart total.
	 * @return FieldContext
	 */
	private static function cart( float $total ): FieldContext {
		return new FieldContext( array( 'cart_total' => $total ), 'classic' );
	}

	/**
	 * A store with no profile runs the document it has.
	 *
	 * @return void
	 */
	public function test_a_store_without_profiles_runs_its_document(): void {
		$document = SchemaDocument::from_array(
			array( 'sections' => array( self::container( 'contato' ) ) )
		);

		$profile = CheckoutProfileResolver::resolve( $document->profiles(), self::cart( 10.0 ) );

		self::assertNull( $profile, 'Nothing was declared, so the store runs its own composition.' );
		self::assertSame(
			$document->sections(),
			CheckoutProfileResolver::compose( $document, $profile )->sections()
		);
	}

	/**
	 * The highest priority among the profiles that match is the one that runs.
	 *
	 * @return void
	 */
	public function test_priority_decides_among_the_profiles_that_match(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'digital',
					'priority'   => 5,
					'conditions' => self::over( 100.0 ),
				)
			),
			self::profile(
				array(
					'id'         => 'restrito',
					'priority'   => 10,
					'conditions' => self::over( 50.0 ),
				)
			),
		);

		$resolved = CheckoutProfileResolver::resolve( $profiles, self::cart( 200.0 ) );

		self::assertNotNull( $resolved );
		self::assertSame( 'restrito', $resolved->id(), 'Both match; the higher priority is considered first.' );
	}

	/**
	 * Two profiles with the same priority keep the order the merchant declared them in.
	 *
	 * The alternative is that the answer depends on the order the array happened to be built in,
	 * which is not something a merchant can see or control.
	 *
	 * @return void
	 */
	public function test_a_tie_is_broken_by_the_declaration_order(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'primeiro',
					'priority'   => 3,
					'conditions' => self::over( 0.0 ),
				)
			),
			self::profile(
				array(
					'id'         => 'segundo',
					'priority'   => 3,
					'conditions' => self::over( 0.0 ),
				)
			),
		);

		$resolved = CheckoutProfileResolver::resolve( $profiles, self::cart( 10.0 ) );

		self::assertNotNull( $resolved );
		self::assertSame( 'primeiro', $resolved->id() );
	}

	/**
	 * A profile whose rule does not match is not the answer, whatever its priority.
	 *
	 * @return void
	 */
	public function test_a_profile_whose_rule_does_not_match_is_not_the_answer(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'caro',
					'priority'   => 99,
					'conditions' => self::over( 1000.0 ),
				)
			),
		);

		self::assertNull( CheckoutProfileResolver::resolve( $profiles, self::cart( 10.0 ) ) );
	}

	/**
	 * A disabled profile is neither a candidate nor a fallback.
	 *
	 * "Off" has to mean off in both mechanisms; a profile the merchant turned off that still
	 * answered the carts nobody else claimed would be an off switch that does not switch off.
	 *
	 * @return void
	 */
	public function test_a_disabled_profile_answers_nothing(): void {
		$profiles = array(
			self::profile(
				array(
					'id'       => 'desligado',
					'enabled'  => false,
					'fallback' => true,
				)
			),
		);

		self::assertNull( CheckoutProfileResolver::resolve( $profiles, self::cart( 10.0 ) ) );
		self::assertNull( CheckoutProfileResolver::fallback( $profiles ) );
	}

	/**
	 * The fallback answers a cart nothing matched, regardless of its priority.
	 *
	 * @return void
	 */
	public function test_the_fallback_answers_a_cart_nothing_matched(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'restrito',
					'priority'   => 50,
					'conditions' => self::over( 1000.0 ),
				)
			),
			self::profile(
				array(
					'id'       => 'minimo',
					'priority' => 0,
					'fallback' => true,
				)
			),
		);

		$resolved = CheckoutProfileResolver::resolve( $profiles, self::cart( 10.0 ) );

		self::assertNotNull( $resolved );
		self::assertSame( 'minimo', $resolved->id() );
	}

	/**
	 * The fallback does not outrank a profile that matches.
	 *
	 * @return void
	 */
	public function test_a_matching_profile_outranks_the_fallback(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'digital',
					'conditions' => self::over( 100.0 ),
				)
			),
			self::profile(
				array(
					'id'       => 'minimo',
					'fallback' => true,
				)
			),
		);

		$resolved = CheckoutProfileResolver::resolve( $profiles, self::cart( 500.0 ) );

		self::assertNotNull( $resolved );
		self::assertSame( 'digital', $resolved->id() );
	}

	/**
	 * Composition replaces the checkout containers and carries the rest of the document through.
	 *
	 * A document serves every destination it has containers for. A profile composes the checkout
	 * (§3.4); if it replaced the whole list, every cart it matched would lose the order panel and
	 * the email blocks, which the merchant never asked for by editing a checkout.
	 *
	 * @return void
	 */
	public function test_composition_replaces_only_the_checkout_containers(): void {
		$document = SchemaDocument::from_array(
			array(
				'sections' => array(
					self::container( 'contato' ),
					array(
						'id'       => 'documentos_do_email',
						'title'    => 'Documentos do pedido',
						'position' => 50,
						'location' => 'order',
						'areas'    => array( 'customer_email', 'admin_email' ),
					),
				),
			)
		);

		$profile = CheckoutProfile::from_array(
			self::profile( array( 'sections' => array( self::container( 'so_o_essencial' ) ) ) )
		);

		$composed = CheckoutProfileResolver::compose( $document, $profile );
		$ids      = array_column( $composed->sections(), 'id' );

		self::assertSame( array( 'so_o_essencial', 'documentos_do_email' ), $ids );
	}

	/**
	 * Composition leaves the document alone when no profile runs.
	 *
	 * @return void
	 */
	public function test_composition_without_a_profile_returns_the_document(): void {
		$document = SchemaDocument::from_array( array( 'sections' => array( self::container( 'contato' ) ) ) );

		self::assertSame( $document, CheckoutProfileResolver::compose( $document, null ) );
	}

	/**
	 * Two profiles that both match are reported as an overlap.
	 *
	 * §6.9 asks for the warning, not for a refusal: priority resolves the pair, and the merchant is
	 * the one who decides whether the overlap is intended.
	 *
	 * @return void
	 */
	public function test_two_matching_profiles_are_reported_as_an_overlap(): void {
		$profiles = array(
			self::profile(
				array(
					'id'         => 'primeiro',
					'priority'   => 10,
					'conditions' => self::over( 0.0 ),
				)
			),
			self::profile(
				array(
					'id'         => 'segundo',
					'priority'   => 5,
					'conditions' => self::over( 0.0 ),
				)
			),
			self::profile(
				array(
					'id'         => 'terceiro',
					'priority'   => 1,
					'conditions' => self::over( 10000.0 ),
				)
			),
		);

		$overlaps = CheckoutProfileResolver::overlaps( $profiles, self::cart( 10.0 ) );

		self::assertCount( 1, $overlaps, 'Only the pair that both match is an overlap.' );
		self::assertSame( 'primeiro', $overlaps[0]['profile'] );
		self::assertSame( 'segundo', $overlaps[0]['other'] );
	}
}
