<?php
/**
 * WCCS-017 proof harness — the inspector shows only supported properties.
 *
 * Task:   WCCS-017 "Criar inspector por tipo"
 * Phase:  F03
 * Accept: "Máscaras, opções, descrição, largura, storage e visibilidade somente onde suportados."
 *
 * "Somente onde suportados" is a claim about the whole system, not about a panel.
 * An inspector that hides a control while the server accepts the value behind it
 * satisfies the screenshot and not the requirement, so this harness checks the
 * two halves together:
 *
 * 1. **No drift.** Every value the catalogue publishes is a value the validator
 *    accepts, and every value it withholds is one the validator refuses. The
 *    inspector and the validator read the same class, and this is where that is
 *    demonstrated rather than asserted in a comment.
 * 2. **The gates hold through the real route.** A mask on a type that cannot be
 *    masked, a storage scope on a field that stores nothing and a value exposed
 *    through the Store API with nothing to expose are all refused with a stable
 *    code, not merely absent from a form.
 *
 * Prerequisite: the plugin must be ACTIVE.
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
 * Builds a definition array for validation.
 *
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_def( array $changes = array() ): array {
	return array_merge(
		array(
			'id'     => 'billing_document',
			'origin' => 'custom',
			'type'   => 'text',
			'label'  => 'CPF',
		),
		$changes
	);
}

/**
 * Validates one definition and returns its error codes.
 *
 * @param array<string, mixed> $data Definition.
 * @return array{valid: bool, codes: array<int, string>}
 */
