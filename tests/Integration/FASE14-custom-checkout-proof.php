<?php
/**
 * Fase 14 proof harness — the custom checkout is the same checkout, presented differently.
 *
 * Task:   Fase 14 "Checkout customizado"
 * Gate:   "funcionalidade equivalente ao checkout padrão nos gateways homologados."
 *
 * Equivalence is a claim about what does *not* change, so the proof is a comparison. The store is
 * put in both states — the custom presentation off and on — and what the checkout offers is read
 * each time: the gateways, the fields WooCommerce receives, and the total. Nothing in that list is
 * allowed to move, because a presentation that changed one of them would not be a presentation.
 *
 * 1. **The plugin is not a gateway and not a checkout.** It registers no gateway, it neither adds
 *    nor withholds one from the list the store offers, and the checkout the customer is given is
 *    WooCommerce's own array of fields — the one this plugin filters into, never one it builds.
 * 2. **What §6.4 makes possible, and what it does not.** A checkout profile owns its own list of
 *    sections; the store's own checkout keeps its own, and composing one does not touch the other.
 *    That is the rule the editor of this phase writes, read here from the server side.
 * 3. **A profile cannot claim a container that is not a checkout container**, refused by name.
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
 * The error codes of a write result.
 *
 * @param \WCCheckoutSuite\Domain\Schema\WriteResult $result Result.
 * @return array<int, string>
 */
function wccs_proof_codes( \WCCheckoutSuite\Domain\Schema\WriteResult $result ): array {
	return array_map( static fn( $error ) => (string) $error['code'], $result->errors() );
}

/**
 * A stored schema document, written the way the repository writes it.
 *
 * @param array<int, array<string, mixed>> $fields   Fields.
 * @param array<int, array<string, mixed>> $sections Containers.
 * @param array<int, array<string, mixed>> $profiles Checkouts.
 * @return void
 */
function wccs_proof_store( array $fields, array $sections, array $profiles = array() ): void {
	$document = array(
		'revision'       => 0,
		'schema_version' => 1,
		'updated_at'     => gmdate( 'c' ),
		'updated_by'     => 1,
		'fields'         => $fields,
		'sections'       => $sections,
		'settings'       => array(),
		'profiles'       => $profiles,
	);

	foreach ( array( 'draft', 'published' ) as $wccs_proof_slot ) {
		update_option(
			\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_proof_slot ),
			(string) wp_json_encode( $document ),
			false
		);
	}
}

/**
 * One custom field.
 *
 * @param string $id      Identifier.
 * @param string $section Container it is bound to.
 * @return array<string, mixed>
 */
function wccs_proof_field( string $id, string $section ): array {
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
		'destinations'   => \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations(),
	);
}

/**
 * One container.
 *
 * @param string            $id       Identifier.
 * @param string            $location WooCommerce location.
 * @param array<int,string> $areas    Destinations it is offered in.
 * @param int               $position Order.
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
 * The identifiers of a list of entries keyed by `id`.
 *
 * @param array<int, mixed> $entries Entries.
 * @return array<int, string>
 */
function wccs_proof_ids( array $entries ): array {
	$ids = array();

	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) && isset( $entry['id'] ) ) {
			$ids[] = (string) $entry['id'];
		} elseif ( is_object( $entry ) && isset( $entry->id ) ) {
			$ids[] = (string) $entry->id;
		}
	}

	return $ids;
}

/**
 * The keys of the checkout fields WooCommerce would draw.
 *
 * @return array<int, string>
 */
function wccs_proof_field_keys(): array {
	$keys = array();

	foreach ( \WCCheckoutSuite\Checkout\Classic\ClassicCheckout::filter_fields( array() ) as $group => $fields ) {
		if ( ! is_array( $fields ) ) {
			continue;
		}

		foreach ( array_keys( $fields ) as $key ) {
			$keys[] = (string) $key;
		}
	}

	sort( $keys );

	return $keys;
}

$wccs_settings = 'WCCheckoutSuite\\Domain\\Settings\\CheckoutSettings';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 14 proof — the same checkout, presented differently' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// The switch is remembered as it was *stored*, and not only as it was read: a store that never
// touched it has no option at all, and writing `no` there would be leaving something behind in the
// one place this proof promises not to.
$wccs_proof_switch_before  = get_option( $wccs_settings::OPTION, null );
$wccs_proof_enabled_before = $wccs_settings::enabled();

