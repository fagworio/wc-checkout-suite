<?php
/**
 * Proof: a section offered in the profile area is a real My Account page.
 *
 * What this harness proves, in order:
 *
 * 1. **The page exists.** A section whose areas include `customer_profile` and whose
 *    presentation names an account slug registers a WooCommerce account endpoint, an
 *    account menu entry under the configured label and at the configured position, and
 *    renders through the endpoint action WooCommerce itself calls.
 * 2. **The page shows the links, not the document.** Each field appears under the title
 *    its `customer_profile` link configured, in the link's order, and a field whose link
 *    belongs to another area — or to nothing — does not appear at all.
 * 3. **The customer's value round-trips.** A submitted form saves to the customer and
 *    comes back on the next render; an omitted value is preserved; a section rendered in
 *    `view` mode is read-only and cannot be written to.
 * 4. **The model refuses configuration that would save nowhere.** A field linked to an
 *    account section that does not store on the customer is rejected with
 *    `account_section_requires_customer_storage`.
 *
 * The harness owns every option and user it touches and restores them before it exits.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use WCCheckoutSuite\Account\MyAccountSections;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

$GLOBALS['wccs_acct'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'notes' => array(),
);

/**
 * Prints one line of output.
 *
 * @param string $line Line.
 * @return void
 */
function wccs_acct_out( string $line ): void {
	fwrite( STDOUT, $line . "\n" );
}

/**
 * Records one assertion.
 *
 * @param string $label    What is being proved.
 * @param bool   $passed   Whether it held.
 * @param string $evidence Short evidence.
 * @return void
 */
function wccs_acct_check( string $label, bool $passed, string $evidence = '' ): void {
	if ( $passed ) {
		++$GLOBALS['wccs_acct']['pass'];
		wccs_acct_out( '  PASS  ' . $label . ( '' !== $evidence ? ' [' . $evidence . ']' : '' ) );
		return;
	}

	++$GLOBALS['wccs_acct']['fail'];
	wccs_acct_out( '  FAIL  ' . $label . ( '' !== $evidence ? ' [' . $evidence . ']' : '' ) );
}

/**
 * Records a note that is not an assertion.
 *
 * @param string $label    Note title.
 * @param string $evidence Explanation.
 * @return void
 */
function wccs_acct_note( string $label, string $evidence ): void {
	$GLOBALS['wccs_acct']['notes'][] = $label;
	wccs_acct_out( '  NOTE  ' . $label . ' — ' . $evidence );
}

/**
 * Builds the destination map of one field.
 *
 * @param array<string, array<string, mixed>> $links Enabled links.
 * @return array<string, array<string, mixed>>
 */
function wccs_acct_destinations( array $links ): array {
	$destinations = DefinitionVocabulary::default_destinations();

	foreach ( $links as $key => $link ) {
		$destinations[ $key ] = $link;
	}

	return $destinations;
}

/**
 * One field of the proof document.
 *
 * @param string                             $id       Identifier.
 * @param string                             $label    Label.
 * @param string                             $type     Field type.
 * @param int                                $position Own position.
 * @param array<string, array<string,mixed>> $links    Enabled destination links.
 * @param array<string, mixed>               $extra    Extra definition keys.
 * @return array<string, mixed>
 */
function wccs_acct_field( string $id, string $label, string $type, int $position, array $links, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => $type,
			'label'          => $label,
			'section'        => 'perfil_conta',
			'enabled'        => true,
			'required'       => false,
			'position'       => $position,
			'storage'        => array(
				'scope'       => 'customer',
				'sensitivity' => 'personal',
			),
			'destinations'   => wccs_acct_destinations( $links ),
		),
		$extra
	);
}

/**
 * One section of the proof document.
 *
 * @param string               $id       Identifier.
 * @param string               $title    Title.
 * @param string               $description Description.
 * @param int                  $position Position.
 * @param array<string, mixed> $account  Account presentation, or an empty array.
 * @return array<string, mixed>
 */
