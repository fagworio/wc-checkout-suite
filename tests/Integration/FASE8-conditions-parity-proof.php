<?php
/**
 * Fase 8 proof harness — one vocabulary, two engines, the same answers.
 *
 * Task:   Fase 8 "Conditions unificadas"
 * Gate:   "mesma condição produz mesma decisão frontend/backend."
 *
 * Parity is not a claim that two implementations look alike; it is a claim about the inputs where
 * they could differ. That part is proven by `resources/fixtures/conditions.json`: one authored
 * expectation per case, read by the PHP suite and by the JavaScript suite, and by a unit test that
 * holds the file's own copy of the vocabulary to the one this store publishes. What a fixture
 * cannot show is whether either engine is reachable from a real store with the sources §6.8 names
 * — which is what this harness proves:
 *
 * 1. **Every source is answered for.** The trusted context builder reports no gap, so no source of
 *    the vocabulary is a rule nobody can decide.
 * 2. **The newest sources carry real values from a real cart**: product tags, whether every product
 *    is virtual or downloadable, how many items, the subtotal before shipping, and the roles of the
 *    signed-in user.
 * 3. **The server decides with them**, through the value pipeline and through a checkout profile —
 *    not through a hand-built context that happens to contain the right strings.
 * 4. **The page is not sent rules it cannot answer** (§11). A rule about the cart is recomputed by
 *    the server; publishing it would invite the browser to answer and the two answers would
 *    disagree in the direction that blocks an order.
 * 5. The harness owns the products, the terms, the document and every option it touches.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce loaded.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok                                = (bool) $condition;
	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];
	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Counts the plugin options currently stored.
 *
 * @return int
 */
function wccs_proof_option_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification that the harness left no residue.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'" );
}

/**
 * A field whose visibility is one rule.
 *
 * @param string               $id   Identifier.
 * @param array<string, mixed> $rule Rule.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, array $rule ): array {
	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => $id,
		'section'        => 'documentacao',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'conditions'     => array(
			'evaluator' => \WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator::KEY,
			'visible'   => $rule,
		),
		'destinations'   => \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations(),
	);
}

/**
 * Whether the value pipeline shows a field whose rule is the given one.
 *
 * @param array<string, mixed> $rule    Rule.
 * @param FieldContext         $context Trusted context.
 * @return bool
 */