function wccs_proof_validate( array $data ): array {
	$result = \WCCheckoutSuite\Domain\Registries::instance()->definition_validator()->validate_array( $data );

	return array(
		'valid' => $result->is_valid(),
		'codes' => $result->error_codes(),
	);
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

$wccs_options_before = wccs_proof_option_count();

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-017 proof — the inspector shows only supported properties' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_registries = \WCCheckoutSuite\Domain\Registries::instance();

// ---------------------------------------------------------------------------
// 1. What the inspector is told.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. The catalogue the inspector builds itself from' );

$wccs_response  = rest_do_request( new WP_REST_Request( 'GET', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\CatalogController::ROUTE_FIELD_TYPES ) );
$wccs_catalogue = $wccs_response->get_data();

wccs_proof_check(
	'The catalogue route answers with 200',
	200 === $wccs_response->get_status(),
	'status=' . $wccs_response->get_status()
);

$wccs_masks = (array) ( $wccs_catalogue['masks'] ?? array() );

wccs_proof_check(
	'The registered masks are published',
	count( $wccs_masks ) === count( $wccs_registries->masks()->to_array() ),
	count( $wccs_masks ) . ' masks: ' . implode( ', ', array_column( $wccs_masks, 'key' ) )
);

$wccs_missing_version = array();

foreach ( $wccs_masks as $wccs_mask ) {
	// The definition stores the version it was configured against, so the picker
	// cannot offer a mask without saying which version it is offering.
	if ( ! isset( $wccs_mask['key'], $wccs_mask['version'] ) ) {
		$wccs_missing_version[] = (string) ( $wccs_mask['key'] ?? '?' );
	}
}

wccs_proof_check(
	'Every published mask carries the version a field must record',
	array() === $wccs_missing_version,
	$wccs_missing_version ? implode( ', ', $wccs_missing_version ) : 'all versioned'
);

$wccs_vocabulary = (array) ( $wccs_catalogue['vocabulary'] ?? array() );
$wccs_expected   = \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::to_array();

// The published vocabulary has to be the enforced one, or the inspector would
// offer a choice the server refuses.
wccs_proof_check(
	'The published vocabulary is the one the validator reads',
	$wccs_vocabulary === $wccs_expected,
	implode( ', ', array_keys( $wccs_vocabulary ) )
);

$wccs_labelled = 0;
$wccs_unlabelled = array();

foreach ( $wccs_vocabulary as $wccs_group => $wccs_entries ) {
	foreach ( (array) $wccs_entries as $wccs_entry ) {
		++$wccs_labelled;

		if ( '' === (string) ( $wccs_entry['label'] ?? '' ) || '' === (string) ( $wccs_entry['description'] ?? '' ) ) {
			$wccs_unlabelled[] = $wccs_group . ':' . (string) ( $wccs_entry['value'] ?? '?' );
		}
	}
}

// A closed vocabulary with no explanation is a list of magic words. Section 428
// asks for the properties, and a property a merchant cannot understand is not
// really offered.
wccs_proof_check(
	'Every published value has a label and an explanation',
	array() === $wccs_unlabelled,
	$wccs_unlabelled ? implode( ', ', $wccs_unlabelled ) : $wccs_labelled . ' values labelled'
);

// ---------------------------------------------------------------------------
// 2. No drift between what is published and what is accepted.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Published equals accepted' );

$wccs_rejected_published = array();

foreach ( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::storage_scope_values() as $wccs_scope ) {
	$wccs_outcome = wccs_proof_validate(
		wccs_proof_def(
			array(
				'storage' => array(
					'scope'       => $wccs_scope,
					'sensitivity' => 'personal',
				),
			)
		)
	);

	if ( ! $wccs_outcome['valid'] ) {
		$wccs_rejected_published[] = 'scope:' . $wccs_scope . '(' . implode( '|', $wccs_outcome['codes'] ) . ')';
	}
}

foreach ( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::storage_sensitivity_values() as $wccs_level ) {
	$wccs_outcome = wccs_proof_validate(
		wccs_proof_def(
			array(
				'storage' => array(
					'scope'       => 'order',
					'sensitivity' => $wccs_level,
				),
			)
		)
	);

	if ( ! $wccs_outcome['valid'] ) {
		$wccs_rejected_published[] = 'sensitivity:' . $wccs_level . '(' . implode( '|', $wccs_outcome['codes'] ) . ')';
	}
}

foreach ( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::hidden_value_policy_values() as $wccs_policy ) {
	$wccs_outcome = wccs_proof_validate( wccs_proof_def( array( 'hidden_value_policy' => $wccs_policy ) ) );

	if ( ! $wccs_outcome['valid'] ) {
		$wccs_rejected_published[] = 'policy:' . $wccs_policy . '(' . implode( '|', $wccs_outcome['codes'] ) . ')';
	}
}

foreach ( \WCCheckoutSuite\Domain\Fields\DefinitionVocabulary::visibility_key_values() as $wccs_audience ) {
	$wccs_outcome = wccs_proof_validate(
		wccs_proof_def(
			array(
				'visibility' => array(
					'admin_order' => true,
					$wccs_audience => true,
				),
			)
		)
	);

	if ( ! $wccs_outcome['valid'] ) {
		$wccs_rejected_published[] = 'audience:' . $wccs_audience . '(' . implode( '|', $wccs_outcome['codes'] ) . ')';
	}
}

wccs_proof_check(
	'Every value the inspector offers is accepted by the validator',
	array() === $wccs_rejected_published,
	$wccs_rejected_published ? implode( ', ', $wccs_rejected_published ) : 'no drift'
);

// And the other direction, which is the one that turns a hidden control back
// into a rule: a value that is not offered must not be accepted either.
$wccs_accepted_hidden = array();

foreach ( array( 'redis', 'ORDER', 'orders', '' ) as $wccs_bogus ) {
	$wccs_outcome = wccs_proof_validate(
		wccs_proof_def(
			array(
				'storage' => array(
					'scope'       => $wccs_bogus,
					'sensitivity' => 'personal',
				),
			)
		)
	);

	if ( $wccs_outcome['valid'] ) {
		$wccs_accepted_hidden[] = 'scope:' . var_export( $wccs_bogus, true );
	}
}

foreach ( array( 'twitter', 'Admin_Order', '' ) as $wccs_bogus ) {
	$wccs_outcome = wccs_proof_validate(
		wccs_proof_def(
			array(
				'visibility' => array(
					'admin_order'  => true,
					$wccs_bogus    => true,
				),
			)
		)
	);

	if ( $wccs_outcome['valid'] ) {
		$wccs_accepted_hidden[] = 'audience:' . var_export( $wccs_bogus, true );
	}
}

wccs_proof_check(
	'A value the inspector never offers is refused, not ignored',
	array() === $wccs_accepted_hidden,
	$wccs_accepted_hidden ? implode( ', ', $wccs_accepted_hidden ) : 'nothing accepted off-list'
);

// ---------------------------------------------------------------------------
// 3. Masks, only where supported.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Masks' );

$wccs_maskable = array();
$wccs_unmaskable = array();

foreach ( $wccs_registries->types()->types() as $wccs_key => $wccs_type ) {
	if ( ! empty( $wccs_type->supports()['maskable'] ) ) {
		$wccs_maskable[] = (string) $wccs_key;
	} else {
		$wccs_unmaskable[] = (string) $wccs_key;
	}
}

wccs_proof_note( 'Maskable types', implode( ', ', $wccs_maskable ) ?: '(none)' );

$wccs_mask_ok = wccs_proof_validate(
	wccs_proof_def(
		array(
			'type' => $wccs_maskable[0] ?? 'text',
			'mask' => array(
				'key'     => $wccs_masks[0]['key'] ?? 'numeric',
				'version' => (int) ( $wccs_masks[0]['version'] ?? 1 ),
			),
		)
	)
);

wccs_proof_check(
	'A mask on a maskable type is accepted',
	$wccs_mask_ok['valid'],
	implode( ', ', $wccs_mask_ok['codes'] )
);

$wccs_mask_wrong_type = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'   => 'billing_mail',
			'type' => in_array( 'email', $wccs_unmaskable, true ) ? 'email' : ( $wccs_unmaskable[0] ?? 'number' ),
			'mask' => array(
				'key'     => $wccs_masks[0]['key'] ?? 'numeric',
				'version' => (int) ( $wccs_masks[0]['version'] ?? 1 ),
			),
		)
	)
);