function wccs_acct_section( string $id, string $title, string $description, int $position, array $account ): array {
	$section = array(
		'id'          => $id,
		'title'       => $title,
		'description' => $description,
		'position'    => $position,
		'location'    => 'order',
		'areas'       => array( 'customer_profile' ),
	);

	if ( array() !== $account ) {
		$section['presentation'] = array(
			'show_title' => true,
			'account'    => $account,
		);
	}

	return $section;
}

/**
 * The document this harness audits.
 *
 * Two pages: an editable one and a read-only one. Each holds fields that must be shown
 * and fields that must not — a link to another area, a link to no area, and a field that
 * is not renderable in an account form.
 *
 * @param bool $customer_storage Whether the account fields store on the customer.
 * @return array{fields: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}
 */
function wccs_acct_document( bool $customer_storage = true ): array {
	$scope = $customer_storage ? 'customer' : 'order';
	$store = array(
		'storage' => array(
			'scope'       => $scope,
			'sensitivity' => 'personal',
		),
	);

	return array(
		'fields'   => array(
			wccs_acct_field(
				'acct_nome',
				'Nome de tratamento',
				'text',
				10,
				array(
					'customer_profile' => array(
						'enabled'  => true,
						'section'  => 'perfil_conta',
						'title'    => 'Como quer ser chamado',
						'position' => 20,
						'mode'     => 'edit',
					),
				),
				array_merge(
					$store,
					array(
						'required' => true,
					)
				)
			),
			wccs_acct_field(
				'acct_bio',
				'Biografia',
				'textarea',
				20,
				array(
					'customer_profile' => array(
						'enabled'  => true,
						'section'  => 'perfil_conta',
						'title'    => 'Notas da conta',
						'position' => 10,
						'mode'     => 'edit',
					),
				),
				$store
			),
			wccs_acct_field(
				'acct_plano',
				'Plano',
				'text',
				30,
				array(
					'customer_profile' => array(
						'enabled'  => true,
						'section'  => 'historico_conta',
						'title'    => 'Plano atual',
						'position' => 10,
						'mode'     => 'view',
					),
				),
				$store
			),
			wccs_acct_field(
				'acct_pedido',
				'Nota do pedido',
				'text',
				40,
				array(
					'customer_order' => array(
						'enabled'  => true,
						'section'  => 'documentos',
						'title'    => 'Nota do pedido',
						'position' => 10,
					),
				),
				$store
			),
			wccs_acct_field(
				'acct_sem_vinculo',
				'Sem vínculo',
				'text',
				50,
				array(),
				$store
			),
		),
		'sections' => array(
			wccs_acct_section(
				'perfil_conta',
				'Minha conta',
				'Dados que ficam guardados consigo.',
				10,
				array(
					'slug'       => 'preferencias',
					'menu_label' => 'Preferências',
					'icon'       => 'user',
					'mode'       => 'edit',
					'position'   => 0,
				)
			),
			wccs_acct_section(
				'historico_conta',
				'Histórico',
				'O que a loja guardou sobre a sua conta.',
				20,
				array(
					'slug'       => 'historico',
					'menu_label' => 'Histórico',
					'icon'       => 'file',
					'mode'       => 'view',
					'position'   => 1,
				)
			),
			array(
				'id'       => 'documentos',
				'title'    => 'Documentos do pedido',
				'position' => 30,
				'location' => 'order',
				'areas'    => array( 'customer_order' ),
			),
		),
	);
}

/**
 * Prints one value's shape for an evidence string.
 *
 * @param mixed $value Value.
 * @return string
 */
function wccs_acct_show( mixed $value ): string {
	return is_scalar( $value ) ? (string) $value : gettype( $value );
}

/**
 * Reads an option while retaining whether it was absent.
 *
 * @param string $key Option key.
 * @return array{exists: bool, value: mixed}
 */
function wccs_acct_backup( string $key ): array {
	$missing = '__WCCS_ACCT_MISSING__';
	$value   = get_option( $key, $missing );

	return array(
		'exists' => $missing !== $value,
		'value'  => $missing === $value ? null : $value,
	);
}

