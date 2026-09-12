<?php
/**
 * WCCS-057 proof harness — the limited ThemeHigh migrator.
 *
 * Task:   WCCS-057 "Criar migrador ThemeHigh limitado"
 * Phase:  F10 · Pedidos, Minha Conta, APIs e privacidade
 * Accept: "Mapeia apenas estruturas comprovadas; unsupported gera relatório e não descarte silencioso."
 *
 * The source plugin is not installed on this store, and that is the first thing asserted:
 * a migrator on a store with no source must do nothing and say so, not produce an empty
 * document that reads as a configured store with no fields. The mapping itself is then
 * exercised with a source shaped the way the structures the adapter records were read —
 * every key it maps appears in that record, and everything outside it is reported.
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
 * The report lines of one kind.
 *
 * @param array<string, mixed> $report Report.
 * @param string               $kind   Kind.
 * @return array<int, array<string, string>>
 */
function wccs_proof_lines( array $report, string $kind ): array {
	$lines = array();

	foreach ( (array) ( $report['unsupported'] ?? array() ) as $line ) {
		if ( is_array( $line ) && $kind === ( $line['kind'] ?? '' ) ) {
			$lines[] = $line;
		}
	}

	return $lines;
}

/**
 * The values of one column of the report lines.
 *
 * @param array<int, array<string, string>> $lines Lines.
 * @param string                            $key   Column.
 * @return array<int, string>
 */
function wccs_proof_column( array $lines, string $key ): array {
	return array_map(
		static function ( array $line ) use ( $key ): string {
			return (string) ( $line[ $key ] ?? '' );
		},
		$lines
	);
}

$wccs_adapter = 'WCCheckoutSuite\\Domain\\Migration\\ThemeHighAdapter';

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-057 proof — the limited migrator' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_options_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" );

// ---------------------------------------------------------------------------
// 1. What the adapter claims to know, and where it read it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A estrutura comprovada' );

wccs_proof_check(
	'Every structure the adapter maps is recorded with the file it was read in',
	count( $wccs_adapter::EVIDENCE ) >= 5
		&& array() === array_filter(
			$wccs_adapter::EVIDENCE,
			static function ( array $line ): bool {
				return '' === (string) ( $line['what'] ?? '' ) || '' === (string) ( $line['where'] ?? '' );
			}
		),
	'entries=' . count( $wccs_adapter::EVIDENCE )
);

wccs_proof_check(
	'And it declares the version those structures were read in',
	'' !== $wccs_adapter::VERIFIED_AGAINST
		&& $wccs_adapter::PLANNING_REFERENCE !== $wccs_adapter::VERIFIED_AGAINST,
	'read in ' . $wccs_adapter::VERIFIED_AGAINST . '; the planning referenced ' . $wccs_adapter::PLANNING_REFERENCE . ' — a mapping about one version is not a mapping about another'
);

wccs_proof_check(
	'The options it reads are the three the source keeps its configuration in',
	array( 'wc_fields_billing', 'wc_fields_shipping', 'wc_fields_additional' ) === array_values( $wccs_adapter::SOURCE_OPTIONS )
);

wccs_proof_check(
	'And every key it maps is a key of that record',
	array() === array_diff(
		array( 'type', 'label', 'required', 'enabled', 'custom', 'show_in_order', 'show_in_email', 'options' ),
		array_keys( $wccs_adapter::SOURCE_KEYS )
	),
	'keys=' . count( $wccs_adapter::SOURCE_KEYS )
);

// ---------------------------------------------------------------------------
// 2. With no source, nothing is invented.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Sem origem, nada é inventado' );

$wccs_read = $wccs_adapter::read();

wccs_proof_check(
	'The source is not present on this store, and the adapter says so',
	false === $wccs_read['present'],
	'the source plugin is not installed here: present=' . wp_json_encode( $wccs_read['present'] )
);

$wccs_empty = $wccs_adapter::migrate( $wccs_read['sections'] );

wccs_proof_check(
	'And produces no fields rather than an empty document pretending to be a configuration',
	array() === $wccs_empty['document']->fields()
		&& 0 === $wccs_empty['report']['counts']['seen'],
	'fields=' . count( $wccs_empty['document']->fields() )
);

// ---------------------------------------------------------------------------
// 3. What maps, and what is reported.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Mapeado ou relatado, nunca descartado' );