function wccs_proof_visible( array $rule, $context ): bool {
	$result = \WCCheckoutSuite\Domain\Registries::instance()->value_processor()->process(
		\WCCheckoutSuite\Domain\Fields\FieldDefinition::from_array( wccs_proof_field( 'wccs_sonda', $rule ) ),
		'valor',
		$context
	);

	return true === $result->is_visible();
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 8 proof — one rule vocabulary, decided the same way twice' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// The slots this harness owns are cleared before the baseline is taken, so the two counts measure
// the same set of options: what the rest of the store had, plus whatever this run leaves.
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// ---------------------------------------------------------------------------
// 1. Every source is answered for.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. No source of the vocabulary is a rule nobody can decide' );

$wccs_proof_context = new \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext();

wccs_proof_check(
	'The context builder reports no gap',
	array() === \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext::missing(),
	'missing=' . implode( ',', \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext::missing() )
);

$wccs_proof_sources = \WCCheckoutSuite\Domain\Conditions\Sources::all();

wccs_proof_check(
	'Section 6.8 is answered in full: product, category, tag, virtual/downloadable, quantity, subtotal, country, state, shipping, payment, user, role and another field',
	count( $wccs_proof_sources ) === 15,
	'count=' . count( $wccs_proof_sources )
);

wccs_proof_check(
	'And the reference is the only source this builder does not answer, because only the submission holds it',
	array( 'field' ) === array_values(
		array_filter(
			array_keys( $wccs_proof_sources ),
			static fn( string $key ): bool => ! in_array( $key, \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext::supplied(), true )
		)
	)
);

// ---------------------------------------------------------------------------
// 2. The newest sources carry real values from a real cart.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. The cart the sources describe' );

$wccs_proof_terms = array();

foreach ( array( 'Restrito (prova 8)' => 'restrito-prova-8', 'Químicos (prova 8)' => 'quimicos-prova-8' ) as $wccs_proof_name => $wccs_proof_slug ) {
	$wccs_proof_term = wp_insert_term( $wccs_proof_name, 'product_cat', array( 'slug' => $wccs_proof_slug ) );

	if ( is_wp_error( $wccs_proof_term ) ) {
		fwrite( STDERR, 'Could not create the category: ' . $wccs_proof_term->get_error_message() . "\n" );
		exit( 1 );
	}

	$wccs_proof_terms[ $wccs_proof_slug ] = (int) $wccs_proof_term['term_id'];
}

$wccs_proof_tag = wp_insert_term( 'Verão (prova 8)', 'product_tag', array( 'slug' => 'verao-prova-8' ) );

if ( is_wp_error( $wccs_proof_tag ) ) {
	fwrite( STDERR, 'Could not create the tag: ' . $wccs_proof_tag->get_error_message() . "\n" );
	exit( 1 );
}

/**
 * One product for the proof.
 *
 * @param string               $slug      Slug.
 * @param string               $price     Regular price.
 * @param bool                 $virtual   Whether it is virtual.
 * @param bool                 $download  Whether it is downloadable.
 * @param array<int, int>      $cats      Category term ids.
 * @param array<int, int>      $tags      Tag term ids.
 * @return int Product id.
 */
function wccs_proof_product( string $slug, string $price, bool $virtual, bool $download, array $cats, array $tags ): int {
	$product = new WC_Product_Simple();

	$product->set_name( 'Produto ' . $slug . ' (prova 8)' );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( $price );
	$product->set_price( $price );
	$product->set_virtual( $virtual );
	$product->set_downloadable( $download );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product->set_category_ids( $cats );
	$product->set_tag_ids( $tags );

	return (int) $product->save();
}

$wccs_proof_digital = wccs_proof_product(
	'produto-digital-prova-8',
	'100',
	true,
	true,
	array( $wccs_proof_terms['restrito-prova-8'] ),
	array( (int) $wccs_proof_tag['term_id'] )
);

$wccs_proof_fisico = wccs_proof_product(
	'produto-fisico-prova-8',
	'50',
	false,
	false,
	array( $wccs_proof_terms['quimicos-prova-8'] ),
	array()
);

/**
 * Empties the cart and puts products in it.
 *
 * @param array<int, int> $ids Product ids.
 * @return void
 */
function wccs_proof_cart( array $ids ): void {
	WC()->cart->empty_cart();

	foreach ( $ids as $id ) {
		WC()->cart->add_to_cart( $id );
	}

	WC()->cart->calculate_totals();
}

/**
 * The context as a cart would produce it.
 *
 * @return array<string, mixed>
 */
function wccs_proof_context(): array {
	return ( new \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext() )->context()->all();
}

wccs_proof_cart( array( $wccs_proof_digital ) );

$wccs_proof_digital_context = wccs_proof_context();

wccs_proof_check(
	'The tag of the product in the cart is read',
	in_array( 'verao-prova-8', (array) ( $wccs_proof_digital_context['cart_tags'] ?? array() ), true ),
	'tags=' . wp_json_encode( $wccs_proof_digital_context['cart_tags'] ?? null )
);

wccs_proof_check(
	'The category of the product in the cart is read, beside it',
	in_array( 'restrito-prova-8', (array) ( $wccs_proof_digital_context['cart_categories'] ?? array() ), true ),
	'categories=' . wp_json_encode( $wccs_proof_digital_context['cart_categories'] ?? null )
);

wccs_proof_check(
	'A cart of one virtual, downloadable product is virtual and downloadable',
	true === $wccs_proof_digital_context['cart_virtual'] && true === $wccs_proof_digital_context['cart_downloadable'],
	'virtual=' . wp_json_encode( $wccs_proof_digital_context['cart_virtual'] ) . ' downloadable=' . wp_json_encode( $wccs_proof_digital_context['cart_downloadable'] )
);

wccs_proof_check(
	'The quantity and the subtotal are numbers, and the subtotal is before shipping',
	1 === $wccs_proof_digital_context['cart_quantity'] && 100.0 === (float) $wccs_proof_digital_context['cart_subtotal'],
	'quantity=' . wp_json_encode( $wccs_proof_digital_context['cart_quantity'] ) . ' subtotal=' . wp_json_encode( $wccs_proof_digital_context['cart_subtotal'] )
);

wccs_proof_cart( array( $wccs_proof_digital, $wccs_proof_fisico ) );

$wccs_proof_mixed_context = wccs_proof_context();

wccs_proof_check(
	'One product that has to be shipped makes the cart neither virtual nor downloadable',
	false === $wccs_proof_mixed_context['cart_virtual'] && false === $wccs_proof_mixed_context['cart_downloadable'],
	'virtual=' . wp_json_encode( $wccs_proof_mixed_context['cart_virtual'] ) . ' downloadable=' . wp_json_encode( $wccs_proof_mixed_context['cart_downloadable'] )
);

wccs_proof_check(
	'Two products are two items, and the subtotal is the sum',
	2 === $wccs_proof_mixed_context['cart_quantity'] && 150.0 === (float) $wccs_proof_mixed_context['cart_subtotal'],
	'quantity=' . wp_json_encode( $wccs_proof_mixed_context['cart_quantity'] ) . ' subtotal=' . wp_json_encode( $wccs_proof_mixed_context['cart_subtotal'] )
);

wccs_proof_check(
	'Both categories and both taxonomies are read, one product each',
	in_array( 'quimicos-prova-8', (array) ( $wccs_proof_mixed_context['cart_categories'] ?? array() ), true )
		&& in_array( 'restrito-prova-8', (array) ( $wccs_proof_mixed_context['cart_categories'] ?? array() ), true )
		&& array( 'verao-prova-8' ) === (array) ( $wccs_proof_mixed_context['cart_tags'] ?? array() ),
	'categories=' . wp_json_encode( $wccs_proof_mixed_context['cart_categories'] ) . ' tags=' . wp_json_encode( $wccs_proof_mixed_context['cart_tags'] )
);

wccs_proof_cart( array() );

$wccs_proof_empty_context = wccs_proof_context();

wccs_proof_check(
	'An empty cart is not virtual and holds no tag, and zero is a quantity rather than an absence',
	false === $wccs_proof_empty_context['cart_virtual']
		&& array() === (array) $wccs_proof_empty_context['cart_tags']
		&& 0 === $wccs_proof_empty_context['cart_quantity']
		&& 0.0 === (float) $wccs_proof_empty_context['cart_subtotal'],
	'quantity=' . wp_json_encode( $wccs_proof_empty_context['cart_quantity'] )
);

wccs_proof_check(
	'The roles of the signed-in user are read, and an administrator holds the role the store calls administrator',
	in_array( 'administrator', (array) ( $wccs_proof_empty_context['user_role'] ?? array() ), true ),
	'roles=' . wp_json_encode( $wccs_proof_empty_context['user_role'] ?? null )
);

$wccs_proof_previous_user = get_current_user_id();
wp_set_current_user( 0 );

$wccs_proof_guest_roles = wccs_proof_context()['user_role'];

wp_set_current_user( $wccs_proof_previous_user );

wccs_proof_check(
	'A guest holds no role, which is what "is empty" answers',
	array() === (array) $wccs_proof_guest_roles,
	'roles=' . wp_json_encode( $wccs_proof_guest_roles )
);

// ---------------------------------------------------------------------------
// 3. The server decides with them.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The server decides the rules the newest sources describe' );

wccs_proof_cart( array( $wccs_proof_digital ) );

$wccs_proof_cart_context = ( new \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext() )->context();

$wccs_proof_rules = array(
	'a rule about a tag shows the field for the cart that holds it' => array(
		'rule'    => array(
			'source'   => 'cart_tags',
			'operator' => 'contains',
			'value'    => 'verao-prova-8',
		),
		'expect'  => true,
	),
	'and hides it for a cart that does not' => array(
		'rule'    => array(
			'source'   => 'cart_tags',
			'operator' => 'contains',
			'value'    => 'nao-existe',
		),
		'expect'  => false,
	),
	'a rule about a virtual cart is decided by the product, not by the page' => array(
		'rule'    => array(
			'source'   => 'cart_virtual',
			'operator' => 'equals',
			'value'    => true,
		),
		'expect'  => true,
	),
	'a rule about the quantity counts items' => array(
		'rule'    => array(
			'source'   => 'cart_quantity',
			'operator' => 'greater_than',
			'value'    => 1,
		),
		'expect'  => false,
	),
	'a rule about the subtotal compares numbers' => array(
		'rule'    => array(
			'source'   => 'cart_subtotal',
			'operator'    => 'greater_than',
			'value'    => 50,
		),
		'expect'  => true,
	),
	'a rule about the role of the user is a rule about one entry of a list' => array(
		'rule'    => array(
			'source'   => 'user_role',
			'operator' => 'contains',
			'value'    => 'administrator',
		),
		'expect'  => true,
	),
	'a group of the newest sources decides as a group' => array(
		'rule'    => array(
			'all' => array(
				array(
					'source'   => 'cart_tags',
					'operator' => 'contains',
					'value'    => 'verao-prova-8',
				),
				array(
					'source'   => 'cart_subtotal',
					'operator' => 'greater_than',
					'value'    => 10,
				),
			),
		),
		'expect'  => true,
	),
);

foreach ( $wccs_proof_rules as $wccs_proof_label => $wccs_proof_case ) {
	wccs_proof_check(
		$wccs_proof_label,
		$wccs_proof_case['expect'] === wccs_proof_visible( $wccs_proof_case['rule'], $wccs_proof_cart_context ),
		'expected=' . wp_json_encode( $wccs_proof_case['expect'] )
	);
}

// ---------------------------------------------------------------------------
// 4. The page is not sent rules it cannot answer.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. What reaches the browser, and what the server keeps' );

$wccs_proof_client = \WCCheckoutSuite\Domain\Conditions\Sources::client_keys();
$wccs_proof_server = \WCCheckoutSuite\Domain\Conditions\Sources::server_keys();

$wccs_proof_client_sorted = $wccs_proof_client;
sort( $wccs_proof_client_sorted );

wccs_proof_check(
	'The page owns the five sources it can answer for, and the reference, which lives in the form',
	array( 'country', 'field', 'payment_method', 'shipping_method', 'state' ) === $wccs_proof_client_sorted,
	'client=' . implode( ',', $wccs_proof_client )
);

wccs_proof_check(
	'Product tags, the two product properties, the quantity, the subtotal and the role are all server sources',
	array() === array_diff(
		array( 'cart_tags', 'cart_virtual', 'cart_downloadable', 'cart_quantity', 'cart_subtotal', 'user_role' ),
		$wccs_proof_server
	),
	'server=' . implode( ',', $wccs_proof_server )
);

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_proof_written = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
		array(
			'revision' => 1,
			'fields'   => array(
				wccs_proof_field(
					'wccs_so_no_brasil',
					array(
						'source'   => 'country',
						'operator' => 'equals',
						'value'    => 'BR',
					)
				),
				wccs_proof_field(
					'wccs_so_com_tag',
					array(
						'source'   => 'cart_tags',
						'operator' => 'contains',
						'value'    => 'verao-prova-8',
					)
				),
			),
			'sections' => array(
				array(
					'id'       => 'documentacao',
					'title'    => 'Documentação',
					'position' => 10,
					'location' => 'order',
					'areas'    => array( 'checkout' ),
				),
			),
		)
	),
	0
);

