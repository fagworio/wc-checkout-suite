<?php
/**
 * Seeds a small, realistic draft through the repository the REST routes use.
 *
 * The design comparison needs the real screens with real fields on them. The
 * integration sweep ends by deleting the draft it established, so this script puts a
 * merchant-like document back: three complementary fields in the billing section,
 * one with a mask, one with a required mark, one with a width.
 *
 * It writes through SchemaRepository::write(), which is the same choke point the
 * routes use, so the document it leaves behind is a document the plugin accepted —
 * not an option written by hand.
 *
 * Usage: wp eval-file tests/Integration/support/seed-design-draft.php
 *
 * @package WC_CheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The same two collaborators the REST controller builds its repository with, so the
// document goes through the same validation and the same core-field guard.
$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_fields = array(
	array(
		'id'             => 'contato_telefone',
		'integration_id' => 'wc-checkoutsuite/contato_telefone',
		'origin'         => 'custom',
		'type'           => 'tel',
		'label'          => 'Telefone',
		'description'    => 'Usamos para avisar sobre a entrega.',
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => true,
		'position'       => 10,
		'layout'         => array(
			'desktop' => 6,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'mask'           => array(
			'key'     => 'br.phone.mobile',
			'version' => 1,
		),
	),
	array(
		'id'             => 'documento_fiscal',
		'integration_id' => 'wc-checkoutsuite/documento_fiscal',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'CPF ou CNPJ',
		'description'    => '',
		'section'        => 'billing',
		'enabled'        => true,
		'required'       => true,
		'position'       => 20,
		'layout'         => array(
			'desktop' => 6,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'mask'           => array(
			'key'     => 'br.cpf',
			'version' => 1,
		),
	),
	array(
		'id'             => 'instrucoes_entrega',
		'integration_id' => 'wc-checkoutsuite/instrucoes_entrega',
		'origin'         => 'custom',
		'type'           => 'textarea',
		'label'          => 'Instruções de entrega',
		'description'    => 'Portaria, horário ou ponto de referência.',
		'section'        => 'order',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'mask'           => null,
	),
);

$wccs_document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
	array(
		'revision' => 0,
		'fields'   => $wccs_fields,
		'sections' => array(),
		'settings' => array(),
	)
);

$wccs_result = $wccs_repository->write( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT, $wccs_document, null );

echo 'write ok=' . ( $wccs_result->is_ok() ? 'yes' : 'no' ) . ' revision=' . $wccs_result->revision() . PHP_EOL;
echo 'errors=' . wp_json_encode( $wccs_result->errors() ) . PHP_EOL;
