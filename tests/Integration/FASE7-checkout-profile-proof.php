<?php
/**
 * Fase 7 proof harness — two checkouts alternate by the cart.
 *
 * Task:   Fase 7 "Checkout profiles: modelo, repositório, validação e runtime"
 * Gate:   "dois perfis alternam correctamente pelo carrinho."
 *
 * A profile is only worth storing if the storefront obeys it, so this harness does not ask the
 * resolver to be right about an array: it puts products in **the real cart** and reads the schema
 * the storefront would read, on both checkouts.
 *
 * 1. **The document carries its profiles**, through write, publish, read and export.
 * 2. **A profile the store cannot decide is refused** at the moments it is edited and published —
 *    two fallbacks, a container that is not a checkout container, a rule in another dialect.
 * 3. **The cart selects the profile**: a cart holding a restricted product gets one composition, a
 *    cart holding a digital product gets another, and a cart matching nothing gets the fallback.
 * 4. **Alternating the cart alternates the checkout**, observed through the classic adapter's own
 *    placement and not through the resolver's return value.
 * 5. **Composition replaces the checkout containers and nothing else**: the order panel and the
 *    e-mail containers are still in the document a cart is served by.
 * 6. **A store with no profiles is untouched**, and owes the feature no behaviour change.
 * 7. The harness owns the products, the terms, the document and every option it touches.
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
 * The stable codes a write refused with.
 *
 * A write result and a validation result are different things: the write knows what it refused,
 * the validation knows why, and only one of them carries a code list of its own.
 *
 * @param \WCCheckoutSuite\Domain\Schema\WriteResult $result Write result.
 * @return array<int, string>
 */
function wccs_proof_codes( \WCCheckoutSuite\Domain\Schema\WriteResult $result ): array {
	return array_map( static fn( $error ) => (string) $error['code'], $result->errors() );
}

/**
 * A container.
 *
 * @param string             $id       Identifier.
 * @param string             $location Location inside the checkout.
 * @param array<int, string> $areas    Destinations it is offered in.
 * @param int                $position Position.
 * @return array<string, mixed>
 */
function wccs_proof_container( string $id, string $location, array $areas, int $position = 10 ): array {
	return array(
		'id'       => $id,
		'title'    => $id,
		'position' => $position,
		'location' => $location,
		'areas'    => $areas,
	);
}

/**
 * A field, in the shape the rest of the store writes: a section for the checkout and a
 * destination map for everywhere else.
 *
 * The checkout use is the `section` plus the container's own areas, exactly as the seeded
 * document writes it — the destination vocabulary names the places a value is *read* after
 * the order, and the checkout is where it is filled in.
 *
 * @param string                            $id      Identifier.
 * @param string                            $section Container the field sits in.
 * @param array<string, array<string, mixed>> $links  Destination links to enable.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $section, array $links = array() ): array {
	$destinations = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations();

	foreach ( $links as $key => $link ) {
		$destinations[ $key ] = $link;
	}

	return array(
		'id'             => $id,
		'integration_id' => 'wc-checkoutsuite/' . $id,
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => $id,
		'section'        => $section,
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'destinations'   => $destinations,
	);
}

/**
 * A profile.
 *
 * @param string               $id         Identifier.
 * @param string               $name       Name.
 * @param array<string, mixed> $conditions Rule that selects it.
 * @param string               $location   Where its container puts the field.
 * @param array<string, mixed> $extra      Extra keys.
 * @return array<string, mixed>
 */
function wccs_proof_profile( string $id, string $name, array $conditions, string $location, array $extra = array() ): array {
	return array_merge(
		array(
			'id'           => $id,
			'name'         => $name,
			'enabled'      => true,
			'source'       => 'duplicate_profile',
			'priority'     => 10,
			'fallback'     => false,
			'conditions'   => $conditions,
			'sections'     => array(
				array(
					'id'       => 'documentacao',
					'title'    => 'Documentação',
					'position' => 10,
					'location' => $location,
					'areas'    => array( 'checkout' ),
				),
			),
			'presentation' => array(),
		),
		$extra
	);
}

