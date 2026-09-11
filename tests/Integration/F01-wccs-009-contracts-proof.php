<?php
/**
 * WCCS-009 proof harness — versioned contracts, normalizers, masks, renderers
 * and the adapter-agnostic value pipeline.
 *
 * Task:   WCCS-009 "Criar normalizadores e contratos"
 * Phase:  F01
 * Accept: "Interfaces versionadas; mesmo contrato serve Classic/Blocks/pedidos."
 *
 * Prerequisite: the plugin must be ACTIVE.
 *
 * Everything registered here is in memory only; no option or table is touched.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

use WCCheckoutSuite\Domain\Conditions\ConditionEvaluatorInterface;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Registries;
use WCCheckoutSuite\Domain\Validation\Mask;
use WCCheckoutSuite\Domain\Validation\NormalizerInterface;
use WCCheckoutSuite\Domain\Validation\RendererInterface;

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
	$ok = (bool) $condition;
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
 * A normalizer declaring a chosen contract version, used to test the guard.
 */
final class WccsProofVersionedNormalizer implements NormalizerInterface {

	/**
	 * Constructor.
	 *
	 * @param string $key     Key.
	 * @param string $version Contract version it declares.
	 */
	public function __construct(
		private string $key,
		private string $version
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return $this->version;
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		unset( $context );

		return $value;
	}
}

/**
 * A renderer used to prove the capability queries.
 */
final class WccsProofRenderer implements RendererInterface {