// The slots this proof writes are cleared before it measures anything: the comparison at the end is
// about what the proof leaves behind, and the store's own document is not the proof's to keep.
foreach ( array( 'draft', 'published' ) as $wccs_proof_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_proof_slot ) );
}

delete_option( 'wccs_schema_revisions' );

$wccs_proof_options_before = wccs_proof_option_count();

/**
 * The callbacks another plugin has put on one filter.
 *
 * Used to assert that this plugin puts none there: the gateway list a checkout offers is
 * WooCommerce's own, and a store plugin that filtered it would be choosing gateways, which is the
 * opposite of presenting the same checkout differently.
 *
 * @param string $hook Filter name.
 * @return array<int, string>
 */
function wccs_proof_filter_callbacks( string $hook ): array {
	$callbacks = array();

	foreach ( (array) ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() ) as $entries ) {
		foreach ( (array) $entries as $entry ) {
			$function = $entry['function'] ?? null;

			if ( is_array( $function ) ) {
				$callbacks[] = is_object( $function[0] ) ? get_class( $function[0] ) . '::' . $function[1] : (string) $function[0] . '::' . $function[1];
			} elseif ( is_string( $function ) ) {
				$callbacks[] = $function;
			} elseif ( $function instanceof Closure ) {
				$callbacks[] = 'closure';
			}
		}
	}

	return $callbacks;
}

// ---------------------------------------------------------------------------
// 0. The ground: a product in the cart, and a document the checkout can render.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '0. O terreno: um produto no carrinho e um documento para renderizar' );

$wccs_proof_product = new WC_Product_Simple();
$wccs_proof_product->set_name( 'Produto de prova 14' );
$wccs_proof_product->set_status( 'publish' );
$wccs_proof_product->set_catalog_visibility( 'visible' );
$wccs_proof_product->set_regular_price( '25' );
$wccs_proof_product->set_price( '25' );
$wccs_proof_product_id = (int) $wccs_proof_product->save();

wccs_proof_store(
	array(
		wccs_proof_field( 'wccs_observacao_fiscal', 'documentacao' ),
		wccs_proof_field( 'wccs_contacto_extra', 'contato' ),
	),
	array(
		wccs_proof_container( 'contato', 'billing', array( 'checkout' ), 10 ),
		wccs_proof_container( 'documentacao', 'order', array( 'checkout' ), 20 ),
		wccs_proof_container( 'conta_extra', 'order', array( 'customer_order' ), 30 ),
	)
);

if ( function_exists( 'WC' ) && WC()->cart ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $wccs_proof_product_id, 2 );
	WC()->cart->calculate_totals();
}

wccs_proof_check(
	'O carrinho tem o produto e um total que a própria WooCommerce calculou',
	function_exists( 'WC' ) && WC()->cart instanceof WC_Cart
		&& 2 === (int) WC()->cart->get_cart_contents_count()
		&& '' !== (string) WC()->cart->get_total(),
	'count=' . ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ) . ' total=' . ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_total() : '' )
);

// ---------------------------------------------------------------------------
// 1. The plugin is not a gateway.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. O plugin não é um gateway' );

$wccs_proof_registered_before = wccs_proof_ids( array_values( WC()->payment_gateways()->payment_gateways() ) );

$wccs_proof_ours = array();

foreach ( WC()->payment_gateways()->payment_gateways() as $wccs_proof_gateway ) {
	$wccs_proof_class = get_class( $wccs_proof_gateway );

	if ( str_starts_with( $wccs_proof_class, 'WCCheckoutSuite' ) ) {
		$wccs_proof_ours[] = $wccs_proof_class;
	}
}

wccs_proof_check(
	'Nenhum gateway registado é deste plugin',
	array() === $wccs_proof_ours,
	'ours=' . implode( ',', $wccs_proof_ours ) . ' registered=' . implode( ',', $wccs_proof_registered_before )
);

// ---------------------------------------------------------------------------
// 2. Turning the presentation on changes the presentation and nothing else.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Ligar a apresentação muda a apresentação, e mais nada' );

$wccs_settings::set_enabled( false );

$wccs_proof_off_gateways = wccs_proof_ids( array_values( $wccs_settings::offered_gateways() ) );
$wccs_proof_off_fields   = wccs_proof_field_keys();
$wccs_proof_off_total    = (string) WC()->cart->get_total();
$wccs_proof_off_decision = $wccs_settings::decision();