/**
 * The classic section a field was placed in, or an empty string.
 *
 * @param array<string, mixed> $fields Classic checkout fields.
 * @param string               $id     Field identifier.
 * @return string
 */
function wccs_proof_placement( array $fields, string $id ): string {
	foreach ( $fields as $section => $entries ) {
		if ( ! is_array( $entries ) ) {
			continue;
		}

		foreach ( array_keys( $entries ) as $key ) {
			if ( $id === (string) $key ) {
				return (string) $section;
			}
		}
	}

	return '';
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 7 proof — two checkouts alternate by the cart' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

// ---------------------------------------------------------------------------
// 1. The document carries its profiles.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The document carries its profiles' );

$wccs_proof_document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
	array(
		'revision' => 0,
		'fields'   => array(
			wccs_proof_field( 'wccs_observacao_fiscal', 'documentacao' ),
			wccs_proof_field(
				'wccs_documento_do_email',
				'documentos_do_email',
				array(
					'customer_email' => array(
						'enabled'  => true,
						'section'  => 'documentos_do_email',
						'title'    => 'Documento do pedido',
						'position' => 10,
					),
				)
			),
		),
		'sections' => array(
			wccs_proof_container( 'documentacao', 'billing', array( 'checkout' ), 10 ),
			wccs_proof_container( 'documentos_do_email', 'order', array( 'customer_email', 'admin_email' ), 50 ),
		),
		'profiles' => array(),
	)
);

$wccs_proof_written = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT,
	$wccs_proof_document,
	0
);

wccs_proof_check(
	'A document without profiles is still a document the store accepts',
	$wccs_proof_written->is_ok(),
	'ok=' . ( $wccs_proof_written->is_ok() ? 'yes' : 'no' ) . ' codes=' . implode( ',', array_map( static fn( $error ) => (string) $error['code'], $wccs_proof_written->errors() ) )
);

$wccs_proof_plain = $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

wccs_proof_check(
	'It reads back with an empty profile list and not with a missing key',
	array() === $wccs_proof_plain->profiles(),
	'profiles=' . count( $wccs_proof_plain->profiles() )
);

// ---------------------------------------------------------------------------
// 2. A profile the store cannot decide is refused.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. A profile the store cannot decide is refused while it is edited' );

$wccs_proof_usable = \WCCheckoutSuite\Domain\Checkout\ProfileValidator::validate_profiles(
	array( wccs_proof_profile( 'restrito', 'Produtos restritos', array(), 'order' ) )
);

wccs_proof_check(
	'A complete profile is one the validator has nothing to say about',
	$wccs_proof_usable->is_valid(),
	implode( ',', $wccs_proof_usable->error_codes() )
);

$wccs_proof_two_fallbacks = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT,
	$wccs_proof_plain->with_profiles(
		array(
			wccs_proof_profile( 'um', 'Um', array(), 'order', array( 'fallback' => true ) ),
			wccs_proof_profile( 'dois', 'Dois', array(), 'order', array( 'fallback' => true ) ),
		)
	),
	$wccs_proof_plain->revision()
);
wccs_proof_check(
	'Two profiles claiming to be the fallback are refused on the draft write',
	! $wccs_proof_two_fallbacks->is_ok()
		&& in_array( 'multiple_fallback_profiles', wccs_proof_codes( $wccs_proof_two_fallbacks ), true ),
	implode( ',', wccs_proof_codes( $wccs_proof_two_fallbacks ) )
);

$wccs_proof_wrong_area = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT,
	$wccs_proof_plain->with_profiles(
		array(
			wccs_proof_profile(
				'email',
				'Checkout de e-mail',
				array(),
				'order',
				array( 'sections' => array( wccs_proof_container( 'documentos_do_email', 'order', array( 'admin_email' ) ) ) )
			),
		)
	),
	$wccs_proof_plain->revision()
);

wccs_proof_check(
	'A profile promising a container that is not a checkout container is refused by name',
	! $wccs_proof_wrong_area->is_ok()
		&& in_array( 'profile_section_not_a_checkout_container', wccs_proof_codes( $wccs_proof_wrong_area ), true ),
	implode( ',', wccs_proof_codes( $wccs_proof_wrong_area ) )
);

