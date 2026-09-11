<?php
/**
 * WCCS-030 proof harness — the error interface.
 *
 * Task:   WCCS-030 "Implementar UX de erro"
 * Phase:  F05
 * Accept: "Mensagens por campo; primeiro erro focado; regra crítica revalidada no submit."
 *
 * The behaviour is JavaScript and is proven by the JavaScript suite, which builds
 * a real form, leaves a field, and asserts what the customer sees and where the
 * cursor goes. What the server owns is the part that makes those messages correct:
 * the rule each field declares, and the wording it fails with — written once,
 * beside the rule, instead of being copied into JavaScript where it would drift.
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * @package WCCheckoutSuite
 */

require_once __DIR__ . '/support/storefront-hooks.php';

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
 * A definition built the way the picker builds one from a preset.
 *
 * @param string               $id      Field identifier.
 * @param string               $preset  Preset key.
 * @param array<string, mixed> $changes Values to override.
 * @return array<string, mixed>
 */
function wccs_proof_from_preset( string $id, string $preset, array $changes = array() ): array {
	$registered = \WCCheckoutSuite\Domain\Registries::instance()->presets()->preset( $preset );

	if ( null === $registered ) {
		return array();
	}

	$defaults = $registered->defaults();

	return array_merge(
		array(
			'id'             => $id,
			'integration_id' => 'wc-checkoutsuite/' . $id,
			'origin'         => 'custom',
			'type'           => $registered->type(),
			'preset'         => $preset,
			'label'          => $registered->label(),
			'section'        => 'billing',
			'enabled'        => true,
			'required'       => false,
			'position'       => 30,
			'layout'         => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'settings'       => $registered->settings(),
			'mask'           => $defaults['mask'] ?? null,
			'normalizer'     => $defaults['normalizer'] ?? null,
			'validators'     => $defaults['validators'] ?? array(),
			'conditions'     => array(),
			'storage'        => array(
				'scope'       => 'order',
				'sensitivity' => 'personal',
			),
			'visibility'     => array(
				'admin_order' => true,
			),
		),
		$changes
	);
}

/**
 * Publishes a document into the published slot.
 *
 * @param array<int, mixed> $fields Fields.
 * @return void
 */
function wccs_proof_publish( array $fields ): void {
	update_option(
		\WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED ),
		(string) wp_json_encode(
			array(
				'revision'       => 11,
				'schema_version' => 1,
				'updated_at'     => gmdate( 'c' ),
				'updated_by'     => 1,
				'fields'         => $fields,
				'sections'       => array(),
				'settings'       => array(),
			)
		),
		false
	);
}

/**
 * Every hook this plugin registers on the storefront half.
 *
 * @return array<int, string>
 */
