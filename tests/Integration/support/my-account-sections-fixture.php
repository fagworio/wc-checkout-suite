<?php
/**
 * Provisions a reversible My Account section browser journey.
 *
 * @package WCCheckoutSuite
 */

use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

const WCCS_ACCOUNT_FLOW_STATE = '/tmp/wccs-account-sections-flow.json';

/**
 * Reads an option while retaining whether it was absent.
 *
 * @param string $key Option key.
 * @return array{exists: bool, value: mixed} Saved state.
 */
function wccs_account_flow_backup( string $key ): array {
	$missing = '__WCCS_ACCOUNT_FLOW_MISSING__';
	$value   = get_option( $key, $missing );

	return array( 'exists' => $missing !== $value, 'value' => $missing === $value ? null : $value );
}

/**
 * Restores one fixture option.
 *
 * @param string                       $key Option key.
 * @param array{exists: bool, value: mixed} $backup Saved state.
 * @return void
 */
function wccs_account_flow_restore( string $key, array $backup ): void {
	if ( $backup['exists'] ) {
		update_option( $key, $backup['value'], false );
		return;
	}

	delete_option( $key );
}

/**
 * Returns the customer-owned field document used by this proof.
 *
 * @return SchemaDocument Account document.
 */
function wccs_account_flow_document(): SchemaDocument {
	// A section offered in the customer profile area is the account page; the link is
	// what puts this field on it, with the title the page shows.
	$destinations = DefinitionVocabulary::default_destinations();

	$destinations['customer_account'] = array(
		'enabled'  => true,
		'section'  => 'wccs_e2e_profile',
		'title'    => 'Nota do perfil E2E',
		'position' => 10,
		'mode'     => 'edit',
	);

	return SchemaDocument::from_array(
		array(
			'revision' => 0,
			'fields'   => array(
				array(
					'id'                  => 'wccs_e2e_profile_note',
					'integration_id'      => 'wc-checkoutsuite/wccs_e2e_profile_note',
					'origin'              => 'custom',
					'type'                => 'text',
					'label'               => 'Nota do perfil E2E',
					'description'         => 'Valor salvo sem criar pedido.',
					'section'             => 'wccs_e2e_profile',
					'enabled'             => true,
					'required'            => true,
					'position'            => 10,
					'layout'              => array( 'desktop' => 12, 'tablet' => 12, 'mobile' => 12 ),
					'settings'            => array(),
					'conditions'          => array(),
					'hidden_value_policy' => 'discard',
					'storage'             => array( 'scope' => 'customer', 'sensitivity' => 'personal' ),
					'destinations'        => $destinations,
					'collection_surface'  => 'my_account',
				),
			),
			'sections' => array(
				array(
					'id'          => 'wccs_e2e_profile',
					'title'       => 'Perfil E2E',
					'description' => 'Dados independentes de pedidos.',
					'position'    => 10,
					'location'    => 'order',
					'areas'       => array( 'customer_account' ),
					'presentation' => array(
						'show_title' => true,
						'account'    => array(
							'slug'       => 'wccs-e2e-profile',
							'menu_label' => 'Perfil E2E',
							'icon'       => 'user',
							'position'   => 2,
							'mode'       => 'edit',
						),
					),
				),
			),
			'settings' => array( 'e2e_account' => true ),
		)
	);
}

$action = isset( $args[0] ) ? (string) $args[0] : 'setup';

if ( 'teardown' === $action ) {
	$state = is_readable( WCCS_ACCOUNT_FLOW_STATE ) ? json_decode( (string) file_get_contents( WCCS_ACCOUNT_FLOW_STATE ), true ) : null;
	if ( ! is_array( $state ) ) {
		exit( 0 );
	}
	foreach ( $state['options'] as $key => $backup ) {
		wccs_account_flow_restore( (string) $key, $backup );
	}
	wp_delete_user( (int) $state['user_id'] );
	@unlink( WCCS_ACCOUNT_FLOW_STATE );
	echo "WCCS_ACCOUNT_FLOW_TEARDOWN=1\n";
	exit( 0 );
}

if ( 'verify' === $action ) {
	$state    = json_decode( (string) file_get_contents( WCCS_ACCOUNT_FLOW_STATE ), true );
	$expected = isset( $args[1] ) ? (string) $args[1] : '';
	$raw      = (string) get_user_meta( (int) $state['user_id'], '_wccs_customer_fields', true );
	$values   = json_decode( $raw, true );
	echo 'WCCS_ACCOUNT_FLOW_VERIFY=' . wp_json_encode(
		array(
			'ok'       => is_array( $values ) && $expected === ( $values['wccs_e2e_profile_note'] ?? '' ),
			'expected' => $expected,
			'stored'   => is_array( $values ) ? (string) ( $values['wccs_e2e_profile_note'] ?? '' ) : null,
			'raw'      => $raw,
		)
	) . "\n";
	exit( 0 );
}

$username = 'wccs_account_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 100, 999 );
$password = wp_generate_password( 20, true, true );
$user_id  = wp_create_user( $username, $password, $username . '@example.test' );
$admin    = get_users( array( 'capability' => 'manage_woocommerce', 'number' => 1 ) );
$admin_id = ! empty( $admin ) ? (int) $admin[0]->ID : get_current_user_id();
$keys     = array(
	SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ),
	SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ),
	'wccs_schema_revisions',
	'wccs_account_endpoint_signature',
);
$options  = array();
foreach ( $keys as $key ) {
	$options[ $key ] = wccs_account_flow_backup( $key );
}
file_put_contents( WCCS_ACCOUNT_FLOW_STATE, wp_json_encode( array( 'user_id' => $user_id, 'options' => $options ) ) );

$repository = new SchemaRepository( Registries::instance()->definition_validator(), new CoreFieldGuard() );
$document   = wccs_account_flow_document();
$write      = $repository->write( SchemaRepository::SLOT_DRAFT, $document, $repository->read( SchemaRepository::SLOT_DRAFT )->revision() );
$publish    = $write->is_ok() ? $repository->publish( $repository->read( SchemaRepository::SLOT_DRAFT ), $repository->read( SchemaRepository::SLOT_DRAFT )->revision(), $admin_id ) : $write;
if ( ! $publish->is_ok() ) {
	echo 'WCCS_ACCOUNT_FLOW_ERROR=' . wp_json_encode( $publish->errors() ) . "\n";
	exit( 1 );
}

echo 'WCCS_ACCOUNT_FLOW=' . wp_json_encode(
	array(
		'username'     => $username,
		'password'     => $password,
		'endpoint_url' => wc_get_account_endpoint_url( 'wccs-e2e-profile' ),
		'checkout_url' => wc_get_checkout_url(),
	)
) . "\n";