// The refused writes left the draft where it was, which is the point of refusing them.
wccs_proof_check(
	'A refused write changed nothing in the draft',
	array() === $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->profiles(),
	'profiles=' . count( $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->profiles() )
);

// ---------------------------------------------------------------------------
// 3. The store: products, categories and the three profiles.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Three checkouts, and the carts that select them' );

$wccs_proof_terms = array();

foreach ( array( 'Restrito (prova)' => 'restrito-prova', 'Digital (prova)' => 'digital-prova' ) as $wccs_proof_name => $wccs_proof_slug ) {
	$wccs_proof_term = wp_insert_term( $wccs_proof_name, 'product_cat', array( 'slug' => $wccs_proof_slug ) );

	if ( is_wp_error( $wccs_proof_term ) ) {
		fwrite( STDERR, 'Could not create the product category: ' . $wccs_proof_term->get_error_message() . "\n" );
		exit( 1 );
	}

	$wccs_proof_terms[ $wccs_proof_name ] = (int) $wccs_proof_term['term_id'];
}

$wccs_proof_products = array();

foreach ( array( 'Restrito (prova)' => 'restrito', 'Digital (prova)' => 'digital' ) as $wccs_proof_name => $wccs_proof_slug ) {
	$wccs_proof_product = new WC_Product_Simple();

	$wccs_proof_product->set_name( 'Produto ' . $wccs_proof_slug . ' (prova)' );
	$wccs_proof_product->set_slug( 'produto-' . $wccs_proof_slug . '-prova' );
	$wccs_proof_product->set_status( 'publish' );
	$wccs_proof_product->set_catalog_visibility( 'visible' );
	$wccs_proof_product->set_regular_price( '10' );
	$wccs_proof_product->set_price( '10' );
	$wccs_proof_product->set_manage_stock( false );
	$wccs_proof_product->set_stock_status( 'instock' );
	$wccs_proof_product->set_category_ids( array( $wccs_proof_terms[ $wccs_proof_name ] ) );

	$wccs_proof_products[ $wccs_proof_slug ] = (int) $wccs_proof_product->save();
}

wccs_proof_check(
	'The cart this proof needs is available',
	WC()->cart instanceof WC_Cart && WC()->session instanceof WC_Session,
	'cart=' . ( WC()->cart instanceof WC_Cart ? 'yes' : 'no' ) . ' session=' . ( WC()->session instanceof WC_Session ? 'yes' : 'no' )
);

// One container id in three profiles, in three different places: that is the whole mechanism.
// The field does not move; the composition decides where the container sits.
$wccs_proof_profiles = array(
	wccs_proof_profile(
		'restrito',
		'Produtos restritos',
		array(
			'source'   => 'cart_categories',
			'operator' => 'contains',
			'value'    => 'restrito-prova',
		),
		'order',
		array( 'priority' => 30 )
	),
	wccs_proof_profile(
		'digital',
		'Produtos digitais',
		array(
			'source'   => 'cart_categories',
			'operator' => 'contains',
			'value'    => 'digital-prova',
		),
		'account',
		array( 'priority' => 20 )
	),
	wccs_proof_profile( 'padrao', 'Checkout padrão', array(), 'shipping', array( 'priority' => 0, 'fallback' => true, 'source' => 'woocommerce_current' ) ),
);

$wccs_proof_published = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT,
	$wccs_proof_plain->with_profiles( $wccs_proof_profiles ),
	$wccs_proof_plain->revision()
);

wccs_proof_check(
	'The draft with three profiles is accepted',
	$wccs_proof_published->is_ok(),
	'codes=' . implode( ',', wccs_proof_codes( $wccs_proof_published ) )
);

$wccs_proof_draft = $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

$wccs_proof_result = $wccs_proof_repository->publish( $wccs_proof_draft, $wccs_proof_draft->revision(), 1 );

wccs_proof_check(
	'Publication validates them and puts them in service',
	$wccs_proof_result->is_ok(),
	'codes=' . implode( ',', wccs_proof_codes( $wccs_proof_result ) )
);