/**
 * Restores one backed-up option.
 *
 * @param string                            $key    Option key.
 * @param array{exists: bool, value: mixed} $backup Saved state.
 * @return void
 */
function wccs_acct_restore( string $key, array $backup ): void {
	if ( $backup['exists'] ) {
		// The two-argument form leaves the option's autoload flag exactly as it was.
		update_option( $key, $backup['value'] );
		return;
	}

	delete_option( $key );
}

/**
 * Renders one account endpoint the way WooCommerce renders it.
 *
 * @param string $slug Endpoint slug.
 * @return string Rendered markup.
 */
function wccs_acct_render( string $slug ): string {
	set_query_var( $slug, '1' );

	ob_start();
	MyAccountSections::render();
	$rendered = (string) ob_get_clean();

	set_query_var( $slug, null );

	return $rendered;
}

/**
 * Submits one account form and returns what the page printed.
 *
 * @param string               $slug    Endpoint slug.
 * @param string               $section Section identifier.
 * @param array<string, mixed> $values  Posted field values.
 * @param string               $nonce   Submitted nonce, or an empty string to omit it.
 * @return string Rendered markup.
 */
function wccs_acct_submit( string $slug, string $section, array $values, string $nonce ): string {
	$_POST = array(
		'wccs_account_section' => $section,
		'wccs_account_fields'  => $values,
	);

	if ( '' !== $nonce ) {
		$_POST['_wccs_account_nonce'] = $nonce;
	}

	$rendered = wccs_acct_render( $slug );

	$_POST = array();

	return $rendered;
}

$wccs_acct_repository = new SchemaRepository(
	Registries::instance()->definition_validator(),
	new CoreFieldGuard()
);