wccs_proof_check(
	'A mask on a type that cannot be masked is refused',
	in_array( 'mask_not_supported', $wccs_mask_wrong_type['codes'], true ),
	implode( ', ', $wccs_mask_wrong_type['codes'] )
);

$wccs_mask_unknown = wccs_proof_validate(
	wccs_proof_def(
		array(
			'type' => $wccs_maskable[0] ?? 'text',
			// Not `br.cpf`: WCCS-026 registered the Brazilian masks, so a probe
			// using one of them would be asserting that a *registered* mask is
			// refused. The key stays absent by construction.
			'mask' => array(
				'key'     => 'not.registered',
				'version' => 1,
			),
		)
	)
);

wccs_proof_check(
	'An unregistered mask is refused',
	in_array( 'unknown_mask', $wccs_mask_unknown['codes'], true ),
	implode( ', ', $wccs_mask_unknown['codes'] )
);

$wccs_mask_stale = wccs_proof_validate(
	wccs_proof_def(
		array(
			'type' => $wccs_maskable[0] ?? 'text',
			'mask' => array(
				'key'     => $wccs_masks[0]['key'] ?? 'numeric',
				'version' => 99,
			),
		)
	)
);

wccs_proof_check(
	'A mask configured against another version is refused, so a changed mask is noticed',
	in_array( 'mask_version_stale', $wccs_mask_stale['codes'], true ),
	implode( ', ', $wccs_mask_stale['codes'] )
);

// ---------------------------------------------------------------------------
// 4. Storage and visibility, only where supported.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Storage and visibility' );

$wccs_heading_default = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'intro_note',
			'type'     => 'heading',
			'label'    => 'Intro',
			'settings' => array( 'content' => 'Bem-vindo' ),
		)
	)
);