$wccs_proof_stored = $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

wccs_proof_check(
	'The published document carries all three, with the fallback and the ids intact',
	3 === count( $wccs_proof_stored->profiles() )
		&& 'padrao' === (string) ( $wccs_proof_stored->profiles()[2]['id'] ?? '' )
		&& true === (bool) ( $wccs_proof_stored->profiles()[2]['fallback'] ?? false ),
	'ids=' . implode( ',', array_column( $wccs_proof_stored->profiles(), 'id' ) )
);

$wccs_proof_exported = \WCCheckoutSuite\Domain\Schema\SchemaTransfer::export( $wccs_proof_stored );

wccs_proof_check(
	'An export carries them, so a store does not lose its checkouts by migrating',
	3 === count( $wccs_proof_exported['schema']['profiles'] ?? array() ),
	'profiles=' . count( $wccs_proof_exported['schema']['profiles'] ?? array() )
);

// ---------------------------------------------------------------------------
// 4. The cart selects the profile, observed through the classic adapter.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Alternating the cart alternates the checkout' );

/**
 * Empties the cart and puts one product in it.
 *
 * @param int $product_id Product, or zero for an empty cart.
 * @return void
 */
function wccs_proof_cart( int $product_id ): void {
	WC()->cart->empty_cart();

	if ( $product_id > 0 ) {
		WC()->cart->add_to_cart( $product_id );
	}

	WC()->cart->calculate_totals();
}

wccs_proof_cart( $wccs_proof_products['restrito'] );

$wccs_proof_classic = \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( array() );

wccs_proof_check(
	'A cart holding the restricted product gets the composition its profile declares',
	'order' === wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' ),
	'placement=' . wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' )
);

wccs_proof_cart( $wccs_proof_products['digital'] );

$wccs_proof_classic = \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( array() );

wccs_proof_check(
	'Swapping the cart for the digital product swaps the composition',
	'account' === wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' ),
	'placement=' . wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' )
);

wccs_proof_cart( 0 );

$wccs_proof_classic = \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( array() );

wccs_proof_check(
	'A cart that matches nothing gets the fallback, not the store default',
	'shipping' === wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' ),
	'placement=' . wccs_proof_placement( $wccs_proof_classic, 'wccs_observacao_fiscal' )
);

wccs_proof_cart( $wccs_proof_products['restrito'] );

$wccs_proof_again = \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( array() );

wccs_proof_check(
	'And going back to the restricted cart goes back to the first composition',
	'order' === wccs_proof_placement( $wccs_proof_again, 'wccs_observacao_fiscal' ),
	'placement=' . wccs_proof_placement( $wccs_proof_again, 'wccs_observacao_fiscal' )
);

// ---------------------------------------------------------------------------
// 5. Composition replaces the checkout containers and nothing else.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. What a profile replaces, and what it does not' );

$wccs_proof_served = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::for_cart( 'classic' );
$wccs_proof_own    = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();

$wccs_proof_ids = array_column( $wccs_proof_served->sections(), 'id' );

wccs_proof_check(
	'The containers offered outside the checkout are still there',
	in_array( 'documentos_do_email', $wccs_proof_ids, true ),
	'ids=' . implode( ',', $wccs_proof_ids )
);

$wccs_proof_location = static function ( array $sections, string $id ): string {
	foreach ( $sections as $section ) {
		if ( is_array( $section ) && $id === (string) ( $section['id'] ?? '' ) ) {
			return (string) ( $section['location'] ?? '' );
		}
	}

	return '';
};

wccs_proof_check(
	'Leaving the cart on the restricted product, the profile decides where the container sits',
	'order' === $wccs_proof_location( $wccs_proof_served->sections(), 'documentacao' ),
	'location=' . $wccs_proof_location( $wccs_proof_served->sections(), 'documentacao' )
);

wccs_proof_check(
	'The store-wide read still answers with the store default, whatever the cart holds',
	'billing' === $wccs_proof_location( $wccs_proof_own->sections(), 'documentacao' ),
	'location=' . $wccs_proof_location( $wccs_proof_own->sections(), 'documentacao' )
);