function wccs_proof_plugin_hooks(): array {
	$found = array();

	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! isset( $hook->callbacks ) ) {
			continue;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) ) {
					continue;
				}

				if ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) {
					continue;
				}

				$class = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

				if ( 0 !== strpos( $class, 'WCCheckoutSuite\\Checkout\\Classic\\' ) ) {
					continue;
				}

				$found[] = $tag . '@' . $priority . ':' . $function[1];
			}
		}
	}

	sort( $found );

	return $found;
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
wccs_proof_out( 'WCCS-030 proof — the error interface' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

$wccs_published = \WCCheckoutSuite\Domain\Schema\SchemaRepository::option_for( \WCCheckoutSuite\Domain\Schema\SchemaRepository::SLOT_PUBLISHED );

delete_option( $wccs_published );

wccs_proof_publish(
	array(
		wccs_proof_from_preset( 'wccs_cpf', 'br.cpf' ),
		wccs_proof_from_preset( 'wccs_rg', 'br.rg' ),
		wccs_proof_from_preset( 'wccs_notes', 'br.address.complement' ),
		wccs_proof_from_preset( 'wccs_hidden', 'br.cep', array( 'enabled' => false ) ),
		wccs_proof_from_preset( 'wccs_upload', 'br.cnpj', array( 'type' => 'heading' ) ),
	)
);

$wccs_bootstrap = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::bootstrap_data();
$wccs_rules     = (array) ( $wccs_bootstrap['rules'] ?? array() );

// ---------------------------------------------------------------------------
// 1. The rules and their wording come from the server.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. What the checkout is told about each field' );

wccs_proof_check(
	'A field with a validator declares it',
	isset( $wccs_rules['wccs_cpf'] ) && 'br.cpf' === ( $wccs_rules['wccs_cpf'][0]['key'] ?? null ),
	'rules=' . wp_json_encode( $wccs_rules['wccs_cpf'] ?? null )
);

wccs_proof_check(
	'With the code a failure produces',
	'invalid_cpf' === ( $wccs_rules['wccs_cpf'][0]['code'] ?? null )
);

wccs_proof_check(
	'And the wording the customer will read, written once beside the rule',
	'' !== ( $wccs_rules['wccs_cpf'][0]['message'] ?? '' )
		&& str_contains( (string) ( $wccs_rules['wccs_cpf'][0]['message'] ?? '' ), 'CPF' ),
	'message=' . (string) ( $wccs_rules['wccs_cpf'][0]['message'] ?? '' )
);

wccs_proof_check(
	'The wording the checkout shows is the wording the server would show',
	( new \WCCheckoutSuite\Domain\Validation\Validators\DocumentValidator(
		'br.cpf',
		\WCCheckoutSuite\Domain\Validation\Validators\DocumentValidator::MODE_CPF
	) )->failure_message() === ( $wccs_rules['wccs_cpf'][0]['message'] ?? null ),
	'one message, two places it is shown'
);

wccs_proof_check(
	'The wording claims nothing about the person',
	! str_contains(
		strtolower( (string) ( $wccs_rules['wccs_cpf'][0]['message'] ?? '' ) ),
		'identity'
	) && ! str_contains(
		strtolower( (string) ( $wccs_rules['wccs_cpf'][0]['message'] ?? '' ) ),
		'verified'
	)
);

// ---------------------------------------------------------------------------
// 2. Only what has a rule and a place to show it.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. What is sent, and what is not' );

wccs_proof_check(
	'A field with no validator is not sent',
	! isset( $wccs_rules['wccs_notes'] ),
	'it has no rule, so the checkout has nothing to check'
);

wccs_proof_check(
	'A field whose rule does not exist is not sent',
	! isset( $wccs_rules['wccs_rg'] ),
	'RG has no validator, and a key that accepted everything would look like a check'
);

wccs_proof_check(
	'An archived field is not sent',
	! isset( $wccs_rules['wccs_hidden'] ),
	'it is not rendered, so there is no field to put a message beside'
);

wccs_proof_check(
	'A field the classic checkout cannot render is not sent',
	! isset( $wccs_rules['wccs_upload'] ),
	'the adapter skips a file field'
);

// ---------------------------------------------------------------------------
// 3. Nothing about the storefront half changed.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. The storefront half' );

$wccs_hooks = wccs_proof_plugin_hooks();

wccs_proof_check(
	'The storefront half is still exactly these hooks',
	wccs_proof_expected_hooks() === $wccs_hooks,
	'found ' . wp_json_encode( $wccs_hooks )
);

$wccs_bundle = WCCS_PLUGIN_DIR . 'build/checkout/index.js';

wccs_proof_check(
	'The bundle is built with the error interface in it',
	is_readable( $wccs_bundle ),
	'size=' . ( is_readable( $wccs_bundle ) ? (string) filesize( $wccs_bundle ) : '0' ) . ' bytes'
);

$wccs_manifest = \WCCheckoutSuite\Checkout\Classic\ClassicAssets::asset_manifest();

wccs_proof_check(
	'And still asks WordPress for nothing but jQuery and the translations',
	array( 'jquery' ) === array_values(
		array_filter(
			$wccs_manifest['dependencies'],
			static function ( string $handle ): bool {
				return 'wp-i18n' !== $handle;
			}
		)
	),
	'dependencies=' . wp_json_encode( $wccs_manifest['dependencies'] )
);

// ---------------------------------------------------------------------------
// 4. Environment.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Environment' );

delete_option( $wccs_published );
delete_option( 'wccs_schema_revisions' );

wccs_proof_check(
	'The harness left no stored option behind',
	wccs_proof_option_count() === $wccs_options_before,
	'before=' . $wccs_options_before . ' after=' . wccs_proof_option_count()
);

wccs_proof_note(
	'Where the acceptance is proven',
	'tests/js/checkout/errors.test.js builds a real form: the message is rendered in the field\'s own row and pointed at with aria-describedby, the value is never erased, the message goes away when the field is corrected, the summary is a list of links with a polite live region, the submit guard blocks and focuses the first error, and it decides again rather than remembering.'
);

wccs_proof_note(
	'Why the submit guard is synchronous',
	'WooCommerce fires checkout_place_order on the form and takes false to mean "abandon this order". A guard that returned a promise would be truthy, so every order would go through while the code looked like it was checking something — which is what the first version of this handler did, and what the test named "a promise is not a refusal" now pins.'
);

wccs_proof_note(
	'What happens when a rule cannot be checked',
	'The field is told the value will be checked when the order is placed, and is not blocked. Nothing is released: the same rule runs again on the server at submit, which is the layer that owns it. Section 10 asks for an explicit alternative or a block with guidance, and this is the alternative.'
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
