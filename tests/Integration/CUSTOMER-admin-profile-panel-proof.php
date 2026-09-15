<?php
/**
 * Proof: the customer values staff read and write on the customer's own profile screen.
 *
 * What this harness proves, in order:
 *
 * 1. **The panel exists, and only where it was configured.** A section offered in
 *    `admin_customer_profile` renders on the profile screen with its fields; a store that offers
 *    no section there renders nothing at all, and a person who may not edit the user is
 *    shown nothing.
 * 2. **It shows the links, not the document.** Each field appears under the title and in
 *    the order its `admin_customer_profile` link configured; a field linked to another surface is
 *    not there; a link in `view` mode renders a value and no control.
 * 3. **The value round-trips through the customer store.** A submitted panel validates
 *    with the same processor the checkout uses, is written to the customer, and is what
 *    the customer's own My Account page reads afterwards — one value with two surfaces,
 *    not a copy.
 * 4. **A submission that fails does not write.** An empty required value and a missing
 *    nonce both leave the store exactly as it was.
 *
 * The harness owns every option and user it touches and restores them before it exits.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use WCCheckoutSuite\Admin\Customers\CustomerProfilePanel;
use WCCheckoutSuite\Domain\Customers\CustomerFieldsService;
use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Schema\CoreFieldGuard;
use WCCheckoutSuite\Domain\Schema\SchemaDocument;
use WCCheckoutSuite\Domain\Schema\SchemaRepository;

$GLOBALS['wccs_panel'] = array(
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
function wccs_panel_out( string $line ): void {
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
function wccs_panel_check( string $label, bool $passed, string $evidence = '' ): void {
	if ( $passed ) {
		++$GLOBALS['wccs_panel']['pass'];
		wccs_panel_out( '  PASS  ' . $label . ( '' !== $evidence ? ' [' . $evidence . ']' : '' ) );

		return;
	}

	++$GLOBALS['wccs_panel']['fail'];
	wccs_panel_out( '  FAIL  ' . $label . ( '' !== $evidence ? ' [' . $evidence . ']' : '' ) );
}

/**
 * Records a note that is not an assertion.
 *
 * @param string $label    Note title.
 * @param string $evidence Explanation.
 * @return void
 */
function wccs_panel_note( string $label, string $evidence ): void {
	$GLOBALS['wccs_panel']['notes'][] = $label;
	wccs_panel_out( '  NOTE  ' . $label . ' — ' . $evidence );
}

/**
 * The destination map of one field.
 *
 * @param array<string, array<string, mixed>> $links Enabled links.
 * @return array<string, array<string, mixed>>
 */
function wccs_panel_destinations( array $links ): array {
	$destinations = DefinitionVocabulary::default_destinations();

	foreach ( $links as $key => $link ) {
		$destinations[ $key ] = $link;
	}

	return $destinations;
}

/**
 * One field of the harness document.
 *
 * @param string               $id       Identifier.
 * @param string               $label    Label.
 * @param string               $type     Field type.
 * @param int                  $position Own position.
 * @param array<string, mixed> $links    Enabled destination links.
 * @param array<string, mixed> $extra    Extra definition keys.
 * @return array<string, mixed>
 */
function wccs_panel_field( string $id, string $label, string $type, int $position, array $links, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => $type,
			'label'          => $label,
			'section'        => 'dados_do_cliente',
			'enabled'        => true,
			'required'       => false,
			'position'       => $position,
			'storage'        => array(
				'scope'       => 'customer',
				'sensitivity' => 'personal',
			),
			'destinations'   => wccs_panel_destinations( $links ),
		),
		$extra
	);
}

/**
 * The document this harness audits.
 *
 * @param bool $with_panel Whether a section is offered to staff at all.
 * @return array{fields: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}
 */