wccs_proof_check(
	'The document with one rule of each kind is accepted',
	$wccs_proof_written->is_ok(),
	'codes=' . implode( ',', array_map( static fn( $error ) => (string) $error['code'], $wccs_proof_written->errors() ) )
);

$wccs_proof_payload = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();
$wccs_proof_sent    = (array) ( $wccs_proof_payload['conditions'] ?? array() );

wccs_proof_check(
	'The rule about the country the page can answer travels to the page',
	isset( $wccs_proof_sent['wccs_so_no_brasil'] ),
	'sent=' . implode( ',', array_keys( $wccs_proof_sent ) )
);

wccs_proof_check(
	'And the rule about a product tag does not: the server recomputes it with the context it holds',
	! isset( $wccs_proof_sent['wccs_so_com_tag'] ),
	'sent=' . implode( ',', array_keys( $wccs_proof_sent ) )
);

// ---------------------------------------------------------------------------
// 5. The same rules decide a checkout profile (§6.9).
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. The same vocabulary selects a checkout' );

$wccs_proof_profiles = array(
	array(
		'id'           => 'quimicos',
		'name'         => 'Produtos restritos',
		'enabled'      => true,
		'source'       => 'duplicate_profile',
		'priority'     => 20,
		'fallback'     => false,
		'conditions'   => array(
			'source'   => 'cart_categories',
			'operator' => 'contains',
			'value'    => 'quimicos-prova-8',
		),
		'sections'     => array(
			array(
				'id'       => 'documentacao',
				'title'    => 'Documentação',
				'position' => 10,
				'location' => 'order',
				'areas'    => array( 'checkout' ),
			),
		),
		'presentation' => array(),
	),
	array(
		'id'           => 'etiquetado',
		'name'         => 'Produtos etiquetados',
		'enabled'      => true,
		'source'       => 'duplicate_profile',
		'priority'     => 30,
		'fallback'     => false,
		'conditions'   => array(
			'source'   => 'cart_tags',
			'operator' => 'contains',
			'value'    => 'verao-prova-8',
		),
		'sections'     => array(
			array(
				'id'       => 'documentacao',
				'title'    => 'Documentação',
				'position' => 10,
				'location' => 'account',
				'areas'    => array( 'checkout' ),
			),
		),
		'presentation' => array(),
	),
);

