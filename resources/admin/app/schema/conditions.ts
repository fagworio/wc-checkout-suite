/**
 * The condition model the rule editor works on.
 *
 * Three things live here, and they are the three the editor needs: how a rule is
 * put together, how it reads back as a sentence, and what is wrong with it.
 *
 * The vocabulary is not declared here. The operators and the sources arrive from
 * the server, read from the classes the validator itself reads, so the editor
 * cannot offer a choice the server would refuse — the rule ADR-0007 sets for every
 * closed vocabulary in this plugin. What this module adds is the reading: which
 * combinations the vocabulary allows, and how to say a rule out loud.
 *
 * A rule is a tree of groups and leaves, and nothing else. Negation is not a node:
 * it is the negated operator, which is why `describe` can render any rule as one
 * sentence without a "not" wrapper around a clause.
 */

import type { ConditionVocabularyShape } from './types';

/** A leaf: one source, one operator, and the value it compares against. */
export interface ConditionLeaf {
	source: string;
	operator: string;
	field?: string;
	value?: any;
}

/** A group: everything, or anything. */
export interface ConditionGroup {
	all?: ConditionNode[];
	any?: ConditionNode[];
}

/** A node of a rule. */
export type ConditionNode = ConditionLeaf | ConditionGroup;

/** One operator, as the server publishes it. */
export interface ConditionOperator {
	key: string;
	label: string;
	takesValue: boolean;
	valueTypes: string[];
	sourceTypes: string[];
	negated: boolean;
}

/** One source, as the server publishes it. */
export interface ConditionSource {
	key: string;
	label: string;
	type: string;
	scope: string;
	isReference: boolean;
}

/** The vocabulary, as the server publishes it. */
export type ConditionVocabulary = ConditionVocabularyShape;

/**
 * Whether a node is a group.
 *
 * @param node Node.
 * @return Whether it is a group.
 */
export function isGroup( node: ConditionNode ): node is ConditionGroup {
	return (
		null !== node &&
		'object' === typeof node &&
		( Array.isArray( ( node as ConditionGroup ).all ) ||
			Array.isArray( ( node as ConditionGroup ).any ) )
	);
}

/**
 * The children of a group.
 *
 * @param node Node.
 * @return Children.
 */
export function childrenOf( node: ConditionNode ): ConditionNode[] {
	if ( ! isGroup( node ) ) {
		return [];
	}

	return node.all ?? node.any ?? [];
}

/**
 * The group kind of a node.
 *
 * @param node Node.
 * @return `all`, `any`, or an empty string for a leaf.
 */
export function groupKind( node: ConditionNode ): string {
	if ( ! isGroup( node ) ) {
		return '';
	}

	return Array.isArray( node.all ) ? 'all' : 'any';
}

/**
 * Looks up an operator.
 *
 * @param vocabulary Vocabulary.
 * @param key        Operator key.
 * @return Operator, or undefined.
 */
export function operatorOf(
	vocabulary: ConditionVocabulary,
	key: string
): ConditionOperator | undefined {
	return ( vocabulary.operators ?? [] ).find(
		( entry ) => entry.key === key
	);
}

/**
 * Looks up a source.
 *
 * @param vocabulary Vocabulary.
 * @param key        Source key.
 * @return Source, or undefined.
 */
export function sourceOf(
	vocabulary: ConditionVocabulary,
	key: string
): ConditionSource | undefined {
	return ( vocabulary.sources ?? [] ).find( ( entry ) => entry.key === key );
}

/**
 * A new leaf, as valid as the vocabulary allows without asking the merchant.
 *
 * The first source and the first operator are chosen rather than left empty: a
 * select with nothing selected is a form the merchant has to repair before they
 * can use it, and an empty operator would be refused by the server the moment it
 * was saved.
 *
 * @param vocabulary Vocabulary.
 * @return Leaf.
 */
export function emptyLeaf( vocabulary: ConditionVocabulary ): ConditionLeaf {
	const source = ( vocabulary.sources ?? [] )[ 0 ];
	const operator = firstOperatorFor( vocabulary, source );

	return {
		source: source?.key ?? '',
		operator: operator?.key ?? '',
		...( source?.isReference ? { field: '' } : {} ),
		...( operator?.takesValue ? { value: '' } : {} ),
	};
}

