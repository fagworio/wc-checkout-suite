<?php
/**
 * Provisions and tears down a complete WooCommerce checkout flow.
 *
 * This is an executable fixture, not a PHPUnit proof. It temporarily makes the
 * local store usable for a real browser purchase, writes a broad published
 * schema through SchemaRepository, and restores the store afterwards.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/full-commerce-flow-fixture.php setup
 *   wp eval-file tests/Integration/support/full-commerce-flow-fixture.php teardown [keep]
 *
 * @package WCCheckoutSuite
 */

use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Domain\Settings\CheckoutSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

const WCCS_FULL_FLOW_STATE = '/tmp/wccs-full-commerce-flow.json';

function wccs_full_flow_destinations( array $enabled ): array {
	$destinations = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations();

	foreach ( $enabled as $key => $entry ) {
		$destinations[ $key ] = is_array( $entry ) ? array_merge( array( 'enabled' => true ), $entry ) : array( 'enabled' => true );
	}

	return $destinations;
}

function wccs_full_flow_field( string $id, string $type, int $position, array $extra = array() ): array {
	$field = array(
		'id'             => 'wccs_e2e_' . $id,
		'integration_id' => 'wc-checkoutsuite/wccs_e2e_' . $id,
		'origin'         => 'custom',
		'type'           => $type,
		'label'          => 'E2E ' . ucwords( str_replace( '_', ' ', $id ) ),
		'description'    => 'Campo criado pelo fluxo completo automatizado.',
		'section'        => 'wccs_e2e_order',
		'enabled'        => true,
		'required'       => false,
		'position'       => $position,
		'layout'         => array( 'desktop' => 12, 'tablet' => 12, 'mobile' => 12 ),
		'settings'       => array(),
		'conditions'     => array(),
		'hidden_value_policy' => 'discard',
		'storage'        => array( 'scope' => 'order', 'sensitivity' => 'personal' ),
		'destinations'   => wccs_full_flow_destinations(
			array(
				'admin_order'    => array( 'section' => 'wccs_e2e_admin', 'title' => 'E2E ' . $id ),
				'customer_order' => array( 'section' => 'wccs_e2e_customer', 'title' => 'E2E ' . $id ),
				'order_received' => array( 'section' => 'wccs_e2e_received', 'title' => 'E2E ' . $id ),
				'customer_email' => array( 'section' => 'wccs_e2e_mail', 'title' => 'E2E ' . $id ),
				'admin_email'    => array( 'section' => 'wccs_e2e_mail', 'title' => 'E2E ' . $id ),
			)
		),
	);

	return array_replace_recursive( $field, $extra );
}

function wccs_full_flow_sections(): array {
	return array(
		array( 'id' => 'wccs_e2e_order', 'title' => 'E2E Checkout Fields', 'position' => 10, 'location' => 'order', 'areas' => array( 'checkout' ) ),
		array( 'id' => 'wccs_e2e_admin', 'title' => 'E2E Admin Order', 'position' => 20, 'location' => 'order', 'areas' => array( 'admin_order' ) ),
		array( 'id' => 'wccs_e2e_customer', 'title' => 'E2E Customer Order', 'position' => 30, 'location' => 'order', 'areas' => array( 'customer_order' ) ),
		array( 'id' => 'wccs_e2e_received', 'title' => 'E2E Order Received', 'position' => 40, 'location' => 'order', 'areas' => array( 'order_received' ) ),
		array( 'id' => 'wccs_e2e_mail', 'title' => 'E2E Emails', 'position' => 50, 'location' => 'order', 'areas' => array( 'customer_email', 'admin_email' ) ),
	);
}

function wccs_full_flow_document(): SchemaDocument {
	$fields = array();
	$types  = array( 'text', 'textarea', 'email', 'tel', 'number', 'url', 'select', 'radio', 'checkbox', 'date', 'time', 'datetime', 'file', 'multiselect', 'checkbox-group', 'hidden' );
	$pos    = 10;

	foreach ( $types as $type ) {
		$id    = str_replace( '-', '_', $type );
		$extra = array();

		if ( in_array( $type, array( 'select', 'radio', 'multiselect', 'checkbox-group' ), true ) ) {
			$extra['settings']['options'] = array(
				array( 'value' => 'alpha', 'label' => 'Alpha' ),
				array( 'value' => 'beta', 'label' => 'Beta' ),
			);
		}

		if ( 'text' === $type ) {
			$extra['label']       = 'E2E CPF normalizado';
			$extra['normalizer']  = 'br.cpf';
			$extra['mask']        = array( 'key' => 'br.cpf', 'version' => 1 );
			$extra['validators']  = array( array( 'key' => 'br.cpf' ) );
			$extra['approval']    = array( 'require_review' => true, 'area' => 'admin_order', 'section' => 'wccs_e2e_admin', 'status' => 'Pendente de aprovação', 'allow_correction' => true, 'allow_resubmit' => false, 'show_status' => true );
		}


		if ( 'file' === $type ) {
			$extra['settings'] = array( 'maxFiles' => 1, 'allowedExtensions' => array( 'txt', 'pdf' ) );
		}

		$fields[] = wccs_full_flow_field( $id, $type, $pos, $extra );
		$pos += 10;
	}

	$fields[] = wccs_full_flow_field( 'conditional', 'text', $pos, array(
		'label'      => 'E2E Campo condicional',
		'conditions' => array( 'visible' => array( 'source' => 'field', 'field' => 'wccs_e2e_select', 'operator' => 'equals', 'value' => 'beta' ) ),
	) );
	$fields[] = wccs_full_flow_field( 'heading', 'heading', $pos + 10, array( 'label' => 'E2E Layout heading', 'destinations' => wccs_full_flow_destinations( array() ), 'storage' => array( 'scope' => 'none', 'sensitivity' => 'public' ) ) );

	return SchemaDocument::from_array(
		array(
			'revision' => 0,
			'fields'   => $fields,
			'sections' => wccs_full_flow_sections(),
			'settings' => array( 'e2e' => true ),
		)
	);
}