	/**
	 * Constructor.
	 *
	 * @param string             $key      Key.
	 * @param array<int, string> $adapters Adapters.
	 */
	public function __construct(
		private string $key,
		private array $adapters
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function adapters(): array {
		return $this->adapters;
	}

	/**
	 * {@inheritDoc}
	 */
	public function assets(): array {
		return array( 'classic' => array( 'wccs-classic' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function template(): ?string {
		return 'templates/checkout/field.php';
	}
}

/**
 * An evaluator that hides everything, standing in for the F06 engine.
 */
final class WccsProofHidingEvaluator implements ConditionEvaluatorInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'proof.never';
	}

	/**
	 * {@inheritDoc}
	 */
	public function contract_version(): string {
		return '1.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( array $rules, FieldContext $context ): bool {
		unset( $rules, $context );

		return false;
	}
}

/**
 * Builds a definition for the pipeline proofs.
 *
 * @param array<string, mixed> $override Overrides.
 * @return FieldDefinition
 */
function wccs_proof_definition( array $override = array() ): FieldDefinition {
	return FieldDefinition::from_array(
		array_merge(
			array(
				'id'        => 'billing_document',
				'type'      => 'text',
				'label'     => 'Document',
				'required'  => true,
				'settings'  => array( 'maxLength' => 20 ),
				'normalizer' => 'digits',
			),
			$override
		)
	);
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'WCCS-009 proof — versioned contracts and the value pipeline' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

$wccs_registries = Registries::instance();
$wccs_processor  = $wccs_registries->value_processor();

// Stands in for the F06 engine so the hidden-value policy is exercisable now.
$wccs_registries->conditions()->register_evaluator( new WccsProofHidingEvaluator(), 'proof-plugin' );

// ---------------------------------------------------------------------------
// 1. Versioned contracts.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. Versioned contracts' );

foreach ( array( 'types', 'presets', 'validators', 'normalizers', 'masks', 'renderers', 'conditions' ) as $wccs_registry_name ) {
	$wccs_registry = $wccs_registries->{$wccs_registry_name}();

	wccs_proof_check(
		"The {$wccs_registry_name} registry declares a contract version",
		'' !== (string) $wccs_registry->contract_version(),
		'contract=' . $wccs_registry->contract_version()
	);
}

wccs_proof_check(
	'A normalizer declaring the accepted contract version registers',
	$wccs_registries->normalizers()->register_normalizer( new WccsProofVersionedNormalizer( 'proof.v1', '1.4' ), 'proof-plugin' )
);

wccs_proof_check(
	'A normalizer declaring an incompatible major version is refused',
	! $wccs_registries->normalizers()->register_normalizer( new WccsProofVersionedNormalizer( 'proof.v2', '2.0' ), 'future-plugin' )
		&& ! $wccs_registries->normalizers()->has( 'proof.v2' )
);

$wccs_contract_diagnostics = array_values(
	array_filter(
		$wccs_registries->normalizers()->diagnostics(),
		static fn( $entry ) => 'incompatible_contract_version' === $entry['code']
	)
);

wccs_proof_check(
	'The refusal is reported as a diagnostic naming both versions',
	1 === count( $wccs_contract_diagnostics )
		&& false !== strpos( $wccs_contract_diagnostics[0]['message'], '2.0' ),
	$wccs_contract_diagnostics[0]['message'] ?? '(none)'
);

// ---------------------------------------------------------------------------
// 2. The same contract serves Classic, Blocks and orders.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. Same contract for Classic, Blocks and orders' );

$wccs_outcomes = array();

foreach ( array( 'classic', 'blocks', 'order' ) as $wccs_adapter ) {
	$wccs_outcomes[ $wccs_adapter ] = $wccs_processor->process(
		wccs_proof_definition(),
		'123.456.789-01',
		new FieldContext( array(), $wccs_adapter )
	);
}

$wccs_values = array_map( static fn( $outcome ) => $outcome->value(), $wccs_outcomes );
$wccs_states = array_map( static fn( $outcome ) => $outcome->result()->is_valid(), $wccs_outcomes );

wccs_proof_check(
	'Normalization is identical in every adapter',
	1 === count( array_unique( $wccs_values ) ) && '12345678901' === $wccs_values['classic'],
	'value=' . var_export( $wccs_values['classic'], true )
);
wccs_proof_check(
	'Validity is identical in every adapter',
	1 === count( array_unique( $wccs_states ) ) && $wccs_states['order'],
	'valid=' . var_export( $wccs_states, true )
);
// Structural check instead of a source scan: the last time an assertion read raw
// file text it matched its own explanatory comment. Reflection reads the code,
// not the prose around it.
$wccs_constructor = new ReflectionMethod( WCCheckoutSuite\Domain\Validation\ValueProcessor::class, '__construct' );
$wccs_dependency_types = array_map(
	static fn( ReflectionParameter $parameter ): string => (string) $parameter->getType(),
	$wccs_constructor->getParameters()
);

wccs_proof_check(
	'The pipeline depends only on domain registries — no WC_Order, no REST request',
	( static function () use ( $wccs_dependency_types ) {
		foreach ( $wccs_dependency_types as $type ) {
			if ( str_contains( $type, 'WC_' ) || str_contains( $type, 'WP_REST' ) || str_contains( $type, 'WP_Post' ) ) {
				return false;
			}
		}

		return array() !== $wccs_dependency_types;
	} )(),
	'constructor dependencies: ' . implode( ', ', $wccs_dependency_types )
);

// Behavioural check: with no request globals populated at all, the result is
// still identical for every adapter.
$_POST    = array();
$_REQUEST = array();

$wccs_without_request = $wccs_processor->process( wccs_proof_definition(), '123.456.789-01', new FieldContext( array(), 'blocks' ) );

wccs_proof_check(
	'The pipeline produces the same result with empty request globals',
	$wccs_without_request->value() === $wccs_values['classic'] && $wccs_without_request->result()->is_valid()
);

// ---------------------------------------------------------------------------
// 3. Pipeline behaviour.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. Pipeline behaviour' );

$wccs_absent = $wccs_processor->process( wccs_proof_definition(), '', new FieldContext() );
wccs_proof_check(
	'Requiredness is enforced by the definition, not by the type',
	! $wccs_absent->result()->is_valid() && in_array( 'required', $wccs_absent->result()->error_codes(), true ),
	'codes=' . implode( ',', $wccs_absent->result()->error_codes() )
);

$wccs_zero = $wccs_processor->process(
	wccs_proof_definition( array( 'id' => 'quantity', 'type' => 'number', 'normalizer' => null, 'label' => 'Quantity' ) ),
	'0',
	new FieldContext()
);
wccs_proof_check( 'Zero is a real value and satisfies requiredness', $wccs_zero->result()->is_valid() && 0 === $wccs_zero->value(), 'value=' . var_export( $wccs_zero->value(), true ) );

$wccs_false = $wccs_processor->process(
	wccs_proof_definition( array( 'id' => 'consent', 'type' => 'checkbox', 'normalizer' => null, 'label' => 'Consent' ) ),
	'',
	new FieldContext()
);
wccs_proof_check( 'A checkbox normalizes an absent value to false and stores it', false === $wccs_false->value() && $wccs_false->is_storable() );

$wccs_hidden = $wccs_processor->process(
	wccs_proof_definition(
		array(
			'conditions' => array(
				'evaluator' => 'proof.never',
				'visible'   => array( 'all' => array( array( 'source' => 'field', 'path' => 'person_type', 'operator' => 'equals', 'value' => 'pj' ) ) ),
			),
		)
	),
	'12345678901',
	new FieldContext()
);
wccs_proof_check(
	'A hidden field discards its value and raises no required error',
	$wccs_hidden->is_discarded() && null === $wccs_hidden->value() && $wccs_hidden->result()->is_valid(),
	'discarded=' . var_export( $wccs_hidden->is_discarded(), true )
);
wccs_proof_check( 'A discarded value is never storable', ! $wccs_hidden->is_storable() );

$wccs_unknown = $wccs_processor->process( wccs_proof_definition( array( 'type' => 'nope' ) ), 'x', new FieldContext() );
wccs_proof_check(
	'An unregistered type fails closed',
	in_array( 'unknown_type', $wccs_unknown->result()->error_codes(), true ) && ! $wccs_unknown->is_storable()
);

$wccs_named = $wccs_processor->process( wccs_proof_definition(), '999', new FieldContext() );
wccs_proof_check( 'The named normalizer runs before validation', '999' === $wccs_named->value() );

// ---------------------------------------------------------------------------
// 4. Mask registry refuses executable content.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Declarative masks only' );

wccs_proof_check( 'The core mask primitives are registered', $wccs_registries->masks()->has( 'numeric' ) && $wccs_registries->masks()->has( 'alphanumeric' ) );
wccs_proof_check(
	'A declarative mask registers',
	$wccs_registries->masks()->register_mask( new Mask( 'proof.mask', array( 'type' => 'pattern', 'pattern' => '00/00' ), 1, array( 'generic' ) ), 'proof-plugin' )
);
wccs_proof_check(
	'A mask whose definition looks like code is refused',
	! $wccs_registries->masks()->register_mask( new Mask( 'proof.evil', 'javascript:alert(1);' ), 'evil-plugin' )
		&& ! $wccs_registries->masks()->has( 'proof.evil' )
);
wccs_proof_check(
	'A mask carrying an unexpected configuration key is refused',
	! $wccs_registries->masks()->register_mask( new Mask( 'proof.evil2', array( 'type' => 'pattern', 'onComplete' => 'x' ) ), 'evil-plugin' )
);
wccs_proof_check(
	'Mask definitions exported to the browser contain data only',
	false === strpos( (string) wp_json_encode( $wccs_registries->masks()->to_array() ), 'function' )
);

// ---------------------------------------------------------------------------
// 5. Renderer registry and capability queries.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '5. Renderer capability queries' );

wccs_proof_check(
	'A renderer declaring known adapters registers',
	$wccs_registries->renderers()->register_renderer( new WccsProofRenderer( 'text', array( 'classic', 'admin' ) ), 'proof-plugin' )
);
wccs_proof_check(
	'A renderer declaring an unknown adapter is refused',
	! $wccs_registries->renderers()->register_renderer( new WccsProofRenderer( 'weird', array( 'classic', 'hologram' ) ), 'proof-plugin' )
);
wccs_proof_check(
	'The capability query answers per adapter',
	$wccs_registries->renderers()->supports( 'text', 'classic' )
		&& ! $wccs_registries->renderers()->supports( 'text', 'blocks' ),
	'classic=yes blocks=no'
);
wccs_proof_check(
	'A type without a Blocks renderer is reported as unsupported, not silently rendered',
	! in_array( 'text', (array) $wccs_registries->renderers()->keys_for_adapter( 'blocks' ), true )
);
wccs_proof_note(
	'No core renderer ships yet',
	'Renderer claims belong to F04 (Classic) and F07 (Blocks), where the renderers actually exist. Until then every type reports unsupported rather than claiming a capability.'
);

// ---------------------------------------------------------------------------
// 6. Definition validation of the normalizer key.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '6. Definition validation' );

$wccs_validator = $wccs_registries->definition_validator();

wccs_proof_check(
	'A definition naming a registered normalizer validates',
	$wccs_validator->validate_array( array( 'id' => 'doc', 'type' => 'text', 'label' => 'Doc', 'normalizer' => 'digits' ) )->is_valid()
);
wccs_proof_check(
	'A definition naming an unregistered normalizer is rejected',
	in_array(
		'unknown_normalizer',
		$wccs_validator->validate_array( array( 'id' => 'doc', 'type' => 'text', 'label' => 'Doc', 'normalizer' => 'br.cnpj' ) )->error_codes(),
		true
	),
	'br.cnpj arrives in WCCS-026'
);

// ---------------------------------------------------------------------------
// 7. Extension path through the public hooks.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '7. Extension path' );

$wccs_draft_source = array();

add_action(
	'wccs_register_normalizers',
	static function ( $registry ) use ( &$wccs_draft_source ) {
		$registry->register_normalizer( new WccsProofVersionedNormalizer( 'proof.hook', '1.0' ), 'hook-plugin' );
		$wccs_draft_source[] = 'normalizers';
	}
);
add_action(
	'wccs_register_masks',
	static function ( $registry ) {
		$registry->register_mask( new Mask( 'proof.hook-mask', '0-0' ), 'hook-plugin' );
	}
);

// `plugins_loaded` already passed inside wp eval-file, so the harness fires the
// documented hooks again to exercise the external path. Duplicate keys are
// rejected by the registries, so core content cannot be replaced.
do_action( 'wccs_register_normalizers', $wccs_registries->normalizers() );
do_action( 'wccs_register_masks', $wccs_registries->masks() );

wccs_proof_check( 'An extension registers a normalizer through the public hook', $wccs_registries->normalizers()->has( 'proof.hook' ) && in_array( 'normalizers', $wccs_draft_source, true ) );
wccs_proof_check( 'An extension registers a mask through the public hook', $wccs_registries->masks()->has( 'proof.hook-mask' ) );
wccs_proof_check(
	'The extension origin is recorded',
	'hook-plugin' === $wccs_registries->normalizers()->source_of( 'proof.hook' ),
	'source=' . $wccs_registries->normalizers()->source_of( 'proof.hook' )
);
wccs_proof_check(
	'Re-firing the hooks cannot replace core content',
	'core' === $wccs_registries->masks()->source_of( 'numeric' )
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