/**
 * A new group with one leaf in it.
 *
 * @param vocabulary Vocabulary.
 * @param kind       `all` or `any`.
 * @return Group.
 */
export function emptyGroup(
	vocabulary: ConditionVocabulary,
	kind = 'all'
): ConditionGroup {
	const children = [ emptyLeaf( vocabulary ) ];

	return 'any' === kind ? { any: children } : { all: children };
}

/**
 * The first operator that can read a source.
 *
 * Offering every operator and refusing most of them on save would be the editor
 * making the merchant discover the vocabulary by trial. A rule that cannot be
 * written reads better as an operator that is not in the list.
 *
 * @param vocabulary Vocabulary.
 * @param source     Source, when one was chosen.
 * @return Operator, or the first one.
 */
export function firstOperatorFor(
	vocabulary: ConditionVocabulary,
	source?: ConditionSource
): ConditionOperator | undefined {
	const operators = vocabulary.operators ?? [];

	if ( source && ! source.isReference ) {
		return operators.find( ( entry ) =>
			entry.sourceTypes.includes( source.type )
		);
	}

	return operators[ 0 ];
}

/**
 * The operators that can read a source.
 *
 * @param vocabulary Vocabulary.
 * @param source     Source key.
 * @return Operators.
 */
export function operatorsFor(
	vocabulary: ConditionVocabulary,
	source: string
): ConditionOperator[] {
	const entry = sourceOf( vocabulary, source );

	if ( ! entry || entry.isReference ) {
		return vocabulary.operators ?? [];
	}

	return ( vocabulary.operators ?? [] ).filter( ( candidate ) =>
		candidate.sourceTypes.includes( entry.type )
	);
}

/**
 * Replaces a node at a path.
 *
 * Returns a new tree rather than mutating: the editor's unsaved-work guard and its
 * undo both compare states, and a tree changed in place would make both of them
 * lie about what changed.
 *
 * @param node    Root node.
 * @param path    Index path, as `childAt` produces.
 * @param replace Replacement.
 * @return New root.
 */
export function replaceAt(
	node: ConditionNode,
	path: number[],
	replace: ConditionNode
): ConditionNode {
	if ( 0 === path.length ) {
		return replace;
	}

	const kind = groupKind( node );

	if ( '' === kind ) {
		return node;
	}

	const children = [ ...childrenOf( node ) ];
	const [ head, ...rest ] = path;

	if ( head < 0 || head >= children.length ) {
		return node;
	}

	children[ head ] = replaceAt( children[ head ], rest, replace );

	return 'all' === kind ? { all: children } : { any: children };
}

/**
 * Adds a node to a group.
 *
 * @param node  Root node.
 * @param path  Path to the group.
 * @param child Child to add.
 * @return New root.
 */
export function addChildAt(
	node: ConditionNode,
	path: number[],
	child: ConditionNode
): ConditionNode {
	const group = nodeAt( node, path );

	if ( ! group || ! isGroup( group ) ) {
		return node;
	}

	const kind = groupKind( group );

	return replaceAt(
		node,
		path,
		'all' === kind
			? { all: [ ...childrenOf( group ), child ] }
			: { any: [ ...childrenOf( group ), child ] }
	);
}

/**
 * Removes a node from a group.
 *
 * Removing the last child of a group removes the group, because a group with
 * nothing in it is a rule that says nothing — and the server refuses it with
 * `empty_condition_group`. An editor that left one behind would hand the merchant
 * a rule the server will not take, with no way to see why. The cascade ends at the
 * root, where removing the last condition clears the rule rather than leaving an
 * empty group the server would refuse.
 *
 * @param node Root node.
 * @param path Path to the node.
 * @return New root, or null when the root itself was removed.
 */
