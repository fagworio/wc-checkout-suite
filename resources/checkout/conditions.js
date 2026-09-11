/**
 * Condition engine, browser half.
 *
 * The same tree the server evaluates, answered in the page, with the same
 * answers. Every decision in `TreeConditionEvaluator` is repeated here
 * deliberately and none of them is left to the language:
 *
 * - `0`, `'0'` and `false` are values and `''`, `null` and `[]` are not, so
 *   emptiness is a list of shapes rather than a truthiness test;
 * - a boolean is compared as `1` or `0` because `String( true )` is `'true'`
 *   while PHP's cast gives `'1'`;
 * - a number is a number only when it matches the same pattern PHP uses, since
 *   `Number( '0x1A' )` is 26 and `is_numeric( '0x1A' )` is false;
 * - anything the engine cannot read — an unreadable tree, an empty group, a
 *   source or operator outside the vocabulary — answers "matches", because the
 *   dangerous answer is the one that removes a field from the checkout.
 *
 * `tests/js/checkout/conditions.test.js` reads `resources/fixtures/conditions.json`,
 * the same file the PHP suite reads, so a divergence fails an assertion on one
 * side instead of reaching a store.
 *
 * @see src/Domain/Conditions/TreeConditionEvaluator.php
 * @see ROADMAP.md section 11
 */

/**
 * The vocabularies, mirrored from `Sources` and `Operators`.
 *
 * Read-only copies of what the server publishes. They exist so the engine can
 * tell "an operator I know" from "an operator I do not", which is the difference
 * between answering about a rule and refusing to hide a field over one.
 */
const OPERATORS = {
	equals: true,
	not_equals: true,
	contains: true,
	not_contains: true,
	greater_than: true,
	less_than: true,
	is_empty: true,
	is_not_empty: true,
	in: true,
	not_in: true,
};

/**
 * Source keys the engine can read.
 *
 * @type {Record<string, boolean>}
 */
const SOURCES = {
	field: true,
	country: true,
	state: true,
	shipping_method: true,
	payment_method: true,
	customer_logged_in: true,
	cart_items: true,
	cart_categories: true,
	cart_total: true,
};

/**
 * Pattern a value has to match to count as a number.
 *
 * The same one PHP uses. Hexadecimal, `Infinity` and `NaN` spellings convert
 * differently in the two languages, and a comparison that depends on which
 * language is asking is not a comparison.
 */
const NUMBER_PATTERN = /^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/;

/**
 * Whether a value counts as absent.
 *
 * @param {any} value Value.
 * @return {boolean} Whether it is empty.
 */
function isEmpty( value ) {
	if ( null === value || undefined === value || '' === value ) {
		return true;
	}

	return Array.isArray( value ) && 0 === value.length;
}

/**
 * A value as text, with the shapes that differ between the two languages fixed.
 *
 * @param {any} value Value.
 * @return {string} Text.
 */
function asText( value ) {
	if ( true === value ) {
		return '1';
	}

	if ( false === value ) {
		return '0';
	}

	if ( null === value || undefined === value ) {
		return '';
	}

	if ( Array.isArray( value ) ) {
		return value.map( asText ).join( ',' );
	}

	return String( value );
}

/**
 * A value as a list of text.
 *
 * @param {any} value Value.
 * @return {string[]} List.
 */
function asList( value ) {
	return ( Array.isArray( value ) ? value : [ value ] ).map( asText );
}

/**
 * A value as a number, or null when it is not written as one.
 *
 * @param {any} value Value.
 * @return {number|null} Number, or null.
 */
function asNumber( value ) {
	if ( 'number' === typeof value ) {
		return value;
	}

	if ( 'string' !== typeof value ) {
		return null;
	}

	const trimmed = value.trim();

	return NUMBER_PATTERN.test( trimmed ) ? Number( trimmed ) : null;
}

/**
 * Whether two values are the same one.
 *
 * @param {any} left  Left value.
 * @param {any} right Right value.
 * @return {boolean} Whether they are the same.
 */
function same( left, right ) {
	if ( Array.isArray( left ) || Array.isArray( right ) ) {
		const one = asList( left );
		const other = asList( right );

		return (
			one.length === other.length &&
			one.every( ( entry, index ) => entry === other[ index ] )
		);
	}

	const one = asNumber( left );
	const other = asNumber( right );

	if ( null !== one && null !== other ) {
		return one === other;
	}

	return asText( left ) === asText( right );
}

/**
 * Whether a needle is in a list, comparing the way `same` compares.
 *
 * @param {any[]} list   List.
 * @param {any}   needle Needle.
 * @return {boolean} Whether it is in the list.
 */