$wccs_source = array(
	'billing'    => array(
		'billing_first_name' => array(
			'type'           => 'text',
			'label'          => 'Primeiro nome',
			'required'       => true,
			'enabled'        => 1,
			'custom'         => 0,
			'show_in_order'  => 1,
			'show_in_email'  => 1,
		),
		'billing_document'   => array(
			'type'           => 'text',
			'label'          => 'CPF',
			'required'       => true,
			'enabled'        => 1,
			'custom'         => 1,
			'show_in_order'  => 1,
			'show_in_email'  => 0,
			// A setting this plugin has no equivalent for: it must be reported.
			'placeholder'    => '000.000.000-00',
			// A setting the adapter knows and does not carry.
			'class'          => 'form-row-wide',
			// A setting nobody declared.
			'something_new'  => 'from a later version of the source',
		),
		'billing_person'     => array(
			'type'          => 'select',
			'label'         => 'Tipo de pessoa',
			'required'      => true,
			'custom'        => 1,
			'enabled'       => 1,
			'options'       => (string) wp_json_encode(
				array(
					'pf' => 'Pessoa física',
					'pj' => 'Pessoa jurídica',
				)
			),
			'show_in_order' => 1,
			'show_in_email' => 1,
		),
		'billing_archived'   => array(
			'type'          => 'text',
			'label'         => 'Archived',
			'enabled'       => 0,
			'custom'        => 1,
			'show_in_order' => 1,
		),
		// A type this plugin does not have: it must not become a text box.
		'billing_colour'     => array(
			'type'          => 'color',
			'label'         => 'Cor',
			'custom'        => 1,
			'enabled'       => 1,
			'show_in_order' => 0,
		),
		// An entry that is not a mapping at all.
		'billing_broken'     => 'not an array',
	),
	'additional' => array(
		'order_comments' => array(
			'type'          => 'textarea',
			'label'         => 'Notas do pedido',
			'custom'        => 0,
			'enabled'       => 1,
			'show_in_order' => 1,
			'show_in_email' => 0,
		),
	),
);

$wccs_result = $wccs_adapter::migrate( $wccs_source );
$wccs_report = $wccs_result['report'];
$wccs_counts = $wccs_report['counts'];

// The invariant is about fields and not about lines: a field ends in the mapped list or in
// a report line about the field itself, while the lines about a *setting* inside a field are
// additional to that. The first version compared counts of different things.
$wccs_unmapped_fields = array_values(
	array_unique(
		array_merge(
			wccs_proof_column( wccs_proof_lines( $wccs_report, 'entry' ), 'field' ),
			wccs_proof_column( wccs_proof_lines( $wccs_report, 'type' ), 'field' )
		)
	)
);

wccs_proof_check(
	'Every field the source offers ends in one of the two lists',
	7 === $wccs_counts['seen']
		&& $wccs_counts['seen'] === count( $wccs_report['mapped'] ) + count( $wccs_unmapped_fields ),
	'seen=' . $wccs_counts['seen'] . ' mapped=' . count( $wccs_report['mapped'] ) . ' not mapped=' . wp_json_encode( $wccs_unmapped_fields )
);

$wccs_mapped_ids = array_column( (array) $wccs_report['mapped'], 'id' );

wccs_proof_check(
	'The fields it understands are mapped, with the types they had',
	array( 'wccs_billing_first_name', 'wccs_billing_document', 'wccs_billing_person', 'wccs_billing_archived', 'wccs_order_comments' ) === $wccs_mapped_ids
		&& 'select' === ( $wccs_report['mapped'][2]['type'] ?? '' ),
	'ids=' . wp_json_encode( $wccs_mapped_ids )
);

wccs_proof_check(
	'A type this plugin does not have is reported and is not turned into a text box',
	array( 'color' ) === wccs_proof_column( wccs_proof_lines( $wccs_report, 'type' ), 'value' )
		&& ! in_array( 'wccs_billing_colour', $wccs_mapped_ids, true ),
	'a text box where a select used to be is data lost without being told'
);

wccs_proof_check(
	'An entry that is not a mapping is reported as such',
	in_array( 'billing_broken', wccs_proof_column( wccs_proof_lines( $wccs_report, 'entry' ), 'field' ), true )
);

$wccs_key_lines = wccs_proof_lines( $wccs_report, 'key' );

wccs_proof_check(
	'A setting with no equivalent here is reported beside its field',
	in_array( 'placeholder', wccs_proof_column( $wccs_key_lines, 'value' ), true )
		&& in_array( 'class', wccs_proof_column( $wccs_key_lines, 'value' ), true ),
	'values=' . wp_json_encode( wccs_proof_column( $wccs_key_lines, 'value' ) )
);

wccs_proof_check(
	'And a setting nobody declared is reported too, rather than ignored',
	in_array( 'something_new', wccs_proof_column( $wccs_key_lines, 'value' ), true ),
	'a later version of the source invents a key and this store is told, not quietly stripped'
);