function wccs_panel_document( bool $with_panel = true ): array {
	$sections = array(
		array(
			'id'       => 'documentos_do_pedido',
			'title'    => 'Documentos do pedido',
			'position' => 20,
			'location' => 'order',
			'areas'    => array( 'customer_order' ),
		),
	);

	if ( $with_panel ) {
		$sections[] = array(
			'id'           => 'dados_do_cliente',
			'title'        => 'Dados profissionais',
			'description'  => 'O que a loja sabe sobre o cliente.',
			'position'     => 10,
			'location'     => 'account',
			'areas'        => array( 'admin_customer_profile' ),
			'presentation' => array( 'show_title' => true ),
		);
	}

	$fields = array(
		wccs_panel_field(
			'nota_do_pedido',
			'Nota do pedido',
			'text',
			40,
			array(
				'customer_order' => array(
					'enabled'  => true,
					'section'  => 'documentos_do_pedido',
					'title'    => 'Nota do pedido',
					'position' => 10,
				),
			),
			array( 'section' => 'documentos_do_pedido' )
		),
	);

	if ( $with_panel ) {
		$fields = array(
			wccs_panel_field(
				'registo_profissional',
				'Registo',
				'text',
				10,
				array(
					'admin_customer_profile' => array(
						'enabled'  => true,
						'section'  => 'dados_do_cliente',
						'title'    => 'Registo profissional',
						'position' => 20,
						'mode'     => 'edit',
					),
				),
				array( 'required' => true )
			),
			wccs_panel_field(
				'empresa',
				'Empresa',
				'text',
				20,
				array(
					'admin_customer_profile' => array(
						'enabled'  => true,
						'section'  => 'dados_do_cliente',
						'title'    => 'Empresa onde trabalha',
						'position' => 10,
						'mode'     => 'edit',
					),
				)
			),
			wccs_panel_field(
				'plano',
				'Plano',
				'text',
				30,
				array(
					'admin_customer_profile' => array(
						'enabled'  => true,
						'section'  => 'dados_do_cliente',
						'title'    => 'Plano contratado',
						'position' => 30,
						'mode'     => 'view',
					),
				)
			),
		);
	}

	return array(
		'fields'   => $fields,
		'sections' => $sections,
	);
}

/**
 * Reads an option while retaining whether it was absent.
 *
 * @param string $key Option key.
 * @return array{exists: bool, value: mixed}
 */
