<?php
/**
 * Turning a name into a key.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Support;

/**
 * The one place a name becomes an identifier.
 *
 * Three definitions in this plugin need a key derived from something a merchant typed — an order
 * status, a workflow, a checkout profile — and all three need the **same** answer, because a key is
 * what the store records and what an order is later read back by. Two rules were learned the hard
 * way and both live here now.
 *
 * 1. **The accents are folded by a table, not by WordPress.** `remove_accents()` exists only in
 *    PHP; the editor proposes identifiers in the browser, and a rule that produced one key in the
 *    browser and another on the server would show a merchant a key their definition does not have.
 *    The table covers the letters an identifier in this store's languages can hold, and anything
 *    outside it is not a letter an identifier may keep — the caller's pattern turns it into a
 *    separator.
 * 2. **A byte-oriented pattern is not a character-oriented one.** `preg_replace( '/[^a-z0-9]+/', … )`
 *    without `/u` walks bytes, so `í` became two separators and "Produtos químicos" became
 *    `produtos_qu_micos`. The fold happens first here for that reason, and the pattern afterwards
 *    only ever sees ASCII.
 *
 * @see \ROADMAP.md sections 12.6, 13.2
 */
final class Slug {

	/**
	 * The letters this store's languages use, folded to their plain form.
	 *
	 * @var array<string, string>
	 */
	private const FOLD = array(
		'á' => 'a',
		'à' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'Á' => 'A',
		'À' => 'A',
		'Â' => 'A',
		'Ã' => 'A',
		'Ä' => 'A',
		'Å' => 'A',
		'ç' => 'c',
		'Ç' => 'C',
		'é' => 'e',
		'è' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'É' => 'E',
		'È' => 'E',
		'Ê' => 'E',
		'Ë' => 'E',
		'í' => 'i',
		'ì' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'Í' => 'I',
		'Ì' => 'I',
		'Î' => 'I',
		'Ï' => 'I',
		'ñ' => 'n',
		'Ñ' => 'N',
		'ó' => 'o',
		'ò' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'Ó' => 'O',
		'Ò' => 'O',
		'Ô' => 'O',
		'Õ' => 'O',
		'Ö' => 'O',
		'ú' => 'u',
		'ù' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'Ú' => 'U',
		'Ù' => 'U',
		'Û' => 'U',
		'Ü' => 'U',
		'ý' => 'y',
		'ÿ' => 'y',
		'Ý' => 'Y',
	);

	/**
	 * Folds accents without leaving the ASCII range.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( string $text ): string {
		return strtr( $text, self::FOLD );
	}

	/**
	 * A key from a name: lowercase, folded, separated and trimmed.
	 *
	 * @param string $name Name.
	 * @param string $fallback Key to use when the name holds nothing usable.
	 * @return string
	 */
	public static function key( string $name, string $fallback = 'item' ): string {
		$key = strtolower( self::fold( $name ) );
		$key = (string) preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( $key, '_' );

		return '' === $key ? $fallback : $key;
	}
}