export function removeAt(
	node: ConditionNode,
	path: number[]
): ConditionNode | null {
	if ( 0 === path.length ) {
		return null;
	}

	const parentPath = path.slice( 0, -1 );
	const index = path[ path.length - 1 ];
	const parent = nodeAt( node, parentPath );

	if ( ! parent || ! isGroup( parent ) ) {
		return node;
	}

	const children = childrenOf( parent ).filter(
		( _child, position ) => position !== index
	);

	if ( 0 === children.length ) {
		return removeAt( node, parentPath );
	}

	return replaceAt(
		node,
		parentPath,
		'all' === groupKind( parent ) ? { all: children } : { any: children }
	);
}

/**
 * Reads the node at a path.
 *
 * @param node Root node.
 * @param path Index path.
 * @return Node, or null.
 */
export function nodeAt(
	node: ConditionNode,
	path: number[]
): ConditionNode | null {
	if ( 0 === path.length ) {
		return node;
	}

	const children = childrenOf( node );
	const [ head, ...rest ] = path;

	if ( head < 0 || head >= children.length ) {
		return null;
	}

	return nodeAt( children[ head ], rest );
}

/**
 * Every field a rule reads.
 *
 * @param node Root node.
 * @return Field identifiers.
 */
export function references( node: ConditionNode ): string[] {
	if ( ! isGroup( node ) ) {
		const leaf = node as ConditionLeaf;

		return 'field' === leaf.source && leaf.field ? [ leaf.field ] : [];
	}

	return childrenOf( node ).reduce< string[] >(
		( found, child ) => [ ...found, ...references( child ) ],
		[]
	);
}

/**
 * The size of a rule.
 *
 * @param node Root node.
 * @return Number of nodes.
 */
export function size( node: ConditionNode ): number {
	if ( ! isGroup( node ) ) {
		return 1;
	}

	return (
		1 +
		childrenOf( node ).reduce(
			( total, child ) => total + size( child ),
			0
		)
	);
}

/**
 * The depth of a rule.
 *
 * @param node Root node.
 * @return Depth.
 */
export function depth( node: ConditionNode ): number {
	if ( ! isGroup( node ) ) {
		return 1;
	}

	return (
		1 +
		childrenOf( node ).reduce(
			( deepest, child ) => Math.max( deepest, depth( child ) ),
			0
		)
	);
}

/**
 * Reads a rule back as a sentence.
 *
 * The acceptance asks for readable rules, and readable means one sentence a
 * merchant can check against what they meant — not a nested form read back at
 * them. Groups become "all of" and "any of", and a leaf names the source, the
 * operator and the value, using the labels the server published.
 *
 * @param node       Root node.
 * @param vocabulary Vocabulary.
 * @param fieldLabel Label of a referenced field, when the caller has one.
 * @return Sentence.
 */
export function describe(
	node: ConditionNode,
	vocabulary: ConditionVocabulary,
	fieldLabel?: ( field: string ) => string
): string {
	if ( ! isGroup( node ) ) {
		return describeLeaf( node as ConditionLeaf, vocabulary, fieldLabel );
	}

	const kind = groupKind( node );
	const parts = childrenOf( node ).map( ( child ) =>
		describe( child, vocabulary, fieldLabel )
	);

	if ( 0 === parts.length ) {
		return 'all' === kind ? 'All of: (nothing)' : 'Any of: (nothing)';
	}

	if ( 1 === parts.length ) {
		return parts[ 0 ];
	}

	const joiner = 'all' === kind ? ' and ' : ' or ';
	const head = 'all' === kind ? 'All of: ' : 'Any of: ';

	return head + parts.join( joiner );
}

/**
 * Reads one leaf back as a phrase.
 *
 * @param leaf       Leaf.
 * @param vocabulary Vocabulary.
 * @param fieldLabel Label of a referenced field.
 * @return Phrase.
 */
function describeLeaf(
	leaf: ConditionLeaf,
	vocabulary: ConditionVocabulary,
	fieldLabel?: ( field: string ) => string
): string {
	const source = sourceOf( vocabulary, leaf.source );
	const operator = operatorOf( vocabulary, leaf.operator );

	const subject = source?.isReference
		? fieldLabel?.( leaf.field ?? '' ) || leaf.field || 'a field'
		: source?.label ?? leaf.source;

	const verb = operator?.label ?? leaf.operator;
	const value = operator?.takesValue ? ` ${ formatValue( leaf.value ) }` : '';

	return `${ subject } ${ verb }${ value }`;
}