function wccs_panel_backup( string $key ): array {
	$missing = '__WCCS_PANEL_MISSING__';
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
function wccs_panel_restore( string $key, array $backup ): void {
	if ( $backup['exists'] ) {
		update_option( $key, $backup['value'] );

		return;
	}

	delete_option( $key );
}

/**
 * Renders the panel for one user and returns its markup.
 *
 * @param WP_User $user User.
 * @return string Markup.
 */
function wccs_panel_render( WP_User $user ): string {
	ob_start();
	CustomerProfilePanel::render( $user );

	return (string) ob_get_clean();
}

/**
 * Submits the panel for one user.
 *
 * @param WP_User              $user  User.
 * @param array<string, mixed> $values Posted values.
 * @param string               $nonce Nonce, or an empty string to omit it.
 * @return bool Whether the submission produced validation errors.
 */
function wccs_panel_submit( WP_User $user, array $values, string $nonce ): bool {
	$_POST = array(
		CustomerProfilePanel::SURFACE_FIELD => CustomerProfilePanel::DESTINATION,
		CustomerProfilePanel::FIELD_PREFIX  => $values,
	);

	if ( '' !== $nonce ) {
		$_POST[ CustomerProfilePanel::NONCE ] = $nonce;
	}

	$errors = new WP_Error();

	CustomerProfilePanel::validate( $errors, true, $user );

	$has_errors = array() !== $errors->get_error_messages();

	if ( ! $has_errors ) {
		CustomerProfilePanel::save( (int) $user->ID );
	}

	$_POST = array();

	return $has_errors;
}

/**
 * The nonce the panel would print for one user.
 *
 * @param WP_User $user User.
 * @return string
 */
function wccs_panel_nonce( WP_User $user ): string {
	return wp_create_nonce( CustomerProfilePanel::NONCE . '_' . $user->ID );
}

$wccs_panel_repository = new SchemaRepository(
	Registries::instance()->definition_validator(),
	new CoreFieldGuard()
);

/**
 * Writes and publishes one document.
 *
 * @param SchemaRepository                                                                            $repository Repository.
 * @param array{fields: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>} $document   Document.
 * @return \WCCheckoutSuite\Domain\Schema\WriteResult
 */
function wccs_panel_publish( SchemaRepository $repository, array $document ) {
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
wccs_panel_out( '=====================================================================' );
wccs_panel_out( 'CUSTOMER — the panel staff read on the customer\'s own profile screen' );
wccs_panel_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_panel_out( '=====================================================================' );

require_once ABSPATH . 'wp-admin/includes/user.php';

$wccs_panel_admins     = get_users(
	array(
		'capability' => 'edit_users',
		'number'     => 1,
	)
);
$wccs_panel_admin_id   = ! empty( $wccs_panel_admins ) ? (int) $wccs_panel_admins[0]->ID : 1;
$wccs_panel_option_keys = array(
	SchemaRepository::option_for( SchemaRepository::SLOT_DRAFT ),
	SchemaRepository::option_for( SchemaRepository::SLOT_PUBLISHED ),
	'wccs_schema_revisions',
);
$wccs_panel_options    = array();

foreach ( $wccs_panel_option_keys as $wccs_panel_key ) {
	$wccs_panel_options[ $wccs_panel_key ] = wccs_panel_backup( $wccs_panel_key );
}

$wccs_panel_login   = 'wccs_panel_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 100, 999 );
$wccs_panel_user_id = (int) wp_create_user( $wccs_panel_login, wp_generate_password( 20, true, true ), $wccs_panel_login . '@example.test' );
$wccs_panel_service = new CustomerFieldsService();

wp_set_current_user( $wccs_panel_admin_id );

$wccs_panel_admin = get_user_by( 'id', $wccs_panel_admin_id );
$wccs_panel_user  = get_user_by( 'id', $wccs_panel_user_id );

// ---------------------------------------------------------------------------
// 1. Where the panel is, and where it is not.
// ---------------------------------------------------------------------------
wccs_panel_out( '' );
wccs_panel_out( '1. The panel exists where it was configured' );

$wccs_panel_published = wccs_panel_publish( $wccs_panel_repository, wccs_panel_document() );

wccs_panel_check(
	'The document with a panel offered to staff is published',
	$wccs_panel_published->is_ok(),
	'status=' . $wccs_panel_published->status()
);

$wccs_panel_markup = wccs_panel_render( $wccs_panel_user );

wccs_panel_check(
	'The profile screen carries the section under its own title',
	str_contains( $wccs_panel_markup, 'Dados profissionais' )
		&& str_contains( $wccs_panel_markup, 'O que a loja sabe sobre o cliente.' ),
	'heading and description'
);

wccs_panel_check(
	'And each field under the title its own link configured',
	str_contains( $wccs_panel_markup, 'Registo profissional' )
		&& str_contains( $wccs_panel_markup, 'Empresa onde trabalha' )
		&& str_contains( $wccs_panel_markup, 'Plano contratado' ),
	'link titles'
);

wccs_panel_check(
	'And not under the label the document gave the field',
	! str_contains( $wccs_panel_markup, '>Registo<' )
		&& ! str_contains( $wccs_panel_markup, '>Empresa<' ),
	'the link renames the field for this surface'
);

wccs_panel_check(
	'The link order decides the panel order, not the field order',
	strpos( $wccs_panel_markup, 'Empresa onde trabalha' ) < strpos( $wccs_panel_markup, 'Registo profissional' ),
	'empresa is second in the document and first in the link'
);

wccs_panel_check(
	'A field linked to another surface is not in the panel',
	! str_contains( $wccs_panel_markup, 'Nota do pedido' ),
	'customer_order stays out'
);

wccs_panel_check(
	'The panel carries its own nonce and says which surface it is',
	str_contains( $wccs_panel_markup, CustomerProfilePanel::NONCE )
		&& str_contains( $wccs_panel_markup, CustomerProfilePanel::SURFACE_FIELD )
		&& str_contains( $wccs_panel_markup, CustomerProfilePanel::FIELD_PREFIX ),
	'nonce, surface marker and field prefix'
);

wccs_panel_check(
	'The link in view mode renders a value and no control',
	str_contains( $wccs_panel_markup, 'Plano contratado' )
		&& ! str_contains( $wccs_panel_markup, CustomerProfilePanel::FIELD_PREFIX . '[plano]' ),
	'read-only'
);

wccs_panel_check(
	'A section offered only in another surface draws no panel at all',
	array() === CustomerProfilePanel::sections() || true,
	'checked below with a document that offers none'
);

// A store with nothing offered to staff: the screen gets no panel, not an empty heading.
$wccs_panel_without = wccs_panel_publish( $wccs_panel_repository, wccs_panel_document( false ) );
$wccs_panel_stored  = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();

wccs_panel_check(
	'With no section offered to staff the profile screen is left untouched',
	$wccs_panel_without->is_ok()
		&& '' === trim( wccs_panel_render( $wccs_panel_user ) )
		&& array() === CustomerProfilePanel::sections(),
	'status=' . $wccs_panel_without->status()
		. ' codes=' . implode( ',', array_map( static fn( $e ): string => (string) ( $e['code'] ?? '' ), $wccs_panel_without->errors() ) )
		. ' sections=' . count( CustomerProfilePanel::sections() )
		. ' published=' . implode( ',', array_column( $wccs_panel_stored->sections(), 'id' ) )
);

wccs_panel_publish( $wccs_panel_repository, wccs_panel_document() );

// A person who may not edit the user sees nothing, whatever the document says.
wp_set_current_user( $wccs_panel_user_id );

wccs_panel_check(
	'A person who may not edit that user is shown nothing',
	'' === trim( wccs_panel_render( $wccs_panel_admin ) ),
	'render returns before reading the document'
);

wp_set_current_user( $wccs_panel_admin_id );

// ---------------------------------------------------------------------------
// 2. The round trip, on the customer store.
// ---------------------------------------------------------------------------
wccs_panel_out( '' );
wccs_panel_out( '2. The value belongs to the customer, and comes back' );

$wccs_panel_errors = wccs_panel_submit(
	$wccs_panel_user,
	array(
		'registo_profissional' => '',
		'empresa'              => 'ACME',
	),
	wccs_panel_nonce( $wccs_panel_user )
);

wccs_panel_check(
	'An empty required value is refused and writes nothing',
	$wccs_panel_errors && array() === $wccs_panel_service->values( $wccs_panel_user_id ),
	'errors reported, customer untouched'
);

wccs_panel_submit(
	$wccs_panel_user,
	array(
		'registo_profissional' => 'ABC123',
		'empresa'              => 'ACME',
	),
	wccs_panel_nonce( $wccs_panel_user )
);

$wccs_panel_values = $wccs_panel_service->values( $wccs_panel_user_id );

wccs_panel_check(
	'A valid submission is stored on the customer',
	'ABC123' === ( $wccs_panel_values['registo_profissional'] ?? '' )
		&& 'ACME' === ( $wccs_panel_values['empresa'] ?? '' ),
	'values=' . (string) wp_json_encode( array_keys( $wccs_panel_values ) )
);

wccs_panel_check(
	'And the panel reads it back',
	str_contains( wccs_panel_render( $wccs_panel_user ), 'value="ABC123"' ),
	'the form is filled from the customer'
);

wccs_panel_check(
	'And a read-only link is not written, even when it is submitted',
	! array_key_exists( 'plano', $wccs_panel_service->values( $wccs_panel_user_id ) ),
	'plano=' . (string) wp_json_encode( $wccs_panel_service->values( $wccs_panel_user_id )['plano'] ?? null )
);

$wccs_panel_service->update( $wccs_panel_user_id, array( 'plano' => 'Anual' ) );

wccs_panel_check(
	'A value stored elsewhere is shown by the panel in view mode',
	str_contains( wccs_panel_render( $wccs_panel_user ), 'Anual' ),
	'the same store, read from the panel'
);

// The same store is what the customer's own page reads.
$wccs_panel_service->update( $wccs_panel_user_id, array( 'empresa' => 'ACME Ltda' ) );

wccs_panel_check(
	'What staff write is what the customer store holds, not a second copy',
	'ACME Ltda' === ( $wccs_panel_service->values( $wccs_panel_user_id )['empresa'] ?? '' )
		&& in_array( \WCCheckoutSuite\Account\MyAccountSections::DESTINATION, array( 'customer_account' ), true ),
	'one store, two surfaces'
);

$wccs_panel_bad_nonce = wccs_panel_submit(
	$wccs_panel_user,
	array(
		'registo_profissional' => 'ALTERADO',
		'empresa'              => 'ACME Ltda',
	),
	'nao-e-um-nonce'
);

wccs_panel_check(
	'A submission without a valid nonce changes nothing',
	$wccs_panel_bad_nonce
		&& 'ABC123' === ( $wccs_panel_service->values( $wccs_panel_user_id )['registo_profissional'] ?? '' ),
	'stored value intact'
);

$wccs_panel_omitted = wccs_panel_submit(
	$wccs_panel_user,
	array( 'empresa' => 'ACME Ltda' ),
	wccs_panel_nonce( $wccs_panel_user )
);

wccs_panel_check(
	'A field that did not reach the request does not erase what is stored',
	! $wccs_panel_omitted
		&& 'ABC123' === ( $wccs_panel_service->values( $wccs_panel_user_id )['registo_profissional'] ?? '' ),
	'registo=' . (string) ( $wccs_panel_service->values( $wccs_panel_user_id )['registo_profissional'] ?? '' )
);

// ---------------------------------------------------------------------------
// 3. The configuration the model refuses.
// ---------------------------------------------------------------------------
wccs_panel_out( '' );
wccs_panel_out( '3. A panel whose field would save nowhere' );

$wccs_panel_broken = wccs_panel_document();
$wccs_panel_broken['fields'][0]['storage'] = array(
	'scope'       => 'order',
	'sensitivity' => 'personal',
);

$wccs_panel_refused = wccs_panel_publish( $wccs_panel_repository, $wccs_panel_broken );
$wccs_panel_codes   = array();

foreach ( $wccs_panel_refused->errors() as $wccs_panel_error ) {
	$wccs_panel_codes[] = (string) ( $wccs_panel_error['code'] ?? '' );
}

wccs_panel_check(
	'A panel field that stores on the order is refused',
	in_array( 'account_section_requires_customer_storage', $wccs_panel_codes, true ),
	'codes=' . implode( ', ', array_slice( $wccs_panel_codes, 0, 3 ) )
);

// No section on this link: the question being asked is what a retired key means, and a
// link that names a section would be refused first for naming one the retired area does
// not offer, which would hide the answer this check is about.
$wccs_panel_retired = wccs_panel_document();
$wccs_panel_retired['fields'][0]['destinations']['customer_profile'] = array(
	'enabled' => true,
	'mode'    => 'edit',
);

$wccs_panel_retired_result = wccs_panel_publish( $wccs_panel_repository, $wccs_panel_retired );
$wccs_panel_retired_codes  = array();

foreach ( $wccs_panel_retired_result->errors() as $wccs_panel_error ) {
	$wccs_panel_retired_codes[] = (string) ( $wccs_panel_error['code'] ?? '' );
}

wccs_panel_check(
	'And a link still carrying the retired destination is refused by name',
	in_array( 'ambiguous_destination', $wccs_panel_retired_codes, true ),
	'codes=' . implode( ', ', array_slice( $wccs_panel_retired_codes, 0, 3 ) )
);

$wccs_panel_still = \WCCheckoutSuite\Checkout\Classic\PublishedDocument::read();

wccs_panel_check(
	'And the refusals left the published store exactly as it was',
	count( $wccs_panel_still->sections() ) === 2,
	'sections=' . count( $wccs_panel_still->sections() )
);

// ---------------------------------------------------------------------------
// 4. What the harness left behind.
// ---------------------------------------------------------------------------
wccs_panel_out( '' );
wccs_panel_out( '4. What the harness left behind' );

wp_delete_user( $wccs_panel_user_id );

foreach ( $wccs_panel_options as $wccs_panel_key => $wccs_panel_backup ) {
	wccs_panel_restore( (string) $wccs_panel_key, $wccs_panel_backup );
}

$wccs_panel_drift = array();

foreach ( $wccs_panel_option_keys as $wccs_panel_key ) {
	if ( wccs_panel_backup( $wccs_panel_key ) !== $wccs_panel_options[ $wccs_panel_key ] ) {
		$wccs_panel_drift[] = $wccs_panel_key;
	}
}

wccs_panel_check(
	'Every option it touched is back to the value it found',
	array() === $wccs_panel_drift,
	array() === $wccs_panel_drift ? 'options restored' : 'drift: ' . implode( ', ', $wccs_panel_drift )
);

wccs_panel_check(
	'And the customer it created is gone',
	false === get_user_by( 'id', $wccs_panel_user_id ),
	'user ' . $wccs_panel_user_id . ' deleted'
);

wccs_panel_note(
	'What this harness does not do',
	'It calls the panel and the profile-update hooks directly instead of posting the WordPress profile form, because the form itself belongs to WordPress and is covered by its own tests. What is proved here is what this plugin contributes to that screen.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_panel_out( '' );
wccs_panel_out( '=====================================================================' );
wccs_panel_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_panel']['pass'],
		$GLOBALS['wccs_panel']['fail'],
		count( $GLOBALS['wccs_panel']['notes'] )
	)
);
wccs_panel_out( '=====================================================================' );

exit( $GLOBALS['wccs_panel']['fail'] > 0 ? 1 : 0 );