wccs_proof_check(
	'A type that stores nothing cannot claim to keep its value with the order',
	in_array( 'storage_scope_requires_value', $wccs_heading_default['codes'], true ),
	implode( ', ', $wccs_heading_default['codes'] )
);

$wccs_heading_ok = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'intro_note',
			'type'     => 'heading',
			'label'    => 'Intro',
			'settings' => array( 'content' => 'Bem-vindo' ),
			'storage'  => array(
				'scope'       => 'none',
				'sensitivity' => 'public',
			),
			'visibility' => array( 'admin_order' => false ),
		)
	)
);

wccs_proof_check(
	'The same type is accepted once it declares that nothing is stored',
	$wccs_heading_ok['valid'],
	implode( ', ', $wccs_heading_ok['codes'] )
);

$wccs_exposed = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'         => 'intro_note',
			'type'       => 'heading',
			'label'      => 'Intro',
			'settings'   => array( 'content' => 'Bem-vindo' ),
			'storage'    => array(
				'scope'       => 'none',
				'sensitivity' => 'public',
			),
			'visibility' => array( 'admin_order' => false, 'public_api' => true ),
		)
	)
);

wccs_proof_check(
	'A value that does not exist cannot be exposed through the Store API',
	in_array( 'visibility_exposes_missing_value', $wccs_exposed['codes'], true ),
	implode( ', ', $wccs_exposed['codes'] )
);

$wccs_audience_type = wccs_proof_validate(
	wccs_proof_def(
		array(
			'visibility' => array(
				'admin_order' => true,
				'public_api'  => 'yes',
			),
		)
	)
);

wccs_proof_check(
	'An audience has to be true or false, not a word',
	in_array( 'invalid_visibility_value', $wccs_audience_type['codes'], true ),
	implode( ', ', $wccs_audience_type['codes'] )
);

$wccs_sensitivity = wccs_proof_validate(
	wccs_proof_def(
		array(
			'storage' => array(
				'scope'       => 'order',
				'sensitivity' => 'segredo',
			),
		)
	)
);

wccs_proof_check(
	'An unknown sensitivity is refused',
	in_array( 'unknown_storage_sensitivity', $wccs_sensitivity['codes'], true ),
	implode( ', ', $wccs_sensitivity['codes'] )
);

// ---------------------------------------------------------------------------
// 5. Options are a shape, not just a list.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Options' );

$wccs_option_shape = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'person_type',
			'type'     => 'select',
			'label'    => 'Tipo',
			'settings' => array( 'options' => array( 'banana' ) ),
		)
	)
);

wccs_proof_check(
	'An option that is not a value/label pair is refused',
	! $wccs_option_shape['valid'],
	implode( ', ', $wccs_option_shape['codes'] )
);

$wccs_option_incomplete = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'person_type',
			'type'     => 'select',
			'label'    => 'Tipo',
			'settings' => array( 'options' => array( array( 'value' => 'pf' ) ) ),
		)
	)
);

wccs_proof_check(
	'An option without a label is refused',
	in_array( 'missing_required_setting', $wccs_option_incomplete['codes'], true ),
	implode( ', ', $wccs_option_incomplete['codes'] )
);

$wccs_option_extra = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'person_type',
			'type'     => 'select',
			'label'    => 'Tipo',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'pf',
						'label' => 'Pessoa física',
						'price' => 10,
					),
				),
			),
		)
	)
);

wccs_proof_check(
	'An option with a property the type never declared is refused',
	in_array( 'unknown_setting', $wccs_option_extra['codes'], true ),
	implode( ', ', $wccs_option_extra['codes'] )
);

$wccs_option_empty = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'person_type',
			'type'     => 'select',
			'label'    => 'Tipo',
			'settings' => array( 'options' => array() ),
		)
	)
);

wccs_proof_check(
	'A choice field with no options is refused',
	in_array( 'setting_too_few_items', $wccs_option_empty['codes'], true ),
	implode( ', ', $wccs_option_empty['codes'] )
);

