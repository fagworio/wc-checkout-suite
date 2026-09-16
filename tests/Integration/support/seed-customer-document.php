<?php
/**
 * Seeds the customer account page a browser observation needs.
 *
 * WCCS-077's account page is rendered from the published document, and a document with a file
 * field offered to `customer_account` is not what a normal store has: this script writes one,
 * creates the customer the page belongs to. The application places the default private store
 * outside the document root, so the real Devilbox privacy probe enables the upload path.
 *
 * Usage:
 *   wp eval-file tests/Integration/support/seed-customer-document.php setup
 *   wp eval-file tests/Integration/support/seed-customer-document.php teardown
 *
 * `setup` prints `login=…` and `user_id=…`; `teardown` removes the document, the observation,
 * the customer and their uploads, so a run leaves nothing behind.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This helper must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;
use WCCheckoutSuite\Domain\Uploads\UploadsEnvironment;

$wccs_seed_action = isset( $args[0] ) ? (string) $args[0] : 'setup';
$wccs_seed_login  = 'wccs_fase4_cliente';

$wccs_seed_repository = new SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

/**
 * The document the observation works against.
 *
 * @return array<string, mixed>
 */
$wccs_seed_document = static function (): array {
	return array(
		'revision' => 1,
		'fields'   => array(
			array(
				'id'             => 'documento_do_cliente',
				'integration_id' => 'wc-checkoutsuite/documento_do_cliente',
				'origin'         => 'custom',
				'type'           => 'file',
				'label'          => 'Contrato social',
				'section'        => 'documentos_da_conta',
				'enabled'        => true,
				'required'       => false,
				'position'       => 10,
				'storage'        => array(
					'scope'       => 'customer',
					'sensitivity' => 'sensitive',
				),
				'settings'       => array(
					'maxFiles'          => 1,
					'allowedExtensions' => array( 'pdf' ),
				),
				'destinations'   => array(
					'customer_account' => array(
						'enabled' => true,
						'section' => 'documentos_da_conta',
						'title'   => 'Seu contrato',
						'mode'    => 'edit',
						'actions' => array( 'show_metadata', 'view', 'download', 'resubmit' ),
					),
				),
			),
			array(
				'id'             => 'nome_preferido',
				'integration_id' => 'wc-checkoutsuite/nome_preferido',
				'origin'         => 'custom',
				'type'           => 'text',
				'label'          => 'Como quer ser chamado',
				'section'        => 'documentos_da_conta',
				'enabled'        => true,
				'required'       => false,
				'position'       => 20,
				'storage'        => array(
					'scope'       => 'customer',
					'sensitivity' => 'personal',
				),
				'settings'       => array(),
				'destinations'   => array(
					'customer_account' => array(
						'enabled' => true,
						'section' => 'documentos_da_conta',
						'mode'    => 'edit',
					),
				),
			),
		),
		'sections' => array(
			array(
				'id'           => 'documentos_da_conta',
				'title'        => 'Documentos',
				'description'  => '',
				'position'     => 10,
				'location'     => 'order',
				'areas'        => array( 'customer_account' ),
				'presentation' => array(
					'account' => array(
						'slug'       => 'documentos-da-conta',
						'menu_label' => 'Documentos',
						'icon'       => 'file',
						'position'   => 5,
						'mode'       => 'edit',
					),
				),
			),
		),
		'settings' => array(),
	);
};

if ( 'teardown' === $wccs_seed_action ) {
	$wccs_seed_user = get_user_by( 'login', $wccs_seed_login );

	if ( $wccs_seed_user ) {
		$wccs_seed_service = new \WCCheckoutSuite\Domain\Uploads\UploadService();
		$wccs_seed_record  = $wccs_seed_service->for_customer_field( (int) $wccs_seed_user->ID, 'documento_do_cliente' );

		if ( is_array( $wccs_seed_record ) ) {
			$wccs_seed_service->remove_for_customer( (string) $wccs_seed_record['token'], (int) $wccs_seed_user->ID );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $wccs_seed_user->ID );
	}

	delete_option( SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ) );
	delete_option( SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ) );
	delete_option( 'wccs_schema_revisions' );
	delete_option( 'wccs_account_endpoint_signature' );
	delete_option( UploadsEnvironment::STATE_OPTION );

	echo "teardown ok=yes\n";

	exit( 0 );
}

$wccs_seed_user = get_user_by( 'login', $wccs_seed_login );

if ( ! $wccs_seed_user ) {
	$wccs_seed_id = wp_insert_user(
		array(
			'user_login' => $wccs_seed_login,
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => $wccs_seed_login . '@example.invalid',
			'role'       => 'customer',
			'first_name' => 'Cliente',
		)
	);

	if ( is_wp_error( $wccs_seed_id ) ) {
		fwrite( STDERR, 'could not create the customer: ' . $wccs_seed_id->get_error_message() . "\n" );
		exit( 1 );
	}

	$wccs_seed_user = get_user_by( 'id', (int) $wccs_seed_id );
}

$wccs_seed_result = $wccs_seed_repository->write(
	SchemaRepository::SLOT_PUBLISHED,
	SchemaDocument::from_array( $wccs_seed_document() ),
	$wccs_seed_repository->read( SchemaRepository::SLOT_PUBLISHED )->revision()
);

echo 'document ok=' . ( $wccs_seed_result->is_ok() ? 'yes' : 'no' ) . "\n";

$wccs_seed_state = UploadsEnvironment::state( true );

\WCCheckoutSuite\Account\MyAccountSections::register_endpoints();

echo 'observation=' . ( $wccs_seed_state['protected'] ? 'protected' : 'unprotected' ) . ' status=' . (int) $wccs_seed_state['status'] . "\n";
echo 'login=' . $wccs_seed_user->user_login . "\n";
echo 'user_id=' . (int) $wccs_seed_user->ID . "\n";
echo 'endpoint=' . home_url( '/minha-conta/documentos-da-conta/' ) . "\n";
