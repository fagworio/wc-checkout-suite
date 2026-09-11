<?php
/**
 * Condition engine tests.
 *
 * The cases are not written here. They are read from
 * `resources/fixtures/conditions.json`, the same file the JavaScript suite reads,
 * which is what makes the two engines comparable: one authored expectation, two
 * implementations, and a divergence that fails an assertion on one side instead of
 * reaching a store.
 *
 * The groups of cases are the words of the acceptance — empty, zero, false,
 * arrays, context and negations — plus the two shapes a rule can take that the
 * engine has to answer without understanding it.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Domain\Conditions;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Domain\Conditions\TreeConditionEvaluator;
use WCCheckoutSuite\Domain\Fields\FieldContext;

/**
 * Condition engine behaviour, driven by the shared fixtures.
 */
final class TreeConditionEvaluatorTest extends TestCase {

	/**
	 * The shared cases.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fixtures(): array {
		static $cases = null;

		if ( null === $cases ) {
			$path = dirname( __DIR__, 4 ) . '/resources/fixtures/conditions.json';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixture shipped with the plugin; the sniff targets remote URLs.
			$raw  = file_get_contents( $path );
			$data = false === $raw ? null : json_decode( $raw, true );

			if ( ! is_array( $data ) || ! isset( $data['cases'] ) || ! is_array( $data['cases'] ) ) {
				self::fail( 'The shared condition fixtures could not be read from ' . $path );
			}

			$cases = array();

			foreach ( $data['cases'] as $fixture ) {
				$cases[] = (array) $fixture;
			}
		}

		return $cases;
	}

	/**
	 * Every case, named by its fixture name.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function case_provider(): array {
		$provided = array();

		foreach ( self::fixtures() as $fixture ) {
			$provided[ (string) $fixture['group'] . ': ' . (string) $fixture['name'] ] = array( $fixture );
		}

		return $provided;
	}

	/**
	 * The engine answers what the fixture says it answers.
	 *
	 * @dataProvider case_provider
	 *
	 * @param array<string, mixed> $fixture Fixture case.
	 * @return void
	 */
	public function test_the_engine_answers_the_shared_case( array $fixture ): void {
		$engine  = new TreeConditionEvaluator();
		$context = new FieldContext( (array) ( $fixture['context'] ?? array() ), 'classic' );

		self::assertSame(
			(bool) $fixture['expect'],
			$engine->evaluate( (array) $fixture['tree'], $context ),
			(string) ( $fixture['note'] ?? '' )
		);
	}

	/**
	 * The fixtures cover every word of the acceptance.
	 *
	 * A fixture set that quietly lost a group would keep every remaining assertion
	 * green while the parity it exists for went untested.
	 *
	 * @return void
	 */
	public function test_the_fixtures_cover_the_acceptance(): void {
		$groups = array();

		foreach ( self::fixtures() as $fixture ) {
			$groups[ (string) $fixture['group'] ] = true;
		}

		ksort( $groups );

		self::assertSame(
			array(
				'comparison' => true,
				'context'    => true,
				'empty'      => true,
				'false'      => true,
				'groups'     => true,
				'lists'      => true,
				'membership' => true,
				'negation'   => true,
				'unreadable' => true,
				'zero'       => true,
			),
			$groups
		);
	}

	/**
	 * Every source and every operator of the vocabulary is exercised.
	 *
	 * The fixture file lists the vocabulary it was written against, and this is
	 * where the list is held to it: a source or an operator the fixtures never use
	 * is a source or an operator whose parity is asserted nowhere.
	 *
	 * @return void
	 */
	public function test_every_operator_and_source_appears_in_a_case(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixture shipped with the plugin; the sniff targets remote URLs.
		$raw = file_get_contents( dirname( __DIR__, 4 ) . '/resources/fixtures/conditions.json' );

		$data = false === $raw ? array() : json_decode( $raw, true );

		$used_operators = array();
		$used_sources   = array();

		$collect = function ( array $node ) use ( &$collect, &$used_operators, &$used_sources ): void {
			if ( isset( $node['source'], $node['operator'] ) ) {
				$used_sources[ (string) $node['source'] ]     = true;
				$used_operators[ (string) $node['operator'] ] = true;
			}

			foreach ( (array) ( $node['all'] ?? $node['any'] ?? array() ) as $child ) {
				$collect( (array) $child );
			}
		};

		foreach ( (array) ( $data['cases'] ?? array() ) as $fixture ) {
			$collect( (array) $fixture['tree'] );
		}

		$missing_operators = array_values( array_diff( (array) $data['vocabulary']['operators'], array_keys( $used_operators ) ) );
		$missing_sources   = array_values( array_diff( (array) $data['vocabulary']['sources'], array_keys( $used_sources ) ) );

		self::assertSame( array(), $missing_operators, 'operators with no case' );
		self::assertSame( array(), $missing_sources, 'sources with no case' );
	}
}