function inList( list, needle ) {
	return list.some( ( entry ) => same( entry, needle ) );
}

/**
 * Whether a value contains another.
 *
 * @param {any} actual   Value read from the context.
 * @param {any} expected What to look for.
 * @return {boolean} Whether it contains it.
 */
function contains( actual, expected ) {
	if ( Array.isArray( actual ) ) {
		return inList( actual, expected );
	}

	return asText( actual ).includes( asText( expected ) );
}

/**
 * Whether a value is greater than, or less than, another.
 *
 * @param {any}     actual   Value read from the context.
 * @param {any}     expected Comparison value.
 * @param {boolean} greater  True for greater than, false for less than.
 * @return {boolean} Whether the comparison holds.
 */
function beyond( actual, expected, greater ) {
	const left = asNumber( actual );
	const right = asNumber( expected );

	if ( null === left || null === right ) {
		return false;
	}

	return greater ? left > right : left < right;
}

/**
 * Applies one operator.
 *
 * @param {any}    actual   Value read from the context.
 * @param {string} operator Operator key.
 * @param {any}    expected Comparison value.
 * @return {boolean} Whether it matches.
 */
function compare( actual, operator, expected ) {
	switch ( operator ) {
		case 'is_empty':
			return isEmpty( actual );
		case 'is_not_empty':
			return ! isEmpty( actual );
		case 'equals':
			return same( actual, expected );
		case 'not_equals':
			return ! same( actual, expected );
		case 'contains':
			return contains( actual, expected );
		case 'not_contains':
			return ! contains( actual, expected );
		case 'greater_than':
			return beyond( actual, expected, true );
		case 'less_than':
			return beyond( actual, expected, false );
		case 'in':
			return inList(
				Array.isArray( expected ) ? expected : [ expected ],
				actual
			);
		case 'not_in':
			return ! inList(
				Array.isArray( expected ) ? expected : [ expected ],
				actual
			);
	}

	// An operator nothing here knows cannot be read, and an unreadable
	// comparison does not hide a field.
	return true;
}

/**
 * Whether a node is a group.
 *
 * @param {any} node Node.
 * @return {boolean} Whether it is a group.
 */
function isGroup( node ) {
	return (
		null !== node &&
		'object' === typeof node &&
		! Array.isArray( node ) &&
		( Array.isArray( node.all ) || Array.isArray( node.any ) )
	);
}

/**
 * Reads what a source holds in this context.
 *
 * @param {any}                 leaf    Leaf.
 * @param {Record<string, any>} context Trusted context.
 * @return {any} Value.
 */
function read( leaf, context ) {
	const entries = context ?? {};

	if ( 'field' !== leaf.source ) {
		return entries[ leaf.source ];
	}

	/** @type {Record<string, any>} */
	const fields = entries.fields ?? {};

	return fields[ leaf.field ?? '' ];
}

/**
 * Decides one node.
 *
 * @param {any}                 node    Node.
 * @param {Record<string, any>} context Trusted context.
 * @return {boolean} Whether it matches.
 */
function decide( node, context ) {
	if ( isGroup( node ) ) {
		const children = node.all ?? node.any ?? [];

		// A group with nothing in it says nothing, and a rule that says nothing is
		// not read as "everything matches".
		if ( 0 === children.length ) {
			return true;
		}

		return Array.isArray( node.all )
			? children.every( ( /** @type {any} */ child ) =>
					decide( child, context )
			  )
			: children.some( ( /** @type {any} */ child ) =>
					decide( child, context )
			  );
	}

	if ( null === node || 'object' !== typeof node || Array.isArray( node ) ) {
		// Not a node at all: the tree cannot be read.
		return true;
	}

	if (
		! (
			/** @type {Record<string, boolean>} */ ( SOURCES )[
				String( node.source )
			]
		) ||
		! (
			/** @type {Record<string, boolean>} */ ( OPERATORS )[
				String( node.operator )
			]
		)
	) {
		return true;
	}

	return compare( read( node, context ), node.operator, node.value );
}

/**
 * Evaluates a rule against a trusted context.
 *
 * @param {any}                 tree    Declarative rule, e.g. `{ all: [ ... ] }`.
 * @param {Record<string, any>} context Trusted context.
 * @return {boolean} True when the rule matches.
 */
export function evaluate( tree, context = {} ) {
	if ( null === tree || 'object' !== typeof tree || Array.isArray( tree ) ) {
		return true;
	}

	if ( ! isGroup( tree ) && 'string' !== typeof tree.source ) {
		return true;
	}

	return decide( tree, context );
}