/**
 * Renders a comparison value for a sentence.
 *
 * @param value Value.
 * @return Text.
 */
export function formatValue( value: any ): string {
	if ( Array.isArray( value ) ) {
		return value.map( ( entry ) => String( entry ) ).join( ', ' );
	}

	if ( true === value ) {
		return 'yes';
	}

	if ( false === value ) {
		return 'no';
	}

	if ( '' === value || null === value || undefined === value ) {
		return '(empty)';
	}

	return String( value );
}

/**
 * What is wrong with a rule, said in words.
 *
 * The editor checks what it can check without the server: the vocabulary it was
 * given, and the field the rule belongs to. Everything else — a reference to a
 * field that does not exist, two fields that depend on each other, an operator
 * that cannot read what the named field holds — needs the rest of the document,
 * and the server answers those where the document is validated. An editor that
 * re-implemented them would be a second opinion, and the two would disagree the
 * first time one of them was edited.
 *
 * @param node                Root node.
 * @param vocabulary          Vocabulary.
 * @param options             Options.
 * @param options.fieldId
 * @param options.fieldLabel
 * @param options.knownFields
 * @return Messages.
 */
export function issues(
	node: ConditionNode,
	vocabulary: ConditionVocabulary,
	options: {
		fieldId?: string;
		fieldLabel?: ( field: string ) => string;
		knownFields?: string[];
	} = {}
): string[] {
	const found: string[] = [];
	const limits = vocabulary.limits ?? {};
	const referencesFound = references( node );

	if ( 1 < size( node ) && depth( node ) > ( limits.maxDepth ?? 8 ) ) {
		found.push(
			`This rule is nested ${ depth( node ) } levels deep; the limit is ${
				limits.maxDepth ?? 8
			}.`
		);
	}

	if ( size( node ) > ( limits.maxNodes ?? 100 ) ) {
		found.push(
			`This rule has ${ size( node ) } parts; the limit is ${
				limits.maxNodes ?? 100
			}.`
		);
	}

	if ( options.fieldId && referencesFound.includes( options.fieldId ) ) {
		found.push( 'A field cannot depend on itself.' );
	}

	for ( const reference of referencesFound ) {
		if ( reference === options.fieldId ) {
			continue;
		}

		if (
			options.knownFields &&
			! options.knownFields.includes( reference )
		) {
			found.push(
				`This rule reads "${
					options.fieldLabel?.( reference ) || reference
				}", which is not a field any more.`
			);
		}
	}

	const walk = ( current: ConditionNode ): void => {
		if ( isGroup( current ) ) {
			const children = childrenOf( current );

			if ( 0 === children.length ) {
				found.push( 'A group with no conditions in it says nothing.' );
			}

			children.forEach( walk );

			return;
		}

		const leaf = current as ConditionLeaf;
		const source = sourceOf( vocabulary, leaf.source );

		if ( ! source ) {
			found.push(
				`"${ leaf.source }" is not something a rule can read.`
			);

			return;
		}

		const operator = operatorOf( vocabulary, leaf.operator );

		if ( ! operator ) {
			found.push( `"${ leaf.operator }" is not an operator.` );

			return;
		}

		if ( source.isReference && ! leaf.field ) {
			found.push( `${ source.label } is chosen but no field is named.` );
		}

		// A catalogue source declares what it holds, so an operator that cannot read
		// it is a comparison with no meaning — and the server refuses it with the
		// same code. A reference is not judged here: what it holds is decided by the
		// field it names, and only the document can resolve that.
		if (
			! source.isReference &&
			! operator.sourceTypes.includes( source.type )
		) {
			found.push(
				`"${ operator.label }" cannot read ${ source.label }, which holds a value of type ${ source.type }.`
			);

			return;
		}

		if ( operator.takesValue ) {
			const type = valueType( leaf.value );

			if (
				undefined === leaf.value ||
				null === leaf.value ||
				'' === leaf.value
			) {
				found.push(
					`"${ operator.label }" needs something to compare against.`
				);
			} else if ( ! operator.valueTypes.includes( type ) ) {
				found.push(
					`"${ operator.label }" cannot compare against a value of type ${ type }.`
				);
			}
		} else if (
			undefined !== leaf.value &&
			null !== leaf.value &&
			'' !== leaf.value
		) {
			found.push(
				`"${ operator.label }" asks a question about what is there and takes no value.`
			);
		}
	};

	walk( node );

	return found;
}

