<?php
/**
 * Native My Account surface tests.
 *
 * §7.4 asks for a section to be placeable inside a page WooCommerce already has, and for the
 * implementation to declare which pages can safely receive content. What is under test is that
 * declaration: the list is closed, every entry says why it is offered, and every surface that is
 * refused says why it is refused.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Customers;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Customers\AccountSurfaces;

/**
 * Which pages may host a section, and why.
 */
final class AccountSurfacesTest extends TestCase {

	/**
	 * The surfaces offered are the ones a section can safely live on.
	 *
	 * @return void
	 */
	public function test_the_offered_surfaces_are_the_safe_ones(): void {
		self::assertSame( array( 'edit-account', 'dashboard' ), AccountSurfaces::values() );
	}

	/**
	 * Every offered surface explains itself.
	 *
	 * A merchant choosing where a section goes is owed the reason the screen offers the pages it
	 * offers, and a page offered without one would be a page nobody can argue about.
	 *
	 * @return void
	 */
	public function test_every_offered_surface_explains_itself(): void {
		foreach ( AccountSurfaces::all() as $surface ) {
			self::assertNotSame( '', (string) $surface['value'] );
			self::assertNotSame( '', (string) $surface['label'], $surface['value'] );
			self::assertNotSame( '', (string) $surface['description'], $surface['value'] );
		}
	}

	/**
	 * A surface is known by its key, and labelled for the merchant.
	 *
	 * @return void
	 */
	public function test_a_surface_is_known_and_labelled(): void {
		self::assertTrue( AccountSurfaces::has( 'edit-account' ) );
		self::assertFalse( AccountSurfaces::has( 'orders' ) );
		self::assertFalse( AccountSurfaces::has( 'nao-existe' ) );
		self::assertSame( 'Detalhes da conta', AccountSurfaces::label( 'edit-account' ) );
		// An unknown key is answered with itself rather than with an empty string: the caller is
		// about to refuse it by name, and the name is what the merchant needs to see.
		self::assertSame( 'nao-existe', AccountSurfaces::label( 'nao-existe' ) );
	}

	/**
	 * The refused pages are refused with a reason each.
	 *
	 * This is the other half of the declaration: a merchant who wonders why they cannot put a
	 * section on their orders page is owed the reason.
	 *
	 * @return void
	 */
	public function test_every_refused_page_says_why(): void {
		$refused = AccountSurfaces::refused();

		foreach ( array( 'orders', 'downloads', 'edit-address', 'payment-methods', 'customer-logout' ) as $page ) {
			self::assertArrayHasKey( $page, $refused );
			self::assertNotSame( '', trim( $refused[ $page ] ), $page );
			self::assertFalse( AccountSurfaces::has( $page ), 'a refused page is never offered: ' . $page );
		}
	}

	/**
	 * Logout is not a page, which is why it can never host content.
	 *
	 * @return void
	 */
	public function test_logout_is_explained_as_a_link_and_not_a_page(): void {
		self::assertStringContainsString( 'ligação', AccountSurfaces::refused()['customer-logout'] );
	}
}