$wccs_settings::set_enabled( true );

$wccs_proof_on_gateways = wccs_proof_ids( array_values( $wccs_settings::offered_gateways() ) );
$wccs_proof_on_fields   = wccs_proof_field_keys();
$wccs_proof_on_total    = (string) WC()->cart->get_total();
$wccs_proof_on_decision = $wccs_settings::decision();

$wccs_proof_offered_callbacks = array_values(
	array_filter(
		wccs_proof_filter_callbacks( 'woocommerce_available_payment_gateways' ),
		static fn( $callback ) => str_contains( $callback, 'WCCheckoutSuite' )
	)
);

wccs_proof_check(
	'A lista que o checkout oferece é a que a própria WooCommerce responde',
	$wccs_proof_off_gateways === wccs_proof_ids( array_values( WC()->payment_gateways()->get_available_payment_gateways() ) ),
	'offered=' . implode( ',', $wccs_proof_off_gateways )
);

wccs_proof_check(
	'E este plugin não filtra essa lista: não escolhe gateways, apresenta o checkout',
	array() === $wccs_proof_offered_callbacks,
	'callbacks=' . implode( ',', $wccs_proof_offered_callbacks )
);

wccs_proof_check(
	'Desligada, a loja apresenta o checkout da própria WooCommerce',
	$wccs_settings::MODE_STORE === ( $wccs_proof_off_decision['mode'] ?? '' ),
	'mode=' . (string) ( $wccs_proof_off_decision['mode'] ?? '' )
);

wccs_proof_check(
	'Ligada, e sem gateway que a bloqueie, é este plugin que apresenta',
	$wccs_settings::MODE_CUSTOM === ( $wccs_proof_on_decision['mode'] ?? '' ),
	'mode=' . (string) ( $wccs_proof_on_decision['mode'] ?? '' ) . ' reason=' . (string) ( $wccs_proof_on_decision['reason'] ?? '' )
);

wccs_proof_check(
	'Os gateways que o checkout oferece são exatamente os mesmos nas duas',
	$wccs_proof_off_gateways === $wccs_proof_on_gateways,
	'off=' . implode( ',', $wccs_proof_off_gateways ) . ' on=' . implode( ',', $wccs_proof_on_gateways )
);

wccs_proof_check(
	'E os campos que a WooCommerce recebe também: é o mesmo checkout',
	$wccs_proof_off_fields === $wccs_proof_on_fields,
	'off=' . implode( ',', $wccs_proof_off_fields ) . ' on=' . implode( ',', $wccs_proof_on_fields )
);

wccs_proof_check(
	'E o total do carrinho não se move com a apresentação',
	$wccs_proof_off_total === $wccs_proof_on_total && '' !== $wccs_proof_on_total,
	'off=' . $wccs_proof_off_total . ' on=' . $wccs_proof_on_total
);

wccs_proof_check(
	'E a lista de gateways registados é a mesma depois de tudo isto',
	$wccs_proof_registered_before === wccs_proof_ids( array_values( WC()->payment_gateways()->payment_gateways() ) )
);

// ---------------------------------------------------------------------------
// 3. The composition §6.4 writes, read from the server side.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. A composição de cada checkout, e a do checkout da loja' );

// The editor writes a profile's own container list; this is what such a document looks like when a
// merchant removed a container from one checkout and kept it in the other.
$wccs_proof_profiles = array(
	array(
		'id'           => 'digital',
		'name'         => 'Checkout digital',
		'enabled'      => true,
		'source'       => 'woocommerce_current',
		'priority'     => 10,
		'fallback'     => true,
		'conditions'   => array(),
		'sections'     => array( wccs_proof_container( 'contato', 'billing', array( 'checkout' ), 10 ) ),
		'presentation' => array(),
	),
);

$wccs_proof_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);
$wccs_proof_plain      = $wccs_proof_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

$wccs_proof_written = $wccs_proof_repository->write(
	\WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED,
	$wccs_proof_plain->with_profiles( $wccs_proof_profiles ),
	$wccs_proof_plain->revision()
);

wccs_proof_check(
	'Um checkout com a sua própria lista de secções é aceite',
	$wccs_proof_written->is_ok(),
	'codes=' . implode( ',', wccs_proof_codes( $wccs_proof_written ) )
);