/**
 * One rule that cannot be satisfied by any cart.
 *
 * @see conflicts
 */
export interface ConditionConflict {
	/** Source the two comparisons disagree about. */
	source: string;
	/** The two readings, in the vocabulary's own words. */
	first: string;
	second: string;
	/** Sentence the editor shows. */
	message: string;
}

/**
 * Comparisons inside one `all` that cannot both hold.
 *
 * §6.9 asks for a conflict to be **shown** and not refused, and this is the same rule for a
 * condition tree: a merchant may write `country equals BR` and `country equals PT` while they are
 * working, or keep a rule that a promotion has temporarily made impossible, and a store that
 * refused to save it would be a store that refuses work in progress. What it must not do is stay
 * silent, because a rule nothing can satisfy is a field that never appears and a checkout profile
 * that never runs — the exact failure this phase exists to make visible.
 *
 * Only `all` groups are read as a conjunction. Inside an `any`, a pair that contradicts itself
 * sits beside alternatives that may hold, so nothing there is unsatisfiable; and a nested `all` is
 * kept as its own scope rather than merged with its parent, which can only make this report
 * narrower than the truth, never wider. A report that cried wolf would be turned off.
 *
 * @param node       Root node.
 * @param vocabulary Vocabulary.
 * @return Conflicts, in the order they were found.
 */
export function conflicts(
	node: ConditionNode,
	vocabulary: ConditionVocabulary
): ConditionConflict[] {
	const found: ConditionConflict[] = [];

	/**
	 * The leaves of one conjunction.
	 *
	 * @param group Group node.
	 * @return Leaves directly inside it, which is what "both at once" means.
	 */
	const conjuncts = ( group: ConditionNode ): ConditionLeaf[] =>
		childrenOf( group ).filter(
			( child ) => ! isGroup( child )
		) as ConditionLeaf[];

	/**
	 * One comparison, in words.
	 *
	 * @param leaf Leaf.
	 * @return Sentence fragment.
	 */
	const sentence = ( leaf: ConditionLeaf ): string => {
		const operator = operatorOf( vocabulary, leaf.operator );
		const label = operator?.label ?? leaf.operator;

		if ( ! operator?.takesValue ) {
			return label;
		}

		return `${ label } ${ formatValue( leaf.value ) }`;
	};

	/**
	 * Whether two comparisons of one source cannot both hold.
	 *
	 * @param a First.
	 * @param b Second.
	 * @return Whether they contradict.
	 */
	const contradict = ( a: ConditionLeaf, b: ConditionLeaf ): boolean => {
		const numeric = ( value: any ): number | null => {
			const asNumber = Number( value );

			return 'number' === valueType( value ) && ! Number.isNaN( asNumber )
				? asNumber
				: null;
		};

		const pair = [ a.operator, b.operator ].sort().join( '+' );

		switch ( pair ) {
			case 'equals+equals':
				return formatValue( a.value ) !== formatValue( b.value );

			case 'equals+not_equals':
				return formatValue( a.value ) === formatValue( b.value );

			case 'contains+not_contains':
				return formatValue( a.value ) === formatValue( b.value );

			case 'is_empty+is_not_empty':
				return true;

			// Asking for a value and asking for no value are two answers to one question.
			case 'equals+is_empty':
			case 'contains+is_empty':
			case 'in+is_empty':
				return true;

			case 'equals+greater_than': {
				const equals = 'equals' === a.operator ? a : b;
				const greater = 'equals' === a.operator ? b : a;
				const value = numeric( equals.value );
				const bound = numeric( greater.value );

				return null !== value && null !== bound && value <= bound;
			}

			case 'equals+less_than': {
				const equals = 'equals' === a.operator ? a : b;
				const less = 'equals' === a.operator ? b : a;
				const value = numeric( equals.value );
				const bound = numeric( less.value );

				return null !== value && null !== bound && value >= bound;
			}

			case 'greater_than+less_than': {
				const greater = 'greater_than' === a.operator ? a : b;
				const less = 'greater_than' === a.operator ? b : a;
				const floor = numeric( greater.value );
				const ceiling = numeric( less.value );

				return null !== floor && null !== ceiling && floor >= ceiling;
			}

			default:
				return false;
		}
	};

	/**
	 * Reads one node, reporting the contradictions inside it.
	 *
	 * @param current Node.
	 */
	const walk = ( current: ConditionNode ): void => {
		if ( ! isGroup( current ) ) {
			return;
		}

		if ( 'all' === groupKind( current ) ) {
			const leaves = conjuncts( current ).filter( ( leaf ) => {
				const source = sourceOf( vocabulary, leaf.source );

				// A reference reads another field, and what it holds is decided by that
				// field: two comparisons of it are not two comparisons of one value here.
				return Boolean( source ) && ! source?.isReference;
			} );

			leaves.forEach( ( leaf, index ) => {
				leaves.slice( index + 1 ).forEach( ( other ) => {
					if ( leaf.source !== other.source ) {
						return;
					}

					if ( ! contradict( leaf, other ) ) {
						return;
					}

					found.push( {
						source: leaf.source,
						first: sentence( leaf ),
						second: sentence( other ),
						message: `"${ sentence( leaf ) }" and "${ sentence(
							other
						) }" cannot both be true, so this rule matches no cart.`,
					} );
				} );
			} );
		}

		childrenOf( current ).forEach( walk );
	};

	walk( node );

	return found;
}