$wccs_proof_cases = array(
	'a cart of the tagged digital product is served by the tag profile, which has the higher priority' => array(
		'products' => array( $wccs_proof_digital ),
		'expect'   => 'etiquetado',
	),
	'a cart of the physical product is served by the category profile' => array(
		'products' => array( $wccs_proof_fisico ),
		'expect'   => 'quimicos',
	),
	'a cart holding both matches both, and the priority decides' => array(
		'products' => array( $wccs_proof_digital, $wccs_proof_fisico ),
		'expect'   => 'etiquetado',
	),
	'a cart that matches neither is served by the store own checkout' => array(
		'products' => array(),
		'expect'   => '',
	),
);

foreach ( $wccs_proof_cases as $wccs_proof_label => $wccs_proof_case ) {
	wccs_proof_cart( $wccs_proof_case['products'] );

	$wccs_proof_resolved = \WCCheckoutSuite\Domain\Checkout\CheckoutProfileResolver::resolve(
		$wccs_proof_profiles,
		( new \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext() )->context()
	);

	wccs_proof_check(
		$wccs_proof_label,
		$wccs_proof_case['expect'] === ( $wccs_proof_resolved?->id() ?? '' ),
		'resolved=' . ( $wccs_proof_resolved?->id() ?? '(the store own checkout)' )
	);
}

