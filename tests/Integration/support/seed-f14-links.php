<?php
/**
 * Seeds an F14 draft: links per destination, sections per area, and one approval flow.
 *
 * The user-level observation of F14 needs a document a merchant would plausibly have
 * written, and it needs it in the **draft**, so the screens have work to show and the
 * publication can be driven from the interface rather than from here. It writes through
 * `SchemaRepository::write()`, the same choke point the REST routes use, so what it
 * leaves behind is a document the plugin accepted.
 *
 * The shape is deliberate, and it is shaped by what this store's checkout can render:
 *
 * - This store runs the **Blocks** checkout, and the Blocks adapter renders a field
 *   itself only when its type is native (`text`, `select`, `checkbox`) and its own section
 *   is a declared section whose location maps to a Blocks location. Everything else —
 *   `textarea`, `file`, a masked text — needs the plugin's own block on the checkout page,
 *   which this store's page does not contain (recorded in WCCS-063). The fields the
 *   customer fills are therefore plain text fields in `dados_fiscais` (billing) and the
 *   rest exist to exercise the configuration.
 * - `documento_fiscal` carries the **approval flow**: a store that reviews the fiscal
 *   document by hand is the ordinary case, and a text field can actually be filled here.
 * - `codigo_retirada` is linked to `order_received` **only**, and `documento_fiscal` to
 *   `admin_order` and `customer_order`. That pair is what makes "the page decides the
 *   area" observable: the same order shows one field on the thank-you page and the other
 *   in the account, never both.
 * - `campo_sem_vinculo` is linked nowhere, and `preferencia_perfil` only to
 *   `customer_profile`, which has no surface yet. Both are filled at checkout, so the
 *   negative half of the audit can be observed on a real page.
 * - `arquivo_autorizacao` is a **file** field linked to five destinations with different
 *   actions each, which is the per-destination matrix of section 12 on a real screen. It
 *   cannot be uploaded on this store (its private directory is served over HTTP, recorded
 *   as `UPLOAD-PRIVACY-ENV`), so what it exercises is the configuration and the
 *   permissions.
 * - The sections are offered in the areas they are used from, which is what WCCS-073 made
 *   a rule rather than a convention.
 *
 * Usage: wp eval-file tests/Integration/support/seed-f14-links.php [draft|published]
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All destinations, with the ones named turned on.
 *
 * @param array<string, array<string, mixed>> $on Links to enable.
 * @return array<string, array<string, mixed>>
 */