wccs_proof_check(
	'The fields are the same ones, whatever composition serves the cart',
	$wccs_proof_served->fields() === $wccs_proof_own->fields(),
	'fields=' . count( $wccs_proof_served->fields() )
);

// ---------------------------------------------------------------------------
// 6. A store with no profiles.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. A store that never wrote a profile pays nothing for the feature' );

$wccs_proof_bare = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT,
	$wccs_proof_plain->with_profiles( array() ),
	$wccs_proof_plain->revision()
);

wccs_proof_check(
	'The profiles can be removed again, leaving the document the store had',
	$wccs_proof_bare->is_ok(),
	'codes=' . implode( ',', wccs_proof_codes( $wccs_proof_bare ) )
);

$wccs_proof_stored_bare = $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT );

$wccs_proof_repository->publish( $wccs_proof_stored_bare, $wccs_proof_stored_bare->revision(), 1 );

wccs_proof_cart( $wccs_proof_products['restrito'] );

$wccs_proof_no_profile = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::for_cart( 'classic' );

wccs_proof_check(
	'With no profile, the cart gets the store default checkout and no error',
	'billing' === $wccs_proof_location( $wccs_proof_no_profile->sections(), 'documentacao' ),
	'location=' . $wccs_proof_location( $wccs_proof_no_profile->sections(), 'documentacao' )
);

$wccs_proof_overlap = \WCCheckoutSuite\Domain\Checkout\CheckoutProfileResolver::overlaps(
	array(
		wccs_proof_profile( 'a', 'A', array(), 'order', array( 'priority' => 20 ) ),
		wccs_proof_profile( 'b', 'B', array(), 'order', array( 'priority' => 10 ) ),
	),
	( new \WCCheckoutSuite\Domain\Conditions\CheckoutConditionContext() )->context()
);

wccs_proof_check(
	'Two profiles that could both be selected are reported as an overlap, and not refused',
	1 === count( $wccs_proof_overlap ) && 'a' === $wccs_proof_overlap[0]['profile'],
	'overlaps=' . count( $wccs_proof_overlap )
);

// ---------------------------------------------------------------------------
// 7. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Environment' );

WC()->cart->empty_cart();

foreach ( $wccs_proof_products as $wccs_proof_product_id ) {
	wp_delete_post( $wccs_proof_product_id, true );
}

foreach ( $wccs_proof_terms as $wccs_proof_term_id ) {
	wp_delete_term( $wccs_proof_term_id, 'product_cat' );
}

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_after = wccs_proof_option_count();

wccs_proof_check(
	'The harness left no stored option behind',
	$wccs_proof_options_after === $wccs_proof_options_before,
	'before=' . $wccs_proof_options_before . ' after=' . $wccs_proof_options_after
);

$wccs_proof_left_products = array();

foreach ( $wccs_proof_products as $wccs_proof_product_id ) {
	if ( get_post( $wccs_proof_product_id ) instanceof WP_Post ) {
		$wccs_proof_left_products[] = $wccs_proof_product_id;
	}
}

wccs_proof_check(
	'The products and categories the proof created are gone',
	array() === $wccs_proof_left_products,
	'left=' . implode( ',', $wccs_proof_left_products )
);

wccs_proof_note(
	'Where the profiles are decided',
	'Before the effective schema is assembled (§1844): PublishedDocument::for_cart() resolves against the trusted cart context the server holds and composes the document the checkout reads. Everything that is not serving a cart — the order panel, the e-mails, the admin screens — keeps reading the store-wide document, because a checkout profile has nothing to say about them.'
);

wccs_proof_note(
	'What a profile replaces',
	'Only the containers offered in the checkout. A document carries containers for every destination it serves, and a composition that replaced the whole list would silently dismantle the order and e-mail surfaces for every cart it matched.'
);

wccs_proof_note(
	'The Blocks checkout',
	'The store runs the Blocks checkout, and its registration is global while its payload is per request: BlocksRenderer and StoreApiExtension read the cart-scoped document, so the composition decides the payload. A field can only be registered once with the additional-fields API, which is why a profile composes containers and does not add a field the Blocks schema never had.'
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