wccs_proof_check(
	'And the flags the source set travel with the field',
	true === ( $wccs_result['document']->to_array()['fields'][1]['visibility']['customer_order'] ?? false )
		&& false === ( $wccs_result['document']->to_array()['fields'][1]['visibility']['customer_email'] ?? true )
		&& false === ( $wccs_result['document']->to_array()['fields'][3]['enabled'] ?? true ),
	'visibility and enabled come from the source, and a disabled field arrives disabled rather than dropped'
);

wccs_proof_check(
	'The sections speak this plugin own location vocabulary',
	array( 'billing', 'order' ) === array_column( $wccs_result['document']->to_array()['sections'], 'location' ),
	'locations=' . wp_json_encode( array_column( $wccs_result['document']->to_array()['sections'], 'location' ) )
);

wccs_proof_check(
	'Choice fields keep their choices',
	2 === count( $wccs_result['document']->to_array()['fields'][2]['settings']['options'] ?? array() ),
	'options=' . wp_json_encode( $wccs_result['document']->to_array()['fields'][2]['settings']['options'] ?? array() )
);

// ---------------------------------------------------------------------------
// 4. The result is a document, and a file the import path already reads.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. O resultado é um documento válido' );

$wccs_repository = new \WCCheckoutSuite\Domain\Schema\SchemaRepository(
	\WCCheckoutSuite\Domain\Registries::instance()->definition_validator(),
	new \WCCheckoutSuite\Domain\Schema\CoreFieldGuard()
);

$wccs_validation = $wccs_repository->validate( $wccs_result['document'] );

wccs_proof_check(
	'What the migration produced is a document the editor would accept',
	$wccs_validation->is_valid(),
	'errors=' . wp_json_encode(
		array_map(
			static function ( $error ): string {
				return is_array( $error ) ? (string) ( $error['code'] ?? '' ) : (string) $error;
			},
			$wccs_validation->errors()
		)
	)
);

$wccs_envelope = $wccs_adapter::envelope( $wccs_result );
$wccs_file     = static function () use ( $wccs_envelope ): string {
	return \WCCheckoutSuite\Domain\Schema\SchemaTransfer::encode( $wccs_envelope );
};

wccs_proof_check(
	'And it becomes the same file the preview and the import already read',
	\WCCheckoutSuite\Domain\Schema\SchemaTransfer::FORMAT === ( $wccs_envelope['format'] ?? '' )
		&& '' !== $wccs_file()
);

$wccs_inspection = \WCCheckoutSuite\Domain\Schema\SchemaTransfer::inspect(
	$wccs_file(),
	\WCCheckoutSuite\Domain\Schema\SchemaDocument::empty()
);

wccs_proof_check(
	'And the import path accepts it, so a migration is previewed before anything is stored',
	true === ( $wccs_inspection['ok'] ?? false ),
	'errors=' . wp_json_encode( array_column( (array) ( $wccs_inspection['errors'] ?? array() ), 'code' ) )
);

wccs_proof_check(
	'And the file says what produced it',
	str_contains( (string) ( $wccs_envelope['exported_from']['migrated_from'] ?? '' ), $wccs_adapter::SOURCE )
		&& str_contains( (string) ( $wccs_envelope['exported_from']['migrated_from'] ?? '' ), $wccs_adapter::VERIFIED_AGAINST ),
	'migrated_from=' . ( $wccs_envelope['exported_from']['migrated_from'] ?? '(none)' )
);

wccs_proof_check(
	'And migrating does not touch the store: nothing is written by reading and mapping',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before
);

wccs_proof_note(
	'What "comprovada" means in this file, and what it does not',
	'The mapping was read in an installation of the source that declares version 2.1.5, and every key it maps is one that installation uses; the record of that reading is in the class, entry by entry, with the file each came from. What is NOT claimed is that the mapping covers version 2.2.0, which the planning referenced from a ZIP: a structure verified in one version is a structure of that version, and the adapter says which. Anything a source of another version carries that this one does not is reported — including a key nobody declared, which is what a later version looks like from here.'
);

wccs_proof_note(
	'What this harness cannot observe',
	'A real migration: the source plugin is not installed on this store, so the mapping is exercised with a source shaped the way the recorded structures describe, and the read path is asserted to find nothing and invent nothing. Running it against a store that has the source installed is a step that needs that store — it is named here rather than simulated.'
);

// ---------------------------------------------------------------------------
// 5. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Environment' );

wccs_proof_check(
	'The harness left no stored option behind',
	(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'wccs\_%'" ) === $wccs_options_before
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