// ---------------------------------------------------------------------------
// 6. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Environment' );

WC()->cart->empty_cart();

wp_delete_post( $wccs_proof_digital, true );
wp_delete_post( $wccs_proof_fisico, true );

foreach ( $wccs_proof_terms as $wccs_proof_term_id ) {
	wp_delete_term( $wccs_proof_term_id, 'product_cat' );
}

wp_delete_term( (int) $wccs_proof_tag['term_id'], 'product_tag' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_proof_options_after === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . $wccs_proof_options_after
);

wccs_proof_check(
	'The products, the categories and the tag the proof created are gone',
	null === get_post( $wccs_proof_digital )
		&& null === get_post( $wccs_proof_fisico )
		&& false === get_term_by( 'slug', 'verao-prova-8', 'product_tag' ),
	''
);

wccs_proof_note(
	'Where parity itself is proven',
	'Not here. resources/fixtures/conditions.json carries one authored expectation per case, and both suites read it: the PHP evaluator test and the JavaScript one. A unit test holds that file\'s own copy of the vocabulary to the one the store publishes, so a source added to either side without a case fails instead of narrowing what "paridade" means. This harness proves the wiring: that the vocabulary the fixtures describe is one a real store reaches, with values from a real cart.'
);

wccs_proof_note(
	'What the page is allowed to decide',
	'Five sources: the customer\'s own country and state, the chosen shipping and payment methods, and another field of the same form. Everything else is recomputed by the server with the context it holds (§11), and the transport is what keeps them apart: ClassicAssets publishes only the rules whose every source the page can answer for, because a browser that answered a question it cannot answer would hide a field the server requires.'
);

wccs_proof_note(
	'How the two product properties read',
	'"Every product in the cart is virtual" and "every product in the cart is downloadable" — a statement about all the items, which is what a checkout that asks no address is for. An empty cart answers false for both rather than claiming a property nothing has. The label the editor shows carries the reading, so the merchant is not left to guess which of the two quantities a boolean meant.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