/**
 * The type of a comparison value, as the vocabulary names types.
 *
 * @param value Value.
 * @return Type.
 */
export function valueType( value: any ): string {
	if ( Array.isArray( value ) ) {
		return 'list';
	}

	if ( 'boolean' === typeof value ) {
		return 'boolean';
	}

	if ( 'number' === typeof value ) {
		return 'number';
	}

	return 'string';
}

/**
 * A sample context the merchant fills in to see what a rule decides.
 *
 * The editor's preview needs values to reason about, and the only honest place to
 * get them is the merchant: guessing that the cart holds R$ 200 would show a
 * preview of a store that does not exist.
 */
export interface PreviewContext {
	country?: string;
	state?: string;
	shipping_method?: string;
	payment_method?: string;
	customer_logged_in?: boolean;
	cart_items?: string[];
	cart_categories?: string[];
	cart_total?: number;
	fields?: Record< string, any >;
}

/** What a preview decided. */
export interface PreviewResult {
	matches: boolean;
	/** The leaves that decided it, in the order they were read. */
	because: string[];
}

/**
 * Evaluates a rule against a sample context, for the editor's preview.
 *
 * This is the **preview** evaluation and not the production one, and the
 * difference is worth stating rather than hiding: the engine that will decide
 * visibility on a real checkout is WCCS-033, it runs in PHP and in JavaScript
 * against shared fixtures, and it reads context the server builds and trusts. What
 * this does is answer "with these values, does my rule match?" while the merchant
 * is looking at it — which is the question the acceptance asks the editor to be
 * able to answer, and a question nobody can answer from the server before the
 * rule is even saved.
 *
 * A source the context does not carry reads as empty rather than as false, and an
 * unknown operator matches nothing: a preview that guessed would be a preview that
 * agreed with a rule the checkout will not run.
 *
 * @param node       Root node.
 * @param context    Sample values.
 * @param vocabulary Vocabulary.
 * @return Result.
 */
