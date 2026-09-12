<?php
/**
 * The store state a browser observation needs, written through the real path.
 *
 * WCCS-063 and WCCS-065 observe the checkout in a browser, and a browser sees
 * whatever the store has published. Writing that state by hand — the shortcut the
 * earlier observation rounds took — makes the observation depend on a shape the
 * reader may not accept, and a document the reader refuses is indistinguishable,
 * from the page, from a document that was never written.
 *
 * So this fixture goes through the repository: draft, publish, and the merchant's
 * opt-in. It is the same path the editor and the REST routes use, which is the
 * only path whose result the storefront is known to honour.
 *
 * It is an eval-file, not a proof: it asserts nothing and it is not part of the
 * gate. It exists so an observation is reproducible.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/browser-fixture.php seed
 *   wp eval-file tests/Integration/support/browser-fixture.php read
 *   wp eval-file tests/Integration/support/browser-fixture.php clear
 *
 * @package WCCheckoutSuite
 */

use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Domain\Settings\CheckoutSettings;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This fixture must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

/**
 * Prints one line of the fixture's own report.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_fixture_say( string $message ): void {
	echo $message . "\n";
}

$wccs_fixture_action = isset( $args[0] ) ? (string) $args[0] : 'seed';

/**
 * The plugin options this fixture owns.
 *
 * @return array<int, string>
 */
function wccs_fixture_options(): array {
	return array(
		SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ),
		SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ),
		'wccs_schema_revisions',
		CheckoutSettings::OPTION,
	);
}

/**
 * A field definition of the shape the editor writes.
 *
 * @param string $id    Bare identifier, without the namespace.
 * @param string $type  Field type.
 * @param int    $order Position among the fixture's fields.
 * @return array<string, mixed>
 */
function wccs_fixture_definition( string $id, string $type, int $order ): array {
	return array(
		'id'             => 'wccs_fixture_' . $id,
		'integration_id' => 'wc-checkoutsuite/wccs_fixture_' . $id,
		'origin'         => 'custom',
		'type'           => $type,
		'label'          => 'Fixture ' . $id,
		'section'        => 'wccs_fixture',
		'enabled'        => true,
		'required'       => false,
		'position'       => $order,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'conditions'     => array(),
		'storage'        => array(
			'scope'       => 'order',
			'sensitivity' => 'personal',
		),
		'visibility'     => array( 'admin_order' => true ),
	);
}

/**
 * The fields the fixture publishes.
 *
 * Two, and the pair is the point. The Blocks checkout draws a `text` field
 * through WooCommerce's own additional-fields API, so a fixture with only that
 * one field proves the native half and gives this plugin nothing to render — and
 * an observation that looked for this plugin's own region would then be measuring
 * the absence it configured. The `textarea` is a type the platform has no field
 * for, so it is the one this plugin's bundle draws, which is what makes the
 * region observable at all.
 *
 * Both sit in a section of their own, so the fixture never claims a section or an
 * identifier WooCommerce owns.
 *
 * @return array<int, array<string, mixed>>
 */
function wccs_fixture_fields(): array {
	return array(
		wccs_fixture_definition( 'note', 'text', 30 ),
		wccs_fixture_definition( 'message', 'textarea', 31 ),
	);
}

/**
 * The section the fixture field belongs to.
 *
 * @return array<string, mixed>
 */
function wccs_fixture_section(): array {
	return array(
		'id'          => 'wccs_fixture',
		'title'       => 'Fixture',
		'description' => '',
		'position'    => 10,
		'location'    => 'billing',
	);
}

