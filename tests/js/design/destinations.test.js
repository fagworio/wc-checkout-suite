/**
 * The destination registry tests.
 *
 * The editor's navigation and the words it uses are the specification's §2 and §3 read as
 * data: five entries, two of them opening their own destinations, and one vocabulary per
 * place — a section on the checkout, a page in My Account, a block on the customer's order,
 * a panel on the order screen, a block in an e-mail. These tests pin the shape, because the
 * screen renders whatever this module says.
 */

import {
	activeEntry,
	collectsAt,
	containerWords,
	DESTINATIONS,
	destination,
	isCustomerDestination,
	navigation,
	offeredInSentence,
} from '../../../resources/admin/app/design/destinations';

describe( 'the destinations the editor offers', () => {
	it( 'is every destination a container can be drawn in, and not the public API', () => {
		const ids = DESTINATIONS.map( ( entry ) => entry.id ).sort();

		expect( ids ).toEqual(
			[
				'admin_customer_profile',
				'admin_email',
				'admin_order',
				'checkout',
				'customer_account',
				'customer_email',
				'customer_order',
				'order_received',
			].sort()
		);
	} );

	it( 'names the two customer surfaces as such, and no others', () => {
		const customer = DESTINATIONS.filter( ( entry ) =>
			isCustomerDestination( entry.id )
		).map( ( entry ) => entry.id );

		expect( customer ).toEqual( [
			'customer_account',
			'admin_customer_profile',
		] );
	} );

	it( 'collects only where a customer actually fills a field in', () => {
		const collecting = DESTINATIONS.filter( ( entry ) =>
			collectsAt( entry.id )
		).map( ( entry ) => entry.id );

		// The checkout is where a value is collected, and the customer's own page is the
		// other place the customer types one. Everywhere else a merchant *reuses* what was
		// collected, which is why creating a field there binds it but collects it elsewhere.
		expect( collecting ).toEqual( [ 'checkout', 'customer_account' ] );
	} );
} );

describe( 'the navigation', () => {
	it( 'keeps three destinations on their own and two groups behind an entry', () => {
		const entries = navigation();

		expect( entries.map( ( entry ) => entry.id ) ).toEqual( [
			'checkout',
			'customer_account',
			'customer_order',
			'admin',
			'more',
		] );
		expect(
			entries
				.filter( ( entry ) => entry.members.length > 1 )
				.map( ( entry ) => [
					entry.id,
					entry.members.map( ( member ) => member.id ),
				] )
		).toEqual( [
			[ 'admin', [ 'admin_order', 'admin_customer_profile' ] ],
			[ 'more', [ 'order_received', 'customer_email', 'admin_email' ] ],
		] );
	} );

	it( 'says which entry a destination belongs to', () => {
		expect( activeEntry( 'checkout' ) ).toBe( 'checkout' );
		expect( activeEntry( 'customer_order' ) ).toBe( 'customer_order' );
		expect( activeEntry( 'admin_customer_profile' ) ).toBe( 'admin' );
		expect( activeEntry( 'admin_order' ) ).toBe( 'admin' );
		expect( activeEntry( 'customer_email' ) ).toBe( 'more' );
	} );
} );

describe( 'what each place calls the group of fields', () => {
	it( 'uses the place\u2019s own word, not the model\u2019s identifier', () => {
		expect( containerWords( 'checkout' ).one ).toBe( 'Seção' );
		expect( containerWords( 'customer_account' ).one ).toBe(
			'Página da conta'
		);
		expect( containerWords( 'customer_order' ).one ).toBe(
			'Bloco do pedido'
		);
		expect( containerWords( 'admin_order' ).one ).toBe(
			'Painel do pedido'
		);
		expect( containerWords( 'admin_customer_profile' ).one ).toBe(
			'Painel do cliente'
		);
		expect( containerWords( 'customer_email' ).one ).toBe(
			'Bloco do e-mail'
		);
		expect( containerWords( 'order_received' ).one ).toBe(
			'Bloco da página'
		);
	} );

	it( 'gives each one the create button the specification names', () => {
		expect( containerWords( 'checkout' ).create ).toBe( 'Nova seção' );
		expect( containerWords( 'customer_account' ).create ).toBe(
			'Nova página'
		);
		expect( containerWords( 'customer_order' ).create ).toBe(
			'Novo bloco'
		);
		expect( containerWords( 'admin_order' ).create ).toBe( 'Novo painel' );
		expect( containerWords( 'admin_customer_profile' ).create ).toBe(
			'Novo painel'
		);
		expect( containerWords( 'admin_email' ).create ).toBe( 'Novo bloco' );
	} );

	it( 'falls back to the checkout\u2019s words for a destination it does not know', () => {
		expect( containerWords( 'nowhere' ).one ).toBe( 'Seção' );
		expect( containerWords( 'nowhere' ).create ).toBe( 'Nova seção' );
		expect( destination( 'nowhere' ) ).toBeUndefined();
	} );
} );

describe( 'where a container appears', () => {
	it( 'names the destinations in the merchant\u2019s words', () => {
		expect( offeredInSentence( [ 'checkout' ] ) ).toBe(
			'Aparece em: Checkout'
		);
		expect(
			offeredInSentence( [
				'customer_account',
				'admin_customer_profile',
			] )
		).toBe( 'Aparece em: Minha conta, Perfil do cliente' );
	} );

	it( 'says so when a container appears nowhere', () => {
		expect( offeredInSentence( [] ) ).toBe(
			'Não aparece em nenhum destino.'
		);
		expect( offeredInSentence( /** @type {any} */ ( undefined ) ) ).toBe(
			'Não aparece em nenhum destino.'
		);
	} );
} );
