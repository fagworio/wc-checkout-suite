<?php
/**
 * The extension contract document, checked against the code.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * A document that names the contracts is a document that drifts.
 *
 * This test is the reason the document can be trusted: it reads every hook the plugin publishes out
 * of `src/` and requires each one to be named in `docs/api/extension-contracts.md`, and it requires
 * every `wccs_`-prefixed hook named in the document to exist in the code. A hook added without
 * documentation fails here, and a hook documented after it was removed fails here too — which is the
 * only way a developer document stays true in a repository nobody reads twice.
 */
final class ExtensionContractsTest extends TestCase {

	/**
	 * The document.
	 */
	private const DOC = 'docs/api/extension-contracts.md';

	/**
	 * The plugin source, concatenated once.
	 *
	 * @return string
	 */
	private function source(): string {
		$root  = dirname( __DIR__, 3 );
		$code  = '';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );

		foreach ( $files as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source of this plugin; the sniff targets remote URLs.
				$code .= (string) file_get_contents( $file->getPathname() );
			}
		}

		return $code;
	}

	/**
	 * The document.
	 *
	 * @return string
	 */
	private function doc(): string {
		$path = dirname( __DIR__, 3 ) . '/' . self::DOC;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local document of this plugin; the sniff targets remote URLs.
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Every hook this plugin publishes, read out of the code.
	 *
	 * @return array<int, string>
	 */
	private function published(): array {
		$source = $this->source();
		$found  = array();

		preg_match_all( "/(?:do_action|apply_filters)\(\s*'([a-z_]+)'/", $source, $matches );

		foreach ( $matches[1] as $hook ) {
			if ( str_starts_with( (string) $hook, 'wccs_' ) ) {
				$found[] = (string) $hook;
			}
		}

		// Hooks named through a constant are published too. Only the constants this codebase
		// uses for hook names are read — the naming convention is what tells a hook from an
		// option — because a constant holding an option name is a name, not a contract.
		preg_match_all( "/const\s+([A-Z_]*(?:HOOK|ACTION|FILTER)[A-Z_]*)\s*=\s*'(wccs_[a-z_]+)'/", $source, $constants );

		// Group 2 is the value: the constant's name is not the hook, and reading group 1 was
		// how the first version of this test made every documented hook look phantom.
		foreach ( $constants[2] as $hook ) {
			$found[] = (string) $hook;
		}

		sort( $found );

		return array_values( array_unique( $found ) );
	}

	/**
	 * The document exists and is not empty.
	 *
	 * @return void
	 */
	public function test_the_document_exists(): void {
		self::assertNotSame( '', $this->doc(), self::DOC . ' is readable' );
	}

	/**
	 * Every published hook is documented.
	 *
	 * @return void
	 */
	public function test_every_published_hook_is_documented(): void {
		$doc     = $this->doc();
		$missing = array();

		foreach ( $this->published() as $hook ) {
			if ( ! str_contains( $doc, $hook ) ) {
				$missing[] = $hook;
			}
		}

		self::assertSame( array(), $missing, 'a published hook nobody documented is a contract nobody can use' );
	}

	/**
	 * Every documented hook is published.
	 *
	 * @return void
	 */
	public function test_every_documented_hook_is_published(): void {
		$published = $this->published();
		$doc       = $this->doc();
		$phantom   = array();

		// Only the rows that document a hook *as a hook* are read: the document also names
		// options, meta keys and table prefixes, and a name that is a valid option is not a
		// promise about a hook. A row is about a hook when it shows the call.
		foreach ( explode( "\n", $doc ) as $line ) {
			if ( ! str_contains( $line, 'do_action(' ) && ! str_contains( $line, 'apply_filters(' ) ) {
				continue;
			}

			preg_match_all( '/`(wccs_[a-z_]+)`/', $line, $matches );

			foreach ( array_unique( $matches[1] ) as $hook ) {
				if ( ! in_array( (string) $hook, $published, true ) ) {
					$phantom[] = (string) $hook;
				}
			}
		}

		self::assertSame( array(), $phantom, 'a documented hook the plugin does not publish is a promise nobody keeps' );
	}

	/**
	 * The five clauses of the acceptance are answered.
	 *
	 * @return void
	 */
	public function test_the_acceptance_clauses_are_answered(): void {
		$doc = $this->doc();

		foreach ( array( '## 1. Ordem', '## 2. Assinaturas', '## 3. Versão', '## 4. Depreciação', '## 5. Componente React demonstrado' ) as $clause ) {
			self::assertStringContainsString( $clause, $doc, 'the document answers ' . $clause );
		}

		// The React clause is not a paragraph: it names the registration call the client publishes.
		self::assertStringContainsString( 'window.wccsBlocksFields.register', $doc );

		// And it is published by the bundle, not only documented.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundle source of this plugin; the sniff targets remote URLs.
		$bundle = (string) file_get_contents( dirname( __DIR__, 3 ) . '/resources/blocks/index.js' );

		self::assertStringContainsString( 'wccsBlocksFields', $bundle );
	}
}