function wccs_full_flow_option_backup( string $key ): array {
	$missing = '__WCCS_MISSING__';
	$value   = get_option( $key, $missing );

	return array( 'exists' => $missing !== $value, 'value' => $missing === $value ? null : $value );
}

function wccs_full_flow_restore_option( string $key, array $backup ): void {
	if ( ! empty( $backup['exists'] ) ) {
		update_option( $key, $backup['value'], false );
	} else {
		delete_option( $key );
	}
}

$action = isset( $args[0] ) ? (string) $args[0] : 'setup';

if ( 'teardown' === $action ) {
	$state = is_readable( WCCS_FULL_FLOW_STATE ) ? json_decode( (string) file_get_contents( WCCS_FULL_FLOW_STATE ), true ) : null;

	if ( ! is_array( $state ) ) {
		echo "No full-flow state found.\n";
	exit( 0 );
	}

	if ( isset( $state['checkout_page'] ) ) {
		wp_update_post( $state['checkout_page'] );
	}

	foreach ( $state['options'] as $key => $backup ) {
		wccs_full_flow_restore_option( $key, $backup );
	}

	$keep = isset( $args[1] ) && 'keep' === (string) $args[1];
	if ( ! $keep ) {
		if ( ! empty( $state['order_id'] ) ) {
			wp_delete_post( (int) $state['order_id'], true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
		if ( ! empty( $state['user_id'] ) ) {
			wp_delete_user( (int) $state['user_id'] );
		}
	}

	@unlink( WCCS_FULL_FLOW_STATE );
	echo 'WCCS_FULL_FLOW_TEARDOWN=' . wp_json_encode( array( 'kept' => $keep ) ) . "\n";
	exit( 0 );
}

$admin = get_users( array( 'capability' => 'manage_woocommerce', 'number' => 1 ) );
$admin_id = ! empty( $admin ) ? (int) $admin[0]->ID : get_current_user_id();
$checkout = wc_get_page_id( 'checkout' );
$checkout_post = get_post( $checkout );
$username = 'wccs_e2e_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 100, 999 );
$password = wp_generate_password( 20, true, true );
$email    = $username . '@example.test';
$user_id  = wp_create_user( $username, $password, $email );
$product  = new WC_Product_Simple();
$product->set_name( 'WCCS E2E Product ' . gmdate( 'Y-m-d H:i:s' ) );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'hidden' );
$product->set_regular_price( '19.90' );
$product->set_price( '19.90' );
$product->set_virtual( true );
$product_id = $product->save();

$option_keys = array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only', 'woocommerce_bacs_settings', CheckoutSettings::OPTION, SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ), SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ), 'wccs_schema_revisions' );
$options = array();
foreach ( $option_keys as $key ) {
	$options[ $key ] = wccs_full_flow_option_backup( $key );
}

$state = array(
	'user_id'       => $user_id,
	'username'      => $username,
	'password'      => $password,
	'email'         => $email,
	'product_id'    => $product_id,
	'product_name'  => $product->get_name(),
	'checkout_page' => array( 'ID' => $checkout, 'post_content' => $checkout_post ? $checkout_post->post_content : '[woocommerce_checkout]', 'post_status' => $checkout_post ? $checkout_post->post_status : 'publish' ),
	'options'       => $options,
);
file_put_contents( WCCS_FULL_FLOW_STATE, wp_json_encode( $state ) );

update_option( 'woocommerce_coming_soon', 'no', false );
update_option( 'woocommerce_store_pages_only', 'no', false );
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Transferência bancária', 'description' => 'E2E payment method', 'instructions' => 'E2E instructions' ), false );
CheckoutSettings::set_enabled( true );
wp_update_post( array( 'ID' => $checkout, 'post_content' => '[woocommerce_checkout]', 'post_status' => 'publish' ) );

$repository = new SchemaRepository( Registries::instance()->definition_validator(), new CoreFieldGuard() );
$draft      = wccs_full_flow_document();
$write      = $repository->write( SchemaRepository::SLOT_DRAFT, $draft, $repository->read( SchemaRepository::SLOT_DRAFT )->revision() );
$publish    = $write->is_ok() ? $repository->publish( $repository->read( SchemaRepository::SLOT_DRAFT ), $repository->read( SchemaRepository::SLOT_DRAFT )->revision(), $admin_id ) : $write;

if ( ! $publish->is_ok() ) {
	echo 'WCCS_FULL_FLOW_ERROR=' . wp_json_encode( array( 'write' => $write->errors(), 'publish' => $publish->errors() ) ) . "\n";
	exit( 1 );
}

$state['schema_revision'] = $repository->read( SchemaRepository::SLOT_PUBLISHED )->revision();
file_put_contents( WCCS_FULL_FLOW_STATE, wp_json_encode( $state ) );

echo 'WCCS_FULL_FLOW=' . wp_json_encode(
	array(
		'user_id'      => $user_id,
		'username'     => $username,
		'password'     => $password,
		'email'        => $email,
		'product_id'   => $product_id,
		'product_name' => $product->get_name(),
		'checkout_url' => wc_get_checkout_url(),
		'schema_revision' => $state['schema_revision'],
		'field_ids'    => array_map( static fn( array $field ): string => (string) $field['id'], $draft->fields() ),
	)
) . "\n";