if ( 'clear' === $wccs_fixture_action ) {
	foreach ( wccs_fixture_options() as $wccs_fixture_option ) {
		delete_option( $wccs_fixture_option );
	}

	wccs_fixture_say( 'cleared=' . implode( ',', wccs_fixture_options() ) );
} elseif ( 'seed' === $wccs_fixture_action ) {
	$wccs_fixture_repository = new SchemaRepository(
		Registries::instance()->definition_validator(),
		new CoreFieldGuard()
	);

	// From a known state, so the fixture does not depend on what a previous run
	// left behind. The options this fixture owns are exactly the ones it clears.
	foreach ( wccs_fixture_options() as $wccs_fixture_option ) {
		delete_option( $wccs_fixture_option );
	}

	$wccs_fixture_document = SchemaDocument::from_array(
		array(
			'revision'       => 1,
			'schema_version' => SchemaDocument::SCHEMA_VERSION,
			'updated_at'     => gmdate( 'c' ),
			'updated_by'     => 1,
			'fields'         => wccs_fixture_fields(),
			'sections'       => array( wccs_fixture_section() ),
			'settings'       => array(),
		)
	);

	$wccs_fixture_write = $wccs_fixture_repository->write(
		SchemaRepository::SLOT_DRAFT,
		$wccs_fixture_document,
		null
	);

	wccs_fixture_say( 'draft=' . $wccs_fixture_write->status() );

	if ( ! $wccs_fixture_write->is_ok() ) {
		foreach ( $wccs_fixture_write->errors() as $wccs_fixture_error ) {
			wccs_fixture_say( 'draft_error=' . wp_json_encode( $wccs_fixture_error ) );
		}

		exit( 1 );
	}

	$wccs_fixture_publish = $wccs_fixture_repository->publish(
		$wccs_fixture_repository->read( SchemaRepository::SLOT_DRAFT ),
		null,
		1
	);

	wccs_fixture_say( 'publish=' . $wccs_fixture_publish->status() . ' revision=' . $wccs_fixture_publish->revision() );

	if ( ! $wccs_fixture_publish->is_ok() ) {
		foreach ( $wccs_fixture_publish->errors() as $wccs_fixture_error ) {
			wccs_fixture_say( 'publish_error=' . wp_json_encode( $wccs_fixture_error ) );
		}

		exit( 1 );
	}

	CheckoutSettings::set_enabled( true );
}

if ( 'clear' !== $wccs_fixture_action ) {
	$wccs_fixture_read = new SchemaRepository(
		Registries::instance()->definition_validator(),
		new CoreFieldGuard()
	);

	$wccs_fixture_published = $wccs_fixture_read->read( SchemaRepository::SLOT_PUBLISHED );

	wccs_fixture_say( 'read_revision=' . $wccs_fixture_published->revision() );
	wccs_fixture_say( 'read_fields=' . count( $wccs_fixture_published->fields() ) );
	wccs_fixture_say( 'read_sections=' . count( $wccs_fixture_published->sections() ) );
	wccs_fixture_say(
		'controlled_fields=' . count( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::fields()['fields'] )
	);
	wccs_fixture_say(
		'controlled_report=' . wp_json_encode( \WCCheckoutSuite\Checkout\Blocks\BlocksRenderer::fields()['report'] )
	);
	wccs_fixture_say(
		'classic_renderable=' . (
			\WCCheckoutSuite\Checkout\Classic\ClassicAssets::has_renderable_fields() ? '1' : '0'
		)
	);
	wccs_fixture_say( 'read_status=' . wp_json_encode( $wccs_fixture_read->read_status( SchemaRepository::SLOT_PUBLISHED ) ) );
	wccs_fixture_say( 'opt_in=' . ( CheckoutSettings::enabled() ? 'yes' : 'no' ) );
	wccs_fixture_say(
		'presents=' . (
			CheckoutSettings::presents( CheckoutSettings::offered_gateways() )
				? 'custom'
				: 'store'
		)
	);
	wccs_fixture_say( 'checkout_page=' . wc_get_page_id( 'checkout' ) );
	wccs_fixture_say( 'block_theme=' . ( wp_is_block_theme() ? '1' : '0' ) );
	wccs_fixture_say( 'active_theme=' . wp_get_theme()->get( 'Name' ) );
	wccs_fixture_say(
		'page_has_checkout_block=' . (
			has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ) ? '1' : '0'
		)
	);

	if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) ) {
		wccs_fixture_say(
			'wc_is_checkout_block_default=' . (
				\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default() ? '1' : '0'
			)
		);
	} else {
		wccs_fixture_say( 'wc_is_checkout_block_default=class_absent' );
	}
}