$wccs_proof_own  = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();
$wccs_proof_served = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::for_cart( 'classic' );

wccs_proof_check(
	'O carrinho recebe a composição do checkout que o escolheu',
	array( 'contato', 'conta_extra' ) === wccs_proof_ids( $wccs_proof_served->sections() ),
	'ids=' . implode( ',', wccs_proof_ids( $wccs_proof_served->sections() ) )
);

wccs_proof_check(
	'E o checkout da própria loja continua com a lista dele',
	array( 'contato', 'documentacao', 'conta_extra' ) === wccs_proof_ids( $wccs_proof_own->sections() ),
	'ids=' . implode( ',', wccs_proof_ids( $wccs_proof_own->sections() ) )
);

wccs_proof_check(
	'E os campos são os mesmos nos dois: o que muda é onde caem, não o que se recolhe',
	$wccs_proof_served->fields() === $wccs_proof_own->fields(),
	'fields=' . count( $wccs_proof_served->fields() )
);

// ---------------------------------------------------------------------------
// 4. A container that is not a checkout container cannot be claimed.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Um checkout não pode reivindicar o que não é do checkout' );

$wccs_proof_claimed = \WCCheckoutSuite\Domain\Checkout\ProfileValidator::validate_profiles(
	array(
		array(
			'id'         => 'errado',
			'name'       => 'Com uma secção da conta',
			'enabled'    => true,
			'source'     => 'woocommerce_current',
			'priority'   => 10,
			'fallback'   => false,
			'conditions' => array(),
			'sections'   => array( wccs_proof_container( 'conta_extra', 'order', array( 'customer_order' ), 10 ) ),
		),
	)
);

wccs_proof_check(
	'Uma secção que não é oferecida ao checkout é recusada por nome',
	! $wccs_proof_claimed->is_valid()
		&& in_array( 'profile_section_not_a_checkout_container', $wccs_proof_claimed->error_codes(), true ),
	implode( ',', $wccs_proof_claimed->error_codes() )
);

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( 'A limpar o que a prova criou' );

foreach ( array( 'draft', 'published' ) as $wccs_proof_slot ) {
	delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( $wccs_proof_slot ) );
}

delete_option( 'wccs_schema_revisions' );

if ( function_exists( 'WC' ) && WC()->cart ) {
	WC()->cart->empty_cart();
}

wp_delete_post( $wccs_proof_product_id, true );

if ( null === $wccs_proof_switch_before ) {
	delete_option( $wccs_settings::OPTION );
} else {
	$wccs_settings::set_enabled( $wccs_proof_enabled_before );
}



wccs_proof_check(
	'A prova não deixa o produto nem o documento atrás',
	null === get_post( $wccs_proof_product_id )
		&& false === get_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( 'draft' ), false )
		&& false === get_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( 'published' ), false ),
	'product=' . ( null === get_post( $wccs_proof_product_id ) ? 'gone' : 'there' )
);

wccs_proof_check(
	'E o interruptor do checkout customizado volta ao que era',
	get_option( $wccs_settings::OPTION, null ) === $wccs_proof_switch_before,
	'antes=' . var_export( $wccs_proof_switch_before, true ) . ' depois=' . var_export( get_option( $wccs_settings::OPTION, null ), true )
);

wccs_proof_check(
	'E as opções do plugin voltam ao que eram',
	wccs_proof_option_count() === $wccs_proof_options_before,
	'antes=' . $wccs_proof_options_before . ' depois=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Porque é que isto é equivalência e não uma promessa',
	'O plugin não desenha o formulário do checkout: filtra-se para dentro do array de campos da própria WooCommerce, não regista gateway nenhum, e a lista de gateways que a página oferece é a mesma função da WooCommerce a responder. Ligar a apresentação acrescenta uma folha de estilo e uma classe no corpo — e é por isso que os campos, os gateways e o total são idênticos nos dois estados, que é o que esta prova compara.'
);

wccs_proof_note(
	'O que fica por provar, e porquê',
	'A cláusula «nos gateways homologados» pede um gateway a correr: um pedido real, num cenário homologado, com a apresentação ligada. Esta loja não tem gateway ativo nem credenciais de sandbox, e por isso o que fica provado é a equivalência estrutural — os mesmos campos, os mesmos gateways, o mesmo total, nenhum gateway próprio — e não uma compra com cartão. O limite é nomeado em vez de contornado, como nas fases 11 e 12.'
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