$wccs_option_ok = wccs_proof_validate(
	wccs_proof_def(
		array(
			'id'       => 'person_type',
			'type'     => 'select',
			'label'    => 'Tipo',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'pf',
						'label' => 'Pessoa física',
					),
					array(
						'value' => 'pj',
						'label' => 'Pessoa jurídica',
					),
				),
			),
		)
	)
);

wccs_proof_check(
	'Well-formed options are accepted',
	$wccs_option_ok['valid'],
	implode( ', ', $wccs_option_ok['codes'] )
);

// ---------------------------------------------------------------------------
// 6. The description survives the route.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Description through the real route' );

$wccs_long_description = wccs_proof_validate(
	wccs_proof_def( array( 'description' => str_repeat( 'a', 501 ) ) )
);

wccs_proof_check(
	'An over-long description is refused',
	in_array( 'description_too_long', $wccs_long_description['codes'], true ),
	implode( ', ', $wccs_long_description['codes'] )
);

$wccs_request = new WP_REST_Request( 'PUT', '/' . WCCS_REST_NAMESPACE . \WCCheckoutSuite\Http\Admin\SchemaController::ROUTE_DRAFT );
$wccs_request->set_header( 'Content-Type', 'application/json' );
$wccs_request->set_body(
	(string) wp_json_encode(
		array(
			'schema'            => array(
				'revision'       => 1,
				'schema_version' => 1,
				'sections'       => array(),
				'settings'       => array(),
				'fields'         => array(
					wccs_proof_def(
						array(
							'description' => 'Como aparece no documento.',
							'mask'        => array(
								'key'     => $wccs_masks[0]['key'] ?? 'numeric',
								'version' => (int) ( $wccs_masks[0]['version'] ?? 1 ),
							),
							'storage'     => array(
								'scope'       => 'order',
								'sensitivity' => 'sensitive',
							),
							'visibility'  => array(
								'admin_order' => true,
								'public_api'  => false,
							),
						)
					),
				),
			),
			'expected_revision' => 0,
		)
	)
);

$wccs_saved = rest_do_request( $wccs_request );

wccs_proof_check(
	'A fully configured field is accepted by the draft route',
	200 === $wccs_saved->get_status(),
	'status=' . $wccs_saved->get_status() . ' ' . (string) wp_json_encode( $wccs_saved->get_data() )
);

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	$wccs_registries->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_stored = $wccs_repository->read( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT )->fields()[0] ?? array();

wccs_proof_check(
	'The description is stored and read back',
	'Como aparece no documento.' === ( $wccs_stored['description'] ?? '' ),
	'description=' . ( $wccs_stored['description'] ?? 'missing' )
);

wccs_proof_check(
	'The mask is stored with its version',
	( $wccs_masks[0]['key'] ?? '' ) === ( $wccs_stored['mask']['key'] ?? '' )
		&& (int) ( $wccs_masks[0]['version'] ?? 0 ) === (int) ( $wccs_stored['mask']['version'] ?? 0 ),
	'version=' . var_export( $wccs_stored['mask']['version'] ?? null, true )
);

// ---------------------------------------------------------------------------
// 7. The inspector reached the bundle.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Build output' );

$wccs_built_js  = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.js' );
$wccs_built_css = (string) file_get_contents( WCCS_PLUGIN_DIR . 'build/admin/index.css' );

wccs_proof_check(
	'The inspector reached the shipped bundle',
	false !== strpos( $wccs_built_js, 'wccs-inspector__panel' )
		&& false !== strpos( $wccs_built_css, 'wccs-inspector__' ),
	'inspector markup and styles present'
);

wccs_proof_check(
	'The statement shown when a type cannot be masked shipped too',
	false !== strpos( $wccs_built_js, 'cannot be masked' ),
	'explanation present'
);

// ---------------------------------------------------------------------------
// 8. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '8. Environment' );

delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_DRAFT ) );
delete_option( \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ) );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Cross-reference',
	'Which controls appear for which declaration is proven by tests/js/components/FieldInspector.test.js (26 specs).'
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