/**
 * Writes and publishes one document.
 *
 * @param SchemaRepository                                              $repository Repository.
 * @param array{fields: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>} $document Document.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_acct_publish( SchemaRepository $repository, array $document ) {
	$draft = $repository->read( SchemaRepository::SLOT_DRAFT );

	$write = $repository->write(
		SchemaRepository::SLOT_DRAFT,
		SchemaDocument::from_array(
			array(
				'revision' => $draft->revision(),
				'fields'   => $document['fields'],
				'sections' => $document['sections'],
				'settings' => array(),
			)
		),
		$draft->revision()
	);

	if ( ! $write->is_ok() ) {
		return $write;
	}

	$draft = $repository->read( SchemaRepository::SLOT_DRAFT );

	return $repository->publish( $draft, $draft->revision(), get_current_user_id() );
}

// ---------------------------------------------------------------------------
// Environment.
// ---------------------------------------------------------------------------
wccs_acct_out( '=====================================================================' );
wccs_acct_out( 'ACCOUNT — sections offered in the profile area are real My Account pages' );
wccs_acct_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_acct_out( '=====================================================================' );

require_once ABSPATH . 'wp-admin/includes/user.php';

$wccs_acct_admin       = get_users(
	array(
		'capability' => 'manage_woocommerce',
		'number'     => 1,
	)
);
$wccs_acct_admin_id    = ! empty( $wccs_acct_admin ) ? (int) $wccs_acct_admin[0]->ID : 1;
$wccs_acct_option_keys = array(
	SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ),
	SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ),
	'wccs_schema_revisions',
	'wccs_account_endpoint_signature',
);
$wccs_acct_options     = array();

foreach ( $wccs_acct_option_keys as $wccs_acct_key ) {
	$wccs_acct_options[ $wccs_acct_key ] = wccs_acct_backup( $wccs_acct_key );
}

$wccs_acct_login    = 'wccs_acct_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 100, 999 );
$wccs_acct_user_id  = (int) wp_create_user( $wccs_acct_login, wp_generate_password( 20, true, true ), $wccs_acct_login . '@example.test' );
$wccs_acct_service  = new CustomerFieldsService();
$wccs_acct_leftover = array();

wp_set_current_user( $wccs_acct_admin_id );

// ---------------------------------------------------------------------------
// 1. The page exists.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '1. The section becomes an endpoint, a menu entry and a rendered page' );

$wccs_acct_result = wccs_acct_publish( $wccs_acct_repository, wccs_acct_document() );

wccs_acct_check(
	'The document with account sections is published',
	$wccs_acct_result->is_ok(),
	'status=' . $wccs_acct_result->status()
);

MyAccountSections::register_endpoints();

$wccs_acct_endpoints = array_map(
	static fn( array $endpoint ): string => (string) ( $endpoint[1] ?? '' ),
	(array) ( $GLOBALS['wp_rewrite']->endpoints ?? array() )
);

wccs_acct_check(
	'Both configured slugs became rewrite endpoints',
	in_array( 'preferencias', $wccs_acct_endpoints, true ) && in_array( 'historico', $wccs_acct_endpoints, true ),
	'endpoints=' . implode( ', ', $wccs_acct_endpoints )
);

wccs_acct_check(
	'WooCommerce is given a renderer for each account endpoint action',
	has_action( 'woocommerce_account_preferencias_endpoint' ) && has_action( 'woocommerce_account_historico_endpoint' ),
	'actions registered on init'
);

$wccs_acct_menu = MyAccountSections::menu_items(
	array(
		'orders'          => 'Pedidos',
		'customer-logout' => 'Sair',
	)
);

wccs_acct_check(
	'The account menu carries both sections, in the configured order and under the configured labels',
	array( 'preferencias', 'historico', 'orders', 'customer-logout' ) === array_keys( $wccs_acct_menu )
		&& 'Preferências' === ( $wccs_acct_menu['preferencias'] ?? '' )
		&& 'Histórico' === ( $wccs_acct_menu['historico'] ?? '' ),
	'menu=' . implode( ', ', array_keys( $wccs_acct_menu ) )
);

$wccs_acct_signature = (string) get_option( 'wccs_account_endpoint_signature', '' );
$wccs_acct_expected  = md5( (string) wp_json_encode( array( 'preferencias', 'historico' ) ) );

wccs_acct_check(
	'The rewrite signature remembers exactly which endpoints exist',
	$wccs_acct_expected === $wccs_acct_signature,
	'signature=' . ( '' === $wccs_acct_signature ? '(empty)' : 'matches' )
);

// ---------------------------------------------------------------------------
// 2. The page shows the links, not the document.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '2. The page shows what its links configured' );

wp_set_current_user( $wccs_acct_user_id );

$wccs_acct_page = wccs_acct_render( 'preferencias' );

wccs_acct_check(
	'The section title and description head the page',
	str_contains( $wccs_acct_page, 'Minha conta' ) && str_contains( $wccs_acct_page, 'Dados que ficam guardados consigo.' ),
	'section title and description'
);

wccs_acct_check(
	'Each field appears under the title its own link configured',
	str_contains( $wccs_acct_page, 'Como quer ser chamado' ) && str_contains( $wccs_acct_page, 'Notas da conta' ),
	'link titles'
);

wccs_acct_check(
	'And not under the label the document gave the field',
	! str_contains( $wccs_acct_page, 'Nome de tratamento' ) && ! str_contains( $wccs_acct_page, 'Biografia' ),
	'the link renames the field for this area'
);

wccs_acct_check(
	'The link order decides the form order, not the field order',
	strpos( $wccs_acct_page, 'Notas da conta' ) < strpos( $wccs_acct_page, 'Como quer ser chamado' ),
	'biografia is second in the document and first in the link'
);

wccs_acct_check(
	'A field linked to another area, or to none, is not on the page',
	! str_contains( $wccs_acct_page, 'Nota do pedido' ) && ! str_contains( $wccs_acct_page, 'Sem vínculo' ),
	'customer_order and unlinked fields stay out'
);

wccs_acct_check(
	'The read-only section is served by its own endpoint, not by this one',
	! str_contains( $wccs_acct_page, 'Plano atual' ),
	'no field of the other account page leaks in'
);

// ---------------------------------------------------------------------------
// 3. The form and the round trip.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '3. The form saves on the customer and comes back' );

wccs_acct_check(
	'The editable section renders a real form with its nonce and its section',
	str_contains( $wccs_acct_page, 'wccs-account-section' )
		&& str_contains( $wccs_acct_page, '_wccs_account_nonce' )
		&& str_contains( $wccs_acct_page, 'wccs_account_section' )
		&& str_contains( $wccs_acct_page, 'Salvar informações' ),
	'form, nonce, section marker and submit'
);

wccs_acct_check(
	'The field the link marked required is required in the form',
	str_contains( $wccs_acct_page, 'validate-required' ),
	'required follows the definition'
);

$wccs_acct_empty = wccs_acct_submit(
	'preferencias',
	'perfil_conta',
	array(
		'acct_nome' => '',
		'acct_bio'  => '',
	),
	wp_create_nonce( 'wccs_account_section_perfil_conta' )
);

wccs_acct_check(
	'An empty required value is refused with a message instead of being saved',
	str_contains( $wccs_acct_empty, 'woocommerce-error' )
		&& array() === $wccs_acct_service->values( $wccs_acct_user_id ),
	'errors rendered, customer untouched'
);

$wccs_acct_saved = wccs_acct_submit(
	'preferencias',
	'perfil_conta',
	array(
		'acct_nome' => 'Ana',
		'acct_bio'  => 'Prefere ser contactada de manhã.',
	),
	wp_create_nonce( 'wccs_account_section_perfil_conta' )
);

$wccs_acct_values = $wccs_acct_service->values( $wccs_acct_user_id );

wccs_acct_check(
	'A valid submission is confirmed and stored on the customer',
	str_contains( $wccs_acct_saved, 'Informações salvas com sucesso.' )
		&& 'Ana' === ( $wccs_acct_values['acct_nome'] ?? '' )
		&& 'Prefere ser contactada de manhã.' === ( $wccs_acct_values['acct_bio'] ?? '' ),
	'bio=' . bin2hex( (string) ( $wccs_acct_values['acct_bio'] ?? '' ) ) . ' want=' . bin2hex( 'Prefere ser contactada de manhã.' )
		. ' nome=' . bin2hex( (string) ( $wccs_acct_values['acct_nome'] ?? '' ) )
		. ' notice=' . ( str_contains( $wccs_acct_saved, 'Informações salvas com sucesso.' ) ? 'sim' : 'não' )
);

wccs_acct_check(
	'And the stored value is what the next render offers',
	str_contains( wccs_acct_render( 'preferencias' ), 'value="Ana"' ),
	'the form is filled from the customer'
);

$wccs_acct_partial = wccs_acct_submit(
	'preferencias',
	'perfil_conta',
	array( 'acct_nome' => 'Ana Maria' ),
	wp_create_nonce( 'wccs_account_section_perfil_conta' )
);

$wccs_acct_after = $wccs_acct_service->values( $wccs_acct_user_id );

wccs_acct_check(
	'A field that did not reach the request is not read as empty and does not erase it',
	str_contains( $wccs_acct_partial, 'Informações salvas com sucesso.' )
		&& 'Ana Maria' === ( $wccs_acct_after['acct_nome'] ?? '' )
		&& 'Prefere ser contactada de manhã.' === ( $wccs_acct_after['acct_bio'] ?? '' ),
	'values=' . (string) wp_json_encode( $wccs_acct_after )
);

$wccs_acct_cleared = wccs_acct_submit(
	'preferencias',
	'perfil_conta',
	array(
		'acct_nome' => 'Ana Maria',
		'acct_bio'  => '',
	),
	wp_create_nonce( 'wccs_account_section_perfil_conta' )
);

wccs_acct_check(
	'An optional field submitted empty is cleared, which is what the browser sends',
	str_contains( $wccs_acct_cleared, 'Informações salvas com sucesso.' )
		&& '' === ( $wccs_acct_service->values( $wccs_acct_user_id )['acct_bio'] ?? null ),
	'values=' . (string) wp_json_encode( $wccs_acct_service->values( $wccs_acct_user_id ) )
);

// Put the biography back, so the merge check below has two values to keep.
$wccs_acct_service->update( $wccs_acct_user_id, array( 'acct_bio' => 'Prefere ser contactada de manhã.' ) );

$wccs_acct_forged = wccs_acct_submit(
	'preferencias',
	'perfil_conta',
	array(
		'acct_nome' => 'Intrusa',
		'acct_bio'  => '',
	),
	'nao-e-um-nonce'
);

wccs_acct_check(
	'A submission without a valid nonce changes nothing',
	str_contains( $wccs_acct_forged, 'woocommerce-error' )
		&& 'Ana Maria' === ( $wccs_acct_service->values( $wccs_acct_user_id )['acct_nome'] ?? '' ),
	'the expired-session message, and the stored value intact'
);

// The read-only page.
$wccs_acct_service->update( $wccs_acct_user_id, array( 'acct_plano' => 'Anual' ) );

$wccs_acct_view = wccs_acct_render( 'historico' );

wccs_acct_check(
	'The read-only page shows the value and offers nothing to submit',
	str_contains( $wccs_acct_view, 'Plano atual' )
		&& str_contains( $wccs_acct_view, 'Anual' )
		&& ! str_contains( $wccs_acct_view, '<form' )
		&& ! str_contains( $wccs_acct_view, 'wccs_account_fields' )
		&& ! str_contains( $wccs_acct_view, '_wccs_account_nonce' )
		&& ! str_contains( $wccs_acct_view, 'Salvar informações' ),
	'html=' . trim( (string) preg_replace( '/\s+/', ' ', $wccs_acct_view ) )
);

$wccs_acct_view_post = wccs_acct_submit(
	'historico',
	'historico_conta',
	array( 'acct_plano' => 'Vitalício' ),
	wp_create_nonce( 'wccs_account_section_historico_conta' )
);

wccs_acct_check(
	'And a submission to it is ignored, even with a valid nonce',
	! str_contains( $wccs_acct_view_post, 'Informações salvas com sucesso.' )
		&& 'Anual' === ( $wccs_acct_service->values( $wccs_acct_user_id )['acct_plano'] ?? '' ),
	'plano=' . wccs_acct_show( $wccs_acct_service->values( $wccs_acct_user_id )['acct_plano'] ?? null )
);

wp_set_current_user( 0 );

wccs_acct_check(
	'A logged-out visitor gets nothing from the endpoint',
	'' === trim( wccs_acct_render( 'preferencias' ) ),
	'render returns early'
);

wp_set_current_user( $wccs_acct_admin_id );

// ---------------------------------------------------------------------------
// 4. The customer store, and the configuration the model refuses.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '4. The customer store and the rule that protects it' );

$wccs_acct_service->update( $wccs_acct_user_id, array( 'acct_nome' => 'Ana Maria' ) );

wccs_acct_check(
	'The customer store merges updates instead of replacing what it holds',
	'Ana Maria' === ( $wccs_acct_service->values( $wccs_acct_user_id )['acct_nome'] ?? '' )
		&& 'Prefere ser contactada de manhã.' === ( $wccs_acct_service->values( $wccs_acct_user_id )['acct_bio'] ?? '' ),
	'values=' . (string) wp_json_encode( $wccs_acct_service->values( $wccs_acct_user_id ) )
);

update_user_meta( $wccs_acct_user_id, CustomerFieldsService::META_FIELDS, 'not-json' );

wccs_acct_check(
	'A customer whose stored meta is not a map reads as no values',
	array() === $wccs_acct_service->values( $wccs_acct_user_id ),
	'defensive read'
);

$wccs_acct_refused = wccs_acct_publish( $wccs_acct_repository, wccs_acct_document( false ) );
$wccs_acct_codes   = array();

foreach ( $wccs_acct_refused->errors() as $wccs_acct_error ) {
	$wccs_acct_codes[] = (string) ( $wccs_acct_error['code'] ?? '' );
}

wccs_acct_check(
	'An account page whose field stores on the order is refused',
	in_array( 'account_section_requires_customer_storage', $wccs_acct_codes, true ),
	'codes=' . implode( ', ', array_slice( $wccs_acct_codes, 0, 4 ) )
);

$wccs_acct_still = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();

wccs_acct_check(
	'And the refusal left the published store exactly as it was',
	count( $wccs_acct_still->sections() ) === 3
		&& in_array( 'perfil_conta', array_column( $wccs_acct_still->sections(), 'id' ), true ),
	'sections=' . count( $wccs_acct_still->sections() )
);

// A store with no account section must not remember an endpoint it does not have.
delete_option( 'wccs_account_endpoint_signature' );
$wccs_acct_result = wccs_acct_publish(
	$wccs_acct_repository,
	array(
		'fields'   => array(
			wccs_acct_field(
				'acct_sem_vinculo',
				'Sem vínculo',
				'text',
				10,
				array(),
				array( 'section' => 'billing' )
			),
		),
		'sections' => array(),
	)
);

MyAccountSections::register_endpoints();

wccs_acct_check(
	'A store with no account section keeps no endpoint signature and renders nothing',
	$wccs_acct_result->is_ok()
		&& false === get_option( 'wccs_account_endpoint_signature', false )
		&& '' === wccs_acct_render( 'preferencias' ),
	'signature removed with the endpoints'
);

// ---------------------------------------------------------------------------
// 5. What the harness left behind.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '5. What the harness left behind' );

wp_delete_user( $wccs_acct_user_id );

foreach ( $wccs_acct_options as $wccs_acct_key => $wccs_acct_backup ) {
	wccs_acct_restore( (string) $wccs_acct_key, $wccs_acct_backup );
}

$wccs_acct_missing = array();

foreach ( array_slice( $wccs_acct_option_keys, 0, 3 ) as $wccs_acct_key ) {
	if ( wccs_acct_backup( $wccs_acct_key ) !== $wccs_acct_options[ $wccs_acct_key ] ) {
		$wccs_acct_missing[] = $wccs_acct_key;
	}
}

wccs_acct_check(
	'The three schema options are back to the value the harness found',
	array() === $wccs_acct_missing,
	array() === $wccs_acct_missing ? 'draft, published and history restored' : 'drift: ' . implode( ', ', $wccs_acct_missing )
);

// Registering an endpoint flushes the rewrite rules; leave the site describing the
// document it actually holds, not the fixture's.
MyAccountSections::register_endpoints();
flush_rewrite_rules();

$wccs_acct_own_slugs = array();

foreach ( \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read()->sections() as $wccs_acct_section ) {
	$wccs_acct_account = (array) ( $wccs_acct_section['presentation']['account'] ?? array() );

	if ( in_array( 'customer_profile', (array) ( $wccs_acct_section['areas'] ?? array() ), true ) && '' !== (string) ( $wccs_acct_account['slug'] ?? '' ) ) {
		$wccs_acct_own_slugs[] = sanitize_title( (string) $wccs_acct_account['slug'] );
	}
}

$wccs_acct_signature = (string) get_option( 'wccs_account_endpoint_signature', '' );
$wccs_acct_expected  = array() === $wccs_acct_own_slugs ? '' : md5( (string) wp_json_encode( $wccs_acct_own_slugs ) );

wccs_acct_check(
	'And the store now remembers exactly the endpoints its own document declares',
	$wccs_acct_expected === $wccs_acct_signature,
	'store endpoints=' . ( array() === $wccs_acct_own_slugs ? '(none)' : implode( ', ', $wccs_acct_own_slugs ) )
);

wccs_acct_check(
	'And the customer it created is gone',
	false === get_user_by( 'id', $wccs_acct_user_id ),
	'user ' . $wccs_acct_user_id . ' deleted'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_acct_out( '' );
wccs_acct_out( '=====================================================================' );
wccs_acct_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_acct']['pass'],
		$GLOBALS['wccs_acct']['fail'],
		count( $GLOBALS['wccs_acct']['notes'] )
	)
);
wccs_acct_out( '=====================================================================' );

exit( $GLOBALS['wccs_acct']['fail'] > 0 ? 1 : 0 );