export function preview(
	node: ConditionNode,
	context: PreviewContext,
	vocabulary: ConditionVocabulary
): PreviewResult {
	const because: string[] = [];

	const walk = ( current: ConditionNode ): boolean => {
		if ( isGroup( current ) ) {
			const children = childrenOf( current );
			const kind = groupKind( current );

			if ( 0 === children.length ) {
				return false;
			}

			return 'all' === kind
				? children.every( walk )
				: children.some( walk );
		}

		const leaf = current as ConditionLeaf;
		const operator = operatorOf( vocabulary, leaf.operator );

		if ( ! operator ) {
			return false;
		}

		const matched = compare( read( leaf, context ), operator, leaf.value );

		because.push(
			`${ describeLeaf( leaf, vocabulary ) } → ${
				matched ? 'matches' : 'does not match'
			}`
		);

		return matched;
	};

	return { matches: walk( node ), because };
}

/**
 * The value a leaf reads out of a sample context.
 *
 * @param leaf    Leaf.
 * @param context Sample values.
 * @return Value.
 */
function read( leaf: ConditionLeaf, context: PreviewContext ): any {
	if ( 'field' === leaf.source ) {
		return ( context.fields ?? {} )[ leaf.field ?? '' ];
	}

	return ( context as any )[ leaf.source ];
}

/**
 * Applies one operator.
 *
 * A list source is compared by membership when the operator asks whether it
 * contains something, and by equality otherwise; a number is compared as a number
 * when the operator is a comparison, because comparing "100" with 9 as text is how
 * a rule about a cart total goes wrong.
 *
 * @param actual   Value read from the context.
 * @param operator Operator.
 * @param expected Comparison value.
 * @return Whether it matches.
 */
function compare(
	actual: any,
	operator: ConditionOperator,
	expected: any
): boolean {
	switch ( operator.key ) {
		case 'is_empty':
			return isEmpty( actual );
		case 'is_not_empty':
			return ! isEmpty( actual );
		case 'equals':
			return asText( actual ) === asText( expected );
		case 'not_equals':
			return asText( actual ) !== asText( expected );
		case 'contains':
			return contains( actual, expected );
		case 'not_contains':
			return ! contains( actual, expected );
		case 'greater_than':
			return toNumber( actual ) > toNumber( expected );
		case 'less_than':
			return toNumber( actual ) < toNumber( expected );
		case 'in':
			return asList( expected ).some(
				( entry ) => asText( entry ) === asText( actual )
			);
		case 'not_in':
			return ! asList( expected ).some(
				( entry ) => asText( entry ) === asText( actual )
			);
	}

	return false;
}

/**
 * Whether a value counts as empty, the way the pipeline counts it.
 *
 * `0`, `'0'` and `false` are values. The pipeline treats them as present, and a
 * preview that called them empty would disagree with the engine it is previewing.
 *
 * @param value Value.
 * @return Whether it is empty.
 */
function isEmpty( value: any ): boolean {
	if ( null === value || undefined === value || '' === value ) {
		return true;
	}

	return Array.isArray( value ) && 0 === value.length;
}

/**
 * Reads a value as text for an equality comparison.
 *
 * @param value Value.
 * @return Text.
 */
function asText( value: any ): string {
	if ( null === value || undefined === value ) {
		return '';
	}

	return String( value );
}

/**
 * Reads a value as a list.
 *
 * @param value Value.
 * @return List.
 */
function asList( value: any ): any[] {
	return Array.isArray( value ) ? value : [ value ];
}

/**
 * Whether a value contains something, for text and for lists.
 *
 * @param actual   Value.
 * @param expected What to look for.
 * @return Whether it contains it.
 */
function contains( actual: any, expected: any ): boolean {
	if ( Array.isArray( actual ) ) {
		return actual.some(
			( entry ) => asText( entry ) === asText( expected )
		);
	}

	return asText( actual ).includes( asText( expected ) );
}

/**
 * Reads a value as a number.
 *
 * @param value Value.
 * @return Number, or a value that compares as false with anything.
 */
function toNumber( value: any ): number {
	const parsed = Number( value );

	return Number.isNaN( parsed ) ? Number.NaN : parsed;
}