function wccs_seed_destinations( array $on ): array {
	$destinations = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::default_destinations();

	foreach ( $on as $key => $link ) {
		$destinations[ $key ] = $link;
	}

	return $destinations;
}

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_fields = array(
	array(
		'id'             => 'documento_fiscal',
		'integration_id' => 'wc-checkoutsuite/documento_fiscal',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'CPF ou CNPJ',
		'description'    => 'Usado na nota fiscal.',
		'section'        => 'dados_fiscais',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'layout'         => array(
			'desktop' => 6,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'destinations'   => wccs_seed_destinations(
			array(
				'admin_order'    => array(
					'enabled'  => true,
					'section'  => 'documentos_para_analise',
					'title'    => 'Documento fiscal',
					'position' => 10,
				),
				'customer_order' => array(
					'enabled'  => true,
					'section'  => 'documentos_enviados',
					'title'    => 'Documento enviado',
					'position' => 10,
				),
				'customer_email' => array(
					'enabled'  => true,
					'section'  => 'documentos_do_email',
					'title'    => 'Documento do pedido',
					'position' => 20,
				),
				'admin_email'    => array(
					'enabled'  => true,
					'section'  => 'documentos_do_email',
					'title'    => 'Cópia para a loja',
					'position' => 20,
				),
				'public_api'     => array( 'enabled' => true ),
			)
		),
		'approval'       => array(
			'require_review'   => true,
			'area'             => 'admin_order',
			'section'          => 'documentos_para_analise',
			'status'           => 'Pendente de aprovação',
			'allow_correction' => true,
			'allow_resubmit'   => false,
			'show_status'      => true,
		),
	),
	array(
		'id'             => 'codigo_retirada',
		'integration_id' => 'wc-checkoutsuite/codigo_retirada',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Código de retirada',
		'description'    => 'Só aparece na página de agradecimento.',
		'section'        => 'dados_fiscais',
		'enabled'        => true,
		'required'       => false,
		'position'       => 20,
		'layout'         => array(
			'desktop' => 6,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'destinations'   => wccs_seed_destinations(
			array(
				'order_received' => array(
					'enabled'  => true,
					'section'  => 'documentos_enviados',
					'title'    => 'Código de retirada',
					'position' => 5,
				),
			)
		),
	),
	array(
		'id'             => 'campo_sem_vinculo',
		'integration_id' => 'wc-checkoutsuite/campo_sem_vinculo',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Campo sem vínculo',
		'description'    => 'Coletado e mostrado em lugar nenhum.',
		'section'        => 'dados_fiscais',
		'enabled'        => true,
		'required'       => false,
		'position'       => 30,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'destinations'   => wccs_seed_destinations( array() ),
	),
	array(
		'id'             => 'preferencia_perfil',
		'integration_id' => 'wc-checkoutsuite/preferencia_perfil',
		'origin'         => 'custom',
		'type'           => 'text',
		'label'          => 'Preferência do perfil',
		'description'    => 'Vinculado só ao perfil, que ainda não tem superfície.',
		'section'        => 'dados_fiscais',
		'enabled'        => true,
		'required'       => false,
		'position'       => 40,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'destinations'   => wccs_seed_destinations(
			array(
				'customer_profile' => array(
					'enabled'  => true,
					'section'  => 'preferencias_do_perfil',
					'title'    => 'Como prefere ser contactado',
					'position' => 10,
				),
			)
		),
	),
	array(
		'id'             => 'observacoes_entrega',
		'integration_id' => 'wc-checkoutsuite/observacoes_entrega',
		'origin'         => 'custom',
		'type'           => 'textarea',
		'label'          => 'Instruções de entrega',
		'description'    => 'Portaria, horário ou ponto de referência.',
		'section'        => 'instrucoes_entrega',
		'enabled'        => true,
		'required'       => false,
		'position'       => 10,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(),
		'destinations'   => wccs_seed_destinations(
			array(
				'admin_order'    => array(
					'enabled'  => true,
					'section'  => 'documentos_para_analise',
					'title'    => 'Observações da entrega',
					'position' => 30,
				),
				'customer_order' => array(
					'enabled'  => true,
					'section'  => 'documentos_enviados',
					'title'    => 'Instruções que você deixou',
					'position' => 20,
				),
			)
		),
	),
	array(
		'id'             => 'arquivo_autorizacao',
		'integration_id' => 'wc-checkoutsuite/arquivo_autorizacao',
		'origin'         => 'custom',
		'type'           => 'file',
		'label'          => 'Autorização assinada',
		'description'    => 'PDF ou foto do documento assinado.',
		'section'        => 'instrucoes_entrega',
		'enabled'        => true,
		'required'       => false,
		'position'       => 20,
		'layout'         => array(
			'desktop' => 12,
			'tablet'  => 12,
			'mobile'  => 12,
		),
		'settings'       => array(
			'maxFiles'          => 1,
			'allowedExtensions' => array( 'pdf', 'jpg', 'png' ),
		),
		'destinations'   => wccs_seed_destinations(
			array(
				'admin_order'    => array(
					'enabled'  => true,
					'section'  => 'documentos_para_analise',
					'title'    => 'Autorização assinada',
					'position' => 20,
					'actions'  => array( 'show_metadata', 'view', 'download', 'approve' ),
				),
				'customer_order' => array(
					'enabled'  => true,
					'section'  => 'documentos_enviados',
					'title'    => 'Enviado por você',
					'position' => 30,
					'actions'  => array( 'show_metadata', 'view' ),
				),
				'order_received' => array(
					'enabled'  => true,
					'section'  => 'documentos_enviados',
					'title'    => 'Enviado agora',
					'position' => 30,
					'actions'  => array( 'show_metadata', 'view', 'download' ),
				),
				'customer_email' => array(
					'enabled'  => true,
					'section'  => 'documentos_do_email',
					'title'    => 'Documento do pedido',
					'position' => 10,
					'actions'  => array( 'show_metadata' ),
				),
				'admin_email'    => array(
					'enabled'  => true,
					'section'  => 'documentos_do_email',
					'title'    => 'Documento do pedido',
					'position' => 10,
					'actions'  => array( 'show_metadata', 'download' ),
				),
			)
		),
	),
);

$wccs_sections = array(
	array(
		'id'       => 'dados_fiscais',
		'title'    => 'Dados fiscais',
		'position' => 10,
		'location' => 'billing',
		'areas'    => array( 'checkout' ),
	),
	array(
		'id'       => 'instrucoes_entrega',
		'title'    => 'Instruções de entrega',
		'position' => 20,
		'location' => 'order',
		'areas'    => array( 'checkout' ),
	),
	array(
		'id'       => 'documentos_para_analise',
		'title'    => 'Documentos para análise',
		'position' => 30,
		'location' => 'order',
		'areas'    => array( 'admin_order' ),
	),
	array(
		'id'       => 'documentos_enviados',
		'title'    => 'Documentos enviados',
		'position' => 40,
		'location' => 'order',
		'areas'    => array( 'customer_order', 'order_received' ),
	),
	array(
		'id'       => 'documentos_do_email',
		'title'    => 'Documentos do pedido',
		'position' => 50,
		'location' => 'order',
		'areas'    => array( 'customer_email', 'admin_email' ),
	),
	array(
		'id'       => 'preferencias_do_perfil',
		'title'    => 'Preferências',
		'position' => 60,
		'location' => 'order',
		'areas'    => array( 'customer_profile' ),
	),
);

$wccs_slot = isset( $args[0] ) && 'published' === $args[0]
	? \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED
	: \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT;

$wccs_document = \WCCheckoutSuite\Domain\Schema\SchemaDocument::from_array(
	array(
		'revision' => 0,
		'fields'   => $wccs_fields,
		'sections' => $wccs_sections,
		'settings' => array(),
	)
);

$wccs_result = $wccs_repository->write(
	$wccs_slot,
	$wccs_document,
	$wccs_repository->read( $wccs_slot )->revision()
);

echo 'seed slot=' . $wccs_slot . ' ok=' . ( $wccs_result->is_ok() ? 'yes' : 'no' ) . ' revision=' . $wccs_result->revision() . PHP_EOL;
echo 'errors=' . wp_json_encode( $wccs_result->errors() ) . PHP_EOL;
